#!/usr/bin/env php
<?php
/**
 * PERTI Archival Daemon
 *
 * Location: wwwroot/scripts/archival_daemon.php
 *
 * Runs trajectory and changelog archival jobs on a configurable schedule.
 * Default: runs every 60 minutes during low-traffic hours (04:00-10:00 UTC),
 * or every 4 hours during peak times.
 *
 * Archival flow:
 *   1. sp_Archive_CompletedFlights - Archive completed flight records
 *   2. sp_Archive_Trajectory_ToWarm - Move >24h trajectories to warm tier
 *   3. sp_Downsample_Trajectory_ToCold - Compress >7d warm to cold tier
 *   4. sp_Purge_OldData - Delete data past retention periods
 *
 * Concurrency Protection:
 *   - PHP-level: PID file prevents multiple daemon instances
 *
 * Usage:
 *   php scripts/archival_daemon.php                    # Run in foreground
 *   php scripts/archival_daemon.php --once             # Run once and exit
 *   php scripts/archival_daemon.php --interval=120     # Custom interval (minutes)
 *   nohup php scripts/archival_daemon.php &            # Run detached
 */

declare(strict_types=1);
error_reporting(E_ALL);
set_time_limit(0);
ini_set('memory_limit', '256M');

// ============================================================================
// PARSE ARGUMENTS
// ============================================================================

