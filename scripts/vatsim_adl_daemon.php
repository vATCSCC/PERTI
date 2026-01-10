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
require_once __DIR__ . '/atis_parser.php';

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

    // ATIS processing with dynamic tiered intervals
    'atis_enabled'    => true,

    // Tier intervals (in 15-second cycles)
    // Tier 0: every 15s (1 cycle)  - METAR update time / bad weather ASPM77
    // Tier 1: every 1min (4 cycles) - ASPM77 normal weather
    // Tier 2: every 5min (20 cycles) - Non-ASPM77 + Canada + LatAm + Caribbean
    // Tier 3: every 30min (120 cycles) - All other airports
    // Tier 4: every 60min (240 cycles) - Clear weather non-priority airports
    'atis_tier_intervals' => [
        0 => 1,    // every 15s
        1 => 4,    // every 1min
        2 => 20,   // every 5min
        3 => 120,  // every 30min
        4 => 240,  // every 60min
    ],

    // ASPM77 airports (FAA Aviation System Performance Metrics)
    'aspm77' => [
        'KABQ', 'KALB', 'PANC', 'KATL', 'KAUS', 'KBDL', 'KBHM', 'KBNA', 'KBOS', 'KBUF',
        'KBUR', 'KBWI', 'KCHS', 'KCLE', 'KCLT', 'KCMH', 'KCVG', 'KDAL', 'KDCA', 'KDEN',
        'KDFW', 'KDTW', 'KEWR', 'KFLL', 'PHNL', 'KHOU', 'KIAD', 'KIAH', 'KIND', 'KJAX',
        'KJFK', 'KLAS', 'KLAX', 'KLGA', 'KLGB', 'KMCI', 'KMCO', 'KMDW', 'KMEM', 'KMIA',
        'KMKE', 'KMSP', 'KMSY', 'KOAK', 'PHOG', 'KOMA', 'KONT', 'KORD', 'KPBI', 'KPDX',
        'KPHL', 'KPHX', 'KPIT', 'KPVD', 'KRDU', 'KRIC', 'KRSW', 'KSAN', 'KSAT', 'KSDF',
        'KSEA', 'KSFO', 'KSJC', 'TJSJ', 'KSLC', 'KSMF', 'KSNA', 'KSTL', 'KTEB', 'KTPA',
        'KTUS',
    ],

    // Regional prefixes for Tier 2 (Canada, Latin America, Caribbean)
    'tier2_prefixes' => [
        'C',  // Canada (CYYZ, CYVR, etc.)
        'M',  // Mexico & Central America (MMMX, MMUN, etc.)
        'S',  // South America (SBGR, SCEL, etc.)
        'T',  // Caribbean (TNCM, TBPB, TFFR, etc.)
    ],

    // METAR update window (minutes before/after hour to boost to Tier 0)
    'metar_window_mins' => 5,
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
// ATIS PROCESSING FUNCTIONS
// ============================================================================

/**
 * Extract ATIS controllers from VATSIM data.
 */
function extractAtisFromJson(array $data): array {
    $atisList = [];

    foreach ($data['atis'] ?? [] as $atis) {
        $callsign = $atis['callsign'] ?? '';
        if (strpos(strtoupper($callsign), '_ATIS') === false) {
            continue;
        }

        // Parse airport and type from callsign
        $airport = '';
        $type = 'COMB';

        if (preg_match('/^([A-Z]{3,4})_(?:D|DEP)_ATIS$/i', $callsign, $m)) {
            $airport = strtoupper($m[1]);
            $type = 'DEP';
        } elseif (preg_match('/^([A-Z]{3,4})_(?:A|ARR)_ATIS$/i', $callsign, $m)) {
            $airport = strtoupper($m[1]);
            $type = 'ARR';
        } elseif (preg_match('/^([A-Z]{3,4})_ATIS$/i', $callsign, $m)) {
            $airport = strtoupper($m[1]);
            $type = 'COMB';
        }

        if (empty($airport)) continue;

        // Add K prefix for US 3-letter codes
        if (strlen($airport) === 3 && !preg_match('/^[PK]/', $airport)) {
            $airport = 'K' . $airport;
        }

        // Join text lines
        $textLines = $atis['text_atis'] ?? [];
        $text = is_array($textLines) ? implode(' ', $textLines) : '';

        // Extract ATIS code
        $code = null;
        if (preg_match('/INFO(?:RMATION)?\s+([A-Z])\b/i', $text, $m)) {
            $code = strtoupper($m[1]);
        } elseif (!empty($atis['atis_code'])) {
            $code = $atis['atis_code'];
        }

        $atisList[] = [
            'airport_icao' => $airport,
            'callsign' => $callsign,
            'atis_type' => $type,
            'atis_code' => $code,
            'frequency' => $atis['frequency'] ?? null,
            'atis_text' => $text,
            'controller_cid' => $atis['cid'] ?? null,
            'logon_time' => $atis['logon_time'] ?? null,
        ];
    }

    return $atisList;
}

