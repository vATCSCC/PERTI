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

// Get effective ATIS source decision and all relevant ATIS records
$sql = "SELECT
    effective_source,
    has_arr,
    has_dep,
    has_comb,
    arr_age_mins,
    dep_age_mins,
    comb_age_mins,
    effective_age_mins,
    -- ARR ATIS fields
    arr_atis_id,
    arr_callsign,
    arr_atis_code,
    arr_frequency,
    arr_atis_text,
    arr_controller_cid,
    arr_fetched_utc,
    -- DEP ATIS fields
    dep_atis_id,
    dep_callsign,
    dep_atis_code,
    dep_frequency,
    dep_atis_text,
    dep_controller_cid,
    dep_fetched_utc,
    -- COMB ATIS fields
    comb_atis_id,
    comb_callsign,
    comb_atis_code,
    comb_frequency,
    comb_atis_text,
    comb_controller_cid,
    comb_fetched_utc,
    -- Weather (from best source)
    wind_dir_deg,
    wind_speed_kt,
    wind_gust_kt,
    visibility_sm,
    ceiling_ft,
    altimeter_inhg,
    flight_category,
    weather_category
FROM dbo.vw_effective_atis
WHERE airport_icao = ?";

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

$effectiveAtis = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($stmt);

// If no recent ATIS found, return empty response
if (!$effectiveAtis || !$effectiveAtis['effective_source']) {
    sqlsrv_close($conn);
    echo json_encode([
        "success" => true,
        "airport_icao" => $airport,
        "has_atis" => false,
        "effective_source" => null,
        "atis" => null,
        "atis_arr" => null,
        "atis_dep" => null,
        "atis_comb" => null,
        "runways" => null,
        "weather" => null
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
    atis_code,
    effective_source,
    effective_age_mins
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

// Helper to format DateTime
function formatUtc($dt) {
    if ($dt instanceof DateTime) {
        return $dt->format('Y-m-d\TH:i:s\Z');
    }
    return $dt;
}

// Helper to build ATIS object
function buildAtisObject($prefix, $data) {
    $atisId = $data[$prefix . '_atis_id'];
    if (!$atisId) return null;

    return [
        "atis_id" => (int)$atisId,
        "callsign" => $data[$prefix . '_callsign'],
        "atis_code" => $data[$prefix . '_atis_code'],
        "frequency" => $data[$prefix . '_frequency'],
        "atis_text" => $data[$prefix . '_atis_text'],
        "controller_cid" => $data[$prefix . '_controller_cid'],
        "fetched_utc" => formatUtc($data[$prefix . '_fetched_utc']),
        "age_mins" => $data[$prefix . '_age_mins'] !== null ? (int)$data[$prefix . '_age_mins'] : null
    ];
}

$configSince = null;
if ($configRow && $configRow['config_since']) {
    $configSince = formatUtc($configRow['config_since']);
}

// Build individual ATIS objects
$atisArr = buildAtisObject('arr', $effectiveAtis);
$atisDep = buildAtisObject('dep', $effectiveAtis);
$atisComb = buildAtisObject('comb', $effectiveAtis);

// Build primary "atis" object based on effective source
$effectiveSource = $effectiveAtis['effective_source'];
$primaryAtis = null;
switch ($effectiveSource) {
    case 'ARR_DEP':
        // When using both, return ARR as primary (since it has weather info)
        $primaryAtis = $atisArr;
        if ($primaryAtis) $primaryAtis['atis_type'] = 'ARR';
        break;
    case 'COMB':
        $primaryAtis = $atisComb;
        if ($primaryAtis) $primaryAtis['atis_type'] = 'COMB';
        break;
    case 'ARR_ONLY':
        $primaryAtis = $atisArr;
        if ($primaryAtis) $primaryAtis['atis_type'] = 'ARR';
        break;
    case 'DEP_ONLY':
        $primaryAtis = $atisDep;
        if ($primaryAtis) $primaryAtis['atis_type'] = 'DEP';
        break;
}

// Build response
$response = [
    "success" => true,
    "airport_icao" => $airport,
    "has_atis" => true,
    "effective_source" => $effectiveSource,
    // Primary ATIS (backwards compatible)
    "atis" => $primaryAtis,
    // Individual ATIS by type (new)
    "atis_arr" => $atisArr,
    "atis_dep" => $atisDep,
    "atis_comb" => $atisComb,
    // Runway configuration
    "runways" => [
        "arr_runways" => $configRow ? $configRow['arr_runways'] : null,
        "dep_runways" => $configRow ? $configRow['dep_runways'] : null,
        "approach_info" => $configRow ? $configRow['approach_info'] : null,
        "config_since" => $configSince,
        "atis_code" => $configRow ? $configRow['atis_code'] : null,
        "effective_source" => $configRow && isset($configRow['effective_source']) ? $configRow['effective_source'] : null,
        "details" => $runways
    ],
    // Weather (from best source)
    "weather" => [
        "wind_dir_deg" => $effectiveAtis['wind_dir_deg'] !== null ? (int)$effectiveAtis['wind_dir_deg'] : null,
        "wind_speed_kt" => $effectiveAtis['wind_speed_kt'] !== null ? (int)$effectiveAtis['wind_speed_kt'] : null,
        "wind_gust_kt" => $effectiveAtis['wind_gust_kt'] !== null ? (int)$effectiveAtis['wind_gust_kt'] : null,
        "visibility_sm" => $effectiveAtis['visibility_sm'] !== null ? (float)$effectiveAtis['visibility_sm'] : null,
        "ceiling_ft" => $effectiveAtis['ceiling_ft'] !== null ? (int)$effectiveAtis['ceiling_ft'] : null,
        "altimeter_inhg" => $effectiveAtis['altimeter_inhg'] !== null ? (float)$effectiveAtis['altimeter_inhg'] : null,
        "flight_category" => $effectiveAtis['flight_category'],
        "weather_category" => $effectiveAtis['weather_category']
    ]
];

echo json_encode($response);
