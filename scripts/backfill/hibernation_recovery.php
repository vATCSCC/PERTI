<?php
/**
 * Hibernation Recovery — Post-process flight data after un-hibernation.
 *
 * During hibernation, core ADL ingest (positions, trajectories, plans) continues
 * but GIS enrichment daemons are paused. This script backfills:
 *   - Route parsing (waypoints, route geometry)
 *   - Boundary detection (ARTCC/TRACON/sector assignment)
 *   - Crossing predictions (boundary entry/exit ETAs)
 *   - Waypoint ETAs (per-fix arrival time estimates)
 *   - SWIM API sync (full refresh)
 *
 * NOTE: As of March 2026, archival_daemon.php skips ALL archival steps during
 * hibernation (including sp_Archive_CompletedFlights which CASCADE-deletes source
 * data). All flights remain in core tables and are available for backfill.
 *
 * IMPORTANT: Phase 1 only queues routes for parsing — the parse_queue_gis_daemon
 * must run to actually parse them. Run Phase 1, wait for the parse daemon to drain
 * the queue, THEN run Phases 2-4 for best results. Or use --phase=all which runs
 * sequentially (but newly-parsed routes won't have crossings until Phase 3 re-runs).
 *
 * Usage:
 *   php hibernation_recovery.php --phase=0                    Diagnostic
 *   php hibernation_recovery.php --phase=1                    Route parsing queue
 *   php hibernation_recovery.php --phase=2                    Boundary detection
 *   php hibernation_recovery.php --phase=3                    Crossing prediction
 *   php hibernation_recovery.php --phase=4                    Waypoint ETA
 *   php hibernation_recovery.php --phase=5                    SWIM full sync
 *   php hibernation_recovery.php --phase=all                  Run phases 1-5
 *   php hibernation_recovery.php --phase=0 --dry-run          Preview only
 *
 * Options:
 *   --phase=N|all       Phase number (0-5) or 'all' to run 1-5 sequentially
 *   --dry-run           Show what would be done without writing changes
 *   --batch=N           Batch size for GIS operations (default: 100)
 *   --delay-hours=N     Extend archive delay before backfill (writes to adl_archive_config)
 *   --include-inactive  Process inactive flights (default: active only for phases 2-4)
 *   --verbose           Extra logging detail
 *
 * @package PERTI
 * @subpackage Backfill
 */

// ----- Bootstrap -----
define('PERTI_LOADED', true);
require_once __DIR__ . '/../../load/config.php';
require_once __DIR__ . '/../../load/connect.php';
require_once __DIR__ . '/../../load/services/GISService.php';

// ----- CLI Argument Parsing -----
$opts = getopt('', [
    'phase:',
    'dry-run',
    'batch:',
    'delay-hours:',
    'include-inactive',
    'verbose',
]);

$phase          = $opts['phase']        ?? null;
$dryRun         = isset($opts['dry-run']);
$batchSize      = (int)($opts['batch'] ?? 100);
$delayHours     = isset($opts['delay-hours']) ? (int)$opts['delay-hours'] : null;
$includeInactive = isset($opts['include-inactive']);
$verbose        = isset($opts['verbose']);

if ($phase === null) {
    fwrite(STDERR, "Usage: php hibernation_recovery.php --phase=0|1|2|3|4|5|all [--dry-run] [--batch=N]\n");
    exit(1);
}

// ----- Connections -----
$conn_adl = get_conn_adl();
if (!$conn_adl) {
    logErr("FATAL: Cannot connect to VATSIM_ADL");
    exit(1);
}

$gis = GISService::getInstance();
$gisAvailable = $gis && $gis->isConnected();

// ----- Logging Utilities -----
function logMsg(string $msg): void {
    $ts = gmdate('Y-m-d H:i:s');
    echo "[{$ts}Z] {$msg}\n";
}

function logErr(string $msg): void {
    $ts = gmdate('Y-m-d H:i:s');
    fwrite(STDERR, "[{$ts}Z] ERROR: {$msg}\n");
}

function logVerbose(string $msg): void {
    global $verbose;
    if ($verbose) {
        logMsg("  >> {$msg}");
    }
}

function logPhaseHeader(int $num, string $name): void {
    logMsg("");
    logMsg(str_repeat('=', 60));
    logMsg("PHASE {$num}: {$name}");
    logMsg(str_repeat('=', 60));
}

// ----- Helper: query ADL (sqlsrv) and return all rows -----
function adlQuery(string $sql, array $params = []): array {
    global $conn_adl;
    $stmt = @sqlsrv_query($conn_adl, $sql, $params, ['QueryTimeout' => 120]);
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        logErr("SQL error: " . json_encode($errors));
        return [];
    }
    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    return $rows;
}

