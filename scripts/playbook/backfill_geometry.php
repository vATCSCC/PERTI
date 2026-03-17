<?php
/**
 * HTTP-triggered batch backfill for playbook route geometry + traversed facilities.
 *
 * Processes routes in batches via HTTP requests. Computes route_geometry envelope
 * (waypoints, GeoJSON, distance) and traversed facilities (ARTCCs, TRACONs, sectors)
 * for all routes that currently have NULL route_geometry.
 *
 * Uses the same PostGIS expand_route_with_artccs() pipeline as recompute_traversed.php
 * but designed for HTTP-triggered execution on Azure App Service where PHP CLI is
 * unavailable in the Kudu container.
 *
 * Usage:
 *   ?action=status   — Show progress
 *   ?action=run      — Process next batch (default 500 routes)
 *   ?action=reset    — Reset state to start over
 *   ?action=log      — Show recent log entries
 *
 * Run via curl loop:
 *   for i in $(seq 1 600); do curl -s --max-time 120 "https://perti.vatcscc.org/scripts/playbook/backfill_geometry.php?action=run" -o /dev/null; sleep 2; done
 */

// Increase execution limits for batch processing
set_time_limit(90);
ini_set('memory_limit', '256M');

header('Content-Type: application/json');

include_once(__DIR__ . '/../../load/config.php');
include_once(__DIR__ . '/../../load/input.php');
include_once(__DIR__ . '/../../load/connect.php');

$action = $_GET['action'] ?? 'status';
$batch_size = min((int)($_GET['batch'] ?? 500), 1000);
if ($batch_size < 1) $batch_size = 500;

// ============================================================================
// State Management (MySQL)
// ============================================================================

/**
 * Ensure state table exists in perti_site (MySQL).
 */
