<?php
/**
 * Test STAR parsing with real flight data
 * Run: php test_star_parsing.php
 */

require_once __DIR__ . '/load/connect.php';

if (!$conn_adl) {
    die("Could not connect to ADL database\n");
}

echo "=== Finding flights with potential STARs ===\n\n";

// Find flights with routes containing numbers (likely SID/STAR codes)
$sql = "
SELECT TOP 10
    c.flight_uid,
    c.callsign,
    fp.fp_dept_icao,
    fp.fp_dest_icao,
    fp.fp_route,
    fp.parse_status,
    (SELECT COUNT(*) FROM dbo.adl_flight_waypoints w WHERE w.flight_uid = c.flight_uid) as waypoint_count
FROM dbo.adl_flight_core c
JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
WHERE c.is_active = 1
  AND fp.fp_route IS NOT NULL
  AND LEN(fp.fp_route) > 15
ORDER BY c.flight_uid DESC
";

$stmt = sqlsrv_query($conn_adl, $sql);
if ($stmt === false) {
    print_r(sqlsrv_errors());
    exit(1);
}

$flights = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $flights[] = $row;
    printf("%-10s %-8s %-4s->%-4s | wpts: %2d | %s\n",
        $row['flight_uid'],
        $row['callsign'],
        $row['fp_dept_icao'],
        $row['fp_dest_icao'],
        $row['waypoint_count'],
        substr($row['fp_route'], 0, 60)
    );
}
sqlsrv_free_stmt($stmt);

if (empty($flights)) {
    echo "No active flights found.\n";
    exit(0);
}

// Pick the first flight for detailed testing
$test_flight = $flights[0];
$flight_uid = $test_flight['flight_uid'];

echo "\n=== Testing sp_ParseRoute on flight_uid: {$flight_uid} ===\n";
echo "Callsign: {$test_flight['callsign']}\n";
echo "Route: {$test_flight['fp_dept_icao']} -> {$test_flight['fp_dest_icao']}\n";
echo "Full route: {$test_flight['fp_route']}\n\n";

// Execute sp_ParseRoute with debug=1
$parse_sql = "EXEC dbo.sp_ParseRoute @flight_uid = ?, @debug = 1";
$stmt = sqlsrv_query($conn_adl, $parse_sql, [$flight_uid]);
if ($stmt === false) {
    echo "Parse error:\n";
    print_r(sqlsrv_errors());
    exit(1);
}

// Capture debug output (PRINT statements)
$messages = [];
while (sqlsrv_next_result($stmt)) {
    // Process result sets
}
sqlsrv_free_stmt($stmt);

echo "Parse completed. Fetching waypoints...\n\n";

// Now fetch the waypoints
$waypoints_sql = "
SELECT
    sequence_num,
    fix_name,
    ROUND(lat, 4) as lat,
    ROUND(lon, 4) as lon,
    source,
    on_airway,
    on_dp,
    on_star,
    segment_dist_nm,
    cum_dist_nm
FROM dbo.adl_flight_waypoints
WHERE flight_uid = ?
ORDER BY sequence_num
";

$stmt = sqlsrv_query($conn_adl, $waypoints_sql, [$flight_uid]);
if ($stmt === false) {
    print_r(sqlsrv_errors());
    exit(1);
}

echo "=== Waypoints ===\n";
echo sprintf("%-4s %-8s %10s %11s %-10s %-8s %-8s %-8s %8s\n",
    "Seq", "Fix", "Lat", "Lon", "Source", "Airway", "DP", "STAR", "Cum NM");
echo str_repeat("-", 90) . "\n";

$star_waypoints = 0;
$dp_waypoints = 0;
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    printf("%-4d %-8s %10.4f %11.4f %-10s %-8s %-8s %-8s %8.1f\n",
        $row['sequence_num'],
        $row['fix_name'],
        $row['lat'] ?? 0,
        $row['lon'] ?? 0,
        $row['source'] ?? '',
        $row['on_airway'] ?? '',
        $row['on_dp'] ?? '',
        $row['on_star'] ?? '',
        $row['cum_dist_nm'] ?? 0
    );
    if (!empty($row['on_star'])) $star_waypoints++;
    if (!empty($row['on_dp'])) $dp_waypoints++;
}
sqlsrv_free_stmt($stmt);

echo "\n=== Summary ===\n";
echo "DP waypoints: {$dp_waypoints}\n";
echo "STAR waypoints: {$star_waypoints}\n";

if ($star_waypoints > 0) {
    echo "\n✓ STAR expansion is working!\n";
} else {
    echo "\n⚠ No STAR waypoints found. Check if route contains a valid STAR.\n";
}
