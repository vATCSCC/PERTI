<?php
/**
 * vIFF CDM Polling Daemon
 *
 * Polls the vIFF ATFCM System (viff-system.network) for A-CDM milestone data
 * and writes directly to SWIM_API.swim_flights. vIFF is the European ATFCM
 * backend for VATSIM by Roger Puig, powering the EuroScope CDM plugin used
 * by 32+ vACCs.
 *
 * This daemon runs even during HIBERNATION_MODE since vIFF CDM milestones
 * are external data that should always be ingested (SWIM exempt).
 *
 * Data flow:
 *   vIFF System (REST API) → this daemon → SWIM_API.swim_flights
 *
 * Endpoints polled:
 *   GET /etfms/relevant    — All CDM flight data (TOBT/taxi/CTOT/AOBT/ATOT)
 *   GET /etfms/restricted  — CTOT restrictions (regulation-assigned CTOTs)
 *   GET /ifps/allStatus    — ATFCM status per flight (REA/FLS/SIR/EXCLUDED)
 *
 * Features:
 *   - 3-tier GUFI-based flight matching (GUFI → cs+dept+dest → cs+dept)
 *   - HHMM/HHMMSS time format auto-detection with midnight rollover
 *   - TSAT/TTOT derivation from CTOT+taxi or TOBT+taxi
 *   - Circuit breaker pattern (file-based, 6 errors/60s → 3-min cooldown)
 *   - MD5-hash cache for change detection
 *   - Provider sync status tracking in tmi_flow_providers
 *
 * Usage:
 *   php viff_cdm_poll_daemon.php [--loop] [--interval=30] [--debug]
 *
 * @package PERTI
 * @subpackage VIFF
 * @version 1.0.0
 */

// Parse CLI arguments
$options = getopt('', ['loop', 'interval:', 'debug']);
$runLoop = isset($options['loop']);
$pollInterval = isset($options['interval']) ? (int)$options['interval'] : 30;
$debug = isset($options['debug']);

// Enforce minimum
$pollInterval = max(15, $pollInterval);

// Load dependencies
if (!defined('PERTI_LOADED')) {
    define('PERTI_LOADED', true);
    require_once __DIR__ . '/../load/config.php';
    require_once __DIR__ . '/../load/connect.php';
}

// Load swim_config for swim_generate_gufi_legacy()
require_once __DIR__ . '/../load/swim_config.php';

// Load CDM + EDCT delivery for CTOT notification dispatch
require_once __DIR__ . '/../load/services/CDMService.php';
require_once __DIR__ . '/../load/services/EDCTDelivery.php';

// ============================================================================
// Constants
// ============================================================================

define('VIFF_API_BASE', defined('VIFF_API_URL') ? VIFF_API_URL : 'https://viff-system.network');
define('VIFF_STATE_FILE', sys_get_temp_dir() . '/perti_viff_cdm_state.json');
define('VIFF_CACHE_FILE', sys_get_temp_dir() . '/perti_viff_cdm_cache.json');
define('VIFF_CIRCUIT_WINDOW', 60);
define('VIFF_CIRCUIT_MAX_ERRORS', 6);
define('VIFF_CIRCUIT_COOLDOWN', 180);
define('VIFF_PROVIDER_CODE', 'VIFF');

// ============================================================================
// Logging
// ============================================================================

function viff_log(string $message, string $level = 'INFO'): void {
    $timestamp = gmdate('Y-m-d H:i:s');
    echo "[$timestamp UTC] [$level] $message\n";
}

// ============================================================================
// Circuit Breaker
// ============================================================================

require_once __DIR__ . '/../lib/connectors/CircuitBreaker.php';

$viff_circuit_breaker = new \PERTI\Lib\Connectors\CircuitBreaker(
    VIFF_STATE_FILE,
    VIFF_CIRCUIT_WINDOW,
    VIFF_CIRCUIT_MAX_ERRORS,
    VIFF_CIRCUIT_COOLDOWN
);

function viff_is_circuit_open(): bool {
    global $viff_circuit_breaker;
    return $viff_circuit_breaker->isOpen();
}

function viff_record_error(): void {
    global $viff_circuit_breaker;
    if ($viff_circuit_breaker->recordError()) {
        viff_log("Circuit breaker tripped — cooldown " . VIFF_CIRCUIT_COOLDOWN . "s", 'WARN');
    }
}

function viff_reset_circuit(): void {
    global $viff_circuit_breaker;
    $viff_circuit_breaker->reset();
}

// ============================================================================
// HTTP Helper
// ============================================================================

/**
 * Fetch JSON from vIFF API endpoint (single request).
 *
 * @param string $url Full URL to fetch
 * @return array|null Decoded JSON or null on failure
 */
function viff_fetch(string $url): ?array {
    $apiKey = defined('VIFF_API_KEY') ? VIFF_API_KEY : '';

    $headers = [
        'Accept: application/json',
        'User-Agent: PERTI-VIFF-CDM-Daemon/1.0',
    ];
    if ($apiKey !== '') {
        $headers[] = 'x-api-key: ' . $apiKey;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        viff_log("HTTP $httpCode from $url" . ($error ? ": $error" : ''), 'ERROR');
        viff_record_error();
        return null;
    }

    $data = json_decode($response, true);
    if ($data === null) {
        viff_log("Invalid JSON from $url", 'ERROR');
        viff_record_error();
        return null;
    }

    return $data;
}

