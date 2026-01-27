<?php
/**
 * SimTraffic -> SWIM Polling Daemon
 *
 * Background process that polls SimTraffic API for active flights
 * and ingests timing data into SWIM database.
 *
 * Features:
 *   - Polls active flights from SWIM that need SimTraffic data
 *   - Respects SimTraffic API rate limit (5 requests/second)
 *   - Circuit breaker pattern for API resilience
 *   - Caching to reduce redundant API calls
 *   - Delta polling based on last poll time
 *
 * Usage:
 *   php simtraffic_swim_poll.php [--loop] [--once] [--debug] [--interval=120]
 *
 * Options:
 *   --loop       Run continuously (default: single run)
 *   --once       Run once and exit (overrides --loop)
 *   --debug      Enable verbose logging
 *   --interval=N Seconds between poll cycles (default: 120)
 *
 * @package PERTI
 * @subpackage SWIM
 * @version 1.0.0
 * @since 2026-01-27
 */

// Can be run standalone or included
if (!defined('PERTI_LOADED')) {
    define('PERTI_LOADED', true);
    require_once __DIR__ . '/../load/config.php';
    require_once __DIR__ . '/../load/connect.php';
}

// Configuration
define('ST_POLL_RATE_LIMIT_MS', 200);         // 200ms = 5 req/sec
define('ST_POLL_CACHE_TTL', 120);             // 2 minutes cache
define('ST_POLL_ERROR_CACHE_TTL', 30);        // 30 seconds error cache
define('ST_POLL_CIRCUIT_WINDOW', 60);         // 60 second rolling window
define('ST_POLL_CIRCUIT_MAX_ERRORS', 6);      // Max errors before cooldown
define('ST_POLL_CIRCUIT_COOLDOWN', 180);      // 3 minute cooldown
define('ST_POLL_BATCH_SIZE', 50);             // Flights per batch
define('ST_POLL_STATE_FILE', sys_get_temp_dir() . '/perti_simtraffic_poll_state.json');
define('ST_POLL_CACHE_DIR', sys_get_temp_dir() . '/perti_simtraffic_cache/');

// Ensure cache directory exists
if (!is_dir(ST_POLL_CACHE_DIR)) {
    @mkdir(ST_POLL_CACHE_DIR, 0755, true);
}

/**
 * Main polling function
 *
 * @param bool $debug Enable verbose output
 * @return array ['success' => bool, 'message' => string, 'stats' => array]
 */
