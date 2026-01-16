<?php

// api/adl/current.php
// Returns current ADL flights from the Azure SQL ADL database (adl_flights).

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

header('Content-Type: application/json; charset=utf-8');

// ---------------------------------------------------------------------------
// 1) Ensure ADL DB connection constants exist
// ---------------------------------------------------------------------------

require_once("../../load/config.php"); // should define ADL_SQL_HOST, ADL_SQL_DATABASE, ADL_SQL_USERNAME, ADL_SQL_PASSWORD

if (!defined("ADL_SQL_HOST") || !defined("ADL_SQL_DATABASE") ||
    !defined("ADL_SQL_USERNAME") || !defined("ADL_SQL_PASSWORD")) {

    http_response_code(500);
    echo json_encode([
        "error" => "ADL_SQL_* constants are not defined. Check config.php for ADL_SQL_HOST, ADL_SQL_DATABASE, ADL_SQL_USERNAME, ADL_SQL_PASSWORD."
    ]);
    exit;
}

// ---------------------------------------------------------------------------
/**
 * Simple wrapper to format sqlsrv_errors() as a string.
 */
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

// ---------------------------------------------------------------------------
// 2) Connect to Azure SQL ADL database
// ---------------------------------------------------------------------------

if (!function_exists('sqlsrv_connect')) {
    http_response_code(500);
    echo json_encode([
        "error" => "The sqlsrv extension is not available in PHP. It is required to connect to Azure SQL (ADL)."
    ]);
    exit;
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
// 3) Use ADL Query Helper for normalized table support
// ---------------------------------------------------------------------------

require_once(__DIR__ . '/AdlQueryHelper.php');
$helper = new AdlQueryHelper();

// ---------------------------------------------------------------------------
// 4) Input parameters
// ---------------------------------------------------------------------------

$callsign = '';
if (isset($_GET['cs'])) {
    $callsign = get_upper('cs');
} elseif (isset($_GET['callsign'])) {
    $callsign = get_upper('callsign');
}

$dep = isset($_GET['dep']) ? get_upper('dep') : '';
$arr = isset($_GET['arr']) ? get_upper('arr') : '';

// active flag: default is only active flights
$activeParam = isset($_GET['active']) ? get_lower('active') : '1';
$activeOnly = ($activeParam !== 'all' && $activeParam !== '0' && $activeParam !== 'false' && $activeParam !== 'no');

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10000;
if ($limit <= 0) {
    $limit = 10000;
} elseif ($limit > 15000) {
    $limit = 15000;
}

// ---------------------------------------------------------------------------
// 5) Build query using helper (supports view or normalized tables)
// ---------------------------------------------------------------------------

$query = $helper->buildCurrentFlightsQuery([
    'activeOnly' => $activeOnly,
    'callsign' => $callsign,
    'dep' => $dep,
    'arr' => $arr,
    'limit' => $limit
]);
$sql = $query['sql'];
$params = $query['params'];

// ---------------------------------------------------------------------------
// 7) Execute query
// ---------------------------------------------------------------------------

$stmt = sqlsrv_query($conn_adl, $sql, $params);
if ($stmt === false) {
    http_response_code(500);
    echo json_encode([
        "error" => "Database error when querying ADL (current).",
        "sql_error" => adl_sql_error_message(),
        "sql" => $sql
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// 8) Collect rows and normalise DateTime columns
// ---------------------------------------------------------------------------

$rows = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    foreach ($row as $k => $v) {
        if ($v instanceof DateTimeInterface) {
            // Format as ISO8601 in UTC (assumes stored as UTC)
            $row[$k] = $v->format("Y-m-d\\TH:i:s\\Z");
        }
    }
    // Decode waypoints_json from SQL Server FOR JSON PATH (returns string)
    // so client receives a proper array instead of double-encoded string
    if (isset($row['waypoints_json']) && is_string($row['waypoints_json'])) {
        $decoded = json_decode($row['waypoints_json'], true);
        $row['waypoints_json'] = $decoded !== null ? $decoded : [];
    }
    $rows[] = $row;
}

sqlsrv_free_stmt($stmt);
sqlsrv_close($conn_adl);

// Derive a snapshot_utc value if possible
$snapshotUtc = gmdate("Y-m-d\\TH:i:s\\Z");
if (!empty($rows) && isset($rows[0]['snapshot_utc'])) {
    $val = $rows[0]['snapshot_utc'];
    if ($val instanceof DateTimeInterface) {
        $snapshotUtc = $val->format("Y-m-d\\TH:i:s\\Z");
    } elseif (is_string($val) && trim($val) !== "") {
        $snapshotUtc = $val;
    }
}

echo json_encode([
    "snapshot_utc" => $snapshotUtc,
    "flights"      => $rows
]);

?>
