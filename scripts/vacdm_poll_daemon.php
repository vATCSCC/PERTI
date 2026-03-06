<?php
/**
 * vACDM Polling Daemon
 *
 * Polls vACDM instances for A-CDM milestone data (TOBT/TSAT/TTOT/ASAT/EXOT)
 * and updates swim_flights CDM columns + pilot readiness in VATSIM_TMI.
 *
 * Discovers providers from tmi_flow_providers where provider_code = 'VACDM'.
 * Each provider has its own circuit breaker state and poll interval.
 *
 * Flow: vACDM API -> THIS SCRIPT -> swim_flights + sp_CDM_UpdateReadiness
 *       (avoids HTTP round-trip through CDM ingest endpoint)
 *
 * Features:
 *   - Multi-provider: polls all active VACDM providers from tmi_flow_providers
 *   - Per-provider circuit breaker (6 errors / 60s -> 3-min cooldown)
 *   - Delta polling via last_sync_utc on provider row
 *   - Staggered start per provider
 *   - Respects HIBERNATION_MODE (exits gracefully)
 *
 * Usage:
 *   php vacdm_poll_daemon.php [--loop] [--once] [--debug] [--interval=120]
 *
 * @package PERTI
 * @subpackage CDM
 * @version 1.0.0
 * @since 2026-03-05
 */

// Can be run standalone or included
if (!defined('PERTI_LOADED')) {
    define('PERTI_LOADED', true);
    require_once __DIR__ . '/../load/config.php';
    require_once __DIR__ . '/../load/connect.php';
}

// Configuration
define('VACDM_POLL_RATE_LIMIT_MS', 200);       // 200ms between API calls
define('VACDM_CIRCUIT_WINDOW', 60);            // 60 second rolling window
define('VACDM_CIRCUIT_MAX_ERRORS', 6);         // Max errors before cooldown
define('VACDM_CIRCUIT_COOLDOWN', 180);         // 3 minute cooldown
define('VACDM_STATE_DIR', sys_get_temp_dir() . '/perti_vacdm_state/');
define('VACDM_BATCH_SIZE', 200);               // Max flights per provider poll

// Ensure state directory exists
if (!is_dir(VACDM_STATE_DIR)) {
    @mkdir(VACDM_STATE_DIR, 0755, true);
}

// Valid CDM readiness states
$VACDM_VALID_STATES = ['PLANNING', 'BOARDING', 'READY', 'TAXIING', 'CANCELLED'];

/**
 * Main polling function — polls all active vACDM providers
 *
 * @param bool $debug Enable verbose output
 * @return array ['success' => bool, 'message' => string, 'stats' => array]
 */
function vacdm_poll_all($debug = false) {
    global $conn_swim, $conn_tmi;

    $stats = [
        'start_time' => microtime(true),
        'providers_checked' => 0,
        'providers_polled' => 0,
        'providers_skipped' => 0,
        'flights_updated' => 0,
        'readiness_updated' => 0,
        'api_errors' => 0,
        'duration_ms' => 0
    ];

    if (!$conn_swim) {
        return ['success' => false, 'message' => 'SWIM database not available', 'stats' => $stats];
    }
    if (!$conn_tmi) {
        return ['success' => false, 'message' => 'TMI database not available', 'stats' => $stats];
    }

    // Get active vACDM providers
    $providers = get_vacdm_providers($conn_tmi);
    $stats['providers_checked'] = count($providers);

    if (count($providers) === 0) {
        $stats['duration_ms'] = round((microtime(true) - $stats['start_time']) * 1000);
        return ['success' => true, 'message' => 'No active vACDM providers configured', 'stats' => $stats];
    }

    if ($debug) {
        echo "Found " . count($providers) . " vACDM provider(s)\n";
    }

    foreach ($providers as $provider) {
        $pid = $provider['provider_id'];
        $code = $provider['provider_code'];
        $name = $provider['provider_name'];
        $base_url = rtrim($provider['api_base_url'] ?? '', '/');

        if (empty($base_url)) {
            if ($debug) echo "  [{$name}] No API URL configured, skipping\n";
            $stats['providers_skipped']++;
            continue;
        }

        // Per-provider circuit breaker
        if (is_vacdm_circuit_open($pid)) {
            if ($debug) echo "  [{$name}] Circuit breaker open, skipping\n";
            $stats['providers_skipped']++;
            continue;
        }

        if ($debug) echo "  [{$name}] Polling {$base_url}...\n";

        try {
            $result = poll_vacdm_provider($provider, $debug);

            $stats['flights_updated'] += $result['updated'];
            $stats['readiness_updated'] += $result['readiness_updated'];
            $stats['api_errors'] += $result['errors'];
            $stats['providers_polled']++;

            // Update provider sync status
            update_provider_sync_status($conn_tmi, $pid, 'SUCCESS',
                "Updated {$result['updated']} flights, {$result['readiness_updated']} readiness");

        } catch (Exception $e) {
            $stats['api_errors']++;
            vacdm_record_error($pid);

            if (vacdm_should_trip($pid)) {
                vacdm_trip_circuit($pid);
                if ($debug) echo "  [{$name}] Circuit breaker tripped!\n";
            }

            update_provider_sync_status($conn_tmi, $pid, 'FAILED', substr($e->getMessage(), 0, 250));
            error_log("vACDM poll error [{$name}]: " . $e->getMessage());
        }

        // Stagger between providers
        usleep(500000); // 500ms
    }

    $stats['duration_ms'] = round((microtime(true) - $stats['start_time']) * 1000);

    return [
        'success' => true,
        'message' => sprintf(
            'vACDM poll: %d providers, %d flights updated, %d readiness in %dms',
            $stats['providers_polled'], $stats['flights_updated'],
            $stats['readiness_updated'], $stats['duration_ms']
        ),
        'stats' => $stats
    ];
}

