<?php
/**
 * api/stats/realtime.php
 * Returns real-time flight statistics (live from flight tables)
 * No parameters - always returns current state
 */

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

require_once("../../load/config.php");
require_once(__DIR__ . '/../../load/perti_constants.php');
require_once(__DIR__ . '/StatsHelper.php');

header('Content-Type: application/json; charset=utf-8');
perti_set_cors();

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

$helper = new StatsHelper($conn);
$stats = $helper->getRealtimeStats();

sqlsrv_close($conn);

echo json_encode([
    "success" => !isset($stats['error']),
    "data" => $stats
]);