/**
 * Fetch multiple vIFF endpoints in parallel using curl_multi.
 *
 * @param array $urls Associative array of key => URL
 * @return array Associative array of key => decoded JSON (null on failure)
 */
function viff_fetch_multi(array $urls, bool $recordErrors = true): array {
    $apiKey = defined('VIFF_API_KEY') ? VIFF_API_KEY : '';

    $headers = [
        'Accept: application/json',
        'User-Agent: PERTI-VIFF-CDM-Daemon/1.0',
    ];
    if ($apiKey !== '') {
        $headers[] = 'x-api-key: ' . $apiKey;
    }

    $mh = curl_multi_init();
    $handles = [];

    foreach ($urls as $key => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }

    // Execute all in parallel
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running > 0) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running > 0);

    // Collect results
    $results = [];
    foreach ($handles as $key => $ch) {
        $response = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($response === false || $httpCode !== 200) {
            viff_log("HTTP $httpCode from {$urls[$key]}" . ($error ? ": $error" : ''), 'ERROR');
            if ($recordErrors) viff_record_error();
            $results[$key] = null;
        } else {
            $data = json_decode($response, true);
            if ($data === null) {
                viff_log("Invalid JSON from {$urls[$key]}", 'ERROR');
                if ($recordErrors) viff_record_error();
            }
            $results[$key] = $data;
        }

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    curl_multi_close($mh);
    return $results;
}

// ============================================================================
// Time Conversion
// ============================================================================

/**
 * Convert vIFF HHMM or HHMMSS time string to ISO 8601 datetime.
 *
 * vIFF uses HHMM (4-digit) in API JSON responses and HHMMSS (6-digit) in
 * FTP export format. This auto-detects the format.
 *
 * Handles midnight rollover: if the parsed hour is more than 6 hours behind
 * the current UTC hour, assumes next day.
 *
 * @param string $t Time string (e.g. "1836" or "183600")
 * @return string|null ISO 8601 datetime string or null if invalid
 */
function viff_time_to_iso(string $t): ?string {
    $t = trim($t);
    if ($t === '' || $t === '0' || $t === '0000') {
        return null;
    }

    $len = strlen($t);

    if ($len === 6) {
        // HHMMSS format
        $hh = (int)substr($t, 0, 2);
        $mm = (int)substr($t, 2, 2);
        $ss = (int)substr($t, 4, 2);
    } elseif ($len === 4) {
        // HHMM format
        $hh = (int)substr($t, 0, 2);
        $mm = (int)substr($t, 2, 2);
        $ss = 0;
    } else {
        return null;
    }

    // Validate ranges
    if ($hh < 0 || $hh > 23 || $mm < 0 || $mm > 59 || $ss < 0 || $ss > 59) {
        return null;
    }

    // Determine date (handle midnight rollover)
    $nowHour = (int)gmdate('G');
    $today = gmdate('Y-m-d');

    if ($hh < $nowHour - 6) {
        // Time is far behind current hour — likely next day
        $date = gmdate('Y-m-d', strtotime('+1 day'));
    } else {
        $date = $today;
    }

    return sprintf('%sT%02d:%02d:%02dZ', $date, $hh, $mm, $ss);
}

/**
 * Derive the UTC date from an EOBT HHMM value for GUFI construction.
 * Uses current UTC date, with same midnight rollover logic.
 *
 * @param string $eobt EOBT in HHMM or HHMMSS format
 * @return string Date in Ymd format (e.g. "20260315")
 */
function viff_eobt_to_date(string $eobt): string {
    $eobt = trim($eobt);
    if (strlen($eobt) >= 4) {
        $hh = (int)substr($eobt, 0, 2);
        $nowHour = (int)gmdate('G');
        if ($hh < $nowHour - 6) {
            return gmdate('Ymd', strtotime('+1 day'));
        }
    }
    return gmdate('Ymd');
}

// ============================================================================
// Flight Matching (batch GUFI + 3-tier cascade fallback)
// ============================================================================

/**
 * Batch-resolve GUFIs to flight_uids in a single query.
 *
 * Constructs all GUFIs for a set of flights and resolves them with one
 * WHERE gufi IN (...) query instead of N individual lookups.
 *
 * @param resource $conn_swim SWIM database connection (sqlsrv)
 * @param array $flights Array of vIFF flight records (already filtered/merged)
 * @return array Map of gufi => ['flight_uid' => int, 'callsign' => str, ...]
 */
