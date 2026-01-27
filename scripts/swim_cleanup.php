<?php
/**
 * VATSWIM Data Retention Cleanup
 *
 * Removes stale data according to retention policies defined in swim_config.php.
 * Should be run via scheduled task/cron job (recommended: every 6 hours).
 *
 * Retention policies:
 * - Active flights: 1 day (inactive flights removed after 24 hours)
 * - Positions: 7 days
 * - Telemetry: 1 day
 * - TMI history: 365 days
 * - Audit log: 90 days
 *
 * @package PERTI
 * @subpackage SWIM
 * @version 1.0.0
 */

// Can be run standalone or included
if (!defined('PERTI_LOADED')) {
    define('PERTI_LOADED', true);
    require_once __DIR__ . '/../load/config.php';
    require_once __DIR__ . '/../load/connect.php';
}

/**
 * Main cleanup function
 *
 * @return array ['success' => bool, 'message' => string, 'stats' => array]
 */
function swim_cleanup_stale_data() {
    global $conn_swim;

    $stats = [
        'start_time' => microtime(true),
        'flights_marked_inactive' => 0,
        'flights_deleted' => 0,
        'audit_deleted' => 0,
        'subscriptions_deleted' => 0,
        'cache_deleted' => 0,
        'errors' => [],
        'duration_ms' => 0
    ];

    // Check connection
    if (!$conn_swim) {
        return ['success' => false, 'message' => 'SWIM_API connection not available', 'stats' => $stats];
    }

    try {
        // ========================================================================
        // 0. Mark flights inactive if not synced in the last 5 minutes
        // This matches the ADL behavior (sp_Adl_RefreshFromVatsim uses 5 min threshold)
        // Critical: delta sync only queries active flights from ADL,
        // so it never sees flights that went inactive. We mark them here based on staleness.
        // ========================================================================
        $sql = "
            UPDATE dbo.swim_flights
            SET is_active = 0
            WHERE is_active = 1
              AND last_sync_utc < DATEADD(MINUTE, -5, GETUTCDATE())
        ";
        $result = @sqlsrv_query($conn_swim, $sql);
        if ($result !== false) {
            $stats['flights_marked_inactive'] = sqlsrv_rows_affected($result);
            sqlsrv_free_stmt($result);
        } else {
            $stats['errors'][] = 'Failed to mark stale flights inactive: ' . swim_get_last_error();
        }

        // ========================================================================
        // 1. Delete inactive flights older than 24 hours
        // ========================================================================
        $sql = "
            DELETE FROM dbo.swim_flights
            WHERE is_active = 0
              AND last_sync_utc < DATEADD(HOUR, -24, GETUTCDATE())
        ";
        $result = @sqlsrv_query($conn_swim, $sql);
        if ($result !== false) {
            $stats['flights_deleted'] = sqlsrv_rows_affected($result);
            sqlsrv_free_stmt($result);
        } else {
            $stats['errors'][] = 'Failed to cleanup flights: ' . swim_get_last_error();
        }

        // ========================================================================
        // 2. Delete audit log entries older than 90 days
        // ========================================================================
        $sql = "
            DELETE FROM dbo.swim_audit_log
            WHERE created_at < DATEADD(DAY, -90, GETUTCDATE())
        ";
        $result = @sqlsrv_query($conn_swim, $sql);
        if ($result !== false) {
            $stats['audit_deleted'] = sqlsrv_rows_affected($result);
            sqlsrv_free_stmt($result);
        } else {
            $stats['errors'][] = 'Failed to cleanup audit log: ' . swim_get_last_error();
        }

        // ========================================================================
        // 3. Delete stale WebSocket subscriptions (disconnected > 1 hour ago)
        // ========================================================================
        $sql = "
            DELETE FROM dbo.swim_subscriptions
            WHERE disconnected_at IS NOT NULL
              AND disconnected_at < DATEADD(HOUR, -1, GETUTCDATE())
        ";
        $result = @sqlsrv_query($conn_swim, $sql);
        if ($result !== false) {
            $stats['subscriptions_deleted'] = sqlsrv_rows_affected($result);
            sqlsrv_free_stmt($result);
        } else {
            // Table might not exist yet - not an error
            $error = swim_get_last_error();
            if (strpos($error, 'Invalid object name') === false) {
                $stats['errors'][] = 'Failed to cleanup subscriptions: ' . $error;
            }
        }

        // ========================================================================
        // 4. Delete stale flight cache entries older than 24 hours
        // ========================================================================
        $sql = "
            DELETE FROM dbo.swim_flight_cache
            WHERE updated_at < DATEADD(HOUR, -24, GETUTCDATE())
        ";
        $result = @sqlsrv_query($conn_swim, $sql);
        if ($result !== false) {
            $stats['cache_deleted'] = sqlsrv_rows_affected($result);
            sqlsrv_free_stmt($result);
        } else {
            // Table might not exist yet - not an error
            $error = swim_get_last_error();
            if (strpos($error, 'Invalid object name') === false) {
                $stats['errors'][] = 'Failed to cleanup cache: ' . $error;
            }
        }

        // ========================================================================
        // 5. Cleanup webhook delivery attempts older than 7 days
        // ========================================================================
        $sql = "
            DELETE FROM dbo.swim_webhook_deliveries
            WHERE created_at < DATEADD(DAY, -7, GETUTCDATE())
        ";
        $result = @sqlsrv_query($conn_swim, $sql);
        if ($result !== false) {
            $stats['webhook_deliveries_deleted'] = sqlsrv_rows_affected($result);
            sqlsrv_free_stmt($result);
        } else {
            // Table might not exist yet - not an error
        }

        $stats['duration_ms'] = round((microtime(true) - $stats['start_time']) * 1000);

        $totalDeleted = $stats['flights_deleted'] + $stats['audit_deleted'] +
                        $stats['subscriptions_deleted'] + $stats['cache_deleted'];

        $success = count($stats['errors']) === 0;
        $message = sprintf(
            'Cleanup completed: %d flights marked inactive, %d flights deleted, %d audit, %d subscriptions, %d cache deleted in %dms',
            $stats['flights_marked_inactive'],
            $stats['flights_deleted'],
            $stats['audit_deleted'],
            $stats['subscriptions_deleted'],
            $stats['cache_deleted'],
            $stats['duration_ms']
        );

        if (!$success) {
            $message .= ' (with ' . count($stats['errors']) . ' errors)';
        }

        return ['success' => $success, 'message' => $message, 'stats' => $stats];

    } catch (Exception $e) {
        $stats['duration_ms'] = round((microtime(true) - $stats['start_time']) * 1000);
        $stats['errors'][] = $e->getMessage();
        return ['success' => false, 'message' => 'Cleanup error: ' . $e->getMessage(), 'stats' => $stats];
    }
}

