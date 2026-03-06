<?php
/**
 * CDM Daemon — Collaborative Decision Making Background Processor
 *
 * Long-running daemon that orchestrates CDM operations on a 60-second cycle:
 *   1. Auto-detect pilot readiness from ADL flight data
 *   2. Compute TSAT/TTOT milestones for flights with TOBT
 *   3. Evaluate EDCT compliance for controlled flights
 *   4. Snapshot airport CDM status for airports with departures
 *   5. Process pending EDCT message delivery
 *   6. Evaluate CDM triggers (every 5 minutes)
 *   7. Purge old CDM data (every 6 hours)
 *
 * Hibernation-aware: During hibernation, readiness detection and data
 * accumulation continue (queued), but message delivery is skipped.
 *
 * Usage:
 *   php cdm_daemon.php [--loop] [--interval=60] [--debug]
 *
 * Options:
 *   --loop              Run continuously (daemon mode)
 *   --interval=N        Seconds between cycles (default: 60, min: 30)
 *   --trigger-interval=N Seconds between trigger evaluations (default: 300)
 *   --purge-interval=N  Seconds between data purge (default: 21600 = 6h)
 *   --debug             Enable verbose logging
 *
 * @package PERTI
 * @subpackage CDM
 * @version 1.0.0
 */

// Parse command line arguments
$options = getopt('', ['loop', 'interval:', 'trigger-interval:', 'purge-interval:', 'debug']);
$runLoop = isset($options['loop']);
$cycleInterval = isset($options['interval']) ? (int)$options['interval'] : 60;
$triggerInterval = isset($options['trigger-interval']) ? (int)$options['trigger-interval'] : 300;
$purgeInterval = isset($options['purge-interval']) ? (int)$options['purge-interval'] : 21600;
$debug = isset($options['debug']);

// Enforce minimums
$cycleInterval = max(30, $cycleInterval);
$triggerInterval = max(60, $triggerInterval);
$purgeInterval = max(3600, $purgeInterval);

// Load dependencies
if (!defined('PERTI_LOADED')) {
    define('PERTI_LOADED', true);
    require_once __DIR__ . '/../load/config.php';
    require_once __DIR__ . '/../load/connect.php';
}
require_once __DIR__ . '/../load/services/CDMService.php';
require_once __DIR__ . '/../load/services/EDCTDelivery.php';

// ============================================================================
// Logging
// ============================================================================

function cdm_log(string $message, string $level = 'INFO'): void {
    $timestamp = gmdate('Y-m-d H:i:s');
    echo "[$timestamp UTC] [$level] $message\n";
}

// ============================================================================
// PID / Heartbeat (same pattern as swim_sync_daemon.php)
// ============================================================================

function cdm_write_heartbeat(string $file, string $status, array $extra = []): void {
    $payload = array_merge([
        'pid'         => getmypid(),
        'status'      => $status,
        'updated_utc' => gmdate('Y-m-d H:i:s'),
        'unix_ts'     => time(),
    ], $extra);

    $written = @file_put_contents($file, json_encode($payload), LOCK_EX);
    if ($written === false) {
        cdm_log("Failed to write heartbeat file: $file", 'WARN');
    }
}

function cdm_write_pid(string $pidFile): void {
    file_put_contents($pidFile, getmypid());
    register_shutdown_function(function () use ($pidFile) {
        if (file_exists($pidFile)) {
            @unlink($pidFile);
        }
    });
}