function viff_batch_gufi_lookup($conn_swim, array $flights): array {
    $gufiMap = [];  // gufi => vIFF flight index (for reverse mapping)
    $gufis = [];

    foreach ($flights as $idx => $f) {
        $callsign = strtoupper(trim($f['callsign'] ?? ''));
        $departure = strtoupper(trim($f['departure'] ?? ''));
        $arrival = strtoupper(trim($f['arrival'] ?? ''));
        $eobt = trim($f['eobt'] ?? '');

        if ($callsign === '' || $departure === '' || $arrival === '' || $eobt === '') {
            continue;
        }

        $date = viff_eobt_to_date($eobt);
        $gufi = swim_generate_gufi_legacy($callsign, $departure, $arrival, $date);
        $gufis[] = $gufi;
        $gufiMap[$gufi] = $idx;
    }

    if (empty($gufis)) {
        return [];
    }

    // Batch query: WHERE gufi IN (?,?,?...) — chunked at 200 to stay under param limits
    $resolved = [];
    foreach (array_chunk($gufis, 200) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $sql = "SELECT flight_uid, callsign, fp_dept_icao, fp_dest_icao, gufi, gufi_legacy
                FROM dbo.swim_flights
                WHERE gufi_legacy IN ($placeholders) AND is_active = 1";
        $stmt = sqlsrv_query($conn_swim, $sql, $chunk);
        if ($stmt !== false) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $resolved[$row['gufi_legacy']] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }
    }

    return $resolved;
}

/**
 * Look up a single flight using tier 2/3 cascade (fallback when GUFI batch misses).
 *
 * Tier 2: Callsign + departure + destination
 * Tier 3: Callsign + departure
 *
 * @param resource $conn_swim SWIM database connection (sqlsrv)
 * @param array $flight vIFF flight record
 * @return array|null Flight row with flight_uid, or null if not found
 */
function viff_match_flight_fallback($conn_swim, array $flight): ?array {
    $callsign = strtoupper(trim($flight['callsign'] ?? ''));
    $departure = strtoupper(trim($flight['departure'] ?? ''));
    $arrival = strtoupper(trim($flight['arrival'] ?? ''));

    if ($callsign === '') {
        return null;
    }

    // Tier 2: Callsign + departure + destination
    if ($departure !== '' && $arrival !== '') {
        $sql = "SELECT TOP 1 flight_uid, callsign, fp_dept_icao, fp_dest_icao
                FROM dbo.swim_flights
                WHERE callsign = ? AND fp_dept_icao = ? AND fp_dest_icao = ?
                  AND is_active = 1
                ORDER BY last_sync_utc DESC";
        $stmt = sqlsrv_query($conn_swim, $sql, [$callsign, $departure, $arrival]);
        if ($stmt !== false) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            if ($row) return $row;
        }
    }

    // Tier 3: Callsign + departure (fallback, matches CDM ingest pattern)
    if ($departure !== '') {
        $sql = "SELECT TOP 1 flight_uid, callsign, fp_dept_icao, fp_dest_icao
                FROM dbo.swim_flights
                WHERE callsign = ? AND fp_dept_icao = ?
                  AND is_active = 1
                ORDER BY last_sync_utc DESC";
        $stmt = sqlsrv_query($conn_swim, $sql, [$callsign, $departure]);
        if ($stmt !== false) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            if ($row) return $row;
        }
    }

    return null;
}

/**
 * Update actual_startup_request_time (ASRT) for a matched flight.
 *
 * @param resource $conn_swim SWIM database connection
 * @param int $flightUid Matched flight UID
 * @param string $asrtIso ISO 8601 datetime for ASRT
 * @param bool $debug Debug logging
 * @return bool True if row was updated
 */
function viff_update_asrt($conn_swim, int $flightUid, string $asrtIso, bool $debug): bool {
    $sql = "UPDATE dbo.swim_flights
            SET actual_startup_request_time = TRY_CONVERT(datetime2, ?),
                cdm_source = 'VIFF_CDM',
                cdm_updated_at = GETUTCDATE(),
                last_sync_utc = GETUTCDATE()
            WHERE flight_uid = ?";
    $stmt = sqlsrv_query($conn_swim, $sql, [$asrtIso, $flightUid]);
    if ($stmt === false) {
        $err = sqlsrv_errors();
        viff_log("ASRT update failed for uid=$flightUid: " . ($err[0]['message'] ?? 'Unknown'), 'ERROR');
        return false;
    }
    $rows = sqlsrv_rows_affected($stmt);
    sqlsrv_free_stmt($stmt);

    if ($debug && $rows > 0) {
        viff_log("  ASRT updated: uid=$flightUid asrt=$asrtIso", 'DEBUG');
    }
    return $rows > 0;
}

// ============================================================================
// Main Poll Function
// ============================================================================

/**
 * Execute one poll cycle: fetch all 3 vIFF endpoints, merge data, write to swim_flights.
 *
 * Optimizations:
 *   - Parallel HTTP via curl_multi (3 endpoints fetched simultaneously)
 *   - Cached flight_uid mappings (skip DB lookup for previously-matched flights)
 *   - Batch GUFI resolution (one WHERE IN query for all changed flights)
 *   - Per-row fallback only for GUFI misses (tier 2/3 cascade)
 *
 * @param bool $debug Enable debug logging
 * @return array Stats array
 */
