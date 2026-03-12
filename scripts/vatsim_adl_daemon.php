#!/usr/bin/env php
<?php
/**
 * VATSIM ADL Refresh Daemon
 * 
 * Location: wwwroot/scripts/vatsim_adl_daemon.php
 * 
 * Fetches VATSIM data every 15 seconds and calls sp_Adl_RefreshFromVatsim_Staged.
 * Optimized for 3,000-6,000 flights per cycle.
 *
 * V9.2.0: When defer_expensive=true, trajectory capture always runs in the SP
 * but ETA/snapshot steps are deferred to a time-budget system after the SP returns.
 * This ensures data ingestion completes within the 15s VATSIM API window.
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
require_once __DIR__ . '/swim_ws_events.php';

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
    // On Azure App Service, logs go to /home/LogFiles/ (startup.sh also redirects stdout there)
    // Locally, logs go to scripts/ directory
    'log_file'     => file_exists('/home/LogFiles') ? '/home/LogFiles/vatsim_adl.log' : $scriptDir . '/vatsim_adl.log',
    'log_to_file'  => true,
    'log_to_stdout'=> false,  // Disabled on Azure - stdout goes to same log file
    
    // Performance thresholds (for warnings)
    'warn_sp_ms'      => 5000,   // Warn if SP takes >5s
    'critical_sp_ms'  => 10000,  // Critical if SP takes >10s

    // TMI → ADL sync (propagates GDP/AFP/GS control data to ADL for SWIM + dashboards)
    // Automatically disabled in hibernation mode (no active TMI programs)
    'tmi_sync_enabled'  => !(defined('HIBERNATION_MODE') && HIBERNATION_MODE),
    'tmi_sync_interval' => 4,  // every 4 cycles = 60s

    // CTP Oceanic compliance check (runs alongside TMI sync, every 8 cycles = 120s)
    'ctp_compliance_enabled' => !(defined('HIBERNATION_MODE') && HIBERNATION_MODE),
    'ctp_compliance_interval' => 8,
    'tmi_db_server'     => defined('TMI_SQL_HOST') ? TMI_SQL_HOST : null,
    'tmi_db_name'       => defined('TMI_SQL_DATABASE') ? TMI_SQL_DATABASE : null,
    'tmi_db_user'       => defined('TMI_SQL_USERNAME') ? TMI_SQL_USERNAME : null,
    'tmi_db_pass'       => defined('TMI_SQL_PASSWORD') ? TMI_SQL_PASSWORD : null,

    // ATIS processing with dynamic tiered intervals
    // Automatically disabled in hibernation mode
    'atis_enabled'    => !(defined('HIBERNATION_MODE') && HIBERNATION_MODE),

    // Tier intervals (in 15-second cycles)
    // Tier 0: every 15s (1 cycle)  - METAR update time / bad weather ASPM82
    // Tier 1: every 1min (4 cycles) - ASPM82 normal weather
    // Tier 2: every 5min (20 cycles) - Non-ASPM82 + Canada + LatAm + Caribbean
    // Tier 3: every 30min (120 cycles) - All other airports
    // Tier 4: every 60min (240 cycles) - Clear weather non-priority airports
    'atis_tier_intervals' => [
        0 => 1,    // every 15s
        1 => 4,    // every 1min
        2 => 20,   // every 5min
        3 => 120,  // every 30min
        4 => 240,  // every 60min
    ],

    // ASPM82 airports (FAA Aviation System Performance Metrics)
    'aspm82' => [
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

    // Boundary & Crossings background processing
    // SP V1.6 uses set-based processing (not per-flight cursor), much faster than legacy
    // Capacity planning (grid changes ~1 per 4min per flight):
    //   1000 flights = 250/min needed, 3000 = 750/min, 5000 = 1250/min, 6000+ events = 1500+/min
    // DISABLED: Now runs in separate boundary_daemon.php for better throughput
    // Adaptive intervals: runs more often when backlog exists, less often when caught up
    'boundary_enabled'       => false,
    'boundary_interval_fast' => 2,    // Run every 2 cycles (30s) when pending > threshold
    'boundary_interval_slow' => 4,    // Run every 4 cycles (60s) when caught up
    'boundary_adaptive_threshold' => 500, // Switch to fast mode when pending > this
    'boundary_max_flights'   => 100,  // Reduced from 2000 - prevents timeout
    'crossings_max_flights'  => 50,   // Reduced from 150 - prevents timeout
    'boundary_timeout'       => 120,  // SP timeout in seconds

    // Runway detection from flight tracks
    // Analyzes recent flight tracks to detect active runways when no ATIS is available
    'runway_detection_enabled'  => true,
    'runway_detection_interval' => 120,   // Run every N cycles (120 = every 30 minutes)
    'runway_detection_timeout'  => 60,    // SP timeout in seconds

    // Wind adjustment calculation (tiered, decoupled from ETA)
    // Runs on separate timer to avoid slowing down main ADL refresh
    // Uses tiered intervals internally: Tier 0 (30s) to Tier 4 (10min) based on flight relevance
    'wind_enabled'        => true,
    'wind_interval'       => 2,      // Run every N cycles (2 = every 30 seconds)
    'wind_timeout'        => 90,     // SP timeout in seconds (increased from 30 to handle large flight counts)

    // SWIM API sync (syncs flight data to SWIM_API database for public API)
    // Primary path is swim_sync_daemon.php; inline SWIM is fallback only.
    'swim_enabled'               => defined('SWIM_SQL_HOST'),  // Auto-enable if SWIM config exists
    'swim_interval'              => 8,      // Run every N cycles (8 = every 2 minutes)
    'swim_inline_enabled'        => getenv('ADL_SWIM_INLINE') === '1',  // Force inline SWIM in ADL loop
    'swim_inline_fallback_enabled' => true, // Run inline only if daemon heartbeat is stale/missing
    'swim_daemon_heartbeat_file' => sys_get_temp_dir() . '/swim_sync_daemon.heartbeat',
    'swim_daemon_stale_sec'      => 300,    // Consider daemon unhealthy after 5 min without heartbeat

    // Zone Detection (OOOI detection at airports)
    // Set to true when zone_daemon.php is running separately
    // This skips zone detection in the main refresh SP to avoid duplicate processing
    'zone_daemon_enabled' => false,  // Set to true when zone_daemon.php is running

    // WebSocket real-time events
    // Publishes flight events to connected WebSocket clients after each refresh
    'websocket_enabled'   => true,
    'websocket_positions' => false,  // Position updates (high volume - disabled by default)

    // Event position logging (TMI compliance analysis)
    // Captures controller positions during active event logging windows
    // Data stored in event_position_log table, linked to perti_events
    'event_logging_enabled' => true,
    'event_logging_interval' => 4,    // Check every N cycles (4 = every 60s during events)

    // Flight stats scheduled jobs
    // Calls sp_ProcessFlightStatsJobs to run hourly/daily/monthly aggregation jobs
    // The SP checks job schedules internally and only runs jobs that are due
    'flight_stats_enabled'  => true,
    'flight_stats_interval' => 60,   // Run every N cycles (60 = every 15 minutes)

    // =============================================
    // V9.0 Staged Refresh Architecture
    // =============================================
    // When enabled, JSON is parsed in PHP and inserted into staging tables,
    // then SP reads from staging tables instead of using OPENJSON.
    // This shifts ~3-5s of SQL compute to fixed-cost PHP, saving ~50% on Serverless costs.
    'staged_refresh_enabled' => true,  // Enable PHP-side JSON parsing
    'staged_batch_size'      => 70,    // Rows per INSERT batch (max ~72 due to 2100 param limit)

    // V9.2 Bulk Literal Mode - use single INSERT statements with literal values
    // Reduces ~43 round trips to ~3 round trips for ~3000 pilots (1000 rows per INSERT)
    // No parameters = no 2100 limit, faster than parameterized batches
    'use_tvp'                => true,  // Use bulk literal for staging inserts (faster)

    // =============================================
    // V9.2 Deferred Expensive Processing
    // =============================================
    // When enabled, the SP defers ETA calculation and snapshot steps.
    // Trajectory position capture ALWAYS runs (ephemeral data).
    // Deferred steps run after SP returns, only when cycle time budget allows.
    // Saves ~800ms per cycle, reducing missed VATSIM feeds from ~38% to ~15-20%.
    'defer_expensive'       => true,   // Defer ETA/snapshot steps, always capture trajectory
    'deferred_eta_interval' => 2,      // Run wind-adjusted batch ETA every N cycles when budget allows
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

/**
 * Connect to VATSIM_TMI database (read-only) for TMI→ADL sync.
 * Returns null if TMI config is missing (graceful degradation).
 */
function getTmiConnection(array $config) {
    if (!$config['tmi_db_server'] || !$config['tmi_db_name']) {
        return null;
    }

    $conn = sqlsrv_connect($config['tmi_db_server'], [
        "Database"                => $config['tmi_db_name'],
        "Uid"                     => $config['tmi_db_user'],
        "PWD"                     => $config['tmi_db_pass'],
        "Encrypt"                 => true,
        "TrustServerCertificate"  => false,
        "LoginTimeout"            => 15,
        "ConnectionPooling"       => true,
        "MultipleActiveResultSets" => false,
        "ApplicationIntent"       => "ReadOnly",
    ]);

    if ($conn === false) {
        logWarn("TMI connection failed", ['errors' => sqlsrv_errors()]);
        return null;
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

    // V8.9: Capture the result set with step timings
    $result = [
        'success'    => true,
        'elapsed_ms' => 0,
        'stats'      => null,
        'steps'      => null,
    ];

    // Sub-procedures may return result sets before the main SP's final SELECT.
    // Iterate through all result sets and find the one with step timing columns.
    do {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row && isset($row['pilots_received']) && isset($row['step1_json_ms'])) {
            // This is the V8.9 result set with step timings
            $result['stats'] = [
                'pilots'      => $row['pilots_received'] ?? 0,
                'heartbeat'   => $row['heartbeat_flights'] ?? 0,
                'new'         => $row['new_flights'] ?? 0,
                'updated'     => $row['updated_flights'] ?? 0,
                'pos_ins'     => $row['positions_inserted'] ?? 0,
                'pos_upd'     => $row['positions_updated'] ?? 0,
                'routes'      => $row['routes_queued'] ?? 0,
                'etds'        => $row['etds_calculated'] ?? 0,
                'etas'        => $row['etas_calculated'] ?? 0,
                'traj'        => $row['trajectories_logged'] ?? 0,
                'zones'       => $row['zone_transitions'] ?? 0,
                'boundaries'  => $row['boundary_transitions'] ?? 0,
                'crossings'   => $row['crossings_calculated'] ?? 0,
            ];

            $result['steps'] = [
                '1_json'      => $row['step1_json_ms'] ?? 0,
                '1b_enrich'   => $row['step1b_enrich_ms'] ?? 0,
                '2_core'      => $row['step2_core_ms'] ?? 0,
                '2a_prefile'  => $row['step2a_prefile_ms'] ?? 0,
                '2b_times'    => $row['step2b_times_ms'] ?? 0,
                '3_position'  => $row['step3_position_ms'] ?? 0,
                '4_flightplan'=> $row['step4_flightplan_ms'] ?? 0,
                '4b_etd'      => $row['step4b_etd_ms'] ?? 0,
                '4c_simbrief' => $row['step4c_simbrief_ms'] ?? 0,
                '5_queue'     => $row['step5_queue_ms'] ?? 0,
                '5b_routedist'=> $row['step5b_routedist_ms'] ?? 0,
                '6_aircraft'  => $row['step6_aircraft_ms'] ?? 0,
                '7_inactive'  => $row['step7_inactive_ms'] ?? 0,
                '8_trajectory'=> $row['step8_trajectory_ms'] ?? 0,
                '8b_bucket'   => $row['step8b_bucket_ms'] ?? 0,
                '8c_waypoint' => $row['step8c_waypoint_ms'] ?? 0,
                '8d_eta'      => $row['step8d_batch_eta_ms'] ?? 0,
                '9_zone'      => $row['step9_zone_ms'] ?? 0,
                '10_boundary' => $row['step10_boundary_ms'] ?? 0,
                '11_crossings'=> $row['step11_crossings_ms'] ?? 0,
                '12_log'      => $row['step12_log_ms'] ?? 0,
                '13_snapshot' => $row['step13_snapshot_ms'] ?? 0,
            ];

            $result['elapsed_ms'] = $row['elapsed_ms'] ?? 0;
            break;  // Found it, stop looking
        }
    } while (sqlsrv_next_result($stmt));

    // Drain any remaining result sets
    while (sqlsrv_next_result($stmt)) {
        // Drain
    }

    sqlsrv_free_stmt($stmt);

    // Fallback to PHP timing if SP didn't return elapsed_ms
    if ($result['elapsed_ms'] == 0) {
        $result['elapsed_ms'] = round((microtime(true) - $startTime) * 1000);
    }

    return $result;
}

// ============================================================================
// V9.0 STAGED REFRESH FUNCTIONS
// PHP parses JSON and inserts to staging tables, then SP reads from staging
// ============================================================================

/**
 * Parse pilots from VATSIM JSON into structured arrays.
 * @param array $vatsimData Decoded VATSIM JSON
 * @return array Array of pilot records ready for staging insert
 */
