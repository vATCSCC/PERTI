<?php
/**
 * Flight Phase Snapshot Diagnostic Endpoint
 * Checks the status of flight_phase_snapshot data
 */

header('Content-Type: application/json; charset=utf-8');

require_once(__DIR__ . '/../../load/config.php');

$connectionInfo = [
    "Database" => ADL_SQL_DATABASE,
    "UID"      => ADL_SQL_USERNAME,
    "PWD"      => ADL_SQL_PASSWORD
];

$conn = sqlsrv_connect(ADL_SQL_HOST, $connectionInfo);
if ($conn === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not connect to database']);
    exit;
}

$result = [
    'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'checks' => []
];

// 1. Check if table exists
$sql = "SELECT COUNT(*) AS cnt FROM sys.tables WHERE name = 'flight_phase_snapshot'";
$stmt = sqlsrv_query($conn, $sql);
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$result['checks']['table_exists'] = $row['cnt'] > 0;
sqlsrv_free_stmt($stmt);

// 2. Check if procedure exists
$sql = "SELECT COUNT(*) AS cnt FROM sys.procedures WHERE name = 'sp_CapturePhaseSnapshot'";
$stmt = sqlsrv_query($conn, $sql);
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$result['checks']['procedure_exists'] = $row['cnt'] > 0;
sqlsrv_free_stmt($stmt);

// 3. Get total snapshot count
$sql = "SELECT COUNT(*) AS cnt FROM dbo.flight_phase_snapshot";
$stmt = sqlsrv_query($conn, $sql);
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$result['total_snapshots'] = $row['cnt'];
sqlsrv_free_stmt($stmt);

// 4. Get snapshots in last 24 hours
$sql = "SELECT COUNT(*) AS cnt FROM dbo.flight_phase_snapshot WHERE snapshot_utc > DATEADD(HOUR, -24, SYSUTCDATETIME())";
$stmt = sqlsrv_query($conn, $sql);
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$result['snapshots_24h'] = $row['cnt'];
sqlsrv_free_stmt($stmt);

// 5. Get latest snapshot time
$sql = "SELECT TOP 1 snapshot_utc, total_active FROM dbo.flight_phase_snapshot ORDER BY snapshot_utc DESC";
$stmt = sqlsrv_query($conn, $sql);
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
if ($row) {
    $ts = $row['snapshot_utc'];
    if ($ts instanceof DateTime) {
        $result['latest_snapshot'] = $ts->format('Y-m-d H:i:s') . ' UTC';
        $result['latest_snapshot_iso'] = $ts->format('Y-m-d\TH:i:s\Z');

        // Calculate how old
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $diff = $now->getTimestamp() - $ts->getTimestamp();
        $result['latest_snapshot_age_minutes'] = round($diff / 60, 1);
    }
    $result['latest_total_active'] = $row['total_active'];
}
sqlsrv_free_stmt($stmt);

// 6. Get oldest snapshot time
$sql = "SELECT TOP 1 snapshot_utc FROM dbo.flight_phase_snapshot ORDER BY snapshot_utc ASC";
$stmt = sqlsrv_query($conn, $sql);
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
if ($row && $row['snapshot_utc'] instanceof DateTime) {
    $result['oldest_snapshot'] = $row['snapshot_utc']->format('Y-m-d H:i:s') . ' UTC';
}
sqlsrv_free_stmt($stmt);

// 7. Check for gaps - get snapshots grouped by hour
$sql = "
    SELECT
        DATEADD(HOUR, DATEDIFF(HOUR, 0, snapshot_utc), 0) AS hour_bucket,
        COUNT(*) AS snapshot_count,
        MIN(snapshot_utc) AS first_snapshot,
        MAX(snapshot_utc) AS last_snapshot
    FROM dbo.flight_phase_snapshot
    WHERE snapshot_utc > DATEADD(HOUR, -24, SYSUTCDATETIME())
    GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, snapshot_utc), 0)
    ORDER BY hour_bucket DESC