/**
 * Detect weather conditions from ATIS text.
 * Returns: 'bad', 'clear', or 'normal'
 *
 * Bad weather indicators:
 * - Precipitation (RA, SN, TS, FZ, etc.)
 * - Low visibility (<3SM or <5000m)
 * - Low ceiling (BKN/OVC below 1000ft)
 * - Strong winds (>20kt or gusts)
 *
 * Clear weather indicators:
 * - SKC, CLR, CAVOK, FEW/SCT only
 * - Visibility 10SM+ or 9999
 * - No precipitation
 */
function detectWeatherCondition(string $atisText): string {
    $text = strtoupper($atisText);

    // Bad weather patterns
    $badPatterns = [
        // Precipitation
        '/\b(?:RA|SN|DZ|GR|GS|PL|IC|UP|SG|SS|DS)\b/',  // Rain, snow, drizzle, hail, etc.
        '/\b(?:\+|\-)?(?:TS|FZ|SH)/',  // Thunderstorm, freezing, showers
        '/\bVC(?:SH|TS)/',  // Vicinity showers/thunderstorms
        // Obscuration
        '/\b(?:FG|BR|HZ|FU|VA|DU|SA)\b/',  // Fog, mist, haze, smoke, volcanic ash, dust, sand
        // Low visibility (less than 3SM or 5000m)
        '/\b[012]SM\b/',  // 0-2 SM
        '/\b[0-4]\d{3}\b/',  // 0000-4999 meters
        '/\bM?1\/[24]SM\b/',  // 1/4SM, 1/2SM
        // Low ceiling
        '/\b(?:BKN|OVC)0(?:0[1-9]|10)\b/',  // BKN/OVC 100-1000ft (001-010)
        '/\bVV0(?:0[1-9]|10)\b/',  // Vertical visibility 100-1000ft
        // Strong winds
        '/\b\d{3}(?:2[5-9]|[3-9]\d)(?:G\d{2})?KT\b/',  // 25+ knots
        '/\b\d{3}\d{2}G\d{2}KT\b/',  // Any gusts
        // Specific conditions
        '/\bWIND\s*SHEAR\b/',
        '/\bMICROBURST\b/',
        '/\bLLWS\b/',  // Low-level wind shear
    ];

    foreach ($badPatterns as $pattern) {
        if (preg_match($pattern, $text)) {
            return 'bad';
        }
    }

    // Clear weather patterns (only if no bad weather found)
    $clearPatterns = [
        '/\b(?:SKC|CLR|CAVOK|NSC)\b/',  // Sky clear, CAVOK
        '/\b(?:10SM|P6SM)\b/',  // Good visibility
        '/\b9999\b/',  // CAVOK visibility
    ];

    // Check for only FEW/SCT clouds (no BKN/OVC)
    $hasClear = false;
    foreach ($clearPatterns as $pattern) {
        if (preg_match($pattern, $text)) {
            $hasClear = true;
            break;
        }
    }

    // If we have clear indicators AND no significant clouds
    if ($hasClear && !preg_match('/\b(?:BKN|OVC)\d{3}\b/', $text)) {
        return 'clear';
    }

    return 'normal';
}

/**
 * Check if current time is near METAR update (around top of hour).
 */
function isNearMetarUpdate(int $windowMins = 5): bool {
    $minute = (int)gmdate('i');
    // METARs typically update around :53-:00
    return ($minute >= (60 - $windowMins) || $minute <= $windowMins);
}

