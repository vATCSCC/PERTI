#!/usr/bin/env php
<?php
/**
 * Deep Hibernation Replay — Post-process archived VATSIM JSON data
 *
 * Reads gzipped JSON files from Azure Blob Storage (datafeed/ prefix),
 * decompresses each, and feeds it through the ADL ingest pipeline
 * (staging tables → sp_Adl_RefreshFromVatsim_Staged).
 *
 * IMPORTANT: Run this AFTER exiting deep hibernation, with all daemons running.
 * The script extends the archive grace period to prevent data loss during replay.
 *
 * Usage:
 *   php scripts/deep_hibernation_replay.php --verbose
 *   php scripts/deep_hibernation_replay.php --start-date=2026-05-01 --end-date=2026-05-13
 *   php scripts/deep_hibernation_replay.php --dry-run
 *   php scripts/deep_hibernation_replay.php --batch=100
 *
 * Options:
 *   --start-date=YYYY-MM-DD   Start date for replay (default: earliest archived)
 *   --end-date=YYYY-MM-DD     End date (default: latest archived)
 *   --delay-ms=N              Delay between cycles in milliseconds (default: 0 = max speed)
 *   --dry-run                 List files and counts without processing
 *   --batch=N                 Process N files then pause for confirmation
 *   --skip-safety             Skip archive grace period extension (not recommended)
 *   --verbose                 Detailed per-cycle logging
 *
 * @see docs/superpowers/specs/2026-05-13-deep-hibernation-design.md Section 6
 */

declare(strict_types=1);
error_reporting(E_ALL);
set_time_limit(0);
ini_set('memory_limit', '512M');

// ============================================================================
// BOOTSTRAP
// ============================================================================

$scriptDir = __DIR__;
$wwwroot = dirname($scriptDir);

require_once $wwwroot . '/load/config.php';
require_once $scriptDir . '/lib/AzureBlobClient.php';

// ============================================================================
// CLI ARGUMENTS
// ============================================================================

$opts = getopt('', [
    'start-date:',
    'end-date:',
    'delay-ms:',
    'dry-run',
    'batch:',
    'skip-safety',
    'verbose',
]);

$startDate  = $opts['start-date'] ?? null;
$endDate    = $opts['end-date'] ?? null;
$delayMs    = (int)($opts['delay-ms'] ?? 0);
$dryRun     = isset($opts['dry-run']);
$batchSize  = isset($opts['batch']) ? (int)$opts['batch'] : 0;
$skipSafety = isset($opts['skip-safety']);
$verbose    = isset($opts['verbose']);

// ============================================================================
// LOGGING
// ============================================================================

$logFile = file_exists('/home/LogFiles') ? '/home/LogFiles/deep_hibernation_replay.log' : $scriptDir . '/deep_hibernation_replay.log';