function cdm_check_existing_instance(string $pidFile): bool {
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
// Step 1: Auto-detect readiness for ground-phase flights
// ============================================================================

function cdm_step_readiness(CDMService $cdm): array {
    $conn_adl = get_conn_adl();
    $stats = ['detected' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

    // Get flights at airports (ground phase, active) that haven't been
    // checked recently (no readiness or readiness older than 60s)
    $sql = "SELECT
                c.flight_uid, c.callsign, c.cid, c.phase, c.is_active,
                p.fp_dept_icao, p.fp_dest_icao,
                pos.altitude_ft, pos.groundspeed_kts,
                t.cdm_readiness_state,
                t.tobt_utc
            FROM dbo.adl_flight_core c
            JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
            LEFT JOIN dbo.adl_flight_position pos ON c.flight_uid = pos.flight_uid
            LEFT JOIN dbo.adl_flight_times t ON c.flight_uid = t.flight_uid
            WHERE c.is_active = 1
              AND (c.phase IN ('prefiled', 'prefile', 'ground', 'departing', 'PREFILED', 'GROUND', 'DEPARTING')
                   OR (pos.altitude_ft IS NOT NULL AND pos.altitude_ft < 500))
              AND p.fp_dept_icao IS NOT NULL";

    $stmt = sqlsrv_query($conn_adl, $sql);
    if ($stmt === false) {
        $stats['errors']++;
        return $stats;
    }

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $stats['detected']++;

        $flight_data = [
            'phase'          => $row['phase'],
            'groundspeed_kts'=> $row['groundspeed_kts'],
            'altitude_ft'    => $row['altitude_ft'],
            'is_active'      => $row['is_active'],
        ];

        $new_state = $cdm->autoDetectReadiness($flight_data);
        if ($new_state === null) {
            $stats['skipped']++;
            continue;
        }

        // Skip if state hasn't changed
        if ($new_state === $row['cdm_readiness_state']) {
            $stats['skipped']++;
            continue;
        }

        $result = $cdm->updateReadiness(
            (int)$row['flight_uid'],
            $row['callsign'],
            $new_state,
            'auto',
            $row['cid'] ? (int)$row['cid'] : null,
            null, // reported_tobt
            $row['fp_dept_icao'],
            $row['fp_dest_icao']
        );

        if ($result !== false) {
            $stats['updated']++;
        } else {
            $stats['errors']++;
        }
    }
    sqlsrv_free_stmt($stmt);

    return $stats;
}

// ============================================================================
// Step 2: Compute TSAT/TTOT milestones
// ============================================================================

function cdm_step_milestones(CDMService $cdm): array {
    $conn_adl = get_conn_adl();
    $stats = ['computed' => 0, 'saved' => 0, 'skipped' => 0, 'errors' => 0];

    // Get flights that have TOBT but missing TSAT, or EDCT changed
    $sql = "SELECT
                t.flight_uid,
                p.fp_dept_icao,
                t.tobt_utc,
                t.tsat_utc,
                t.ttot_utc,
                t.edct_utc,
                t.tsat_source
            FROM dbo.adl_flight_times t
            JOIN dbo.adl_flight_core c ON t.flight_uid = c.flight_uid
            JOIN dbo.adl_flight_plan p ON t.flight_uid = p.flight_uid
            WHERE c.is_active = 1
              AND t.tobt_utc IS NOT NULL
              AND p.fp_dept_icao IS NOT NULL
              AND (t.tsat_utc IS NULL OR t.tsat_source = 'calculated')";

    $stmt = sqlsrv_query($conn_adl, $sql);
    if ($stmt === false) {
        $stats['errors']++;
        return $stats;
    }

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $tobt_str = ($row['tobt_utc'] instanceof \DateTime)
            ? $row['tobt_utc']->format('Y-m-d H:i:s')
            : $row['tobt_utc'];
        $edct_str = null;
        if ($row['edct_utc'] !== null) {
            $edct_str = ($row['edct_utc'] instanceof \DateTime)
                ? $row['edct_utc']->format('Y-m-d H:i:s')
                : $row['edct_utc'];
        }

        $milestones = $cdm->computeMilestones(
            (int)$row['flight_uid'],
            $row['fp_dept_icao'],
            $tobt_str,
            $edct_str
        );
        $stats['computed']++;

        if ($milestones['tsat_utc'] === null) {
            $stats['skipped']++;
            continue;
        }

        // Check if milestones actually changed
        $existing_tsat = ($row['tsat_utc'] instanceof \DateTime)
            ? $row['tsat_utc']->format('Y-m-d H:i:s')
            : $row['tsat_utc'];

        if ($milestones['tsat_utc'] === $existing_tsat) {
            $stats['skipped']++;
            continue;
        }

        if ($cdm->saveMilestones((int)$row['flight_uid'], $milestones)) {
            $stats['saved']++;
        } else {
            $stats['errors']++;
        }
    }
    sqlsrv_free_stmt($stmt);

    return $stats;
}

