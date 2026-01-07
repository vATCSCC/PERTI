<?php
/**
 * Simple query test - no complex subqueries or geometry conversion
 * Tests if the complex SELECT columns cause timeout/errors
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../load/connect.php';

if (!isset($conn_adl)) {
    echo json_encode(['error' => 'No connection']);
    exit;
}

// Simple query - just core fields
$sql = "SELECT TOP 10000
            c.flight_uid, c.callsign, c.cid, c.phase, c.is_active,
            p.lat, p.lon, p.altitude_ft, p.groundspeed_kts,
            fp.fp_dept_icao, fp.fp_dest_icao
        FROM dbo.adl_flight_core c
        LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        WHERE c.is_active = 1
        ORDER BY c.callsign";

$start = microtime(true);
$stmt = sqlsrv_query($conn_adl, $sql);
$queryTime = round((microtime(true) - $start) * 1000);

if ($stmt === false) {
    echo json_encode(['error' => 'Query failed', 'sql_error' => sqlsrv_errors()]);
    exit;
}

$rows = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $rows[] = $row;
}
$fetchTime = round((microtime(true) - $start) * 1000);

echo json_encode([
    'simple_query_count' => count($rows),
    'query_time_ms' => $queryTime,
    'total_time_ms' => $fetchTime,
    'note' => 'This is without waypoints subquery or geometry conversion'
]);
