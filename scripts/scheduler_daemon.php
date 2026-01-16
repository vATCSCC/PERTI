<?php
/**
 * PERTI Scheduler Daemon
 *
 * Runs continuously, checking the scheduler_state table to determine
 * when to execute the scheduler. Uses the tiered system:
 *   - Tier 0: Check every 1 min (transition imminent)
 *   - Tier 1: Check every 5 min (transition soon)
 *   - Tier 2: Check every 15 min (transition upcoming)
 *   - Tier 3: Check every 60 min (idle)
 *
 * Usage:
 *   php scheduler_daemon.php [--interval=60]
 *
 * Options:
 *   --interval=N   Base check interval in seconds (default: 60)
 *                  The daemon checks if it's time to run every N seconds
 */

// Parse command line arguments
$options = getopt('', ['interval::']);
$checkInterval = isset($options['interval']) ? (int)$options['interval'] : 60;

// Ensure minimum interval
if ($checkInterval < 10) $checkInterval = 10;

echo "[SCHEDULER DAEMON] Starting...\n";
echo "[SCHEDULER DAEMON] Check interval: {$checkInterval}s\n";
echo "[SCHEDULER DAEMON] PID: " . getmypid() . "\n";

// Change to the web root directory
$wwwroot = getenv('WWWROOT') ?: dirname(__DIR__);
chdir($wwwroot);

echo "[SCHEDULER DAEMON] Working directory: " . getcwd() . "\n";

// Include the database connection
require_once __DIR__ . '/../api/splits/connect_adl.php';

if (!$conn_adl) {
    echo "[SCHEDULER DAEMON] ERROR: Database connection failed\n";
    exit(1);
}

echo "[SCHEDULER DAEMON] Database connected\n";
echo "[SCHEDULER DAEMON] Entering main loop...\n";
echo "========================================\n";

$runCount = 0;
$lastRunTime = null;

while (true) {
    $now = gmdate('Y-m-d H:i:s');

    // Check if it's time to run
    $sql = "SELECT
                next_run_at,
                last_run_at,
                last_tier,
                CASE WHEN next_run_at <= GETUTCDATE() THEN 1 ELSE 0 END AS should_run,
                DATEDIFF(SECOND, GETUTCDATE(), next_run_at) AS seconds_until
            FROM scheduler_state
            WHERE id = 1";

    $stmt = sqlsrv_query($conn_adl, $sql);

    if ($stmt === false) {
        echo "[{$now}] ERROR: Failed to query scheduler_state\n";
        sleep($checkInterval);
        continue;
    }

    $state = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$state) {
        echo "[{$now}] No scheduler state found, initializing...\n";
        $sql = "INSERT INTO scheduler_state (id, next_run_at, last_tier) VALUES (1, GETUTCDATE(), 3)";
        sqlsrv_query($conn_adl, $sql);
        sleep($checkInterval);
        continue;
    }

    $shouldRun = (int)$state['should_run'] === 1;
    $secondsUntil = (int)$state['seconds_until'];
    $tier = (int)$state['last_tier'];

    if ($shouldRun) {
        $runCount++;
        echo "[{$now}] EXECUTING scheduler (run #{$runCount})...\n";

        // Execute the scheduler by including it
        ob_start();
        $_GET['force'] = '1'; // Force execution
        include __DIR__ . '/../api/scheduler.php';
        $output = ob_get_clean();

        // Parse the JSON response
        $result = json_decode($output, true);

        if ($result) {
            $activated = $result['totals']['activated'] ?? 0;
            $deactivated = $result['totals']['deactivated'] ?? 0;
            $newTier = $result['tier']['current'] ?? 3;
            $nextMin = $result['tier']['next_run_minutes'] ?? 60;

            echo "[{$now}] Completed: {$activated} activated, {$deactivated} deactivated\n";
            echo "[{$now}] Next tier: {$newTier}, next run in {$nextMin} min\n";
        } else {
            echo "[{$now}] WARNING: Could not parse scheduler response\n";
        }

        $lastRunTime = $now;
    } else {
        // Not time yet - just log occasionally
        if ($runCount === 0 || $secondsUntil % 300 < $checkInterval) {
            echo "[{$now}] Waiting... next run in {$secondsUntil}s (tier {$tier})\n";
        }
    }

    // Sleep for the check interval
    sleep($checkInterval);
}