// ----- Helper: execute ADL statement (returns affected rows or false) -----
function adlExec(string $sql, array $params = []) {
    global $conn_adl;
    $stmt = @sqlsrv_query($conn_adl, $sql, $params, ['QueryTimeout' => 120]);
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        logErr("SQL error: " . json_encode($errors));
        return false;
    }
    $affected = sqlsrv_rows_affected($stmt);
    sqlsrv_free_stmt($stmt);
    return $affected;
}

// =====================================================================
// PHASE 0: DIAGNOSTIC
// =====================================================================
function phase0_diagnostic(): void {
    logPhaseHeader(0, 'DIAGNOSTIC');

    // 1. Flight counts by status
    logMsg("--- Flight Counts in Core Tables ---");
    $rows = adlQuery("
        SELECT
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) AS inactive,
            COUNT(*) AS total,
            MIN(first_seen_utc) AS oldest_first_seen,
            MAX(first_seen_utc) AS newest_first_seen,
            MIN(last_seen_utc) AS oldest_last_seen,
            MAX(last_seen_utc) AS newest_last_seen
        FROM dbo.adl_flight_core
    ");
    if ($rows) {
        $r = $rows[0];
        logMsg("  Active:   {$r['active']}");
        logMsg("  Inactive: {$r['inactive']}");
        logMsg("  Total:    {$r['total']}");
        logMsg("  Oldest first_seen: " . formatDt($r['oldest_first_seen']));
        logMsg("  Newest first_seen: " . formatDt($r['newest_first_seen']));
        logMsg("  Oldest last_seen:  " . formatDt($r['oldest_last_seen']));
        logMsg("  Newest last_seen:  " . formatDt($r['newest_last_seen']));
    }

    // 2. Archive counts
    logMsg("");
    logMsg("--- Archive Table ---");
    $rows = adlQuery("
        SELECT COUNT(*) AS total,
            MIN(archived_utc) AS oldest_archived,
            MAX(archived_utc) AS newest_archived
        FROM dbo.adl_flight_archive
    ");
    if ($rows) {
        $r = $rows[0];
        logMsg("  Archived flights: {$r['total']}");
        logMsg("  Oldest archived: " . formatDt($r['oldest_archived']));
        logMsg("  Newest archived: " . formatDt($r['newest_archived']));
    }

    // 3. Parse status distribution
    logMsg("");
    logMsg("--- Route Parse Status ---");
    $rows = adlQuery("
        SELECT fp.parse_status, COUNT(*) AS cnt
        FROM dbo.adl_flight_plan fp
        JOIN dbo.adl_flight_core c ON c.flight_uid = fp.flight_uid
        GROUP BY fp.parse_status
        ORDER BY cnt DESC
    ");
    foreach ($rows as $r) {
        $status = $r['parse_status'] ?? 'NULL';
        logMsg("  {$status}: {$r['cnt']}");
    }

    // 4. Parse queue status
    logMsg("");
    logMsg("--- Parse Queue ---");
    $rows = adlQuery("
        SELECT status, COUNT(*) AS cnt
        FROM dbo.adl_parse_queue
        GROUP BY status
    ");
    if (empty($rows)) {
        logMsg("  (empty)");
    }
    foreach ($rows as $r) {
        logMsg("  {$r['status']}: {$r['cnt']}");
    }

    // 5. Boundary detection coverage
    logMsg("");
    logMsg("--- Boundary Detection Coverage ---");
    $rows = adlQuery("
        SELECT
            SUM(CASE WHEN c.current_artcc IS NOT NULL THEN 1 ELSE 0 END) AS has_artcc,
            SUM(CASE WHEN c.current_artcc IS NULL THEN 1 ELSE 0 END) AS no_artcc,
            SUM(CASE WHEN c.boundary_updated_at IS NOT NULL THEN 1 ELSE 0 END) AS has_boundary_ts,
            COUNT(*) AS total
        FROM dbo.adl_flight_core c
    ");
    if ($rows) {
        $r = $rows[0];
        logMsg("  With ARTCC:    {$r['has_artcc']}");
        logMsg("  Without ARTCC: {$r['no_artcc']}");
        logMsg("  With boundary timestamp: {$r['has_boundary_ts']}");
    }

    // 6. Crossing predictions
    logMsg("");
    logMsg("--- Crossing Predictions ---");
    $rows = adlQuery("
        SELECT
            COUNT(DISTINCT flight_uid) AS flights_with_crossings,
            COUNT(*) AS total_crossings
        FROM dbo.adl_flight_planned_crossings
    ");
    if ($rows) {
        $r = $rows[0];
        logMsg("  Flights with crossings: {$r['flights_with_crossings']}");
        logMsg("  Total crossing records: {$r['total_crossings']}");
    }

    // 7. Flights needing backfill (have position but no enrichment)
    logMsg("");
    logMsg("--- Backfill Candidates (have position, missing enrichment) ---");
    $rows = adlQuery("
        SELECT
            SUM(CASE WHEN fp.parse_status != 'COMPLETE' AND fp.fp_route IS NOT NULL
                      AND fp.fp_route != '' THEN 1 ELSE 0 END) AS needs_route_parse,
            SUM(CASE WHEN c.current_artcc IS NULL AND p.lat IS NOT NULL THEN 1 ELSE 0 END) AS needs_boundary,
            SUM(CASE WHEN fp.parse_status = 'COMPLETE' AND fp.waypoint_count >= 2
                      AND NOT EXISTS (
                          SELECT 1 FROM dbo.adl_flight_planned_crossings x
                          WHERE x.flight_uid = c.flight_uid
                      ) THEN 1 ELSE 0 END) AS needs_crossings,
            COUNT(*) AS total_in_core
        FROM dbo.adl_flight_core c
        LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    ");
    if ($rows) {
        $r = $rows[0];
        logMsg("  Need route parsing:    {$r['needs_route_parse']}");
        logMsg("  Need boundary detect:  {$r['needs_boundary']}");
        logMsg("  Need crossing predict: {$r['needs_crossings']}");
        logMsg("  Total in core tables:  {$r['total_in_core']}");
    }

    // 8. Waypoint ETA coverage (active only)
    logMsg("");
    logMsg("--- Waypoint ETA Coverage (Active Flights) ---");
    $rows = adlQuery("
        SELECT
            SUM(CASE WHEN w.has_eta = 1 THEN 1 ELSE 0 END) AS with_eta,
            SUM(CASE WHEN w.has_eta = 0 OR w.has_eta IS NULL THEN 1 ELSE 0 END) AS without_eta
        FROM dbo.adl_flight_core c
        LEFT JOIN (
            SELECT flight_uid, MAX(CASE WHEN eta_utc IS NOT NULL THEN 1 ELSE 0 END) AS has_eta
            FROM dbo.adl_flight_waypoints
            GROUP BY flight_uid
        ) w ON w.flight_uid = c.flight_uid
        WHERE c.is_active = 1
    ");
    if ($rows) {
        $r = $rows[0];
        logMsg("  Active with waypoint ETAs:    {$r['with_eta']}");
        logMsg("  Active without waypoint ETAs: {$r['without_eta']}");
    }

    // 9. Archive config
    logMsg("");
    logMsg("--- Archive Configuration ---");
    $rows = adlQuery("
        SELECT config_key, config_value
        FROM dbo.adl_archive_config
        WHERE config_key IN ('COMPLETED_FLIGHT_DELAY_HOURS', 'TRAJECTORY_HOT_HOURS',
                             'TRAJECTORY_WARM_DAYS', 'TRAJECTORY_COLD_DAYS')
    ");
    foreach ($rows as $r) {
        logMsg("  {$r['config_key']}: {$r['config_value']}");
    }

    // 10. SWIM sync status
    logMsg("");
    logMsg("--- SWIM API Sync Status ---");
    $conn_swim = function_exists('get_conn_swim') ? get_conn_swim() : null;
    if ($conn_swim) {
        $stmt = @sqlsrv_query($conn_swim, "
            SELECT COUNT(*) AS total,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active,
                MAX(last_sync_utc) AS last_sync
            FROM dbo.swim_flights
        ");
        if ($stmt) {
            $r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            if ($r) {
                logMsg("  SWIM flights total:  {$r['total']}");
                logMsg("  SWIM flights active: {$r['active']}");
                logMsg("  Last sync: " . formatDt($r['last_sync']));
            }
            sqlsrv_free_stmt($stmt);
        }
    } else {
        logMsg("  (SWIM_API connection not available)");
    }

    // 11. Backfill chain feasibility
    logMsg("");
    logMsg("--- Backfill Chain Feasibility ---");
    $rows = adlQuery("
        SELECT
            SUM(CASE WHEN fp.parse_status IS NULL OR fp.parse_status IN ('PENDING','FAILED','PARTIAL') THEN 1 ELSE 0 END) AS unparsed,
            SUM(CASE WHEN (fp.parse_status IS NULL OR fp.parse_status IN ('PENDING','FAILED','PARTIAL'))
                      AND fp.fp_route IS NOT NULL AND fp.fp_route != '' THEN 1 ELSE 0 END) AS parseable,
            SUM(CASE WHEN (fp.parse_status IS NULL OR fp.parse_status IN ('PENDING','FAILED','PARTIAL'))
                      AND (fp.fp_route IS NULL OR fp.fp_route = '') THEN 1 ELSE 0 END) AS no_route_permanent,
            SUM(CASE WHEN fp.parse_status = 'COMPLETE' THEN 1 ELSE 0 END) AS already_parsed,
            COUNT(*) AS total
        FROM dbo.adl_flight_core c
        LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
    ");
    if ($rows) {
        $r = $rows[0];
        logMsg("  Parseable (have fp_route):      {$r['parseable']}");
        logMsg("  Already parsed (COMPLETE):       {$r['already_parsed']}");
        logMsg("  Permanently unparsable (no route): {$r['no_route_permanent']}");
    }

    logMsg("");
    logMsg("--- Recommended Backfill Sequence ---");
    logMsg("  1. Run Phase 1 (--phase=1 --include-inactive) to queue routes");
    logMsg("  2. Start parse_queue_gis_daemon — wait for queue to drain");
    logMsg("  3. Run Phase 2 (--phase=2 --include-inactive) for boundary detection");
    logMsg("  4. Run Phase 3 (--phase=3 --include-inactive) for crossing prediction");
    logMsg("  5. Run Phase 4 (--phase=4) for waypoint ETAs (active flights only)");
    logMsg("  6. Run Phase 5 (--phase=5) for SWIM full sync");

    logMsg("");
    logMsg("Diagnostic complete.");
}

function formatDt($dt): string {
    if ($dt === null) return '(null)';
    if ($dt instanceof DateTime) return $dt->format('Y-m-d H:i:s') . 'Z';
    return (string)$dt;
}

// =====================================================================
// PHASE 1: ROUTE PARSING — Queue unparsed routes
// =====================================================================
function phase1_routeParsing(bool $dryRun, bool $includeInactive): void {
    logPhaseHeader(1, 'ROUTE PARSING QUEUE');

    // The parse queue daemon's claim query does NOT filter on is_active,
    // so we can queue both active and inactive flights.
    $activeFilter = $includeInactive ? '' : 'AND c.is_active = 1';

    // Count candidates
    $rows = adlQuery("
        SELECT COUNT(*) AS cnt
        FROM dbo.adl_flight_plan fp
        JOIN dbo.adl_flight_core c ON c.flight_uid = fp.flight_uid
        LEFT JOIN dbo.adl_parse_queue pq ON pq.flight_uid = fp.flight_uid
        WHERE (fp.parse_status IS NULL OR fp.parse_status IN ('PENDING', 'FAILED'))
          AND fp.fp_route IS NOT NULL
          AND fp.fp_route != ''
          AND (pq.flight_uid IS NULL OR pq.status = 'FAILED')
          {$activeFilter}
    ");
    $count = $rows[0]['cnt'] ?? 0;
    logMsg("Flights needing route parsing: {$count}");

    if ($count === 0) {
        logMsg("Nothing to queue.");
        return;
    }

    if ($dryRun) {
        logMsg("[DRY RUN] Would queue {$count} flights for route parsing.");
        return;
    }

    // Queue them — INSERT with MERGE pattern to avoid duplicates
    $affected = adlExec("
        INSERT INTO dbo.adl_parse_queue (flight_uid, parse_tier, status, queued_utc, next_eligible_utc)
        SELECT fp.flight_uid,
               COALESCE(fp.parse_tier, 2),
               'PENDING',
               SYSUTCDATETIME(),
               SYSUTCDATETIME()
        FROM dbo.adl_flight_plan fp
        JOIN dbo.adl_flight_core c ON c.flight_uid = fp.flight_uid
        LEFT JOIN dbo.adl_parse_queue pq ON pq.flight_uid = fp.flight_uid
        WHERE (fp.parse_status IS NULL OR fp.parse_status IN ('PENDING', 'FAILED'))
          AND fp.fp_route IS NOT NULL
          AND fp.fp_route != ''
          AND pq.flight_uid IS NULL
          {$activeFilter}
    ");

    // Also reset FAILED items to PENDING
    $resetCount = adlExec("
        UPDATE dbo.adl_parse_queue
        SET status = 'PENDING',
            next_eligible_utc = SYSUTCDATETIME()
        WHERE status = 'FAILED'
    ");

    logMsg("Queued {$affected} new flights for route parsing.");
    if ($resetCount > 0) {
        logMsg("Reset {$resetCount} FAILED queue items to PENDING.");
    }
    logMsg("The parse_queue_gis_daemon will process these when running.");
}

// =====================================================================
// PHASE 2: BOUNDARY DETECTION
// =====================================================================
function phase2_boundaryDetection(bool $dryRun, int $batchSize, bool $includeInactive): void {
    global $gis, $gisAvailable;

    logPhaseHeader(2, 'BOUNDARY DETECTION');

    if (!$gisAvailable) {
        logErr("PostGIS not available — cannot run boundary detection.");
        logErr("Ensure VATSIM_GIS database is accessible and un-paused.");
        return;
    }

    $activeFilter = $includeInactive ? '' : 'AND c.is_active = 1';

    // Count candidates: flights with position but no ARTCC assigned
    $rows = adlQuery("
        SELECT COUNT(*) AS cnt
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
        WHERE c.current_artcc IS NULL
          AND p.lat IS NOT NULL
          AND p.lat BETWEEN -90 AND 90
          AND p.lon BETWEEN -180 AND 180
          {$activeFilter}
    ");
    $totalCount = $rows[0]['cnt'] ?? 0;
    logMsg("Flights needing boundary detection: {$totalCount}");

    if ($totalCount === 0) {
        logMsg("Nothing to process.");
        return;
    }

    if ($dryRun) {
        logMsg("[DRY RUN] Would process {$totalCount} flights through PostGIS boundary detection.");
        return;
    }

    // Process in batches (cursor-based pagination via flight_uid)
    $processed = 0;
    $updated = 0;
    $errors = 0;
    $lastUid = 0;

    while ($processed < $totalCount) {
        $flights = adlQuery("
            SELECT TOP ({$batchSize})
                c.flight_uid,
                p.lat,
                p.lon,
                ISNULL(p.altitude_ft, 0) AS altitude_ft
            FROM dbo.adl_flight_core c
            JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
            WHERE c.current_artcc IS NULL
              AND p.lat IS NOT NULL
              AND p.lat BETWEEN -90 AND 90
              AND p.lon BETWEEN -180 AND 180
              AND c.flight_uid > ?
              {$activeFilter}
            ORDER BY c.flight_uid ASC
        ", [$lastUid]);

        if (empty($flights)) break;

        $lastUid = end($flights)['flight_uid'];

        // Format for GISService
        $gisInput = array_map(function($f) {
            return [
                'flight_uid' => (int)$f['flight_uid'],
                'lat'        => (float)$f['lat'],
                'lon'        => (float)$f['lon'],
                'altitude'   => (int)$f['altitude_ft'],
            ];
        }, $flights);

        // Call PostGIS
        $results = $gis->detectBoundariesAndSectorsBatch($gisInput);

        if (empty($results)) {
            logVerbose("Batch returned empty results (batch of " . count($flights) . ")");
            $processed += count($flights);
            continue;
        }

        // Index results by flight_uid
        $resultMap = [];
        foreach ($results as $r) {
            $resultMap[$r['flight_uid']] = $r;
        }

        // Write results back to ADL
        foreach ($flights as $f) {
            $uid = $f['flight_uid'];
            $r = $resultMap[$uid] ?? null;

            if (!$r || empty($r['artcc_code'])) {
                // No boundary found (e.g., oceanic, outside coverage)
                continue;
            }

            $ok = adlExec("
                UPDATE dbo.adl_flight_core
                SET current_artcc = ?,
                    current_tracon = ?,
                    current_sector_low = ?,
                    current_sector_high = ?,
                    current_sector_superhigh = ?,
                    boundary_updated_at = SYSUTCDATETIME()
                WHERE flight_uid = ?
            ", [
                $r['artcc_code'],
                $r['tracon_code'] ?? null,
                $r['sector_low'] ?? null,
                $r['sector_high'] ?? null,
                $r['sector_superhigh'] ?? null,
                $uid,
            ]);

            if ($ok !== false) {
                $updated++;
            } else {
                $errors++;
            }
        }

        $processed += count($flights);
        $pct = round(($processed / $totalCount) * 100);
        logMsg("  Boundary: {$processed}/{$totalCount} processed ({$pct}%), {$updated} updated, {$errors} errors");

        // Safety: if we got fewer than batch size, we're done
        if (count($flights) < $batchSize) break;
    }

    logMsg("Boundary detection complete: {$updated} flights updated, {$errors} errors.");
}

// =====================================================================
// PHASE 3: CROSSING PREDICTION
// =====================================================================
function phase3_crossingPrediction(bool $dryRun, int $batchSize, bool $includeInactive): void {
    global $gis, $gisAvailable;

    logPhaseHeader(3, 'CROSSING PREDICTION');

    if (!$gisAvailable) {
        logErr("PostGIS not available — cannot run crossing prediction.");
        return;
    }

    $activeFilter = $includeInactive ? '' : 'AND c.is_active = 1';

    // Count candidates: parsed routes with no crossings yet
    $rows = adlQuery("
        SELECT COUNT(*) AS cnt
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
        WHERE fp.parse_status = 'COMPLETE'
          AND fp.waypoint_count >= 2
          AND p.lat IS NOT NULL
          AND p.lon IS NOT NULL
          AND NOT EXISTS (
              SELECT 1 FROM dbo.adl_flight_planned_crossings x
              WHERE x.flight_uid = c.flight_uid
          )
          {$activeFilter}
    ");
    $totalCount = $rows[0]['cnt'] ?? 0;
    logMsg("Flights needing crossing prediction: {$totalCount}");

    if ($totalCount === 0) {
        logMsg("Nothing to process.");
        return;
    }

    if ($dryRun) {
        logMsg("[DRY RUN] Would process {$totalCount} flights through PostGIS crossing prediction.");
        return;
    }

    $processed = 0;
    $flightsWithCrossings = 0;
    $totalCrossings = 0;
    $errors = 0;
    $lastUid = 0;

    while ($processed < $totalCount) {
        // Fetch batch of flights
        $flights = adlQuery("
            SELECT TOP ({$batchSize})
                c.flight_uid,
                p.lat AS current_lat,
                p.lon AS current_lon,
                ISNULL(p.groundspeed_kts, 450) AS groundspeed_kts,
                ISNULL(p.dist_flown_nm, 0) AS dist_flown_nm,
                c.last_seen_utc
            FROM dbo.adl_flight_core c
            JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
            JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
            WHERE fp.parse_status = 'COMPLETE'
              AND fp.waypoint_count >= 2
              AND p.lat IS NOT NULL
              AND p.lon IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1 FROM dbo.adl_flight_planned_crossings x
                  WHERE x.flight_uid = c.flight_uid
              )
              AND c.flight_uid > ?
              {$activeFilter}
            ORDER BY c.flight_uid ASC
        ", [$lastUid]);

        if (empty($flights)) break;
        $lastUid = end($flights)['flight_uid'];

        // Fetch waypoints for entire batch in one query (much faster than per-flight)
        $uids = array_column($flights, 'flight_uid');
        $uidPlaceholders = implode(',', $uids);
        $allWaypoints = adlQuery("
            SELECT flight_uid, fix_name, lat, lon, sequence_num, cum_dist_nm
            FROM dbo.adl_flight_waypoints
            WHERE flight_uid IN ({$uidPlaceholders})
            ORDER BY flight_uid, sequence_num ASC
        ");

        // Group waypoints by flight_uid
        $waypointsByFlight = [];
        foreach ($allWaypoints as $wp) {
            $waypointsByFlight[$wp['flight_uid']][] = $wp;
        }

        // Build batch input for calculateCrossingsBatch()
        $batchInput = [];
        $flightMap = [];
        foreach ($flights as $f) {
            $uid = $f['flight_uid'];
            $wps = $waypointsByFlight[$uid] ?? [];
            if (count($wps) < 2) {
                $processed++;
                continue;
            }

            $refTime = ($f['last_seen_utc'] instanceof DateTime)
                ? $f['last_seen_utc']->format('Y-m-d H:i:s')
                : gmdate('Y-m-d H:i:s');

            $batchInput[] = [
                'flight_uid'     => (int)$uid,
                'waypoints'      => array_map(function($wp) {
                    return [
                        'lat'          => (float)$wp['lat'],
                        'lon'          => (float)$wp['lon'],
                        'sequence_num' => (int)$wp['sequence_num'],
                        'fix_name'     => $wp['fix_name'],
                    ];
                }, $wps),
                'current_lat'    => (float)$f['current_lat'],
                'current_lon'    => (float)$f['current_lon'],
                'dist_flown_nm'  => (float)$f['dist_flown_nm'],
                'groundspeed_kts'=> max((int)$f['groundspeed_kts'], 100),
                'current_time'   => $refTime,
            ];
            $flightMap[$uid] = true;
        }

        if (empty($batchInput)) {
            if (count($flights) < $batchSize) break;
            continue;
        }

        // Use batch method — single PostGIS round-trip for entire batch
        try {
            $batchResults = $gis->calculateCrossingsBatch($batchInput);
        } catch (\Exception $e) {
            logErr("Batch crossing error: " . $e->getMessage());
            $errors += count($batchInput);
            $processed += count($batchInput);
            if (count($flights) < $batchSize) break;
            continue;
        }

        // Write results to ADL
        foreach ($batchResults as $uid => $crossings) {
            if (empty($crossings)) {
                $processed++;
                continue;
            }

            // Delete any existing (shouldn't exist, but safety)
            adlExec("DELETE FROM dbo.adl_flight_planned_crossings WHERE flight_uid = ?", [$uid]);

            $order = 0;
            foreach ($crossings as $c) {
                $order++;
                // Convert eta_utc string to DateTime for sqlsrv (matches daemon pattern)
                $etaParam = null;
                if (!empty($c['eta_utc'])) {
                    try {
                        $etaParam = new DateTime($c['eta_utc']);
                    } catch (\Exception $e) {
                        $etaParam = null;
                    }
                }

                adlExec("
                    INSERT INTO dbo.adl_flight_planned_crossings
                        (flight_uid, crossing_source, boundary_code, boundary_type,
                         crossing_type, crossing_order, planned_entry_utc,
                         entry_lat, entry_lon, calculated_at, calculation_tier)
                    VALUES (?, 'BACKFILL', ?, ?, ?, ?, ?, ?, ?, SYSUTCDATETIME(), 99)
                ", [
                    $uid,
                    $c['boundary_code'] ?? '',
                    $c['boundary_type'] ?? 'ARTCC',
                    $c['crossing_type'] ?? 'ENTRY',
                    $order,
                    $etaParam,
                    $c['crossing_lat'] ?? null,
                    $c['crossing_lon'] ?? null,
                ]);
            }

            $flightsWithCrossings++;
            $totalCrossings += $order;
            $processed++;
        }

        // Count flights in batch that had no crossings
        $processed += count($flightMap) - count($batchResults);

        $pct = $totalCount > 0 ? round(($processed / $totalCount) * 100) : 100;
        logMsg("  Crossings: {$processed}/{$totalCount} ({$pct}%), {$flightsWithCrossings} with crossings, {$totalCrossings} total");

        if (count($flights) < $batchSize) break;
    }

    logMsg("Crossing prediction complete: {$flightsWithCrossings} flights, {$totalCrossings} crossings, {$errors} errors.");
}

// =====================================================================
// PHASE 4: WAYPOINT ETA
// =====================================================================
function phase4_waypointEta(bool $dryRun, int $batchSize): void {
    logPhaseHeader(4, 'WAYPOINT ETA');

    // Waypoint ETA only makes sense for active flights (ETAs relative to now)
    $rows = adlQuery("
        SELECT COUNT(*) AS cnt
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        WHERE c.is_active = 1
          AND c.phase NOT IN ('arrived')
          AND fp.waypoint_count >= 2
          AND EXISTS (
              SELECT 1 FROM dbo.adl_flight_waypoints w
              WHERE w.flight_uid = c.flight_uid
          )
    ");
    $count = $rows[0]['cnt'] ?? 0;
    logMsg("Active flights eligible for waypoint ETA: {$count}");

    if ($count === 0) {
        logMsg("Nothing to process.");
        return;
    }

    if ($dryRun) {
        logMsg("[DRY RUN] Would calculate waypoint ETAs for {$count} active flights.");
        return;
    }

    // Call the existing SP for each tier (0-4), processing all tiers at once
    // The SP itself filters is_active = 1, so this works naturally
    $totalProcessed = 0;
    $totalWaypoints = 0;

    for ($tier = 0; $tier <= 4; $tier++) {
        $rows = adlQuery("
            EXEC dbo.sp_CalculateWaypointETABatch_Tiered
                @tier = ?,
                @max_flights = ?,
                @debug = 0
        ", [$tier, $batchSize]);

        if ($rows) {
            $r = $rows[0];
            $fp = $r['flights_processed'] ?? 0;
            $wu = $r['waypoints_updated'] ?? 0;
            $ms = $r['elapsed_ms'] ?? 0;
            $totalProcessed += $fp;
            $totalWaypoints += $wu;
            logMsg("  Tier {$tier}: {$fp} flights, {$wu} waypoints updated ({$ms}ms)");
        }
    }

    logMsg("Waypoint ETA complete: {$totalProcessed} flights, {$totalWaypoints} waypoints updated.");
}

// =====================================================================
// PHASE 5: SWIM FULL SYNC
// =====================================================================
function phase5_swimSync(bool $dryRun): void {
    logPhaseHeader(5, 'SWIM FULL SYNC');

    $conn_swim = function_exists('get_conn_swim') ? get_conn_swim() : null;
    if (!$conn_swim) {
        logErr("SWIM_API connection not available. Skipping.");
        return;
    }

    // Count current SWIM flights
    $stmt = @sqlsrv_query($conn_swim, "
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active
        FROM dbo.swim_flights
    ");
    if ($stmt) {
        $r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        logMsg("Current SWIM state: {$r['total']} total, {$r['active']} active");
        sqlsrv_free_stmt($stmt);
    }

    // Count active ADL flights that should be in SWIM
    $rows = adlQuery("
        SELECT COUNT(*) AS cnt FROM dbo.adl_flight_core WHERE is_active = 1
    ");
    $adlActive = $rows[0]['cnt'] ?? 0;
    logMsg("Active ADL flights to sync: {$adlActive}");

    if ($dryRun) {
        logMsg("[DRY RUN] Would reset SWIM sync marker to trigger full resync.");
        return;
    }

    // Reset the sync marker to epoch — next swim_sync_daemon cycle will do a full pull
    $resetStmt = @sqlsrv_query($conn_swim, "
        UPDATE dbo.swim_flights
        SET last_sync_utc = '2000-01-01 00:00:00'
        WHERE is_active = 1
    ");
    if ($resetStmt !== false) {
        $affected = sqlsrv_rows_affected($resetStmt);
        sqlsrv_free_stmt($resetStmt);
        logMsg("Reset last_sync_utc on {$affected} SWIM flights.");
    }

    // Also mark all SWIM flights inactive so the next sync rebuilds cleanly
    $markStmt = @sqlsrv_query($conn_swim, "
        UPDATE dbo.swim_flights SET is_active = 0
    ");
    if ($markStmt !== false) {
        sqlsrv_free_stmt($markStmt);
    }

    logMsg("SWIM sync marker reset. The swim_sync_daemon will perform a full resync on next cycle.");
    logMsg("Verify via: php scripts/swim_sync.php (or wait for daemon)");
}

// =====================================================================
// ARCHIVE DELAY EXTENSION
// =====================================================================
function extendArchiveDelay(int $hours, bool $dryRun): void {
    logMsg("");
    logMsg("--- Extending Archive Delay ---");

    $rows = adlQuery("
        SELECT config_value
        FROM dbo.adl_archive_config
        WHERE config_key = 'COMPLETED_FLIGHT_DELAY_HOURS'
    ");
    $current = $rows[0]['config_value'] ?? '2';
    logMsg("Current COMPLETED_FLIGHT_DELAY_HOURS: {$current}");
    logMsg("Requested: {$hours}");

    if ($dryRun) {
        logMsg("[DRY RUN] Would set COMPLETED_FLIGHT_DELAY_HOURS to {$hours}.");
        return;
    }

    $affected = adlExec("
        UPDATE dbo.adl_archive_config
        SET config_value = ?
        WHERE config_key = 'COMPLETED_FLIGHT_DELAY_HOURS'
    ", [(string)$hours]);

    if ($affected !== false) {
        logMsg("Archive delay set to {$hours} hours. Remember to reset after backfill!");
    } else {
        logErr("Failed to update archive delay.");
    }
}

// =====================================================================
// MAIN
// =====================================================================
logMsg("Hibernation Recovery Script");
logMsg("Phase: {$phase} | Dry run: " . ($dryRun ? 'YES' : 'no') . " | Batch: {$batchSize}");
logMsg("GIS available: " . ($gisAvailable ? 'YES' : 'NO'));
logMsg("Include inactive: " . ($includeInactive ? 'YES' : 'no'));

// Extend archive delay if requested
if ($delayHours !== null) {
    extendArchiveDelay($delayHours, $dryRun);
}

$startTime = microtime(true);

if ($phase === '0' || $phase === 'all') {
    phase0_diagnostic();
}

if ($phase === '1' || $phase === 'all') {
    phase1_routeParsing($dryRun, $includeInactive);
}

if ($phase === '2' || $phase === 'all') {
    phase2_boundaryDetection($dryRun, $batchSize, $includeInactive);
}

if ($phase === '3' || $phase === 'all') {
    phase3_crossingPrediction($dryRun, $batchSize, $includeInactive);
}

if ($phase === '4' || $phase === 'all') {
    phase4_waypointEta($dryRun, $batchSize);
}

if ($phase === '5' || $phase === 'all') {
    phase5_swimSync($dryRun);
}

$elapsed = round(microtime(true) - $startTime, 1);
logMsg("");
logMsg("Done in {$elapsed}s.");
