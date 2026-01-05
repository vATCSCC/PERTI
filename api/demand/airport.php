<?php

// api/demand/airport.php
// Returns demand data for a single airport
// Aggregates arrival and departure counts by time bins with status breakdown

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once("../../load/config.php");

// Check ADL database configuration
if (!defined("ADL_SQL_HOST") || !defined("ADL_SQL_DATABASE") ||
    !defined("ADL_SQL_USERNAME") || !defined("ADL_SQL_PASSWORD")) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "ADL_SQL_* constants are not defined."]);
    exit;
}

// Helper function for SQL Server error messages
function adl_sql_error_message() {
    $errs = sqlsrv_errors(SQLSRV_ERR_ERRORS);
    if (!$errs) return "";
    $msgs = [];
    foreach ($errs as $e) {
        $msgs[] = (isset($e['SQLSTATE']) ? $e['SQLSTATE'] : '') . " " .
                  (isset($e['code']) ? $e['code'] : '') . " " .
                  (isset($e['message']) ? trim($e['message']) : '');
    }
    return implode(" | ", $msgs);
}

// Check sqlsrv extension
if (!function_exists('sqlsrv_connect')) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "sqlsrv extension not available."]);
    exit;
}

// Connect to ADL database
$connectionInfo = [
    "Database" => ADL_SQL_DATABASE,
    "UID"      => ADL_SQL_USERNAME,
    "PWD"      => ADL_SQL_PASSWORD
];

$conn = sqlsrv_connect(ADL_SQL_HOST, $connectionInfo);
if ($conn === false) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Unable to connect to ADL database.",
        "sql_error" => adl_sql_error_message()
    ]);
    exit;
}

// Get parameters
$airport = isset($_GET['airport']) ? strtoupper(trim($_GET['airport'])) : '';
$granularity = isset($_GET['granularity']) ? strtolower(trim($_GET['granularity'])) : 'hourly';
$direction = isset($_GET['direction']) ? strtolower(trim($_GET['direction'])) : 'both';
$start = isset($_GET['start']) ? trim($_GET['start']) : null;
$end = isset($_GET['end']) ? trim($_GET['end']) : null;

// Validate airport
if (empty($airport) || strlen($airport) !== 4) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Invalid or missing airport parameter. Must be 4-letter ICAO code."]);
    sqlsrv_close($conn);
    exit;
}

// Validate granularity
if (!in_array($granularity, ['15min', 'hourly'])) {
    $granularity = 'hourly';
}

// Validate direction
if (!in_array($direction, ['arr', 'dep', 'both'])) {
    $direction = 'both';
}

// Parse time range (defaults: now - 1h to now + 6h)
$now = new DateTime('now', new DateTimeZone('UTC'));

if ($start !== null) {
    try {
        $startDt = new DateTime($start, new DateTimeZone('UTC'));
    } catch (Exception $e) {
        $startDt = (clone $now)->modify('-1 hour');
    }
} else {
    $startDt = (clone $now)->modify('-1 hour');
}

if ($end !== null) {
    try {
        $endDt = new DateTime($end, new DateTimeZone('UTC'));
    } catch (Exception $e) {
        $endDt = (clone $now)->modify('+6 hours');
    }
} else {
    $endDt = (clone $now)->modify('+6 hours');
}

// Format for SQL
$startSQL = $startDt->format('Y-m-d H:i:s');
$endSQL = $endDt->format('Y-m-d H:i:s');

// Determine time binning SQL based on granularity
// For hourly: truncate to hour
// For 15min: truncate to 15-minute boundary
if ($granularity === '15min') {
    // 15-minute bins: DATEADD(MINUTE, (DATEDIFF(MINUTE, 0, time) / 15) * 15, 0)
    $arrTimeBinSQL = "DATEADD(MINUTE, (DATEDIFF(MINUTE, '2000-01-01', eta_runway_utc) / 15) * 15, '2000-01-01')";
    $depTimeBinSQL = "DATEADD(MINUTE, (DATEDIFF(MINUTE, '2000-01-01', etd_runway_utc) / 15) * 15, '2000-01-01')";
} else {
    // Hourly bins
    $arrTimeBinSQL = "DATEADD(HOUR, DATEDIFF(HOUR, 0, eta_runway_utc), 0)";
    $depTimeBinSQL = "DATEADD(HOUR, DATEDIFF(HOUR, 0, etd_runway_utc), 0)";
}

// FSM Status mapping based on flight_status and is_active
// flight_status: NULL/empty = not departed, 'A' = active/airborne, 'D' = departed, 'L' = landed
$statusCaseSQL = "
    CASE
        WHEN flight_status = 'L' THEN 'arrived'
        WHEN flight_status = 'A' THEN 'active'
        WHEN flight_status = 'D' THEN 'departed'
        WHEN etd_runway_utc IS NOT NULL AND etd_runway_utc < GETUTCDATE()
             AND (flight_status IS NULL OR flight_status = '') THEN 'dep_past_etd'
        WHEN is_active = 1 AND (flight_status IS NULL OR flight_status = '') THEN 'scheduled'
        ELSE 'proposed'
    END
";

$response = [
    "success" => true,
    "airport" => $airport,
    "timestamp" => gmdate("Y-m-d\\TH:i:s\\Z"),
    "time_range" => [
        "start" => $startDt->format("Y-m-d\\TH:i:s\\Z"),
        "end" => $endDt->format("Y-m-d\\TH:i:s\\Z")
    ],
    "granularity" => $granularity,
    "direction" => $direction,
    "data" => [
        "arrivals" => [],
        "departures" => []
    ]
];

