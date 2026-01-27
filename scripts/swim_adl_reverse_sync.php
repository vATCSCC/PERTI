<?php
/**
 * SWIM to ADL Reverse Sync
 *
 * Propagates SimTraffic flight times from SWIM back to ADL normalized tables.
 * This enables the new architecture where SimTraffic times flow through VATSWIM first.
 *
 * Flow: SimTraffic API -> SWIM Ingest -> swim_flights -> THIS SCRIPT -> ADL tables
 *
 * Target ADL tables:
 *   - adl_flight_times: OOOI times, ETAs, controlled times
 *   - adl_flight_tmi: Metering delay, sequence, etc.
 *   - adl_flight_plan: Arrival runway, metering fix
 *
 * Features:
 *   - Delta sync: Only processes flights updated since last sync
 *   - Source tracking: Records SimTraffic as the source
 *   - Idempotent: Safe to run multiple times
 *
 * @package PERTI
 * @subpackage SWIM
 * @version 2.0.0
 * @since 2026-01-27
 * @updated 2026-01-27 - Added FIXM column dual-write support
 */

// Can be run standalone or included
if (!defined('PERTI_LOADED')) {
    define('PERTI_LOADED', true);
    require_once __DIR__ . '/../load/config.php';
    require_once __DIR__ . '/../load/connect.php';
}

// Sync state file for tracking last sync time
define('REVERSE_SYNC_STATE_FILE', sys_get_temp_dir() . '/perti_swim_adl_reverse_sync.json');

/**
 * Main reverse sync function
 *
 * @param bool $force Force full sync (ignore last sync time)
 * @return array ['success' => bool, 'message' => string, 'stats' => array]
 */