/**
 * Get active vACDM providers from tmi_flow_providers
 */
function get_vacdm_providers($conn_tmi) {
    $sql = "SELECT provider_id, provider_code, provider_name, api_base_url,
                   api_version, auth_type, auth_config_json, sync_interval_sec,
                   last_sync_utc
            FROM dbo.tmi_flow_providers
            WHERE provider_code = 'VACDM'
              AND sync_enabled = 1
              AND is_active = 1
            ORDER BY priority ASC";

    $stmt = sqlsrv_query($conn_tmi, $sql);
    if ($stmt === false) {
        return [];
    }

    $providers = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $providers[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    return $providers;
}

/**
 * Poll a single vACDM provider instance
 *
 * @param array $provider Provider row from tmi_flow_providers
 * @param bool $debug
 * @return array Stats for this provider
 */
function poll_vacdm_provider($provider, $debug = false) {
    global $conn_swim, $conn_tmi, $VACDM_VALID_STATES;

    $base_url = rtrim($provider['api_base_url'], '/');
    $pid = $provider['provider_id'];
    $result = ['updated' => 0, 'readiness_updated' => 0, 'not_found' => 0, 'errors' => 0];

    // Build auth headers
    $headers = ['Accept: application/json'];
    if ($provider['auth_type'] === 'API_KEY' && $provider['auth_config_json']) {
        $auth = json_decode($provider['auth_config_json'], true);
        if (!empty($auth['api_key'])) {
            $header_name = $auth['header_name'] ?? 'Authorization';
            $header_prefix = $auth['header_prefix'] ?? 'Bearer ';
            $headers[] = "{$header_name}: {$header_prefix}{$auth['api_key']}";
        }
    }

    // Fetch pilot data from vACDM
    // Common vACDM API patterns: /api/v1/pilots, /api/pilots
    $api_path = '/api/v1/pilots';
    if ($provider['api_version'] === 'v2') {
        $api_path = '/api/v2/pilots';
    }

    $url = $base_url . $api_path;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => $headers
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception("cURL error: {$curlError}");
    }

    if ($httpCode === 401 || $httpCode === 403) {
        throw new Exception("Auth error (HTTP {$httpCode}) for {$url}");
    }

    if ($httpCode !== 200) {
        vacdm_record_error($pid);
        throw new Exception("HTTP {$httpCode} from {$url}");
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("JSON parse error from {$url}");
    }

    // vACDM returns array of pilot objects, or { pilots: [...] }
    $pilots = $data;
    if (isset($data['pilots']) && is_array($data['pilots'])) {
        $pilots = $data['pilots'];
    } elseif (isset($data['data']) && is_array($data['data'])) {
        $pilots = $data['data'];
    }

    if (!is_array($pilots)) {
        if ($debug) echo "    No pilot data in response\n";
        return $result;
    }

    if ($debug) echo "    Received " . count($pilots) . " pilot records\n";

    // Process each pilot record
    $count = 0;
    foreach ($pilots as $pilot) {
        if ($count >= VACDM_BATCH_SIZE) break;

        try {
            $callsign = strtoupper(trim($pilot['callsign'] ?? ''));
            if (empty($callsign)) continue;

            $airport = strtoupper(trim($pilot['airport'] ?? $pilot['dep'] ?? $pilot['departure'] ?? ''));

            // Extract CDM milestones — vACDM field names vary by instance
            $tobt = $pilot['tobt'] ?? $pilot['tobt_utc'] ?? $pilot['targetOffBlockTime'] ?? null;
            $tsat = $pilot['tsat'] ?? $pilot['tsat_utc'] ?? $pilot['targetStartApprovalTime'] ?? null;
            $ttot = $pilot['ttot'] ?? $pilot['ttot_utc'] ?? $pilot['targetTakeoffTime'] ?? null;
            $asat = $pilot['asat'] ?? $pilot['asat_utc'] ?? $pilot['actualStartApprovalTime'] ?? null;
            $exot = $pilot['exot'] ?? $pilot['taxiTime'] ?? $pilot['expectedTaxiOut'] ?? null;
            $state = strtoupper(trim($pilot['vacdm_status'] ?? $pilot['status'] ?? $pilot['readiness'] ?? ''));

            // Skip if no CDM data at all
            if (!$tobt && !$tsat && !$ttot && !$asat && empty($state)) {
                continue;
            }

            // Build swim_flights UPDATE
            $set_clauses = [];
            $params = [];

            if ($tobt) {
                $set_clauses[] = 'target_off_block_time = TRY_CONVERT(datetime2, ?)';
                $params[] = $tobt;
            }
            if ($tsat) {
                $set_clauses[] = 'target_startup_approval_time = TRY_CONVERT(datetime2, ?)';
                $params[] = $tsat;
            }
            if ($ttot) {
                $set_clauses[] = 'target_takeoff_time = TRY_CONVERT(datetime2, ?)';
                $params[] = $ttot;
            }
            if ($asat) {
                $set_clauses[] = 'actual_startup_approval_time = TRY_CONVERT(datetime2, ?)';
                $params[] = $asat;
            }
            if ($exot !== null && is_numeric($exot)) {
                $exot_val = intval($exot);
                if ($exot_val >= 0 && $exot_val <= 120) {
                    $set_clauses[] = 'expected_taxi_out_time = ?';
                    $params[] = $exot_val;
                }
            }

            // Always set source tracking
            $set_clauses[] = 'cdm_source = ?';
            $params[] = 'VACDM';
            $set_clauses[] = 'cdm_updated_at = GETUTCDATE()';
            $set_clauses[] = 'last_sync_utc = GETUTCDATE()';

            // Find flight by callsign + departure airport
            if (!empty($airport)) {
                $params[] = $callsign;
                $params[] = $airport;
                $where = "callsign = ? AND fp_dept_icao = ? AND is_active = 1";
            } else {
                $params[] = $callsign;
                $where = "callsign = ? AND is_active = 1";
            }

            $sql = "UPDATE dbo.swim_flights SET " . implode(', ', $set_clauses) . " WHERE " . $where;
            $upd_stmt = sqlsrv_query($conn_swim, $sql, $params);

            if ($upd_stmt !== false) {
                $rows = sqlsrv_rows_affected($upd_stmt);
                sqlsrv_free_stmt($upd_stmt);
                if ($rows > 0) {
                    $result['updated']++;
                } else {
                    $result['not_found']++;
                }
            }

            // Update readiness state in VATSIM_TMI if valid
            if (!empty($state) && in_array($state, $VACDM_VALID_STATES) && $conn_tmi) {
                // Need flight_uid for the SP — look it up
                $uid = get_flight_uid_by_callsign($conn_swim, $callsign, $airport);
                if ($uid) {
                    $sp_sql = "EXEC sp_CDM_UpdateReadiness ?, ?, ?, ?, ?, ?";
                    $sp_params = [$uid, $callsign, $airport, $state, $tobt, 'VACDM'];
                    $sp_stmt = sqlsrv_query($conn_tmi, $sp_sql, $sp_params);
                    if ($sp_stmt !== false) {
                        sqlsrv_free_stmt($sp_stmt);
                        $result['readiness_updated']++;
                    }
                }
            }

            $count++;

            // Rate limit between updates
            usleep(VACDM_POLL_RATE_LIMIT_MS * 1000);

        } catch (Exception $e) {
            $result['errors']++;
            if ($debug) echo "    Error for {$callsign}: " . $e->getMessage() . "\n";
        }
    }

    return $result;
}

