<?php

// api/demand/configs.php
// Returns available configurations for an airport
// Used for rate override config selection

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
    $errs = sqlsrv_errors(SQLSRV_ERR_ERRORS);
    $errMsg = $errs ? implode(" | ", array_map(fn($e) => trim($e['message'] ?? ''), $errs)) : '';
    echo json_encode([
        "success" => false,
        "error" => "Unable to connect to ADL database.",
        "sql_error" => $errMsg
    ]);
    exit;
}

// Get weather category for current conditions (if available)
$weatherCategory = 'VMC'; // Default

$weatherSql = "SELECT TOP 1 weather_category FROM dbo.vatsim_atis
               WHERE airport_icao = ? AND weather_category IS NOT NULL
               ORDER BY fetched_utc DESC";
$weatherStmt = sqlsrv_query($conn, $weatherSql, [$airport]);
if ($weatherStmt) {
    $weatherRow = sqlsrv_fetch_array($weatherStmt, SQLSRV_FETCH_ASSOC);
    if ($weatherRow && $weatherRow['weather_category']) {
        $weatherCategory = $weatherRow['weather_category'];
    }
    sqlsrv_free_stmt($weatherStmt);
}

// Get all active configs for the airport with their rates
$sql = "
    SELECT
        s.config_id,
        s.airport_icao,
        s.config_name,
        s.config_code,
        s.arr_runways,
        s.dep_runways,
        s.is_active,
        -- VATSIM rates for each weather category
        r.vatsim_vmc_aar,
        r.vatsim_lvmc_aar,
        r.vatsim_imc_aar,
        r.vatsim_limc_aar,
        r.vatsim_vlimc_aar,
        r.vatsim_vmc_adr,
        r.vatsim_lvmc_adr,
        r.vatsim_imc_adr,
        r.vatsim_limc_adr,
        r.vatsim_vlimc_adr,
        -- RW rates
        r.rw_vmc_aar,
        r.rw_lvmc_aar,
        r.rw_imc_aar,
        r.rw_limc_aar,
        r.rw_vlimc_aar,
        r.rw_vmc_adr,
        r.rw_lvmc_adr,
        r.rw_imc_adr,
        r.rw_limc_adr,
        r.rw_vlimc_adr
    FROM dbo.vw_airport_config_summary s
    LEFT JOIN dbo.vw_airport_config_rates r ON s.config_id = r.config_id
    WHERE s.airport_icao = ?
      AND s.is_active = 1
    ORDER BY s.config_name
";

$stmt = sqlsrv_query($conn, $sql, [$airport]);

if ($stmt === false) {
    http_response_code(500);
    $errs = sqlsrv_errors(SQLSRV_ERR_ERRORS);
    $errMsg = $errs ? implode(" | ", array_map(fn($e) => trim($e['message'] ?? ''), $errs)) : '';
    echo json_encode([
        "success" => false,
        "error" => "Query failed.",
        "sql_error" => $errMsg
    ]);
    sqlsrv_close($conn);
    exit;
}

$configs = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    // Determine current rates based on weather category
    $aarField = 'vatsim_' . strtolower($weatherCategory) . '_aar';
    $adrField = 'vatsim_' . strtolower($weatherCategory) . '_adr';
    $rwAarField = 'rw_' . strtolower($weatherCategory) . '_aar';
    $rwAdrField = 'rw_' . strtolower($weatherCategory) . '_adr';

    $configs[] = [
        "config_id" => (int)$row['config_id'],
        "config_name" => $row['config_name'],
        "config_code" => $row['config_code'],
        "arr_runways" => $row['arr_runways'],
        "dep_runways" => $row['dep_runways'],
        // Rates for current weather
        "current_aar" => $row[$aarField] !== null ? (int)$row[$aarField] : null,
        "current_adr" => $row[$adrField] !== null ? (int)$row[$adrField] : null,
        "current_rw_aar" => $row[$rwAarField] !== null ? (int)$row[$rwAarField] : null,
        "current_rw_adr" => $row[$rwAdrField] !== null ? (int)$row[$rwAdrField] : null,
        // All VATSIM rates by weather
        "rates" => [
            "vmc" => [
                "aar" => $row['vatsim_vmc_aar'] !== null ? (int)$row['vatsim_vmc_aar'] : null,
                "adr" => $row['vatsim_vmc_adr'] !== null ? (int)$row['vatsim_vmc_adr'] : null
            ],
            "lvmc" => [
                "aar" => $row['vatsim_lvmc_aar'] !== null ? (int)$row['vatsim_lvmc_aar'] : null,
                "adr" => $row['vatsim_lvmc_adr'] !== null ? (int)$row['vatsim_lvmc_adr'] : null
            ],
            "imc" => [
                "aar" => $row['vatsim_imc_aar'] !== null ? (int)$row['vatsim_imc_aar'] : null,
                "adr" => $row['vatsim_imc_adr'] !== null ? (int)$row['vatsim_imc_adr'] : null
            ],
            "limc" => [
                "aar" => $row['vatsim_limc_aar'] !== null ? (int)$row['vatsim_limc_aar'] : null,
                "adr" => $row['vatsim_limc_adr'] !== null ? (int)$row['vatsim_limc_adr'] : null
            ],
            "vlimc" => [
                "aar" => $row['vatsim_vlimc_aar'] !== null ? (int)$row['vatsim_vlimc_aar'] : null,
                "adr" => $row['vatsim_vlimc_adr'] !== null ? (int)$row['vatsim_vlimc_adr'] : null
            ]
        ]
    ];
}

sqlsrv_free_stmt($stmt);
sqlsrv_close($conn);

echo json_encode([
    "success" => true,
    "airport_icao" => $airport,
    "weather_category" => $weatherCategory,
    "configs" => $configs,
    "count" => count($configs)
]);