// ============================================================================
// Step 3: Evaluate EDCT compliance for controlled flights
// ============================================================================

function cdm_step_compliance(CDMService $cdm): array {
    $conn_adl = get_conn_adl();
    $conn_tmi = get_conn_tmi();
    $stats = ['evaluated' => 0, 'at_risk' => 0, 'compliant' => 0, 'errors' => 0];

    // Step A: Get controlled flights from TMI (separate DB from ADL)
    $tmi_sql = "SELECT fc.flight_uid, fc.callsign, fc.program_id, fc.slot_id,
                       fc.ctd_utc AS edct_utc
                FROM dbo.tmi_flight_control fc
                JOIN dbo.tmi_programs p ON fc.program_id = p.program_id
                WHERE p.is_active = 1 AND fc.ctd_utc IS NOT NULL";

    $tmi_stmt = sqlsrv_query($conn_tmi, $tmi_sql);
    if ($tmi_stmt === false) {
        $stats['errors']++;
        return $stats;
    }

    $controlled = [];
    while ($row = sqlsrv_fetch_array($tmi_stmt, SQLSRV_FETCH_ASSOC)) {
        $controlled[(int)$row['flight_uid']] = $row;
    }
    sqlsrv_free_stmt($tmi_stmt);

    if (empty($controlled)) return $stats;

    // Step B: Get flight times from ADL for these flight_uids
    // Build batch lookup (chunks of 500 to stay under parameter limits)
    foreach (array_chunk(array_keys($controlled), 500) as $uid_chunk) {
        $placeholders = implode(',', array_fill(0, count($uid_chunk), '?'));
        $adl_sql = "SELECT flight_uid, off_utc, ttot_utc
                    FROM dbo.adl_flight_times
                    WHERE flight_uid IN ($placeholders)";

        $adl_stmt = sqlsrv_query($conn_adl, $adl_sql, $uid_chunk);
        if ($adl_stmt === false) {
            $stats['errors']++;
            continue;
        }

        $adl_times = [];
        while ($arow = sqlsrv_fetch_array($adl_stmt, SQLSRV_FETCH_ASSOC)) {
            $adl_times[(int)$arow['flight_uid']] = $arow;
        }
        sqlsrv_free_stmt($adl_stmt);

        // Step C: Evaluate compliance for each controlled flight
        foreach ($uid_chunk as $uid) {
            if (!isset($controlled[$uid])) continue;
            $fc = $controlled[$uid];
            $times = $adl_times[$uid] ?? [];

            $edct_str = ($fc['edct_utc'] instanceof \DateTime)
                ? $fc['edct_utc']->format('Y-m-d H:i:s')
                : $fc['edct_utc'];

            $actual_off_str = null;
            $off = $times['off_utc'] ?? null;
            if ($off !== null) {
                $actual_off_str = ($off instanceof \DateTime) ? $off->format('Y-m-d H:i:s') : $off;
            }

            $ttot_str = null;
            $ttot = $times['ttot_utc'] ?? null;
            if ($ttot !== null) {
                $ttot_str = ($ttot instanceof \DateTime) ? $ttot->format('Y-m-d H:i:s') : $ttot;
            }

            $cdm->evaluateEDCTCompliance(
                $uid,
                $fc['callsign'],
                (int)$fc['program_id'],
                $fc['slot_id'] ? (int)$fc['slot_id'] : null,
                $edct_str,
                $actual_off_str,
                $ttot_str
            );
            $stats['evaluated']++;
        }
    }

    return $stats;
}