/**
 * Look up flight_uid from swim_flights by callsign
 */
function get_flight_uid_by_callsign($conn_swim, $callsign, $airport = '') {
    if (!empty($airport)) {
        $sql = "SELECT TOP 1 flight_uid FROM dbo.swim_flights
                WHERE callsign = ? AND fp_dept_icao = ? AND is_active = 1
                ORDER BY last_sync_utc DESC";
        $params = [$callsign, $airport];
    } else {
        $sql = "SELECT TOP 1 flight_uid FROM dbo.swim_flights
                WHERE callsign = ? AND is_active = 1
                ORDER BY last_sync_utc DESC";
        $params = [$callsign];
    }

    $stmt = sqlsrv_query($conn_swim, $sql, $params);
    if ($stmt === false) return null;

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return $row ? $row['flight_uid'] : null;
}

/**
 * Update provider sync status in tmi_flow_providers
 */
function update_provider_sync_status($conn_tmi, $provider_id, $status, $message = '') {
    $sql = "UPDATE dbo.tmi_flow_providers
            SET last_sync_utc = GETUTCDATE(),
                last_sync_status = ?,
                last_sync_message = ?
            WHERE provider_id = ?";
    $stmt = @sqlsrv_query($conn_tmi, $sql, [$status, substr($message, 0, 256), $provider_id]);
    if ($stmt !== false) sqlsrv_free_stmt($stmt);
}

