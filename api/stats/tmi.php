<?php
/**
 * api/stats/tmi.php
 * Returns TMI (Traffic Management Initiative) impact statistics
 *
 * Parameters:
 *   type    - TMI type (GS, GDP, AFP, REROUTE, etc.), optional
 *   airport - Target airport ICAO code, optional
 *   date    - Specific date (YYYY-MM-DD), optional
 *   days    - Number of days to return (default 7)
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
$type = isset($_GET['type']) ? strtoupper(trim($_GET['type'])) : null;
$airport = isset($_GET['airport']) ? strtoupper(trim($_GET['airport'])) : null;
$date = isset($_GET['date']) ? $_GET['date'] : null;
$days = isset($_GET['days']) ? (int)$_GET['days'] : 7;

// Validate airport code
if ($airport !== null && !preg_match('/^[A-Z]{4}$/', $airport)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid airport ICAO code."]);
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

$helper = new StatsHelper($conn);
$stats = $helper->getTmiStats($type, $airport, $date, $days);

sqlsrv_close($conn);

echo json_encode([
    "success" => !isset($stats['error']),
    "timestamp_utc" => gmdate('Y-m-d\TH:i:s\Z'),
    "parameters" => [
        "type" => $type,
        "airport" => $airport,
        "date" => $date,
        "days" => $days
    ],
    "count" => is_array($stats) && !isset($stats['error']) ? count($stats) : 0,
    "data" => $stats
]);