function simtraffic_poll_to_swim($debug = false) {
    global $conn_swim;

    $stats = [
        'start_time' => microtime(true),
        'flights_queried' => 0,
        'api_calls' => 0,
        'cache_hits' => 0,
        'updated' => 0,
        'not_found' => 0,
        'api_errors' => 0,
        'skipped_cooldown' => 0,
        'duration_ms' => 0
    ];

    if (!$conn_swim) {
        return ['success' => false, 'message' => 'SWIM database connection not available', 'stats' => $stats];
    }

    // Check circuit breaker
    if (is_circuit_open()) {
        $stats['skipped_cooldown'] = 1;
        return ['success' => true, 'message' => 'Circuit breaker open - in cooldown', 'stats' => $stats];
    }

    try {
        // Get active flights that need SimTraffic polling
        $callsigns = get_flights_needing_simtraffic($conn_swim, $debug);
        $stats['flights_queried'] = count($callsigns);

        if (count($callsigns) === 0) {
            $stats['duration_ms'] = round((microtime(true) - $stats['start_time']) * 1000);
            return ['success' => true, 'message' => 'No flights need SimTraffic polling', 'stats' => $stats];
        }

        if ($debug) {
            echo "Found " . count($callsigns) . " flights to poll\n";
        }

        // Process in batches
        foreach (array_chunk($callsigns, ST_POLL_BATCH_SIZE) as $batch) {
            foreach ($batch as $callsign) {
                // Check cache first
                $cached = get_cached_simtraffic($callsign);
                if ($cached !== null) {
                    $stats['cache_hits']++;
                    if ($cached !== false) {
                        $result = ingest_simtraffic_to_swim($conn_swim, $callsign, $cached);
                        if ($result) $stats['updated']++;
                    }
                    continue;
                }

                // Fetch from API
                $data = fetch_simtraffic_api($callsign, $debug);
                $stats['api_calls']++;

                if ($data === null) {
                    // API error
                    $stats['api_errors']++;
                    record_error();

                    // Check if we should trip circuit breaker
                    if (should_trip_circuit()) {
                        trip_circuit();
                        if ($debug) echo "Circuit breaker tripped!\n";
                        break 2;  // Exit both loops
                    }
                    continue;
                }

                if ($data === false) {
                    // Flight not found in SimTraffic
                    $stats['not_found']++;
                    cache_simtraffic($callsign, false, ST_POLL_CACHE_TTL);
                    continue;
                }

                // Cache successful response
                cache_simtraffic($callsign, $data, ST_POLL_CACHE_TTL);

                // Ingest into SWIM
                $result = ingest_simtraffic_to_swim($conn_swim, $callsign, $data);
                if ($result) {
                    $stats['updated']++;
                    if ($debug) echo "  Updated: $callsign\n";
                }

                // Rate limit: 200ms between API calls
                usleep(ST_POLL_RATE_LIMIT_MS * 1000);
            }
        }

        $stats['duration_ms'] = round((microtime(true) - $stats['start_time']) * 1000);

        return [
            'success' => true,
            'message' => sprintf(
                'Poll complete: %d queried, %d API calls, %d cache hits, %d updated in %dms',
                $stats['flights_queried'], $stats['api_calls'], $stats['cache_hits'],
                $stats['updated'], $stats['duration_ms']
            ),
            'stats' => $stats
        ];

    } catch (Exception $e) {
        $stats['duration_ms'] = round((microtime(true) - $stats['start_time']) * 1000);
        error_log('SimTraffic poll error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Poll error: ' . $e->getMessage(), 'stats' => $stats];
    }
}

/**
 * Get list of active flights that need SimTraffic polling
 *
 * Criteria:
 *   - Active flights (is_active = 1)
 *   - Not recently polled (simtraffic_sync_utc > 2 min ago or NULL)
 *   - Enroute or in ground phase (not arrived)
 */
function get_flights_needing_simtraffic($conn, $debug = false) {
    $sql = "
        SELECT DISTINCT sf.callsign
        FROM dbo.swim_flights sf
        WHERE sf.is_active = 1
          AND (sf.phase IS NULL OR sf.phase NOT IN ('arrived', 'disconnected'))
          AND (
              sf.simtraffic_sync_utc IS NULL
              OR sf.simtraffic_sync_utc < DATEADD(SECOND, -?, GETUTCDATE())
          )
        ORDER BY sf.callsign
    ";

    $stmt = sqlsrv_query($conn, $sql, [ST_POLL_CACHE_TTL]);
    if ($stmt === false) {
        throw new Exception('Failed to query flights');
    }

    $callsigns = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $callsigns[] = $row['callsign'];
    }
    sqlsrv_free_stmt($stmt);

    return $callsigns;
}

/**
 * Fetch flight data from SimTraffic API
 *
 * @param string $callsign
 * @param bool $debug
 * @return array|false|null Data array, false if not found, null on error
 */
function fetch_simtraffic_api($callsign, $debug = false) {
    // Get API key
    $apiKey = getenv('SIMTRAFFIC_API_KEY');
    if (!$apiKey && defined('SIMTRAFFIC_API_KEY')) {
        $apiKey = SIMTRAFFIC_API_KEY;
    }

    if (!$apiKey) {
        error_log('SIMTRAFFIC_API_KEY not configured');
        return null;
    }

    $url = 'https://api.simtraffic.net/v1/flight/' . rawurlencode($callsign);

    if ($debug) {
        echo "  API: $callsign... ";
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $apiKey,
            'Accept: application/json'
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        if ($debug) echo "CURL ERROR: $curlError\n";
        return null;  // Network error - count toward circuit breaker
    }

    if ($httpCode === 404) {
        if ($debug) echo "NOT FOUND\n";
        return false;  // Flight not in SimTraffic - cache as not found
    }

    if ($httpCode === 401) {
        // Auth error - log but don't trip circuit (config issue)
        if ($debug) echo "UNAUTHORIZED (check API key)\n";
        error_log('SimTraffic API 401 Unauthorized - check SIMTRAFFIC_API_KEY');
        return false;  // Treat as "not available" rather than transient error
    }

    if ($httpCode === 400) {
        // Bad request - likely malformed callsign, don't trip circuit
        if ($debug) echo "BAD REQUEST\n";
        return false;
    }

    if ($httpCode === 403) {
        // Forbidden - likely CloudFlare/WAF or IP block, log but don't trip circuit
        if ($debug) echo "FORBIDDEN (possible IP block)\n";
        static $logged403 = false;
        if (!$logged403) {
            error_log('SimTraffic API 403 Forbidden - possible CloudFlare/IP block');
            $logged403 = true;  // Only log once per process
        }
        return false;
    }

    if ($httpCode !== 200) {
        if ($debug) echo "HTTP $httpCode\n";
        return null;  // Other errors (5xx, etc.) - trip circuit breaker
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        if ($debug) echo "JSON ERROR\n";
        return null;
    }

    if ($debug) echo "OK\n";
    return $data;
}