// Query arrivals
if ($direction === 'arr' || $direction === 'both') {
    $arrSQL = "
        SELECT
            {$arrTimeBinSQL} AS time_bin,
            COUNT(*) AS total,
            SUM(CASE WHEN flight_status = 'L' THEN 1 ELSE 0 END) AS arrived,
            SUM(CASE WHEN flight_status = 'A' THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN flight_status = 'D' THEN 1 ELSE 0 END) AS departed,
            SUM(CASE WHEN (flight_status IS NULL OR flight_status = '' OR flight_status NOT IN ('A','D','L'))
                          AND is_active = 1 THEN 1 ELSE 0 END) AS scheduled,
            SUM(CASE WHEN (flight_status IS NULL OR flight_status = '' OR flight_status NOT IN ('A','D','L'))
                          AND (is_active = 0 OR is_active IS NULL) THEN 1 ELSE 0 END) AS proposed
        FROM dbo.adl_flights
        WHERE fp_dest_icao = ?
          AND eta_runway_utc IS NOT NULL
          AND eta_runway_utc >= ?
          AND eta_runway_utc < ?
        GROUP BY {$arrTimeBinSQL}
        ORDER BY time_bin
    ";

    $params = [$airport, $startSQL, $endSQL];
    $arrStmt = sqlsrv_query($conn, $arrSQL, $params);

    if ($arrStmt === false) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "error" => "Database error when querying arrivals.",
            "sql_error" => adl_sql_error_message()
        ]);
        sqlsrv_close($conn);
        exit;
    }

    while ($row = sqlsrv_fetch_array($arrStmt, SQLSRV_FETCH_ASSOC)) {
        $timeBin = $row['time_bin'];
        if ($timeBin instanceof DateTime) {
            $timeBin = $timeBin->format("Y-m-d\\TH:i:s\\Z");
        }

        $response['data']['arrivals'][] = [
            "time_bin" => $timeBin,
            "total" => (int)$row['total'],
            "breakdown" => [
                "arrived" => (int)$row['arrived'],
                "active" => (int)$row['active'],
                "departed" => (int)$row['departed'],
                "scheduled" => (int)$row['scheduled'],
                "proposed" => (int)$row['proposed']
            ]
        ];
    }
    sqlsrv_free_stmt($arrStmt);
}

// Query departures
if ($direction === 'dep' || $direction === 'both') {
    $depSQL = "
        SELECT
            {$depTimeBinSQL} AS time_bin,
            COUNT(*) AS total,
            SUM(CASE WHEN flight_status = 'L' THEN 1 ELSE 0 END) AS arrived,
            SUM(CASE WHEN flight_status = 'A' THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN flight_status = 'D' THEN 1 ELSE 0 END) AS departed,
            SUM(CASE WHEN (flight_status IS NULL OR flight_status = '' OR flight_status NOT IN ('A','D','L'))
                          AND is_active = 1 THEN 1 ELSE 0 END) AS scheduled,
            SUM(CASE WHEN (flight_status IS NULL OR flight_status = '' OR flight_status NOT IN ('A','D','L'))
                          AND (is_active = 0 OR is_active IS NULL) THEN 1 ELSE 0 END) AS proposed
        FROM dbo.adl_flights
        WHERE fp_dept_icao = ?
          AND etd_runway_utc IS NOT NULL
          AND etd_runway_utc >= ?
          AND etd_runway_utc < ?
        GROUP BY {$depTimeBinSQL}
        ORDER BY time_bin
    ";

    $params = [$airport, $startSQL, $endSQL];
    $depStmt = sqlsrv_query($conn, $depSQL, $params);

    if ($depStmt === false) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "error" => "Database error when querying departures.",
            "sql_error" => adl_sql_error_message()
        ]);
        sqlsrv_close($conn);
        exit;
    }

    while ($row = sqlsrv_fetch_array($depStmt, SQLSRV_FETCH_ASSOC)) {
        $timeBin = $row['time_bin'];
        if ($timeBin instanceof DateTime) {
            $timeBin = $timeBin->format("Y-m-d\\TH:i:s\\Z");
        }

        $response['data']['departures'][] = [
            "time_bin" => $timeBin,
            "total" => (int)$row['total'],
            "breakdown" => [
                "arrived" => (int)$row['arrived'],
                "active" => (int)$row['active'],
                "departed" => (int)$row['departed'],
                "scheduled" => (int)$row['scheduled'],
                "proposed" => (int)$row['proposed']
            ]
        ];
    }
    sqlsrv_free_stmt($depStmt);
}

// Get last ADL update time
$lastUpdateSQL = "SELECT MAX(snapshot_utc) AS last_update FROM dbo.adl_flights";
$lastUpdateStmt = sqlsrv_query($conn, $lastUpdateSQL);
if ($lastUpdateStmt !== false) {
    $row = sqlsrv_fetch_array($lastUpdateStmt, SQLSRV_FETCH_ASSOC);
    if ($row && $row['last_update']) {
        $lastUpdate = $row['last_update'];
        if ($lastUpdate instanceof DateTime) {
            $response['last_adl_update'] = $lastUpdate->format("Y-m-d\\TH:i:s\\Z");
        } else {
            $response['last_adl_update'] = $lastUpdate;
        }
    }
    sqlsrv_free_stmt($lastUpdateStmt);
}

sqlsrv_close($conn);

echo json_encode($response);