$options = getopt('', ['once', 'interval::', 'batch-size::', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
PERTI Archival Daemon

Usage: php archival_daemon.php [OPTIONS]

Options:
  --once           Run archival once and exit
  --interval=N     Check interval in minutes (default: 60 during off-peak, 240 peak)
  --batch-size=N   Batch size for archival operations (default: 50000)
  --help           Show this help message

The daemon automatically adjusts its schedule based on time of day:
  - 04:00-10:00 UTC: Runs every 60 minutes (off-peak)
  - Other times: Runs every 4 hours (to avoid impacting live operations)

HELP;
    exit(0);
}

$runOnce = isset($options['once']);
$customInterval = isset($options['interval']) ? (int)$options['interval'] : null;
$batchSize = isset($options['batch-size']) ? (int)$options['batch-size'] : 50000;

// ============================================================================
// LOAD PERTI CONFIG
// ============================================================================

$scriptDir = __DIR__;
$wwwroot = dirname($scriptDir);
$configPath = $wwwroot . '/load/config.php';

if (!file_exists($configPath)) {
    die("ERROR: Cannot find config at {$configPath}\n");
}

require_once $configPath;

// Verify ADL constants exist
if (!defined('ADL_SQL_HOST') || !defined('ADL_SQL_DATABASE')) {
    die("ERROR: ADL_SQL_* constants not defined in config.php\n");
}

// ============================================================================
// CONFIGURATION
// ============================================================================

$config = [
    'db_server'   => ADL_SQL_HOST,
    'db_name'     => ADL_SQL_DATABASE,
    'db_user'     => defined('ADL_SQL_USERNAME') ? ADL_SQL_USERNAME : 'adl_api_user',
    'db_pass'     => defined('ADL_SQL_PASSWORD') ? ADL_SQL_PASSWORD : '',

    // Default intervals (minutes)
    'off_peak_interval' => 60,   // 04:00-10:00 UTC
    'peak_interval'     => 240,  // Other times (4 hours)
    'off_peak_start'    => 4,    // 04:00 UTC
    'off_peak_end'      => 10,   // 10:00 UTC

    // Batch sizes
    'batch_size'        => $batchSize,

    // Logging
    'log_file'    => file_exists('/home/LogFiles')
                     ? '/home/LogFiles/archival.log'
                     : $scriptDir . '/archival.log',
];

// ============================================================================
// LOGGING
// ============================================================================

function logMsg(string $msg, string $level = 'INFO'): void {
    global $config;
    $timestamp = gmdate('Y-m-d H:i:s');
    $line = "[{$timestamp}] [{$level}] {$msg}\n";

    echo $line;

    if (!empty($config['log_file'])) {
        @file_put_contents($config['log_file'], $line, FILE_APPEND | LOCK_EX);
    }
}

// ============================================================================
// DATABASE CONNECTION
// ============================================================================

function connectDb(): mixed {
    global $config;

    $serverName = "tcp:{$config['db_server']},1433";
    $connectionOptions = [
        "Database" => $config['db_name'],
        "Uid" => $config['db_user'],
        "PWD" => $config['db_pass'],
        "Encrypt" => true,
        "TrustServerCertificate" => false,
        "LoginTimeout" => 30,
        "ConnectionPooling" => false,
    ];

    $conn = sqlsrv_connect($serverName, $connectionOptions);

    if ($conn === false) {
        $errors = sqlsrv_errors();
        logMsg("Database connection failed: " . json_encode($errors), 'ERROR');
        return null;
    }

    return $conn;
}

// ============================================================================
// ARCHIVAL EXECUTION
// ============================================================================

function runArchival(mixed $conn, int $batchSize): array {
    $results = [
        'started_utc' => gmdate('Y-m-d H:i:s'),
        'steps' => [],
        'success' => true,
        'error' => null,
    ];

    // Step 1: Archive completed flights
    logMsg("Step 1/4: Archiving completed flights...");
    $stepResult = runStep($conn, 'EXEC dbo.sp_Archive_CompletedFlights @debug = 0');
    $results['steps']['completed_flights'] = $stepResult;

    // Step 2: Move trajectory to warm tier
    logMsg("Step 2/4: Moving trajectory to warm tier (batch_size={$batchSize})...");
    $stepResult = runStep($conn, "EXEC dbo.sp_Archive_Trajectory_ToWarm @batch_size = {$batchSize}, @debug = 0");
    $results['steps']['trajectory_to_warm'] = $stepResult;

    // Step 3: Compress warm to cold tier
    logMsg("Step 3/4: Compressing warm tier to cold...");
    $stepResult = runStep($conn, 'EXEC dbo.sp_Downsample_Trajectory_ToCold @debug = 0');
    $results['steps']['trajectory_to_cold'] = $stepResult;

    // Step 4: Purge old data
    logMsg("Step 4/4: Purging old data...");
    $stepResult = runStep($conn, 'EXEC dbo.sp_Purge_OldData @debug = 0');
    $results['steps']['purge'] = $stepResult;

    // Check for any failures
    foreach ($results['steps'] as $step => $stepResult) {
        if (!$stepResult['success']) {
            $results['success'] = false;
            $results['error'] = "Step '{$step}' failed: " . ($stepResult['error'] ?? 'Unknown error');
            break;
        }
    }

    $results['ended_utc'] = gmdate('Y-m-d H:i:s');

    return $results;
}

function runStep(mixed $conn, string $sql): array {
    $start = microtime(true);
    $result = [
        'success' => false,
        'duration_ms' => 0,
        'output' => null,
        'error' => null,
    ];

    $stmt = sqlsrv_query($conn, $sql);

    if ($stmt === false) {
        $errors = sqlsrv_errors();
        $result['error'] = json_encode($errors);
        $result['duration_ms'] = (int)((microtime(true) - $start) * 1000);
        logMsg("  FAILED: " . $result['error'], 'ERROR');
        return $result;
    }

    // Fetch any result sets
    $output = [];
    do {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $output[] = $row;
        }
    } while (sqlsrv_next_result($stmt));

    sqlsrv_free_stmt($stmt);

    $result['success'] = true;
    $result['duration_ms'] = (int)((microtime(true) - $start) * 1000);
    $result['output'] = $output;

    $durationSec = round($result['duration_ms'] / 1000, 1);
    logMsg("  Completed in {$durationSec}s");

    return $result;
}

// ============================================================================
// GET ARCHIVAL STATS
// ============================================================================

function getArchivalStats(mixed $conn): array {
    // NOLOCK: Safe for stats query - approximate counts are fine for monitoring
    $sql = "
        SELECT
            (SELECT COUNT(*) FROM dbo.adl_flight_trajectory WITH (NOLOCK)) AS hot_tier_rows,
            (SELECT COUNT(*) FROM dbo.adl_trajectory_archive WITH (NOLOCK)) AS warm_tier_rows,
            (SELECT COUNT(*) FROM dbo.adl_trajectory_compressed WITH (NOLOCK)) AS cold_tier_rows,
            (SELECT COUNT(*) FROM dbo.adl_flight_changelog WITH (NOLOCK)) AS changelog_rows
    ";

    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) {
        return [];
    }

    $stats = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    return $stats ?: [];
}

