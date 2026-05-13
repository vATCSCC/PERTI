#!/usr/bin/env php
<?php
/**
 * Deep Hibernation Capture Daemon
 *
 * Fetches raw VATSIM Data API JSON every 15 seconds, gzip-compresses it,
 * and batch-uploads to Azure Blob Storage every 10 minutes.
 *
 * This daemon replaces vatsim_adl_daemon.php during deep hibernation.
 * No SQL processing occurs — raw JSON is preserved for post-processing replay.
 *
 * Usage:
 *   php scripts/deep_hibernation_daemon.php                # Run in foreground
 *   nohup php scripts/deep_hibernation_daemon.php &        # Run detached
 *
 * Environment:
 *   ADL_ARCHIVE_STORAGE_CONN     - Azure Blob Storage connection string (required)
 *   DEEP_HIB_FETCH_INTERVAL      - Seconds between fetches (default: 15)
 *   DEEP_HIB_UPLOAD_INTERVAL     - Seconds between blob upload batches (default: 600)
 *   DEEP_HIB_BUFFER_PATH         - Local buffer directory (default: /home/site/data/deep-hibernation-buffer)
 *
 * @see docs/superpowers/specs/2026-05-13-deep-hibernation-design.md
 */

declare(strict_types=1);
error_reporting(E_ALL);
set_time_limit(0);
ini_set('memory_limit', '256M');

// ============================================================================
// LOAD CONFIG
// ============================================================================

$scriptDir = __DIR__;
$wwwroot = dirname($scriptDir);
$configPath = $wwwroot . '/load/config.php';

if (!file_exists($configPath)) {
    die("ERROR: Cannot find config at {$configPath}\n");
}

require_once $configPath;
require_once $scriptDir . '/lib/AzureBlobClient.php';

// ============================================================================
// CONFIGURATION
// ============================================================================

$config = [
    'vatsim_url'      => 'https://data.vatsim.net/v3/vatsim-data.json',
    'fetch_interval'  => (int)(getenv('DEEP_HIB_FETCH_INTERVAL') ?: 15),
    'upload_interval' => (int)(getenv('DEEP_HIB_UPLOAD_INTERVAL') ?: 600),
    'buffer_path'     => getenv('DEEP_HIB_BUFFER_PATH') ?: '/home/site/data/deep-hibernation-buffer',
    'container'       => 'adl-raw-archive',
    'blob_prefix'     => 'datafeed',
    'log_file'        => file_exists('/home/LogFiles') ? '/home/LogFiles/deep_hibernation.log' : $scriptDir . '/deep_hibernation.log',
];

// Validate required env var
$storageConn = getenv('ADL_ARCHIVE_STORAGE_CONN');
if (empty($storageConn)) {
    die("ERROR: ADL_ARCHIVE_STORAGE_CONN environment variable not set\n");
}

// ============================================================================
// LOGGING
// ============================================================================

function logMsg(string $msg, string $level = 'INFO'): void {
    global $config;
    $timestamp = gmdate('Y-m-d H:i:s');
    $line = "[{$timestamp} UTC] [{$level}] {$msg}\n";
    @file_put_contents($config['log_file'], $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

// ============================================================================
// VATSIM FETCH
// ============================================================================

function fetchVatsimJson(string $url): ?string {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_ENCODING       => 'gzip,deflate',
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Accept-Encoding: gzip, deflate',
            'User-Agent: PERTI-DeepHibernation/1.0',
        ],
    ]);

    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($data === false || $httpCode !== 200) {
        logMsg("Fetch failed: HTTP {$httpCode}, error: {$error}", 'WARN');
        return null;
    }

    return $data;
}

// ============================================================================
// LOCAL BUFFER
// ============================================================================

function ensureBufferDir(string $path): void {
    if (!is_dir($path)) {
        if (!mkdir($path, 0755, true)) {
            die("ERROR: Cannot create buffer directory: {$path}\n");
        }
        logMsg("Created buffer directory: {$path}");
    }
}

function writeToBuffer(string $bufferPath, string $jsonData): ?string {
    $timestamp = gmdate('Ymd-His');
    $filename = "vatsim-data-{$timestamp}.json.gz";
    $filepath = "{$bufferPath}/{$filename}";

    $compressed = gzencode($jsonData, 6);
    if ($compressed === false) {
        logMsg("gzencode failed for {$filename}", 'ERROR');
        return null;
    }

    if (file_put_contents($filepath, $compressed) === false) {
        logMsg("Failed to write buffer file: {$filepath}", 'ERROR');
        return null;
    }

    return $filepath;
}

function getBufferedFiles(string $bufferPath): array {
    $files = glob("{$bufferPath}/vatsim-data-*.json.gz");
    if ($files === false) return [];
    sort($files); // Chronological order (filenames contain timestamps)
    return $files;
}

// ============================================================================
// BLOB UPLOAD
// ============================================================================