function swim_adl_reverse_sync($force = false) {
    global $conn_adl, $conn_swim;

    $stats = [
        'start_time' => microtime(true),
        'flights_checked' => 0,
        'flights_synced' => 0,
        'times_updated' => 0,
        'tmi_updated' => 0,
        'plan_updated' => 0,
        'not_found_in_adl' => 0,
        'errors' => 0,
        'duration_ms' => 0
    ];

    // Check connections
    if (!$conn_adl) {
        return ['success' => false, 'message' => 'VATSIM_ADL connection not available', 'stats' => $stats];
    }
    if (!$conn_swim) {
        return ['success' => false, 'message' => 'SWIM_API connection not available', 'stats' => $stats];
    }

    try {
        // Step 1: Get last reverse sync time
        $lastSync = $force ? null : get_last_reverse_sync_time();
        $lastSyncStr = $lastSync ? $lastSync->format('Y-m-d H:i:s') : '2000-01-01 00:00:00';

        // Step 2: Query SWIM for SimTraffic-updated flights
        // V3.1: Read from new FIXM columns with fallback to legacy OOOI columns
        $sql = "
            SELECT
                sf.flight_uid,
                sf.gufi,
                sf.callsign,
                sf.fp_dept_icao,
                sf.fp_dest_icao,
                -- Departure times (FIXM columns with legacy fallback)
                COALESCE(sf.actual_off_block_time, sf.out_utc) AS out_utc,
                COALESCE(sf.taxi_start_time, sf.taxi_time_utc) AS taxi_time_utc,
                COALESCE(sf.departure_sequence_time, sf.sequence_time_utc) AS sequence_time_utc,
                COALESCE(sf.hold_short_time, sf.holdshort_time_utc) AS holdshort_time_utc,
                COALESCE(sf.runway_entry_time, sf.runway_time_utc) AS runway_time_utc,
                COALESCE(sf.actual_time_of_departure, sf.off_utc) AS off_utc,
                sf.edct_utc,
                -- Arrival times (FIXM columns with legacy fallback)
                COALESCE(sf.estimated_time_of_arrival, sf.eta_utc) AS eta_utc,
                COALESCE(sf.estimated_runway_arrival_time, sf.eta_runway_utc) AS eta_runway_utc,
                COALESCE(sf.actual_landing_time, sf.on_utc) AS on_utc,
                sf.metering_time,
                sf.actual_metering_time,
                sf.eta_vertex,
                sf.actual_vertex_time,
                sf.sta_vertex,
                -- Metering fields
                sf.metering_point,
                sf.metering_delay,
                sf.metering_status,
                sf.sequence_number,
                sf.arr_runway,
                sf.arrival_stream,
                sf.metering_frozen,
                -- Status
                sf.phase,
                sf.current_artcc,
                sf.simtraffic_phase,
                sf.simtraffic_sync_utc,
                sf.metering_source
            FROM dbo.swim_flights sf
            WHERE sf.metering_source = 'simtraffic'
              AND sf.simtraffic_sync_utc > ?
              AND sf.is_active = 1
            ORDER BY sf.simtraffic_sync_utc ASC
        ";

        $stmt = sqlsrv_query($conn_swim, $sql, [$lastSyncStr]);
        if ($stmt === false) {
            $err = sqlsrv_errors();
            return ['success' => false, 'message' => 'SWIM query failed: ' . ($err[0]['message'] ?? 'Unknown'), 'stats' => $stats];
        }

        $flights = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $flights[] = $row;
        }
        sqlsrv_free_stmt($stmt);

        $stats['flights_checked'] = count($flights);

        if (count($flights) === 0) {
            $stats['duration_ms'] = round((microtime(true) - $stats['start_time']) * 1000);
            return ['success' => true, 'message' => 'No SimTraffic updates to sync', 'stats' => $stats];
        }

        // Step 3: Process each flight
        $latestSync = null;
        foreach ($flights as $swimFlight) {
            try {
                $result = sync_flight_to_adl($conn_adl, $swimFlight);

                if ($result['status'] === 'synced') {
                    $stats['flights_synced']++;
                    if ($result['times_updated']) $stats['times_updated']++;
                    if ($result['tmi_updated']) $stats['tmi_updated']++;
                    if ($result['plan_updated']) $stats['plan_updated']++;
                } elseif ($result['status'] === 'not_found') {
                    $stats['not_found_in_adl']++;
                }

                // Track latest sync time
                if ($swimFlight['simtraffic_sync_utc']) {
                    $syncTime = $swimFlight['simtraffic_sync_utc'];
                    if ($syncTime instanceof DateTime) {
                        if (!$latestSync || $syncTime > $latestSync) {
                            $latestSync = $syncTime;
                        }
                    }
                }

            } catch (Exception $e) {
                $stats['errors']++;
                error_log('Reverse sync error for ' . $swimFlight['callsign'] . ': ' . $e->getMessage());
            }
        }

        // Step 4: Update last sync time
        if ($latestSync) {
            save_last_reverse_sync_time($latestSync);
        }

        $stats['duration_ms'] = round((microtime(true) - $stats['start_time']) * 1000);

        return [
            'success' => true,
            'message' => sprintf(
                'Reverse sync: %d flights, %d times, %d tmi, %d plan updated in %dms',
                $stats['flights_synced'], $stats['times_updated'],
                $stats['tmi_updated'], $stats['plan_updated'], $stats['duration_ms']
            ),
            'stats' => $stats
        ];

    } catch (Exception $e) {
        $stats['duration_ms'] = round((microtime(true) - $stats['start_time']) * 1000);
        error_log('SWIM reverse sync error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Sync error: ' . $e->getMessage(), 'stats' => $stats];
    }
}

/**
 * Sync a single flight from SWIM to ADL
 *
 * @param resource $conn_adl ADL database connection
 * @param array $swimFlight Flight data from SWIM
 * @return array Result with status and details
 */