// ============================================================================
// Step 4: Airport CDM status snapshots
// ============================================================================

function cdm_step_airport_snapshots(CDMService $cdm): array {
    $conn_tmi = get_conn_tmi();
    $stats = ['airports' => 0, 'errors' => 0];

    $is_hibernation = defined('HIBERNATION_MODE') && HIBERNATION_MODE;

    // Get airports with active CDM readiness entries
    $sql = "SELECT DISTINCT dep_airport
            FROM dbo.vw_cdm_current_readiness
            WHERE dep_airport IS NOT NULL";

    $stmt = sqlsrv_query($conn_tmi, $sql);
    if ($stmt === false) {
        $stats['errors']++;
        return $stats;
    }

    $airports = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $airports[] = $row['dep_airport'];
    }
    sqlsrv_free_stmt($stmt);

    foreach ($airports as $icao) {
        $snap_sql = "EXEC dbo.sp_CDM_SnapshotAirportStatus @airport_icao = ?, @is_hibernation = ?";
        $snap_stmt = sqlsrv_query($conn_tmi, $snap_sql, [$icao, $is_hibernation ? 1 : 0]);
        if ($snap_stmt !== false) {
            sqlsrv_free_stmt($snap_stmt);
            $stats['airports']++;
        } else {
            $stats['errors']++;
        }
    }

    return $stats;
}

// ============================================================================
// Step 5: Process pending message delivery
// ============================================================================

function cdm_step_delivery(EDCTDelivery $edct): array {
    return $edct->processPendingDeliveries(20);
}

// ============================================================================
// Step 6: Evaluate CDM triggers
// ============================================================================

function cdm_step_triggers(): array {
    $conn_tmi = get_conn_tmi();
    $conn_adl = get_conn_adl();
    $stats = ['evaluated' => 0, 'fired' => 0, 'errors' => 0];

    $is_hibernation = defined('HIBERNATION_MODE') && HIBERNATION_MODE;
    if ($is_hibernation) {
        return $stats; // No trigger evaluation during hibernation
    }

    // Get active armed triggers
    $sql = "SELECT trigger_id, trigger_name, condition_type, condition_json,
                   action_type, action_json, airport_icao, facility_code,
                   cooldown_minutes, last_fired_utc, consecutive_met,
                   required_consecutive
            FROM dbo.cdm_triggers
            WHERE is_active = 1 AND is_armed = 1";

    $stmt = sqlsrv_query($conn_tmi, $sql);
    if ($stmt === false) {
        $stats['errors']++;
        return $stats;
    }

    while ($trigger = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $stats['evaluated']++;
        $trigger_id = (int)$trigger['trigger_id'];

        // Check cooldown
        if ($trigger['last_fired_utc'] !== null) {
            $last_fired = ($trigger['last_fired_utc'] instanceof \DateTime)
                ? $trigger['last_fired_utc']->getTimestamp()
                : strtotime($trigger['last_fired_utc']);
            $cooldown_sec = (int)$trigger['cooldown_minutes'] * 60;
            if ((time() - $last_fired) < $cooldown_sec) {
                cdm_trigger_log($conn_tmi, $trigger_id, 'COOLDOWN_SKIP', null, null);
                continue;
            }
        }

        // Evaluate condition
        $condition_met = cdm_evaluate_trigger_condition(
            $conn_tmi, $conn_adl,
            $trigger['condition_type'],
            $trigger['condition_json'],
            $trigger['airport_icao']
        );

        if ($condition_met) {
            $new_consecutive = (int)$trigger['consecutive_met'] + 1;

            // Update consecutive count
            $up_sql = "UPDATE dbo.cdm_triggers SET consecutive_met = ? WHERE trigger_id = ?";
            sqlsrv_query($conn_tmi, $up_sql, [$new_consecutive, $trigger_id]);

            if ($new_consecutive >= (int)$trigger['required_consecutive']) {
                // Fire the trigger
                $action_result = cdm_fire_trigger_action(
                    $conn_tmi,
                    $trigger['action_type'],
                    $trigger['action_json'],
                    $trigger['airport_icao']
                );

                // Reset consecutive and record last_fired
                $reset_sql = "UPDATE dbo.cdm_triggers SET consecutive_met = 0, last_fired_utc = GETUTCDATE() WHERE trigger_id = ?";
                sqlsrv_query($conn_tmi, $reset_sql, [$trigger_id]);

                cdm_trigger_log($conn_tmi, $trigger_id, 'FIRED', true, $action_result);
                $stats['fired']++;
            } else {
                cdm_trigger_log($conn_tmi, $trigger_id, 'CONDITION_MET', true, null);
            }
        } else {
            // Reset consecutive counter
            if ((int)$trigger['consecutive_met'] > 0) {
                $reset_sql = "UPDATE dbo.cdm_triggers SET consecutive_met = 0 WHERE trigger_id = ?";
                sqlsrv_query($conn_tmi, $reset_sql, [$trigger_id]);
            }
            cdm_trigger_log($conn_tmi, $trigger_id, 'EVALUATED', false, null);
        }
    }
    sqlsrv_free_stmt($stmt);

    return $stats;
}

