<?php
/**
 * ECFMP Flow Measure Polling Daemon
 *
 * Polls the ECFMP (European Centralised Flow Management Programme) API
 * for flow measures and upserts into VATSIM_TMI tmi_flow_measures table.
 *
 * ECFMP API: https://ecfmp.vatsim.net/api/v1/
 *
 * This daemon runs even during HIBERNATION_MODE since ECFMP flow measures
 * are external data that should always be ingested (like the Discord queue).
 *
 * Features:
 *   - Polls flow measures every 5 minutes (configurable)
 *   - Fetches FIR lookup table once at startup (numeric ID → ICAO code)
 *   - Maps ECFMP measure types to PERTI TMI schema types
 *   - Circuit breaker pattern (file-based, 6 errors/60s → 3-min cooldown)
 *   - File cache for deduplication
 *   - Status lifecycle: NOTIFIED → ACTIVE → EXPIRED / WITHDRAWN
 *   - Broadcasts WebSocket events on measure changes
 *
 * Usage:
 *   php ecfmp_poll_daemon.php [--loop] [--interval=300] [--debug]
 *
 * @package PERTI
 * @subpackage ECFMP
 * @version 1.0.0
 */

// Parse CLI arguments
$options = getopt('', ['loop', 'interval:', 'debug']);
$runLoop = isset($options['loop']);
$pollInterval = isset($options['interval']) ? (int)$options['interval'] : 300;
$debug = isset($options['debug']);

// Enforce minimum
$pollInterval = max(60, $pollInterval);

// Load dependencies
if (!defined('PERTI_LOADED')) {
    define('PERTI_LOADED', true);
    require_once __DIR__ . '/../load/config.php';
    require_once __DIR__ . '/../load/connect.php';
}

// ============================================================================
// Constants
// ============================================================================

define('ECFMP_API_BASE', 'https://ecfmp.vatsim.net/api/v1');
define('ECFMP_STATE_FILE', sys_get_temp_dir() . '/perti_ecfmp_poll_state.json');
define('ECFMP_CACHE_FILE', sys_get_temp_dir() . '/perti_ecfmp_cache.json');
define('ECFMP_CIRCUIT_WINDOW', 60);
define('ECFMP_CIRCUIT_MAX_ERRORS', 6);
define('ECFMP_CIRCUIT_COOLDOWN', 180);
define('ECFMP_PROVIDER_CODE', 'ECFMP');

// ============================================================================
// Logging
// ============================================================================

function ecfmp_log(string $message, string $level = 'INFO'): void {
    $timestamp = gmdate('Y-m-d H:i:s');
    echo "[$timestamp UTC] [$level] $message\n";
}

// ============================================================================
// Circuit Breaker (file-based, same pattern as simtraffic_swim_poll.php)
// ============================================================================

function ecfmp_read_state(): array {
    if (!file_exists(ECFMP_STATE_FILE)) {
        return ['errors' => [], 'cooldown_until' => 0];
    }
    $data = json_decode(file_get_contents(ECFMP_STATE_FILE), true);
    return is_array($data) ? $data : ['errors' => [], 'cooldown_until' => 0];
}

function ecfmp_write_state(array $state): void {
    @file_put_contents(ECFMP_STATE_FILE, json_encode($state), LOCK_EX);
}

function ecfmp_is_circuit_open(): bool {
    $state = ecfmp_read_state();
    if ($state['cooldown_until'] > time()) {
        return true;
    }
    // Prune errors outside window
    $cutoff = time() - ECFMP_CIRCUIT_WINDOW;
    $recent = array_filter($state['errors'], fn($t) => $t > $cutoff);
    return count($recent) >= ECFMP_CIRCUIT_MAX_ERRORS;
}

function ecfmp_record_error(): void {
    $state = ecfmp_read_state();
    $state['errors'][] = time();
    // Prune old errors
    $cutoff = time() - ECFMP_CIRCUIT_WINDOW;
    $state['errors'] = array_values(array_filter($state['errors'], fn($t) => $t > $cutoff));
    // Trip if threshold exceeded
    if (count($state['errors']) >= ECFMP_CIRCUIT_MAX_ERRORS) {
        $state['cooldown_until'] = time() + ECFMP_CIRCUIT_COOLDOWN;
        ecfmp_log("Circuit breaker tripped — cooldown " . ECFMP_CIRCUIT_COOLDOWN . "s", 'WARN');
    }
    ecfmp_write_state($state);
}

