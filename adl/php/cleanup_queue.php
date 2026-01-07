<?php
/**
 * Parse Queue Cleanup Script
 *
 * Runs sp_CleanupParseQueue to clear orphaned/stale entries.
 *
 * Usage:
 *   php cleanup_queue.php          # Dry run (preview)
 *   php cleanup_queue.php --run    # Actually clean up
 */

require_once __DIR__ . '/../../load/connect.php';

// Check connection
if (!isset($conn_adl) || $conn_adl === null || $conn_adl === false) {
    echo "ERROR: Could not connect to VATSIM_ADL database.\n";
    exit(1);
}

echo "Connected to VATSIM_ADL database.\n\n";

// Check if --run flag is passed
$dryRun = !in_array('--run', $argv ?? []);

if ($dryRun) {
    echo "=== DRY RUN MODE ===\n";
    echo "This will show what WOULD be deleted.\n";
    echo "Run with --run flag to actually delete.\n\n";
} else {
    echo "=== EXECUTING CLEANUP ===\n\n";
}

// First, get current queue stats
$sql = "SELECT
    COUNT(*) AS total,
    COUNT(CASE WHEN status = 'PENDING' THEN 1 END) AS pending,
    COUNT(CASE WHEN status = 'PROCESSING' THEN 1 END) AS processing,
    COUNT(CASE WHEN status = 'COMPLETE' THEN 1 END) AS complete,
    COUNT(CASE WHEN status = 'FAILED' THEN 1 END) AS failed
FROM dbo.adl_parse_queue";

$stmt = sqlsrv_query($conn_adl, $sql);
if ($stmt) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    echo "Current Queue Status:\n";
    echo "  Total:      " . number_format($row['total']) . "\n";
    echo "  Pending:    " . number_format($row['pending']) . "\n";
    echo "  Processing: " . number_format($row['processing']) . "\n";
    echo "  Complete:   " . number_format($row['complete']) . "\n";
    echo "  Failed:     " . number_format($row['failed']) . "\n\n";
    sqlsrv_free_stmt($stmt);
}

// Check if procedure exists
$sql = "SELECT COUNT(*) AS cnt FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.sp_CleanupParseQueue') AND type = 'P'";
$stmt = sqlsrv_query($conn_adl, $sql);
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($stmt);

if ($row['cnt'] == 0) {
    echo "Procedure sp_CleanupParseQueue not found.\n";
    echo "Running inline cleanup instead...\n\n";

    // Run inline cleanup
    $now = gmdate('Y-m-d H:i:s');

    // 1. Count orphaned entries (inactive flights)
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_parse_queue q
            LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = q.flight_uid
            WHERE q.status = 'PENDING' AND (c.flight_uid IS NULL OR c.is_active = 0)";
    $stmt = sqlsrv_query($conn_adl, $sql);
    $orphaned = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)['cnt'];
    sqlsrv_free_stmt($stmt);
    echo "Orphaned entries (inactive flights): " . number_format($orphaned) . "\n";

    // 2. Count stale entries (> 2 hours old)
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_parse_queue
            WHERE status = 'PENDING' AND queued_utc < DATEADD(HOUR, -2, SYSUTCDATETIME())";
    $stmt = sqlsrv_query($conn_adl, $sql);
    $stale = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)['cnt'];
    sqlsrv_free_stmt($stmt);
    echo "Stale entries (> 2 hours):           " . number_format($stale) . "\n";

    // 3. Count old failed entries
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_parse_queue
            WHERE status = 'FAILED' AND queued_utc < DATEADD(HOUR, -4, SYSUTCDATETIME())";
    $stmt = sqlsrv_query($conn_adl, $sql);
    $failed = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)['cnt'];
    sqlsrv_free_stmt($stmt);
    echo "Old failed entries (> 4 hours):      " . number_format($failed) . "\n";

    // 4. Count stuck processing
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_parse_queue
            WHERE status = 'PROCESSING' AND started_utc < DATEADD(MINUTE, -30, SYSUTCDATETIME())";
    $stmt = sqlsrv_query($conn_adl, $sql);
    $stuck = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)['cnt'];
    sqlsrv_free_stmt($stmt);
    echo "Stuck processing (> 30 min):         " . number_format($stuck) . "\n";

    $total = $orphaned + $stale + $failed + $stuck;
    echo "\nTotal to clean up: " . number_format($total) . "\n\n";

    if (!$dryRun && $total > 0) {
        echo "Cleaning up...\n";

        // Delete orphaned
        $sql = "DELETE q FROM dbo.adl_parse_queue q
                LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = q.flight_uid
                WHERE q.status = 'PENDING' AND (c.flight_uid IS NULL OR c.is_active = 0)";
        sqlsrv_query($conn_adl, $sql);
        echo "  Deleted orphaned entries.\n";

        // Delete stale
        $sql = "DELETE FROM dbo.adl_parse_queue
                WHERE status = 'PENDING' AND queued_utc < DATEADD(HOUR, -2, SYSUTCDATETIME())";
        sqlsrv_query($conn_adl, $sql);
        echo "  Deleted stale entries.\n";

        // Delete old failed
        $sql = "DELETE FROM dbo.adl_parse_queue
                WHERE status = 'FAILED' AND queued_utc < DATEADD(HOUR, -4, SYSUTCDATETIME())";
        sqlsrv_query($conn_adl, $sql);
        echo "  Deleted old failed entries.\n";

        // Reset stuck
        $sql = "UPDATE dbo.adl_parse_queue SET status = 'PENDING', started_utc = NULL
                WHERE status = 'PROCESSING' AND started_utc < DATEADD(MINUTE, -30, SYSUTCDATETIME())";
        sqlsrv_query($conn_adl, $sql);
        echo "  Reset stuck processing entries.\n";

        echo "\nCleanup complete!\n";
    }
} else {
    // Run the stored procedure
    $sql = "EXEC dbo.sp_CleanupParseQueue @dry_run = ?";
    $params = [$dryRun ? 1 : 0];

    $stmt = sqlsrv_query($conn_adl, $sql, $params);
    if ($stmt === false) {
        echo "ERROR: " . print_r(sqlsrv_errors(), true) . "\n";
        exit(1);
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($row) {
        echo "Cleanup Results:\n";
        echo "  Deleted (inactive flights): " . number_format($row['deleted_inactive_flights'] ?? 0) . "\n";
        echo "  Deleted (stale pending):    " . number_format($row['deleted_stale_pending'] ?? 0) . "\n";
        echo "  Deleted (old failed):       " . number_format($row['deleted_old_failed'] ?? 0) . "\n";
        echo "  Deleted (stuck processing): " . number_format($row['deleted_stuck_processing'] ?? 0) . "\n";
        echo "  Total cleaned:              " . number_format($row['total_cleaned'] ?? 0) . "\n";
        echo "  Remaining pending:          " . number_format($row['remaining_pending'] ?? 0) . "\n";
    }
    sqlsrv_free_stmt($stmt);
}

// Final stats
echo "\n";
$sql = "SELECT
    COUNT(*) AS total,
    COUNT(CASE WHEN status = 'PENDING' THEN 1 END) AS pending
FROM dbo.adl_parse_queue";
$stmt = sqlsrv_query($conn_adl, $sql);
if ($stmt) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    echo "Final Queue Status:\n";
    echo "  Total:   " . number_format($row['total']) . "\n";
    echo "  Pending: " . number_format($row['pending']) . "\n";
    sqlsrv_free_stmt($stmt);
}

sqlsrv_close($conn_adl);
echo "\nDone.\n";