/**
 * Evaluate a trigger condition against current data.
 */
function cdm_evaluate_trigger_condition($conn_tmi, $conn_adl, string $type, string $json, ?string $airport): bool {
    $config = json_decode($json, true);
    if (!$config) return false;

    switch ($type) {
        case 'demand_exceeds_rate':
            return cdm_condition_demand_exceeds_rate($conn_adl, $airport, $config);

        case 'weather_change':
            return cdm_condition_weather_change($conn_adl, $airport, $config);

        case 'fix_demand':
            return cdm_condition_fix_demand($conn_adl, $config);

        case 'gs_duration':
            return cdm_condition_gs_duration($conn_tmi, $airport, $config);

        default:
            return false;
    }
}

/**
 * Condition: demand exceeds rate by threshold percentage.
 */
function cdm_condition_demand_exceeds_rate($conn_adl, ?string $airport, array $config): bool {
    if (!$airport) return false;
    $threshold_pct = (float)($config['threshold_pct'] ?? 120);
    $default_aar = (int)($config['default_aar'] ?? 30);

    // Count demand for the next hour
    $demand_sql = "SELECT COUNT(*) AS demand
                   FROM dbo.adl_flight_times t
                   JOIN dbo.adl_flight_core c ON t.flight_uid = c.flight_uid
                   JOIN dbo.adl_flight_plan p ON t.flight_uid = p.flight_uid
                   WHERE p.fp_dest_icao = ? AND c.is_active = 1
                     AND t.eta_utc BETWEEN GETUTCDATE() AND DATEADD(HOUR, 1, GETUTCDATE())";

    $demand_stmt = sqlsrv_query($conn_adl, $demand_sql, [$airport]);
    if ($demand_stmt === false) return false;
    $demand_row = sqlsrv_fetch_array($demand_stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($demand_stmt);
    $demand = (int)($demand_row['demand'] ?? 0);

    // Get AAR from manual_rate_override (ADL) or use trigger-defined default
    $aar_sql = "SELECT TOP 1 override_aar FROM dbo.manual_rate_override
                WHERE airport_icao = ? AND is_active = 1";
    $aar_stmt = sqlsrv_query($conn_adl, $aar_sql, [$airport]);
    $aar = $default_aar;
    if ($aar_stmt !== false) {
        $aar_row = sqlsrv_fetch_array($aar_stmt, SQLSRV_FETCH_ASSOC);
        if ($aar_row && $aar_row['override_aar']) {
            $aar = (int)$aar_row['override_aar'];
        }
        sqlsrv_free_stmt($aar_stmt);
    }

    if ($aar <= 0) return false;

    $demand_pct = ($demand / $aar) * 100;
    return $demand_pct >= $threshold_pct;
}

/**
 * Condition: weather category change (e.g., VMC → IMC).
 */
function cdm_condition_weather_change($conn_adl, ?string $airport, array $config): bool {
    if (!$airport) return false;
    $target_wx = $config['weather_category'] ?? 'IMC';

    // Check latest ATIS-derived weather category
    $sql = "SELECT TOP 1 weather_category
            FROM dbo.atis_config_history
            WHERE airport_icao = ?
            ORDER BY detected_utc DESC";

    $stmt = sqlsrv_query($conn_adl, $sql, [$airport]);
    if ($stmt === false) return false;

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    return $row && $row['weather_category'] === $target_wx;
}

/**
 * Condition: fix demand exceeds threshold.
 */
function cdm_condition_fix_demand($conn_adl, array $config): bool {
    $fix_name = $config['fix_name'] ?? null;
    $threshold = (int)($config['threshold'] ?? 20);
    $window_minutes = (int)($config['window_minutes'] ?? 60);
    if (!$fix_name) return false;

    $sql = "SELECT COUNT(*) AS cnt
            FROM dbo.adl_flight_waypoints w
            JOIN dbo.adl_flight_core c ON w.flight_uid = c.flight_uid
            WHERE w.fix_name = ? AND c.is_active = 1
              AND w.eta_utc BETWEEN GETUTCDATE() AND DATEADD(MINUTE, ?, GETUTCDATE())";

    $stmt = sqlsrv_query($conn_adl, $sql, [$fix_name, $window_minutes]);
    if ($stmt === false) return false;

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    return $row && (int)$row['cnt'] >= $threshold;
}

/**
 * Condition: ground stop has been active for N minutes.
 */
function cdm_condition_gs_duration($conn_tmi, ?string $airport, array $config): bool {
    if (!$airport) return false;
    $min_duration = (int)($config['min_duration_minutes'] ?? 60);

    $sql = "SELECT TOP 1 start_utc
            FROM dbo.tmi_programs
            WHERE ctl_element = ? AND program_type = 'GS' AND is_active = 1
            ORDER BY start_utc ASC";

    $stmt = sqlsrv_query($conn_tmi, $sql, [$airport]);
    if ($stmt === false) return false;

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$row) return false;

    $start_ts = ($row['start_utc'] instanceof \DateTime)
        ? $row['start_utc']->getTimestamp()
        : strtotime($row['start_utc']);

    return (time() - $start_ts) >= ($min_duration * 60);
}

