<?php
/**
 * PERTI Events Sync Daemon
 *
 * Long-running daemon that syncs events from VATUSA, VATCAN, and VATSIM APIs
 * into the perti_events table for TMI compliance analysis and position logging.
 *
 * Runs every 6 hours by default.
 *
 * Usage:
 *   php event_sync_daemon.php --loop [--interval=21600]
 *
 * Options:
 *   --loop          Run continuously (required for daemon mode)
 *   --interval=N    Seconds between sync cycles (default: 21600 = 6 hours)
 *   --once          Run once and exit (for testing)
 *
 * Target Table: dbo.perti_events (VATSIM_ADL)
 *
 * @package PERTI\Scripts
 * @version 2.0.0
 * @since 2026-02-02 (migrated from division_events to perti_events)
 */

// Parse command line arguments
$options = getopt('', ['loop', 'interval::', 'once', 'help']);

if (isset($options['help'])) {
    echo "PERTI Events Sync Daemon\n";
    echo "========================\n";
    echo "Syncs VATUSA, VATCAN, and VATSIM events to perti_events table.\n\n";
    echo "Usage: php event_sync_daemon.php --loop [--interval=21600]\n\n";
    echo "Options:\n";
    echo "  --loop          Run continuously as daemon\n";
    echo "  --interval=N    Seconds between syncs (default: 21600 = 6h)\n";
    echo "  --once          Run once and exit\n";
    echo "  --help          Show this help\n";
    exit(0);
}

$loopMode = isset($options['loop']);
$onceMode = isset($options['once']);
$interval = isset($options['interval']) ? (int)$options['interval'] : 21600; // 6 hours default

if (!$loopMode && !$onceMode) {
    echo "Error: Must specify --loop or --once\n";
    echo "Run with --help for usage\n";
    exit(1);
}

// Include the sync functions (uses perti_events table)
require_once __DIR__ . '/sync_perti_events.php';

/**
 * Log message with timestamp
 */
function daemon_log(string $message): void
{
    $ts = gmdate('Y-m-d H:i:s');
    echo "[$ts UTC] $message\n";
}

/**
 * Run a single sync cycle
 */
function runSyncCycle(): array
{
    daemon_log("Starting perti_events sync cycle...");
    $startTime = microtime(true);

    $results = sync_perti_events('ALL');

    $elapsed = round(microtime(true) - $startTime, 2);

    if ($results['success']) {
        $summary = [];
        foreach ($results['by_source'] as $r) {
            $status = $r['errors'] > 0 ? '!' : '✓';
            $summary[] = "{$r['source']}:{$r['synced']}/{$r['fetched']}";
        }
        daemon_log("Sync complete in {$elapsed}s - " . implode(', ', $summary));

        if ($results['totals']['errors'] > 0) {
            daemon_log("WARNING: {$results['totals']['errors']} errors occurred");
        }
    } else {
        daemon_log("ERROR: {$results['error']}");
    }

    return $results;
}

// Main execution
daemon_log("=== PERTI Events Sync Daemon ===");
daemon_log("Target: dbo.perti_events (VATSIM_ADL)");
daemon_log("Mode: " . ($loopMode ? "daemon (interval: {$interval}s)" : "single run"));

if ($onceMode) {
    // Single run mode
    $results = runSyncCycle();
    exit($results['success'] ? 0 : 1);
}

// Daemon loop mode
daemon_log("Starting daemon loop...");

// Run immediately on startup
runSyncCycle();

while (true) {
    daemon_log("Sleeping for {$interval}s until next sync...");
    sleep($interval);
    runSyncCycle();
}
