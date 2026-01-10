<?php

// api/demand/atis.php
// Returns the latest ATIS information for an airport
// Includes ATIS text, runway configuration, and approach types

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

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

// Get airport parameter
$airport = isset($_GET['airport']) ? strtoupper(trim($_GET['airport'])) : '';

if (empty($airport)) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Airport parameter is required."]);
    exit;
}

// Normalize airport code (add K prefix for US 3-letter codes)
if (strlen($airport) === 3 && !preg_match('/^[PK]/', $airport)) {
    $airport = 'K' . $airport;
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

// Get latest ATIS record for this airport
$sql = "SELECT TOP 1
    a.atis_id,
    a.airport_icao,
    a.callsign,
    a.atis_type,
    a.atis_code,
    a.frequency,
    a.atis_text,
    a.fetched_utc,
    a.controller_cid,
    DATEDIFF(MINUTE, a.fetched_utc, GETUTCDATE()) AS age_mins
FROM dbo.vatsim_atis a
WHERE a.airport_icao = ?
  AND a.fetched_utc > DATEADD(HOUR, -2, GETUTCDATE())
ORDER BY a.fetched_utc DESC";

$stmt = sqlsrv_query($conn, $sql, [$airport]);

if ($stmt === false) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Failed to query ATIS data.",
        "sql_error" => adl_sql_error_message()
    ]);
    sqlsrv_close($conn);
    exit;
}

$atisRow = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($stmt);

// If no recent ATIS found, return empty response
if (!$atisRow) {
    sqlsrv_close($conn);
    echo json_encode([
        "success" => true,
        "airport_icao" => $airport,
        "has_atis" => false,
        "atis" => null,
        "runways" => null
    ]);
    exit;
}

// Get current runway configuration from the view
$sql = "SELECT
    airport_icao,
    arr_runways,
    dep_runways,
    approach_info,
    config_since,
    atis_code
FROM dbo.vw_current_airport_config
WHERE airport_icao = ?";

$stmt = sqlsrv_query($conn, $sql, [$airport]);
$configRow = null;
if ($stmt !== false) {
    $configRow = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
}

// Get detailed runway information
$runways = [];
$sql = "SELECT
    runway_id,
    runway_use,
    approach_type,
    source_type,
    effective_utc,
    active_mins
FROM dbo.vw_current_runways_in_use
WHERE airport_icao = ?
ORDER BY runway_use, runway_id";

$stmt = sqlsrv_query($conn, $sql, [$airport]);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $effectiveUtc = $row['effective_utc'];
        if ($effectiveUtc instanceof DateTime) {
            $effectiveUtc = $effectiveUtc->format('Y-m-d\TH:i:s\Z');
        }
        $runways[] = [
            "runway_id" => $row['runway_id'],
            "runway_use" => $row['runway_use'],
            "approach_type" => $row['approach_type'],
            "source_type" => $row['source_type'],
            "effective_utc" => $effectiveUtc,
            "active_mins" => (int)$row['active_mins']
        ];
    }
    sqlsrv_free_stmt($stmt);
}

sqlsrv_close($conn);

// Format timestamps
$fetchedUtc = $atisRow['fetched_utc'];
if ($fetchedUtc instanceof DateTime) {
    $fetchedUtc = $fetchedUtc->format('Y-m-d\TH:i:s\Z');
}

$configSince = null;
if ($configRow && $configRow['config_since']) {
    $configSince = $configRow['config_since'];
    if ($configSince instanceof DateTime) {
        $configSince = $configSince->format('Y-m-d\TH:i:s\Z');
    }
}

// Build response
$response = [
    "success" => true,
    "airport_icao" => $airport,
    "has_atis" => true,
    "atis" => [
        "atis_id" => (int)$atisRow['atis_id'],
        "callsign" => $atisRow['callsign'],
        "atis_type" => $atisRow['atis_type'],
        "atis_code" => $atisRow['atis_code'],
        "frequency" => $atisRow['frequency'],
        "atis_text" => $atisRow['atis_text'],
        "fetched_utc" => $fetchedUtc,
        "age_mins" => (int)$atisRow['age_mins'],
        "controller_cid" => $atisRow['controller_cid']
    ],
    "runways" => [
        "arr_runways" => $configRow ? $configRow['arr_runways'] : null,
        "dep_runways" => $configRow ? $configRow['dep_runways'] : null,
        "approach_info" => $configRow ? $configRow['approach_info'] : null,
        "config_since" => $configSince,
        "atis_code" => $configRow ? $configRow['atis_code'] : null,
        "details" => $runways
    ]
];

echo json_encode($response);