/**
 * Fire a trigger action.
 */
function cdm_fire_trigger_action($conn_tmi, string $type, string $json, ?string $airport): ?string {
    $config = json_decode($json, true);
    if (!$config) return 'FAILED: invalid action JSON';

    switch ($type) {
        case 'alert':
            // Log alert — future: push to WebSocket/Discord
            cdm_log("TRIGGER ALERT: " . ($config['message'] ?? 'No message') . " for $airport");
            return 'SUCCESS';

        case 'propose_gdp':
        case 'propose_gs':
        case 'propose_mit':
        case 'adjust_rate':
            // Future: create TMI proposal via tmi_proposals
            cdm_log("TRIGGER ACTION: $type for $airport (config: " . json_encode($config) . ")", 'WARN');
            return 'PROPOSAL_STUB';

        default:
            return 'FAILED: unknown action type';
    }
}

/**
 * Write a trigger evaluation log entry.
 */
function cdm_trigger_log($conn_tmi, int $trigger_id, string $event_type, ?bool $condition_result, ?string $action_result): void {
    $sql = "INSERT INTO dbo.cdm_trigger_log (trigger_id, event_type, condition_result, action_result)
            VALUES (?, ?, ?, ?)";

    $stmt = sqlsrv_query($conn_tmi, $sql, [
        $trigger_id,
        $event_type,
        $condition_result === null ? null : ($condition_result ? 1 : 0),
        $action_result
    ]);
    if ($stmt !== false) {
        sqlsrv_free_stmt($stmt);
    }
}

