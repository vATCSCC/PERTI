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
require_once("../../load/input.php");

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
$airport = isset($_GET['airport']) ? get_upper('airport') : '';

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
        "atis_age_mins" => null,
        "flight_category" => null,
        "weather_category" => "VMC",
        "weather_impact_category" => 0,
        "config_matched" => false,
        "config_id" => null,
        "config_name" => null,
        "match_type" => null,
        "match_score" => 0,
        "arr_runways" => null,
        "dep_runways" => null,
        "weather" => [
            "wind_dir_deg" => null,
            "wind_speed_kt" => null,
            "wind_gust_kt" => null,
            "visibility_sm" => null,
            "ceiling_ft" => null
        ],
        "rates" => [
            "vatsim_aar" => null,
            "vatsim_adr" => null,
            "rw_aar" => null,
            "rw_adr" => null
        ],
        "is_suggested" => true,
        "rate_source" => null,
        "effective_utc" => gmdate('Y-m-d\TH:i:s\Z')
    ]);
    exit;
}

// Format effective_utc
$effectiveUtc = $row['effective_utc'];
if ($effectiveUtc instanceof DateTime) {
    $effectiveUtc = $effectiveUtc->format('Y-m-d\TH:i:s\Z');
}

// Format override_end_utc if present
$overrideEndUtc = isset($row['override_end_utc']) ? $row['override_end_utc'] : null;
if ($overrideEndUtc instanceof DateTime) {
    $overrideEndUtc = $overrideEndUtc->format('Y-m-d\TH:i:s\Z');
}

// Build response with enhanced fields
$response = [
    "success" => true,
    "airport_icao" => $row['airport_icao'],
    "has_atis" => (bool)$row['has_atis'],
    "atis_code" => $row['atis_code'],
    "atis_age_mins" => $row['atis_age_mins'] !== null ? (int)$row['atis_age_mins'] : null,
    "flight_category" => $row['flight_category'],
    "weather_category" => $row['weather_category'] ?? 'VMC',
    "weather_impact_category" => (int)($row['weather_impact_category'] ?? 0),
    "config_matched" => (bool)$row['config_matched'],
    "config_id" => $row['config_id'] !== null ? (int)$row['config_id'] : null,
    "config_name" => $row['config_name'],
    "match_type" => $row['match_type'],
    "match_score" => (int)($row['match_score'] ?? 0),
    "arr_runways" => $row['arr_runways'],
    "dep_runways" => $row['dep_runways'],
    "weather" => [
        "wind_dir_deg" => $row['wind_dir_deg'] !== null ? (int)$row['wind_dir_deg'] : null,
        "wind_speed_kt" => $row['wind_speed_kt'] !== null ? (int)$row['wind_speed_kt'] : null,
        "wind_gust_kt" => $row['wind_gust_kt'] !== null ? (int)$row['wind_gust_kt'] : null,
        "visibility_sm" => $row['visibility_sm'] !== null ? (float)$row['visibility_sm'] : null,
        "ceiling_ft" => $row['ceiling_ft'] !== null ? (int)$row['ceiling_ft'] : null
    ],
    "rates" => [
        "vatsim_aar" => $row['vatsim_aar'] !== null ? (int)$row['vatsim_aar'] : null,
        "vatsim_adr" => $row['vatsim_adr'] !== null ? (int)$row['vatsim_adr'] : null,
        "rw_aar" => $row['rw_aar'] !== null ? (int)$row['rw_aar'] : null,
        "rw_adr" => $row['rw_adr'] !== null ? (int)$row['rw_adr'] : null
    ],
    "is_suggested" => (bool)$row['is_suggested'],
    "rate_source" => $row['rate_source'],
    "effective_utc" => $effectiveUtc,
    // Manual override fields (new)
    "has_override" => (bool)($row['has_override'] ?? false),
    "override_id" => isset($row['override_id']) ? (int)$row['override_id'] : null,
    "override_reason" => $row['override_reason'] ?? null,
    "override_end_utc" => $overrideEndUtc
];

echo json_encode($response);