function sync_flight_to_adl($conn_adl, $swimFlight) {
    $callsign = $swimFlight['callsign'];
    $dest = $swimFlight['fp_dest_icao'];

    // Look up flight in ADL by callsign and destination
    $lookup_sql = "
        SELECT TOP 1 c.flight_uid
        FROM dbo.adl_flight_core c
        WHERE c.callsign = ?
          AND c.dest_icao = ?
          AND c.is_active = 1
        ORDER BY c.last_seen_utc DESC
    ";

    $lookup_stmt = sqlsrv_query($conn_adl, $lookup_sql, [$callsign, $dest]);
    if ($lookup_stmt === false) {
        throw new Exception('ADL lookup failed');
    }

    $adlFlight = sqlsrv_fetch_array($lookup_stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($lookup_stmt);

    if (!$adlFlight) {
        return ['status' => 'not_found', 'callsign' => $callsign];
    }

    $flight_uid = $adlFlight['flight_uid'];
    $times_updated = false;
    $tmi_updated = false;
    $plan_updated = false;

    // === Update adl_flight_times ===
    // DUAL-WRITE: Write to both legacy columns and FIXM-aligned columns
    // Legacy columns will be deprecated after 30-day transition period
    $times_updates = [];
    $times_params = [];

    // ─────────────────────────────────────────────────────────
    // DEPARTURE TIMES
    // ─────────────────────────────────────────────────────────

    // Pushback / Gate departure (AOBT)
    if ($swimFlight['out_utc']) {
        $formatted = format_datetime($swimFlight['out_utc']);
        // Legacy column
        $times_updates[] = 'atd_utc = ?';
        $times_params[] = $formatted;
        // FIXM column
        $times_updates[] = 'actual_off_block_time = ?';
        $times_params[] = $formatted;
    }

    // Taxi start time (SimTraffic-specific)
    if (!empty($swimFlight['taxi_time_utc'])) {
        $formatted = format_datetime($swimFlight['taxi_time_utc']);
        // FIXM column only (no legacy equivalent)
        $times_updates[] = 'taxi_start_time = ?';
        $times_params[] = $formatted;
    }

    // Departure sequence time (SimTraffic-specific)
    if (!empty($swimFlight['sequence_time_utc'])) {
        $formatted = format_datetime($swimFlight['sequence_time_utc']);
        // FIXM column only (no legacy equivalent)
        $times_updates[] = 'departure_sequence_time = ?';
        $times_params[] = $formatted;
    }

    // Hold short time (SimTraffic-specific)
    if (!empty($swimFlight['holdshort_time_utc'])) {
        $formatted = format_datetime($swimFlight['holdshort_time_utc']);
        // FIXM column only (no legacy equivalent)
        $times_updates[] = 'hold_short_time = ?';
        $times_params[] = $formatted;
    }

    // Runway entry time (SimTraffic-specific)
    if (!empty($swimFlight['runway_time_utc'])) {
        $formatted = format_datetime($swimFlight['runway_time_utc']);
        // FIXM column only (no legacy equivalent)
        $times_updates[] = 'runway_entry_time = ?';
        $times_params[] = $formatted;
    }

    // Takeoff / Wheels up (ATOT)
    if ($swimFlight['off_utc']) {
        $formatted = format_datetime($swimFlight['off_utc']);
        // Legacy column
        $times_updates[] = 'atd_runway_utc = ?';
        $times_params[] = $formatted;
        // FIXM column
        $times_updates[] = 'actual_time_of_departure = ?';
        $times_params[] = $formatted;
    }

    // EDCT / CTD
    if ($swimFlight['edct_utc']) {
        $formatted = format_datetime($swimFlight['edct_utc']);
        // Legacy columns
        $times_updates[] = 'edct_utc = ?';
        $times_params[] = $formatted;
        $times_updates[] = 'ctd_utc = ?';
        $times_params[] = $formatted;
        // FIXM column
        $times_updates[] = 'controlled_time_of_departure = ?';
        $times_params[] = $formatted;
    }

    // ─────────────────────────────────────────────────────────
    // ARRIVAL TIMES
    // ─────────────────────────────────────────────────────────

    // ETA at destination
    if ($swimFlight['eta_utc']) {
        $formatted = format_datetime($swimFlight['eta_utc']);
        // Legacy column
        $times_updates[] = 'eta_utc = ?';
        $times_params[] = $formatted;
        // FIXM column
        $times_updates[] = 'estimated_time_of_arrival = ?';
        $times_params[] = $formatted;
    }

    // ETA at runway threshold
    if ($swimFlight['eta_runway_utc']) {
        $formatted = format_datetime($swimFlight['eta_runway_utc']);
        // Legacy column
        $times_updates[] = 'eta_runway_utc = ?';
        $times_params[] = $formatted;
        // FIXM column
        $times_updates[] = 'estimated_runway_arrival_time = ?';
        $times_params[] = $formatted;
    }

    // Landing / Wheels down (ALDT)
    if ($swimFlight['on_utc']) {
        $formatted = format_datetime($swimFlight['on_utc']);
        // Legacy columns
        $times_updates[] = 'ata_utc = ?';
        $times_params[] = $formatted;
        $times_updates[] = 'ata_runway_utc = ?';
        $times_params[] = $formatted;
        // FIXM columns
        $times_updates[] = 'actual_landing_time = ?';
        $times_params[] = $formatted;
        $times_updates[] = 'actual_in_block_time = ?';
        $times_params[] = $formatted;
    }

    // ─────────────────────────────────────────────────────────
    // METERING TIMES
    // ─────────────────────────────────────────────────────────

    // STA at meter fix (scheduled time of arrival)
    if ($swimFlight['metering_time']) {
        $times_updates[] = 'sta_meterfix_utc = ?';
        $times_params[] = format_datetime($swimFlight['metering_time']);
    }

    // Actual time at meter fix
    if ($swimFlight['actual_metering_time']) {
        $formatted = format_datetime($swimFlight['actual_metering_time']);
        // Legacy column
        $times_updates[] = 'eta_meterfix_utc = ?';
        $times_params[] = $formatted;
        // FIXM column
        $times_updates[] = 'actual_metering_time = ?';
        $times_params[] = $formatted;
    }

    // ─────────────────────────────────────────────────────────
    // DELAY & SOURCE TRACKING
    // ─────────────────────────────────────────────────────────

    // Metering delay
    if ($swimFlight['metering_delay'] !== null) {
        $times_updates[] = 'delay_minutes = ?';
        $times_params[] = intval($swimFlight['metering_delay']);
    }

    // Source tracking
    $times_updates[] = 'eta_source = ?';
    $times_params[] = 'simtraffic';
    $times_updates[] = 'etd_source = ?';
    $times_params[] = 'simtraffic';
    $times_updates[] = 'times_updated_utc = SYSUTCDATETIME()';

    if (!empty($times_updates)) {
        $times_params[] = $flight_uid;
        $times_sql = "UPDATE dbo.adl_flight_times SET " . implode(', ', $times_updates) . " WHERE flight_uid = ?";

        $stmt = sqlsrv_query($conn_adl, $times_sql, $times_params);
        if ($stmt === false) {
            $err = sqlsrv_errors();
            throw new Exception('Times update failed: ' . ($err[0]['message'] ?? 'Unknown'));
        }
        $rows = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);
        $times_updated = ($rows > 0);
    }

    // === Update adl_flight_tmi (if table exists) ===
    $tmi_updates = [];
    $tmi_params = [];

    if ($swimFlight['metering_delay'] !== null) {
        $tmi_updates[] = 'delay_minutes = ?';
        $tmi_params[] = intval($swimFlight['metering_delay']);
    }

    if ($swimFlight['sequence_number'] !== null) {
        $tmi_updates[] = 'sequence = ?';
        $tmi_params[] = intval($swimFlight['sequence_number']);
    }

    if ($swimFlight['metering_status']) {
        $tmi_updates[] = 'metering_status = ?';
        $tmi_params[] = $swimFlight['metering_status'];
    }

    if ($swimFlight['arrival_stream']) {
        $tmi_updates[] = 'arrival_stream = ?';
        $tmi_params[] = $swimFlight['arrival_stream'];
    }

    if ($swimFlight['metering_frozen'] !== null) {
        $tmi_updates[] = 'metering_frozen = ?';
        $tmi_params[] = $swimFlight['metering_frozen'] ? 1 : 0;
    }

    $tmi_updates[] = 'tmi_source = ?';
    $tmi_params[] = 'simtraffic';
    $tmi_updates[] = 'tmi_updated_utc = SYSUTCDATETIME()';

    if (!empty($tmi_updates)) {
        // Check if TMI table exists and has the flight
        $tmi_check_sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'adl_flight_tmi'";
        $check_stmt = sqlsrv_query($conn_adl, $tmi_check_sql);
        $tmi_exists = sqlsrv_fetch_array($check_stmt);
        sqlsrv_free_stmt($check_stmt);

        if ($tmi_exists) {
            $tmi_params[] = $flight_uid;
            $tmi_sql = "UPDATE dbo.adl_flight_tmi SET " . implode(', ', $tmi_updates) . " WHERE flight_uid = ?";

            $stmt = @sqlsrv_query($conn_adl, $tmi_sql, $tmi_params);
            if ($stmt !== false) {
                $rows = sqlsrv_rows_affected($stmt);
                sqlsrv_free_stmt($stmt);
                $tmi_updated = ($rows > 0);
            }
        }
    }

    // === Update adl_flight_plan (if arr_runway or metering_point) ===
    $plan_updates = [];
    $plan_params = [];

    if ($swimFlight['arr_runway']) {
        $plan_updates[] = 'arr_runway = ?';
        $plan_params[] = $swimFlight['arr_runway'];
    }

    if ($swimFlight['metering_point']) {
        $plan_updates[] = 'afix = ?';
        $plan_params[] = $swimFlight['metering_point'];
    }

    if (!empty($plan_updates)) {
        $plan_params[] = $flight_uid;
        $plan_sql = "UPDATE dbo.adl_flight_plan SET " . implode(', ', $plan_updates) . " WHERE flight_uid = ?";

        $stmt = @sqlsrv_query($conn_adl, $plan_sql, $plan_params);
        if ($stmt !== false) {
            $rows = sqlsrv_rows_affected($stmt);
            sqlsrv_free_stmt($stmt);
            $plan_updated = ($rows > 0);
        }
    }

    // === Update phase in adl_flight_core ===
    if ($swimFlight['phase']) {
        $phase_sql = "UPDATE dbo.adl_flight_core SET phase = ? WHERE flight_uid = ?";
        $stmt = @sqlsrv_query($conn_adl, $phase_sql, [$swimFlight['phase'], $flight_uid]);
        if ($stmt !== false) {
            sqlsrv_free_stmt($stmt);
        }
    }

    return [
        'status' => 'synced',
        'callsign' => $callsign,
        'flight_uid' => $flight_uid,
        'times_updated' => $times_updated,
        'tmi_updated' => $tmi_updated,
        'plan_updated' => $plan_updated
    ];
}

