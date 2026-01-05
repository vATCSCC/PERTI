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
// Introspect columns
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

$idCol        = adl_lookup_column($columnsLower, ['id']);
$callsignCol  = adl_lookup_column($columnsLower, ['callsign']);
$isActiveCol  = adl_lookup_column($columnsLower, ['is_active']);
$lastSeenCol  = adl_lookup_column($columnsLower, ['last_seen_utc']);
$snapshotCol  = adl_lookup_column($columnsLower, ['snapshot_utc']);

$sql = "SELECT TOP 1 * FROM adl_flights";
$where = [];
$params = [];

if ($id > 0) {
    if ($idCol === null) {
        http_response_code(500);
        echo json_encode([
            "error" => "ADL table does not have an 'id' column; cannot look up by id."
        ]);
        exit;
    }
    $where[] = $idCol . " = ?";
    $params[] = $id;
} else {
    if ($callsignCol === null) {
        http_response_code(500);
        echo json_encode([
            "error" => "ADL table does not have a 'callsign' column; cannot look up by callsign."
        ]);
        exit;
    }
    $where[] = $callsignCol . " = ?";
    $params[] = $callsign;
}

// Optional active filter, if requested
if ($isActiveCol !== null &&
    ($activeParam === '1' || $activeParam === 'true' || $activeParam === 'yes')) {

    $where[] = $isActiveCol . " = 1";
}

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

// If multiple rows exist for a callsign, prefer active and most recent
$orderParts = [];
if ($isActiveCol !== null) {
    $orderParts[] = $isActiveCol . " DESC";
}
if ($lastSeenCol !== null) {
    $orderParts[] = $lastSeenCol . " DESC";
}
if ($snapshotCol !== null) {
    $orderParts[] = $snapshotCol . " DESC";
}
if ($callsignCol !== null) {
    $orderParts[] = $callsignCol . " ASC";
}

if (!empty($orderParts)) {
    $orderParts = array_values(array_unique($orderParts));
    $sql .= " ORDER BY " . implode(", ", $orderParts);
}

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
