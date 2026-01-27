<?php
/**
 * Emergency Stale Flight Cleanup
 *
 * Marks flights as inactive if not synced in the last 5 minutes.
 * Run via: https://perti.vatcscc.org/api/admin/cleanup_stale_flights.php?key=swim_sys_vatcscc_internal_001
 */

require_once __DIR__ . '/../../load/config.php';
require_once __DIR__ . '/../../load/connect.php';

header('Content-Type: application/json');

// Auth check
$authorized = false;
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
    if ($matches[1] === 'swim_sys_vatcscc_internal_001') {
        $authorized = true;
    }
}
if (!$authorized && isset($_GET['key']) && $_GET['key'] === 'swim_sys_vatcscc_internal_001') {
    $authorized = true;
}

if (!$authorized) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

global $conn_swim;
if (!$conn_swim) {
    echo json_encode(['success' => false, 'error' => 'SWIM database connection not available']);
    exit;
}

$results = [
    'success' => true,
    'timestamp' => gmdate('c'),
    'before' => [],
    'after' => [],
];

// Count before
$sql = "SELECT COUNT(*) as cnt FROM dbo.swim_flights WHERE is_active = 1";
$stmt = sqlsrv_query($conn_swim, $sql);
if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $results['before']['active_count'] = (int)$row['cnt'];
    sqlsrv_free_stmt($stmt);
}

$sql = "SELECT COUNT(*) as cnt FROM dbo.swim_flights WHERE is_active = 1 AND last_sync_utc < DATEADD(MINUTE, -5, GETUTCDATE())";
$stmt = sqlsrv_query($conn_swim, $sql);
if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $results['before']['stale_count'] = (int)$row['cnt'];
    sqlsrv_free_stmt($stmt);
}

// Mark stale flights as inactive
$sql = "
    UPDATE dbo.swim_flights
    SET is_active = 0
    WHERE is_active = 1
      AND last_sync_utc < DATEADD(MINUTE, -5, GETUTCDATE())
";
$stmt = sqlsrv_query($conn_swim, $sql);
if ($stmt === false) {
    $err = sqlsrv_errors()[0] ?? ['message' => 'Unknown error'];
    $results['success'] = false;
    $results['error'] = $err['message'];
    echo json_encode($results, JSON_PRETTY_PRINT);
    exit;
}

$results['marked_inactive'] = sqlsrv_rows_affected($stmt);
sqlsrv_free_stmt($stmt);

// Count after
$sql = "SELECT COUNT(*) as cnt FROM dbo.swim_flights WHERE is_active = 1";
$stmt = sqlsrv_query($conn_swim, $sql);
if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $results['after']['active_count'] = (int)$row['cnt'];
    sqlsrv_free_stmt($stmt);
}

$results['message'] = sprintf(
    'Marked %d stale flights as inactive. Active flights: %d -> %d',
    $results['marked_inactive'],
    $results['before']['active_count'],
    $results['after']['active_count']
);

echo json_encode($results, JSON_PRETTY_PRINT);
