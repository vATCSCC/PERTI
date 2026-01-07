<?php
/**
 * api/stats/status.php
 * Returns flight statistics job status and health
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

$helper = new StatsHelper($conn);

// Get job status
$jobs = $helper->getJobStatus();

// Get recent run log
$sql = "SELECT * FROM dbo.vw_flight_stats_recent_runs";
$stmt = sqlsrv_query($conn, $sql);

$runs = [];
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Format datetime objects
        foreach ($row as $key => $value) {
            if ($value instanceof DateTime) {
                $row[$key] = $value->format('Y-m-d\TH:i:s\Z');
            }
        }
        $runs[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

// Get retention tiers
$sql = "SELECT * FROM dbo.vw_flight_stats_retention_active";
$stmt = sqlsrv_query($conn, $sql);

$tiers = [];
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $tiers[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

// Get table counts
$tableCounts = [];
$tables = ['flight_stats_daily', 'flight_stats_hourly', 'flight_stats_airport',
           'flight_stats_citypair', 'flight_stats_artcc', 'flight_stats_tmi',
           'flight_stats_aircraft', 'flight_stats_monthly_summary'];

foreach ($tables as $table) {
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.$table";
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt !== false) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $tableCounts[$table] = (int)($row['cnt'] ?? 0);
        sqlsrv_free_stmt($stmt);
    }
}

sqlsrv_close($conn);

echo json_encode([
    "success" => true,
    "timestamp_utc" => gmdate('Y-m-d\TH:i:s\Z'),
    "jobs" => $jobs,
    "recent_runs" => array_slice($runs, 0, 20),
    "retention_tiers" => $tiers,
    "table_counts" => $tableCounts
]);