function uploadBufferedFiles(AzureBlobClient $client, string $container, string $prefix, string $bufferPath): array {
    $files = getBufferedFiles($bufferPath);
    if (empty($files)) return ['uploaded' => 0, 'failed' => 0, 'bytes' => 0];

    $uploaded = 0;
    $failed = 0;
    $totalBytes = 0;

    foreach ($files as $filepath) {
        $filename = basename($filepath);

        // Parse timestamp from filename: vatsim-data-YYYYMMDD-HHMMSS.json.gz
        if (!preg_match('/vatsim-data-(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})\.json\.gz/', $filename, $m)) {
            logMsg("Unexpected filename format: {$filename}, skipping", 'WARN');
            continue;
        }

        // Blob path: datafeed/YYYY/MM/DD/HH/vatsim-data-YYYYMMDD-HHMMSS.json.gz
        $blobPath = "{$prefix}/{$m[1]}/{$m[2]}/{$m[3]}/{$m[4]}/{$filename}";

        $data = file_get_contents($filepath);
        if ($data === false) {
            logMsg("Failed to read buffer file: {$filepath}", 'ERROR');
            $failed++;
            continue;
        }

        try {
            $result = $client->putBlob($container, $blobPath, $data);
            if ($result['status'] === 201) {
                $totalBytes += strlen($data);
                $uploaded++;
                unlink($filepath); // Remove after successful upload
            } else {
                logMsg("Blob upload returned HTTP {$result['status']} for {$blobPath}", 'WARN');
                $failed++;
            }
        } catch (Exception $e) {
            logMsg("Blob upload exception for {$blobPath}: {$e->getMessage()}", 'ERROR');
            $failed++;
        }
    }

    return ['uploaded' => $uploaded, 'failed' => $failed, 'bytes' => $totalBytes];
}

// ============================================================================
// MAIN LOOP
// ============================================================================

logMsg("=== Deep Hibernation Capture Daemon starting ===");
logMsg("Config: fetch={$config['fetch_interval']}s, upload={$config['upload_interval']}s");
logMsg("Buffer: {$config['buffer_path']}");
logMsg("Container: {$config['container']}, prefix: {$config['blob_prefix']}");

ensureBufferDir($config['buffer_path']);

// Initialize blob client
$blobClient = new AzureBlobClient($storageConn);

// Crash recovery: upload any existing buffered files from previous run
$existingFiles = getBufferedFiles($config['buffer_path']);
if (!empty($existingFiles)) {
    logMsg("Crash recovery: found " . count($existingFiles) . " buffered files, uploading...");
    $result = uploadBufferedFiles($blobClient, $config['container'], $config['blob_prefix'], $config['buffer_path']);
    logMsg("Crash recovery: uploaded={$result['uploaded']}, failed={$result['failed']}, bytes={$result['bytes']}");
}

// Signal handling for graceful shutdown
$running = true;
if (function_exists('pcntl_signal')) {
    $handler = function ($sig) use (&$running) {
        logMsg("Received signal {$sig}, shutting down...");
        $running = false;
    };
    pcntl_signal(SIGTERM, $handler);
    pcntl_signal(SIGINT, $handler);
}

$stats = ['fetches' => 0, 'fetch_errors' => 0, 'uploads' => 0, 'upload_bytes' => 0];
$lastUploadTime = time();

while ($running) {
    $cycleStart = microtime(true);

    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }

    // 1. Fetch VATSIM data
    $jsonData = fetchVatsimJson($config['vatsim_url']);

    if ($jsonData !== null && strlen($jsonData) > 1000) {
        // Validate JSON structure (must have 'pilots' key)
        $decoded = json_decode($jsonData, true);
        if ($decoded && isset($decoded['pilots'])) {
            $pilotCount = count($decoded['pilots']);
            unset($decoded); // Free memory

            // 2. Gzip + write to local buffer
            $filepath = writeToBuffer($config['buffer_path'], $jsonData);
            if ($filepath !== null) {
                $stats['fetches']++;
                $compressedSize = filesize($filepath);

                // Log every 20th fetch (5 minutes)
                if ($stats['fetches'] % 20 === 1) {
                    $rawKb = round(strlen($jsonData) / 1024);
                    $gzKb = round($compressedSize / 1024);
                    logMsg("Fetch #{$stats['fetches']}: {$pilotCount} pilots, raw={$rawKb}KB, gz={$gzKb}KB");
                }
            }
        } else {
            logMsg("Invalid JSON structure (no 'pilots' key), skipping", 'WARN');
            $stats['fetch_errors']++;
        }
    } else {
        $stats['fetch_errors']++;
    }

    unset($jsonData); // Free memory

    // 3. Batch upload check (every upload_interval seconds)
    $timeSinceUpload = time() - $lastUploadTime;
    if ($timeSinceUpload >= $config['upload_interval']) {
        $result = uploadBufferedFiles($blobClient, $config['container'], $config['blob_prefix'], $config['buffer_path']);
        if ($result['uploaded'] > 0 || $result['failed'] > 0) {
            $stats['uploads'] += $result['uploaded'];
            $stats['upload_bytes'] += $result['bytes'];
            $bytesKb = round($result['bytes'] / 1024);
            logMsg("Upload batch: uploaded={$result['uploaded']}, failed={$result['failed']}, size={$bytesKb}KB");
        }
        $lastUploadTime = time();
    }

    // 4. Sleep until next fetch interval
    $elapsed = microtime(true) - $cycleStart;
    $sleepTime = max(0, $config['fetch_interval'] - $elapsed);
    if ($sleepTime > 0) {
        usleep((int)($sleepTime * 1_000_000));
    }
}

// Final upload before exit
logMsg("Shutting down — uploading remaining buffered files...");
$result = uploadBufferedFiles($blobClient, $config['container'], $config['blob_prefix'], $config['buffer_path']);
logMsg("Final upload: uploaded={$result['uploaded']}, failed={$result['failed']}");
logMsg("Session stats: fetches={$stats['fetches']}, errors={$stats['fetch_errors']}, uploads={$stats['uploads']}, totalBytes={$stats['upload_bytes']}");
logMsg("=== Deep Hibernation Capture Daemon stopped ===");
