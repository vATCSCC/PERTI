<?php
/**
 * Boundary Detection Diagnostic Endpoint
 * Checks boundary data and recent crossings by type
 */

header('Content-Type: application/json; charset=utf-8');

require_once(__DIR__ . '/../../load/config.php');

$connectionInfo = [
    "Database" => ADL_SQL_DATABASE,
    "UID"      => ADL_SQL_USERNAME,
    "PWD"      => ADL_SQL_PASSWORD
];

$conn = sqlsrv_connect(ADL_SQL_HOST, $connectionInfo);
if ($conn === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not connect to database']);
    exit;
}

$result = [
    'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'checks' => []
];

// 1. Check boundary counts by type
$sql = "SELECT boundary_type, COUNT(*) AS cnt, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_cnt
        FROM dbo.adl_boundary
        GROUP BY boundary_type
        ORDER BY boundary_type";
$stmt = sqlsrv_query($conn, $sql);
$boundaryTypes = [];
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $boundaryTypes[$row['boundary_type']] = [
            'total' => $row['cnt'],
            'active' => $row['active_cnt']
        ];
    }
    sqlsrv_free_stmt($stmt);
}
$result['boundary_counts'] = $boundaryTypes;

// 2. Check grid index counts by type
$sql = "SELECT boundary_type, COUNT(DISTINCT boundary_id) AS boundaries_indexed, COUNT(*) AS grid_cells
        FROM dbo.adl_boundary_grid
        GROUP BY boundary_type
        ORDER BY boundary_type";
$stmt = sqlsrv_query($conn, $sql);
$gridCounts = [];
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $gridCounts[$row['boundary_type']] = [
            'boundaries' => $row['boundaries_indexed'],
            'cells' => $row['grid_cells']
        ];
    }
    sqlsrv_free_stmt($stmt);
}
$result['grid_index_counts'] = $gridCounts;

// 3. Check boundary log entries by type (last hour)
$sql = "SELECT boundary_type, COUNT(*) AS crossings_1h
        FROM dbo.adl_flight_boundary_log
        WHERE entry_time > DATEADD(HOUR, -1, SYSUTCDATETIME())
        GROUP BY boundary_type
        ORDER BY boundary_type";
$stmt = sqlsrv_query($conn, $sql);
$crossings1h = [];
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $crossings1h[$row['boundary_type']] = $row['crossings_1h'];
    }
    sqlsrv_free_stmt($stmt);
}
$result['crossings_last_1h'] = $crossings1h;

// 4. Check boundary log entries by type (last 24 hours)
$sql = "SELECT boundary_type, COUNT(*) AS crossings_24h
        FROM dbo.adl_flight_boundary_log
        WHERE entry_time > DATEADD(HOUR, -24, SYSUTCDATETIME())
        GROUP BY boundary_type
        ORDER BY boundary_type";
$stmt = sqlsrv_query($conn, $sql);
$crossings24h = [];
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $crossings24h[$row['boundary_type']] = $row['crossings_24h'];
    }
    sqlsrv_free_stmt($stmt);
}
$result['crossings_last_24h'] = $crossings24h;

// 5. Check most recent log entries by type
$sql = "WITH LatestByType AS (
            SELECT boundary_type, entry_time, boundary_code,
                   ROW_NUMBER() OVER (PARTITION BY boundary_type ORDER BY entry_time DESC) AS rn
            FROM dbo.adl_flight_boundary_log
        )
        SELECT boundary_type, entry_time, boundary_code
        FROM LatestByType
        WHERE rn = 1";
$stmt = sqlsrv_query($conn, $sql);
$latestByType = [];
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $ts = $row['entry_time'];
        $latestByType[$row['boundary_type']] = [
            'boundary' => $row['boundary_code'],
            'time' => ($ts instanceof DateTime) ? $ts->format('Y-m-d H:i:s') . ' UTC' : $ts
        ];
    }
    sqlsrv_free_stmt($stmt);
}
$result['latest_crossing_by_type'] = $latestByType;

