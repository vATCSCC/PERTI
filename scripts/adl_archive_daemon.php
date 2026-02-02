#!/usr/bin/env php
<?php
/**
 * ADL Archive Daemon
 *
 * Schedules daily archival of trajectory data from VATSIM_ADL to Parquet in Azure Blob Storage.
 * Runs at 10:00 UTC daily (lowest VATSIM traffic), archiving the previous day's data.
 *
 * This daemon calls the Python script (daily_archive.py) which handles the actual
 * Parquet conversion and blob upload.
 *
 * Usage:
 *   php adl_archive_daemon.php           # Run as daemon (loops forever)
 *   php adl_archive_daemon.php --once    # Run once and exit
 *   php adl_archive_daemon.php --now     # Archive yesterday immediately
 *
 * Environment:
 *   ADL_ARCHIVE_STORAGE_CONN  - Azure Blob Storage connection string
 *   ADL_ARCHIVE_HOUR_UTC      - Hour to run (0-23), default: 10
 *
 * Author: Claude (AI-assisted implementation)
 * Date: 2026-02-02
 */

declare(strict_types=1);

// Configuration - 10:00 UTC is typically lowest VATSIM traffic
// (night in Americas, early morning in Europe)
define('ARCHIVE_HOUR_UTC', (int)getenv('ADL_ARCHIVE_HOUR_UTC') ?: 10);
define('ARCHIVE_MINUTE_UTC', 0);
define('PYTHON_SCRIPT', __DIR__ . '/adl_archive/daily_archive.py');
define('LOG_FILE', '/home/LogFiles/adl_archive.log');
define('LOCK_FILE', '/tmp/adl_archive.lock');

// Parse command line args
$runOnce = in_array('--once', $argv);
$runNow = in_array('--now', $argv);

/**
 * Log message with timestamp
 */
function logMsg(string $msg, string $level = 'INFO'): void {
    $timestamp = gmdate('Y-m-d H:i:s');
    $line = "[{$timestamp} UTC] [{$level}] {$msg}\n";

    // Log to file
    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);

    // Also echo to stdout for Azure logs
    echo $line;
}

/**
 * Calculate seconds until next scheduled run time
 */
function secondsUntilNextRun(): int {
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $target = clone $now;
    $target->setTime(ARCHIVE_HOUR_UTC, ARCHIVE_MINUTE_UTC, 0);

    // If we've passed today's run time, schedule for tomorrow
    if ($now >= $target) {
        $target->modify('+1 day');
    }

    return $target->getTimestamp() - $now->getTimestamp();
}

/**
 * Run the archive job for a specific date
 */
function runArchive(?string $date = null): bool {
    // Check for lock (prevent concurrent runs)
    if (file_exists(LOCK_FILE)) {
        $lockAge = time() - filemtime(LOCK_FILE);
        if ($lockAge < 3600) { // Lock is less than 1 hour old
            logMsg("Archive already running (lock age: {$lockAge}s), skipping", 'WARN');
            return false;
        }
        // Stale lock, remove it
        unlink(LOCK_FILE);
    }

    // Create lock
    file_put_contents(LOCK_FILE, getmypid());

    try {
        // Determine date to archive (yesterday if not specified)
        if ($date === null) {
            $date = (new DateTime('yesterday', new DateTimeZone('UTC')))->format('Y-m-d');
        }

        logMsg("Starting archive for date: {$date}");

        // Check if Python script exists
        if (!file_exists(PYTHON_SCRIPT)) {
            logMsg("Python script not found: " . PYTHON_SCRIPT, 'ERROR');
            return false;
        }

        // Check for storage connection string
        $storageConn = getenv('ADL_ARCHIVE_STORAGE_CONN');
        if (empty($storageConn)) {
            logMsg("ADL_ARCHIVE_STORAGE_CONN environment variable not set", 'ERROR');
            return false;
        }

        // Build command
        $cmd = sprintf(
            'python3 %s --date %s 2>&1',
            escapeshellarg(PYTHON_SCRIPT),
            escapeshellarg($date)
        );

        logMsg("Executing: python3 daily_archive.py --date {$date}");

        // Execute Python script
        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        // Log output
        foreach ($output as $line) {
            logMsg("  " . $line);
        }

        if ($returnCode === 0) {
            logMsg("Archive completed successfully for {$date}");
            return true;
        } else {
            logMsg("Archive failed with exit code {$returnCode}", 'ERROR');
            return false;
        }

    } finally {
        // Release lock
        @unlink(LOCK_FILE);
    }
}

/**
 * Check for any missed days and backfill them
 */
function checkForMissedDays(): void {
    // This could be enhanced to check blob storage for gaps
    // For now, we just run for yesterday
    logMsg("Checking for missed archive days...");

    // TODO: Query blob storage to find gaps in the last 7 days
    // and backfill any missing dates
}

// Main loop
logMsg("ADL Archive Daemon starting");
logMsg("Schedule: Daily at " . sprintf('%02d:%02d', ARCHIVE_HOUR_UTC, ARCHIVE_MINUTE_UTC) . " UTC");
logMsg("Python script: " . PYTHON_SCRIPT);

// Check Python availability
exec('python3 --version 2>&1', $pyVersion, $pyCode);
if ($pyCode === 0) {
    logMsg("Python: " . implode(' ', $pyVersion));
} else {
    logMsg("WARNING: Python3 not found, archive jobs will fail", 'WARN');
}

// If --now flag, run immediately
if ($runNow) {
    logMsg("Running archive immediately (--now flag)");
    $success = runArchive();
    exit($success ? 0 : 1);
}

// If --once flag, wait for next scheduled time, run, then exit
if ($runOnce) {
    $sleepSec = secondsUntilNextRun();
    logMsg("Will run in {$sleepSec} seconds (--once mode)");
    sleep($sleepSec);
    $success = runArchive();
    exit($success ? 0 : 1);
}

// Daemon loop
while (true) {
    $sleepSec = secondsUntilNextRun();
    $nextRun = (new DateTime('now', new DateTimeZone('UTC')))
        ->modify("+{$sleepSec} seconds")
        ->format('Y-m-d H:i:s');

    logMsg("Next archive scheduled for: {$nextRun} UTC (in {$sleepSec} seconds)");

    // Sleep until next run time
    // Use smaller sleep intervals to allow graceful shutdown
    while ($sleepSec > 0) {
        $sleepChunk = min($sleepSec, 300); // Sleep max 5 minutes at a time
        sleep($sleepChunk);
        $sleepSec -= $sleepChunk;
    }

    // Run the archive
    runArchive();

    // Brief pause before calculating next run
    sleep(60);
}
