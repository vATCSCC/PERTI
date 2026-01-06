<?php

// api/adl/stats.php
// Returns flight count statistics for the TMI status bar
// Categories: Global (D-D, D-I, I-D, I-I), Domestic by DCC/ASPM77/OEP35/Core30

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once("../../load/config.php");

if (!defined("ADL_SQL_HOST") || !defined("ADL_SQL_DATABASE") ||
    !defined("ADL_SQL_USERNAME") || !defined("ADL_SQL_PASSWORD")) {
    http_response_code(500);
    echo json_encode(["error" => "ADL_SQL_* constants are not defined."]);
    exit;
}

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

if (!function_exists('sqlsrv_connect')) {
    http_response_code(500);
    echo json_encode(["error" => "sqlsrv extension not available."]);
    exit;
}

$connectionInfo = [
    "Database" => ADL_SQL_DATABASE,
    "UID"      => ADL_SQL_USERNAME,
    "PWD"      => ADL_SQL_PASSWORD
];

$conn = sqlsrv_connect(ADL_SQL_HOST, $connectionInfo);
if ($conn === false) {
    http_response_code(500);
    echo json_encode([
        "error" => "Unable to connect to ADL database.",
        "sql_error" => adl_sql_error_message()
    ]);
    exit;
}

// Use ADL Query Helper for normalized table support
require_once(__DIR__ . '/AdlQueryHelper.php');

// Build the statistics query using helper
// Supports feature flag switching between view and normalized tables
// DCC Region mapping (dbo.apts.DCC_REGION uses full names):
//   Northeast    -> NE (ZBW ZDC ZNY ZOB ZWY)
//   Southeast    -> SE (ZID ZJX ZMA ZMO ZTL)
//   Midwest      -> MW (ZAU ZDV ZKC ZMP)
//   South Central-> SW (ZAB ZFW ZHO ZHU ZME)
//   West         -> NW (ZAK ZAN ZHN ZLA ZLC ZOA ZSE)
//   Canada, Caribbean, Other -> Other

$helper = new AdlQueryHelper();
$query = $helper->buildStatsQuery();
$sql = $query['sql'];

$stmt = sqlsrv_query($conn, $sql);
if ($stmt === false) {
    http_response_code(500);
    echo json_encode([
        "error" => "Database error when querying flight stats.",
        "sql_error" => adl_sql_error_message()
    ]);
    sqlsrv_close($conn);
    exit;
}

$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($stmt);
sqlsrv_close($conn);

if (!$row) {
    echo json_encode([
        "snapshot_utc" => gmdate("Y-m-d\\TH:i:s\\Z"),
        "global" => [
            "total" => 0,
            "domestic_to_domestic" => 0,
            "domestic_to_intl" => 0,
            "intl_to_domestic" => 0,
            "intl_to_intl" => 0
        ],
        "domestic" => [
            "total" => 0,
            "arr_dcc" => ["NE" => 0, "SE" => 0, "MW" => 0, "SC" => 0, "W" => 0, "Other" => 0],
            "dep_dcc" => ["NE" => 0, "SE" => 0, "MW" => 0, "SC" => 0, "W" => 0, "Other" => 0],
            "arr_aspm77" => ["yes" => 0, "no" => 0],
            "arr_oep35" => ["yes" => 0, "no" => 0],
            "arr_core30" => ["yes" => 0, "no" => 0]
        ]
    ]);
    exit;
}

// Build response structure
$response = [
    "snapshot_utc" => gmdate("Y-m-d\\TH:i:s\\Z"),
    "global" => [
        "total" => (int)$row['total_flights'],
        "domestic_to_domestic" => (int)$row['domestic_to_domestic'],
        "domestic_to_intl" => (int)$row['domestic_to_intl'],
        "intl_to_domestic" => (int)$row['intl_to_domestic'],
        "intl_to_intl" => (int)$row['intl_to_intl']
    ],
    "domestic" => [
        "total" => (int)$row['domestic_total'],
        "arr_dcc" => [
            "NE" => (int)$row['arr_dcc_ne'],
            "SE" => (int)$row['arr_dcc_se'],
            "MW" => (int)$row['arr_dcc_mw'],
            "SC" => (int)$row['arr_dcc_sc'],
            "W" => (int)$row['arr_dcc_w'],
            "Other" => (int)$row['arr_dcc_other']
        ],
        "dep_dcc" => [
            "NE" => (int)$row['dep_dcc_ne'],
            "SE" => (int)$row['dep_dcc_se'],
            "MW" => (int)$row['dep_dcc_mw'],
            "SC" => (int)$row['dep_dcc_sc'],
            "W" => (int)$row['dep_dcc_w'],
            "Other" => (int)$row['dep_dcc_other']
        ],
        "arr_aspm77" => ["yes" => (int)$row['arr_aspm77'], "no" => (int)$row['arr_non_aspm77']],
        "arr_oep35" => ["yes" => (int)$row['arr_oep35'], "no" => (int)$row['arr_non_oep35']],
        "arr_core30" => ["yes" => (int)$row['arr_core30'], "no" => (int)$row['arr_non_core30']]
    ]
];

echo json_encode($response);