function logMsg(string $msg, string $level = 'INFO'): void {
    global $logFile;
    $timestamp = gmdate('Y-m-d H:i:s');
    $line = "[{$timestamp} UTC] [{$level}] {$msg}\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

// ============================================================================
// PRE-FLIGHT CHECKS
// ============================================================================

logMsg("=== Deep Hibernation Replay ===");

// 1. Verify we're NOT in deep hibernation mode
if (defined('DEEP_HIBERNATION_MODE') && DEEP_HIBERNATION_MODE) {
    logMsg("ERROR: DEEP_HIBERNATION_MODE is still active. Exit deep hibernation first.", 'ERROR');
    exit(1);
}

// 2. Verify storage connection
$storageConn = getenv('ADL_ARCHIVE_STORAGE_CONN');
if (empty($storageConn)) {
    logMsg("ERROR: ADL_ARCHIVE_STORAGE_CONN not set", 'ERROR');
    exit(1);
}

// 3. Verify ADL database connection
if (!defined('ADL_SQL_HOST') || !defined('ADL_SQL_DATABASE') || !defined('ADL_SQL_USERNAME') || !defined('ADL_SQL_PASSWORD')) {
    logMsg("ERROR: ADL_SQL_* constants not defined", 'ERROR');
    exit(1);
}

// 4. Connect to ADL
logMsg("Connecting to VATSIM_ADL...");
$conn = sqlsrv_connect(ADL_SQL_HOST, [
    'Database' => ADL_SQL_DATABASE,
    'UID'      => ADL_SQL_USERNAME,
    'PWD'      => ADL_SQL_PASSWORD,
    'Encrypt'  => true,
    'TrustServerCertificate' => false,
    'LoginTimeout' => 30,
]);
if ($conn === false) {
    $errors = sqlsrv_errors();
    logMsg("ERROR: Cannot connect to VATSIM_ADL: " . json_encode($errors), 'ERROR');
    exit(1);
}
logMsg("Connected to VATSIM_ADL");

// 5. Check archival daemon is not running
if (!$skipSafety) {
    logMsg("Safety check: verifying archival daemon is not running...");
    // Check lock file
    if (file_exists('/tmp/adl_archive.lock')) {
        logMsg("ERROR: Archival daemon lock file exists at /tmp/adl_archive.lock. Stop it first.", 'ERROR');
        exit(1);
    }
    logMsg("Safety check passed: archival daemon not running");

    // Extend archive grace period to 48 hours
    logMsg("Extending archive grace period to 48 hours...");
    if (!$dryRun) {
        $sql = "IF EXISTS (SELECT 1 FROM adl_archive_config WHERE config_key = 'COMPLETED_FLIGHT_DELAY_HOURS')
                    UPDATE adl_archive_config SET config_value = '48', updated_utc = GETUTCDATE()
                    WHERE config_key = 'COMPLETED_FLIGHT_DELAY_HOURS'
                ELSE
                    INSERT INTO adl_archive_config (config_key, config_value, updated_utc)
                    VALUES ('COMPLETED_FLIGHT_DELAY_HOURS', '48', GETUTCDATE())";
        $stmt = sqlsrv_query($conn, $sql);
        if ($stmt === false) {
            logMsg("WARNING: Could not extend grace period: " . json_encode(sqlsrv_errors()), 'WARN');
        } else {
            sqlsrv_free_stmt($stmt);
            logMsg("Archive grace period set to 48 hours");
        }
    } else {
        logMsg("[DRY RUN] Would set COMPLETED_FLIGHT_DELAY_HOURS to 48");
    }
}

// ============================================================================
// LIST ARCHIVED FILES
// ============================================================================

logMsg("Listing archived files from blob storage...");
$blobClient = new AzureBlobClient($storageConn);
$container = 'adl-raw-archive';
$prefix = 'datafeed/';

// Build prefix filter from date range
if ($startDate) {
    $prefix = 'datafeed/' . str_replace('-', '/', $startDate) . '/';
    // For multi-day ranges, we'll list all and filter
    if ($endDate && $startDate !== $endDate) {
        $prefix = 'datafeed/';
    }
}

$allBlobs = [];
$marker = null;
do {
    $result = $blobClient->listBlobs($container, $prefix, $marker);
    foreach ($result['blobs'] as $blob) {
        // Filter by date range if specified
        if ($startDate || $endDate) {
            // Extract date from path: datafeed/YYYY/MM/DD/HH/vatsim-data-YYYYMMDD-HHMMSS.json.gz
            if (preg_match('#datafeed/(\d{4})/(\d{2})/(\d{2})/#', $blob['name'], $m)) {
                $blobDate = "{$m[1]}-{$m[2]}-{$m[3]}";
                if ($startDate && $blobDate < $startDate) continue;
                if ($endDate && $blobDate > $endDate) continue;
            }
        }
        $allBlobs[] = $blob;
    }
    $marker = $result['next_marker'];
} while ($marker !== null);

// Sort by name (lexicographic = chronological due to timestamp naming)
usort($allBlobs, fn($a, $b) => strcmp($a['name'], $b['name']));

$totalFiles = count($allBlobs);
$totalBytes = array_sum(array_column($allBlobs, 'size'));
logMsg("Found {$totalFiles} archived files (" . round($totalBytes / 1024 / 1024, 1) . " MB compressed)");

if ($totalFiles === 0) {
    logMsg("No files to replay. Exiting.");
    exit(0);
}

// Show date range
if (!empty($allBlobs)) {
    logMsg("Date range: {$allBlobs[0]['name']} to {$allBlobs[$totalFiles - 1]['name']}");
}

if ($dryRun) {
    logMsg("[DRY RUN] Would process {$totalFiles} files. Exiting.");
    exit(0);
}

// ============================================================================
// REPLAY LOOP
// ============================================================================

// Load shared ADL ingest functions (extracted from vatsim_adl_daemon.php in Task 4A)
// Provides: parseVatsimPilots(), parseVatsimPrefiles(), insertPilotsBulkLiteral(),
// insertPrefilesBulkLiteral(), executeStagedRefreshSP(), generateBatchId()
require_once $scriptDir . '/lib/adl_ingest_functions.php';

logMsg("=== Starting replay of {$totalFiles} files ===");

$processed = 0;
$errors = 0;
$startTime = microtime(true);

foreach ($allBlobs as $blob) {
    $processed++;

    if ($verbose) {
        logMsg("Processing [{$processed}/{$totalFiles}]: {$blob['name']}");
    }

    try {
        // 1. Download blob
        $compressed = $blobClient->getBlob($container, $blob['name']);

        // 2. Decompress
        $jsonData = gzdecode($compressed);
        if ($jsonData === false) {
            logMsg("Failed to decompress: {$blob['name']}", 'ERROR');
            $errors++;
            continue;
        }

        // 3. Validate JSON
        $vatsimData = json_decode($jsonData, true);
        if (!$vatsimData || !isset($vatsimData['pilots'])) {
            logMsg("Invalid JSON structure in: {$blob['name']}", 'WARN');
            $errors++;
            continue;
        }

        $pilotCount = count($vatsimData['pilots']);

        // 4. Parse pilots + prefiles (same as ADL daemon)
        // NOTE: We skip computeChangeFlags() — change detection (position/altitude/route deltas)
        // is meaningless for historical replay since we're processing snapshots sequentially.
        // The SP handles flight state updates without needing change_flags.
        $parsedPilots = parseVatsimPilots($vatsimData);
        $parsedPrefiles = parseVatsimPrefiles($vatsimData);

        // 5. Insert to staging tables
        $batchId = generateBatchId();
        $pilotResult = insertPilotsBulkLiteral($conn, $parsedPilots, $batchId, 1000);
        $prefileResult = insertPrefilesBulkLiteral($conn, $parsedPrefiles, $batchId, 1000);

        // 6. Execute staged refresh SP
        $spResult = executeStagedRefreshSP($conn, $batchId, 120, false, false);

        if ($verbose) {
            logMsg("  Replayed: {$pilotCount} pilots, SP={$spResult['elapsed_ms']}ms");
        }

        // Free memory
        unset($compressed, $jsonData, $vatsimData, $parsedPilots, $parsedPrefiles);

    } catch (Exception $e) {
        logMsg("Error processing {$blob['name']}: {$e->getMessage()}", 'ERROR');
        $errors++;
    }

    // Delay between cycles if configured
    if ($delayMs > 0) {
        usleep($delayMs * 1000);
    }

    // Batch pause
    if ($batchSize > 0 && $processed % $batchSize === 0 && $processed < $totalFiles) {
        $elapsed = round(microtime(true) - $startTime);
        $rate = round($processed / max($elapsed, 1), 1);
        logMsg("Batch checkpoint: {$processed}/{$totalFiles} ({$rate} files/sec, {$errors} errors)");
        logMsg("Press ENTER to continue or Ctrl+C to stop...");
        fgets(STDIN);
    }

    // Progress log every 100 files
    if ($processed % 100 === 0) {
        $elapsed = round(microtime(true) - $startTime);
        $rate = round($processed / max($elapsed, 1), 1);
        $remaining = $totalFiles - $processed;
        $eta = $rate > 0 ? round($remaining / $rate) : 0;
        logMsg("Progress: {$processed}/{$totalFiles} ({$rate}/sec, ~{$eta}s remaining, {$errors} errors)");
    }
}

// ============================================================================
// POST-REPLAY
// ============================================================================

$totalElapsed = round(microtime(true) - $startTime);
logMsg("=== Replay complete ===");
logMsg("Processed: {$processed}, Errors: {$errors}, Elapsed: {$totalElapsed}s");

if (!$skipSafety) {
    logMsg("");
    logMsg("Next steps:");
    logMsg("  1. Run GIS backfill: php scripts/backfill/hibernation_recovery.php --phase=auto --include-inactive");
    logMsg("  2. After backfill completes, reset archive grace period:");
    logMsg("     php scripts/backfill/hibernation_recovery.php --delay-hours=2");
    logMsg("  3. Restart archival daemon if stopped");
}

sqlsrv_close($conn);