// 6. Check top 5 boundaries per type (last hour)
$sql = "WITH RankedBoundaries AS (
            SELECT
                b.boundary_name,
                b.boundary_type,
                COUNT(*) AS crossing_cnt,
                ROW_NUMBER() OVER (PARTITION BY b.boundary_type ORDER BY COUNT(*) DESC) AS rn
            FROM dbo.adl_flight_boundary_log bl
            INNER JOIN dbo.adl_boundary b ON bl.boundary_id = b.boundary_id
            WHERE bl.entry_time > DATEADD(HOUR, -1, SYSUTCDATETIME())
            GROUP BY b.boundary_name, b.boundary_type
        )
        SELECT boundary_name, boundary_type, crossing_cnt
        FROM RankedBoundaries
        WHERE rn <= 5
        ORDER BY boundary_type, crossing_cnt DESC";
$stmt = sqlsrv_query($conn, $sql);
$topByType = [];
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $type = $row['boundary_type'];
        if (!isset($topByType[$type])) {
            $topByType[$type] = [];
        }
        $topByType[$type][] = [
            'name' => $row['boundary_name'],
            'count' => $row['crossing_cnt']
        ];
    }
    sqlsrv_free_stmt($stmt);
}
$result['top_5_by_type_1h'] = $topByType;

// 7. Check current flight boundary assignments
$sql = "SELECT
            SUM(CASE WHEN current_artcc IS NOT NULL THEN 1 ELSE 0 END) AS with_artcc,
            SUM(CASE WHEN current_tracon IS NOT NULL THEN 1 ELSE 0 END) AS with_tracon,
            SUM(CASE WHEN current_sector_low IS NOT NULL THEN 1 ELSE 0 END) AS with_sector_low,
            SUM(CASE WHEN current_sector_high IS NOT NULL THEN 1 ELSE 0 END) AS with_sector_high,
            SUM(CASE WHEN current_sector_superhigh IS NOT NULL THEN 1 ELSE 0 END) AS with_sector_superhigh,
            COUNT(*) AS total_active
        FROM dbo.adl_flight_core
        WHERE is_active = 1";
$stmt = sqlsrv_query($conn, $sql);
if ($stmt) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $result['current_flight_assignments'] = [
        'total_active' => $row['total_active'],
        'with_artcc' => $row['with_artcc'],
        'with_tracon' => $row['with_tracon'],
        'with_sector_low' => $row['with_sector_low'],
        'with_sector_high' => $row['with_sector_high'],
        'with_sector_superhigh' => $row['with_sector_superhigh']
    ];
    sqlsrv_free_stmt($stmt);
}

// 8. Sample flights with TRACON assignments
$sql = "SELECT TOP 10 callsign, current_artcc, current_tracon, current_sector_low, current_sector_high,
               p.altitude_ft, p.lat, p.lon
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
        WHERE c.is_active = 1 AND c.current_tracon IS NOT NULL
        ORDER BY c.last_seen_utc DESC";
$stmt = sqlsrv_query($conn, $sql);
$sampleFlights = [];
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $sampleFlights[] = [
            'callsign' => $row['callsign'],
            'artcc' => $row['current_artcc'],
            'tracon' => $row['current_tracon'],
            'sector_low' => $row['current_sector_low'],
            'sector_high' => $row['current_sector_high'],
            'altitude' => $row['altitude_ft'],
            'position' => round($row['lat'], 2) . ', ' . round($row['lon'], 2)
        ];
    }
    sqlsrv_free_stmt($stmt);
}
$result['sample_flights_with_tracon'] = $sampleFlights;

// 9. Check if boundary detection procedure exists
$sql = "SELECT COUNT(*) AS cnt FROM sys.procedures WHERE name = 'sp_ProcessBoundaryDetectionBatch'";
$stmt = sqlsrv_query($conn, $sql);
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$result['checks']['boundary_procedure_exists'] = $row['cnt'] > 0;
sqlsrv_free_stmt($stmt);

// 10. Check last refresh time
$sql = "SELECT TOP 1 snapshot_utc FROM dbo.adl_flight_core ORDER BY snapshot_utc DESC";
$stmt = sqlsrv_query($conn, $sql);
if ($stmt) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($row && $row['snapshot_utc'] instanceof DateTime) {
        $result['last_refresh'] = $row['snapshot_utc']->format('Y-m-d H:i:s') . ' UTC';
    }
    sqlsrv_free_stmt($stmt);
}

sqlsrv_close($conn);

echo json_encode($result, JSON_PRETTY_PRINT);
