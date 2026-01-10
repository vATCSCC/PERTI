<?php

// api/demand/rates.php
// Returns suggested AAR/ADR rates for an airport
// Based on current ATIS weather and runway configuration

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

// Call stored procedure to get suggested rates
$sql = "EXEC dbo.sp_GetSuggestedRates @airport_icao = ?";
$stmt = sqlsrv_query($conn, $sql, [$airport]);

if ($stmt === false) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Failed to execute sp_GetSuggestedRates.",
        "sql_error" => adl_sql_error_message()
    ]);
    sqlsrv_close($conn);
    exit;
}

$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($stmt);
sqlsrv_close($conn);

if (!$row) {
    // No data returned - airport may not have configurations
    echo json_encode([
        "success" => true,
        "airport_icao" => $airport,
        "has_atis" => false,
        "atis_code" => null,
        "flight_category" => null,
        "weather_category" => "VMC",
        "config_matched" => false,
        "config_id" => null,
        "config_name" => null,
        "arr_runways" => null,
        "dep_runways" => null,
        "rates" => [
            "vatsim_aar" => null,
            "vatsim_adr" => null,
            "rw_aar" => null,
            "rw_adr" => null
        ],
        "is_suggested" => true,
        "effective_utc" => gmdate('Y-m-d\TH:i:s\Z')
    ]);
    exit;
}

// Format effective_utc
$effectiveUtc = $row['effective_utc'];
if ($effectiveUtc instanceof DateTime) {
    $effectiveUtc = $effectiveUtc->format('Y-m-d\TH:i:s\Z');
}

// Build response
$response = [
    "success" => true,
    "airport_icao" => $row['airport_icao'],
    "has_atis" => (bool)$row['has_atis'],
    "atis_code" => $row['atis_code'],
    "flight_category" => $row['flight_category'],
    "weather_category" => $row['weather_category'] ?? 'VMC',
    "config_matched" => (bool)$row['config_matched'],
    "config_id" => $row['config_id'],
    "config_name" => $row['config_name'],
    "arr_runways" => $row['arr_runways'],
    "dep_runways" => $row['dep_runways'],
    "rates" => [
        "vatsim_aar" => $row['vatsim_aar'] !== null ? (int)$row['vatsim_aar'] : null,
        "vatsim_adr" => $row['vatsim_adr'] !== null ? (int)$row['vatsim_adr'] : null,
        "rw_aar" => $row['rw_aar'] !== null ? (int)$row['rw_aar'] : null,
        "rw_adr" => $row['rw_adr'] !== null ? (int)$row['rw_adr'] : null
    ],
    "is_suggested" => (bool)$row['is_suggested'],
    "effective_utc" => $effectiveUtc
];

echo json_encode($response);