";
$stmt = sqlsrv_query($conn, $sql);
$hourly = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $bucket = $row['hour_bucket'];
    $first = $row['first_snapshot'];
    $last = $row['last_snapshot'];
    $hourly[] = [
        'hour' => ($bucket instanceof DateTime) ? $bucket->format('Y-m-d H:i') : $bucket,
        'count' => $row['snapshot_count'],
        'first' => ($first instanceof DateTime) ? $first->format('H:i:s') : $first,
        'last' => ($last instanceof DateTime) ? $last->format('H:i:s') : $last
    ];
}
$result['hourly_breakdown'] = $hourly;
sqlsrv_free_stmt($stmt);

// 8. Get last 10 snapshots
$sql = "SELECT TOP 10 snapshot_utc, prefile_cnt, taxiing_cnt, departed_cnt, enroute_cnt, descending_cnt, arrived_cnt, total_active
        FROM dbo.flight_phase_snapshot ORDER BY snapshot_utc DESC";
$stmt = sqlsrv_query($conn, $sql);
$recent = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $ts = $row['snapshot_utc'];
    $recent[] = [
        'time' => ($ts instanceof DateTime) ? $ts->format('Y-m-d H:i:s') : $ts,
        'prefile' => $row['prefile_cnt'],
        'taxiing' => $row['taxiing_cnt'],
        'departed' => $row['departed_cnt'],
        'enroute' => $row['enroute_cnt'],
        'descending' => $row['descending_cnt'],
        'arrived' => $row['arrived_cnt'],
        'total' => $row['total_active']
    ];
}
$result['recent_snapshots'] = $recent;
sqlsrv_free_stmt($stmt);

// 9. Check current active flight count (what snapshot should capture)
$sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_flight_core WHERE is_active = 1";
$stmt = sqlsrv_query($conn, $sql);
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$result['current_active_flights'] = $row['cnt'];
sqlsrv_free_stmt($stmt);

// 10. Check last refresh time (from flight_core)
$sql = "SELECT TOP 1 snapshot_utc FROM dbo.adl_flight_core ORDER BY snapshot_utc DESC";
$stmt = sqlsrv_query($conn, $sql);
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
if ($row && $row['snapshot_utc'] instanceof DateTime) {
    $ts = $row['snapshot_utc'];
    $result['last_vatsim_refresh'] = $ts->format('Y-m-d H:i:s') . ' UTC';

    $now = new DateTime('now', new DateTimeZone('UTC'));
    $diff = $now->getTimestamp() - $ts->getTimestamp();
    $result['vatsim_refresh_age_minutes'] = round($diff / 60, 1);
}
sqlsrv_free_stmt($stmt);

// 11. Try to manually capture a snapshot
$result['manual_capture'] = [];
$sql = "EXEC dbo.sp_CapturePhaseSnapshot";
$stmt = sqlsrv_query($conn, $sql);
if ($stmt === false) {
    $errors = sqlsrv_errors();
    $result['manual_capture']['success'] = false;
    $result['manual_capture']['errors'] = $errors;
} else {
    $result['manual_capture']['success'] = true;
    sqlsrv_free_stmt($stmt);

    // Check if it actually created a new snapshot
    $sql = "SELECT TOP 1 snapshot_utc FROM dbo.flight_phase_snapshot ORDER BY snapshot_utc DESC";
    $stmt2 = sqlsrv_query($conn, $sql);
    $row = sqlsrv_fetch_array($stmt2, SQLSRV_FETCH_ASSOC);
    if ($row && $row['snapshot_utc'] instanceof DateTime) {
        $result['manual_capture']['new_snapshot_time'] = $row['snapshot_utc']->format('Y-m-d H:i:s') . ' UTC';
    }
    sqlsrv_free_stmt($stmt2);
}

sqlsrv_close($conn);

echo json_encode($result, JSON_PRETTY_PRINT);
