#!/usr/bin/env php
<?php
/**
 * Webhook Delivery Daemon
 *
 * Processes outbound events from swim_webhook_events queue.
 * Dispatches batches to registered webhook callback URLs with
 * HMAC-SHA256 signatures, retry with exponential backoff, and
 * circuit breaker protection.
 *
 * Runs on a 10-second cycle. Hibernation-aware: pauses delivery
 * during hibernation (events queue as pending, will drain on wake).
 *
 * Usage:
 *   php scripts/webhook_delivery_daemon.php --loop              # Continuous
 *   php scripts/webhook_delivery_daemon.php                     # Single run
 *   php scripts/webhook_delivery_daemon.php --loop --debug
 *   php scripts/webhook_delivery_daemon.php --loop --interval=15
 *
 * @package PERTI\Scripts
 */

set_time_limit(0);
ini_set('memory_limit', '128M');

// Parse command line arguments
$options = getopt('', ['loop', 'interval:', 'debug', 'once']);
$runLoop = isset($options['loop']);
$debug = isset($options['debug']);
$interval = isset($options['interval']) ? (int)$options['interval'] : 10;

// Enforce minimum interval
$interval = max(5, $interval);

// Load dependencies
$wwwroot = dirname(__DIR__);
require_once $wwwroot . '/load/config.php';
require_once $wwwroot . '/load/connect.php';
require_once $wwwroot . '/load/swim_config.php';
require_once $wwwroot . '/lib/webhooks/WebhookSender.php';
require_once $wwwroot . '/lib/connectors/CircuitBreaker.php';

use PERTI\Lib\Webhooks\WebhookSender;
use PERTI\Lib\Connectors\CircuitBreaker;

// ============================================================================
// Constants
// ============================================================================

define('WEBHOOK_PID_FILE', sys_get_temp_dir() . '/perti_webhook_delivery_daemon.pid');
define('WEBHOOK_HEARTBEAT_FILE', sys_get_temp_dir() . '/perti_webhook_delivery_daemon.heartbeat');

// ============================================================================
// Logging & Process Management (same pattern as swim_tmi_sync_daemon.php)
// ============================================================================

function wh_log(string $message, string $level = 'INFO'): void {
    $timestamp = gmdate('Y-m-d H:i:s');
    echo "[$timestamp UTC] [$level] $message\n";
}

function wh_write_heartbeat(string $status, array $extra = []): void {
    $payload = array_merge([
        'pid' => getmypid(),
        'status' => $status,
        'updated_utc' => gmdate('Y-m-d H:i:s'),
        'unix_ts' => time(),
    ], $extra);
    @file_put_contents(WEBHOOK_HEARTBEAT_FILE, json_encode($payload), LOCK_EX);
}

function wh_write_pid(): void {
    file_put_contents(WEBHOOK_PID_FILE, getmypid());
    register_shutdown_function(function () {
        @unlink(WEBHOOK_PID_FILE);
    });
}

function wh_check_existing_instance(): bool {
    if (!file_exists(WEBHOOK_PID_FILE)) return false;
    $pid = (int)file_get_contents(WEBHOOK_PID_FILE);
    if ($pid <= 0) return false;
    if (PHP_OS_FAMILY === 'Windows') {
        exec("tasklist /FI \"PID eq $pid\" 2>NUL", $output);
        return count($output) > 1;
    }
    return posix_kill($pid, 0);
}

// ============================================================================
// Startup
// ============================================================================

if (wh_check_existing_instance()) {
    wh_log("Another instance is already running. Exiting.", 'WARN');
    exit(1);
}

wh_write_pid();
register_shutdown_function(function () {
    @unlink(WEBHOOK_HEARTBEAT_FILE);
});
wh_write_heartbeat('starting');

wh_log("========================================");
wh_log("Webhook Delivery Daemon Starting");
wh_log("  Interval: {$interval}s");
wh_log("  Mode: " . ($runLoop ? 'daemon (continuous)' : 'single run'));
wh_log("  Debug: " . ($debug ? 'ON' : 'OFF'));
wh_log("  PID: " . getmypid());
wh_log("========================================");

// Check hibernation on startup
if (defined('HIBERNATION_MODE') && HIBERNATION_MODE) {
    wh_log("HIBERNATION: Outbound webhook delivery paused. Events will queue as pending.");
    if (!$runLoop) exit(0);
}

