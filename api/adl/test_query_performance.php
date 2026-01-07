<?php
/**
 * Query Performance Diagnostic
 * Tests different query variations to identify the performance bottleneck
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../load/connect.php';

if (!isset($conn_adl)) {
    echo json_encode(['error' => 'No connection']);
    exit;
}

$results = [
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    'tests' => []
];

// Helper function to run a query and measure time
function runTest($conn, $name, $sql) {
    $start = microtime(true);
    $stmt = sqlsrv_query($conn, $sql);
    $queryTime = round((microtime(true) - $start) * 1000);

    if ($stmt === false) {
        $errors = sqlsrv_errors();
        return [
            'name' => $name,
            'success' => false,
            'error' => $errors ? $errors[0]['message'] : 'Unknown error',
            'query_time_ms' => $queryTime
        ];
    }

    $count = 0;
    while (sqlsrv_fetch($stmt)) {
        $count++;
    }
    $totalTime = round((microtime(true) - $start) * 1000);
    sqlsrv_free_stmt($stmt);

    return [
        'name' => $name,
        'success' => true,
        'row_count' => $count,
        'query_time_ms' => $queryTime,
        'fetch_time_ms' => $totalTime - $queryTime,
        'total_time_ms' => $totalTime
    ];
}

// Test 1: Simple core-only query
$results['tests'][] = runTest($conn_adl, '1_core_only',
    "SELECT TOP 10000 flight_uid, callsign, cid, phase, is_active
     FROM dbo.adl_flight_core WHERE is_active = 1");

// Test 2: Core + position LEFT JOIN
$results['tests'][] = runTest($conn_adl, '2_with_position',
    "SELECT TOP 10000 c.flight_uid, c.callsign, p.lat, p.lon, p.altitude_ft
     FROM dbo.adl_flight_core c
     LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
     WHERE c.is_active = 1");

// Test 3: Full JOINs without expensive columns
$results['tests'][] = runTest($conn_adl, '3_full_joins_simple',
    "SELECT TOP 10000 c.flight_uid, c.callsign, p.lat, p.lon,
            fp.fp_dept_icao, fp.fp_dest_icao, ac.weight_class, t.eta_epoch
     FROM dbo.adl_flight_core c
     LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
     LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
     LEFT JOIN dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid
     LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
     WHERE c.is_active = 1");

// Test 4: With geometry STAsText() only
$results['tests'][] = runTest($conn_adl, '4_with_geometry',
    "SELECT TOP 10000 c.flight_uid, c.callsign, fp.route_geometry.STAsText() AS geom
     FROM dbo.adl_flight_core c
     LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
     WHERE c.is_active = 1");

// Test 5: With waypoints subquery only
$results['tests'][] = runTest($conn_adl, '5_with_waypoints',
    "SELECT TOP 10000 c.flight_uid, c.callsign,
            (SELECT w.fix_name, w.lat, w.lon, w.sequence_num
             FROM dbo.adl_flight_waypoints w
             WHERE w.flight_uid = c.flight_uid
             ORDER BY w.sequence_num
             FOR JSON PATH) AS waypoints_json
     FROM dbo.adl_flight_core c
     WHERE c.is_active = 1");

// Test 6: With BOTH geometry and waypoints
$results['tests'][] = runTest($conn_adl, '6_geometry_and_waypoints',
    "SELECT TOP 10000 c.flight_uid, c.callsign,
            fp.route_geometry.STAsText() AS geom,
            (SELECT w.fix_name, w.lat, w.lon, w.sequence_num
             FROM dbo.adl_flight_waypoints w
             WHERE w.flight_uid = c.flight_uid
             ORDER BY w.sequence_num
             FOR JSON PATH) AS waypoints_json
     FROM dbo.adl_flight_core c
     LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
     WHERE c.is_active = 1");

// Test 7: The actual complex query from AdlQueryHelper (abridged)
$results['tests'][] = runTest($conn_adl, '7_actual_query',
    "SELECT TOP 10000
        c.flight_uid, c.flight_key, c.callsign, c.cid, c.phase, c.flight_status, c.is_active,
        c.first_seen_utc, c.last_seen_utc, c.logon_time_utc, c.snapshot_utc,
        p.lat, p.lon, p.altitude_ft, p.groundspeed_kts, p.heading_deg,
        fp.fp_rule, fp.fp_dept_icao, fp.fp_dest_icao, fp.fp_route, fp.gcd_nm,
        fp.route_geometry.STAsText() AS route_geometry_wkt,
        (SELECT w.fix_name, w.lat, w.lon, w.sequence_num
         FROM dbo.adl_flight_waypoints w
         WHERE w.flight_uid = c.flight_uid
         ORDER BY w.sequence_num
         FOR JSON PATH) AS waypoints_json,
        ac.aircraft_icao, ac.weight_class,
        t.eta_epoch, t.etd_epoch
     FROM dbo.adl_flight_core c
     LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
     LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
     LEFT JOIN dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid
     LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
     WHERE c.is_active = 1
     ORDER BY t.eta_epoch ASC, c.callsign ASC");

// Summary
$results['summary'] = [
    'expected_rows' => $results['tests'][0]['row_count'] ?? 0,
    'bottleneck' => null
];

// Identify bottleneck
foreach ($results['tests'] as $test) {
    if ($test['success'] && isset($test['row_count'])) {
        if ($test['row_count'] < ($results['summary']['expected_rows'] * 0.9)) {
            $results['summary']['bottleneck'] = $test['name'] . ' - returned ' . $test['row_count'] . ' rows (expected ~' . $results['summary']['expected_rows'] . ')';
            break;
        }
    }
}

echo json_encode($results, JSON_PRETTY_PRINT);
