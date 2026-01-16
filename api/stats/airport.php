<?php
/**
 * api/stats/airport.php
 * Returns airport performance statistics (taxi times, throughput)
 *
 * Parameters:
 *   icao  - Airport ICAO code (e.g., KJFK), optional
 *   date  - Specific date (YYYY-MM-DD), optional
 *   days  - Number of days to return (default 7)
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
$icao = isset($_GET['icao']) ? get_upper('icao') : null;
$date = isset($_GET['date']) ? $_GET['date'] : null;
$days = isset($_GET['days']) ? (int)$_GET['days'] : 7;

// Validate ICAO
if ($icao !== null && !preg_match('/^[A-Z]{4}$/', $icao)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid ICAO code. Must be 4 letters."]);
    sqlsrv_close($conn);
    exit;
}

// Validate date format
if ($date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid date format. Use YYYY-MM-DD."]);
    sqlsrv_close($conn);
    exit;
}

$days = min(max($days, 1), 90);

$helper = new StatsHelper($conn);
$stats = $helper->getAirportStats($icao, $date, $days);

sqlsrv_close($conn);

echo json_encode([
    "success" => !isset($stats['error']),
    "timestamp_utc" => gmdate('Y-m-d\TH:i:s\Z'),
    "parameters" => [
        "icao" => $icao,
        "date" => $date,
        "days" => $days
    ],
    "count" => is_array($stats) && !isset($stats['error']) ? count($stats) : 0,
    "data" => $stats
]);
