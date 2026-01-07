#!/usr/bin/env php
<?php
/**
 * VATSIM ADL Refresh Daemon
 * 
 * Location: wwwroot/scripts/vatsim_adl_daemon.php
 * 
 * Fetches VATSIM data every 15 seconds and calls sp_Adl_RefreshFromVatsim.
 * Optimized for 3,000-6,000 flights per cycle.
 * 
 * Usage:
 *   php scripts/vatsim_adl_daemon.php                # Run in foreground
 *   nohup php scripts/vatsim_adl_daemon.php &        # Run detached
 *   systemctl start vatsim-adl                       # Via systemd
 */

declare(strict_types=1);
error_reporting(E_ALL);
set_time_limit(0);
ini_set('memory_limit', '512M');  // Increased for large payloads

// ============================================================================
// LOAD PERTI CONFIG
// ============================================================================

$scriptDir = __DIR__;
$wwwroot = dirname($scriptDir);  // Parent of scripts/ is wwwroot/
$configPath = $wwwroot . '/load/config.php';

if (!file_exists($configPath)) {
    die("ERROR: Cannot find config at {$configPath}\n" .
        "Make sure this script is in wwwroot/scripts/\n");
}

require_once $configPath;

// Verify ADL constants exist
if (!defined('ADL_SQL_HOST') || !defined('ADL_SQL_DATABASE') || !defined('ADL_SQL_USERNAME') || !defined('ADL_SQL_PASSWORD')) {
    die("ERROR: ADL_SQL_* constants not defined in config.php\n" .
        "Required: ADL_SQL_HOST, ADL_SQL_DATABASE, ADL_SQL_USERNAME, ADL_SQL_PASSWORD\n");
}

// ============================================================================
// CONFIGURATION
// ============================================================================

$config = [
    // Database (from PERTI config)
    'db_server'   => ADL_SQL_HOST,
    'db_name'     => ADL_SQL_DATABASE,
    'db_user'     => ADL_SQL_USERNAME,
    'db_pass'     => ADL_SQL_PASSWORD,
    
    // VATSIM API
    'vatsim_url'  => 'https://data.vatsim.net/v3/vatsim-data.json',
    
    // Timing
    'interval_seconds' => 15,
    'sp_timeout'       => 120,  // SP timeout in seconds
    
    // Logging
    'log_file'     => $scriptDir . '/vatsim_adl.log',
    'log_to_file'  => true,
    'log_to_stdout'=> true,
    
    // Performance thresholds (for warnings)
    'warn_sp_ms'      => 5000,   // Warn if SP takes >5s
    'critical_sp_ms'  => 10000,  // Critical if SP takes >10s
];

// ============================================================================
// LOGGING
// ============================================================================

/**
 * Rotate log file if it exceeds max size.
 * Keeps up to 3 rotated logs (.1, .2, .3)
 */
function rotateLogIfNeeded(string $logFile, int $maxSizeBytes = 10485760): void {
    if (!file_exists($logFile)) {
        return;
    }

    $size = @filesize($logFile);
    if ($size === false || $size < $maxSizeBytes) {
        return;
    }

    // Rotate: .3 -> delete, .2 -> .3, .1 -> .2, current -> .1
    $rotated3 = $logFile . '.3';
    $rotated2 = $logFile . '.2';
    $rotated1 = $logFile . '.1';

    if (file_exists($rotated3)) {
        @unlink($rotated3);
    }
    if (file_exists($rotated2)) {
        @rename($rotated2, $rotated3);
    }
    if (file_exists($rotated1)) {
        @rename($rotated1, $rotated2);
    }
    @rename($logFile, $rotated1);

    // Create new empty log
    @file_put_contents($logFile, '');
}

