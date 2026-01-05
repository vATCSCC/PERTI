<?php

// api/demand/airports.php
// Returns list of airports for demand visualization filters
// Supports filtering by category (ASPM77/OEP35/Core30), ARTCC, and search term

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

// Get filter parameters
$category = isset($_GET['category']) ? strtolower(trim($_GET['category'])) : 'all';
$artcc = isset($_GET['artcc']) ? strtoupper(trim($_GET['artcc'])) : '';
$tier = isset($_GET['tier']) ? trim($_GET['tier']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Load tier data if tier filter is specified
$tierARTCCs = [];
if (!empty($tier) && $tier !== 'all' && !empty($artcc)) {
    $tierJsonPath = realpath(__DIR__ . '/../../assets/data/artcc_tiers.json');
    if ($tierJsonPath && file_exists($tierJsonPath)) {
        $tierData = json_decode(file_get_contents($tierJsonPath), true);
        if ($tierData && isset($tierData['byFacility'][$artcc][$tier])) {
            $tierARTCCs = $tierData['byFacility'][$artcc][$tier]['artccs'] ?? [];
        }
    }
}

// Build WHERE clause
$whereClauses = [];
$params = [];

// Only include airports with ICAO codes (4 characters starting with K or P for US)
$whereClauses[] = "ICAO_ID IS NOT NULL AND LEN(ICAO_ID) = 4";

// Filter by category
if ($category === 'aspm77') {
    $whereClauses[] = "ASPM77 = 1";
} elseif ($category === 'oep35') {
    $whereClauses[] = "OEP35 = 1";
} elseif ($category === 'core30') {
    $whereClauses[] = "Core30 = 1";
}

// Filter by ARTCC or tier ARTCCs
if (!empty($tierARTCCs)) {
    // Filter by multiple ARTCCs from tier
    $placeholders = [];
    foreach ($tierARTCCs as $tierArtcc) {
        $placeholders[] = "?";
        $params[] = $tierArtcc;
    }
    $whereClauses[] = "RESP_ARTCC_ID IN (" . implode(", ", $placeholders) . ")";
} elseif (!empty($artcc)) {
    // Filter by single ARTCC
    $whereClauses[] = "RESP_ARTCC_ID = ?";
    $params[] = $artcc;
}

// Filter by search term (ICAO or name)
if (!empty($search)) {
    $whereClauses[] = "(ICAO_ID LIKE ? OR ARPT_NAME LIKE ?)";
    $searchParam = '%' . $search . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$whereSQL = implode(" AND ", $whereClauses);

// Query airports
$sql = "
    SELECT
        ICAO_ID,
        ARPT_NAME,
        RESP_ARTCC_ID,
        DCC_REGION,
        ASPM77,
        OEP35,
        Core30,
        LAT_DECIMAL,
        LONG_DECIMAL
    FROM dbo.apts
    WHERE {$whereSQL}
    ORDER BY
        CASE
            WHEN Core30 = 1 THEN 1
            WHEN OEP35 = 1 THEN 2
            WHEN ASPM77 = 1 THEN 3
            ELSE 4
        END,
        ICAO_ID
";

$stmt = sqlsrv_query($conn, $sql, $params);
if ($stmt === false) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Database error when querying airports.",
        "sql_error" => adl_sql_error_message()
    ]);
    sqlsrv_close($conn);
    exit;
}

// Build response
$airports = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $airports[] = [
        "icao" => $row['ICAO_ID'],
        "name" => $row['ARPT_NAME'],
        "artcc" => $row['RESP_ARTCC_ID'],
        "dcc_region" => $row['DCC_REGION'],
        "is_aspm77" => $row['ASPM77'] == 1,
        "is_oep35" => $row['OEP35'] == 1,
        "is_core30" => $row['Core30'] == 1,
        "lat" => $row['LAT_DECIMAL'] !== null ? (float)$row['LAT_DECIMAL'] : null,
        "lon" => $row['LONG_DECIMAL'] !== null ? (float)$row['LONG_DECIMAL'] : null
    ];
}

sqlsrv_free_stmt($stmt);

// Also get list of unique ARTCCs for filter dropdown
$artccSql = "
    SELECT DISTINCT RESP_ARTCC_ID
    FROM dbo.apts
    WHERE RESP_ARTCC_ID IS NOT NULL AND RESP_ARTCC_ID != ''
    ORDER BY RESP_ARTCC_ID
";
$artccStmt = sqlsrv_query($conn, $artccSql);
$artccList = [];
if ($artccStmt !== false) {
    while ($row = sqlsrv_fetch_array($artccStmt, SQLSRV_FETCH_ASSOC)) {
        $artccList[] = $row['RESP_ARTCC_ID'];
    }
    sqlsrv_free_stmt($artccStmt);
}

sqlsrv_close($conn);

// Return response
echo json_encode([
    "success" => true,
    "timestamp" => gmdate("Y-m-d\\TH:i:s\\Z"),
    "filters" => [
        "category" => $category,
        "artcc" => $artcc,
        "tier" => $tier,
        "search" => $search
    ],
    "count" => count($airports),
    "airports" => $airports,
    "artcc_list" => $artccList
]);