function parseVatsimPilots(array $vatsimData): array {
    $pilots = [];

    foreach ($vatsimData['pilots'] ?? [] as $p) {
        $fp = $p['flight_plan'] ?? [];

        // Build flight_key: cid|callsign|dept|dest|deptime
        $flightKey = ($p['cid'] ?? '') . '|' .
                     ($p['callsign'] ?? '') . '|' .
                     ($fp['departure'] ?? '') . '|' .
                     ($fp['arrival'] ?? '') . '|' .
                     ($fp['deptime'] ?? '');

        // Calculate route_hash (SHA2-256)
        $routeHashInput = ($fp['route'] ?? '') . '|' . ($fp['remarks'] ?? '');
        $routeHash = hash('sha256', $routeHashInput, true); // Binary

        // Extract airline ICAO from callsign
        $callsign = $p['callsign'] ?? '';
        $airlineIcao = null;
        if (strlen($callsign) >= 4 && preg_match('/^[A-Z]{3}[0-9]/', $callsign)) {
            $airlineIcao = substr($callsign, 0, 3);
        }

        $pilots[] = [
            'cid' => (int)($p['cid'] ?? 0),
            'callsign' => substr($callsign, 0, 16),
            'lat' => isset($p['latitude']) ? (float)$p['latitude'] : null,
            'lon' => isset($p['longitude']) ? (float)$p['longitude'] : null,
            'altitude_ft' => isset($p['altitude']) ? (int)$p['altitude'] : null,
            'groundspeed_kts' => isset($p['groundspeed']) ? (int)$p['groundspeed'] : null,
            'heading_deg' => isset($p['heading']) ? (int)$p['heading'] : null,
            'qnh_in_hg' => isset($p['qnh_i_hg']) ? (float)$p['qnh_i_hg'] : null,
            'qnh_mb' => isset($p['qnh_mb']) ? (int)$p['qnh_mb'] : null,
            'flight_server' => isset($p['server']) ? substr($p['server'], 0, 32) : null,
            'logon_time' => $p['logon_time'] ?? null,
            'fp_rule' => isset($fp['flight_rules']) ? substr($fp['flight_rules'], 0, 1) : null,
            'dept_icao' => isset($fp['departure']) ? substr($fp['departure'], 0, 4) : null,
            'dest_icao' => isset($fp['arrival']) ? substr($fp['arrival'], 0, 4) : null,
            'alt_icao' => isset($fp['alternate']) ? substr($fp['alternate'], 0, 4) : null,
            'route' => $fp['route'] ?? null,
            'remarks' => $fp['remarks'] ?? null,
            'altitude_filed_raw' => isset($fp['altitude']) ? substr($fp['altitude'], 0, 16) : null,
            'tas_filed_raw' => isset($fp['cruise_tas']) ? substr($fp['cruise_tas'], 0, 16) : null,
            'dep_time_z' => isset($fp['deptime']) ? substr($fp['deptime'], 0, 4) : null,
            'enroute_time_raw' => isset($fp['enroute_time']) ? substr($fp['enroute_time'], 0, 8) : null,
            'fuel_time_raw' => isset($fp['fuel_time']) ? substr($fp['fuel_time'], 0, 8) : null,
            'aircraft_faa_raw' => isset($fp['aircraft_faa']) ? substr($fp['aircraft_faa'], 0, 32) : null,
            'aircraft_short' => isset($fp['aircraft_short']) ? substr($fp['aircraft_short'], 0, 8) : null,
            'fp_dof_raw' => isset($fp['dof']) ? substr($fp['dof'], 0, 16) : null,
            'flight_key' => $flightKey,
            'route_hash' => $routeHash,
            'airline_icao' => $airlineIcao,
        ];
    }

    return $pilots;
}

/**
 * Parse prefiles from VATSIM JSON into structured arrays.
 * @param array $vatsimData Decoded VATSIM JSON
 * @return array Array of prefile records ready for staging insert
 */
function parseVatsimPrefiles(array $vatsimData): array {
    $prefiles = [];

    foreach ($vatsimData['prefiles'] ?? [] as $pf) {
        $fp = $pf['flight_plan'] ?? [];

        if (empty($pf['callsign'])) continue;

        // Build flight_key
        $flightKey = ($pf['cid'] ?? '') . '|' .
                     ($pf['callsign'] ?? '') . '|' .
                     ($fp['departure'] ?? '') . '|' .
                     ($fp['arrival'] ?? '') . '|' .
                     ($fp['deptime'] ?? '');

        // Calculate route_hash (MD5 for prefiles, matching SP)
        $routeHashInput = $fp['route'] ?? '';
        $routeHash = md5($routeHashInput, true); // Binary

        $prefiles[] = [
            'cid' => (int)($pf['cid'] ?? 0),
            'callsign' => substr($pf['callsign'] ?? '', 0, 16),
            'fp_rule' => isset($fp['flight_rules']) ? substr($fp['flight_rules'], 0, 1) : null,
            'dept_icao' => isset($fp['departure']) ? substr($fp['departure'], 0, 4) : null,
            'dest_icao' => isset($fp['arrival']) ? substr($fp['arrival'], 0, 4) : null,
            'alt_icao' => isset($fp['alternate']) ? substr($fp['alternate'], 0, 4) : null,
            'route' => $fp['route'] ?? null,
            'remarks' => $fp['remarks'] ?? null,
            'altitude_filed_raw' => isset($fp['altitude']) ? substr($fp['altitude'], 0, 16) : null,
            'tas_filed_raw' => isset($fp['cruise_tas']) ? substr($fp['cruise_tas'], 0, 16) : null,
            'dep_time_z' => isset($fp['deptime']) ? substr($fp['deptime'], 0, 4) : null,
            'enroute_time_raw' => isset($fp['enroute_time']) ? substr($fp['enroute_time'], 0, 8) : null,
            'aircraft_faa_raw' => isset($fp['aircraft_faa']) ? substr($fp['aircraft_faa'], 0, 32) : null,
            'aircraft_short' => isset($fp['aircraft_short']) ? substr($fp['aircraft_short'], 0, 8) : null,
            'flight_key' => $flightKey,
            'route_hash' => $routeHash,
        ];
    }

    return $prefiles;
}

/**
 * Clear staging tables and insert pilots in batches.
 * @param resource $conn SQL Server connection
 * @param array $pilots Parsed pilot records
 * @param string $batchId UUID for this batch
 * @param int $batchSize Rows per INSERT statement
 * @return int Number of rows inserted
 */
function insertPilotsToStaging($conn, array $pilots, string $batchId, int $batchSize = 500): int {
    if (empty($pilots)) return 0;

    $inserted = 0;
    $batches = array_chunk($pilots, $batchSize);

    foreach ($batches as $batch) {
        $values = [];
        $params = [];

        foreach ($batch as $p) {
            $placeholders = [];

            // Regular fields
            $fields = [
                'cid', 'callsign', 'lat', 'lon', 'altitude_ft', 'groundspeed_kts',
                'heading_deg', 'qnh_in_hg', 'qnh_mb', 'flight_server', 'logon_time',
                'fp_rule', 'dept_icao', 'dest_icao', 'alt_icao', 'route', 'remarks',
                'altitude_filed_raw', 'tas_filed_raw', 'dep_time_z', 'enroute_time_raw',
                'fuel_time_raw', 'aircraft_faa_raw', 'aircraft_short', 'fp_dof_raw',
                'flight_key'
            ];

            foreach ($fields as $field) {
                $placeholders[] = '?';
                $params[] = $p[$field];
            }

            // route_hash is binary - convert to hex for SQL CONVERT
            $placeholders[] = 'CONVERT(VARBINARY(32), ?, 2)';
            $params[] = $p['route_hash'] !== null ? bin2hex($p['route_hash']) : null;

            // airline_icao
            $placeholders[] = '?';
            $params[] = $p['airline_icao'];

            // batch_id
            $placeholders[] = '?';
            $params[] = $batchId;

            $values[] = '(' . implode(',', $placeholders) . ')';
        }

        $sql = "INSERT INTO dbo.adl_staging_pilots (
            cid, callsign, lat, lon, altitude_ft, groundspeed_kts,
            heading_deg, qnh_in_hg, qnh_mb, flight_server, logon_time,
            fp_rule, dept_icao, dest_icao, alt_icao, route, remarks,
            altitude_filed_raw, tas_filed_raw, dep_time_z, enroute_time_raw,
            fuel_time_raw, aircraft_faa_raw, aircraft_short, fp_dof_raw,
            flight_key, route_hash, airline_icao, batch_id
        ) VALUES " . implode(',', $values);

        $stmt = sqlsrv_query($conn, $sql, $params);

        if ($stmt === false) {
            $errors = sqlsrv_errors();
            throw new Exception("Staging insert failed: " . json_encode($errors));
        }

        $inserted += sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);
    }

    return $inserted;
}

/**
 * Insert prefiles into staging table.
 */
function insertPrefilesToStaging($conn, array $prefiles, string $batchId, int $batchSize = 500): int {
    if (empty($prefiles)) return 0;

    $inserted = 0;
    $batches = array_chunk($prefiles, $batchSize);

    foreach ($batches as $batch) {
        $values = [];
        $params = [];

        foreach ($batch as $pf) {
            $placeholders = [];

            // Regular fields (excluding route_hash)
            $fields = [
                'cid', 'callsign', 'fp_rule', 'dept_icao', 'dest_icao', 'alt_icao',
                'route', 'remarks', 'altitude_filed_raw', 'tas_filed_raw',
                'dep_time_z', 'enroute_time_raw', 'aircraft_faa_raw', 'aircraft_short',
                'flight_key'
            ];

            foreach ($fields as $field) {
                $placeholders[] = '?';
                $params[] = $pf[$field];
            }

            // route_hash is binary (MD5 = 16 bytes) - convert to hex for SQL CONVERT
            $placeholders[] = 'CONVERT(VARBINARY(32), ?, 2)';
            $params[] = $pf['route_hash'] !== null ? bin2hex($pf['route_hash']) : null;

            // batch_id
            $placeholders[] = '?';
            $params[] = $batchId;

            $values[] = '(' . implode(',', $placeholders) . ')';
        }

        $sql = "INSERT INTO dbo.adl_staging_prefiles (
            cid, callsign, fp_rule, dept_icao, dest_icao, alt_icao,
            route, remarks, altitude_filed_raw, tas_filed_raw,
            dep_time_z, enroute_time_raw, aircraft_faa_raw, aircraft_short,
            flight_key, route_hash, batch_id
        ) VALUES " . implode(',', $values);

        $stmt = sqlsrv_query($conn, $sql, $params);

        if ($stmt === false) {
            $errors = sqlsrv_errors();
            throw new Exception("Prefile staging insert failed: " . json_encode($errors));
        }

        $inserted += sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);
    }

    return $inserted;
}

/**
 * Clear staging tables before new batch.
 */
function clearStagingTables($conn): void {
    $stmt = sqlsrv_query($conn, "EXEC dbo.sp_ClearStagingTables");
    if ($stmt !== false) {
        sqlsrv_free_stmt($stmt);
    }
}

// ============================================================================
// V9.2 BULK LITERAL INSERT FUNCTIONS
// Uses single INSERT statements with literal values (no parameters)
// O(1) round trips per batch - much faster than parameterized batches
// Safe: uses proper SQL escaping to prevent injection
// ============================================================================

/**
 * Escape a string value for SQL Server literal insertion.
 * Returns N'escaped_value' or NULL.
 */
function sqlEscapeString(?string $value): string {
    if ($value === null) {
        return 'NULL';
    }
    // Escape single quotes by doubling them
    $escaped = str_replace("'", "''", $value);
    return "N'" . $escaped . "'";
}

/**
 * Format a numeric value for SQL literal insertion.
 */
function sqlEscapeNumber($value, bool $isInt = false): string {
    if ($value === null) {
        return 'NULL';
    }
    if ($isInt) {
        return (string)(int)$value;
    }
    return (string)(float)$value;
}

/**
 * Format a binary value (route_hash) for SQL insertion.
 * Uses CONVERT(VARBINARY(32), '...', 2) with hex string.
 */
function sqlEscapeBinary(?string $binaryData): string {
    if ($binaryData === null) {
        return 'NULL';
    }
    $hex = bin2hex($binaryData);
    return "CONVERT(VARBINARY(32), '{$hex}', 2)";
}

/**
 * Insert pilots to staging using bulk literal INSERT (O(1) per batch).
 * No parameters = no 2100 limit, much faster than parameterized batches.
 * @param resource $conn SQL Server connection
 * @param array $pilots Parsed pilot records
 * @param string $batchId UUID for this batch
 * @param int $batchSize Rows per INSERT statement (default 1000)
 * @return array ['inserted' => count, 'method' => 'bulk']
 */
function insertPilotsBulkLiteral($conn, array $pilots, string $batchId, int $batchSize = 1000): array {
    if (empty($pilots)) return ['inserted' => 0, 'method' => 'bulk'];

    clearStagingTables($conn);

    $inserted = 0;
    $batches = array_chunk($pilots, $batchSize);
    $escapedBatchId = sqlEscapeString($batchId);

    foreach ($batches as $batch) {
        $valuesClauses = [];

        foreach ($batch as $p) {
            $values = [
                sqlEscapeNumber($p['cid'], true),
                sqlEscapeString($p['callsign']),
                sqlEscapeNumber($p['lat'], false),
                sqlEscapeNumber($p['lon'], false),
                sqlEscapeNumber($p['altitude_ft'], true),
                sqlEscapeNumber($p['groundspeed_kts'], true),
                sqlEscapeNumber($p['heading_deg'], true),
                sqlEscapeNumber($p['qnh_in_hg'], false),
                sqlEscapeNumber($p['qnh_mb'], true),
                sqlEscapeString($p['flight_server']),
                sqlEscapeString($p['logon_time']),
                sqlEscapeString($p['fp_rule']),
                sqlEscapeString($p['dept_icao']),
                sqlEscapeString($p['dest_icao']),
                sqlEscapeString($p['alt_icao']),
                sqlEscapeString($p['route']),
                sqlEscapeString($p['remarks']),
                sqlEscapeString($p['altitude_filed_raw']),
                sqlEscapeString($p['tas_filed_raw']),
                sqlEscapeString($p['dep_time_z']),
                sqlEscapeString($p['enroute_time_raw']),
                sqlEscapeString($p['fuel_time_raw']),
                sqlEscapeString($p['aircraft_faa_raw']),
                sqlEscapeString($p['aircraft_short']),
                sqlEscapeString($p['fp_dof_raw']),
                sqlEscapeString($p['flight_key']),
                sqlEscapeBinary($p['route_hash']),
                sqlEscapeString($p['airline_icao']),
                sqlEscapeNumber($p['change_flags'] ?? 15, true),
                $escapedBatchId,
            ];

            $valuesClauses[] = '(' . implode(',', $values) . ')';
        }

        $sql = "INSERT INTO dbo.adl_staging_pilots (
            cid, callsign, lat, lon, altitude_ft, groundspeed_kts,
            heading_deg, qnh_in_hg, qnh_mb, flight_server, logon_time,
            fp_rule, dept_icao, dest_icao, alt_icao, route, remarks,
            altitude_filed_raw, tas_filed_raw, dep_time_z, enroute_time_raw,
            fuel_time_raw, aircraft_faa_raw, aircraft_short, fp_dof_raw,
            flight_key, route_hash, airline_icao, change_flags, batch_id
        ) VALUES " . implode(',', $valuesClauses);

        $stmt = sqlsrv_query($conn, $sql);

        if ($stmt === false) {
            $errors = sqlsrv_errors();
            throw new Exception("Bulk pilot insert failed: " . json_encode($errors));
        }

        $inserted += sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);
    }

    return ['inserted' => $inserted, 'method' => 'bulk'];
}