function logMessage(string $level, string $message, array $context = []): void {
    global $config;
    static $writeCount = 0;

    $timestamp = gmdate('Y-m-d H:i:s');
    $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
    $line = "[{$timestamp}Z] [{$level}] {$message}{$contextStr}\n";

    if ($config['log_to_stdout']) {
        echo $line;
        flush();
    }

    if ($config['log_to_file'] && !empty($config['log_file'])) {
        @file_put_contents($config['log_file'], $line, FILE_APPEND | LOCK_EX);

        // Check for rotation every 100 writes to avoid stat() on every log
        $writeCount++;
        if ($writeCount >= 100) {
            $writeCount = 0;
            rotateLogIfNeeded($config['log_file'], 10485760);  // 10 MB max
        }
    }
}

function logInfo(string $msg, array $ctx = []): void { logMessage('INFO', $msg, $ctx); }
function logError(string $msg, array $ctx = []): void { logMessage('ERROR', $msg, $ctx); }
function logWarn(string $msg, array $ctx = []): void { logMessage('WARN', $msg, $ctx); }

// ============================================================================
// DATABASE CONNECTION (Optimized for performance)
// ============================================================================

function getConnection(array $config) {
    $connectionOptions = [
        "Database"               => $config['db_name'],
        "Uid"                    => $config['db_user'],
        "PWD"                    => $config['db_pass'],
        "Encrypt"                => true,
        "TrustServerCertificate" => false,
        "LoginTimeout"           => 30,
        "ConnectionPooling"      => true,
        // Performance optimizations
        "MultipleActiveResultSets" => false,  // We don't need MARS
        "ApplicationIntent"      => "ReadWrite",
    ];
    
    $conn = sqlsrv_connect($config['db_server'], $connectionOptions);
    
    if ($conn === false) {
        $errors = sqlsrv_errors();
        throw new Exception("SQL connection failed: " . json_encode($errors));
    }
    
    return $conn;
}

// ============================================================================
// VATSIM DATA FETCH (Optimized with cURL for better performance)
// ============================================================================

function fetchVatsimData(string $url): ?string {
    // Use cURL if available (faster than file_get_contents)
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_ENCODING       => 'gzip,deflate',  // Request compressed response
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Accept-Encoding: gzip, deflate',
                'User-Agent: PERTI-ADL-Daemon/1.0',
            ],
            CURLOPT_TCP_FASTOPEN   => true,  // TCP Fast Open if available
            CURLOPT_TCP_NODELAY    => true,  // Disable Nagle's algorithm
        ]);
        
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($data === false || $httpCode !== 200) {
            logWarn("cURL fetch failed", ['http_code' => $httpCode, 'error' => $error]);
            return null;
        }
        
        return $data;
    }
    
    // Fallback to file_get_contents
    $context = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 30,
            'header'  => [
                'Accept: application/json',
                'Accept-Encoding: gzip, deflate',
                'User-Agent: PERTI-ADL-Daemon/1.0',
            ],
        ],
    ]);
    
    $data = @file_get_contents($url, false, $context);
    return $data !== false ? $data : null;
}

// ============================================================================
// STORED PROCEDURE EXECUTION
// ============================================================================

function executeRefreshSP($conn, string $jsonData, int $timeout): array {
    $startTime = microtime(true);
    
    // Use parameterized query for safety and performance
    $sql = "EXEC [dbo].[sp_Adl_RefreshFromVatsim_Normalized] @Json = ?";
    
    // Set query timeout
    $options = ['QueryTimeout' => $timeout];
    
    $stmt = sqlsrv_query($conn, $sql, [&$jsonData], $options);
    
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        throw new Exception("SP execution failed: " . json_encode($errors));
    }
    
    // The SP logs its own metrics to adl_run_log, just consume any results
    while (sqlsrv_next_result($stmt)) {
        // Drain all result sets
    }
    
    sqlsrv_free_stmt($stmt);
    
    $elapsed = (microtime(true) - $startTime) * 1000;
    
    return [
        'success'    => true,
        'elapsed_ms' => round($elapsed),
    ];
}