// ============================================================================
// Main Loop
// ============================================================================

$cycleCount = 0;
$totalDispatched = 0;
$totalFailed = 0;
$totalDead = 0;

do {
    $cycleStart = microtime(true);
    $cycleCount++;
    wh_write_heartbeat('running', ['cycle' => $cycleCount]);

    // Skip during hibernation (events queue, drain on wake)
    if (defined('HIBERNATION_MODE') && HIBERNATION_MODE) {
        if ($debug) wh_log("Hibernation active -- skipping cycle", 'DEBUG');
        wh_write_heartbeat('hibernating', ['cycle' => $cycleCount]);
        if ($runLoop) {
            sleep($interval);
            continue;
        }
        break;
    }

    // Get SWIM_API connection (lazy loaded)
    $conn = get_conn_swim();
    if (!$conn) {
        wh_log("SWIM_API connection unavailable, skipping cycle", 'ERROR');
        wh_write_heartbeat('error', ['cycle' => $cycleCount, 'error' => 'no_swim_conn']);
        if ($runLoop) {
            sleep($interval);
            continue;
        }
        exit(1);
    }

    // Initialize circuit breaker (reads state from file each cycle)
    $cb = new CircuitBreaker(
        sys_get_temp_dir() . '/perti_simtraffic_webhook_state.json',
        60,   // 60s rolling window
        6,    // 6 errors to trip
        180   // 3-minute cooldown
    );

    // Initialize sender (read config from swim_config.php)
    global $SWIM_WEBHOOK_CONFIG;
    $retryIntervals = $SWIM_WEBHOOK_CONFIG['retry_intervals'] ?? [10, 30, 90];
    $batchSize = $SWIM_WEBHOOK_CONFIG['batch_size'] ?? 50;
    $sender = new WebhookSender($conn, $cb, $retryIntervals, $batchSize, $debug);

    if ($debug) wh_log("Cycle #{$cycleCount}: Processing pending events...", 'DEBUG');

    try {
        $result = $sender->processPendingEvents();
    } catch (\Throwable $e) {
        wh_log("Exception in processPendingEvents: " . $e->getMessage(), 'ERROR');
        $result = ['dispatched' => 0, 'failed' => 0, 'dead' => 0, 'skipped_circuit' => false];
    }

    // Update lifetime counters
    $totalDispatched += $result['dispatched'];
    $totalFailed += $result['failed'];
    $totalDead += $result['dead'];

    // Log activity (always log if something happened, or debug mode)
    $hasActivity = $result['dispatched'] > 0 || $result['failed'] > 0 || $result['dead'] > 0;
    if ($debug || $hasActivity || $result['skipped_circuit']) {
        $msg = sprintf(
            "dispatched=%d failed=%d dead=%d circuit=%s",
            $result['dispatched'],
            $result['failed'],
            $result['dead'],
            $result['skipped_circuit'] ? 'OPEN' : 'closed'
        );
        wh_log($msg);
    }

    $cycleDuration = microtime(true) - $cycleStart;
    wh_write_heartbeat('idle', [
        'cycle' => $cycleCount,
        'cycle_ms' => (int)round($cycleDuration * 1000),
        'last_dispatched' => $result['dispatched'],
        'total_dispatched' => $totalDispatched,
        'total_failed' => $totalFailed,
    ]);

    // Periodic stats log every 60 cycles (~10 minutes at 10s interval)
    if ($cycleCount % 60 === 0) {
        wh_log(sprintf(
            "Stats after %d cycles: dispatched=%d failed=%d dead=%d",
            $cycleCount, $totalDispatched, $totalFailed, $totalDead
        ));
    }

    // Sleep until next cycle (with signal dispatch for graceful shutdown)
    if ($runLoop) {
        $sleepSeconds = max(1, (int)ceil($interval - $cycleDuration));

        if ($debug) {
            wh_log("Cycle completed in " . round($cycleDuration * 1000) . "ms, sleeping {$sleepSeconds}s", 'DEBUG');
        }

        $sleepRemaining = $sleepSeconds;
        while ($sleepRemaining > 0) {
            sleep(1);
            $sleepRemaining--;
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }

} while ($runLoop);

wh_log("Webhook Delivery Daemon exiting");
exit(0);