/**
 * Insert prefiles to staging using bulk literal INSERT (O(1) per batch).
 */
function insertPrefilesBulkLiteral($conn, array $prefiles, string $batchId, int $batchSize = 1000): array {
    if (empty($prefiles)) return ['inserted' => 0, 'method' => 'bulk'];

    $inserted = 0;
    $batches = array_chunk($prefiles, $batchSize);
    $escapedBatchId = sqlEscapeString($batchId);

    foreach ($batches as $batch) {
        $valuesClauses = [];

        foreach ($batch as $pf) {
            $values = [
                sqlEscapeNumber($pf['cid'], true),
                sqlEscapeString($pf['callsign']),
                sqlEscapeString($pf['fp_rule']),
                sqlEscapeString($pf['dept_icao']),
                sqlEscapeString($pf['dest_icao']),
                sqlEscapeString($pf['alt_icao']),
                sqlEscapeString($pf['route']),
                sqlEscapeString($pf['remarks']),
                sqlEscapeString($pf['altitude_filed_raw']),
                sqlEscapeString($pf['tas_filed_raw']),
                sqlEscapeString($pf['dep_time_z']),
                sqlEscapeString($pf['enroute_time_raw']),
                sqlEscapeString($pf['aircraft_faa_raw']),
                sqlEscapeString($pf['aircraft_short']),
                sqlEscapeString($pf['flight_key']),
                sqlEscapeBinary($pf['route_hash']),
                $escapedBatchId,
            ];

            $valuesClauses[] = '(' . implode(',', $values) . ')';
        }

        $sql = "INSERT INTO dbo.adl_staging_prefiles (
            cid, callsign, fp_rule, dept_icao, dest_icao, alt_icao,
            route, remarks, altitude_filed_raw, tas_filed_raw,
            dep_time_z, enroute_time_raw, aircraft_faa_raw, aircraft_short,
            flight_key, route_hash, batch_id
        ) VALUES " . implode(',', $valuesClauses);

        $stmt = sqlsrv_query($conn, $sql);

        if ($stmt === false) {
            $errors = sqlsrv_errors();
            throw new Exception("Bulk prefile insert failed: " . json_encode($errors));
        }

        $inserted += sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);
    }

    return ['inserted' => $inserted, 'method' => 'bulk'];
}

/**
 * Execute the staged refresh SP (reads from staging tables).
 * @param resource $conn SQL Server connection
 * @param string $batchId UUID for this batch
 * @param int $timeout Query timeout in seconds
 * @param bool $skipZoneDetection Set to true when zone_daemon.php is running
 * @param bool $deferExpensive Set to true to defer ETA/snapshot steps (trajectory always captured)
 * @return array Result with stats and timings
 */
function executeStagedRefreshSP($conn, string $batchId, int $timeout, bool $skipZoneDetection = false, bool $deferExpensive = false): array {
    $startTime = microtime(true);

    $skipZone = $skipZoneDetection ? 1 : 0;
    $defer = $deferExpensive ? 1 : 0;
    $sql = "EXEC [dbo].[sp_Adl_RefreshFromVatsim_Staged] @batch_id = ?, @skip_zone_detection = ?, @defer_expensive = ?";
    $options = ['QueryTimeout' => $timeout];

    $stmt = sqlsrv_query($conn, $sql, [$batchId, $skipZone, $defer], $options);

    if ($stmt === false) {
        $errors = sqlsrv_errors();
        throw new Exception("Staged SP execution failed: " . json_encode($errors));
    }

    $result = [
        'success'    => true,
        'elapsed_ms' => 0,
        'stats'      => null,
        'steps'      => null,
    ];

    // Read result set (same structure as original SP)
    do {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row && isset($row['pilots_received']) && isset($row['step1_json_ms'])) {
            $result['stats'] = [
                'pilots'      => $row['pilots_received'] ?? 0,
                'heartbeat'   => $row['heartbeat_flights'] ?? 0,
                'new'         => $row['new_flights'] ?? 0,
                'updated'     => $row['updated_flights'] ?? 0,
                'pos_ins'     => $row['positions_inserted'] ?? 0,
                'pos_upd'     => $row['positions_updated'] ?? 0,
                'routes'      => $row['routes_queued'] ?? 0,
                'etds'        => $row['etds_calculated'] ?? 0,
                'etas'        => $row['etas_calculated'] ?? 0,
                'traj'        => $row['trajectories_logged'] ?? 0,
                'zones'       => $row['zone_transitions'] ?? 0,
                'boundaries'  => $row['boundary_transitions'] ?? 0,
                'crossings'   => $row['crossings_calculated'] ?? 0,
            ];

            $result['steps'] = [
                '1_staging'   => $row['step1_json_ms'] ?? 0,  // Now staging read
                '1b_enrich'   => $row['step1b_enrich_ms'] ?? 0,
                '2_core'      => $row['step2_core_ms'] ?? 0,
                '2a_prefile'  => $row['step2a_prefile_ms'] ?? 0,
                '2b_times'    => $row['step2b_times_ms'] ?? 0,
                '3_position'  => $row['step3_position_ms'] ?? 0,
                '4_flightplan'=> $row['step4_flightplan_ms'] ?? 0,
                '4b_etd'      => $row['step4b_etd_ms'] ?? 0,
                '4c_simbrief' => $row['step4c_simbrief_ms'] ?? 0,
                '5_queue'     => $row['step5_queue_ms'] ?? 0,
                '5b_routedist'=> $row['step5b_routedist_ms'] ?? 0,
                '6_aircraft'  => $row['step6_aircraft_ms'] ?? 0,
                '7_inactive'  => $row['step7_inactive_ms'] ?? 0,
                '8_trajectory'=> $row['step8_trajectory_ms'] ?? 0,
                '8b_bucket'   => $row['step8b_bucket_ms'] ?? 0,
                '8c_waypoint' => $row['step8c_waypoint_ms'] ?? 0,
                '8d_eta'      => $row['step8d_batch_eta_ms'] ?? 0,
                '9_zone'      => $row['step9_zone_ms'] ?? 0,
                '10_boundary' => $row['step10_boundary_ms'] ?? 0,
                '11_crossings'=> $row['step11_crossings_ms'] ?? 0,
                '12_log'      => $row['step12_log_ms'] ?? 0,
                '13_snapshot' => $row['step13_snapshot_ms'] ?? 0,
            ];

            $result['elapsed_ms'] = $row['elapsed_ms'] ?? 0;
            break;
        }
    } while (sqlsrv_next_result($stmt));

    while (sqlsrv_next_result($stmt)) {
        // Drain remaining results
    }

    sqlsrv_free_stmt($stmt);

    if ($result['elapsed_ms'] == 0) {
        $result['elapsed_ms'] = round((microtime(true) - $startTime) * 1000);
    }

    return $result;
}

// ============================================================================
// DELTA DETECTION (V9.3.0)
// ============================================================================

/**
 * Compare current pilot data against previous cycle to detect changes.
 *
 * Returns a bitmask:
 *   1 = POSITION_CHANGED (lat, lon, altitude, groundspeed, heading)
 *   2 = PLAN_CHANGED     (route_hash, altitude, TAS, rule, aircraft, remarks)
 *   4 = NEW_FLIGHT       (flight_key not in previous cycle)
 *   0 = Heartbeat        (everything identical — skip expensive processing)
 *
 * Position uses loose comparison (!=) for float tolerance.
 * Integers and strings use strict comparison (!==).
 */
function computeChangeFlags(array $current, ?array $previous): int {
    if ($previous === null) {
        return 7; // NEW_FLIGHT | PLAN_CHANGED | POSITION_CHANGED
    }

    $flags = 0;

    // Position: exact match (zero threshold) for robust state tracking
    if (($current['lat'] ?? 0) != ($previous['lat'] ?? 0)
        || ($current['lon'] ?? 0) != ($previous['lon'] ?? 0)
        || ($current['altitude_ft'] ?? 0) !== ($previous['altitude_ft'] ?? 0)
        || ($current['groundspeed_kts'] ?? 0) !== ($previous['groundspeed_kts'] ?? 0)
        || ($current['heading_deg'] ?? 0) !== ($previous['heading_deg'] ?? 0)
    ) {
        $flags |= 1; // POSITION_CHANGED
    }

    // Plan: compare ALL plan-relevant fields (not just route hash)
    // Catches: route change, altitude amendment, TAS change, aircraft swap, rule change, remarks edit
    if (($current['route_hash'] ?? '') !== ($previous['route_hash'] ?? '')
        || ($current['altitude_filed_raw'] ?? '') !== ($previous['altitude_filed_raw'] ?? '')
        || ($current['tas_filed_raw'] ?? '') !== ($previous['tas_filed_raw'] ?? '')
        || ($current['fp_rule'] ?? '') !== ($previous['fp_rule'] ?? '')
        || ($current['aircraft_faa_raw'] ?? '') !== ($previous['aircraft_faa_raw'] ?? '')
        || ($current['remarks'] ?? '') !== ($previous['remarks'] ?? '')
    ) {
        $flags |= 2; // PLAN_CHANGED
    }

    return $flags;
}

/**
 * Execute deferred expensive processing (ETA calculations, legacy log, snapshot).
 * Called after the main SP when @defer_expensive is enabled.
 * Trajectory capture always happens in the SP - this only handles ETA and snapshots.
 * Uses remaining cycle time budget to decide what to run.
 *
 * @param resource $conn SQL Server connection
 * @param array $config Daemon config
 * @param array &$stats Running stats (modified in place)
 * @param float $cycleStart microtime(true) of cycle start
 * @return array Results with timing and counts
 */
function executeDeferredProcessing($conn, array $config, array &$stats, float $cycleStart): array {
    $result = [
        'eta_basic' => null,
        'eta_batch' => null,
        'log' => null,
        'snapshot' => null,
        'elapsed_ms' => 0,
        'skipped' => false,
    ];

    $cycleElapsedMs = round((microtime(true) - $cycleStart) * 1000);
    $budget = $config['interval_seconds'] * 1000 - $cycleElapsedMs - 2000; // 2s safety margin

    if ($budget <= 0) {
        $stats['deferred_skipped']++;
        $result['skipped'] = true;
        return $result;
    }

    $deferStart = microtime(true);

    // Basic ETA (the @process_eta portion of sp_ProcessTrajectoryBatch)
    if ($budget > 300) {
        $start = microtime(true);
        $etaCount = 0;
        $trajCount = 0;
        $stmt = sqlsrv_query($conn,
            "EXEC dbo.sp_ProcessTrajectoryBatch @process_eta = 1, @process_trajectory = 0, @eta_count = ?, @traj_count = ?",
            [
                [&$etaCount, SQLSRV_PARAM_INOUT, null, SQLSRV_SQLTYPE_INT],
                [&$trajCount, SQLSRV_PARAM_INOUT, null, SQLSRV_SQLTYPE_INT],
            ],
            ['QueryTimeout' => 10]
        );
        if ($stmt !== false) {
            while (sqlsrv_next_result($stmt)) {}
            sqlsrv_free_stmt($stmt);
            $ms = round((microtime(true) - $start) * 1000);
            $result['eta_basic'] = ['count' => $etaCount, 'ms' => $ms];
            $budget -= $ms;
        }
    }

    // High-accuracy batch ETA with wind integration (every N cycles)
    if ($stats['runs'] % $config['deferred_eta_interval'] === 0 && $budget > 500) {
        $start = microtime(true);
        $batchEtaCount = 0;
        $stmt = sqlsrv_query($conn,
            "EXEC dbo.sp_CalculateETABatch @eta_count = ?",
            [[&$batchEtaCount, SQLSRV_PARAM_INOUT, null, SQLSRV_SQLTYPE_INT]],
            ['QueryTimeout' => 10]
        );
        if ($stmt !== false) {
            while (sqlsrv_next_result($stmt)) {}
            sqlsrv_free_stmt($stmt);
            $ms = round((microtime(true) - $start) * 1000);
            $result['eta_batch'] = ['count' => $batchEtaCount, 'ms' => $ms];
            $budget -= $ms;
        }
    }

    // Legacy trajectory log (has internal 60s skip logic, cheap when skipped)
    if ($budget > 100) {
        $start = microtime(true);
        $stmt = sqlsrv_query($conn, "EXEC dbo.sp_Log_Trajectory", [], ['QueryTimeout' => 5]);
        if ($stmt !== false) {
            while (sqlsrv_next_result($stmt)) {}
            sqlsrv_free_stmt($stmt);
        }
        $result['log'] = ['ms' => round((microtime(true) - $start) * 1000)];
    }

    // Phase snapshot — every 5 minutes (chart displays 5-min buckets anyway)
    static $lastSnapshotTime = 0;
    $timeSinceSnapshot = microtime(true) - $lastSnapshotTime;

    if ($timeSinceSnapshot >= 300 && $budget > 100) {
        $start = microtime(true);
        $stmt = sqlsrv_query($conn, "EXEC dbo.sp_CapturePhaseSnapshot", [], ['QueryTimeout' => 5]);
        if ($stmt !== false) {
            while (sqlsrv_next_result($stmt)) {}
            sqlsrv_free_stmt($stmt);
            $lastSnapshotTime = microtime(true);
        }
        $result['snapshot'] = ['ms' => round((microtime(true) - $start) * 1000)];
        $budget -= $result['snapshot']['ms'];
    }

    // Gap detection & OOOI backfill — every ~50 cycles when budget healthy
    static $lastGapCheckRun = 0;
    if ($stats['runs'] - $lastGapCheckRun >= 50 && $budget > 2000) {
        $lastGapCheckRun = $stats['runs'];

        $gapSql = "
            WITH ordered AS (
                SELECT snapshot_utc,
                       LEAD(snapshot_utc) OVER (ORDER BY snapshot_utc) AS next_utc
                FROM dbo.flight_phase_snapshot
                WHERE snapshot_utc > DATEADD(HOUR, -48, SYSUTCDATETIME())
            )
            SELECT TOP 1 snapshot_utc AS gap_start, next_utc AS gap_end
            FROM ordered
            WHERE DATEDIFF(MINUTE, snapshot_utc, next_utc) > 10
            ORDER BY snapshot_utc ASC";

        $gapStmt = sqlsrv_query($conn, $gapSql, [], ['QueryTimeout' => 3]);
        if ($gapStmt !== false) {
            $gapRow = sqlsrv_fetch_array($gapStmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($gapStmt);

            if ($gapRow && $gapRow['gap_start'] && $gapRow['gap_end']) {
                $filled = 0;
                $bfStmt = sqlsrv_query($conn,
                    "EXEC dbo.sp_BackfillPhaseSnapshotGap @gap_start=?, @gap_end=?, @max_rows=10, @filled=?",
                    [
                        $gapRow['gap_start'],
                        $gapRow['gap_end'],
                        [&$filled, SQLSRV_PARAM_INOUT, null, SQLSRV_SQLTYPE_INT],
                    ],
                    ['QueryTimeout' => 15]
                );
                if ($bfStmt !== false) {
                    while (sqlsrv_next_result($bfStmt)) {}
                    sqlsrv_free_stmt($bfStmt);
                }
                if ($filled > 0) {
                    $result['backfill'] = $filled;
                }
            }
        }
    }

    $result['elapsed_ms'] = round((microtime(true) - $deferStart) * 1000);
    $stats['deferred_runs']++;
    $stats['deferred_total_ms'] += $result['elapsed_ms'];

    return $result;
}

/**
 * Generate a UUID v4 for batch tracking.
 */
function generateBatchId(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
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

        // Parse weather from ATIS text
        $weather = parseAtisWeather($text);

        $atisList[] = [
            'airport_icao' => $airport,
            'callsign' => $callsign,
            'atis_type' => $type,
            'atis_code' => $code,
            'frequency' => $atis['frequency'] ?? null,
            'atis_text' => $text,
            'controller_cid' => $atis['cid'] ?? null,
            'logon_time' => $atis['logon_time'] ?? null,
            // Weather fields
            'wind_dir_deg' => $weather['wind_dir'],
            'wind_speed_kt' => $weather['wind_speed'],
            'wind_gust_kt' => $weather['wind_gust'],
            'visibility_sm' => $weather['visibility_sm'],
            'ceiling_ft' => $weather['ceiling_ft'],
            'altimeter_inhg' => $weather['altimeter'],
            'flight_category' => $weather['flight_category'],
            'weather_category' => $weather['weather_category'],
        ];
    }

    return $atisList;
}

