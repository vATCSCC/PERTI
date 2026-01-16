<?php
/**
 * Parse Queue Cleanup API Endpoint
 *
 * GET  /api/adl/cleanup-queue.php         - Preview (dry run)
 * POST /api/adl/cleanup-queue.php         - Execute cleanup
 * GET  /api/adl/cleanup-queue.php?run=1   - Execute cleanup (alternative)
 */

header('Content-Type: application/json; charset=utf-8');

// Load config and connection
require_once(__DIR__ . '/../../load/config.php');
require_once(__DIR__ . '/../../load/connect.php');

// Check ADL connection
if (!isset($conn_adl) || $conn_adl === null || $conn_adl === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not connect to VATSIM_ADL database']);
    exit;
}

// Determine if this is a dry run or actual execution
$dryRun = true;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' || isset($_GET['run'])) {
    $dryRun = false;
}

$result = [
    'dry_run' => $dryRun,
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    'before' => [],
    'cleanup' => [],
    'after' => []
];

// Get current queue stats
$sql = "SELECT
    COUNT(*) AS total,
    COUNT(CASE WHEN status = 'PENDING' THEN 1 END) AS pending,
    COUNT(CASE WHEN status = 'PROCESSING' THEN 1 END) AS processing,
    COUNT(CASE WHEN status = 'COMPLETE' THEN 1 END) AS complete,
    COUNT(CASE WHEN status = 'FAILED' THEN 1 END) AS failed
FROM dbo.adl_parse_queue";

$stmt = sqlsrv_query($conn_adl, $sql);
if ($stmt) {
    $result['before'] = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
}

// Count what would be cleaned
$cleanup = [
    'orphaned_inactive' => 0,
    'stale_pending' => 0,
    'old_failed' => 0,
    'stuck_processing' => 0,
    'total' => 0
];

// 1. Orphaned entries (inactive flights)
$sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_parse_queue q
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = q.flight_uid
        WHERE q.status = 'PENDING' AND (c.flight_uid IS NULL OR c.is_active = 0)";
$stmt = sqlsrv_query($conn_adl, $sql);
if ($stmt) {
    $cleanup['orphaned_inactive'] = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)['cnt'];
    sqlsrv_free_stmt($stmt);
}

// 2. Stale entries (> 2 hours old)
$sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_parse_queue
        WHERE status = 'PENDING' AND queued_utc < DATEADD(HOUR, -2, SYSUTCDATETIME())";
$stmt = sqlsrv_query($conn_adl, $sql);
if ($stmt) {
    $cleanup['stale_pending'] = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)['cnt'];
    sqlsrv_free_stmt($stmt);
}

// 3. Old failed entries (> 4 hours)
$sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_parse_queue
        WHERE status = 'FAILED' AND queued_utc < DATEADD(HOUR, -4, SYSUTCDATETIME())";
$stmt = sqlsrv_query($conn_adl, $sql);
if ($stmt) {
    $cleanup['old_failed'] = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)['cnt'];
    sqlsrv_free_stmt($stmt);
}

// 4. Stuck processing (> 30 min)
$sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_parse_queue
        WHERE status = 'PROCESSING' AND started_utc < DATEADD(MINUTE, -30, SYSUTCDATETIME())";
$stmt = sqlsrv_query($conn_adl, $sql);
if ($stmt) {
    $cleanup['stuck_processing'] = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)['cnt'];
    sqlsrv_free_stmt($stmt);
}

$cleanup['total'] = $cleanup['orphaned_inactive'] + $cleanup['stale_pending'] +
                    $cleanup['old_failed'] + $cleanup['stuck_processing'];

$result['cleanup'] = $cleanup;

// Execute cleanup if not dry run
if (!$dryRun && $cleanup['total'] > 0) {
    $deleted = [
        'orphaned_inactive' => 0,
        'stale_pending' => 0,
        'old_failed' => 0,
        'stuck_processing' => 0
    ];

    // Delete orphaned
    $sql = "DELETE q FROM dbo.adl_parse_queue q
            LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = q.flight_uid
            WHERE q.status = 'PENDING' AND (c.flight_uid IS NULL OR c.is_active = 0)";
    $stmt = sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $deleted['orphaned_inactive'] = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);
    }

    // Delete stale
    $sql = "DELETE FROM dbo.adl_parse_queue
            WHERE status = 'PENDING' AND queued_utc < DATEADD(HOUR, -2, SYSUTCDATETIME())";
    $stmt = sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $deleted['stale_pending'] = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);
    }

    // Delete old failed
    $sql = "DELETE FROM dbo.adl_parse_queue
            WHERE status = 'FAILED' AND queued_utc < DATEADD(HOUR, -4, SYSUTCDATETIME())";
    $stmt = sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $deleted['old_failed'] = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);
    }

    // Reset stuck processing
    $sql = "UPDATE dbo.adl_parse_queue SET status = 'PENDING', started_utc = NULL
            WHERE status = 'PROCESSING' AND started_utc < DATEADD(MINUTE, -30, SYSUTCDATETIME())";
    $stmt = sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $deleted['stuck_processing'] = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);
    }

    $deleted['total'] = $deleted['orphaned_inactive'] + $deleted['stale_pending'] +
                        $deleted['old_failed'] + $deleted['stuck_processing'];

    $result['deleted'] = $deleted;
}

// Get final stats
$sql = "SELECT
    COUNT(*) AS total,
    COUNT(CASE WHEN status = 'PENDING' THEN 1 END) AS pending,
    COUNT(CASE WHEN status = 'PROCESSING' THEN 1 END) AS processing,
    COUNT(CASE WHEN status = 'COMPLETE' THEN 1 END) AS complete,
    COUNT(CASE WHEN status = 'FAILED' THEN 1 END) AS failed
FROM dbo.adl_parse_queue";

$stmt = sqlsrv_query($conn_adl, $sql);
if ($stmt) {
    $result['after'] = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
}

sqlsrv_close($conn_adl);

echo json_encode($result, JSON_PRETTY_PRINT);
