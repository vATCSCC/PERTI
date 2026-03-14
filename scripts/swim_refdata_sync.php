<?php
/**
 * SWIM Reference Data Sync
 *
 * Syncs CDR and playbook reference data from internal databases (VATSIM_REF,
 * perti_site MySQL) into SWIM_API for external API consumption. This isolates
 * external SWIM API traffic from internal operational databases.
 *
 * Called by refdata_sync_daemon.php Phase 3 after the core CDR and playbook
 * imports complete. SWIM_API unavailability is non-fatal and never blocks the
 * core import.
 *
 * @package PERTI
 * @subpackage SWIM
 * @version 1.0.0
 */

if (!defined('PERTI_LOADED')) {
    define('PERTI_LOADED', true);
    require_once __DIR__ . '/../load/config.php';
    require_once __DIR__ . '/../load/connect.php';
}

// SQL Server allows max 2100 params per batch; playbook_plays has 22 cols → 2100/22 = 95 max
define('SWIM_REFDATA_BATCH_SIZE', 90);

/**
 * Run both CDR and playbook SWIM syncs.
 * Safe to call even if SWIM_API is down — returns success with skip messages.
 *
 * @return array{success: bool, message: string}
 */
function runSwimRefdataSync(): array {
    $start = microtime(true);
    $messages = [];
    $anyFailure = false;

    // CDR sync
    $cdrResult = swimSyncCdrs();
    $messages[] = 'CDR: ' . $cdrResult['message'];
    if (!$cdrResult['success'] && $cdrResult['skipped'] !== true) {
        $anyFailure = true;
    }

    // Playbook sync
    $pbResult = swimSyncPlaybook();
    $messages[] = 'Playbook: ' . $pbResult['message'];
    if (!$pbResult['success'] && $pbResult['skipped'] !== true) {
        $anyFailure = true;
    }

    $elapsed = round(microtime(true) - $start, 1);
    $summary = implode(' | ', $messages) . " [{$elapsed}s]";

    return [
        'success' => !$anyFailure,
        'message' => $summary
    ];
}

/**
 * Sync CDRs from VATSIM_REF to SWIM_API.
 *
 * Reads all rows from VATSIM_REF.dbo.coded_departure_routes,
 * then does DELETE + batch INSERT into SWIM_API.dbo.swim_coded_departure_routes.
 *
 * @return array{success: bool, message: string, count: int, skipped: bool}
 */