/**
 * Detect weather conditions from ATIS text.
 * Returns: 'bad', 'clear', or 'normal'
 */
function detectWeatherCondition(string $atisText): string {
    $text = strtoupper($atisText);

    // Bad weather patterns
    $badPatterns = [
        '/\b(?:RA|SN|DZ|GR|GS|PL|IC|UP|SG|SS|DS)\b/',
        '/\b(?:\+|\-)?(?:TS|FZ|SH)/',
        '/\bVC(?:SH|TS)/',
        '/\b(?:FG|BR|HZ|FU|VA|DU|SA)\b/',
        '/\b[012]SM\b/',
        '/\b[0-4]\d{3}\b/',
        '/\bM?1\/[24]SM\b/',
        '/\b(?:BKN|OVC)0(?:0[1-9]|10)\b/',
        '/\bVV0(?:0[1-9]|10)\b/',
        '/\b\d{3}(?:2[5-9]|[3-9]\d)(?:G\d{2})?KT\b/',
        '/\b\d{3}\d{2}G\d{2}KT\b/',
        '/\bWIND\s*SHEAR\b/',
        '/\bMICROBURST\b/',
        '/\bLLWS\b/',
    ];

    foreach ($badPatterns as $pattern) {
        if (preg_match($pattern, $text)) {
            return 'bad';
        }
    }

    $clearPatterns = [
        '/\b(?:SKC|CLR|CAVOK|NSC)\b/',
        '/\b(?:10SM|P6SM)\b/',
        '/\b9999\b/',
    ];

    $hasClear = false;
    foreach ($clearPatterns as $pattern) {
        if (preg_match($pattern, $text)) {
            $hasClear = true;
            break;
        }
    }

    if ($hasClear && !preg_match('/\b(?:BKN|OVC)\d{3}\b/', $text)) {
        return 'clear';
    }

    return 'normal';
}

function isNearMetarUpdate(int $windowMins = 5): bool {
    $minute = (int)gmdate('i');
    return ($minute >= (60 - $windowMins) || $minute <= $windowMins);
}

function getBaseTier(string $airport, array $config): int {
    if (in_array($airport, $config['aspm82'])) {
        return 1;
    }
    $prefix = substr($airport, 0, 1);
    if (in_array($prefix, $config['tier2_prefixes'])) {
        return 2;
    }
    if ($prefix === 'K' || $prefix === 'P') {
        return 2;
    }
    return 3;
}

function getEffectiveTier(string $airport, string $atisText, array $config): int {
    $baseTier = getBaseTier($airport, $config);
    $weather = detectWeatherCondition($atisText);
    $isMetarTime = isNearMetarUpdate($config['metar_window_mins'] ?? 5);
    $isAspm82 = in_array($airport, $config['aspm82']);

    if ($isMetarTime && $isAspm82) {
        return 0;
    }
    if ($weather === 'bad') {
        if ($isAspm82) {
            return 0;
        } else {
            return min($baseTier, 1);
        }
    }
    if ($weather === 'clear' && $baseTier >= 3) {
        return 4;
    }
    return $baseTier;
}

function getAtisForCycle(array $atisList, array $config, int $cycleNum): array {
    $filtered = [];
    $intervals = $config['atis_tier_intervals'];

    foreach ($atisList as $atis) {
        $airport = $atis['airport_icao'];
        $text = $atis['atis_text'] ?? '';
        $tier = getEffectiveTier($airport, $text, $config);
        $interval = $intervals[$tier] ?? $intervals[3];

        if ($cycleNum % $interval === 0) {
            $atis['_tier'] = $tier;
            $filtered[] = $atis;
        }
    }

    return $filtered;
}

function processAtis($conn, array $atisList): array {
    if (empty($atisList)) {
        return ['imported' => 0, 'parsed' => 0, 'skipped' => 0];
    }

    $imported = 0;
    $parsed = 0;
    $skipped = 0;

    $json = json_encode($atisList);
    $sql = "EXEC dbo.sp_ImportVatsimAtis @json = ?";
    $stmt = @sqlsrv_query($conn, $sql, [&$json]);

    if ($stmt !== false) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC);
        $imported = $row[0] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    $sql = "EXEC dbo.sp_GetPendingAtis @limit = 500";
    $stmt = @sqlsrv_query($conn, $sql);

    if ($stmt === false) {
        return ['imported' => $imported, 'parsed' => 0, 'skipped' => 0];
    }

    $batch = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $atisId = (int)$row['atis_id'];
        $text = $row['atis_text'] ?? '';
        $result = parseAtisRunways($text);

        $runways = [];
        if (!empty($result['landing']) || !empty($result['departing'])) {
            $all = array_unique(array_merge($result['landing'], $result['departing']));
            foreach ($all as $rwy) {
                $isLanding = in_array($rwy, $result['landing']);
                $isDeparting = in_array($rwy, $result['departing']);
                $use = ($isLanding && $isDeparting) ? 'BOTH' : ($isLanding ? 'ARR' : 'DEP');
                $runways[] = [
                    'runway_id' => $rwy,
                    'runway_use' => $use,
                    'approach_type' => $result['approaches'][$rwy][0] ?? null
                ];
            }
        }

        $batch[] = [
            'atis_id' => $atisId,
            'runways' => $runways
        ];
    }
    sqlsrv_free_stmt($stmt);

    if (!empty($batch)) {
        $batchJson = json_encode($batch);
        $sql = "EXEC dbo.sp_ImportRunwaysInUseBatch @json = ?";
        $stmt = @sqlsrv_query($conn, $sql, [&$batchJson]);

        if ($stmt !== false) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            $parsed = $row['parsed'] ?? 0;
            $skipped = $row['skipped'] ?? 0;
            sqlsrv_free_stmt($stmt);
        }
    }

    return ['imported' => $imported, 'parsed' => $parsed, 'skipped' => $skipped];
}

// ============================================================================
// ATIS TIERED CLEANUP
// ============================================================================

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
// EVENT POSITION LOGGING (TMI Compliance Analysis)
// ============================================================================

/**
 * Check if any event is currently in its logging window.
 * Returns array of active event IDs and details, or empty array if none.
 */
function getActiveEventLogging($conn): array {
    // Use the helper function from migration 004
    $sql = "SELECT event_id, event_name, event_type, featured_airports
            FROM dbo.fn_GetActiveEventIds(DEFAULT)";

    $stmt = @sqlsrv_query($conn, $sql);
    if ($stmt === false) {
        return [];
    }

    $events = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $events[] = [
            'event_id' => $row['event_id'],
            'event_name' => $row['event_name'],
            'event_type' => $row['event_type'],
            'featured_airports' => $row['featured_airports'],
        ];
    }
    sqlsrv_free_stmt($stmt);

    return $events;
}

/**
 * Extract controller positions from VATSIM datafeed.
 * Excludes ATIS positions (separate table) and OBS-only positions.
 */
function extractControllersFromJson(array $data): array {
    $controllers = [];

    foreach ($data['controllers'] ?? [] as $ctrl) {
        $callsign = $ctrl['callsign'] ?? '';

        // Skip ATIS connections (handled separately)
        if (stripos($callsign, '_ATIS') !== false) {
            continue;
        }

        // Skip OBS-only connections without real callsigns
        if (empty($callsign) || preg_match('/^OBS\d*$/i', $callsign)) {
            continue;
        }

        $controllers[] = [
            'cid' => $ctrl['cid'] ?? 0,
            'callsign' => $callsign,
            'frequency' => $ctrl['frequency'] ?? null,
            'visual_range' => $ctrl['visual_range'] ?? null,
            'rating' => $ctrl['rating'] ?? null,
            'logon_time' => $ctrl['logon_time'] ?? null,
            'latitude' => $ctrl['latitude'] ?? null,
            'longitude' => $ctrl['longitude'] ?? null,
            'text_atis' => isset($ctrl['text_atis']) && is_array($ctrl['text_atis'])
                ? implode(' ', $ctrl['text_atis'])
                : null,
        ];
    }

    return $controllers;
}

/**
 * Log controller positions for active events using bulk SP.
 *
 * @param resource $conn Database connection
 * @param array $events Active events from getActiveEventLogging()
 * @param array $controllers Controllers from extractControllersFromJson()
 * @return array Results by event
 */
function logEventPositions($conn, array $events, array $controllers): array {
    if (empty($events) || empty($controllers)) {
        return [];
    }

    $results = [];
    $controllersJson = json_encode($controllers, JSON_UNESCAPED_UNICODE);

    foreach ($events as $event) {
        $eventId = $event['event_id'];

        // Filter controllers by featured airports if specified
        $filteredJson = $controllersJson;
        if (!empty($event['featured_airports'])) {
            $featuredAirports = json_decode($event['featured_airports'], true);
            if (is_array($featuredAirports) && !empty($featuredAirports)) {
                // Filter controllers whose callsign starts with any featured airport
                $filteredControllers = array_filter($controllers, function($c) use ($featuredAirports) {
                    foreach ($featuredAirports as $apt) {
                        // Remove K prefix for matching (KJFK -> JFK matches JFK_TWR)
                        $aptCode = ltrim($apt, 'K');
                        if (stripos($c['callsign'], $aptCode) === 0) {
                            return true;
                        }
                        if (stripos($c['callsign'], $apt) === 0) {
                            return true;
                        }
                    }
                    return false;
                });

                // If no matches, log all controllers for event
                if (!empty($filteredControllers)) {
                    $filteredJson = json_encode(array_values($filteredControllers), JSON_UNESCAPED_UNICODE);
                }
            }
        }

        // Call bulk SP
        $sql = "EXEC dbo.sp_LogEventPositionsBulk @event_id = ?, @json = ?";
        $stmt = @sqlsrv_query($conn, $sql, [$eventId, $filteredJson]);

        if ($stmt !== false) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            $results[$eventId] = [
                'event_name' => $event['event_name'],
                'positions_logged' => $row['positions_logged'] ?? 0,
            ];
            sqlsrv_free_stmt($stmt);
        } else {
            $errors = sqlsrv_errors();
            logWarn("Event position logging failed", [
                'event_id' => $eventId,
                'error' => json_encode($errors),
            ]);
            $results[$eventId] = [
                'event_name' => $event['event_name'],
                'positions_logged' => 0,
                'error' => true,
            ];
        }
    }

    return $results;
}

// ============================================================================
// BOUNDARY & CROSSINGS BACKGROUND PROCESSING
// ============================================================================

function executeBoundaryProcessing($conn, array $config): ?array {
    $startTime = microtime(true);

    $sql = "EXEC dbo.sp_ProcessBoundaryAndCrossings_Background @max_flights_per_run = ?, @max_crossings_per_run = ?, @debug = 0";
    $options = ['QueryTimeout' => $config['boundary_timeout']];

    $stmt = @sqlsrv_query($conn, $sql, [$config['boundary_max_flights'], $config['crossings_max_flights']], $options);

    if ($stmt === false) {
        $errors = sqlsrv_errors();
        logWarn("Boundary SP failed", ['error' => json_encode($errors)]);
        return null;
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$row) {
        return null;
    }

    $elapsedMs = $row['elapsed_ms'] ?? round((microtime(true) - $startTime) * 1000);

    return [
        'boundary_flights'     => $row['boundary_flights'] ?? 0,
        'boundary_transitions' => $row['boundary_transitions'] ?? 0,
        'crossings_calculated' => $row['crossings_calculated'] ?? 0,
        'elapsed_ms'           => $elapsedMs,
    ];
}

