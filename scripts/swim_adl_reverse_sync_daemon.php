<?php
/**
 * SWIM -> ADL Reverse Sync Daemon
 *
 * Continuous loop wrapper for swim_adl_reverse_sync.php.
 * Propagates SimTraffic flight times from SWIM back to ADL normalized tables.
 *
 * Usage:
 *   php swim_adl_reverse_sync_daemon.php [--loop] [--interval=120] [--debug]
 *
 * Options:
 *   --loop       Run continuously (default: single run)
 *   --interval=N Seconds between sync cycles (default: 120)
 *   --debug      Enable verbose logging
 *
 * @package PERTI
 * @subpackage SWIM
 * @version 1.0.0
 * @since 2026-01-27
 */

// Load the reverse sync functions
require_once __DIR__ . '/swim_adl_reverse_sync.php';

// Parse command line arguments
$loop = in_array('--loop', $argv);
$debug = in_array('--debug', $argv);
$interval = 120;

foreach ($argv as $arg) {
    if (preg_match('/^--interval=(\d+)$/', $arg, $m)) {
        $interval = intval($m[1]);
    }
}

echo "=== SWIM -> ADL Reverse Sync Daemon ===\n";
echo "Started: " . date('Y-m-d H:i:s T') . "\n";
echo "Mode: " . ($loop ? "Continuous (every {$interval}s)" : "Single run") . "\n";
echo "Debug: " . ($debug ? "ON" : "OFF") . "\n";
echo "========================================\n\n";

$cycle = 0;

do {
    $cycle++;
    $startTime = microtime(true);

    if ($debug || $cycle === 1) {
        echo "[" . date('Y-m-d H:i:s') . "] Cycle $cycle: Starting reverse sync...\n";
    }

    try {
        $result = swim_adl_reverse_sync();

        if ($debug || !$result['success']) {
            echo "[" . date('Y-m-d H:i:s') . "] " .
                 ($result['success'] ? "OK" : "FAILED") . ": " .
                 $result['message'] . "\n";
        }

        // Log stats periodically (every 10 cycles or on error)
        if ($debug || $cycle % 10 === 0 || !$result['success']) {
            echo "  Stats: " . json_encode($result['stats']) . "\n";
        }

    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
    }

    if ($loop) {
        $elapsed = microtime(true) - $startTime;
        $sleepTime = max(1, $interval - $elapsed);

        if ($debug) {
            echo "  Sleeping for " . round($sleepTime) . " seconds...\n";
        }

        sleep((int)$sleepTime);
    }

} while ($loop);

echo "\n[" . date('Y-m-d H:i:s') . "] Daemon exiting.\n";
