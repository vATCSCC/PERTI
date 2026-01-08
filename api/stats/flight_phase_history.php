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

$labels = [];        // ISO timestamps for time axis
$displayLabels = []; // Human-readable labels (dd/HHmmZ)
$datasets = [
    'arrived' => [],
    'descending' => [],
    'enroute' => [],
    'departed' => [],
    'taxiing' => [],
    'prefile' => [],
    'unknown' => []
];

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $bucket = $row['bucket'];
    if ($bucket instanceof DateTime) {
        // ISO 8601 format for Chart.js time axis
        $labels[] = $bucket->format('Y-m-d\TH:i:s\Z');
        $displayLabels[] = $bucket->format('d/Hi') . 'Z';
    } else {
        // Parse from string: YYYY-MM-DD HH:MM:SS
        $labels[] = str_replace(' ', 'T', $bucket) . 'Z';
        $day = substr($bucket, 8, 2);
        $hour = substr($bucket, 11, 2);
        $minute = substr($bucket, 14, 2);
        $displayLabels[] = $day . '/' . $hour . $minute . 'Z';
    }

    // Stack order (bottom to top): arrived, descending, enroute, departed, taxiing, prefile, unknown
    $datasets['arrived'][] = (int)$row['arrived'];
    $datasets['descending'][] = (int)$row['descending'];
    $datasets['enroute'][] = (int)$row['enroute'];
    $datasets['departed'][] = (int)$row['departed'];
    $datasets['taxiing'][] = (int)$row['taxiing'];
    $datasets['prefile'][] = (int)$row['prefile'];
    $datasets['unknown'][] = (int)$row['unknown'];
}

sqlsrv_free_stmt($stmt);

// Get current time label for vertical line marker (dd/hhmmZ format)
$currentTimeLabel = gmdate('d/Hi') . 'Z';

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

// Calculate summary statistics for each phase
function calcStats($arr) {
    if (empty($arr)) return ['min' => 0, 'max' => 0, 'avg' => 0, 'median' => 0, 'sum' => 0];
    sort($arr);
    $count = count($arr);
    $sum = array_sum($arr);
    $median = $count % 2 === 0
        ? ($arr[$count/2 - 1] + $arr[$count/2]) / 2
        : $arr[floor($count/2)];
    return [
        'min' => min($arr),
        'max' => max($arr),
        'avg' => round($sum / $count, 1),
        'median' => round($median, 1),
        'sum' => $sum
    ];
}

// Calculate total active (sum of all phases except prefile for each time point)
$totalActive = [];
for ($i = 0; $i < count($datasets['arrived']); $i++) {
    $totalActive[] = $datasets['arrived'][$i] + $datasets['descending'][$i] +
                     $datasets['enroute'][$i] + $datasets['departed'][$i] +
                     $datasets['taxiing'][$i];
}

$summary = [
    'prefile' => calcStats($datasets['prefile']),
    'taxiing' => calcStats($datasets['taxiing']),
    'departed' => calcStats($datasets['departed']),
    'enroute' => calcStats($datasets['enroute']),
    'descending' => calcStats($datasets['descending']),
    'arrived' => calcStats($datasets['arrived']),
    'unknown' => calcStats($datasets['unknown']),
    'total_active' => calcStats($totalActive)
];

echo json_encode([
    "success" => true,
    "timestamp_utc" => gmdate('Y-m-d\TH:i:s\Z'),
    "current_time_iso" => gmdate('Y-m-d\TH:i:s\Z'),
    "parameters" => [
        "hours" => $hours,
        "interval_minutes" => $interval
    ],
    "debug" => [
        "snapshot_count" => $snapshotCount,
        "bucket_count" => count($labels)
    ],
    "data" => [
        "labels" => $labels,              // ISO timestamps for time axis
        "display_labels" => $displayLabels, // Human-readable dd/HHmmZ
        "datasets" => $datasets
    ],
    "summary" => $summary
], JSON_PRETTY_PRINT);