/**
 * Determine base tier for an airport.
 * Tier 1: ASPM77
 * Tier 2: Non-ASPM77 US + Canada/LatAm/Caribbean
 * Tier 3: All others (Europe, Asia, etc.)
 */
function getBaseTier(string $airport, array $config): int {
    // Check ASPM77
    if (in_array($airport, $config['aspm77'])) {
        return 1;
    }

    // Check regional prefixes (Canada, LatAm, Caribbean)
    $prefix = substr($airport, 0, 1);
    if (in_array($prefix, $config['tier2_prefixes'])) {
        return 2;
    }

    // Check if US airport (non-ASPM77)
    if ($prefix === 'K' || $prefix === 'P') {
        return 2;
    }

    // All others (Europe, Asia, etc.)
    return 3;
}

/**
 * Get effective tier with dynamic weather adjustments.
 *
 * Rules:
 * - Near METAR update time: ASPM77 -> Tier 0
 * - Bad weather + ASPM77: -> Tier 0
 * - Bad weather + NOT ASPM77: -> Tier 1
 * - Clear weather + Tier 3: -> Tier 4
 */
function getEffectiveTier(string $airport, string $atisText, array $config): int {
    $baseTier = getBaseTier($airport, $config);
    $weather = detectWeatherCondition($atisText);
    $isMetarTime = isNearMetarUpdate($config['metar_window_mins'] ?? 5);
    $isAspm77 = in_array($airport, $config['aspm77']);

    // Near METAR update: boost ASPM77 airports to Tier 0
    if ($isMetarTime && $isAspm77) {
        return 0;
    }

    // Bad weather adjustments
    if ($weather === 'bad') {
        if ($isAspm77) {
            return 0;  // ASPM77 with bad weather -> Tier 0
        } else {
            return min($baseTier, 1);  // Non-ASPM77 with bad weather -> at most Tier 1
        }
    }

    // Clear weather: downgrade Tier 3 to Tier 4
    if ($weather === 'clear' && $baseTier >= 3) {
        return 4;
    }

    return $baseTier;
}

/**
 * Get ATIS records to process for this cycle based on dynamic tiers.
 */
function getAtisForCycle(array $atisList, array $config, int $cycleNum): array {
    $filtered = [];
    $intervals = $config['atis_tier_intervals'];

    foreach ($atisList as $atis) {
        $airport = $atis['airport_icao'];
        $text = $atis['atis_text'] ?? '';

        // Determine effective tier for this airport
        $tier = getEffectiveTier($airport, $text, $config);
        $interval = $intervals[$tier] ?? $intervals[3];  // Default to Tier 3 interval

        // Check if this cycle should process this tier
        if ($cycleNum % $interval === 0) {
            $atis['_tier'] = $tier;  // Add tier info for logging
            $filtered[] = $atis;
        }
    }

    return $filtered;
}

/**
 * Process and import ATIS data.
 */
function processAtis($conn, array $atisList): array {
    if (empty($atisList)) {
        return ['imported' => 0, 'parsed' => 0];
    }

    $imported = 0;
    $parsed = 0;

    // Import ATIS records
    $json = json_encode($atisList);
    $sql = "EXEC dbo.sp_ImportVatsimAtis @json = ?";
    $stmt = @sqlsrv_query($conn, $sql, [&$json]);

    if ($stmt !== false) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC);
        $imported = $row[0] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // Parse pending ATIS
    $sql = "EXEC dbo.sp_GetPendingAtis @limit = 100";
    $stmt = @sqlsrv_query($conn, $sql);

    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $atisId = $row['atis_id'];
            $text = $row['atis_text'] ?? '';

            $result = parseAtisRunways($text);
            if (!empty($result['landing']) || !empty($result['departing'])) {
                $runwaysJson = runwaysToJson($result['landing'], $result['departing'], $result['approaches']);

                $sql2 = "EXEC dbo.sp_ImportRunwaysInUse @atis_id = ?, @runways_json = ?";
                $stmt2 = @sqlsrv_query($conn, $sql2, [&$atisId, &$runwaysJson]);
                if ($stmt2 !== false) {
                    $parsed++;
                    sqlsrv_free_stmt($stmt2);
                }
            }
        }
        sqlsrv_free_stmt($stmt);
    }

    return ['imported' => $imported, 'parsed' => $parsed];
}