function viff_poll(bool $debug = false): array {
    $conn_swim = get_conn_swim();
    $stats = [
        'fetched'     => 0,
        'updated'     => 0,
        'not_found'   => 0,
        'unchanged'   => 0,
        'skipped'     => 0,
        'errors'      => 0,
        'cache_hits'  => 0,
        'asrt_updated' => 0,
    ];

    if (!$conn_swim) {
        viff_log("SWIM database connection unavailable", 'ERROR');
        return $stats;
    }

    // Check circuit breaker
    if (viff_is_circuit_open()) {
        viff_log("Circuit breaker open — in cooldown");
        return $stats;
    }

    // -------------------------------------------------------------------------
    // Step A: Fetch all 3 endpoints in parallel via curl_multi
    // -------------------------------------------------------------------------
    $responses = viff_fetch_multi([
        'flights'      => VIFF_API_BASE . '/etfms/relevant',
        'restrictions' => VIFF_API_BASE . '/etfms/restricted',
        'statuses'     => VIFF_API_BASE . '/ifps/allStatus',
    ]);

    $flights = $responses['flights'];
    if ($flights === null || !is_array($flights)) {
        viff_log("Failed to fetch /etfms/relevant", 'ERROR');
        return $stats;
    }

    $stats['fetched'] = count($flights);

    // Build CTOT map from /etfms/restricted
    $ctotMap = [];
    if (is_array($responses['restrictions'])) {
        foreach ($responses['restrictions'] as $r) {
            $cs = strtoupper(trim($r['callsign'] ?? ''));
            if ($cs !== '' && !empty($r['ctot'])) {
                $ctotMap[$cs] = [
                    'ctot' => $r['ctot'],
                    'reason' => $r['mostPenalizingAirspace'] ?? '',
                ];
            }
        }
        if ($debug) viff_log("  /etfms/restricted: " . count($ctotMap) . " CTOTs", 'DEBUG');
    }

    // Build status map from /ifps/allStatus
    $statusMap = [];
    if (is_array($responses['statuses'])) {
        foreach ($responses['statuses'] as $s) {
            $cs = strtoupper(trim($s['callsign'] ?? ''));
            if ($cs !== '' && !empty($s['cdmSts'])) {
                $statusMap[$cs] = strtoupper(trim($s['cdmSts']));
            }
        }
        if ($debug) viff_log("  /ifps/allStatus: " . count($statusMap) . " statuses", 'DEBUG');
    }

    // -------------------------------------------------------------------------
    // Step A2: Fetch ASRT data from /ifps/depAirport (per-airport)
    // -------------------------------------------------------------------------
    $asrtUpdated = 0;

    // Extract unique departure airports from CDM flights
    $cdmAirports = [];
    foreach ($flights as $f) {
        if (!empty($f['isCdm']) && !empty($f['departure'])) {
            $dept = strtoupper(trim($f['departure']));
            if ($dept !== '') {
                $cdmAirports[$dept] = true;
            }
        }
    }
    $cdmAirports = array_keys($cdmAirports);

    if (count($cdmAirports) > 50) {
        viff_log("ASRT poll: " . count($cdmAirports) . " airports exceeds cap of 50 — skipping", 'WARN');
        $cdmAirports = [];
    }

    if (!empty($cdmAirports)) {
        // Build per-airport URLs
        $airportUrls = [];
        foreach ($cdmAirports as $icao) {
            $airportUrls["dep_$icao"] = VIFF_API_BASE . '/ifps/depAirport?airport=' . $icao;
        }

        if ($debug) viff_log("  ASRT poll: " . count($airportUrls) . " airports", 'DEBUG');

        // Fetch all in parallel (recordErrors=false: per-airport failures don't trip breaker)
        $airportResponses = viff_fetch_multi($airportUrls, false);

        // Load ASRT cache (separate from main flight hash cache)
        $asrtCache = [];
        if (file_exists(VIFF_CACHE_FILE)) {
            $allCache = json_decode(file_get_contents(VIFF_CACHE_FILE), true) ?: [];
            foreach ($allCache as $k => $v) {
                if (strpos($k, 'ASRT:') === 0) {
                    $asrtCache[$k] = $v;
                }
            }
        }
        $newAsrtCache = [];

        foreach ($airportResponses as $key => $data) {
            if ($data === null || !is_array($data)) continue;

            $icao = substr($key, 4); // strip "dep_" prefix

            foreach ($data as $record) {
                $callsign = strtoupper(trim($record['callsign'] ?? ''));
                if ($callsign === '') continue;

                $reqAsrt = trim($record['cdmData']['reqAsrt'] ?? '');
                if ($reqAsrt === '' || $reqAsrt === '0' || $reqAsrt === '0000') continue;

                // Convert HHMM to ISO 8601
                $asrtIso = viff_time_to_iso($reqAsrt);
                if ($asrtIso === null) continue;

                // Cache check: skip if unchanged
                $cacheKey = "ASRT:$callsign:$icao";
                $newAsrtCache[$cacheKey] = $asrtIso;
                if (isset($asrtCache[$cacheKey]) && $asrtCache[$cacheKey] === $asrtIso) {
                    continue;
                }

                // Skip airborne flights (atot present means already departed)
                $atot = trim($record['atot'] ?? '');
                if ($atot !== '' && $atot !== '0' && $atot !== '0000') continue;

                // Match flight to swim_flights
                $match = viff_match_flight_fallback($conn_swim, [
                    'callsign' => $callsign,
                    'departure' => $icao,
                    'arrival' => '',
                ]);

                if (!$match) {
                    if ($debug) viff_log("  ASRT not found: $callsign ($icao)", 'DEBUG');
                    continue;
                }

                // Write ASRT
                if (viff_update_asrt($conn_swim, $match['flight_uid'], $asrtIso, $debug)) {
                    $asrtUpdated++;
                }
            }
        }

        // Store ASRT cache entries for merging later
        $stats['_asrtCache'] = $newAsrtCache;
    }

    $stats['asrt_updated'] = $asrtUpdated;

    // -------------------------------------------------------------------------
    // Step B: Load cache (hash + cached flight_uid mappings)
    // Cache format: { "CS:DEPT:ARR": { "hash": "md5", "uid": 12345 }, ... }
    // -------------------------------------------------------------------------
    $cache = [];
    if (file_exists(VIFF_CACHE_FILE)) {
        $cache = json_decode(file_get_contents(VIFF_CACHE_FILE), true) ?: [];
    }
    $newCache = [];

    // -------------------------------------------------------------------------
    // Step C: Filter, merge, and identify changed flights
    // -------------------------------------------------------------------------
    $changedFlights = [];  // Flights that need DB matching + update

    foreach ($flights as $f) {
        $callsign = strtoupper(trim($f['callsign'] ?? ''));
        if ($callsign === '') continue;

        // Filter: only process CDM flights (isCdm must be explicitly true)
        if (empty($f['isCdm'])) {
            $stats['skipped']++;
            continue;
        }

        // Merge CTOT restriction data if available
        if (isset($ctotMap[$callsign])) {
            if (empty($f['ctot'])) {
                $f['ctot'] = $ctotMap[$callsign]['ctot'];
            }
            if (empty($f['mostPenalizingAirspace'])) {
                $f['mostPenalizingAirspace'] = $ctotMap[$callsign]['reason'];
            }
        }

        // Merge ATFCM status if available
        if (isset($statusMap[$callsign]) && empty($f['atfcmStatus'])) {
            $f['atfcmStatus'] = $statusMap[$callsign];
        }

        // Cache-based change detection
        $cacheKey = $callsign . ':' . ($f['departure'] ?? '') . ':' . ($f['arrival'] ?? '');
        $cacheHash = md5(json_encode($f));

        // Preserve cached UID mapping
        $cachedUid = isset($cache[$cacheKey]['uid']) ? $cache[$cacheKey]['uid'] : null;
        $newCache[$cacheKey] = ['hash' => $cacheHash, 'uid' => $cachedUid];

        if (isset($cache[$cacheKey]['hash']) && $cache[$cacheKey]['hash'] === $cacheHash) {
            $stats['unchanged']++;
            continue;
        }

        // Flight data changed — needs update
        $f['_cacheKey'] = $cacheKey;
        $f['_cachedUid'] = $cachedUid;
        $changedFlights[] = $f;
    }

    if (empty($changedFlights)) {
        if ($debug) viff_log("  No changed flights — skipping DB operations", 'DEBUG');
        // Merge ASRT cache entries before writing (even when no main flights changed)
        if (!empty($stats['_asrtCache'])) {
            foreach ($stats['_asrtCache'] as $k => $v) {
                $newCache[$k] = $v;
            }
            unset($stats['_asrtCache']);
        }
        @file_put_contents(VIFF_CACHE_FILE, json_encode($newCache), LOCK_EX);
        viff_update_provider_sync($stats);
        return $stats;
    }

    // -------------------------------------------------------------------------
    // Step D: Resolve flight_uids — cached UIDs first, then batch GUFI, then fallback
    // -------------------------------------------------------------------------

    // Partition: flights with cached UID vs those needing lookup
    $needsLookup = [];
    $readyToUpdate = [];

    foreach ($changedFlights as $f) {
        if ($f['_cachedUid'] !== null) {
            // Cached UID — skip DB lookup entirely
            $stats['cache_hits']++;
            $readyToUpdate[] = [
                'match' => ['flight_uid' => $f['_cachedUid']],
                'flight' => $f,
            ];
        } else {
            $needsLookup[] = $f;
        }
    }

    if ($debug && $stats['cache_hits'] > 0) {
        viff_log("  UID cache hits: {$stats['cache_hits']}", 'DEBUG');
    }

    // Batch GUFI lookup for flights that need resolution
    if (!empty($needsLookup)) {
        $gufiResults = viff_batch_gufi_lookup($conn_swim, $needsLookup);

        if ($debug && !empty($gufiResults)) {
            viff_log("  Batch GUFI resolved: " . count($gufiResults) . "/" . count($needsLookup), 'DEBUG');
        }

        foreach ($needsLookup as $f) {
            $callsign = strtoupper(trim($f['callsign'] ?? ''));
            $departure = strtoupper(trim($f['departure'] ?? ''));
            $arrival = strtoupper(trim($f['arrival'] ?? ''));
            $eobt = trim($f['eobt'] ?? '');

            $match = null;

            // Try batch GUFI result first
            if ($departure !== '' && $arrival !== '' && $eobt !== '') {
                $date = viff_eobt_to_date($eobt);
                $gufi = swim_generate_gufi_legacy($callsign, $departure, $arrival, $date);
                if (isset($gufiResults[$gufi])) {
                    $match = $gufiResults[$gufi];
                }
            }

            // Fallback to individual tier 2/3 lookup
            if (!$match) {
                $match = viff_match_flight_fallback($conn_swim, $f);
            }

            if (!$match) {
                $stats['not_found']++;
                if ($debug) viff_log("  Not found: $callsign ($departure -> $arrival)", 'DEBUG');
                continue;
            }

            // Cache the resolved UID for next cycle
            $cacheKey = $f['_cacheKey'];
            $newCache[$cacheKey]['uid'] = $match['flight_uid'];

            $readyToUpdate[] = [
                'match' => $match,
                'flight' => $f,
            ];
        }
    }

    // -------------------------------------------------------------------------
    // Step E: Execute all updates
    // -------------------------------------------------------------------------
    foreach ($readyToUpdate as $item) {
        try {
            $result = viff_update_flight($conn_swim, $item['match'], $item['flight'], $debug);
            if ($result) {
                $stats['updated']++;
            }
        } catch (\Exception $e) {
            $stats['errors']++;
            $cs = $item['flight']['callsign'] ?? 'unknown';
            viff_log("Error updating $cs: " . $e->getMessage(), 'ERROR');
        }
    }

    // -------------------------------------------------------------------------
    // Step F: Finalize
    // -------------------------------------------------------------------------
    // Merge ASRT cache entries into main cache before writing
    if (!empty($stats['_asrtCache'])) {
        foreach ($stats['_asrtCache'] as $k => $v) {
            $newCache[$k] = $v;
        }
        unset($stats['_asrtCache']);
    }
    @file_put_contents(VIFF_CACHE_FILE, json_encode($newCache), LOCK_EX);
    viff_update_provider_sync($stats);

    // Reset circuit breaker on success
    if ($stats['errors'] === 0) {
        viff_reset_circuit();
    }

    return $stats;
}