function swimSyncCdrs(): array {
    $connRef  = get_conn_ref();
    $connSwim = get_conn_swim();

    if (!$connSwim) {
        return ['success' => false, 'message' => 'SWIM_API unavailable — skipped', 'count' => 0, 'skipped' => true];
    }
    if (!$connRef) {
        return ['success' => false, 'message' => 'VATSIM_REF unavailable — skipped', 'count' => 0, 'skipped' => true];
    }

    // Read all CDRs from source
    $sql = "SELECT cdr_code, full_route, origin_icao, dest_icao,
                   dep_artcc, arr_artcc, direction,
                   altitude_min_ft, altitude_max_ft, is_active, source
            FROM dbo.coded_departure_routes";
    $stmt = sqlsrv_query($connRef, $sql);
    if ($stmt === false) {
        return ['success' => false, 'message' => 'Failed to read CDRs: ' . _swimRefErrMsg(), 'count' => 0, 'skipped' => false];
    }

    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $row;
    }
    sqlsrv_free_stmt($stmt);

    if (empty($rows)) {
        return ['success' => true, 'message' => '0 CDRs in source — nothing to sync', 'count' => 0, 'skipped' => false];
    }

    // DELETE + batch INSERT into SWIM_API (auto-commit per batch)
    $delStmt = sqlsrv_query($connSwim, "DELETE FROM dbo.swim_coded_departure_routes");
    if ($delStmt === false) {
        return ['success' => false, 'message' => 'DELETE failed: ' . _swimRefErrMsg(), 'count' => 0, 'skipped' => false];
    }
    sqlsrv_free_stmt($delStmt);

    // Batch INSERT 900 rows/chunk
    $inserted = 0;
    $errors   = 0;
    $cols = "cdr_code, full_route, origin_icao, dest_icao, dep_artcc, arr_artcc,
             direction, altitude_min_ft, altitude_max_ft, is_active, source, last_sync_utc";

    for ($i = 0; $i < count($rows); $i += SWIM_REFDATA_BATCH_SIZE) {
        $chunk = array_slice($rows, $i, SWIM_REFDATA_BATCH_SIZE);
        $values = [];
        $params = [];

        foreach ($chunk as $r) {
            $values[] = "(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, SYSUTCDATETIME())";
            $params[] = $r['cdr_code'];
            $params[] = $r['full_route'];
            $params[] = $r['origin_icao'];
            $params[] = $r['dest_icao'];
            $params[] = $r['dep_artcc'];
            $params[] = $r['arr_artcc'];
            $params[] = $r['direction'];
            $params[] = $r['altitude_min_ft'];
            $params[] = $r['altitude_max_ft'];
            $params[] = $r['is_active'] ? 1 : 0;
            $params[] = $r['source'];
        }

        $sql = "INSERT INTO dbo.swim_coded_departure_routes ($cols) VALUES " . implode(',', $values);
        $stmt = sqlsrv_query($connSwim, $sql, $params);
        if ($stmt !== false) {
            $inserted += count($chunk);
            sqlsrv_free_stmt($stmt);
        } else {
            $errors++;
            if ($errors <= 3) {
                _swimRefLog("CDR batch insert error at offset $i: " . _swimRefErrMsg(), 'WARN');
            }
        }
    }

    if ($inserted === 0) {
        return ['success' => false, 'message' => "All CDR inserts failed ($errors batch errors)", 'count' => 0, 'skipped' => false];
    }

    $msg = "$inserted CDRs synced to SWIM_API";
    if ($errors > 0) {
        $msg .= " ($errors batch errors)";
    }
    return ['success' => true, 'message' => $msg, 'count' => $inserted, 'skipped' => false];
}

/**
 * Sync playbook plays and routes from MySQL to SWIM_API.
 *
 * Reads all plays and routes from perti_site MySQL,
 * then does DELETE + batch INSERT into SWIM_API tables.
 *
 * @return array{success: bool, message: string, plays: int, routes: int, skipped: bool}
 */
