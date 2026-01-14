<?php

// api/demand/config_search.php
// Returns available configurations for an airport from ADL
// Used for config picker in plan.php and sheet.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

require_once("../../load/config.php");
require_once("../../load/connect.php");

// Check if ADL connection is available
if (!$conn_adl) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "ADL database connection not available."]);
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
$airportIcao = $airport;
if (strlen($airport) === 3 && !preg_match('/^[PK]/', $airport)) {
    $airportIcao = 'K' . $airport;
}

// Get all active configs for the airport with their rates
$sql = "
    SELECT
        s.config_id,
        s.airport_faa,
        s.airport_icao,
        s.config_name,
        s.config_code,
        s.arr_runways,
        s.dep_runways,
        -- VATSIM rates for each weather category
        r.vatsim_vmc_aar,
        r.vatsim_vmc_adr,
        r.vatsim_lvmc_aar,
        r.vatsim_lvmc_adr,
        r.vatsim_imc_aar,
        r.vatsim_imc_adr,
        r.vatsim_limc_aar,
        r.vatsim_limc_adr,
        r.vatsim_vlimc_aar,
        r.vatsim_vlimc_adr
    FROM dbo.vw_airport_config_summary s
    LEFT JOIN dbo.vw_airport_config_rates r ON s.config_id = r.config_id
    WHERE (s.airport_icao = ? OR s.airport_faa = ?)
      AND s.is_active = 1
    ORDER BY s.config_name
";

$stmt = sqlsrv_query($conn_adl, $sql, [$airportIcao, $airport]);

if ($stmt === false) {
    $errs = sqlsrv_errors(SQLSRV_ERR_ERRORS);
    $errMsg = $errs ? implode(" | ", array_map(fn($e) => trim($e['message'] ?? ''), $errs)) : '';
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Query failed.",
        "sql_error" => $errMsg
    ]);
    exit;
}

$configs = [];
$foundIcao = null;
$foundFaa = null;

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    if (!$foundIcao) {
        $foundIcao = $row['airport_icao'];
        $foundFaa = $row['airport_faa'];
    }

    $configs[] = [
        "config_id" => (int)$row['config_id'],
        "config_name" => $row['config_name'],
        "config_code" => $row['config_code'],
        "arr_runways" => $row['arr_runways'],
        "dep_runways" => $row['dep_runways'],
        // Rates by weather category
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

echo json_encode([
    "success" => true,
    "airport_icao" => $foundIcao ?? $airportIcao,
    "airport_faa" => $foundFaa ?? $airport,
    "configs" => $configs,
    "count" => count($configs)
]);