function ensureStateTable($conn_pdo) {
    $conn_pdo->exec("CREATE TABLE IF NOT EXISTS playbook_backfill_state (
        `key` VARCHAR(50) PRIMARY KEY,
        `value` TEXT NOT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
}

function getState($conn_pdo, $key, $default = null) {
    $stmt = $conn_pdo->prepare("SELECT `value` FROM playbook_backfill_state WHERE `key` = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['value'] : $default;
}

function setState($conn_pdo, $key, $value) {
    $stmt = $conn_pdo->prepare("INSERT INTO playbook_backfill_state (`key`, `value`) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
    $stmt->execute([$key, (string)$value]);
}

function appendLog($conn_pdo, $msg) {
    $ts = gmdate('Y-m-d H:i:s');
    $conn_pdo->prepare("INSERT INTO playbook_backfill_state (`key`, `value`)
        VALUES (CONCAT('log_', ?), ?)
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")
        ->execute([$ts . '_' . substr(md5($msg), 0, 6), "[$ts] $msg"]);
}

// ============================================================================
// Normalization helpers (same as recompute_traversed.php)
// ============================================================================

function normalizeCanadianArtcc($code) {
    static $map = [
        'CZE' => 'CZEG', 'CZU' => 'CZUL', 'CZV' => 'CZVR',
        'CZW' => 'CZWG', 'CZY' => 'CZYZ', 'CZM' => 'CZQM',
        'CZQ' => 'CZQX', 'CZO' => 'CZQO',
        'PAZA' => 'ZAN',
    ];
    $code = strtoupper(trim($code));
    if (preg_match('/^KZ[A-Z]{2}$/', $code)) $code = substr($code, 1);
    return $map[$code] ?? $code;
}

function normalizeCanadianArtccCsv($csv) {
    if (trim($csv) === '') return $csv;
    return implode(',', array_map('normalizeCanadianArtcc', explode(',', $csv)));
}

function normalizeRouteCanadian($rs) {
    static $codes = ['CZE','CZU','CZV','CZW','CZY','CZM','CZQ','CZO'];
    $parts = preg_split('/\s+/', trim($rs));
    foreach ($parts as &$p) {
        if (in_array(strtoupper($p), $codes)) {
            $p = normalizeCanadianArtcc($p);
        }
    }
    return implode(' ', $parts);
}

function extractEndpoint($label, $airportsCsv, $artccsCsv) {
    if ($airportsCsv !== '') {
        $first = strtoupper(trim(explode(',', $airportsCsv)[0]));
        if ($first !== '' && preg_match('/^[A-Z]{3,4}$/', $first)) return $first;
    }
    $label = strtoupper(trim($label));
    if ($label !== '' && preg_match('/^[A-Z][A-Z0-9]{1,4}$/', $label)) return $label;
    if ($artccsCsv !== '') {
        $first = strtoupper(trim(explode(',', $artccsCsv)[0]));
        if ($first !== '' && $first !== 'UNKN' && $first !== 'VARIOUS' && preg_match('/^[A-Z]{2,4}$/', $first)) return $first;
    }
    return '';
}

// ============================================================================
// Actions
// ============================================================================

ensureStateTable($conn_pdo);

switch ($action) {
    case 'status':
        $total_null = $conn_sqli->query("SELECT COUNT(*) as c FROM playbook_routes WHERE route_geometry IS NULL")->fetch_assoc()['c'];
        $total_all = $conn_sqli->query("SELECT COUNT(*) as c FROM playbook_routes")->fetch_assoc()['c'];
        $total_done = $total_all - $total_null;
        echo json_encode([
            'status' => 'ok',
            'total_routes' => (int)$total_all,
            'completed' => (int)$total_done,
            'remaining' => (int)$total_null,
            'last_route_id' => (int)getState($conn_pdo, 'geom_last_route_id', 0),
            'total_processed' => (int)getState($conn_pdo, 'geom_total_processed', 0),
            'total_errors' => (int)getState($conn_pdo, 'geom_total_errors', 0),
            'started_at' => getState($conn_pdo, 'geom_started_at'),
            'last_batch_at' => getState($conn_pdo, 'geom_last_batch_at'),
            'batch_size' => $batch_size,
        ]);
        break;

    case 'reset':
        setState($conn_pdo, 'geom_last_route_id', '0');
        setState($conn_pdo, 'geom_total_processed', '0');
        setState($conn_pdo, 'geom_total_errors', '0');
        setState($conn_pdo, 'geom_started_at', '');
        setState($conn_pdo, 'geom_last_batch_at', '');
        appendLog($conn_pdo, 'State reset');
        echo json_encode(['status' => 'ok', 'message' => 'State reset']);
        break;

    case 'log':
        $stmt = $conn_pdo->query("SELECT `key`, `value` FROM playbook_backfill_state
            WHERE `key` LIKE 'log_%' ORDER BY `key` DESC LIMIT 50");
        $logs = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $logs[] = $row['value'];
        }
        echo json_encode(['status' => 'ok', 'logs' => $logs]);
        break;

    case 'run':
        runBatch($conn_pdo, $conn_sqli, $batch_size);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Unknown action: ' . $action]);
}

// ============================================================================
// Batch Processing
// ============================================================================

function runBatch($conn_pdo, $conn_sqli, $batch_size) {
    $conn_gis = get_conn_gis();
    if (!$conn_gis) {
        echo json_encode(['status' => 'error', 'message' => 'PostGIS connection failed']);
        return;
    }

    // Initialize start time on first run
    if (!getState($conn_pdo, 'geom_started_at')) {
        setState($conn_pdo, 'geom_started_at', gmdate('Y-m-d H:i:s'));
    }

    $last_id = (int)getState($conn_pdo, 'geom_last_route_id', 0);

    // Fetch next batch — routes missing geometry OR traversed facilities
    // Re-process all routes with NULL route_geometry (includes those that
    // previously had geometry but lost it, or never had it computed)
    $stmt = $conn_sqli->prepare("SELECT route_id, play_id, route_string,
        origin, dest, origin_airports, dest_airports,
        origin_artccs, dest_artccs
        FROM playbook_routes
        WHERE route_geometry IS NULL AND route_id > ?
        ORDER BY route_id LIMIT ?");
    $stmt->bind_param('ii', $last_id, $batch_size);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        setState($conn_pdo, 'geom_last_batch_at', gmdate('Y-m-d H:i:s'));
        appendLog($conn_pdo, 'Backfill complete — no more routes to process');
        echo json_encode([
            'status' => 'complete',
            'message' => 'No more routes to process',
            'total_processed' => (int)getState($conn_pdo, 'geom_total_processed', 0),
            'total_errors' => (int)getState($conn_pdo, 'geom_total_errors', 0),
        ]);
        return;
    }

    // Prepare PostGIS query
    $gis_sql = "WITH route AS (
                    SELECT waypoints, artccs_traversed, route_geometry AS geom,
                           ST_AsGeoJSON(route_geometry) AS geojson,
                           ST_Length(route_geometry::geography) / 1852.0 AS distance_nm
                    FROM expand_route_with_artccs(?)
                )
                SELECT
                    route.geojson,
                    route.distance_nm,
                    route.waypoints::text AS waypoints_json,
                    sub.btype, sub.code
                FROM route
                LEFT JOIN LATERAL (
                    SELECT sub2.btype, sub2.code, sub2.trav_order FROM (
                        SELECT 'artcc' AS btype, u.code, u.ord AS trav_order
                        FROM unnest(route.artccs_traversed) WITH ORDINALITY AS u(code, ord)
                        WHERE route.geom IS NOT NULL
                        UNION ALL
                        SELECT 'tracon', t.tracon_code,
                            ST_LineLocatePoint(route.geom, ST_Centroid(ST_Intersection(route.geom, t.geom)))
                        FROM tracon_boundaries t WHERE ST_Intersects(route.geom, t.geom)
                            AND route.geom IS NOT NULL
                        UNION ALL
                        SELECT CONCAT('sector_', LOWER(s.sector_type)), s.sector_code,
                            ST_LineLocatePoint(route.geom, ST_Centroid(ST_Intersection(route.geom, s.geom)))
                        FROM sector_boundaries s WHERE ST_Intersects(route.geom, s.geom)
                            AND route.geom IS NOT NULL
                    ) sub2
                    ORDER BY
                        CASE WHEN sub2.btype = 'artcc' THEN 1
                             WHEN sub2.btype = 'tracon' THEN 2
                             ELSE 3 END,
                        sub2.trav_order
                ) sub ON true";
    $gis_stmt = $conn_gis->prepare($gis_sql);

    // Prepare MySQL update
    $update_stmt = $conn_sqli->prepare("UPDATE playbook_routes SET
        traversed_artccs = ?, traversed_tracons = ?,
        traversed_sectors_low = ?, traversed_sectors_high = ?, traversed_sectors_superhigh = ?,
        route_geometry = ?
        WHERE route_id = ?");

    $batch_processed = 0;
    $batch_errors = 0;
    $batch_max_id = $last_id;
    $start_time = microtime(true);

    while ($row = $result->fetch_assoc()) {
        $route_id = (int)$row['route_id'];
        $batch_max_id = $route_id;
        $rs = normalizeRouteCanadian(strtoupper(trim($row['route_string'])));
        $oar = normalizeCanadianArtccCsv($row['origin_artccs'] ?? '');
        $dar = normalizeCanadianArtccCsv($row['dest_artccs'] ?? '');

        // Build full route string with origin/dest endpoints
        $fullRoute = $rs;
        $origEndpoint = extractEndpoint($row['origin'] ?? '', $row['origin_airports'] ?? '', $oar);
        $destEndpoint = extractEndpoint($row['dest'] ?? '', $row['dest_airports'] ?? '', $dar);

        $routeParts = preg_split('/\s+/', $fullRoute);
        $firstToken = strtoupper($routeParts[0] ?? '');
        $lastToken = strtoupper($routeParts[count($routeParts) - 1] ?? '');
        if ($origEndpoint && $origEndpoint !== $firstToken && $origEndpoint !== 'UNKN' && $origEndpoint !== 'VARIOUS') {
            $fullRoute = $origEndpoint . ' ' . $fullRoute;
        }
        if ($destEndpoint && $destEndpoint !== $lastToken && $destEndpoint !== 'UNKN' && $destEndpoint !== 'VARIOUS') {
            $fullRoute = $fullRoute . ' ' . $destEndpoint;
        }

        $artccs = [];
        $tracons = [];
        $sectors_low = [];
        $sectors_high = [];
        $sectors_superhigh = [];
        $geojson_raw = null;
        $distance_nm = null;
        $waypoints_raw = null;

        try {
            $gis_stmt->execute([$fullRoute]);

            while ($r = $gis_stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($geojson_raw === null) {
                    $geojson_raw = $r['geojson'];
                    $distance_nm = $r['distance_nm'];
                    $waypoints_raw = $r['waypoints_json'];
                }
                $code = $r['code'] ?? null;
                if ($code === null) continue;

                switch ($r['btype']) {
                    case 'artcc':
                        $code = normalizeCanadianArtcc($code);
                        $artccs[] = $code;
                        break;
                    case 'tracon': $tracons[] = $code; break;
                    case 'sector_low': $sectors_low[] = $code; break;
                    case 'sector_high': $sectors_high[] = $code; break;
                    case 'sector_superhigh': $sectors_superhigh[] = $code; break;
                }
            }
        } catch (Exception $e) {
            $batch_errors++;
            if ($batch_errors <= 3) {
                appendLog($conn_pdo, "ERROR route $route_id: " . $e->getMessage());
            }
            $batch_processed++;
            continue;
        }

        // Build rich geometry envelope
        $route_geom = null;
        if ($geojson_raw) {
            $envelope = [
                'geojson' => json_decode($geojson_raw, true),
                'waypoints' => $waypoints_raw ? json_decode($waypoints_raw, true) : [],
                'distance_nm' => $distance_nm !== null ? round((float)$distance_nm, 1) : null,
                'frozen_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
            $route_geom = json_encode($envelope, JSON_UNESCAPED_SLASHES);
        }

        // Merge origin ARTCCs before, dest ARTCCs after GIS results
        $origin_list = [];
        foreach (explode(',', $oar) as $a) {
            $a = trim($a);
            if ($a !== '' && strtoupper($a) !== 'UNKN' && strtoupper($a) !== 'VARIOUS') $origin_list[] = $a;
        }
        $dest_list = [];
        foreach (explode(',', $dar) as $a) {
            $a = trim($a);
            if ($a !== '' && strtoupper($a) !== 'UNKN' && strtoupper($a) !== 'VARIOUS') $dest_list[] = $a;
        }
        $artccs = array_merge($origin_list, $artccs, $dest_list);

        $trav_artccs = implode(',', array_unique(array_filter($artccs)));
        $trav_tracons = implode(',', array_unique(array_filter($tracons)));
        $trav_sec_low = implode(',', array_unique(array_filter($sectors_low)));
        $trav_sec_high = implode(',', array_unique(array_filter($sectors_high)));
        $trav_sec_superhigh = implode(',', array_unique(array_filter($sectors_superhigh)));

        $update_stmt->bind_param('ssssssi',
            $trav_artccs, $trav_tracons,
            $trav_sec_low, $trav_sec_high, $trav_sec_superhigh,
            $route_geom, $route_id);
        $update_stmt->execute();

        $batch_processed++;
    }

    $elapsed = round(microtime(true) - $start_time, 2);
    $total_processed = (int)getState($conn_pdo, 'geom_total_processed', 0) + $batch_processed;
    $total_errors = (int)getState($conn_pdo, 'geom_total_errors', 0) + $batch_errors;

    setState($conn_pdo, 'geom_last_route_id', (string)$batch_max_id);
    setState($conn_pdo, 'geom_total_processed', (string)$total_processed);
    setState($conn_pdo, 'geom_total_errors', (string)$total_errors);
    setState($conn_pdo, 'geom_last_batch_at', gmdate('Y-m-d H:i:s'));

    $rate = $batch_processed > 0 ? round($batch_processed / $elapsed, 1) : 0;
    appendLog($conn_pdo, "Batch: $batch_processed routes in {$elapsed}s ({$rate}/s), errors: $batch_errors, last_id: $batch_max_id");

    // Check remaining
    $remaining = $conn_sqli->query("SELECT COUNT(*) as c FROM playbook_routes WHERE route_geometry IS NULL")->fetch_assoc()['c'];

    echo json_encode([
        'status' => 'ok',
        'batch_processed' => $batch_processed,
        'batch_errors' => $batch_errors,
        'batch_elapsed_sec' => $elapsed,
        'rate_per_sec' => $rate,
        'last_route_id' => $batch_max_id,
        'total_processed' => $total_processed,
        'total_errors' => $total_errors,
        'remaining' => (int)$remaining,
    ]);
}
