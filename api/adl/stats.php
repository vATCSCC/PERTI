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

// Build the statistics query
// Domestic = ICAO starts with 'K' (CONUS), 'P' (Pacific - HI, AK, etc.)
// International = Everything else
//
// DCC Region mapping (dbo.apts.DCC_REGION uses full names):
//   Northeast    -> NE (ZBW ZDC ZNY ZOB ZWY)
//   Southeast    -> SE (ZID ZJX ZMA ZMO ZTL)
//   Midwest      -> MW (ZAU ZDV ZKC ZMP)
//   South Central-> SW (ZAB ZFW ZHO ZHU ZME)
//   West         -> NW (ZAK ZAN ZHN ZLA ZLC ZOA ZSE)
//   Canada, Caribbean, Other -> Other

$sql = "
WITH flight_classifications AS (
    SELECT 
        f.flight_key,
        f.fp_dept_icao,
        f.fp_dest_icao,
        CASE 
            WHEN LEFT(f.fp_dept_icao, 1) IN ('K', 'P') THEN 1 
            ELSE 0 
        END AS dep_domestic,
        CASE 
            WHEN LEFT(f.fp_dest_icao, 1) IN ('K', 'P') THEN 1 
            ELSE 0 
        END AS arr_domestic,
        dept_apt.DCC_REGION AS dep_dcc_region,
        dest_apt.DCC_REGION AS arr_dcc_region,
        dest_apt.ASPM77 AS arr_aspm77,
        dest_apt.OEP35 AS arr_oep35,
        dest_apt.Core30 AS arr_core30
    FROM dbo.adl_flights f
    LEFT JOIN dbo.apts dept_apt ON dept_apt.ICAO_ID = f.fp_dept_icao
    LEFT JOIN dbo.apts dest_apt ON dest_apt.ICAO_ID = f.fp_dest_icao
    WHERE f.is_active = 1
)
SELECT 
    -- Global totals
    COUNT(*) AS total_flights,
    SUM(CASE WHEN dep_domestic = 1 AND arr_domestic = 1 THEN 1 ELSE 0 END) AS domestic_to_domestic,
    SUM(CASE WHEN dep_domestic = 1 AND arr_domestic = 0 THEN 1 ELSE 0 END) AS domestic_to_intl,
    SUM(CASE WHEN dep_domestic = 0 AND arr_domestic = 1 THEN 1 ELSE 0 END) AS intl_to_domestic,
    SUM(CASE WHEN dep_domestic = 0 AND arr_domestic = 0 THEN 1 ELSE 0 END) AS intl_to_intl,
    
    -- Domestic flights total (either dep or arr is domestic)
    SUM(CASE WHEN dep_domestic = 1 OR arr_domestic = 1 THEN 1 ELSE 0 END) AS domestic_total,
    
    -- By arrival DCC Region (domestic arrivals only)
    -- Map full region names to abbreviations: Northeast->NE, Southeast->SE, Midwest->MW, South Central->SC, West->W
    SUM(CASE WHEN arr_domestic = 1 AND arr_dcc_region = 'Northeast' THEN 1 ELSE 0 END) AS arr_dcc_ne,
    SUM(CASE WHEN arr_domestic = 1 AND arr_dcc_region = 'Southeast' THEN 1 ELSE 0 END) AS arr_dcc_se,
    SUM(CASE WHEN arr_domestic = 1 AND arr_dcc_region = 'Midwest' THEN 1 ELSE 0 END) AS arr_dcc_mw,
    SUM(CASE WHEN arr_domestic = 1 AND arr_dcc_region = 'South Central' THEN 1 ELSE 0 END) AS arr_dcc_sc,
    SUM(CASE WHEN arr_domestic = 1 AND arr_dcc_region = 'West' THEN 1 ELSE 0 END) AS arr_dcc_w,
    SUM(CASE WHEN arr_domestic = 1 AND (arr_dcc_region IS NULL OR arr_dcc_region NOT IN ('Northeast','Southeast','Midwest','South Central','West')) THEN 1 ELSE 0 END) AS arr_dcc_other,
    
    -- By departure DCC Region (domestic departures only)
    SUM(CASE WHEN dep_domestic = 1 AND dep_dcc_region = 'Northeast' THEN 1 ELSE 0 END) AS dep_dcc_ne,
    SUM(CASE WHEN dep_domestic = 1 AND dep_dcc_region = 'Southeast' THEN 1 ELSE 0 END) AS dep_dcc_se,
    SUM(CASE WHEN dep_domestic = 1 AND dep_dcc_region = 'Midwest' THEN 1 ELSE 0 END) AS dep_dcc_mw,
    SUM(CASE WHEN dep_domestic = 1 AND dep_dcc_region = 'South Central' THEN 1 ELSE 0 END) AS dep_dcc_sc,
    SUM(CASE WHEN dep_domestic = 1 AND dep_dcc_region = 'West' THEN 1 ELSE 0 END) AS dep_dcc_w,
    SUM(CASE WHEN dep_domestic = 1 AND (dep_dcc_region IS NULL OR dep_dcc_region NOT IN ('Northeast','Southeast','Midwest','South Central','West')) THEN 1 ELSE 0 END) AS dep_dcc_other,
    
    -- By airport tier (domestic arrivals only)
    SUM(CASE WHEN arr_domestic = 1 AND arr_aspm77 = 1 THEN 1 ELSE 0 END) AS arr_aspm77,
    SUM(CASE WHEN arr_domestic = 1 AND arr_oep35 = 1 THEN 1 ELSE 0 END) AS arr_oep35,
    SUM(CASE WHEN arr_domestic = 1 AND arr_core30 = 1 THEN 1 ELSE 0 END) AS arr_core30,
    
    -- Non-tier domestic arrivals
    SUM(CASE WHEN arr_domestic = 1 AND (arr_aspm77 = 0 OR arr_aspm77 IS NULL) THEN 1 ELSE 0 END) AS arr_non_aspm77,
    SUM(CASE WHEN arr_domestic = 1 AND (arr_oep35 = 0 OR arr_oep35 IS NULL) THEN 1 ELSE 0 END) AS arr_non_oep35,
    SUM(CASE WHEN arr_domestic = 1 AND (arr_core30 = 0 OR arr_core30 IS NULL) THEN 1 ELSE 0 END) AS arr_non_core30
FROM flight_classifications
";

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