/**
 * Format DateTime for SQL Server
 */
function format_datetime($value) {
    if ($value instanceof DateTime) {
        return $value->format('Y-m-d H:i:s');
    }
    return $value;
}

/**
 * Get last reverse sync time from state file
 */
function get_last_reverse_sync_time() {
    if (!file_exists(REVERSE_SYNC_STATE_FILE)) {
        return null;
    }

    $state = json_decode(file_get_contents(REVERSE_SYNC_STATE_FILE), true);
    if (!$state || empty($state['last_sync_utc'])) {
        return null;
    }

    return new DateTime($state['last_sync_utc'], new DateTimeZone('UTC'));
}

/**
 * Save last reverse sync time to state file
 */
function save_last_reverse_sync_time(DateTime $time) {
    $state = [
        'last_sync_utc' => $time->format('Y-m-d H:i:s'),
        'updated_at' => (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s')
    ];

    file_put_contents(REVERSE_SYNC_STATE_FILE, json_encode($state, JSON_PRETTY_PRINT));
}

// If run directly from command line
if (php_sapi_name() === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $force = in_array('--force', $argv);

    echo "SWIM -> ADL Reverse Sync\n";
    echo "========================\n";

    if ($force) {
        echo "Mode: Full sync (forced)\n";
    } else {
        echo "Mode: Delta sync\n";
    }

    $result = swim_adl_reverse_sync($force);

    echo "\nResult: " . ($result['success'] ? 'SUCCESS' : 'FAILED') . "\n";
    echo "Message: " . $result['message'] . "\n";
    echo "\nStats:\n";
    foreach ($result['stats'] as $key => $value) {
        echo "  $key: $value\n";
    }

    exit($result['success'] ? 0 : 1);
}