/**
 * Ingest SimTraffic data into SWIM database
 *
 * Note: During FIXM migration transition, we dual-write to both legacy
 * columns (out_utc, off_utc, etc.) and new FIXM-aligned columns
 * (actual_off_block_time, actual_time_of_departure, etc.)
 */
function ingest_simtraffic_to_swim($conn, $callsign, $stData) {
    $departure = $stData['departure'] ?? [];
    $arrival = $stData['arrival'] ?? [];
    $status = $stData['status'] ?? [];
    $dest_icao = $stData['arrival_afld'] ?? null;

    // Build UPDATE
    $updates = [];
    $params = [];

    // Departure times (dual-write: legacy + FIXM columns)
    if (!empty($departure['push_time'])) {
        // Legacy: out_utc
        $updates[] = 'out_utc = TRY_CONVERT(datetime2, ?)';
        $params[] = $departure['push_time'];
        // FIXM: actual_off_block_time (AOBT)
        $updates[] = 'actual_off_block_time = TRY_CONVERT(datetime2, ?)';
        $params[] = $departure['push_time'];
    }
    if (!empty($departure['taxi_time'])) {
        // Legacy: taxi_time_utc
        $updates[] = 'taxi_time_utc = TRY_CONVERT(datetime2, ?)';
        $params[] = $departure['taxi_time'];
        // FIXM: taxi_start_time
        $updates[] = 'taxi_start_time = TRY_CONVERT(datetime2, ?)';
        $params[] = $departure['taxi_time'];
    }
    if (!empty($departure['sequence_time'])) {
        // Legacy: sequence_time_utc
        $updates[] = 'sequence_time_utc = TRY_CONVERT(datetime2, ?)';
        $params[] = $departure['sequence_time'];
        // FIXM: departure_sequence_time
        $updates[] = 'departure_sequence_time = TRY_CONVERT(datetime2, ?)';
        $params[] = $departure['sequence_time'];
    }
    if (!empty($departure['holdshort_time'])) {
        // Legacy: holdshort_time_utc
        $updates[] = 'holdshort_time_utc = TRY_CONVERT(datetime2, ?)';
        $params[] = $departure['holdshort_time'];
        // FIXM: hold_short_time
        $updates[] = 'hold_short_time = TRY_CONVERT(datetime2, ?)';
        $params[] = $departure['holdshort_time'];
    }
    if (!empty($departure['runway_time'])) {
        // Legacy: runway_time_utc
        $updates[] = 'runway_time_utc = TRY_CONVERT(datetime2, ?)';
        $params[] = $departure['runway_time'];
        // FIXM: runway_entry_time
        $updates[] = 'runway_entry_time = TRY_CONVERT(datetime2, ?)';
        $params[] = $departure['runway_time'];
    }
    if (!empty($departure['takeoff_time'])) {
        // Legacy: off_utc
        $updates[] = 'off_utc = TRY_CONVERT(datetime2, ?)';
        $params[] = $departure['takeoff_time'];
        // FIXM: actual_time_of_departure (ATOT)
        $updates[] = 'actual_time_of_departure = TRY_CONVERT(datetime2, ?)';
        $params[] = $departure['takeoff_time'];
    }
    if (!empty($departure['edct'])) {
        $updates[] = 'edct_utc = TRY_CONVERT(datetime2, ?)';
        $params[] = $departure['edct'];
        // Note: edct_utc stays as-is (already FIXM-aligned abbreviation)
    }

    // Arrival times (dual-write: legacy + FIXM columns)
    if (!empty($arrival['eta'])) {
        // Legacy: eta_utc, eta_runway_utc
        $updates[] = 'eta_utc = TRY_CONVERT(datetime2, ?)';
        $params[] = $arrival['eta'];
        $updates[] = 'eta_runway_utc = TRY_CONVERT(datetime2, ?)';
        $params[] = $arrival['eta'];
        // FIXM: estimated_time_of_arrival, estimated_runway_arrival_time
        $updates[] = 'estimated_time_of_arrival = TRY_CONVERT(datetime2, ?)';
        $params[] = $arrival['eta'];
        $updates[] = 'estimated_runway_arrival_time = TRY_CONVERT(datetime2, ?)';
        $params[] = $arrival['eta'];
    }

    $eta_mf = $arrival['eta_mf'] ?? $arrival['etaMF'] ?? $arrival['mft'] ?? $arrival['MFT'] ?? null;
    if (!empty($eta_mf)) {
        $updates[] = 'metering_time = TRY_CONVERT(datetime2, ?)';
        $params[] = $eta_mf;
    }

    $eta_vt = $arrival['eta_vertex'] ?? $arrival['eta_vt'] ?? $arrival['vt'] ?? null;
    if (!empty($eta_vt)) {
        $updates[] = 'eta_vertex = TRY_CONVERT(datetime2, ?)';
        $params[] = $eta_vt;
    }

    $on_time = $arrival['on_time'] ?? $arrival['on_utc'] ?? null;
    if (!empty($on_time)) {
        // Legacy: on_utc
        $updates[] = 'on_utc = TRY_CONVERT(datetime2, ?)';
        $params[] = $on_time;
        // FIXM: actual_landing_time (ALDT)
        $updates[] = 'actual_landing_time = TRY_CONVERT(datetime2, ?)';
        $params[] = $on_time;
    }

    $meter_fix = $arrival['metering_fix'] ?? $arrival['meter_fix'] ?? null;
    if (!empty($meter_fix)) {
        $updates[] = 'metering_point = ?';
        $params[] = strtoupper(trim($meter_fix));
    }

    $rwy = $arrival['rwy_assigned'] ?? $arrival['runway'] ?? null;
    if (!empty($rwy)) {
        $updates[] = 'arr_runway = ?';
        $params[] = strtoupper(trim($rwy));
    }

    // Status
    $phase = null;
    if (!empty($status['arrived']) || !empty($arrival['arrived'])) {
        $phase = 'arrived';
    } elseif (!empty($status['departed']) || !empty($departure['takeoff_time'])) {
        $phase = 'enroute';
    } elseif (!empty($departure['taxi_time']) || !empty($departure['push_time'])) {
        $phase = 'taxiing';
    }

    if ($phase) {
        $updates[] = 'phase = ?';
        $params[] = $phase;
        $updates[] = 'simtraffic_phase = ?';
        $params[] = $phase;
    }

    if (!empty($status['in_artcc'])) {
        $updates[] = 'current_artcc = ?';
        $params[] = strtoupper(trim($status['in_artcc']));
    }

    if (isset($status['delay_value'])) {
        $updates[] = 'metering_delay = ?';
        $params[] = intval($status['delay_value']);
    }

    // Tracking
    $updates[] = 'metering_source = ?';
    $params[] = 'simtraffic';
    $updates[] = 'simtraffic_sync_utc = GETUTCDATE()';
    $updates[] = 'last_sync_utc = GETUTCDATE()';

    if (empty($updates)) {
        return false;
    }

    // Find flight by callsign (and optionally destination)
    $params[] = strtoupper(trim($callsign));

    $where = "callsign = ? AND is_active = 1";
    if ($dest_icao) {
        $where .= " AND fp_dest_icao = ?";
        $params[] = strtoupper(trim($dest_icao));
    }

    $sql = "UPDATE dbo.swim_flights SET " . implode(', ', $updates) . " WHERE " . $where;

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        $err = sqlsrv_errors();
        error_log('SimTraffic ingest error: ' . ($err[0]['message'] ?? 'Unknown'));
        return false;
    }

    $rows = sqlsrv_rows_affected($stmt);
    sqlsrv_free_stmt($stmt);

    return $rows > 0;
}