// ============================================================================
// ATIS TIERED CLEANUP
// ============================================================================

/**
 * Run tiered ATIS cleanup.
 * Calls sp_CleanupOldAtis which uses tiered retention:
 *   Tier 0: Never delete (ASPM77 + event airports)
 *   Tier 1: 30 days (CA/MX/LATAM majors)
 *   Tier 2: 7 days (Global majors)
 *   Tier 3: 24 hours (Americas non-major)
 *   Tier 4: 1 hour (Global non-major)
 */
function runAtisCleanup($conn): ?array {
    $sql = "EXEC dbo.sp_CleanupOldAtis @dry_run = 0";
    $stmt = @sqlsrv_query($conn, $sql);

    if ($stmt === false) {
        return null;
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$row) {
        return null;
    }

    return [
        'tier1_30d' => $row['deleted_tier1_30d'] ?? 0,
        'tier2_7d'  => $row['deleted_tier2_7d'] ?? 0,
        'tier3_24h' => $row['deleted_tier3_24h'] ?? 0,
        'tier4_1h'  => $row['deleted_tier4_1h'] ?? 0,
        'total'     => $row['deleted_atis_total'] ?? 0,
        'history'   => $row['deleted_history'] ?? 0,
        'remaining' => $row['remaining_atis'] ?? 0,
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
        'total_atis'    => 0,
        'total_parsed'  => 0,
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
            
            // 4. Execute stored procedure for flights
            $spResult = executeRefreshSP($conn, $jsonData, $config['sp_timeout']);
            $spMs = $spResult['elapsed_ms'];

            // 5. Process ATIS (with dynamic tiered intervals)
            $atisImported = 0;
            $atisParsed = 0;
            if ($config['atis_enabled']) {
                // Decode JSON for ATIS extraction
                $vatsimData = json_decode($jsonData, true);
                if ($vatsimData) {
                    $allAtis = extractAtisFromJson($vatsimData);
                    $tieredAtis = getAtisForCycle($allAtis, $config, $stats['runs']);

                    if (!empty($tieredAtis)) {
                        $atisResult = processAtis($conn, $tieredAtis);
                        $atisImported = $atisResult['imported'];
                        $atisParsed = $atisResult['parsed'];
                    }
                }
                unset($vatsimData);
            }

            // Free memory
            unset($jsonData);

            // 6. Update stats
            $stats['successes']++;
            $stats['total_sp_ms'] += $spMs;
            $stats['total_flights'] += $pilotCount;
            $stats['total_atis'] += $atisImported;
            $stats['total_parsed'] += $atisParsed;
            if ($spMs > $stats['max_sp_ms']) {
                $stats['max_sp_ms'] = $spMs;
            }

            // 7. Log with performance level
            $logLevel = 'INFO';
            $perfNote = '';
            if ($spMs >= $config['critical_sp_ms']) {
                $logLevel = 'ERROR';
                $perfNote = ' [CRITICAL: >10s]';
            } elseif ($spMs >= $config['warn_sp_ms']) {
                $logLevel = 'WARN';
                $perfNote = ' [SLOW: >5s]';
            }
            
            $logContext = [
                'pilots'   => $pilotCount,
                'json_kb'  => $jsonSizeKb,
                'fetch_ms' => $fetchMs,
                'sp_ms'    => $spMs,
            ];

            // Add ATIS stats when processed this cycle
            if ($atisImported > 0 || $atisParsed > 0) {
                $logContext['atis'] = $atisImported;
                $logContext['parsed'] = $atisParsed;
            }

            logMessage($logLevel, "Refresh #{$stats['runs']}{$perfNote}", $logContext);
            
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
                'total_atis'    => $stats['total_atis'],
                'atis_parsed'   => $stats['total_parsed'],
            ]);

            // Run ATIS tiered cleanup every 100 cycles (~25 min)
            if ($conn !== null) {
                $cleanupResult = runAtisCleanup($conn);
                if ($cleanupResult !== null && $cleanupResult['total'] > 0) {
                    logInfo("ATIS cleanup completed", $cleanupResult);
                }
            }
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
