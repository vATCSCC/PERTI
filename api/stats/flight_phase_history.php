<?php
/**
 * api/stats/flight_phase_history.php
 * Returns flight phase counts over time for stacked area chart
 *
 * Parameters:
 *   hours - Number of hours to return (default 24, max 48)
 *   interval - Aggregation interval in minutes (default 15)
 */

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once("../../load/config.php");

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
$interval = isset($_GET['interval']) ? (int)$_GET['interval'] : 15;

$hours = min(max($hours, 1), 48); // Max 48 hours
$interval = min(max($interval, 1), 60); // 1-60 minute intervals

// Query: get snapshots and aggregate by interval
// Using ROW_NUMBER to downsample to requested interval
$sql = "
    WITH intervals AS (
        SELECT
            snapshot_utc,
            prefile_cnt,
            taxiing_cnt,
            departed_cnt,
            enroute_cnt,
            descending_cnt,
            arrived_cnt,
            unknown_cnt,
            total_active,
            DATEADD(MINUTE,
                (DATEDIFF(MINUTE, '2000-01-01', snapshot_utc) / ?) * ?,
                '2000-01-01'
            ) AS bucket
        FROM dbo.flight_phase_snapshot
        WHERE snapshot_utc > DATEADD(HOUR, -?, SYSUTCDATETIME())
    ),
    aggregated AS (
        SELECT
            bucket,
            AVG(prefile_cnt) AS prefile,
            AVG(taxiing_cnt) AS taxiing,
            AVG(departed_cnt) AS departed,
            AVG(enroute_cnt) AS enroute,
            AVG(descending_cnt) AS descending,
            AVG(arrived_cnt) AS arrived,
            AVG(unknown_cnt) AS unknown,
            AVG(total_active) AS total
        FROM intervals
        GROUP BY bucket
    )
    SELECT * FROM aggregated
    ORDER BY bucket ASC
";

$params = [$interval, $interval, $hours];
$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt === false) {
    http_response_code(500);
    echo json_encode([
        "error" => "Query failed",
        "details" => sqlsrv_errors()
    ]);
    sqlsrv_close($conn);
    exit;
}

$labels = [];
$datasets = [
    'arrived' => [],
    'descending' => [],
    'enroute' => [],
    'departed' => [],
    'taxiing' => [],
    'prefile' => []
];

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $bucket = $row['bucket'];
    if ($bucket instanceof DateTime) {
        $labels[] = $bucket->format('H:i');
    } else {
        $labels[] = substr($bucket, 11, 5); // Extract HH:MM from datetime string
    }

    // Stack order (bottom to top): arrived, descending, enroute, departed, taxiing, prefile
    $datasets['arrived'][] = (int)$row['arrived'];
    $datasets['descending'][] = (int)$row['descending'];
    $datasets['enroute'][] = (int)$row['enroute'];
    $datasets['departed'][] = (int)$row['departed'];
    $datasets['taxiing'][] = (int)$row['taxiing'];
    $datasets['prefile'][] = (int)$row['prefile'];
}

sqlsrv_free_stmt($stmt);

// Get current time label for vertical line marker
$currentTimeLabel = gmdate('H:i');

// Get total snapshot count for debugging
$countSql = "SELECT COUNT(*) AS cnt FROM dbo.flight_phase_snapshot WHERE snapshot_utc > DATEADD(HOUR, -?, SYSUTCDATETIME())";
$countStmt = sqlsrv_query($conn, $countSql, [$hours]);
$snapshotCount = 0;
if ($countStmt) {
    $countRow = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
    $snapshotCount = $countRow['cnt'] ?? 0;
    sqlsrv_free_stmt($countStmt);
}

sqlsrv_close($conn);

echo json_encode([
    "success" => true,
    "timestamp_utc" => gmdate('Y-m-d\TH:i:s\Z'),
    "current_time_label" => $currentTimeLabel,
    "parameters" => [
        "hours" => $hours,
        "interval_minutes" => $interval
    ],
    "debug" => [
        "snapshot_count" => $snapshotCount,
        "bucket_count" => count($labels)
    ],
    "data" => [
        "labels" => $labels,
        "datasets" => $datasets
    ]
], JSON_PRETTY_PRINT);