function swimSyncPlaybook(): array {
    global $conn_pdo;

    $connSwim = get_conn_swim();

    if (!$connSwim) {
        return ['success' => false, 'message' => 'SWIM_API unavailable — skipped', 'plays' => 0, 'routes' => 0, 'skipped' => true];
    }
    if (!$conn_pdo) {
        return ['success' => false, 'message' => 'MySQL unavailable — skipped', 'plays' => 0, 'routes' => 0, 'skipped' => true];
    }

    // Read all plays from MySQL
    $playStmt = $conn_pdo->query(
        "SELECT play_id, play_name, play_name_norm, display_name, description,
                category, impacted_area, facilities_involved, scenario_type,
                route_format, source, status, visibility, airac_cycle,
                route_count, org_code, ctp_scope, ctp_session_id,
                created_by, updated_by,
                DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at,
                DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at
         FROM playbook_plays"
    );
    if (!$playStmt) {
        return ['success' => false, 'message' => 'Failed to read plays from MySQL', 'plays' => 0, 'routes' => 0, 'skipped' => false];
    }
    $plays = $playStmt->fetchAll(\PDO::FETCH_ASSOC);

    // Route count for logging (don't fetchAll — too much memory for 56K+ rows)
    $routeCountStmt = $conn_pdo->query("SELECT COUNT(*) FROM playbook_routes");
    $routeTotal = $routeCountStmt ? (int)$routeCountStmt->fetchColumn() : 0;

    _swimRefLog("Read " . count($plays) . " plays, $routeTotal routes in MySQL");

    // DELETE + batch INSERT into SWIM_API (auto-commit per batch to survive HTTP timeouts)
    $del1 = sqlsrv_query($connSwim, "DELETE FROM dbo.swim_playbook_routes");
    if ($del1 === false) {
        return ['success' => false, 'message' => 'DELETE routes failed: ' . _swimRefErrMsg(), 'plays' => 0, 'routes' => 0, 'skipped' => false];
    }
    sqlsrv_free_stmt($del1);

    $del2 = sqlsrv_query($connSwim, "DELETE FROM dbo.swim_playbook_plays");
    if ($del2 === false) {
        return ['success' => false, 'message' => 'DELETE plays failed: ' . _swimRefErrMsg(), 'plays' => 0, 'routes' => 0, 'skipped' => false];
    }
    sqlsrv_free_stmt($del2);

    // Batch INSERT plays
    $insertedPlays = 0;
    $playCols = "play_id, play_name, play_name_norm, display_name, description,
                 category, impacted_area, facilities_involved, scenario_type,
                 route_format, source, status, visibility, airac_cycle,
                 route_count, org_code, ctp_scope, ctp_session_id,
                 created_by, updated_by, created_at, updated_at, last_sync_utc";

    for ($i = 0; $i < count($plays); $i += SWIM_REFDATA_BATCH_SIZE) {
        $chunk = array_slice($plays, $i, SWIM_REFDATA_BATCH_SIZE);
        $values = [];
        $params = [];

        foreach ($chunk as $p) {
            $values[] = "(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, SYSUTCDATETIME())";
            $params[] = (int)$p['play_id'];
            $params[] = $p['play_name'];
            $params[] = $p['play_name_norm'];
            $params[] = $p['display_name'];
            $params[] = $p['description'];
            $params[] = $p['category'];
            $params[] = $p['impacted_area'];
            $params[] = $p['facilities_involved'];
            $params[] = $p['scenario_type'];
            $params[] = $p['route_format'];
            $params[] = $p['source'];
            $params[] = $p['status'];
            $params[] = $p['visibility'];
            $params[] = $p['airac_cycle'];
            $params[] = (int)($p['route_count'] ?? 0);
            $params[] = $p['org_code'];
            $params[] = $p['ctp_scope'];
            $params[] = $p['ctp_session_id'] !== null ? (int)$p['ctp_session_id'] : null;
            $params[] = $p['created_by'];
            $params[] = $p['updated_by'];
            $params[] = $p['created_at'];
            $params[] = $p['updated_at'];
        }

        $sql = "INSERT INTO dbo.swim_playbook_plays ($playCols) VALUES " . implode(',', $values);
        $stmt = sqlsrv_query($connSwim, $sql, $params);
        if ($stmt !== false) {
            $insertedPlays += count($chunk);
            sqlsrv_free_stmt($stmt);
        } else {
            _swimRefLog("Play batch insert error at offset $i: " . _swimRefErrMsg(), 'WARN');
            return ['success' => false, 'message' => 'Play batch insert failed at offset ' . $i, 'plays' => 0, 'routes' => 0, 'skipped' => false];
        }
    }

    // Batch INSERT routes — streamed from MySQL to avoid OOM on 56K+ rows
    $insertedRoutes = 0;
    $routeCols = "route_id, play_id, route_string, origin, origin_filter, dest, dest_filter,
                  origin_airports, origin_tracons, origin_artccs,
                  dest_airports, dest_tracons, dest_artccs,
                  traversed_artccs, traversed_tracons,
                  traversed_sectors_low, traversed_sectors_high, traversed_sectors_superhigh,
                  remarks, sort_order, last_sync_utc";

    // Use unbuffered query to stream 268K+ routes without OOM
    $conn_pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    $routeStmt = $conn_pdo->query(
        "SELECT route_id, play_id, route_string, origin, origin_filter, dest, dest_filter,
                origin_airports, origin_tracons, origin_artccs,
                dest_airports, dest_tracons, dest_artccs,
                traversed_artccs, traversed_tracons,
                traversed_sectors_low, traversed_sectors_high, traversed_sectors_superhigh,
                remarks, sort_order
         FROM playbook_routes"
    );
    $conn_pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true); // restore default
    if (!$routeStmt) {
        sqlsrv_rollback($connSwim);
        return ['success' => false, 'message' => 'Failed to read routes from MySQL', 'plays' => $insertedPlays, 'routes' => 0, 'skipped' => false];
    }

    $chunk = [];
    while ($r = $routeStmt->fetch(\PDO::FETCH_ASSOC)) {
        $chunk[] = $r;
        if (count($chunk) >= SWIM_REFDATA_BATCH_SIZE) {
            $ok = _insertRouteBatch($connSwim, $routeCols, $chunk, $insertedRoutes);
            if (!$ok) {
                return ['success' => false, 'message' => "Route batch insert failed at offset $insertedRoutes", 'plays' => $insertedPlays, 'routes' => $insertedRoutes, 'skipped' => false];
            }
            $insertedRoutes += count($chunk);
            $chunk = [];
        }
    }
    // Final partial chunk
    if (!empty($chunk)) {
        $ok = _insertRouteBatch($connSwim, $routeCols, $chunk, $insertedRoutes);
        if (!$ok) {
            return ['success' => false, 'message' => "Route batch insert failed at offset $insertedRoutes", 'plays' => $insertedPlays, 'routes' => $insertedRoutes, 'skipped' => false];
        }
        $insertedRoutes += count($chunk);
    }
    $routeStmt->closeCursor();

    return [
        'success' => true,
        'message' => "$insertedPlays plays + $insertedRoutes routes synced to SWIM_API",
        'plays'   => $insertedPlays,
        'routes'  => $insertedRoutes,
        'skipped' => false
    ];
}