// === PER-PROVIDER CIRCUIT BREAKER ===

function vacdm_state_file($provider_id) {
    return VACDM_STATE_DIR . "circuit_{$provider_id}.json";
}

function get_vacdm_circuit_state($provider_id) {
    $file = vacdm_state_file($provider_id);
    if (!file_exists($file)) {
        return ['errors' => [], 'cooldown_until' => null];
    }
    return json_decode(file_get_contents($file), true) ?? ['errors' => [], 'cooldown_until' => null];
}

function save_vacdm_circuit_state($provider_id, $state) {
    file_put_contents(vacdm_state_file($provider_id), json_encode($state), LOCK_EX);
}

function is_vacdm_circuit_open($provider_id) {
    $state = get_vacdm_circuit_state($provider_id);
    return !empty($state['cooldown_until']) && $state['cooldown_until'] > time();
}

function vacdm_record_error($provider_id) {
    $state = get_vacdm_circuit_state($provider_id);
    $now = time();
    $state['errors'] = array_values(array_filter($state['errors'] ?? [], function($ts) use ($now) {
        return ($now - $ts) <= VACDM_CIRCUIT_WINDOW;
    }));
    $state['errors'][] = $now;
    save_vacdm_circuit_state($provider_id, $state);
}

function vacdm_should_trip($provider_id) {
    $state = get_vacdm_circuit_state($provider_id);
    return count($state['errors'] ?? []) >= VACDM_CIRCUIT_MAX_ERRORS;
}

function vacdm_trip_circuit($provider_id) {
    $state = ['errors' => [], 'cooldown_until' => time() + VACDM_CIRCUIT_COOLDOWN];
    save_vacdm_circuit_state($provider_id, $state);
}

// === CLI RUNNER ===

if (php_sapi_name() === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {

    // Check hibernation mode
    if (defined('HIBERNATION_MODE') && HIBERNATION_MODE) {
        echo "vACDM daemon skipped (HIBERNATION_MODE active)\n";
        exit(0);
    }

    $loop = in_array('--loop', $argv);
    $once = in_array('--once', $argv);
    $debug = in_array('--debug', $argv);
    $interval = 120; // Default 2 minutes

    foreach ($argv as $arg) {
        if (preg_match('/^--interval=(\d+)$/', $arg, $m)) {
            $interval = intval($m[1]);
        }
    }

    $continuous = $loop && !$once;

    echo "vACDM Polling Daemon\n";
    echo "====================\n";
    echo "Mode: " . ($continuous ? "Continuous (every {$interval}s)" : 'Single run') . "\n";
    echo "Debug: " . ($debug ? 'ON' : 'OFF') . "\n\n";

    // PID singleton
    $pid_file = sys_get_temp_dir() . '/perti_vacdm_poll.pid';
    if (file_exists($pid_file)) {
        $old_pid = trim(file_get_contents($pid_file));
        if ($old_pid && file_exists("/proc/{$old_pid}")) {
            echo "Already running (PID: {$old_pid})\n";
            exit(1);
        }
    }
    file_put_contents($pid_file, getmypid());

    // Signal handling
    $running = true;
    if (function_exists('pcntl_signal')) {
        pcntl_signal(SIGTERM, function() use (&$running, $pid_file) {
            echo "\nSIGTERM received, shutting down...\n";
            $running = false;
            @unlink($pid_file);
        });
        pcntl_signal(SIGINT, function() use (&$running, $pid_file) {
            echo "\nSIGINT received, shutting down...\n";
            $running = false;
            @unlink($pid_file);
        });
    }

    do {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        echo "[" . gmdate('Y-m-d H:i:s') . "Z] Starting vACDM poll cycle...\n";

        $result = vacdm_poll_all($debug);

        echo "  " . ($result['success'] ? 'OK' : 'FAILED') . ": " . $result['message'] . "\n";

        if ($continuous && $running) {
            // Sleep in 1-second increments to respond to signals
            $slept = 0;
            while ($slept < $interval && $running) {
                sleep(1);
                $slept++;
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
            }
        }

    } while ($continuous && $running);

    @unlink($pid_file);
    echo "vACDM daemon stopped.\n";
    exit($result['success'] ? 0 : 1);
}
