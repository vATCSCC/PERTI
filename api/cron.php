<?php
/**
 * PERTI Unified Scheduler Cron Entry Point
 *
 * Lightweight endpoint for cron to call frequently (e.g., every minute).
 * Only executes the full scheduler if it's actually time to run.
 *
 * Handles all scheduled resources: Splits, Routes, Initiatives
 *
 * Usage:
 *   Cron: * * * * * curl -s https://perti.vatcscc.org/api/cron.php > /dev/null
 *   Or Windows Task Scheduler running every minute
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/splits/connect_adl.php';

if (!$conn_adl) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

// Quick check: is it time to run?
$sql = "SELECT
            CASE WHEN next_run_at <= GETUTCDATE() THEN 1 ELSE 0 END AS should_run,
            DATEDIFF(SECOND, GETUTCDATE(), next_run_at) AS seconds_until
        FROM scheduler_state
        WHERE id = 1";

$stmt = sqlsrv_query($conn_adl, $sql);

if ($stmt === false) {
    // Table might not exist yet - run scheduler to initialize
    include __DIR__ . '/scheduler.php';
    exit;
}

$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($stmt);

if (!$row) {
    // No state row - run scheduler to initialize
    include __DIR__ . '/scheduler.php';
    exit;
}

$shouldRun = (int)$row['should_run'] === 1;
$secondsUntil = (int)$row['seconds_until'];

if ($shouldRun) {
    // Time to run - execute the unified scheduler
    include __DIR__ . '/scheduler.php';
} else {
    // Not time yet - return minimal response
    echo json_encode([
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'executed' => false,
        'seconds_until_next' => max(0, $secondsUntil)
    ]);
}