// === CACHING ===

function get_cached_simtraffic($callsign) {
    $file = ST_POLL_CACHE_DIR . 'st_' . preg_replace('/[^A-Z0-9]/', '', strtoupper($callsign)) . '.json';

    if (!file_exists($file)) {
        return null;
    }

    $mtime = filemtime($file);
    $age = time() - $mtime;

    $content = file_get_contents($file);
    $data = json_decode($content, true);

    if (!$data) {
        return null;
    }

    $ttl = $data['_error'] ? ST_POLL_ERROR_CACHE_TTL : ST_POLL_CACHE_TTL;

    if ($age > $ttl) {
        @unlink($file);
        return null;
    }

    if ($data['_not_found'] ?? false) {
        return false;
    }

    return $data;
}

function cache_simtraffic($callsign, $data, $ttl) {
    $file = ST_POLL_CACHE_DIR . 'st_' . preg_replace('/[^A-Z0-9]/', '', strtoupper($callsign)) . '.json';

    if ($data === false) {
        $data = ['_not_found' => true, '_cached_at' => time()];
    } else {
        $data['_cached_at'] = time();
    }

    file_put_contents($file, json_encode($data));
}

// === CIRCUIT BREAKER ===

function get_circuit_state() {
    if (!file_exists(ST_POLL_STATE_FILE)) {
        return ['errors' => [], 'cooldown_until' => null];
    }
    return json_decode(file_get_contents(ST_POLL_STATE_FILE), true) ?? ['errors' => [], 'cooldown_until' => null];
}

