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
// 3) Introspect adl_flights columns
// ---------------------------------------------------------------------------

$columnsLower = [];

$colSql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'adl_flights'";
$colStmt = sqlsrv_query($conn_adl, $colSql);
if ($colStmt === false) {
    http_response_code(500);
    echo json_encode([
        "error" => "Unable to read ADL schema (INFORMATION_SCHEMA query failed).",
        "sql_error" => adl_sql_error_message(),
        "sql" => $colSql
    ]);
    exit;
}

while ($col = sqlsrv_fetch_array($colStmt, SQLSRV_FETCH_ASSOC)) {
    if (!isset($col['COLUMN_NAME'])) {
        continue;
    }
    $field = $col['COLUMN_NAME'];
    $columnsLower[strtolower($field)] = $field;
}

sqlsrv_free_stmt($colStmt);

if (empty($columnsLower)) {
    http_response_code(500);
    echo json_encode([
        "error" => "ADL table adl_flights has no columns or could not be read."
    ]);
    exit;
}

function adl_lookup_column($columnsLower, $candidateNames) {
    foreach ($candidateNames as $name) {
        $key = strtolower($name);
        if (isset($columnsLower[$key])) {
            return $columnsLower[$key];
        }
    }
    return null;
}

// ---------------------------------------------------------------------------
// 4) Input parameters
// ---------------------------------------------------------------------------

$callsign = '';
if (isset($_GET['cs'])) {
    $callsign = strtoupper(trim($_GET['cs']));
} elseif (isset($_GET['callsign'])) {
    $callsign = strtoupper(trim($_GET['callsign']));
}

$dep = isset($_GET['dep']) ? strtoupper(trim($_GET['dep'])) : '';
$arr = isset($_GET['arr']) ? strtoupper(trim($_GET['arr'])) : '';

// active flag: default is only active flights
$activeParam = isset($_GET['active']) ? strtolower(trim($_GET['active'])) : '1';

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10000;
if ($limit <= 0) {
    $limit = 10000;
} elseif ($limit > 15000) {
    $limit = 15000;
}

// ---------------------------------------------------------------------------
// 5) Build WHERE clause & ORDER BY
// ---------------------------------------------------------------------------

$where = [];
$params = [];

// is_active filter by default (if column exists)
$isActiveCol = adl_lookup_column($columnsLower, ['is_active']);
if ($isActiveCol !== null &&
    $activeParam !== 'all' &&
    $activeParam !== '0' &&
    $activeParam !== 'false' &&
    $activeParam !== 'no') {

    $where[] = $isActiveCol . " = 1";
}

if ($callsign !== '') {
    $callsignCol = adl_lookup_column($columnsLower, ['callsign']);
    if ($callsignCol !== null) {
        $where[] = $callsignCol . " = ?";
        $params[] = $callsign;
    }
}

// Determine which columns to use for dep/arr filters
$depField = adl_lookup_column($columnsLower, ['fp_dept_icao', 'dep_icao', 'dep', 'departure', 'origin']);
$arrField = adl_lookup_column($columnsLower, ['fp_dest_icao', 'arr_icao', 'arr', 'arrival', 'destination']);

if ($dep !== '' && $depField !== null) {
    $where[] = $depField . " = ?";
    $params[] = $dep;
}

if ($arr !== '' && $arrField !== null) {
    $where[] = $arrField . " = ?";
    $params[] = $arr;
}

// ORDER BY: prefer ETA-like columns, then arrival buckets, then last_seen, then callsign
$orderParts = [];

$etaCol = adl_lookup_column($columnsLower, ['eta_epoch', 'eta_best_epoch', 'eta_best_utc', 'eta_utc']);
if ($etaCol !== null) {
    $orderParts[] = $etaCol . " ASC";
}

$arrBucketCol = adl_lookup_column($columnsLower, ['arrival_bucket_utc']);
if ($arrBucketCol !== null) {
    $orderParts[] = $arrBucketCol . " ASC";
}

$estArrCol = adl_lookup_column($columnsLower, ['estimated_arr_utc']);
if ($estArrCol !== null) {
    $orderParts[] = $estArrCol . " ASC";
}

$lastSeenCol = adl_lookup_column($columnsLower, ['last_seen_utc']);
if ($lastSeenCol !== null) {
    $orderParts[] = $lastSeenCol . " ASC";
}

$callsignCol = adl_lookup_column($columnsLower, ['callsign']);
if ($callsignCol !== null) {
    $orderParts[] = $callsignCol . " ASC";
}

if (!empty($orderParts)) {
    $orderParts = array_values(array_unique($orderParts));
}

// ---------------------------------------------------------------------------
// 6) Build SQL
// ---------------------------------------------------------------------------

$sql = "SELECT TOP {$limit} * FROM adl_flights";

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

if (!empty($orderParts)) {
    $sql .= " ORDER BY " . implode(", ", $orderParts);
}

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
    $rows[] = $row;
}

sqlsrv_free_stmt($stmt);
sqlsrv_close($conn_adl);

// Derive a snapshot_utc value if possible
$snapshotUtc = gmdate("Y-m-d\\TH:i:s\\Z");
$snapshotColName = adl_lookup_column($columnsLower, ['snapshot_utc']);
if ($snapshotColName !== null && !empty($rows) && isset($rows[0][$snapshotColName])) {
    $val = $rows[0][$snapshotColName];
    if ($val instanceof DateTimeInterface) {
        $snapshotUtc = $val->format("Y-m-d\\TH:i:s\\Z");
    } elseif (is_string($val) && trim($val) !== "") {
        $snapshotUtc = $val;
    }
}

echo json_encode([
    "snapshot_utc" => $snapshotUtc,
    "flights"      => $rows,
    "rows"         => $rows
]);

?>