/**
 * Update the provider sync status in tmi_flow_providers.
 */
function viff_update_provider_sync(array $stats): void {
    $conn_tmi = get_conn_tmi();
    if (!$conn_tmi) return;

    $syncMsg = sprintf('%d fetched, %d updated, %d not found, %d unchanged, %d skipped, %d cache_hits, %d asrt',
        $stats['fetched'], $stats['updated'], $stats['not_found'],
        $stats['unchanged'], $stats['skipped'], $stats['cache_hits'],
        $stats['asrt_updated'] ?? 0);
    $syncSql = "UPDATE dbo.tmi_flow_providers SET
                    last_sync_utc = GETUTCDATE(),
                    last_sync_status = ?,
                    last_sync_message = ?
                WHERE provider_code = ?";
    $syncStmt = sqlsrv_query($conn_tmi, $syncSql, [
        $stats['errors'] > 0 ? 'PARTIAL' : 'SUCCESS',
        $syncMsg,
        VIFF_PROVIDER_CODE
    ]);
    if ($syncStmt !== false) sqlsrv_free_stmt($syncStmt);
}

// ============================================================================
// Flight Update
// ============================================================================

/**
 * Update a matched swim_flights record with vIFF CDM data.
 *
 * @param resource $conn_swim SWIM database connection
 * @param array $match Matched flight row (flight_uid, callsign, etc.)
 * @param array $f vIFF flight record (merged with restrictions and statuses)
 * @param bool $debug Debug logging
 * @return bool True if row was updated
 */
