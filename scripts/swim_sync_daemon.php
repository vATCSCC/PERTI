<?php
/**
 * VATSWIM Sync Daemon
 *
 * Long-running daemon that syncs flight data from VATSIM_ADL to SWIM_API database.
 * Runs delta sync every 2 minutes for optimal performance on Azure SQL Basic.
 *
 * Also runs cleanup every 6 hours to remove stale data per retention policies.
 *
 * Usage:
 *   php swim_sync_daemon.php [--loop] [--sync-interval=120] [--cleanup-interval=21600]
 *
 * Options:
 *   --loop              Run continuously (daemon mode)
 *   --sync-interval=N   Seconds between sync cycles (default: 120 = 2 minutes)
 *   --cleanup-interval=N Seconds between cleanup cycles (default: 21600 = 6 hours)
 *   --debug             Enable debug output
 *
 * @package PERTI
 * @subpackage SWIM
 * @version 1.0.0
 */

// Parse command line arguments
$options = getopt('', ['loop', 'sync-interval:', 'cleanup-interval:', 'debug']);
$runLoop = isset($options['loop']);
$syncInterval = isset($options['sync-interval']) ? (int)$options['sync-interval'] : 120;
$cleanupInterval = isset($options['cleanup-interval']) ? (int)$options['cleanup-interval'] : 21600;
$debug = isset($options['debug']);

// Ensure minimum intervals
$syncInterval = max(30, $syncInterval);
$cleanupInterval = max(3600, $cleanupInterval);

// Load dependencies
define('PERTI_LOADED', true);
require_once __DIR__ . '/../load/config.php';
require_once __DIR__ . '/../load/connect.php';
require_once __DIR__ . '/swim_sync.php';
require_once __DIR__ . '/swim_cleanup.php';

/**
 * Log message with timestamp
 */
function swim_log($message, $level = 'INFO') {
    $timestamp = gmdate('Y-m-d H:i:s');
    echo "[$timestamp UTC] [$level] $message\n";
}

/**
 * Write PID file for process management
 */
function swim_write_pid($pidFile) {
    file_put_contents($pidFile, getmypid());
    register_shutdown_function(function() use ($pidFile) {
        if (file_exists($pidFile)) {
            unlink($pidFile);
        }
    });
}

/**
 * Check if another instance is running
 */
function swim_check_existing_instance($pidFile) {
    if (!file_exists($pidFile)) {
        return false;
    }

    $pid = (int)file_get_contents($pidFile);
    if ($pid <= 0) {
        return false;
    }

    // Check if process is running (cross-platform)
    if (PHP_OS_FAMILY === 'Windows') {
        exec("tasklist /FI \"PID eq $pid\" 2>NUL", $output);
        return count($output) > 1;
    } else {
        return posix_kill($pid, 0);
    }
}

// ============================================================================
// Main Daemon Logic
// ============================================================================

$pidFile = sys_get_temp_dir() . '/swim_sync_daemon.pid';

// Check for existing instance
if (swim_check_existing_instance($pidFile)) {
    swim_log("Another instance is already running. Exiting.", 'WARN');
    exit(1);
}

// Write PID file
swim_write_pid($pidFile);

swim_log("========================================");
swim_log("VATSWIM Sync Daemon Starting");
swim_log("  Sync interval: {$syncInterval}s (" . round($syncInterval / 60, 1) . " min)");
swim_log("  Cleanup interval: {$cleanupInterval}s (" . round($cleanupInterval / 3600, 1) . " hours)");
swim_log("  Mode: " . ($runLoop ? 'daemon (continuous)' : 'single run'));
swim_log("  PID: " . getmypid());
swim_log("========================================");

// Track last cleanup time
$lastCleanupTime = 0;
$cycleCount = 0;

do {
    $cycleStart = microtime(true);
    $cycleCount++;

    swim_log("--- Sync cycle #$cycleCount starting ---");

    // ========================================================================
    // Run Sync
    // ========================================================================
    try {
        $result = swim_sync_from_adl();

        if ($result['success']) {
            swim_log($result['message']);
            if ($debug && !empty($result['stats'])) {
                swim_log("  Stats: " . json_encode($result['stats']), 'DEBUG');
            }
        } else {
            swim_log($result['message'], 'ERROR');
        }
    } catch (Exception $e) {
        swim_log("Sync exception: " . $e->getMessage(), 'ERROR');
    }

    // ========================================================================
    // Run Cleanup (if due)
    // ========================================================================
    $timeSinceCleanup = time() - $lastCleanupTime;
    if ($timeSinceCleanup >= $cleanupInterval) {
        swim_log("Running scheduled cleanup (last: " . round($timeSinceCleanup / 3600, 1) . " hours ago)");

        try {
            $cleanupResult = swim_cleanup_stale_data();

            if ($cleanupResult['success']) {
                swim_log("Cleanup: " . $cleanupResult['message']);
            } else {
                swim_log("Cleanup failed: " . $cleanupResult['message'], 'ERROR');
            }

            $lastCleanupTime = time();
        } catch (Exception $e) {
            swim_log("Cleanup exception: " . $e->getMessage(), 'ERROR');
        }
    }

    // ========================================================================
    // Sleep until next cycle
    // ========================================================================
    if ($runLoop) {
        $cycleDuration = microtime(true) - $cycleStart;
        $sleepTime = max(1, $syncInterval - $cycleDuration);

        if ($debug) {
            swim_log("Cycle completed in " . round($cycleDuration * 1000) . "ms, sleeping {$sleepTime}s", 'DEBUG');
        }

        // Sleep in 1-second increments to allow graceful shutdown
        $sleepRemaining = $sleepTime;
        while ($sleepRemaining > 0) {
            sleep(min(1, $sleepRemaining));
            $sleepRemaining--;

            // Check for shutdown signals
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }

} while ($runLoop);

swim_log("VATSWIM Sync Daemon exiting");
