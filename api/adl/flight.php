<?php

// api/adl/flight.php
// Returns a single flight from ADL Azure SQL (adl_flights) in JSON format,
// looked up by id or callsign.

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once("../../load/config.php"); // ADL_SQL_* constants

if (!defined("ADL_SQL_HOST") || !defined("ADL_SQL_DATABASE") ||
    !defined("ADL_SQL_USERNAME") || !defined("ADL_SQL_PASSWORD")) {

    http_response_code(500);
    echo json_encode([
        "error" => "ADL_SQL_* constants are not defined. Check config.php for ADL_SQL_HOST, ADL_SQL_DATABASE, ADL_SQL_USERNAME, ADL_SQL_PASSWORD."
    ]);
    exit;
}

if (!function_exists('sqlsrv_connect')) {
    http_response_code(500);
    echo json_encode([
        "error" => "The sqlsrv extension is not available in PHP. It is required to connect to Azure SQL (ADL)."
    ]);
    exit;
}

function adl_sql_error_message()
{
    $errs = sqlsrv_errors(SQLSRV_ERR_ERRORS);
    if (!$errs) {
        return "";
    }
    $msgs = [];
    foreach ($errs as $e) {
        $msgs[] = (isset($e['SQLSTATE']) ? $e['SQLSTATE'] : '') . " " .
                  (isset($e['code']) ? $e['code'] : '') . " " .
                  (isset($e['message']) ? trim($e['message']) : '');
    }
    return implode(" | ", $msgs);
}

$connectionInfo = [
    "Database" => ADL_SQL_DATABASE,
    "UID"      => ADL_SQL_USERNAME,
    "PWD"      => ADL_SQL_PASSWORD
];

$conn_adl = sqlsrv_connect(ADL_SQL_HOST, $connectionInfo);
if ($conn_adl === false) {
    http_response_code(500);
    echo json_encode([
        "error" => "Unable to connect to ADL Azure SQL database.",
        "sql_error" => adl_sql_error_message()
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// Use ADL Query Helper for normalized table support
// ---------------------------------------------------------------------------

require_once(__DIR__ . '/AdlQueryHelper.php');
$helper = new AdlQueryHelper();

// ---------------------------------------------------------------------------
// Determine lookup key
// ---------------------------------------------------------------------------

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$callsign = '';
if (isset($_GET['cs'])) {
    $callsign = strtoupper(trim($_GET['cs']));
} elseif (isset($_GET['callsign'])) {
    $callsign = strtoupper(trim($_GET['callsign']));
}

$activeParam = isset($_GET['active']) ? strtolower(trim($_GET['active'])) : '';

// Validate input
if ($id <= 0 && $callsign === '') {
    http_response_code(400);
    echo json_encode([
        "error" => "Must provide ?id=<int> or ?cs=<CALLSIGN>"
    ]);
    exit;
}

// Build query using helper (supports view or normalized tables)
$activeOnly = ($activeParam === '1' || $activeParam === 'true' || $activeParam === 'yes');
$query = $helper->buildFlightLookupQuery([
    'id' => $id,
    'callsign' => $callsign,
    'activeOnly' => $activeOnly
]);
$sql = $query['sql'];
$params = $query['params'];

// ---------------------------------------------------------------------------
// Execute
// ---------------------------------------------------------------------------

$stmt = sqlsrv_query($conn_adl, $sql, $params);
if ($stmt === false) {
    http_response_code(500);
    echo json_encode([
        "error" => "Database error when querying ADL (flight).",
        "sql_error" => adl_sql_error_message(),
        "sql" => $sql
    ]);
    exit;
}

$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($stmt);
sqlsrv_close($conn_adl);

if (!$row) {
    http_response_code(404);
    echo json_encode([
        "error" => "Flight not found in ADL"
    ]);
    exit;
}

// Normalise DateTime columns to strings
foreach ($row as $k => $v) {
    if ($v instanceof DateTimeInterface) {
        $row[$k] = $v->format("Y-m-d\\TH:i:s\\Z");
    }
}

echo json_encode($row);

?>