function viff_update_flight($conn_swim, array $match, array $f, bool $debug): bool {
    $setClauses = [];
    $params = [];

    // TOBT — Target Off-Block Time
    $tobtIso = !empty($f['tobt']) ? viff_time_to_iso($f['tobt']) : null;
    if ($tobtIso !== null) {
        $setClauses[] = 'target_off_block_time = TRY_CONVERT(datetime2, ?)';
        $params[] = $tobtIso;
    }

    // CTOT — Controlled Time of Departure (EU regulation-assigned)
    $ctotIso = !empty($f['ctot']) ? viff_time_to_iso($f['ctot']) : null;
    if ($ctotIso !== null) {
        $setClauses[] = 'controlled_time_of_departure = TRY_CONVERT(datetime2, ?)';
        $params[] = $ctotIso;
    }

    // Taxi time (minutes string)
    $taxiMinutes = null;
    if (isset($f['taxi']) && is_numeric($f['taxi'])) {
        $taxiMinutes = (int)$f['taxi'];
        if ($taxiMinutes >= 0 && $taxiMinutes <= 120) {
            $setClauses[] = 'expected_taxi_out_time = ?';
            $params[] = $taxiMinutes;
        } else {
            $taxiMinutes = null;
        }
    }

    // TSAT/TTOT derivation
    // Priority: CTOT-based (regulated) > TOBT-based (unregulated)
    if ($ctotIso !== null && $taxiMinutes !== null) {
        // Regulated flight: TTOT = CTOT, TSAT = CTOT - taxi
        $setClauses[] = 'target_takeoff_time = TRY_CONVERT(datetime2, ?)';
        $params[] = $ctotIso;

        $tsatTime = strtotime($ctotIso) - ($taxiMinutes * 60);
        $tsatIso = gmdate('Y-m-d\TH:i:s\Z', $tsatTime);
        $setClauses[] = 'target_startup_approval_time = TRY_CONVERT(datetime2, ?)';
        $params[] = $tsatIso;
    } elseif ($tobtIso !== null && $taxiMinutes !== null) {
        // Unregulated flight: TTOT = TOBT + taxi, TSAT = TOBT
        $ttotTime = strtotime($tobtIso) + ($taxiMinutes * 60);
        $ttotIso = gmdate('Y-m-d\TH:i:s\Z', $ttotTime);
        $setClauses[] = 'target_takeoff_time = TRY_CONVERT(datetime2, ?)';
        $params[] = $ttotIso;

        $setClauses[] = 'target_startup_approval_time = TRY_CONVERT(datetime2, ?)';
        $params[] = $tobtIso;
    }

    // AOBT — Actual Off-Block Time (if reported)
    if (!empty($f['aobt'])) {
        $aobtIso = viff_time_to_iso($f['aobt']);
        if ($aobtIso !== null) {
            $setClauses[] = 'actual_off_block_time = TRY_CONVERT(datetime2, ?)';
            $params[] = $aobtIso;
        }
    }

    // ATOT — Actual Time of Departure (if reported)
    if (!empty($f['atot'])) {
        $atotIso = viff_time_to_iso($f['atot']);
        if ($atotIso !== null) {
            $setClauses[] = 'actual_time_of_departure = TRY_CONVERT(datetime2, ?)';
            $params[] = $atotIso;
        }
    }

    // EOBT — only write if estimated_time_of_departure is currently empty
    if (!empty($f['eobt'])) {
        $eobtIso = viff_time_to_iso($f['eobt']);
        if ($eobtIso !== null) {
            $setClauses[] = 'estimated_time_of_departure = COALESCE(estimated_time_of_departure, TRY_CONVERT(datetime2, ?))';
            $params[] = $eobtIso;
        }
    }

    // EU ATFCM status
    $atfcmStatus = null;
    if (!empty($f['atfcmData']['excluded']) && $f['atfcmData']['excluded'] === true) {
        $atfcmStatus = 'EXCLUDED';
    } elseif (!empty($f['atfcmStatus'])) {
        $atfcmStatus = strtoupper(trim($f['atfcmStatus']));
    }
    if ($atfcmStatus !== null) {
        $setClauses[] = 'eu_atfcm_status = ?';
        $params[] = $atfcmStatus;
    }

    // ATFCM sub-fields (individual regulatory flags from atfcmData)
    if (isset($f['atfcmData']) && is_array($f['atfcmData'])) {
        $setClauses[] = 'eu_atfcm_excluded = ?';
        $params[] = !empty($f['atfcmData']['excluded']) ? 1 : 0;

        $setClauses[] = 'eu_atfcm_ready = ?';
        $params[] = !empty($f['atfcmData']['isRea']) ? 1 : 0;

        $setClauses[] = 'eu_atfcm_slot_improvement = ?';
        $params[] = !empty($f['atfcmData']['SIR']) ? 1 : 0;
    }

    // Flow measure identification (mostPenalizingAirspace = regulation name)
    if (!empty($f['mostPenalizingAirspace'])) {
        $setClauses[] = 'flow_measure_ident = ?';
        $params[] = substr($f['mostPenalizingAirspace'], 0, 32);
    }

    // Nothing to update
    if (empty($setClauses)) {
        return false;
    }

    // Source tracking
    $setClauses[] = "cdm_source = 'VIFF_CDM'";
    $setClauses[] = 'cdm_updated_at = GETUTCDATE()';
    $setClauses[] = 'last_sync_utc = GETUTCDATE()';

    // WHERE clause
    $params[] = $match['flight_uid'];

    $sql = "UPDATE dbo.swim_flights SET " . implode(', ', $setClauses) . " WHERE flight_uid = ?";

    $stmt = sqlsrv_query($conn_swim, $sql, $params);
    if ($stmt === false) {
        $err = sqlsrv_errors();
        throw new \Exception('SQL error: ' . ($err[0]['message'] ?? 'Unknown'));
    }

    $rows = sqlsrv_rows_affected($stmt);
    sqlsrv_free_stmt($stmt);

    if ($debug && $rows > 0) {
        $callsign = $f['callsign'] ?? 'unknown';
        $departure = $f['departure'] ?? '????';
        viff_log("  Updated: $callsign ($departure) uid={$match['flight_uid']}", 'DEBUG');
    }

    // Deliver CTOT notification if CTOT was written and row was updated
    if ($rows > 0 && $ctotIso !== null) {
        try {
            static $edctDelivery = null;
            if ($edctDelivery === null) {
                $cdmService = new CDMService($conn_swim, get_conn_tmi());
                $edctDelivery = new EDCTDelivery($cdmService, get_conn_tmi(), $debug);
            }
            $callsign = $match['callsign'] ?? ($f['callsign'] ?? null);
            if ($callsign) {
                $regulationId = $f['mostPenalizingAirspace'] ?? null;
                $message = $edctDelivery->formatCTOTMessage($ctotIso, $regulationId);
                $edctDelivery->deliverMessage(
                    (int)$match['flight_uid'],
                    $callsign,
                    CDMService::MSG_CTOT,
                    $message,
                    $ctotIso
                );
                if ($debug) {
                    viff_log("  CTOT delivery queued: $callsign CTOT=$ctotIso", 'DEBUG');
                }
            }
        } catch (\Throwable $e) {
            viff_log("CTOT delivery error for uid={$match['flight_uid']}: " . $e->getMessage(), 'WARN');
        }
    }

    return $rows > 0;
}