function ecfmp_reset_circuit(): void {
    ecfmp_write_state(['errors' => [], 'cooldown_until' => 0]);
}

// ============================================================================
// HTTP Helper
// ============================================================================

function ecfmp_fetch(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'User-Agent: PERTI-ECFMP-Daemon/1.0'
        ],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        ecfmp_log("HTTP $httpCode from $url" . ($error ? ": $error" : ''), 'ERROR');
        ecfmp_record_error();
        return null;
    }

    $data = json_decode($response, true);
    if ($data === null) {
        ecfmp_log("Invalid JSON from $url", 'ERROR');
        ecfmp_record_error();
        return null;
    }

    return $data;
}

// ============================================================================
// FIR Lookup (numeric ID → ICAO code)
// ============================================================================

function ecfmp_fetch_fir_map(): array {
    $data = ecfmp_fetch(ECFMP_API_BASE . '/flight-information-region');
    if ($data === null) return [];

    $map = [];
    foreach ($data as $fir) {
        if (isset($fir['id'], $fir['identifier'])) {
            $map[(int)$fir['id']] = $fir['identifier'];
        }
    }

    ecfmp_log("FIR map loaded: " . count($map) . " FIRs");
    return $map;
}

// ============================================================================
// ECFMP Type Mapping
// ============================================================================

/**
 * Map ECFMP measure type to PERTI TMI schema values.
 *
 * @return array{measure_type: string, measure_unit: string|null}
 */
function ecfmp_map_measure_type(string $ecfmp_type): array {
    $mapping = [
        'minimum_departure_interval' => ['MDI', 'SEC'],
        'average_departure_interval' => ['MDI', 'SEC'],
        'per_hour'                   => ['RATE', 'PER_HOUR'],
        'miles_in_trail'             => ['MIT', 'NM'],
        'max_ias'                    => ['OTHER', 'KTS'],
        'max_mach'                   => ['OTHER', 'MACH'],
        'ias_reduction'              => ['OTHER', 'KTS'],
        'mach_reduction'             => ['OTHER', 'MACH'],
        'prohibit'                   => ['GS', null],
        'ground_stop'                => ['GS', null],
        'mandatory_route'            => ['REROUTE', null],
    ];

    return $mapping[$ecfmp_type] ?? ['OTHER', null];
}

/**
 * Map ECFMP filters array to PERTI filters_json structure.
 */
function ecfmp_map_filters(array $ecfmp_filters): array {
    $result = [];

    foreach ($ecfmp_filters as $filter) {
        $type = $filter['type'] ?? '';
        $value = $filter['value'] ?? null;

        switch ($type) {
            case 'ADEP':
                $result['adep'] = $value;
                break;
            case 'ADES':
                $result['ades'] = $value;
                break;
            case 'level':
                $result['levels'] = $value;
                break;
            case 'level_above':
                $result['level_above'] = is_array($value) ? ($value[0] ?? null) : $value;
                break;
            case 'level_below':
                $result['level_below'] = is_array($value) ? ($value[0] ?? null) : $value;
                break;
            case 'waypoint':
                $result['waypoints'] = $value;
                break;
            case 'member_event':
                $result['member_event'] = is_array($value) ? ($value[0] ?? null) : $value;
                break;
            case 'member_not_event':
                $result['member_not_event'] = is_array($value) ? ($value[0] ?? null) : $value;
                break;
        }
    }

    return $result;
}

/**
 * Determine measure status based on timestamps.
 */
function ecfmp_compute_status(array $measure): string {
    if (!empty($measure['withdrawn_at'])) {
        return 'WITHDRAWN';
    }

    $now = time();
    $start = strtotime($measure['starttime'] ?? '');
    $end = strtotime($measure['endtime'] ?? '');

    if ($start === false || $end === false) return 'ACTIVE';

    if ($now < $start) return 'NOTIFIED';
    if ($now >= $start && $now < $end) return 'ACTIVE';
    return 'EXPIRED';
}

