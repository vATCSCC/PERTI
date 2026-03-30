<?php
/**
 * TMI Delay Attribution Daemon
 *
 * Computes per-flight delay attribution by comparing EDCT/ETE baselines
 * against actual OOOI times. Reads from VATSIM_ADL, writes to VATSIM_TMI.
 *
 * Usage:
 *   php delay_attribution_daemon.php --loop [--interval=60] [--debug]
 *
 * @package PERTI
 * @subpackage TMI
 * @version 1.0.0
 * @date 2026-03-30
 */

$opts = getopt('', ['loop', 'interval:', 'debug', 'once']);
$loop_mode = isset($opts['loop']);
$interval = isset($opts['interval']) ? (int)$opts['interval'] : 60;
$debug = isset($opts['debug']);
$once = isset($opts['once']);

require_once(__DIR__ . '/../../load/config.php');
require_once(__DIR__ . '/../../load/connect.php');

function delay_log(string $msg, string $level = 'INFO'): void {
    $ts = gmdate('Y-m-d H:i:s');
    echo "[{$ts} UTC] [{$level}] {$msg}\n";
}

delay_log("Delay attribution daemon starting (interval={$interval}s, loop=" . ($loop_mode ? 'yes' : 'no') . ")");

// Load cause taxonomy for lookups
function load_cause_map($conn_tmi): array {
    $sql = "SELECT cause_id, cause_category, cause_subcategory FROM dbo.tmi_cause_taxonomy WHERE is_active = 1";
    $stmt = sqlsrv_query($conn_tmi, $sql);
    $map = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $key = $row['cause_category'] . '/' . $row['cause_subcategory'];
        $map[$key] = $row;
    }
    sqlsrv_free_stmt($stmt);
    return $map;
}