// ============================================================================
// PID / Heartbeat
// ============================================================================

function viff_write_heartbeat(array $stats): void {
    $file = sys_get_temp_dir() . '/viff_cdm_poll_daemon.heartbeat';
    $payload = [
        'pid'         => getmypid(),
        'status'      => 'idle',
        'updated_utc' => gmdate('Y-m-d H:i:s'),
        'unix_ts'     => time(),
        'stats'       => $stats,
    ];
    @file_put_contents($file, json_encode($payload), LOCK_EX);
}

function viff_write_pid(string $pidFile): void {
    file_put_contents($pidFile, getmypid());
    register_shutdown_function(function () use ($pidFile) {
        if (file_exists($pidFile)) @unlink($pidFile);
    });
}

function viff_check_existing_instance(string $pidFile): bool {
    if (!file_exists($pidFile)) return false;
    $pid = (int)file_get_contents($pidFile);
    if ($pid <= 0) return false;
    if (PHP_OS_FAMILY === 'Windows') {
        exec("tasklist /FI \"PID eq $pid\" 2>NUL", $output);
        return count($output) > 1;
    }
    return posix_kill($pid, 0);
}

// ============================================================================
// Main Daemon Logic
// ============================================================================

$pidFile = sys_get_temp_dir() . '/viff_cdm_poll_daemon.pid';
$heartbeatFile = sys_get_temp_dir() . '/viff_cdm_poll_daemon.heartbeat';