// ============================================================================
// Step 7: Purge old CDM data
// ============================================================================

function cdm_step_purge(): array {
    $conn_tmi = get_conn_tmi();
    $stats = ['success' => false];

    $sql = "EXEC dbo.sp_CDM_PurgeOldData";
    $stmt = sqlsrv_query($conn_tmi, $sql);
    if ($stmt !== false) {
        // Consume all result sets (SP uses PRINT)
        while (sqlsrv_next_result($stmt)) {}
        sqlsrv_free_stmt($stmt);
        $stats['success'] = true;
    }

    return $stats;
}

// ============================================================================
// Main Daemon Logic
// ============================================================================

$pidFile = sys_get_temp_dir() . '/cdm_daemon.pid';
$heartbeatFile = sys_get_temp_dir() . '/cdm_daemon.heartbeat';

// Singleton check
if (cdm_check_existing_instance($pidFile)) {
    cdm_log("Another instance is already running. Exiting.", 'WARN');
    exit(1);
}

// Write PID file
cdm_write_pid($pidFile);
register_shutdown_function(function () use ($heartbeatFile) {
    if (file_exists($heartbeatFile)) {
        @unlink($heartbeatFile);
    }
});
cdm_write_heartbeat($heartbeatFile, 'starting');

// Initialize services
$conn_tmi = get_conn_tmi();
$conn_adl = get_conn_adl();

if (!$conn_tmi || !$conn_adl) {
    cdm_log("Required database connections unavailable. Exiting.", 'ERROR');
    exit(1);
}

$cdm = new CDMService($conn_tmi, $conn_adl, $debug);
$edct = new EDCTDelivery($cdm, $conn_tmi, $debug);

cdm_log("========================================");
cdm_log("CDM Daemon Starting");
cdm_log("  Cycle interval:   {$cycleInterval}s (" . round($cycleInterval / 60, 1) . " min)");
cdm_log("  Trigger interval: {$triggerInterval}s (" . round($triggerInterval / 60, 1) . " min)");
cdm_log("  Purge interval:   {$purgeInterval}s (" . round($purgeInterval / 3600, 1) . " hours)");
cdm_log("  Mode:             " . ($runLoop ? 'daemon (continuous)' : 'single run'));
cdm_log("  Hibernation:      " . (defined('HIBERNATION_MODE') && HIBERNATION_MODE ? 'ACTIVE' : 'off'));
cdm_log("  PID:              " . getmypid());
cdm_log("========================================");

$lastTriggerTime = 0;
$lastPurgeTime = 0;
$cycleCount = 0;