function getBoundaryPendingCount($conn): int {
    // NOLOCK: Safe for monitoring query - we only need approximate count
    $sql = "SELECT COUNT(*) AS cnt
            FROM dbo.adl_flight_core c WITH (NOLOCK)
            JOIN dbo.adl_flight_position p WITH (NOLOCK) ON p.flight_uid = c.flight_uid
            WHERE c.is_active = 1
              AND p.lat IS NOT NULL
              AND (c.current_artcc_id IS NULL
                  OR c.last_grid_lat IS NULL
                  OR c.last_grid_lat != CAST(FLOOR(p.lat / 0.5) AS SMALLINT)
                  OR c.last_grid_lon != CAST(FLOOR(p.lon / 0.5) AS SMALLINT))";

    $stmt = @sqlsrv_query($conn, $sql);
    if ($stmt === false) {
        return 0;
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    return (int)($row['cnt'] ?? 0);
}

// ============================================================================
// RUNWAY DETECTION FROM FLIGHT TRACKS
// ============================================================================

function executeRunwayDetection($conn, array $config): ?array {
    $startTime = microtime(true);

    $sql = "EXEC dbo.sp_DetectRunwaysFromFlights @debug = 0";
    $options = ['QueryTimeout' => $config['runway_detection_timeout']];

    $stmt = @sqlsrv_query($conn, $sql, [], $options);

    if ($stmt === false) {
        $errors = sqlsrv_errors();
        logWarn("Runway detection SP failed", ['error' => json_encode($errors)]);
        return null;
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$row) {
        return null;
    }

    $elapsedMs = round((microtime(true) - $startTime) * 1000);

    return [
        'airports_analyzed' => $row['airports_analyzed'] ?? 0,
        'departures_detected' => $row['departures_detected'] ?? 0,
        'arrivals_detected' => $row['arrivals_detected'] ?? 0,
        'configs_inserted' => $row['configs_inserted'] ?? 0,
        'elapsed_ms' => $elapsedMs,
    ];
}

// ============================================================================
// WIND ADJUSTMENT CALCULATION (Tiered)
// ============================================================================

function executeWindCalculation($conn, array $config): ?array {
    $startTime = microtime(true);

    // V2 uses segment-based wind calculations (climb/cruise/descent)
    $sql = "EXEC dbo.sp_UpdateFlightWindAdjustments_V2 @debug = 0";
    $options = ['QueryTimeout' => $config['wind_timeout']];

    $stmt = @sqlsrv_query($conn, $sql, [], $options);

    if ($stmt === false) {
        $errors = sqlsrv_errors();
        logWarn("Wind calculation SP failed", ['error' => json_encode($errors)]);
        return null;
    }

    sqlsrv_free_stmt($stmt);

    $elapsedMs = round((microtime(true) - $startTime) * 1000);

    return [
        'elapsed_ms' => $elapsedMs,
    ];
}

// ============================================================================
// SWIM API SYNC
// ============================================================================

function getSwimConnection() {
    if (!defined('SWIM_SQL_HOST') || !defined('SWIM_SQL_DATABASE') ||
        !defined('SWIM_SQL_USERNAME') || !defined('SWIM_SQL_PASSWORD')) {
        return null;
    }

    $connectionOptions = [
        "Database"               => SWIM_SQL_DATABASE,
        "Uid"                    => SWIM_SQL_USERNAME,
        "PWD"                    => SWIM_SQL_PASSWORD,
        "Encrypt"                => true,
        "TrustServerCertificate" => false,
        "LoginTimeout"           => 30,
        "ConnectionPooling"      => true,
        "MultipleActiveResultSets" => false,
        "ApplicationIntent"      => "ReadWrite",
    ];

    $conn = @sqlsrv_connect(SWIM_SQL_HOST, $connectionOptions);
    return $conn !== false ? $conn : null;
}

function getSwimDaemonHeartbeatAge(array $config): ?int {
    $heartbeatFile = $config['swim_daemon_heartbeat_file'] ?? '';
    if ($heartbeatFile === '') {
        return null;
    }

    clearstatcache(true, $heartbeatFile);
    if (!is_file($heartbeatFile)) {
        return null;
    }

    $mtime = @filemtime($heartbeatFile);
    if ($mtime === false) {
        return null;
    }

    $age = time() - $mtime;
    return $age < 0 ? 0 : $age;
}

function shouldRunInlineSwim(array $config, int $run, ?int &$heartbeatAge = null): bool {
    $heartbeatAge = null;
    if (empty($config['swim_enabled'])) {
        return false;
    }
    if ($run % $config['swim_interval'] !== 0) {
        return false;
    }

    if (!empty($config['swim_inline_enabled'])) {
        return true;
    }

    if (empty($config['swim_inline_fallback_enabled'])) {
        return false;
    }

    $heartbeatAge = getSwimDaemonHeartbeatAge($config);
    if ($heartbeatAge === null) {
        return true;
    }

    $staleSec = (int)($config['swim_daemon_stale_sec'] ?? 300);
    return $heartbeatAge > $staleSec;
}

// ============================================================================
// TMI → ADL SYNC (Cross-database: VATSIM_TMI → VATSIM_ADL)
// Propagates GDP/AFP/GS control data so SWIM, dashboards, and compliance
// tools see current TMI state. Handles multi-program precedence (FAA model).
// ============================================================================

/**
 * Sync active TMI flight control records from VATSIM_TMI to VATSIM_ADL.
 *
 * For each active flight: determines the controlling program (GS wins,
 * otherwise MAX ctd_utc), updates adl_flight_tmi + adl_flight_times,
 * and cleans up flights no longer under any active program.
 *
 * @param resource $conn_adl  VATSIM_ADL connection (read-write)
 * @param resource $conn_tmi  VATSIM_TMI connection (read-only)
 * @return array{synced: int, cleaned: int, ms: int}|null
 */
function executeDeferredTMISync($conn_adl, $conn_tmi): ?array {
    $start = microtime(true);

    // ── Step 1: Fetch all active control records from TMI ────────────────
    $tmiSql = "
        SELECT fc.flight_uid, fc.control_id, fc.program_id, fc.slot_id,
               fc.ctd_utc, fc.cta_utc, fc.octd_utc, fc.octa_utc,
               fc.ctl_type, fc.ctl_prgm, fc.ctl_elem,
               fc.ctl_exempt, fc.program_delay_min, fc.delay_capped,
               fc.gs_held, fc.gs_release_utc,
               fc.is_popup, fc.is_recontrol, fc.ecr_pending,
               fc.sl_hold, fc.subbable,
               p.program_type, p.ctl_element AS program_ctl_element
        FROM dbo.tmi_flight_control fc
        INNER JOIN dbo.tmi_programs p ON fc.program_id = p.program_id
        WHERE p.is_active = 1
          AND fc.flight_uid IS NOT NULL
    ";

    $tmiStmt = sqlsrv_query($conn_tmi, $tmiSql, [], ['QueryTimeout' => 15]);
    if ($tmiStmt === false) {
        $errors = sqlsrv_errors();
        logWarn("TMI sync: failed to query TMI", ['errors' => $errors]);
        return null;
    }

    // Collect all records grouped by flight_uid
    $flightRecords = []; // flight_uid => [record, ...]
    while ($row = sqlsrv_fetch_array($tmiStmt, SQLSRV_FETCH_ASSOC)) {
        $fuid = (int)$row['flight_uid'];
        $flightRecords[$fuid][] = $row;
    }
    sqlsrv_free_stmt($tmiStmt);

    if (empty($flightRecords)) {
        // No active TMI flights — still need to run cleanup
        return executeTmiSyncCleanup($conn_adl, [], $start);
    }

    // ── Step 2: Compute controlling program per flight ───────────────────
    // FAA model: GS wins, otherwise MAX(ctd_utc), fallback MAX(control_id)
    $syncRows = [];
    foreach ($flightRecords as $fuid => $records) {
        $controlling = null;
        $effectiveCtd = null;

        if (count($records) === 1) {
            // Single program — straightforward
            $controlling = $records[0];
            $effectiveCtd = $controlling['ctd_utc'];
        } else {
            // Multi-program: determine controlling record
            $gsRecord = null;
            $maxCtdRecord = null;
            $maxCtd = null;
            $maxControlId = null;
            $fallbackRecord = null;

            foreach ($records as $rec) {
                // Track effective CTD (MAX across all programs)
                $ctd = $rec['ctd_utc'];
                if ($ctd !== null) {
                    $ctdStr = ($ctd instanceof DateTimeInterface) ? $ctd->format('Y-m-d H:i:s') : (string)$ctd;
                    if ($effectiveCtd === null || $ctdStr > $effectiveCtd) {
                        $effectiveCtd = $ctdStr;
                    }
                }

                // GS always wins
                if ((int)($rec['gs_held'] ?? 0) === 1) {
                    $gsRecord = $rec;
                }

                // Track MAX ctd_utc
                if ($ctd !== null) {
                    if ($maxCtd === null || $ctdStr > $maxCtd) {
                        $maxCtd = $ctdStr;
                        $maxCtdRecord = $rec;
                    }
                }

                // Fallback: MAX control_id (most recently assigned)
                $cid = (int)$rec['control_id'];
                if ($maxControlId === null || $cid > $maxControlId) {
                    $maxControlId = $cid;
                    $fallbackRecord = $rec;
                }
            }

            $controlling = $gsRecord ?? $maxCtdRecord ?? $fallbackRecord;
        }

        if ($controlling === null) continue;

        // Format effective CTD for EDCT
        $effCtdFormatted = null;
        if ($effectiveCtd !== null) {
            $effCtdFormatted = ($effectiveCtd instanceof DateTimeInterface)
                ? $effectiveCtd->format('Y-m-d H:i:s')
                : (string)$effectiveCtd;
        }

        $syncRows[$fuid] = [
            'flight_uid'        => $fuid,
            'ctl_type'          => $controlling['ctl_type'],
            'ctl_element'       => $controlling['ctl_elem'],  // ctl_elem → ctl_element
            'ctl_prgm'          => $controlling['ctl_prgm'],
            'program_id'        => $controlling['program_id'],
            'slot_id'           => $controlling['slot_id'],
            'ctd_utc'           => $controlling['ctd_utc'],
            'cta_utc'           => $controlling['cta_utc'],
            'edct_utc'          => $effCtdFormatted,  // MAX(ctd_utc) across all programs
            'octd_utc'          => $controlling['octd_utc'],
            'octa_utc'          => $controlling['octa_utc'],
            'program_delay_min' => $controlling['program_delay_min'],
            'delay_capped'      => $controlling['delay_capped'],
            'gs_held'           => $controlling['gs_held'],
            'gs_release_utc'    => $controlling['gs_release_utc'],
            'is_popup'          => $controlling['is_popup'],
            'is_recontrol'      => $controlling['is_recontrol'],
            'ecr_pending'       => $controlling['ecr_pending'],
            'sl_hold'           => $controlling['sl_hold'],
            'subbable'          => $controlling['subbable'],
            'ctl_exempt'        => $controlling['ctl_exempt'],
        ];
    }

    // ── Step 3: Create temp table on ADL and batch INSERT ────────────────
    $createSql = "
        CREATE TABLE #TmiSync (
            flight_uid BIGINT PRIMARY KEY,
            ctl_type NVARCHAR(8), ctl_element NVARCHAR(8), ctl_prgm NVARCHAR(64),
            program_id INT, slot_id BIGINT,
            ctd_utc DATETIME2(0), cta_utc DATETIME2(0), edct_utc DATETIME2(0),
            octd_utc DATETIME2(0), octa_utc DATETIME2(0),
            program_delay_min INT, delay_capped BIT,
            gs_held BIT, gs_release_utc DATETIME2(0),
            is_popup BIT, is_recontrol BIT, ecr_pending BIT,
            sl_hold BIT, subbable BIT,
            ctl_exempt BIT
        )
    ";

    $createStmt = sqlsrv_query($conn_adl, $createSql);
    if ($createStmt === false) {
        logWarn("TMI sync: failed to create temp table", ['errors' => sqlsrv_errors()]);
        return null;
    }
    sqlsrv_free_stmt($createStmt);

    // Batch INSERT in chunks of 50
    $chunks = array_chunk(array_values($syncRows), 50);
    $insertedCount = 0;

    foreach ($chunks as $chunk) {
        $values = [];
        $params = [];
        foreach ($chunk as $row) {
            $values[] = "(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $params[] = $row['flight_uid'];
            $params[] = $row['ctl_type'];
            $params[] = $row['ctl_element'];
            $params[] = $row['ctl_prgm'];
            $params[] = $row['program_id'];
            $params[] = $row['slot_id'];
            $params[] = formatDatetimeParam($row['ctd_utc']);
            $params[] = formatDatetimeParam($row['cta_utc']);
            $params[] = $row['edct_utc'];
            $params[] = formatDatetimeParam($row['octd_utc']);
            $params[] = formatDatetimeParam($row['octa_utc']);
            $params[] = $row['program_delay_min'];
            $params[] = $row['delay_capped'];
            $params[] = $row['gs_held'];
            $params[] = formatDatetimeParam($row['gs_release_utc']);
            $params[] = $row['is_popup'];
            $params[] = $row['is_recontrol'];
            $params[] = $row['ecr_pending'];
            $params[] = $row['sl_hold'];
            $params[] = $row['subbable'];
            $params[] = $row['ctl_exempt'];
        }

        $insertSql = "INSERT INTO #TmiSync VALUES " . implode(', ', $values);
        $insertStmt = sqlsrv_query($conn_adl, $insertSql, $params);
        if ($insertStmt !== false) {
            $insertedCount += count($chunk);
            sqlsrv_free_stmt($insertStmt);
        } else {
            logWarn("TMI sync: batch insert failed", ['errors' => sqlsrv_errors()]);
        }
    }

    if ($insertedCount === 0) {
        // All batch inserts failed — still run cleanup for ended programs
        $result = executeTmiSyncCleanup($conn_adl, [], $start);
        $dropStmt = sqlsrv_query($conn_adl, "DROP TABLE #TmiSync");
        if ($dropStmt !== false) sqlsrv_free_stmt($dropStmt);
        return $result;
    }

    // ── Step 4: Ensure adl_flight_tmi rows exist, then UPDATE ────────────
    $ensureSql = "
        INSERT INTO dbo.adl_flight_tmi (flight_uid)
        SELECT s.flight_uid FROM #TmiSync s
        WHERE NOT EXISTS (
            SELECT 1 FROM dbo.adl_flight_tmi t WHERE t.flight_uid = s.flight_uid
        )
    ";
    $ensureStmt = sqlsrv_query($conn_adl, $ensureSql);
    if ($ensureStmt !== false) sqlsrv_free_stmt($ensureStmt);

    $updateSql = "
        UPDATE t SET
            t.ctl_type = s.ctl_type,
            t.ctl_element = s.ctl_element,
            t.ctl_prgm = s.ctl_prgm,
            t.program_id = s.program_id,
            t.slot_id = s.slot_id,
            t.ctd_utc = s.ctd_utc,
            t.cta_utc = s.cta_utc,
            t.edct_utc = s.edct_utc,
            t.octd_utc = s.octd_utc,
            t.octa_utc = s.octa_utc,
            t.program_delay_min = s.program_delay_min,
            t.delay_capped = s.delay_capped,
            t.gs_held = s.gs_held,
            t.gs_release_utc = s.gs_release_utc,
            t.is_popup = s.is_popup,
            t.is_recontrol = s.is_recontrol,
            t.ecr_pending = s.ecr_pending,
            t.sl_hold = s.sl_hold,
            t.subbable = s.subbable,
            t.ctl_exempt = s.ctl_exempt,
            t.tmi_updated_utc = SYSUTCDATETIME()
        FROM dbo.adl_flight_tmi t
        INNER JOIN #TmiSync s ON t.flight_uid = s.flight_uid
    ";
    $updateStmt = sqlsrv_query($conn_adl, $updateSql);
    $synced = 0;
    if ($updateStmt !== false) {
        $synced = sqlsrv_rows_affected($updateStmt) ?? 0;
        sqlsrv_free_stmt($updateStmt);
    }

    // ── Step 5: Mirror control times to adl_flight_times ─────────────────
    $timesSql = "
        UPDATE ft SET
            ft.ctd_utc = s.ctd_utc,
            ft.cta_utc = s.cta_utc,
            ft.edct_utc = s.edct_utc
        FROM dbo.adl_flight_times ft
        INNER JOIN #TmiSync s ON ft.flight_uid = s.flight_uid
    ";
    $timesStmt = sqlsrv_query($conn_adl, $timesSql);
    if ($timesStmt !== false) sqlsrv_free_stmt($timesStmt);

    // ── Step 6: Cleanup stale TMI data ───────────────────────────────────
    $result = executeTmiSyncCleanup($conn_adl, array_keys($syncRows), $start, $synced);

    // Drop temp table
    $dropStmt = sqlsrv_query($conn_adl, "DROP TABLE #TmiSync");
    if ($dropStmt !== false) sqlsrv_free_stmt($dropStmt);

    return $result;
}