// ============================================================================
// CONNECTION HEALTH CHECK (Fast)
// ============================================================================

function isConnectionAlive($conn): bool {
    if ($conn === null || $conn === false) {
        return false;
    }
    
    $stmt = @sqlsrv_query($conn, "SELECT 1");
    if ($stmt === false) {
        return false;
    }
    sqlsrv_free_stmt($stmt);
    return true;
}

// ============================================================================
// MAIN LOOP
// ============================================================================

function runDaemon(array $config): void {
    logInfo("=== VATSIM ADL Daemon Starting ===", [
        'interval'  => $config['interval_seconds'] . 's',
        'server'    => preg_replace('/\.database\.windows\.net$/', '.***', $config['db_server']),
        'database'  => $config['db_name'],
        'warn_ms'   => $config['warn_sp_ms'],
        'crit_ms'   => $config['critical_sp_ms'],
    ]);
    
    // Establish initial connection
    $conn = null;
    $reconnectAttempts = 0;
    $maxReconnectAttempts = 10;
    
    while ($conn === null && $reconnectAttempts < $maxReconnectAttempts) {
        try {
            $conn = getConnection($config);
            logInfo("Database connected");
        } catch (Exception $e) {
            $reconnectAttempts++;
            logError("Connection attempt {$reconnectAttempts} failed", ['error' => $e->getMessage()]);
            if ($reconnectAttempts < $maxReconnectAttempts) {
                sleep(min(30, $reconnectAttempts * 5));  // Exponential backoff capped at 30s
            }
        }
    }
    
    if ($conn === null) {
        logError("FATAL: Could not connect to database. Exiting.");
        exit(1);
    }
    
    // Stats
    $stats = [
        'runs'          => 0,
        'successes'     => 0,
        'failures'      => 0,
        'total_sp_ms'   => 0,
        'max_sp_ms'     => 0,
        'total_flights' => 0,
        'started'       => time(),
    ];
    
    // Signal handling
    $running = true;
    if (function_exists('pcntl_signal')) {
        $handler = function($sig) use (&$running) {
            logInfo("Received signal {$sig}, shutting down...");
            $running = false;
        };
        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }
    
    // ========== MAIN LOOP ==========
    while ($running) {
        $cycleStart = microtime(true);
        $stats['runs']++;
        
        try {
            // 1. Check connection health (fast check)
            if (!isConnectionAlive($conn)) {
                logWarn("Connection lost, reconnecting...");
                @sqlsrv_close($conn);
                $conn = getConnection($config);
                logInfo("Reconnected");
            }
            
            // 2. Fetch VATSIM data
            $fetchStart = microtime(true);
            $jsonData = fetchVatsimData($config['vatsim_url']);
            $fetchMs = round((microtime(true) - $fetchStart) * 1000);
            
            if ($jsonData === null || strlen($jsonData) < 1000) {
                throw new Exception("Failed to fetch VATSIM data or response too small");
            }
            
            // 3. Quick parse to get pilot count (for logging)
            $pilotCount = 0;
            if (preg_match('/"pilots"\s*:\s*\[/', $jsonData)) {
                // Count pilots by counting callsign occurrences (fast regex, avoids full JSON parse)
                $pilotCount = preg_match_all('/"callsign"\s*:/', $jsonData);
            }
            
            $jsonSizeKb = round(strlen($jsonData) / 1024);
            
            // 4. Execute stored procedure
            $spResult = executeRefreshSP($conn, $jsonData, $config['sp_timeout']);
            $spMs = $spResult['elapsed_ms'];
            
            // Free memory immediately
            unset($jsonData);
            
            // 5. Update stats
            $stats['successes']++;
            $stats['total_sp_ms'] += $spMs;
            $stats['total_flights'] += $pilotCount;
            if ($spMs > $stats['max_sp_ms']) {
                $stats['max_sp_ms'] = $spMs;
            }
            
            // 6. Log with performance level
            $logLevel = 'INFO';
            $perfNote = '';
            if ($spMs >= $config['critical_sp_ms']) {
                $logLevel = 'ERROR';
                $perfNote = ' [CRITICAL: >10s]';
            } elseif ($spMs >= $config['warn_sp_ms']) {
                $logLevel = 'WARN';
                $perfNote = ' [SLOW: >5s]';
            }
            
            logMessage($logLevel, "Refresh #{$stats['runs']}{$perfNote}", [
                'pilots'   => $pilotCount,
                'json_kb'  => $jsonSizeKb,
                'fetch_ms' => $fetchMs,
                'sp_ms'    => $spMs,
            ]);
            
        } catch (Exception $e) {
            $stats['failures']++;
            logError("Refresh #{$stats['runs']} FAILED", ['error' => $e->getMessage()]);
            
            // Attempt reconnection
            try {
                @sqlsrv_close($conn);
                $conn = getConnection($config);
                logInfo("Reconnected after error");
            } catch (Exception $re) {
                logError("Reconnection failed", ['error' => $re->getMessage()]);
                $conn = null;
            }
        }
        
        // Log stats every 100 runs (~25 minutes)
        if ($stats['runs'] % 100 === 0) {
            $avgSpMs = $stats['successes'] > 0 ? round($stats['total_sp_ms'] / $stats['successes']) : 0;
            $avgFlights = $stats['successes'] > 0 ? round($stats['total_flights'] / $stats['successes']) : 0;
            $uptime = round((time() - $stats['started']) / 60);
            $successRate = $stats['runs'] > 0 ? round(($stats['successes'] / $stats['runs']) * 100, 1) : 0;
            
            logInfo("=== Stats @ run {$stats['runs']} ===", [
                'uptime_min'    => $uptime,
                'success_rate'  => "{$successRate}%",
                'avg_sp_ms'     => $avgSpMs,
                'max_sp_ms'     => $stats['max_sp_ms'],
                'avg_flights'   => $avgFlights,
            ]);
        }
        
        // Calculate sleep time
        $cycleElapsed = microtime(true) - $cycleStart;
        $sleepTime = $config['interval_seconds'] - $cycleElapsed;
        
        if ($sleepTime > 0 && $running) {
            usleep((int)($sleepTime * 1000000));
        }
        
        // Process signals
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
        
        // Reconnect if connection was lost
        if ($conn === null && $running) {
            try {
                $conn = getConnection($config);
                logInfo("Connection restored");
            } catch (Exception $e) {
                logError("Still cannot connect", ['error' => $e->getMessage()]);
                sleep(5);
            }
        }
    }
    
    // Shutdown
    logInfo("=== Daemon Stopped ===", [
        'total_runs' => $stats['runs'],
        'successes'  => $stats['successes'],
        'failures'   => $stats['failures'],
    ]);
    
    if ($conn) {
        @sqlsrv_close($conn);
    }
}

// ============================================================================
// ENTRY POINT
// ============================================================================

// Check prerequisites
if (!extension_loaded('sqlsrv')) {
    die("ERROR: sqlsrv extension not loaded.\n" .
        "Linux: sudo pecl install sqlsrv\n" .
        "Windows: Download from Microsoft and enable in php.ini\n");
}

// Check for curl (optional but recommended)
if (!function_exists('curl_init')) {
    logWarn("cURL not available, using file_get_contents (slower)");
}

// Prevent multiple instances (simple lock file)
$lockFile = __DIR__ . '/vatsim_adl.lock';
$lockFp = fopen($lockFile, 'c+');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    die("ERROR: Another instance is already running (lock file: {$lockFile})\n");
}
ftruncate($lockFp, 0);
fwrite($lockFp, (string)getmypid());
fflush($lockFp);

// Run
runDaemon($config);

// Cleanup
flock($lockFp, LOCK_UN);
fclose($lockFp);
@unlink($lockFile);