do {
    $cycleStart = microtime(true);
    $cycleCount++;
    cdm_write_heartbeat($heartbeatFile, 'running', ['cycle' => $cycleCount]);

    cdm_log("--- CDM cycle #$cycleCount ---");

    // ====================================================================
    // Step 1: Auto-detect readiness
    // ====================================================================
    try {
        $readiness_stats = cdm_step_readiness($cdm);
        if ($debug || $readiness_stats['updated'] > 0) {
            cdm_log("  Readiness: {$readiness_stats['detected']} checked, {$readiness_stats['updated']} updated, {$readiness_stats['skipped']} unchanged");
        }
    } catch (Throwable $e) {
        cdm_log("  Readiness error: " . $e->getMessage(), 'ERROR');
    }

    // ====================================================================
    // Step 2: Compute TSAT/TTOT milestones
    // ====================================================================
    try {
        $milestone_stats = cdm_step_milestones($cdm);
        if ($debug || $milestone_stats['saved'] > 0) {
            cdm_log("  Milestones: {$milestone_stats['computed']} computed, {$milestone_stats['saved']} saved");
        }
    } catch (Throwable $e) {
        cdm_log("  Milestones error: " . $e->getMessage(), 'ERROR');
    }

    // ====================================================================
    // Step 3: Evaluate EDCT compliance
    // ====================================================================
    try {
        $compliance_stats = cdm_step_compliance($cdm);
        if ($debug || $compliance_stats['evaluated'] > 0) {
            cdm_log("  Compliance: {$compliance_stats['evaluated']} evaluated");
        }
    } catch (Throwable $e) {
        cdm_log("  Compliance error: " . $e->getMessage(), 'ERROR');
    }

    // ====================================================================
    // Step 4: Airport status snapshots
    // ====================================================================
    try {
        $airport_stats = cdm_step_airport_snapshots($cdm);
        if ($debug || $airport_stats['airports'] > 0) {
            cdm_log("  Airport snapshots: {$airport_stats['airports']} airports");
        }
    } catch (Throwable $e) {
        cdm_log("  Airport snapshot error: " . $e->getMessage(), 'ERROR');
    }

    // ====================================================================
    // Step 5: Process pending message delivery
    // ====================================================================
    try {
        $delivery_stats = cdm_step_delivery($edct);
        if ($debug) {
            $delivered = ($delivery_stats['cpdlc'] ?? 0) + ($delivery_stats['vpilot'] ?? 0)
                       + ($delivery_stats['web'] ?? 0) + ($delivery_stats['discord'] ?? 0);
            if ($delivered > 0 || !empty($delivery_stats['skipped'])) {
                cdm_log("  Delivery: " . json_encode($delivery_stats));
            }
        }
    } catch (Throwable $e) {
        cdm_log("  Delivery error: " . $e->getMessage(), 'ERROR');
    }

    // ====================================================================
    // Step 6: Evaluate triggers (every triggerInterval)
    // ====================================================================
    $timeSinceTrigger = time() - $lastTriggerTime;
    if ($timeSinceTrigger >= $triggerInterval) {
        try {
            $trigger_stats = cdm_step_triggers();
            if ($debug || $trigger_stats['fired'] > 0) {
                cdm_log("  Triggers: {$trigger_stats['evaluated']} evaluated, {$trigger_stats['fired']} fired");
            }
            $lastTriggerTime = time();
        } catch (Throwable $e) {
            cdm_log("  Trigger error: " . $e->getMessage(), 'ERROR');
        }
    }

    // ====================================================================
    // Step 7: Purge old data (every purgeInterval)
    // ====================================================================
    $timeSincePurge = time() - $lastPurgeTime;
    if ($timeSincePurge >= $purgeInterval) {
        try {
            cdm_log("  Running CDM data purge...");
            $purge_stats = cdm_step_purge();
            cdm_log("  Purge: " . ($purge_stats['success'] ? 'complete' : 'FAILED'));
            $lastPurgeTime = time();
        } catch (Throwable $e) {
            cdm_log("  Purge error: " . $e->getMessage(), 'ERROR');
        }
    }

    // ====================================================================
    // Cycle complete
    // ====================================================================
    $cycleDuration = microtime(true) - $cycleStart;
    cdm_write_heartbeat($heartbeatFile, 'idle', [
        'cycle'    => $cycleCount,
        'cycle_ms' => (int)round($cycleDuration * 1000),
    ]);

    if ($debug) {
        cdm_log("  Cycle #$cycleCount complete in " . round($cycleDuration * 1000) . "ms");
    }

    // Sleep until next cycle (1-second increments for graceful shutdown)
    if ($runLoop) {
        $sleepSeconds = max(1, (int)ceil($cycleInterval - $cycleDuration));
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

cdm_log("CDM Daemon exiting");
