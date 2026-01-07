<?php
/**
 * api/stats/citypair.php
 * Returns city-pair route analytics
 *
 * Parameters:
 *   origin - Origin ICAO code, optional
 *   dest   - Destination ICAO code, optional
 *   date   - Specific date (YYYY-MM-DD), optional
 *   days   - Number of days to return (default 7)
 *   limit  - Max records (default 100)
 */

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once("../../load/config.php");
require_once(__DIR__ . '/StatsHelper.php');

if (!defined("ADL_SQL_HOST") || !defined("ADL_SQL_DATABASE") ||
    !defined("ADL_SQL_USERNAME") || !defined("ADL_SQL_PASSWORD")) {
    http_response_code(500);
    echo json_encode(["error" => "ADL_SQL_* constants are not defined."]);
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
    echo json_encode(["error" => "Unable to connect to ADL database."]);
    exit;
}

// Parse parameters
$origin = isset($_GET['origin']) ? strtoupper(trim($_GET['origin'])) : null;
$dest = isset($_GET['dest']) ? strtoupper(trim($_GET['dest'])) : null;
$date = isset($_GET['date']) ? $_GET['date'] : null;
$days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;

// Validate ICAO codes
if ($origin !== null && !preg_match('/^[A-Z]{4}$/', $origin)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid origin ICAO code."]);
    sqlsrv_close($conn);
    exit;
}

if ($dest !== null && !preg_match('/^[A-Z]{4}$/', $dest)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid destination ICAO code."]);
    sqlsrv_close($conn);
    exit;
}

if ($date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid date format. Use YYYY-MM-DD."]);
    sqlsrv_close($conn);
    exit;
}

$days = min(max($days, 1), 90);
$limit = min(max($limit, 1), 1000);

$helper = new StatsHelper($conn);
$stats = $helper->getCitypairStats($origin, $dest, $date, $days, $limit);

sqlsrv_close($conn);

echo json_encode([
    "success" => !isset($stats['error']),
    "timestamp_utc" => gmdate('Y-m-d\TH:i:s\Z'),
    "parameters" => [
        "origin" => $origin,
        "dest" => $dest,
        "date" => $date,
        "days" => $days,
        "limit" => $limit
    ],
    "count" => is_array($stats) && !isset($stats['error']) ? count($stats) : 0,
    "data" => $stats
]);