function run_attribution_cycle($conn_adl, $conn_tmi, bool $debug): int {
    $cause_map = load_cause_map($conn_tmi);
    $processed = 0;

    // Step 1: Find TMI-controlled flights with OOOI data that need attribution
    $adl_sql = "
        SELECT
            fc.flight_uid, fc.callsign, fc.program_id, fc.program_type,
            fc.program_delay_min, fc.ctl_element,
            ft.etd_utc, ft.edct_utc, ft.out_utc, ft.off_utc,
            ft.on_utc, ft.in_utc, ft.eta_utc, ft.ata_utc,
            ft.ete_minutes, ft.first_seen_utc,
            fcore.dep_icao, fcore.arr_icao, fcore.dep_artcc, fcore.arr_artcc,
            fa.icao_type, fa.carrier_code
        FROM dbo.adl_flight_tmi fc
        JOIN dbo.adl_flight_times ft ON fc.flight_uid = ft.flight_uid
        JOIN dbo.adl_flight_core fcore ON fc.flight_uid = fcore.flight_uid
        LEFT JOIN dbo.adl_flight_aircraft fa ON fc.flight_uid = fa.flight_uid
        WHERE fc.program_id IS NOT NULL
          AND fc.program_delay_min > 0
          AND ft.out_utc IS NOT NULL
          AND fcore.is_active = 1
    ";
    $adl_stmt = sqlsrv_query($conn_adl, $adl_sql);
    if ($adl_stmt === false) {
        delay_log('Failed to query ADL flights: ' . (sqlsrv_errors()[0]['message'] ?? 'unknown'), 'ERROR');
        return 0;
    }

    $flights = [];
    while ($row = sqlsrv_fetch_array($adl_stmt, SQLSRV_FETCH_ASSOC)) {
        $flights[] = $row;
    }
    sqlsrv_free_stmt($adl_stmt);

    if (empty($flights)) {
        if ($debug) delay_log('No flights needing attribution');
        return 0;
    }

    delay_log('Found ' . count($flights) . ' flights for attribution');

    // Step 2: Batch-fetch taxi references for relevant airports
    $airports = array_unique(array_filter(array_column($flights, 'dep_icao')));
    $taxi_refs = [];
    if (!empty($airports)) {
        $placeholders = implode(',', array_fill(0, count($airports), '?'));
        $taxi_sql = "SELECT airport_icao, unimpeded_taxi_sec FROM dbo.airport_taxi_reference WHERE airport_icao IN ({$placeholders})";
        $taxi_stmt = sqlsrv_query($conn_adl, $taxi_sql, array_values($airports));
        if ($taxi_stmt) {
            while ($row = sqlsrv_fetch_array($taxi_stmt, SQLSRV_FETCH_ASSOC)) {
                $taxi_refs[$row['airport_icao']] = (int)$row['unimpeded_taxi_sec'];
            }
            sqlsrv_free_stmt($taxi_stmt);
        }
    }

    // Step 3: Compute attribution for each flight
    $batch = [];
    foreach ($flights as $f) {
        $uid = $f['flight_uid'];

        // TMI_HOLD: EDCT minus original ETD
        if ($f['edct_utc'] && $f['etd_utc']) {
            $edct_ts = ($f['edct_utc'] instanceof DateTime) ? $f['edct_utc']->getTimestamp() : strtotime($f['edct_utc']);
            $etd_ts = ($f['etd_utc'] instanceof DateTime) ? $f['etd_utc']->getTimestamp() : strtotime($f['etd_utc']);
            $hold_min = round(($edct_ts - $etd_ts) / 60, 1);
            if ($hold_min > 0) {
                $cause_key = 'TMI/' . ($f['program_type'] ?? 'GDP');
                $cause = $cause_map[$cause_key] ?? $cause_map['OTHER/UNATTRIBUTED'];
                $batch[] = [
                    $uid, $f['callsign'], $f['dep_icao'], $f['arr_icao'],
                    'GATE', $hold_min,
                    $f['etd_utc'], $f['edct_utc'],
                    $cause['cause_id'], $cause['cause_category'], $cause['cause_subcategory'],
                    $f['program_id'], null, null,
                    $f['ctl_element'], null,
                    $f['arr_artcc'], $f['dep_artcc'],
                    $f['icao_type'], $f['carrier_code'],
                    'EDCT_DIFF', 'HIGH'
                ];
            }
        }

        // TAXI_EXCESS: actual taxi minus unimpeded reference
        if ($f['off_utc'] && $f['out_utc'] && isset($taxi_refs[$f['dep_icao']])) {
            $off_ts = ($f['off_utc'] instanceof DateTime) ? $f['off_utc']->getTimestamp() : strtotime($f['off_utc']);
            $out_ts = ($f['out_utc'] instanceof DateTime) ? $f['out_utc']->getTimestamp() : strtotime($f['out_utc']);
            $actual_taxi = $off_ts - $out_ts;
            $unimpeded = $taxi_refs[$f['dep_icao']];
            $excess_min = round(($actual_taxi - $unimpeded) / 60, 1);
            if ($excess_min > 1.0) {
                $cause = $cause_map['OTHER/UNATTRIBUTED'] ?? ['cause_id' => 22, 'cause_category' => 'OTHER', 'cause_subcategory' => 'UNATTRIBUTED'];
                $batch[] = [
                    $uid, $f['callsign'], $f['dep_icao'], $f['arr_icao'],
                    'TAXI_OUT', $excess_min,
                    null, null,
                    $cause['cause_id'], $cause['cause_category'], $cause['cause_subcategory'],
                    null, null, null,
                    $f['dep_icao'], null,
                    $f['arr_artcc'], $f['dep_artcc'],
                    $f['icao_type'], $f['carrier_code'],
                    'TAXI_REFERENCE', 'HIGH'
                ];
            }
        }

        $processed++;
    }

    // Step 4: Mark old attributions as superseded
    if (!empty($batch)) {
        $uids = array_map('intval', array_unique(array_column($batch, 0)));
        $uid_list = implode(',', $uids);
        $supersede_sql = "UPDATE dbo.tmi_delay_attribution SET is_current = 0 WHERE flight_uid IN ({$uid_list}) AND is_current = 1";
        $s_stmt = sqlsrv_query($conn_tmi, $supersede_sql);
        if ($s_stmt) sqlsrv_free_stmt($s_stmt);

        // Step 5: Batch insert new attributions
        foreach (array_chunk($batch, 100) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $row) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                $params = array_merge($params, $row);
            }
            $insert_sql = "INSERT INTO dbo.tmi_delay_attribution
                (flight_uid, callsign, dep_icao, arr_icao,
                 delay_phase, delay_minutes, baseline_utc, actual_utc,
                 cause_id, cause_category, cause_subcategory,
                 attributed_program_id, attributed_entry_id, attributed_log_id,
                 attributed_facility, attributed_org,
                 arr_facility, dep_facility, aircraft_type, carrier,
                 computation_method, confidence)
                VALUES " . implode(', ', $values);
            $i_stmt = sqlsrv_query($conn_tmi, $insert_sql, $params);
            if ($i_stmt === false) {
                delay_log('Insert failed: ' . (sqlsrv_errors()[0]['message'] ?? 'unknown'), 'ERROR');
            } else {
                sqlsrv_free_stmt($i_stmt);
            }
        }

        delay_log("Attributed " . count($batch) . " delay records for {$processed} flights");
    }

    return $processed;
}

// Main loop
do {
    try {
        $conn_adl = get_conn_adl();
        $conn_tmi = get_conn_tmi();
        $count = run_attribution_cycle($conn_adl, $conn_tmi, $debug);
        if ($debug) delay_log("Cycle complete: {$count} flights processed");
    } catch (Exception $e) {
        delay_log('Cycle error: ' . $e->getMessage(), 'ERROR');
    }

    if ($once) break;
    if ($loop_mode) sleep($interval);
} while ($loop_mode);

delay_log('Daemon exiting');