// ============================================================================
// Main Poll Function
// ============================================================================

function ecfmp_poll(array $fir_map, bool $debug = false): array {
    $conn_tmi = get_conn_tmi();
    $stats = [
        'fetched'   => 0,
        'inserted'  => 0,
        'updated'   => 0,
        'unchanged' => 0,
        'withdrawn' => 0,
        'errors'    => 0,
    ];

    if (!$conn_tmi) {
        return ['success' => false, 'message' => 'TMI database connection unavailable', 'stats' => $stats];
    }

    // Check circuit breaker
    if (ecfmp_is_circuit_open()) {
        return ['success' => true, 'message' => 'Circuit breaker open — in cooldown', 'stats' => $stats];
    }

    // Look up provider_id for ECFMP
    $prov_sql = "SELECT provider_id FROM dbo.tmi_flow_providers WHERE provider_code = ?";
    $prov_stmt = sqlsrv_query($conn_tmi, $prov_sql, [ECFMP_PROVIDER_CODE]);
    if ($prov_stmt === false) {
        return ['success' => false, 'message' => 'Failed to query tmi_flow_providers', 'stats' => $stats];
    }
    $prov_row = sqlsrv_fetch_array($prov_stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($prov_stmt);

    if (!$prov_row) {
        return ['success' => false, 'message' => 'ECFMP provider not found in tmi_flow_providers', 'stats' => $stats];
    }
    $provider_id = (int)$prov_row['provider_id'];

    // Fetch measures from ECFMP
    $measures = ecfmp_fetch(ECFMP_API_BASE . '/flow-measure');
    if ($measures === null) {
        return ['success' => false, 'message' => 'Failed to fetch ECFMP flow measures', 'stats' => $stats];
    }

    $stats['fetched'] = count($measures);

    // Load cache for change detection
    $cache = [];
    if (file_exists(ECFMP_CACHE_FILE)) {
        $cache = json_decode(file_get_contents(ECFMP_CACHE_FILE), true) ?: [];
    }
    $new_cache = [];

    foreach ($measures as $m) {
        $ext_id = (string)($m['id'] ?? '');
        if (!$ext_id) continue;

        // Cache-based change detection: skip if unchanged
        $cache_key = $ext_id;
        $cache_hash = md5(json_encode($m));
        $new_cache[$cache_key] = $cache_hash;

        if (isset($cache[$cache_key]) && $cache[$cache_key] === $cache_hash) {
            $stats['unchanged']++;
            continue;
        }

        // Map ECFMP type to PERTI schema
        [$measure_type, $measure_unit] = ecfmp_map_measure_type($m['measure']['type'] ?? '');
        $measure_value = $m['measure']['value'] ?? null;

        // Handle mandatory_route: value is array of route strings
        $mandatory_route_json = null;
        if ($measure_type === 'REROUTE' && is_array($measure_value)) {
            $mandatory_route_json = json_encode($measure_value);
            $measure_value = null;
        }

        // Map filters
        $filters_json = json_encode(ecfmp_map_filters($m['filters'] ?? []));

        // Resolve FIR codes from numeric IDs
        $fir_codes = [];
        foreach ($m['notified_flight_information_regions'] ?? [] as $fir_id) {
            if (isset($fir_map[(int)$fir_id])) {
                $fir_codes[] = $fir_map[(int)$fir_id];
            }
        }
        // Use first FIR as ctl_element (primary controlling region)
        $ctl_element = $fir_codes[0] ?? null;
        $element_type = $ctl_element ? 'FIR' : null;

        // Compute status
        $status = ecfmp_compute_status($m);

        // Parse timestamps
        $start_utc = $m['starttime'] ?? null;
        $end_utc = $m['endtime'] ?? null;
        $withdrawn_at = $m['withdrawn_at'] ?? null;

        // Check if measure exists already
        $exists_sql = "SELECT measure_id, status FROM dbo.tmi_flow_measures WHERE provider_id = ? AND external_id = ?";
        $exists_stmt = sqlsrv_query($conn_tmi, $exists_sql, [$provider_id, $ext_id]);
        $existing = false;
        if ($exists_stmt !== false) {
            $existing = sqlsrv_fetch_array($exists_stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($exists_stmt);
        }

        if ($existing) {
            // Update existing
            $upd_sql = "UPDATE dbo.tmi_flow_measures SET
                            ident = ?, event_id = ?, ctl_element = ?, element_type = ?,
                            measure_type = ?, measure_value = ?, measure_unit = ?,
                            reason = ?, filters_json = ?, mandatory_route_json = ?,
                            start_utc = ?, end_utc = ?, status = ?,
                            withdrawn_at = ?, raw_data_json = ?,
                            updated_at = SYSUTCDATETIME(), synced_at = SYSUTCDATETIME()
                        WHERE provider_id = ? AND external_id = ?";

            $upd_params = [
                $m['ident'] ?? $ext_id,
                $m['event_id'] ?? null,
                $ctl_element,
                $element_type,
                $measure_type,
                is_numeric($measure_value) ? (int)$measure_value : null,
                $measure_unit,
                $m['reason'] ?? null,
                $filters_json,
                $mandatory_route_json,
                $start_utc,
                $end_utc,
                $status,
                $withdrawn_at,
                json_encode($m),
                $provider_id,
                $ext_id
            ];

            $upd_stmt = sqlsrv_query($conn_tmi, $upd_sql, $upd_params);
            if ($upd_stmt !== false) {
                sqlsrv_free_stmt($upd_stmt);
                $stats['updated']++;

                // Track withdrawals
                if ($status === 'WITHDRAWN' && ($existing['status'] ?? '') !== 'WITHDRAWN') {
                    $stats['withdrawn']++;
                    if ($debug) ecfmp_log("  Withdrawn: {$m['ident']}");
                }
            } else {
                $stats['errors']++;
                if ($debug) ecfmp_log("  Update error for $ext_id: " . json_encode(sqlsrv_errors()), 'ERROR');
            }
        } else {
            // Insert new
            $ins_sql = "INSERT INTO dbo.tmi_flow_measures (
                            provider_id, external_id, ident, event_id,
                            ctl_element, element_type,
                            measure_type, measure_value, measure_unit,
                            reason, filters_json, mandatory_route_json,
                            start_utc, end_utc, status,
                            withdrawn_at, raw_data_json,
                            synced_at, created_at, updated_at
                        ) VALUES (
                            ?, ?, ?, ?,
                            ?, ?,
                            ?, ?, ?,
                            ?, ?, ?,
                            ?, ?, ?,
                            ?, ?,
                            SYSUTCDATETIME(), SYSUTCDATETIME(), SYSUTCDATETIME()
                        )";

            $ins_params = [
                $provider_id,
                $ext_id,
                $m['ident'] ?? $ext_id,
                $m['event_id'] ?? null,
                $ctl_element,
                $element_type,
                $measure_type,
                is_numeric($measure_value) ? (int)$measure_value : null,
                $measure_unit,
                $m['reason'] ?? null,
                $filters_json,
                $mandatory_route_json,
                $start_utc,
                $end_utc,
                $status,
                $withdrawn_at,
                json_encode($m)
            ];

            $ins_stmt = sqlsrv_query($conn_tmi, $ins_sql, $ins_params);
            if ($ins_stmt !== false) {
                sqlsrv_free_stmt($ins_stmt);
                $stats['inserted']++;
                if ($debug) ecfmp_log("  Inserted: {$m['ident']} ($measure_type $status)");
            } else {
                $stats['errors']++;
                if ($debug) ecfmp_log("  Insert error for $ext_id: " . json_encode(sqlsrv_errors()), 'ERROR');
            }
        }
    }

    // Update provider sync status
    $sync_sql = "UPDATE dbo.tmi_flow_providers SET
                    last_sync_utc = SYSUTCDATETIME(),
                    last_sync_status = ?,
                    last_sync_message = ?
                 WHERE provider_id = ?";
    $sync_msg = sprintf('%d fetched, %d new, %d updated, %d unchanged',
        $stats['fetched'], $stats['inserted'], $stats['updated'], $stats['unchanged']);
    $sync_stmt = sqlsrv_query($conn_tmi, $sync_sql, [
        $stats['errors'] > 0 ? 'PARTIAL' : 'SUCCESS',
        $sync_msg,
        $provider_id
    ]);
    if ($sync_stmt !== false) sqlsrv_free_stmt($sync_stmt);

    // Write cache
    @file_put_contents(ECFMP_CACHE_FILE, json_encode($new_cache), LOCK_EX);

    // Reset circuit breaker on success
    if ($stats['errors'] === 0) {
        ecfmp_reset_circuit();
    }

    return [
        'success' => true,
        'message' => $sync_msg,
        'stats'   => $stats
    ];
}

// ============================================================================
// PID / Heartbeat
// ============================================================================

function ecfmp_write_heartbeat(string $file, string $status, array $extra = []): void {
    $payload = array_merge([
        'pid'         => getmypid(),
        'status'      => $status,
        'updated_utc' => gmdate('Y-m-d H:i:s'),
        'unix_ts'     => time(),
    ], $extra);
    @file_put_contents($file, json_encode($payload), LOCK_EX);
}

function ecfmp_write_pid(string $pidFile): void {
    file_put_contents($pidFile, getmypid());
    register_shutdown_function(function () use ($pidFile) {
        if (file_exists($pidFile)) @unlink($pidFile);
    });
}

function ecfmp_check_existing_instance(string $pidFile): bool {
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

$pidFile = sys_get_temp_dir() . '/ecfmp_poll_daemon.pid';
$heartbeatFile = sys_get_temp_dir() . '/ecfmp_poll_daemon.heartbeat';

// Singleton
if (ecfmp_check_existing_instance($pidFile)) {
    ecfmp_log("Another instance is already running. Exiting.", 'WARN');
    exit(1);
}

ecfmp_write_pid($pidFile);
register_shutdown_function(function () use ($heartbeatFile) {
    if (file_exists($heartbeatFile)) @unlink($heartbeatFile);
});
ecfmp_write_heartbeat($heartbeatFile, 'starting');

ecfmp_log("========================================");
ecfmp_log("ECFMP Flow Measure Polling Daemon");
ecfmp_log("  Poll interval: {$pollInterval}s (" . round($pollInterval / 60, 1) . " min)");
ecfmp_log("  Mode: " . ($runLoop ? 'daemon (continuous)' : 'single run'));
ecfmp_log("  NOTE: Runs during hibernation (always-on)");
ecfmp_log("  PID: " . getmypid());
ecfmp_log("========================================");

// Fetch FIR lookup once at startup
$fir_map = ecfmp_fetch_fir_map();
if (empty($fir_map)) {
    ecfmp_log("WARNING: FIR map empty — FIR codes will not be resolved", 'WARN');
}

$cycleCount = 0;

do {
    $cycleStart = microtime(true);
    $cycleCount++;
    ecfmp_write_heartbeat($heartbeatFile, 'running', ['cycle' => $cycleCount]);

    ecfmp_log("--- ECFMP poll cycle #$cycleCount ---");

    try {
        $result = ecfmp_poll($fir_map, $debug);

        if ($result['success']) {
            ecfmp_log("  " . $result['message']);
            if ($debug && !empty($result['stats'])) {
                ecfmp_log("  Stats: " . json_encode($result['stats']), 'DEBUG');
            }
        } else {
            ecfmp_log("  " . $result['message'], 'ERROR');
        }
    } catch (Throwable $e) {
        ecfmp_log("Poll exception: " . $e->getMessage(), 'ERROR');
    }

    // Refresh FIR map every 100 cycles (avoids stale data if ECFMP adds FIRs)
    if ($cycleCount % 100 === 0) {
        $new_fir_map = ecfmp_fetch_fir_map();
        if (!empty($new_fir_map)) {
            $fir_map = $new_fir_map;
        }
    }

    $cycleDuration = microtime(true) - $cycleStart;
    ecfmp_write_heartbeat($heartbeatFile, 'idle', [
        'cycle'    => $cycleCount,
        'cycle_ms' => (int)round($cycleDuration * 1000),
    ]);

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

ecfmp_log("ECFMP Polling Daemon exiting");