/**
 * Get sqlsrv error message string.
 */
function _swimRefErrMsg(): string {
    $errors = sqlsrv_errors();
    if ($errors && isset($errors[0]['message'])) {
        return $errors[0]['message'];
    }
    return 'Unknown sqlsrv error';
}

/**
 * Log a message (uses refdata daemon's logMsg if available, otherwise echo).
 */
/**
 * Insert a batch of route rows into swim_playbook_routes.
 */
function _insertRouteBatch($connSwim, string $routeCols, array $chunk, int $offset): bool {
    $values = [];
    $params = [];
    foreach ($chunk as $r) {
        $values[] = "(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, SYSUTCDATETIME())";
        $params[] = (int)$r['route_id'];
        $params[] = (int)$r['play_id'];
        $params[] = $r['route_string'];
        $params[] = $r['origin'];
        $params[] = $r['origin_filter'];
        $params[] = $r['dest'];
        $params[] = $r['dest_filter'];
        $params[] = $r['origin_airports'];
        $params[] = $r['origin_tracons'];
        $params[] = $r['origin_artccs'];
        $params[] = $r['dest_airports'];
        $params[] = $r['dest_tracons'];
        $params[] = $r['dest_artccs'];
        $params[] = $r['traversed_artccs'];
        $params[] = $r['traversed_tracons'];
        $params[] = $r['traversed_sectors_low'];
        $params[] = $r['traversed_sectors_high'];
        $params[] = $r['traversed_sectors_superhigh'];
        $params[] = $r['remarks'];
        $params[] = (int)($r['sort_order'] ?? 0);
    }
    $sql = "INSERT INTO dbo.swim_playbook_routes ($routeCols) VALUES " . implode(',', $values);
    $stmt = sqlsrv_query($connSwim, $sql, $params);
    if ($stmt !== false) {
        sqlsrv_free_stmt($stmt);
        return true;
    }
    _swimRefLog("Route batch insert error at offset $offset: " . _swimRefErrMsg(), 'WARN');
    return false;
}

function _swimRefLog(string $message, string $level = 'INFO'): void {
    if (function_exists('logMsg')) {
        logMsg("[SWIM-REF] $message", $level);
    } else {
        $ts = gmdate('Y-m-d H:i:s');
        echo "[$ts UTC] [$level] [SWIM-REF] $message\n";
    }
}