/**
 * Get table sizes for monitoring
 *
 * @return array Table size information
 */
function swim_get_table_sizes() {
    global $conn_swim;

    if (!$conn_swim) {
        return ['error' => 'No connection'];
    }

    $sql = "
        SELECT
            t.name AS table_name,
            p.rows AS row_count,
            SUM(a.total_pages) * 8 AS total_space_kb,
            SUM(a.used_pages) * 8 AS used_space_kb
        FROM sys.tables t
        INNER JOIN sys.indexes i ON t.object_id = i.object_id
        INNER JOIN sys.partitions p ON i.object_id = p.object_id AND i.index_id = p.index_id
        INNER JOIN sys.allocation_units a ON p.partition_id = a.container_id
        WHERE t.name LIKE 'swim_%'
        GROUP BY t.name, p.rows
        ORDER BY SUM(a.total_pages) DESC
    ";

    $result = @sqlsrv_query($conn_swim, $sql);
    if ($result === false) {
        return ['error' => swim_get_last_error()];
    }

    $tables = [];
    while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
        $tables[] = [
            'table' => $row['table_name'],
            'rows' => (int)$row['row_count'],
            'size_kb' => (int)$row['total_space_kb'],
            'size_mb' => round($row['total_space_kb'] / 1024, 2)
        ];
    }
    sqlsrv_free_stmt($result);

    return $tables;
}

/**
 * Get last SQL Server error
 */
function swim_get_last_error() {
    $errors = sqlsrv_errors();
    if ($errors && count($errors) > 0) {
        return $errors[0]['message'] ?? 'Unknown error';
    }
    return 'Unknown error';
}

// ============================================================================
// Run if executed directly (CLI or via cron)
// ============================================================================

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($argv[0] ?? '')) {
    echo "VATSWIM Data Retention Cleanup - Starting...\n";
    echo "Timestamp: " . gmdate('Y-m-d H:i:s') . " UTC\n\n";

    // Get table sizes before cleanup
    echo "Table sizes before cleanup:\n";
    $sizes = swim_get_table_sizes();
    if (isset($sizes['error'])) {
        echo "  Error: " . $sizes['error'] . "\n";
    } else {
        foreach ($sizes as $table) {
            printf("  %-30s %10d rows  %8.2f MB\n",
                $table['table'], $table['rows'], $table['size_mb']);
        }
    }
    echo "\n";

    // Run cleanup
    $result = swim_cleanup_stale_data();
    echo $result['message'] . "\n";

    if (!empty($result['stats']['errors'])) {
        echo "\nErrors encountered:\n";
        foreach ($result['stats']['errors'] as $error) {
            echo "  - $error\n";
        }
    }

    // Get table sizes after cleanup
    echo "\nTable sizes after cleanup:\n";
    $sizes = swim_get_table_sizes();
    if (!isset($sizes['error'])) {
        foreach ($sizes as $table) {
            printf("  %-30s %10d rows  %8.2f MB\n",
                $table['table'], $table['rows'], $table['size_mb']);
        }
    }

    exit($result['success'] ? 0 : 1);
}