// Singleton
if (viff_check_existing_instance($pidFile)) {
    viff_log("Another instance is already running. Exiting.", 'WARN');
    exit(1);
}

viff_write_pid($pidFile);
register_shutdown_function(function () use ($heartbeatFile) {
    if (file_exists($heartbeatFile)) @unlink($heartbeatFile);
});

viff_log("========================================");
viff_log("vIFF CDM Polling Daemon");
viff_log("  API base: " . VIFF_API_BASE);
viff_log("  Poll interval: {$pollInterval}s");
viff_log("  Mode: " . ($runLoop ? 'daemon (continuous)' : 'single run'));
viff_log("  Auth: " . (defined('VIFF_API_KEY') && VIFF_API_KEY !== '' ? 'API key configured' : 'NO API KEY'));
viff_log("  NOTE: Runs during hibernation (SWIM exempt)");
viff_log("  PID: " . getmypid());
viff_log("========================================");

if (!defined('VIFF_API_KEY') || VIFF_API_KEY === '') {
    viff_log("WARNING: No VIFF_API_KEY configured — API requests may fail", 'WARN');
}

$cycleCount = 0;

do {
    $cycleStart = microtime(true);
    $cycleCount++;

    viff_log("--- vIFF CDM poll cycle #$cycleCount ---");

    try {
        $stats = viff_poll($debug);

        $msg = sprintf('fetched=%d updated=%d not_found=%d unchanged=%d skipped=%d cache_hits=%d errors=%d asrt=%d',
            $stats['fetched'], $stats['updated'], $stats['not_found'],
            $stats['unchanged'], $stats['skipped'], $stats['cache_hits'], $stats['errors'],
            $stats['asrt_updated']);
        viff_log("  $msg");

        viff_write_heartbeat($stats);
    } catch (\Throwable $e) {
        viff_log("Poll exception: " . $e->getMessage(), 'ERROR');
    }

    $cycleDuration = microtime(true) - $cycleStart;

    if ($runLoop) {
        $sleepSeconds = max(1, (int)ceil($pollInterval - $cycleDuration));
        $sleepRemaining = $sleepSeconds;
        while ($sleepRemaining > 0) {
            sleep(1);
            $sleepRemaining--;
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }

} while ($runLoop);

viff_log("vIFF CDM Polling Daemon exiting");