function save_circuit_state($state) {
    file_put_contents(ST_POLL_STATE_FILE, json_encode($state));
}

function is_circuit_open() {
    $state = get_circuit_state();
    if (!empty($state['cooldown_until']) && $state['cooldown_until'] > time()) {
        return true;
    }
    return false;
}

function record_error() {
    $state = get_circuit_state();
    $now = time();

    // Clean old errors outside window
    $state['errors'] = array_filter($state['errors'] ?? [], function($ts) use ($now) {
        return ($now - $ts) <= ST_POLL_CIRCUIT_WINDOW;
    });

    $state['errors'][] = $now;
    save_circuit_state($state);
}

function should_trip_circuit() {
    $state = get_circuit_state();
    return count($state['errors'] ?? []) >= ST_POLL_CIRCUIT_MAX_ERRORS;
}

function trip_circuit() {
    $state = get_circuit_state();
    $state['cooldown_until'] = time() + ST_POLL_CIRCUIT_COOLDOWN;
    $state['errors'] = [];
    save_circuit_state($state);
}

// === CLI RUNNER ===

if (php_sapi_name() === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $loop = in_array('--loop', $argv);
    $once = in_array('--once', $argv);
    $debug = in_array('--debug', $argv);
    $interval = 120;

    // Parse interval
    foreach ($argv as $arg) {
        if (preg_match('/^--interval=(\d+)$/', $arg, $m)) {
            $interval = intval($m[1]);
        }
    }

    // --once overrides --loop for backward compatibility
    $continuous = $loop && !$once;

    echo "SimTraffic -> SWIM Polling Daemon\n";
    echo "=================================\n";
    echo "Mode: " . ($continuous ? "Continuous (every {$interval}s)" : 'Single run') . "\n";
    echo "Debug: " . ($debug ? 'ON' : 'OFF') . "\n\n";

    do {
        echo "[" . date('Y-m-d H:i:s') . "] Starting poll cycle...\n";

        $result = simtraffic_poll_to_swim($debug);

        echo "Result: " . ($result['success'] ? 'SUCCESS' : 'FAILED') . "\n";
        echo "Message: " . $result['message'] . "\n";

        if ($continuous) {
            echo "Sleeping for {$interval} seconds...\n\n";
            sleep($interval);
        }

    } while ($continuous);

    exit($result['success'] ? 0 : 1);
}
