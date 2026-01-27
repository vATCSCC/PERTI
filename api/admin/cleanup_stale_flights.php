<?php
/**
 * Emergency Stale Flight Cleanup (Optimized)
 *
 * Marks flights as inactive if not synced in the last 5 minutes.
 * Uses batched updates to avoid lock escalation and transaction log bloat.
 *
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

$startTime = microtime(true);
$batchSize = 10000;  // Process 10k rows per batch to avoid lock escalation
$totalMarked = 0;
$batches = 0;

$results = [
    'success' => true,
    'timestamp' => gmdate('c'),
    'batches' => [],
];

// Batched update loop - keeps running until no more stale flights
do {
    // Update in batches using TOP to limit rows per transaction
    // This prevents lock escalation and keeps transaction log small
    $sql = "
        SET NOCOUNT ON;
        UPDATE TOP ($batchSize) dbo.swim_flights
        SET is_active = 0
        WHERE is_active = 1
          AND last_sync_utc < DATEADD(MINUTE, -5, GETUTCDATE())
    ";

    $stmt = sqlsrv_query($conn_swim, $sql);
    if ($stmt === false) {
        $err = sqlsrv_errors()[0] ?? ['message' => 'Unknown error'];
        $results['success'] = false;
        $results['error'] = $err['message'];
        $results['total_marked'] = $totalMarked;
        $results['batches_completed'] = $batches;
        echo json_encode($results, JSON_PRETTY_PRINT);
        exit;
    }

    $rowsAffected = sqlsrv_rows_affected($stmt);
    sqlsrv_free_stmt($stmt);

    if ($rowsAffected > 0) {
        $batches++;
        $totalMarked += $rowsAffected;
        $results['batches'][] = [
            'batch' => $batches,
            'rows' => $rowsAffected,
            'cumulative' => $totalMarked,
        ];
    }

    // Safety limit - max 50 batches (500k rows) to prevent runaway
    if ($batches >= 50) {
        $results['warning'] = 'Hit safety limit of 50 batches';
        break;
    }

} while ($rowsAffected === $batchSize);  // Continue if we hit the batch limit

// Get final counts (quick query using index on is_active)
$sql = "SELECT COUNT(*) as cnt FROM dbo.swim_flights WITH (NOLOCK) WHERE is_active = 1";
$stmt = sqlsrv_query($conn_swim, $sql);
if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $results['active_flights_remaining'] = (int)$row['cnt'];
    sqlsrv_free_stmt($stmt);
}

$duration = round((microtime(true) - $startTime) * 1000);

$results['total_marked'] = $totalMarked;
$results['batches_completed'] = $batches;
$results['duration_ms'] = $duration;
$results['message'] = sprintf(
    'Marked %d stale flights as inactive in %d batches (%dms). %d active flights remaining.',
    $totalMarked,
    $batches,
    $duration,
    $results['active_flights_remaining'] ?? 0
);

// Compact output - remove batch details if more than 5 batches
if ($batches > 5) {
    unset($results['batches']);
    $results['batches_summary'] = sprintf('%d batches of %d rows each', $batches, $batchSize);
}

echo json_encode($results, JSON_PRETTY_PRINT);