// ============================================================================
// DETERMINE INTERVAL
// ============================================================================

function getInterval(array $config, ?int $customInterval): int {
    if ($customInterval !== null) {
        return max(1, $customInterval);
    }

    $hour = (int)gmdate('G');

    if ($hour >= $config['off_peak_start'] && $hour < $config['off_peak_end']) {
        return $config['off_peak_interval'];
    }

    return $config['peak_interval'];
}

// ============================================================================
// PID FILE LOCK (for continuous mode only)
// ============================================================================

define('PID_FILE', sys_get_temp_dir() . '/perti_archival_daemon.pid');

function acquirePidLock(): bool {
    if (file_exists(PID_FILE)) {
        $existingPid = (int) file_get_contents(PID_FILE);
        if (PHP_OS_FAMILY === 'Windows') {
            exec("tasklist /FI \"PID eq {$existingPid}\" 2>NUL", $output, $exitCode);
            $processExists = count($output) > 1;
        } else {
            $processExists = posix_kill($existingPid, 0);
        }
        if ($processExists) {
            echo "ERROR: Another instance is already running (PID: {$existingPid})\n";
            echo "If this is incorrect, delete: " . PID_FILE . "\n";
            return false;
        }
        unlink(PID_FILE);
    }
    file_put_contents(PID_FILE, (string)getmypid());
    return true;
}

function releasePidLock(): void {
    if (file_exists(PID_FILE)) {
        unlink(PID_FILE);
    }
}

register_shutdown_function('releasePidLock');

// Acquire PID lock for continuous mode
if (!$runOnce) {
    if (!acquirePidLock()) {
        exit(1);
    }
}

// ============================================================================
// MAIN
// ============================================================================

logMsg("========================================");
logMsg("PERTI Archival Daemon Starting");
logMsg("PID: " . getmypid());
if (!$runOnce) {
    logMsg("PID lock acquired");
}
logMsg("Batch size: {$batchSize}");
logMsg("Run mode: " . ($runOnce ? 'once' : 'continuous'));
logMsg("========================================");

$conn = connectDb();
if (!$conn) {
    logMsg("Failed to connect to database, exiting", 'ERROR');
    exit(1);
}

logMsg("Database connected");

// Get initial stats
$stats = getArchivalStats($conn);
logMsg("Current stats: hot=" . number_format($stats['hot_tier_rows'] ?? 0) .
       ", warm=" . number_format($stats['warm_tier_rows'] ?? 0) .
       ", cold=" . number_format($stats['cold_tier_rows'] ?? 0));

$runCount = 0;

do {
    $runCount++;
    logMsg("--- Archival Run #{$runCount} ---");

    $results = runArchival($conn, $config['batch_size']);

    if ($results['success']) {
        logMsg("Archival completed successfully");
    } else {
        logMsg("Archival failed: " . $results['error'], 'ERROR');
    }

    // Get updated stats
    $stats = getArchivalStats($conn);
    logMsg("Updated stats: hot=" . number_format($stats['hot_tier_rows'] ?? 0) .
           ", warm=" . number_format($stats['warm_tier_rows'] ?? 0) .
           ", cold=" . number_format($stats['cold_tier_rows'] ?? 0));

    if ($runOnce) {
        break;
    }

    // Calculate next run time
    $intervalMinutes = getInterval($config, $customInterval);
    $nextRun = gmdate('Y-m-d H:i:s', time() + ($intervalMinutes * 60));
    logMsg("Next run in {$intervalMinutes} minutes at {$nextRun} UTC");
    logMsg("----------------------------------------");

    // Sleep until next run
    sleep($intervalMinutes * 60);

    // Reconnect if needed (connection may have timed out)
    if (!sqlsrv_query($conn, "SELECT 1")) {
        logMsg("Reconnecting to database...");
        sqlsrv_close($conn);
        $conn = connectDb();
        if (!$conn) {
            logMsg("Failed to reconnect, exiting", 'ERROR');
            exit(1);
        }
    }

} while (true);

logMsg("Archival daemon exiting");
sqlsrv_close($conn);
exit(0);