/**
 * Format a datetime value (DateTime object or string) for SQL parameterized query.
 */
function formatDatetimeParam($value): ?string {
    if ($value === null) return null;
    if ($value instanceof DateTimeInterface) return $value->format('Y-m-d H:i:s');
    return (string)$value;
}

/**
 * CTP Oceanic: Update compliance status for flights with EDCTs in active sessions.
 * Runs every ~120s alongside TMI sync. Lightweight: single batch query + update.
 *
 * @param resource $conn_adl  VATSIM_ADL connection
 * @param resource $conn_tmi  VATSIM_TMI connection (read-write for ctp_flight_control)
 * @return array{updated: int, sessions: int, ms: int}|null
 */
function executeCtpComplianceCheck($conn_adl, $conn_tmi): ?array {
    $start = microtime(true);

    // 1. Find active CTP sessions with assigned EDCTs
    $sessionSql = "SELECT session_id FROM dbo.ctp_sessions WHERE status IN ('ACTIVE', 'MONITORING')";
    $sessionResult = sqlsrv_query($conn_tmi, $sessionSql);
    if (!$sessionResult) return null;

    $sessionIds = [];
    while ($row = sqlsrv_fetch_array($sessionResult, SQLSRV_FETCH_ASSOC)) {
        $sessionIds[] = (int)$row['session_id'];
    }
    sqlsrv_free_stmt($sessionResult);

    if (empty($sessionIds)) {
        return ['updated' => 0, 'sessions' => 0, 'ms' => round((microtime(true) - $start) * 1000)];
    }

    $updated = 0;

    foreach ($sessionIds as $sid) {
        // 2. Get flights needing compliance check
        $flightSql = "
            SELECT ctp_control_id, flight_uid, edct_utc, compliance_status
            FROM dbo.ctp_flight_control
            WHERE session_id = ? AND edct_status = 'ASSIGNED' AND is_excluded = 0 AND edct_utc IS NOT NULL
        ";
        $flightResult = sqlsrv_query($conn_tmi, $flightSql, [$sid]);
        if (!$flightResult) continue;

        $flights = [];
        $fuids = [];
        while ($f = sqlsrv_fetch_array($flightResult, SQLSRV_FETCH_ASSOC)) {
            $uid = (int)$f['flight_uid'];
            $flights[$uid] = $f;
            $fuids[] = $uid;
        }
        sqlsrv_free_stmt($flightResult);

        if (empty($fuids)) continue;

        // 3. Batch fetch departure times from ADL (chunks of 500)
        $depTimes = [];
        foreach (array_chunk($fuids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $adlSql = "
                SELECT t.flight_uid, t.off_utc, t.out_utc, c.flight_phase
                FROM dbo.adl_flight_times t
                JOIN dbo.adl_flight_core c ON c.flight_uid = t.flight_uid
                WHERE t.flight_uid IN ($placeholders)
            ";
            $adlResult = sqlsrv_query($conn_adl, $adlSql, $chunk);
            if (!$adlResult) continue;
            while ($row = sqlsrv_fetch_array($adlResult, SQLSRV_FETCH_ASSOC)) {
                $depTimes[(int)$row['flight_uid']] = $row;
            }
            sqlsrv_free_stmt($adlResult);
        }

        // 4. Calculate compliance and update
        $nowTs = time();
        foreach ($flights as $uid => $f) {
            $edctVal = $f['edct_utc'];
            $edctStr = formatDatetimeParam($edctVal);
            if (!$edctStr) continue;
            $edctTs = strtotime($edctStr);
            if (!$edctTs) continue;

            $complianceStatus = 'PENDING';
            $deltaMin = null;
            $actualDep = null;

            if (isset($depTimes[$uid])) {
                $adl = $depTimes[$uid];
                $depVal = $adl['off_utc'] ?? $adl['out_utc'] ?? null;
                if ($depVal) {
                    $depStr = formatDatetimeParam($depVal);
                    $depTs = $depStr ? strtotime($depStr) : false;
                    if ($depTs) {
                        $actualDep = $depStr;
                        $deltaMin = (int)round(($depTs - $edctTs) / 60);
                        if ($deltaMin < -5) {
                            $complianceStatus = 'EARLY';
                        } elseif ($deltaMin <= 15) {
                            $complianceStatus = 'ON_TIME';
                        } else {
                            $complianceStatus = 'LATE';
                        }
                    }
                }

                if (!$actualDep && $edctTs && ($nowTs - $edctTs) > 1800) {
                    $phase = $adl['flight_phase'] ?? '';
                    if (in_array($phase, ['COMPLETED', 'CANCELLED'])) {
                        $complianceStatus = 'NO_SHOW';
                    } elseif ($phase !== 'ACTIVE' && $phase !== 'PREFILED') {
                        $complianceStatus = 'LATE';
                    }
                }
            }

            $oldStatus = $f['compliance_status'] ?? 'PENDING';
            if ($complianceStatus !== $oldStatus) {
                $edctStatusNew = in_array($complianceStatus, ['ON_TIME', 'EARLY']) ? 'COMPLIANT'
                    : (in_array($complianceStatus, ['LATE', 'NO_SHOW']) ? 'NON_COMPLIANT' : null);

                $updateSql = "UPDATE dbo.ctp_flight_control SET compliance_status = ?, compliance_delta_min = ?, actual_dep_utc = ?"
                    . ($edctStatusNew ? ", edct_status = '$edctStatusNew'" : "")
                    . " WHERE ctp_control_id = ?";
                $upResult = sqlsrv_query($conn_tmi, $updateSql, [
                    $complianceStatus,
                    $deltaMin,
                    $actualDep,
                    (int)$f['ctp_control_id']
                ]);
                if ($upResult) {
                    $updated++;
                    sqlsrv_free_stmt($upResult);
                }
            }
        }
    }

    return [
        'updated' => $updated,
        'sessions' => count($sessionIds),
        'ms' => round((microtime(true) - $start) * 1000)
    ];
}

/**
 * Clear TMI columns for flights no longer in any active program.
 *
 * @param resource $conn_adl ADL connection
 * @param int[] $activeFuids Flight UIDs still under active TMI control
 * @param float $start Timer start for elapsed ms
 * @param int $synced Count of synced rows (passed through to result)
 * @return array{synced: int, cleaned: int, ms: int}
 */
function executeTmiSyncCleanup($conn_adl, array $activeFuids, float $start, int $synced = 0): array {
    // Find flights in ADL that have program_id but are NOT in the active set.
    // Use OUTPUT to capture affected flight_uids for targeted times cleanup (I2 fix).
    $cleanupSql = "
        UPDATE t SET
            t.ctl_type = NULL, t.ctl_element = NULL, t.ctl_prgm = NULL,
            t.program_id = NULL, t.slot_id = NULL,
            t.ctd_utc = NULL, t.cta_utc = NULL, t.edct_utc = NULL,
            t.octd_utc = NULL, t.octa_utc = NULL,
            t.program_delay_min = NULL, t.delay_capped = 0,
            t.gs_held = 0, t.gs_release_utc = NULL,
            t.is_popup = 0, t.is_recontrol = 0, t.ecr_pending = 0,
            t.sl_hold = 0, t.subbable = 1,
            t.ctl_exempt = 0,
            t.tmi_updated_utc = SYSUTCDATETIME()
        OUTPUT inserted.flight_uid
        FROM dbo.adl_flight_tmi t
        WHERE t.program_id IS NOT NULL
    ";

    // If there are active flights, exclude them from cleanup
    if (!empty($activeFuids)) {
        $cleanupSql .= " AND NOT EXISTS (SELECT 1 FROM #TmiSync s WHERE s.flight_uid = t.flight_uid)";
    }

    $cleanupStmt = sqlsrv_query($conn_adl, $cleanupSql);
    $cleaned = 0;
    $cleanedFuids = [];
    if ($cleanupStmt !== false) {
        while ($row = sqlsrv_fetch_array($cleanupStmt, SQLSRV_FETCH_ASSOC)) {
            $cleanedFuids[] = (int)$row['flight_uid'];
            $cleaned++;
        }
        sqlsrv_free_stmt($cleanupStmt);
    }

    // Clear adl_flight_times only for the specific flights that were just cleaned
    if ($cleaned > 0) {
        $chunks = array_chunk($cleanedFuids, 100);
        foreach ($chunks as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $timesCleanSql = "
                UPDATE dbo.adl_flight_times
                SET ctd_utc = NULL, cta_utc = NULL, edct_utc = NULL
                WHERE flight_uid IN ({$placeholders})
                  AND (ctd_utc IS NOT NULL OR cta_utc IS NOT NULL OR edct_utc IS NOT NULL)
            ";
            $timesCleanStmt = sqlsrv_query($conn_adl, $timesCleanSql, $chunk);
            if ($timesCleanStmt !== false) sqlsrv_free_stmt($timesCleanStmt);
        }
    }

    return [
        'synced'  => $synced,
        'cleaned' => $cleaned,
        'ms'      => round((microtime(true) - $start) * 1000),
    ];
}

function executeSwimSync($conn_adl, $conn_swim): ?array {
    static $syncScriptLoaded = false;

    if (!$syncScriptLoaded) {
        $syncScript = __DIR__ . '/swim_sync.php';
        if (file_exists($syncScript)) {
            require_once $syncScript;
            $syncScriptLoaded = true;
        } else {
            logWarn("SWIM sync script not found: {$syncScript}");
            return null;
        }
    }

    if (!function_exists('swim_sync_from_adl')) {
        return null;
    }

    $GLOBALS['conn_adl'] = $conn_adl;
    $GLOBALS['conn_swim'] = $conn_swim;

    $result = swim_sync_from_adl();

    if ($result['success']) {
        return [
            'flights_synced' => $result['stats']['flights_changed'] ?? ($result['stats']['flights_fetched'] ?? 0),
            'inserted'       => $result['stats']['inserted'] ?? 0,
            'updated'        => $result['stats']['updated'] ?? 0,
            'deleted'        => $result['stats']['deleted'] ?? 0,
            'elapsed_ms'     => $result['stats']['duration_ms'] ?? 0,
        ];
    } else {
        logWarn("SWIM sync failed", ['error' => $result['message']]);
        return null;
    }
}

// ============================================================================
// FLIGHT STATS SCHEDULED JOBS
// ============================================================================

/**
 * Execute scheduled flight stats jobs (hourly, daily, monthly aggregation).
 * Calls sp_ProcessFlightStatsJobs which checks each job's schedule internally.
 * @param resource $conn SQL Server connection
 * @return array|null Result with jobs executed, or null on error
 */
function executeFlightStatsJobs($conn): ?array {
    $startTime = microtime(true);

    // sp_ProcessFlightStatsJobs iterates through flight_stats_job_config,
    // checks if each job should run based on schedule, and executes if due
    $sql = "EXEC dbo.sp_ProcessFlightStatsJobs";
    $stmt = @sqlsrv_query($conn, $sql, [], ['QueryTimeout' => 300]);  // 5 min timeout for aggregation

    if ($stmt === false) {
        $errors = sqlsrv_errors();
        logWarn("Flight stats job SP failed", ['error' => json_encode($errors)]);
        return null;
    }

    // The SP prints messages but doesn't return a result set
    // Drain any results
    while (sqlsrv_next_result($stmt)) {
        // Drain
    }
    sqlsrv_free_stmt($stmt);

    $elapsedMs = round((microtime(true) - $startTime) * 1000);

    return [
        'elapsed_ms' => $elapsedMs,
    ];
}

// ============================================================================
// CONNECTION HEALTH CHECK (Fast)
// ============================================================================

function isConnectionAlive($conn): bool {
    if ($conn === null || $conn === false) {
        return false;
    }

    try {
        $stmt = @sqlsrv_query($conn, "SELECT 1");
    } catch (Throwable $e) {
        return false;
    }

    if ($stmt === false) {
        return false;
    }
    @sqlsrv_free_stmt($stmt);
    return true;
}

/**
 * Best-effort SQLSRV connection close.
 * Some SQLSRV failure states can leave an invalid handle that throws TypeError
 * on sqlsrv_close(), so we treat close as optional and always null the handle.
 */
function safeCloseConnection(&$conn): void {
    if ($conn === null || $conn === false) {
        $conn = null;
        return;
    }

    try {
        @sqlsrv_close($conn);
    } catch (Throwable $e) {
        // Ignore invalid/terminated handles during recovery.
    }

    $conn = null;
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
        'websocket' => $config['websocket_enabled'] ? 'enabled' : 'disabled',
        'mode'      => $config['staged_refresh_enabled']
                        ? ($config['use_tvp'] ? 'staged+bulk (V9.2)' : 'staged (V9.0)')
                        : 'legacy',
    ]);
    
    // Establish initial connection
    $conn = null;
    $reconnectAttempts = 0;
    $maxReconnectAttempts = 10;
    
    while ($conn === null && $reconnectAttempts < $maxReconnectAttempts) {
        try {
            $conn = getConnection($config);
            logInfo("Database connected");
        } catch (Throwable $e) {
            $reconnectAttempts++;
            logError("Connection attempt {$reconnectAttempts} failed", ['error' => $e->getMessage()]);
            if ($reconnectAttempts < $maxReconnectAttempts) {
                sleep(min(30, $reconnectAttempts * 5));
            }
        }
    }
    
    if ($conn === null) {
        logError("FATAL: Could not connect to database. Exiting.");
        exit(1);
    }
    
    // Establish SWIM_API connection (inline mode only).
    // Daemon-first mode keeps SWIM out of the ADL critical path.
    $conn_swim = null;
    if ($config['swim_enabled']) {
        if ($config['swim_inline_enabled']) {
            $conn_swim = getSwimConnection();
            if ($conn_swim) {
                logInfo("SWIM_API database connected", [
                    'database' => SWIM_SQL_DATABASE,
                    'mode' => 'inline',
                ]);
            } else {
                logWarn("SWIM_API connection failed - inline sync disabled");
            }
        } else {
            logInfo("SWIM sync delegated to swim_sync_daemon", [
                'heartbeat_file' => $config['swim_daemon_heartbeat_file'],
                'stale_sec' => $config['swim_daemon_stale_sec'],
            ]);
        }
    }

    // TMI connection (lazy — connects on first sync cycle, not at startup)
    $conn_tmi = null;

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
        'total_skipped' => 0,
        'started'       => time(),
        // Boundary processing stats
        'boundary_runs'        => 0,
        'boundary_transitions' => 0,
        'boundary_crossings'   => 0,
        'boundary_total_ms'    => 0,
        // Runway detection stats
        'runway_detect_runs'   => 0,
        'runway_configs_found' => 0,
        'runway_detect_ms'     => 0,
        // Wind calculation stats
        'wind_runs'            => 0,
        'wind_total_ms'        => 0,
        // SWIM API sync stats
        'swim_runs'            => 0,
        'swim_synced'          => 0,
        'swim_total_ms'        => 0,
        // WebSocket stats
        'ws_events'            => 0,
        'ws_runs'              => 0,
        // Flight stats job stats
        'fstats_runs'          => 0,
        'fstats_total_ms'      => 0,
        // Event position logging stats
        'event_log_runs'       => 0,
        'event_positions_total'=> 0,
        // V9.0 Staging stats
        'total_staging_ms'     => 0,
        'total_insert_ms'      => 0,
        // V9.2 Deferred processing stats
        'deferred_runs'        => 0,
        'deferred_skipped'     => 0,
        'deferred_total_ms'    => 0,
        // V9.3 Delta detection stats
        'total_heartbeat'      => 0,
    ];
    
    // WebSocket: Track last refresh time for event detection
    $lastRefreshTime = null;
    
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
                safeCloseConnection($conn);
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
            
            // 3. Decode JSON (needed for both staged and legacy paths)
            $jsonSizeKb = round(strlen($jsonData) / 1024);
            $parseStart = microtime(true);
            $vatsimData = json_decode($jsonData, true);
            $parseMs = round((microtime(true) - $parseStart) * 1000);

            if (!$vatsimData || !isset($vatsimData['pilots'])) {
                throw new Exception("Failed to parse VATSIM JSON");
            }

            $pilotCount = count($vatsimData['pilots'] ?? []);

            // 4. Execute stored procedure for flights
            // V9.0: Use staged refresh (PHP parses JSON, inserts to staging tables)
            // or legacy (pass JSON string to SP for OPENJSON parsing)
            $stagingMs = 0;
            $insertMs = 0;

            if ($config['staged_refresh_enabled']) {
                // === V9.0/V9.1 STAGED REFRESH ===
                // PHP parses JSON (~100ms) + bulk insert vs SQL OPENJSON (3-5s)

                // 4a. Parse pilots in PHP
                $stagingStart = microtime(true);
                $parsedPilots = parseVatsimPilots($vatsimData);
                $parsedPrefiles = parseVatsimPrefiles($vatsimData);
                $stagingMs = round((microtime(true) - $stagingStart) * 1000);

                // 4a2. Delta detection: compare against previous cycle (V9.3.0)
                static $previousPilots = [];

                foreach ($parsedPilots as &$pilot) {
                    $key = $pilot['flight_key'];
                    $prev = $previousPilots[$key] ?? null;
                    $pilot['change_flags'] = computeChangeFlags($pilot, $prev);
                }
                unset($pilot);

                // Delta stats for logging
                $deltaStats = ['heartbeat' => 0, 'pos_changed' => 0, 'plan_changed' => 0, 'new' => 0];
                foreach ($parsedPilots as $p) {
                    $f = $p['change_flags'];
                    if ($f === 0) $deltaStats['heartbeat']++;
                    if ($f & 1) $deltaStats['pos_changed']++;
                    if ($f & 2) $deltaStats['plan_changed']++;
                    if ($f & 4) $deltaStats['new']++;
                }

                // Rebuild previous state for next cycle (only store comparison fields)
                $previousPilots = [];
                foreach ($parsedPilots as $p) {
                    $previousPilots[$p['flight_key']] = [
                        'lat'              => $p['lat'],
                        'lon'              => $p['lon'],
                        'altitude_ft'      => $p['altitude_ft'],
                        'groundspeed_kts'  => $p['groundspeed_kts'],
                        'heading_deg'      => $p['heading_deg'],
                        'route_hash'       => $p['route_hash'],
                        'altitude_filed_raw' => $p['altitude_filed_raw'] ?? '',
                        'tas_filed_raw'    => $p['tas_filed_raw'] ?? '',
                        'fp_rule'          => $p['fp_rule'] ?? '',
                        'aircraft_faa_raw' => $p['aircraft_faa_raw'] ?? '',
                        'remarks'          => $p['remarks'] ?? '',
                    ];
                }

                // 4b. Insert to staging tables
                $insertStart = microtime(true);
                $batchId = generateBatchId();

                $insertMode = 'batched';
                if ($config['use_tvp']) {
                    // V9.2: Bulk literal INSERT mode (O(1) per 1000-row batch)
                    // Single INSERT with literal values - no parameters, no 2100 limit
                    // 3000 pilots = 3 round trips vs 43 with parameterized batches
                    $pilotResult = insertPilotsBulkLiteral($conn, $parsedPilots, $batchId, 1000);
                    $prefileResult = insertPrefilesBulkLiteral($conn, $parsedPrefiles, $batchId, 1000);
                    $insertedPilots = $pilotResult['inserted'];
                    $insertedPrefiles = $prefileResult['inserted'];
                    $insertMode = $pilotResult['method'];  // Should be 'bulk'
                } else {
                    // V9.0: Batched INSERTs with parameters (~43 round trips for 3K pilots)
                    clearStagingTables($conn);
                    $insertedPilots = insertPilotsToStaging($conn, $parsedPilots, $batchId, $config['staged_batch_size']);
                    $insertedPrefiles = insertPrefilesToStaging($conn, $parsedPrefiles, $batchId, $config['staged_batch_size']);
                }
                $insertMs = round((microtime(true) - $insertStart) * 1000);

                // 4c. Execute staged refresh SP
                $spResult = executeStagedRefreshSP($conn, $batchId, $config['sp_timeout'], $config['zone_daemon_enabled'], $config['defer_expensive']);
                $spMs = $spResult['elapsed_ms'];

                // Log staging performance on first run or every 100 runs
                if ($stats['runs'] == 1 || $stats['runs'] % 100 === 0) {
                    logInfo("Staged refresh ({$insertMode}): parse={$parseMs}ms, staging={$stagingMs}ms, insert={$insertMs}ms, sp={$spMs}ms", [
                        'pilots' => $insertedPilots,
                        'prefiles' => $insertedPrefiles,
                    ]);
                }
            } else {
                // === LEGACY REFRESH ===
                // Pass raw JSON to SP, uses OPENJSON (3-5s at 3000 pilots)
                $spResult = executeRefreshSP($conn, $jsonData, $config['sp_timeout']);
                $spMs = $spResult['elapsed_ms'];
            }

            // Free raw JSON string (we have vatsimData now)
            unset($jsonData);

            // 4d. Deferred ETA processing (trajectory already captured in SP)
            // Runs ETA calcs, legacy log, and snapshot when cycle time budget allows
            $deferredResult = null;
            if ($config['defer_expensive']) {
                $deferredResult = executeDeferredProcessing($conn, $config, $stats, $cycleStart);
            }

            // 4e. TMI → ADL sync (every 60s)
            // Propagates GDP/AFP/GS control data from VATSIM_TMI to VATSIM_ADL
            // so SWIM API, dashboards, and compliance tools see current state.
            $tmiSyncResult = null;
            if ($config['tmi_sync_enabled'] && ($stats['runs'] % $config['tmi_sync_interval'] === 0)) {
                // Lazy connect / reconnect TMI
                if ($conn_tmi === null || $conn_tmi === false) {
                    $conn_tmi = getTmiConnection($config);
                    if ($conn_tmi) {
                        logInfo("TMI database connected (read-only)");
                    }
                }
                if ($conn_tmi) {
                    try {
                        $tmiSyncResult = executeDeferredTMISync($conn, $conn_tmi);
                        if ($tmiSyncResult !== null && ($tmiSyncResult['synced'] > 0 || $tmiSyncResult['cleaned'] > 0)) {
                            logInfo("TMI sync: {$tmiSyncResult['synced']} synced, {$tmiSyncResult['cleaned']} cleaned ({$tmiSyncResult['ms']}ms)");
                        }
                    } catch (Throwable $e) {
                        logWarn("TMI sync error: " . $e->getMessage());
                        // Force reconnect next cycle
                        @sqlsrv_close($conn_tmi);
                        $conn_tmi = null;
                    }
                }
            }

            // 4b. CTP Oceanic compliance check (runs every ctp_compliance_interval cycles)
            $ctpResult = null;
            if ($config['ctp_compliance_enabled'] && ($stats['runs'] % $config['ctp_compliance_interval'] === 0)) {
                if ($conn_tmi) {
                    try {
                        $ctpResult = executeCtpComplianceCheck($conn, $conn_tmi);
                        if ($ctpResult !== null && $ctpResult['updated'] > 0) {
                            logInfo("CTP compliance: {$ctpResult['updated']} updated across {$ctpResult['sessions']} session(s) ({$ctpResult['ms']}ms)");
                        }
                    } catch (Throwable $e) {
                        logWarn("CTP compliance error: " . $e->getMessage());
                    }
                }
            }

            // 5. Process ATIS (with dynamic tiered intervals)
            $atisImported = 0;
            $atisParsed = 0;
            $atisSkipped = 0;
            if ($config['atis_enabled'] && $vatsimData) {
                $allAtis = extractAtisFromJson($vatsimData);
                $tieredAtis = getAtisForCycle($allAtis, $config, $stats['runs']);

                if (!empty($tieredAtis)) {
                    $atisResult = processAtis($conn, $tieredAtis);
                    $atisImported = $atisResult['imported'];
                    $atisParsed = $atisResult['parsed'];
                    $atisSkipped = $atisResult['skipped'] ?? 0;
                }
            }

            // 5a. Event position logging (TMI compliance)
            // Captures controller positions during active event logging windows
            $eventLogResult = null;
            $eventPositionsLogged = 0;
            if ($config['event_logging_enabled'] && $vatsimData && $stats['runs'] % $config['event_logging_interval'] === 0) {
                $activeEvents = getActiveEventLogging($conn);
                if (!empty($activeEvents)) {
                    $controllers = extractControllersFromJson($vatsimData);
                    if (!empty($controllers)) {
                        $eventLogResult = logEventPositions($conn, $activeEvents, $controllers);
                        foreach ($eventLogResult as $evtResult) {
                            $eventPositionsLogged += $evtResult['positions_logged'] ?? 0;
                        }

                        if ($eventPositionsLogged > 0) {
                            $stats['event_log_runs']++;
                            $stats['event_positions_total'] += $eventPositionsLogged;

                            logInfo("Event positions logged", [
                                'events' => count($activeEvents),
                                'positions' => $eventPositionsLogged,
                                'controllers' => count($controllers),
                            ]);
                        }
                    }
                }
            }

            // Free parsed data
            unset($vatsimData);

            // 5b. Boundary & Crossings processing (adaptive interval)
            $boundaryResult = null;
            $boundaryPending = 0;
            if ($config['boundary_enabled']) {
                static $lastBoundaryPending = 0;
                if ($stats['runs'] % 2 === 0) {
                    $lastBoundaryPending = getBoundaryPendingCount($conn);
                }
                $boundaryPending = $lastBoundaryPending;

                $boundaryInterval = ($boundaryPending > $config['boundary_adaptive_threshold'])
                    ? $config['boundary_interval_fast']
                    : $config['boundary_interval_slow'];

                if ($stats['runs'] % $boundaryInterval === 0) {
                    $boundaryResult = executeBoundaryProcessing($conn, $config);
                    if ($boundaryResult !== null) {
                        $stats['boundary_runs']++;
                        $stats['boundary_transitions'] += $boundaryResult['boundary_transitions'];
                        $stats['boundary_crossings'] += $boundaryResult['crossings_calculated'];
                        $stats['boundary_total_ms'] += $boundaryResult['elapsed_ms'];
                    }
                }
            }

            // 5c. Runway detection from flight tracks (every 30 minutes)
            $runwayResult = null;
            if ($config['runway_detection_enabled'] && $stats['runs'] % $config['runway_detection_interval'] === 0) {
                $runwayResult = executeRunwayDetection($conn, $config);
                if ($runwayResult !== null) {
                    $stats['runway_detect_runs']++;
                    $stats['runway_configs_found'] += $runwayResult['configs_inserted'];
                    $stats['runway_detect_ms'] += $runwayResult['elapsed_ms'];

                    if ($runwayResult['configs_inserted'] > 0) {
                        logInfo("Runway detection completed", [
                            'airports' => $runwayResult['airports_analyzed'],
                            'deps' => $runwayResult['departures_detected'],
                            'arrs' => $runwayResult['arrivals_detected'],
                            'configs' => $runwayResult['configs_inserted'],
                            'ms' => $runwayResult['elapsed_ms'],
                        ]);
                    }
                }
            }

            // 5d. Wind adjustment calculation (tiered, every 30 seconds)
            $windResult = null;
            if ($config['wind_enabled'] && $stats['runs'] % $config['wind_interval'] === 0) {
                $windResult = executeWindCalculation($conn, $config);
                if ($windResult !== null) {
                    $stats['wind_runs']++;
                    $stats['wind_total_ms'] += $windResult['elapsed_ms'];
                }
            }

            // 5e. SWIM API sync (every 2 minutes)
            $swimResult = null;
            $swimMode = null;
            $swimHeartbeatAge = null;
            $swimTick = $config['swim_enabled'] && ($stats['runs'] % $config['swim_interval'] === 0);
            static $lastSwimMode = null;

            if ($swimTick) {
                if (shouldRunInlineSwim($config, $stats['runs'], $swimHeartbeatAge)) {
                    $swimMode = $config['swim_inline_enabled'] ? 'inline' : 'fallback';

                    if ($conn_swim === null) {
                        $conn_swim = getSwimConnection();
                        if ($conn_swim) {
                            logInfo("SWIM_API database connected", [
                                'database' => SWIM_SQL_DATABASE,
                                'mode' => $swimMode,
                            ]);
                        } else {
                            logWarn("SWIM_API connection failed - inline sync unavailable", [
                                'mode' => $swimMode,
                            ]);
                        }
                    }

                    if ($conn_swim !== null) {
                        $swimResult = executeSwimSync($conn, $conn_swim);
                        if ($swimResult !== null) {
                            $stats['swim_runs']++;
                            $stats['swim_synced'] += $swimResult['flights_synced'];
                            $stats['swim_total_ms'] += $swimResult['elapsed_ms'];
                        }
                    }
                } else {
                    $swimMode = 'daemon';
                    $swimHeartbeatAge = getSwimDaemonHeartbeatAge($config);
                    // Release fallback connection now that daemon is healthy
                    if ($conn_swim !== null && empty($config['swim_inline_enabled'])) {
                        @sqlsrv_close($conn_swim);
                        $conn_swim = null;
                    }
                }
            }

            if ($swimMode !== null && $swimMode !== $lastSwimMode) {
                if ($swimMode === 'daemon') {
                    logInfo("SWIM mode active: daemon", ['heartbeat_age_s' => $swimHeartbeatAge]);
                } elseif ($swimMode === 'fallback') {
                    logWarn("SWIM mode active: fallback inline (daemon heartbeat stale/missing)", [
                        'heartbeat_age_s' => $swimHeartbeatAge,
                    ]);
                } else {
                    logWarn("SWIM mode active: forced inline");
                }
                $lastSwimMode = $swimMode;
            }

            // 5f. WebSocket real-time events
            $wsResult = null;
            if ($config['websocket_enabled']) {
                $currentTime = gmdate('Y-m-d H:i:s');
                
                if ($lastRefreshTime !== null) {
                    $wsResult = swim_processWebSocketEvents($conn, $lastRefreshTime, $config['websocket_positions']);
                    if ($wsResult !== null && $wsResult['total_events'] > 0) {
                        $stats['ws_runs']++;
                        $stats['ws_events'] += $wsResult['total_events'];
                    }
                }
                $lastRefreshTime = $currentTime;
            }

            // 5g. Flight stats scheduled jobs (every 15 minutes)
            $flightStatsResult = null;
            if ($config['flight_stats_enabled'] && $stats['runs'] % $config['flight_stats_interval'] === 0) {
                $flightStatsResult = executeFlightStatsJobs($conn);
                if ($flightStatsResult !== null) {
                    $stats['fstats_runs']++;
                    $stats['fstats_total_ms'] += $flightStatsResult['elapsed_ms'];

                    // Log when jobs run (since they may execute hourly/daily aggregation)
                    if ($flightStatsResult['elapsed_ms'] > 1000) {
                        logInfo("Flight stats jobs processed", [
                            'elapsed_ms' => $flightStatsResult['elapsed_ms'],
                        ]);
                    }
                }
            }

            // 6. Update stats
            $stats['successes']++;
            $stats['total_sp_ms'] += $spMs;
            $stats['total_flights'] += $pilotCount;
            $stats['total_atis'] += $atisImported;
            $stats['total_parsed'] += $atisParsed;
            $stats['total_skipped'] += $atisSkipped;
            if ($spMs > $stats['max_sp_ms']) {
                $stats['max_sp_ms'] = $spMs;
            }

            // V9.0 Staging stats
            if ($config['staged_refresh_enabled']) {
                $stats['total_staging_ms'] += $stagingMs;
                $stats['total_insert_ms'] += $insertMs;
                $stats['total_heartbeat'] += $deltaStats['heartbeat'] ?? 0;
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

            // Add staging metrics when using V9.0 staged refresh
            if ($config['staged_refresh_enabled'] && ($stagingMs > 0 || $insertMs > 0)) {
                $logContext['parse_ms'] = $parseMs;
                $logContext['stg_ms'] = $stagingMs;
                $logContext['ins_ms'] = $insertMs;
                // V9.3 delta stats
                $logContext['hb'] = $deltaStats['heartbeat'] ?? 0;
            }

            if ($atisImported > 0 || $atisParsed > 0 || $atisSkipped > 0) {
                $logContext['atis'] = $atisImported;
                $logContext['parsed'] = $atisParsed;
                if ($atisSkipped > 0) {
                    $logContext['skipped'] = $atisSkipped;
                }
            }

            if ($boundaryResult !== null) {
                $logContext['bnd_ms'] = $boundaryResult['elapsed_ms'];
                $logContext['bnd_pending'] = $boundaryPending;
                $logContext['bnd_mode'] = ($boundaryPending > $config['boundary_adaptive_threshold']) ? 'FAST' : 'slow';
                if ($boundaryResult['boundary_transitions'] > 0) {
                    $logContext['bnd_trans'] = $boundaryResult['boundary_transitions'];
                }
                if ($boundaryResult['crossings_calculated'] > 0) {
                    $logContext['crossings'] = $boundaryResult['crossings_calculated'];
                }
            }

            if ($swimMode !== null) {
                $logContext['swim_mode'] = $swimMode;
                if ($swimHeartbeatAge !== null) {
                    $logContext['swim_hb_s'] = $swimHeartbeatAge;
                }
            }

            if ($swimResult !== null) {
                $logContext['swim_ms'] = $swimResult['elapsed_ms'];
                $logContext['swim_sync'] = $swimResult['flights_synced'];
                if ($swimResult['inserted'] > 0) {
                    $logContext['swim_ins'] = $swimResult['inserted'];
                }
            }

            if ($wsResult !== null && $wsResult['total_events'] > 0) {
                $logContext['ws_events'] = $wsResult['total_events'];
            }

            // V9.2: Deferred processing metrics
            if ($deferredResult !== null) {
                $logContext['def_ms'] = $deferredResult['elapsed_ms'];
                if ($deferredResult['skipped']) {
                    $logContext['def'] = 'SKIP';
                } else {
                    if ($deferredResult['eta_basic'] !== null) {
                        $logContext['def_eta1'] = $deferredResult['eta_basic']['count'];
                    }
                    if ($deferredResult['eta_batch'] !== null) {
                        $logContext['def_eta2'] = $deferredResult['eta_batch']['count'];
                    }
                    if (isset($deferredResult['backfill'])) {
                        $logContext['bf'] = $deferredResult['backfill'];
                    }
                }
            }

            if ($tmiSyncResult !== null && ($tmiSyncResult['synced'] > 0 || $tmiSyncResult['cleaned'] > 0)) {
                $logContext['tmi'] = $tmiSyncResult['synced'];
                $logContext['tmi_clean'] = $tmiSyncResult['cleaned'];
                $logContext['tmi_ms'] = $tmiSyncResult['ms'];
            }

            if ($ctpResult !== null && $ctpResult['updated'] > 0) {
                $logContext['ctp'] = $ctpResult['updated'];
                $logContext['ctp_ms'] = $ctpResult['ms'];
            }

            logMessage($logLevel, "Refresh #{$stats['runs']}{$perfNote}", $logContext);

            // V8.9: Log step timings when slow (>5s)
            if ($spMs >= $config['warn_sp_ms'] && !empty($spResult['steps'])) {
                $steps = $spResult['steps'];
                arsort($steps);
                $topSteps = array_slice($steps, 0, 5, true);

                $stepParts = [];
                foreach ($topSteps as $step => $ms) {
                    if ($ms > 100) {
                        $stepParts[] = "{$step}={$ms}ms";
                    }
                }

                if (!empty($stepParts)) {
                    logWarn("  Slow steps: " . implode(', ', $stepParts));
                }
            }
            
        } catch (Throwable $e) {
            $stats['failures']++;
            logError("Refresh #{$stats['runs']} FAILED", ['error' => $e->getMessage()]);
            
            try {
                safeCloseConnection($conn);
                $conn = getConnection($config);
                logInfo("Reconnected after error");
            } catch (Throwable $re) {
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

            $avgBndMs = $stats['boundary_runs'] > 0 ? round($stats['boundary_total_ms'] / $stats['boundary_runs']) : 0;
            $avgRwyMs = $stats['runway_detect_runs'] > 0 ? round($stats['runway_detect_ms'] / $stats['runway_detect_runs']) : 0;
            $avgWindMs = $stats['wind_runs'] > 0 ? round($stats['wind_total_ms'] / $stats['wind_runs']) : 0;
            $avgStagingMs = $stats['successes'] > 0 ? round($stats['total_staging_ms'] / $stats['successes']) : 0;
            $avgInsertMs = $stats['successes'] > 0 ? round($stats['total_insert_ms'] / $stats['successes']) : 0;

            $statsContext = [
                'uptime_min'    => $uptime,
                'success_rate'  => "{$successRate}%",
                'avg_sp_ms'     => $avgSpMs,
                'max_sp_ms'     => $stats['max_sp_ms'],
                'avg_flights'   => $avgFlights,
                'total_atis'    => $stats['total_atis'],
                'atis_parsed'   => $stats['total_parsed'],
                'atis_skipped'  => $stats['total_skipped'],
                'bnd_runs'      => $stats['boundary_runs'],
                'bnd_trans'     => $stats['boundary_transitions'],
                'bnd_cross'     => $stats['boundary_crossings'],
                'avg_bnd_ms'    => $avgBndMs,
                'rwy_runs'      => $stats['runway_detect_runs'],
                'rwy_configs'   => $stats['runway_configs_found'],
                'avg_rwy_ms'    => $avgRwyMs,
                'wind_runs'     => $stats['wind_runs'],
                'avg_wind_ms'   => $avgWindMs,
                'ws_runs'       => $stats['ws_runs'],
                'ws_events'     => $stats['ws_events'],
                'fstats_runs'   => $stats['fstats_runs'],
                'evt_log_runs'  => $stats['event_log_runs'],
                'evt_positions' => $stats['event_positions_total'],
            ];

            // Add V9.0/V9.2 staging stats if enabled
            if ($config['staged_refresh_enabled']) {
                $statsContext['mode'] = $config['use_tvp'] ? 'bulk' : 'batched';
                $statsContext['avg_stg_ms'] = $avgStagingMs;
                $statsContext['avg_ins_ms'] = $avgInsertMs;
            }

            // V9.2 Deferred processing stats
            if ($config['defer_expensive']) {
                $avgDefMs = $stats['deferred_runs'] > 0 ? round($stats['deferred_total_ms'] / $stats['deferred_runs']) : 0;
                $statsContext['def_runs'] = $stats['deferred_runs'];
                $statsContext['def_skip'] = $stats['deferred_skipped'];
                $statsContext['avg_def_ms'] = $avgDefMs;
            }

            logInfo("=== Stats @ run {$stats['runs']} ===", $statsContext);

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
        
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
        
        if ($conn === null && $running) {
            try {
                $conn = getConnection($config);
                logInfo("Connection restored");
            } catch (Throwable $e) {
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
    
    if ($conn_tmi) {
        @sqlsrv_close($conn_tmi);
    }
    if ($conn) {
        safeCloseConnection($conn);
    }
}

// ============================================================================
// ENTRY POINT
// ============================================================================

if (!extension_loaded('sqlsrv')) {
    die("ERROR: sqlsrv extension not loaded.\n" .
        "Linux: sudo pecl install sqlsrv\n" .
        "Windows: Download from Microsoft and enable in php.ini\n");
}

if (!function_exists('curl_init')) {
    logWarn("cURL not available, using file_get_contents (slower)");
}

$lockFile = __DIR__ . '/vatsim_adl.lock';
$lockFp = @fopen($lockFile, 'c+');
if ($lockFp === false) {
    // Try system temp directory as fallback
    $lockFile = sys_get_temp_dir() . '/vatsim_adl.lock';
    $lockFp = @fopen($lockFile, 'c+');
    if ($lockFp === false) {
        die("ERROR: Cannot create lock file. Tried:\n  - " . __DIR__ . "/vatsim_adl.lock\n  - {$lockFile}\n");
    }
    logInfo("Using fallback lock file: {$lockFile}");
}
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    die("ERROR: Another instance is already running (lock file: {$lockFile})\n");
}
ftruncate($lockFp, 0);
fwrite($lockFp, (string)getmypid());
fflush($lockFp);

runDaemon($config);

flock($lockFp, LOCK_UN);
fclose($lockFp);
@unlink($lockFile);
