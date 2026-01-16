<?php

// api/demand/airport.php
// Returns demand data for a single airport
// Aggregates arrival and departure counts by time bins with status breakdown

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once(__DIR__ . "/../../load/config.php");
require_once(__DIR__ . "/../../load/input.php");

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
$airport = isset($_GET['airport']) ? get_upper('airport') : '';
$granularity = isset($_GET['granularity']) ? get_lower('granularity') : 'hourly';
$direction = isset($_GET['direction']) ? get_lower('direction') : 'both';
$start = isset($_GET['start']) ? trim($_GET['start']) : null;
$end = isset($_GET['end']) ? trim($_GET['end']) : null;
$timeBasis = isset($_GET['time_basis']) ? get_lower('time_basis') : 'eta'; // 'eta' or 'ctd'
$programId = isset($_GET['program_id']) ? (int)$_GET['program_id'] : null;

// Validate airport
if (empty($airport) || strlen($airport) !== 4) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Invalid or missing airport parameter. Must be 4-letter ICAO code."]);
    sqlsrv_close($conn);
    exit;
}

// Validate granularity (15min, 30min, hourly)
if (!in_array($granularity, ['15min', '30min', 'hourly'])) {
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

// Use ADL Query Helper for normalized table support
require_once(__DIR__ . '/../adl/AdlQueryHelper.php');
$helper = new AdlQueryHelper();

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
    "time_basis" => $timeBasis,
    "data" => [
        "arrivals" => [],
        "departures" => []
    ]
];

// Use TMI-aware query when time_basis=ctd
if ($timeBasis === 'ctd') {
    $tmiQuery = $helper->buildTmiDemandAggregationQuery([
        'airport' => $airport,
        'granularity' => $granularity,
        'startSQL' => $startSQL,
        'endSQL' => $endSQL,
        'program_id' => $programId
    ]);

    $tmiStmt = sqlsrv_query($conn, $tmiQuery['sql'], $tmiQuery['params']);

    if ($tmiStmt === false) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "error" => "Database error when querying TMI demand.",
            "sql_error" => adl_sql_error_message()
        ]);
        sqlsrv_close($conn);
        exit;
    }

    while ($row = sqlsrv_fetch_array($tmiStmt, SQLSRV_FETCH_ASSOC)) {
        $timeBin = $row['time_bin'];
        if ($timeBin instanceof DateTime) {
            $timeBin = $timeBin->format("Y-m-d\\TH:i:s\\Z");
        }

        $response['data']['arrivals'][] = [
            "time_bin" => $timeBin,
            "total" => (int)$row['total'],
            "breakdown" => [
                // TMI statuses (priority coloring)
                "proposed_gs" => (int)$row['proposed_gs'],
                "simulated_gs" => (int)$row['simulated_gs'],
                "actual_gs" => (int)$row['actual_gs'],
                "proposed_gdp" => (int)$row['proposed_gdp'],
                "simulated_gdp" => (int)$row['simulated_gdp'],
                "actual_gdp" => (int)$row['actual_gdp'],
                "exempt" => (int)$row['exempt'],
                "uncontrolled" => (int)$row['uncontrolled'],
                // Phase breakdown (for uncontrolled flights)
                "arrived" => (int)$row['arrived'],
                "disconnected" => (int)$row['disconnected'],
                "descending" => (int)$row['descending'],
                "enroute" => (int)$row['enroute'],
                "departed" => (int)$row['departed'],
                "taxiing" => (int)$row['taxiing'],
                "prefile" => (int)$row['prefile']
            ]
        ];
    }
    sqlsrv_free_stmt($tmiStmt);

    // Get last ADL update time
    $lastUpdateQuery = $helper->buildLastUpdateQuery();
    $lastUpdateStmt = sqlsrv_query($conn, $lastUpdateQuery['sql']);
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
    exit;
}

// Query arrivals
if ($direction === 'arr' || $direction === 'both') {
    $arrQuery = $helper->buildDemandAggregationQuery([
        'airport' => $airport,
        'direction' => 'arr',
        'granularity' => $granularity,
        'startSQL' => $startSQL,
        'endSQL' => $endSQL
    ]);

    $arrStmt = sqlsrv_query($conn, $arrQuery['sql'], $arrQuery['params']);

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
                "disconnected" => (int)$row['disconnected'],
                "descending" => (int)$row['descending'],
                "enroute" => (int)$row['enroute'],
                "departed" => (int)$row['departed'],
                "taxiing" => (int)$row['taxiing'],
                "prefile" => (int)$row['prefile'],
                "unknown" => (int)$row['unknown']
            ]
        ];
    }
    sqlsrv_free_stmt($arrStmt);
}

// Query departures
if ($direction === 'dep' || $direction === 'both') {
    $depQuery = $helper->buildDemandAggregationQuery([
        'airport' => $airport,
        'direction' => 'dep',
        'granularity' => $granularity,
        'startSQL' => $startSQL,
        'endSQL' => $endSQL
    ]);

    $depStmt = sqlsrv_query($conn, $depQuery['sql'], $depQuery['params']);

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
                "disconnected" => (int)$row['disconnected'],
                "descending" => (int)$row['descending'],
                "enroute" => (int)$row['enroute'],
                "departed" => (int)$row['departed'],
                "taxiing" => (int)$row['taxiing'],
                "prefile" => (int)$row['prefile'],
                "unknown" => (int)$row['unknown']
            ]
        ];
    }
    sqlsrv_free_stmt($depStmt);
}

// Get last ADL update time
$lastUpdateQuery = $helper->buildLastUpdateQuery();
$lastUpdateStmt = sqlsrv_query($conn, $lastUpdateQuery['sql']);
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
