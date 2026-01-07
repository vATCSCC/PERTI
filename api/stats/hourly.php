<?php
/**
 * api/stats/hourly.php
 * Returns hourly flight statistics time-series
 *
 * Parameters:
 *   hours - Number of hours to return (default 24)
 *   start - Start datetime (ISO 8601), optional
 *   end   - End datetime (ISO 8601), optional
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
$hours = isset($_GET['hours']) ? (int)$_GET['hours'] : 24;
$start = isset($_GET['start']) ? $_GET['start'] : null;
$end = isset($_GET['end']) ? $_GET['end'] : null;

$hours = min(max($hours, 1), 720); // Max 30 days of hourly data

$helper = new StatsHelper($conn);
$stats = $helper->getHourlyStats($hours, $start, $end);

sqlsrv_close($conn);

echo json_encode([
    "success" => !isset($stats['error']),
    "timestamp_utc" => gmdate('Y-m-d\TH:i:s\Z'),
    "parameters" => [
        "hours" => $hours,
        "start" => $start,
        "end" => $end
    ],
    "count" => is_array($stats) && !isset($stats['error']) ? count($stats) : 0,
    "data" => $stats
]);
