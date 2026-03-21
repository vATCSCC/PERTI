<?php
/**
 * SWIM API Data Sync
 *
 * Syncs flight data from VATSIM_ADL to SWIM_API database.
 * Called after each ADL refresh cycle (~60 seconds).
 *
 * V4.0: Expanded to ~121 columns matching sp_Swim_BulkUpsert v2.0
 *       (migration 026). Adds position, plan, times, TMI, aircraft columns
 *       needed for full SWIM data isolation (flight.php parity).
 *       SP v2.0 uses row-hash skip to eliminate no-op updates.
 *
 * V3.0: Delta sync - only syncs flights that changed since last sync
 *       Reduces sync from 3000+ rows to ~300-500 rows per cycle
 *       Expected improvement: 115s -> 3-5s on Azure SQL Basic
 *
 * V2.0: Uses sp_Swim_BulkUpsert for batch operations instead of row-by-row.
 *
 * Azure SQL Basic doesn't support cross-database queries,
 * so we sync via PHP instead.
 *
 * @package PERTI
 * @subpackage SWIM
 * @version 4.0.0
 */

// Can be run standalone or included
if (!defined('PERTI_LOADED')) {
    define('PERTI_LOADED', true);
    require_once __DIR__ . '/../load/config.php';
    require_once __DIR__ . '/../load/connect.php';
}

/**
 * Main sync function - call after ADL refresh completes
 * V3: Delta sync - only syncs flights that changed since last sync
 *
 * @return array ['success' => bool, 'message' => string, 'stats' => array]
 */
function swim_sync_from_adl() {
    global $conn_adl, $conn_swim;

    $stats = [
        'start_time' => microtime(true),
        'flights_checked' => 0,
        'flights_changed' => 0,
        'inserted' => 0,
        'updated' => 0,
        'deleted' => 0,
        'skipped' => 0,
        'marked_inactive' => 0,
        'errors' => 0,
        'duration_ms' => 0,
        'mode' => 'delta'
    ];

    // Check connections
    if (!$conn_adl) {
        return ['success' => false, 'message' => 'VATSIM_ADL connection not available', 'stats' => $stats];
    }
    if (!$conn_swim) {
        return ['success' => false, 'message' => 'SWIM_API connection not available', 'stats' => $stats];
    }

    try {
        // Step 1: Get last successful sync time from SWIM_API
        $lastSync = swim_get_last_sync_time($conn_swim);

        // Step 2: Fetch only flights that changed since last sync from VATSIM_ADL
        $flights = fetch_adl_flights_delta($conn_adl, $lastSync);

        if ($flights === false) {
            return ['success' => false, 'message' => 'Failed to fetch delta from VATSIM_ADL', 'stats' => $stats];
        }

        $stats['flights_changed'] = count($flights);

        if (count($flights) === 0) {
            $stats['duration_ms'] = round((microtime(true) - $stats['start_time']) * 1000);
            return ['success' => true, 'message' => 'No changes to sync', 'stats' => $stats];
        }

        // Step 3: Encode as JSON for batch SP
        $json = json_encode($flights, JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            return ['success' => false, 'message' => 'JSON encoding failed: ' . json_last_error_msg(), 'stats' => $stats];
        }

        // Step 4: Call batch upsert SP on SWIM_API
        $result = swim_bulk_upsert($conn_swim, $json);

        if ($result === false) {
            // SP missing in this environment - use legacy path for compatibility
            return swim_sync_delta_legacy($flights, $stats);
        }

        if (isset($result['error'])) {
            $stats['duration_ms'] = round((microtime(true) - $stats['start_time']) * 1000);
            return [
                'success' => false,
                'message' => 'Bulk upsert failed (will retry next cycle): ' . $result['error'],
                'stats' => $stats
            ];
        }

        $stats['inserted'] = $result['inserted'] ?? 0;
        $stats['updated'] = $result['updated'] ?? 0;
        $stats['deleted'] = $result['deleted'] ?? 0;
        $stats['skipped'] = $result['skipped'] ?? 0;

        // Step 5: Mark stale flights as inactive (5 min threshold, matches ADL)
        // This is critical because delta sync only sees active flights from ADL
        $stats['marked_inactive'] = swim_mark_stale_flights_inactive($conn_swim);

        $stats['duration_ms'] = round((microtime(true) - $stats['start_time']) * 1000);

        return [
            'success' => true,
            'message' => sprintf(
                'Delta sync: %d changed, %d ins, %d upd, %d skip, %d inactive in %dms',
                $stats['flights_changed'], $stats['inserted'], $stats['updated'],
                $stats['skipped'], $stats['marked_inactive'], $stats['duration_ms']
            ),
            'stats' => $stats
        ];

    } catch (Throwable $e) {
        $stats['duration_ms'] = round((microtime(true) - $stats['start_time']) * 1000);
        error_log('SWIM delta sync error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Sync error: ' . $e->getMessage(), 'stats' => $stats];
    }
}

/**
 * Get last successful sync timestamp from SWIM_API
 *
 * @param resource $conn_swim SWIM_API connection
 * @return DateTime|null Last sync time or null for first sync
 */
function swim_get_last_sync_time($conn_swim) {
    $sql = "SELECT MAX(last_sync_utc) AS last_sync FROM dbo.swim_flights";
    $stmt = @sqlsrv_query($conn_swim, $sql);

    if ($stmt === false) {
        // Table might not exist yet - return 1 hour ago
        return (new DateTime('now', new DateTimeZone('UTC')))->modify('-1 hour');
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$row || !$row['last_sync']) {
        // First sync - return 1 hour ago to get recent flights
        return (new DateTime('now', new DateTimeZone('UTC')))->modify('-1 hour');
    }

    // Subtract 30 seconds for overlap safety (network latency, clock skew)
    $lastSync = $row['last_sync'];
    if ($lastSync instanceof DateTime) {
        return $lastSync->modify('-30 seconds');
    }

    return (new DateTime($lastSync, new DateTimeZone('UTC')))->modify('-30 seconds');
}

/**
 * Mark stale flights as inactive in SWIM_API
 * Uses 5-minute threshold to match ADL behavior (sp_Adl_RefreshFromVatsim)
 *
 * @param resource $conn_swim SWIM_API connection
 * @return int Number of flights marked inactive
 */
function swim_mark_stale_flights_inactive($conn_swim) {
    $sql = "
        UPDATE dbo.swim_flights
        SET is_active = 0
        WHERE is_active = 1
          AND last_sync_utc < DATEADD(MINUTE, -5, GETUTCDATE())
    ";

    $result = @sqlsrv_query($conn_swim, $sql);
    if ($result === false) {
        error_log('Failed to mark stale flights inactive: ' . print_r(sqlsrv_errors(), true));
        return 0;
    }

    $count = sqlsrv_rows_affected($result);
    sqlsrv_free_stmt($result);
    return $count;
}

/**
 * Fetch only flights that changed since last sync from VATSIM_ADL
 * Uses position_updated_utc, times_updated_utc, tmi_updated_utc as change indicators
 *
 * @param resource $conn_adl VATSIM_ADL sqlsrv connection
 * @param DateTime|null $lastSync Last sync timestamp
 * @return array|false Flight data array or false on error
 */
function fetch_adl_flights_delta($conn_adl, $lastSync) {
    // Format timestamp for SQL Server
    $syncTime = $lastSync instanceof DateTime
        ? $lastSync->format('Y-m-d H:i:s')
        : $lastSync;

    $sql = "
        SELECT
            -- Core identity (13)
            c.flight_uid, c.flight_key, c.callsign, c.cid, c.flight_id,
            c.phase, c.is_active,
            c.first_seen_utc, c.last_seen_utc, c.logon_time_utc,
            c.current_artcc, c.current_tracon, c.current_zone,
            c.current_zone_airport, c.current_sector_low, c.current_sector_high,
            c.weather_impact, c.weather_alert_ids,

            -- Position (17)
            pos.lat, pos.lon, pos.altitude_ft, pos.heading_deg, pos.groundspeed_kts,
            pos.vertical_rate_fpm, pos.dist_to_dest_nm, pos.dist_flown_nm, pos.pct_complete,
            pos.true_airspeed_kts, pos.mach AS mach_number,
            pos.altitude_assigned, pos.altitude_cleared, pos.track_deg,
            pos.qnh_in_hg, pos.qnh_mb,
            pos.route_dist_to_dest_nm, pos.route_pct_complete,
            pos.next_waypoint_name, pos.dist_to_next_waypoint_nm,

            -- Flight plan (27)
            fp.fp_dept_icao, fp.fp_dest_icao, fp.fp_alt_icao,
            fp.fp_altitude_ft, fp.fp_tas_kts, fp.fp_route, fp.fp_remarks, fp.fp_rule,
            fp.fp_dept_artcc, fp.fp_dest_artcc, fp.fp_dept_tracon, fp.fp_dest_tracon,
            fp.dfix, fp.dp_name, fp.afix, fp.star_name, fp.dep_runway, fp.arr_runway,
            fp.gcd_nm, fp.route_total_nm, fp.aircraft_type,
            fp.aircraft_equip AS equipment_qualifier, fp.approach AS approach_procedure,
            fp.fp_route_expanded, fp.fp_fuel_minutes, fp.dtrsn, fp.strsn,
            fp.waypoint_count, fp.parse_status, fp.simbrief_id AS simbrief_ofp_id,

            -- Times (24)
            t.eta_utc, t.eta_runway_utc, t.eta_source, t.eta_method, t.etd_utc,
            t.out_utc, t.off_utc, t.on_utc, t.in_utc, t.ete_minutes,
            t.ctd_utc, t.cta_utc, t.edct_utc,
            t.sta_utc, t.etd_runway_utc, t.etd_source,
            t.octd_utc, t.octa_utc, t.ate_minutes,
            t.eta_confidence, t.eta_wind_component_kts,

            -- TMI control (21)
            tmi.gs_held, tmi.gs_release_utc, tmi.ctl_type, tmi.ctl_prgm, tmi.ctl_element,
            tmi.is_exempt, tmi.exempt_reason, tmi.slot_time_utc, tmi.slot_status,
            tmi.program_id, tmi.slot_id, tmi.delay_minutes, tmi.delay_status,
            tmi.ctl_exempt, tmi.ctl_exempt_reason, tmi.aslot, tmi.delay_source,
            tmi.is_popup, tmi.popup_detected_utc, tmi.absolute_delay_min, tmi.schedule_variation_min,

            -- Aircraft (10)
            ac.aircraft_icao, ac.aircraft_faa, ac.weight_class, ac.wake_category,
            ac.engine_type, ac.airline_icao, ac.airline_name,
            ac.engine_count, ac.cruise_tas_kts, ac.ceiling_ft

        FROM dbo.adl_flight_core c
        LEFT JOIN dbo.adl_flight_position pos ON pos.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_tmi tmi ON tmi.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid
        WHERE c.is_active = 1
          AND (
              -- Position changed since last sync
              pos.position_updated_utc > ?
              -- Or times changed (ETA, OOOI)
              OR t.times_updated_utc > ?
              -- Or TMI changed
              OR tmi.tmi_updated_utc > ?
              -- Or new flight (first seen after last sync)
              OR c.first_seen_utc > ?
              -- Or flight plan changed (route re-parse, runway update)
              OR fp.fp_updated_utc > ?
              -- Or aircraft data changed
              OR ac.aircraft_updated_utc > ?
          )
    ";

    // Use sqlsrv (not PDO) - $conn_adl is a sqlsrv resource
    $params = [$syncTime, $syncTime, $syncTime, $syncTime, $syncTime, $syncTime];
    $stmt = @sqlsrv_query($conn_adl, $sql, $params);

    if ($stmt === false) {
        error_log('SWIM delta query failed: ' . print_r(sqlsrv_errors(), true));
        return false;
    }

    $flights = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Generate GUFI
        $row['gufi'] = swim_generate_gufi_sync(
            $row['callsign'],
            $row['fp_dept_icao'],
            $row['fp_dest_icao'],
            $row['first_seen_utc']
        );

        // Format datetime fields for JSON
        foreach ($row as $key => $value) {
            if ($value instanceof DateTime) {
                $row[$key] = $value->format('Y-m-d H:i:s');
            }
        }

        $flights[] = $row;
    }

    sqlsrv_free_stmt($stmt);
    return $flights;
}

/**
 * Legacy delta sync - row by row if SP doesn't exist
 */
function swim_sync_delta_legacy(array $flights, array $stats) {
    global $conn_swim;

    // Get existing flight_uids from SWIM_API
    $existing_uids = get_existing_swim_uids($conn_swim);

    // Sync each changed flight (upsert)
    foreach ($flights as $flight) {
        $result = upsert_swim_flight($conn_swim, $flight, $existing_uids);
        if ($result === 'inserted') {
            $stats['inserted']++;
        } elseif ($result === 'updated') {
            $stats['updated']++;
        } else {
            $stats['errors']++;
        }
    }

    $stats['duration_ms'] = round((microtime(true) - $stats['start_time']) * 1000);

    return [
        'success' => true,
        'message' => sprintf(
            'Delta sync (legacy): %d changed, %d ins, %d upd in %dms',
            $stats['flights_changed'], $stats['inserted'], $stats['updated'], $stats['duration_ms']
        ),
        'stats' => $stats
    ];
}

/**
 * Call the batch upsert stored procedure
 * 
 * @param resource $conn_swim SWIM_API connection
 * @param string $json JSON-encoded flight array
 * @return array|false Stats array on success, false if SP missing (legacy fallback)
 */
function swim_bulk_upsert($conn_swim, string $json) {
    $sql = "EXEC dbo.sp_Swim_BulkUpsert @Json = ?";
    
    $stmt = @sqlsrv_query($conn_swim, $sql, [&$json], ['QueryTimeout' => 120]);
    
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        // Check if it's a "SP not found" error - fall back to legacy
        $errorMsg = $errors[0]['message'] ?? '';
        if (strpos($errorMsg, 'Could not find stored procedure') !== false ||
            strpos($errorMsg, 'Invalid object name') !== false) {
            error_log('SWIM bulk upsert SP not found, falling back to legacy sync');
            return false;
        }
        error_log('SWIM bulk upsert failed (no legacy fallback): ' . json_encode($errors));
        return ['error' => ($errorMsg !== '' ? $errorMsg : 'Unknown SQL error')];
    }
    
    // Get result row
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    
    if (!$row) {
        return ['inserted' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0];
    }

    return [
        'inserted' => $row['inserted'] ?? 0,
        'updated' => $row['updated'] ?? 0,
        'deleted' => $row['deleted'] ?? 0,
        'skipped' => $row['skipped'] ?? 0,
        'total' => $row['total'] ?? 0,
        'sp_elapsed_ms' => $row['elapsed_ms'] ?? 0,
    ];
}

/**
 * Legacy row-by-row sync (fallback if SP not deployed yet)
 */
function swim_sync_from_adl_legacy(array $flights, array $stats) {
    global $conn_swim;
    
    // Get existing flight_uids from SWIM_API
    $existing_uids = get_existing_swim_uids($conn_swim);
    
    // Sync each flight (upsert)
    foreach ($flights as $flight) {
        $result = upsert_swim_flight($conn_swim, $flight, $existing_uids);
        if ($result === 'inserted') {
            $stats['inserted']++;
        } elseif ($result === 'updated') {
            $stats['updated']++;
        } else {
            $stats['errors']++;
        }
    }
    
    // Delete stale flights (inactive for >2 hours)
    $stats['deleted'] = delete_stale_flights($conn_swim);
    
    $stats['duration_ms'] = round((microtime(true) - $stats['start_time']) * 1000);
    
    return [
        'success' => true,
        'message' => sprintf(
            'Sync (legacy) completed: %d fetched, %d inserted, %d updated, %d deleted in %dms',
            $stats['flights_fetched'], $stats['inserted'], $stats['updated'], $stats['deleted'], $stats['duration_ms']
        ),
        'stats' => $stats
    ];
}

/**
 * Fetch all relevant flights from VATSIM_ADL normalized tables
 *
 * @param resource $conn_adl VATSIM_ADL sqlsrv connection
 * @return array|false Flight data array or false on error
 */
function fetch_adl_flights($conn_adl) {
    $sql = "
        SELECT
            -- Core identity (13)
            c.flight_uid, c.flight_key, c.callsign, c.cid, c.flight_id,
            c.phase, c.is_active,
            c.first_seen_utc, c.last_seen_utc, c.logon_time_utc,
            c.current_artcc, c.current_tracon, c.current_zone,
            c.current_zone_airport, c.current_sector_low, c.current_sector_high,
            c.weather_impact, c.weather_alert_ids,

            -- Position (17)
            pos.lat, pos.lon, pos.altitude_ft, pos.heading_deg, pos.groundspeed_kts,
            pos.vertical_rate_fpm, pos.dist_to_dest_nm, pos.dist_flown_nm, pos.pct_complete,
            pos.true_airspeed_kts, pos.mach AS mach_number,
            pos.altitude_assigned, pos.altitude_cleared, pos.track_deg,
            pos.qnh_in_hg, pos.qnh_mb,
            pos.route_dist_to_dest_nm, pos.route_pct_complete,
            pos.next_waypoint_name, pos.dist_to_next_waypoint_nm,

            -- Flight plan (27)
            fp.fp_dept_icao, fp.fp_dest_icao, fp.fp_alt_icao,
            fp.fp_altitude_ft, fp.fp_tas_kts, fp.fp_route, fp.fp_remarks, fp.fp_rule,
            fp.fp_dept_artcc, fp.fp_dest_artcc, fp.fp_dept_tracon, fp.fp_dest_tracon,
            fp.dfix, fp.dp_name, fp.afix, fp.star_name, fp.dep_runway, fp.arr_runway,
            fp.gcd_nm, fp.route_total_nm, fp.aircraft_type,
            fp.aircraft_equip AS equipment_qualifier, fp.approach AS approach_procedure,
            fp.fp_route_expanded, fp.fp_fuel_minutes, fp.dtrsn, fp.strsn,
            fp.waypoint_count, fp.parse_status, fp.simbrief_id AS simbrief_ofp_id,

            -- Times (24)
            t.eta_utc, t.eta_runway_utc, t.eta_source, t.eta_method, t.etd_utc,
            t.out_utc, t.off_utc, t.on_utc, t.in_utc, t.ete_minutes,
            t.ctd_utc, t.cta_utc, t.edct_utc,
            t.sta_utc, t.etd_runway_utc, t.etd_source,
            t.octd_utc, t.octa_utc, t.ate_minutes,
            t.eta_confidence, t.eta_wind_component_kts,

            -- TMI control (21)
            tmi.gs_held, tmi.gs_release_utc, tmi.ctl_type, tmi.ctl_prgm, tmi.ctl_element,
            tmi.is_exempt, tmi.exempt_reason, tmi.slot_time_utc, tmi.slot_status,
            tmi.program_id, tmi.slot_id, tmi.delay_minutes, tmi.delay_status,
            tmi.ctl_exempt, tmi.ctl_exempt_reason, tmi.aslot, tmi.delay_source,
            tmi.is_popup, tmi.popup_detected_utc, tmi.absolute_delay_min, tmi.schedule_variation_min,

            -- Aircraft (10)
            ac.aircraft_icao, ac.aircraft_faa, ac.weight_class, ac.wake_category,
            ac.engine_type, ac.airline_icao, ac.airline_name,
            ac.engine_count, ac.cruise_tas_kts, ac.ceiling_ft

        FROM dbo.adl_flight_core c
        LEFT JOIN dbo.adl_flight_position pos ON pos.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_tmi tmi ON tmi.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid
        WHERE c.is_active = 1
           OR c.last_seen_utc > DATEADD(HOUR, -2, GETUTCDATE())
    ";

    // Use sqlsrv (not PDO) - $conn_adl is a sqlsrv resource
    $stmt = @sqlsrv_query($conn_adl, $sql);

    if ($stmt === false) {
        error_log('SWIM sync - ADL query failed: ' . print_r(sqlsrv_errors(), true));
        return false;
    }

    $flights = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Generate GUFI
        $row['gufi'] = swim_generate_gufi_sync(
            $row['callsign'],
            $row['fp_dept_icao'],
            $row['fp_dest_icao'],
            $row['first_seen_utc']
        );

        // Format datetime fields for JSON
        foreach ($row as $key => $value) {
            if ($value instanceof DateTime) {
                $row[$key] = $value->format('Y-m-d H:i:s');
            }
        }

        $flights[] = $row;
    }

    sqlsrv_free_stmt($stmt);
    return $flights;
}

/**
 * Generate GUFI string
 */
function swim_generate_gufi_sync($callsign, $dept, $dest, $first_seen = null) {
    if ($first_seen instanceof DateTime) {
        $date = $first_seen->format('Ymd');
    } elseif ($first_seen) {
        $date = date('Ymd', strtotime($first_seen));
    } else {
        $date = gmdate('Ymd');
    }
    
    return sprintf('VAT-%s-%s-%s-%s',
        $date,
        strtoupper(trim($callsign ?? '')),
        strtoupper(trim($dept ?? 'XXXX')),
        strtoupper(trim($dest ?? 'XXXX'))
    );
}

/**
 * Get existing flight_uids from SWIM_API (legacy function)
 */
function get_existing_swim_uids($conn_swim) {
    $sql = "SELECT flight_uid FROM dbo.swim_flights";
    $stmt = sqlsrv_query($conn_swim, $sql);
    
    $uids = [];
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC)) {
            $uids[$row[0]] = true;
        }
        sqlsrv_free_stmt($stmt);
    }
    return $uids;
}

/**
 * Insert or update a flight in SWIM_API (legacy function)
 *
 * FIXM-only: Writes only FIXM-aligned columns (legacy columns removed after transition)
 */
function upsert_swim_flight($conn_swim, $flight, $existing_uids) {
    $uid = $flight['flight_uid'];
    $is_update = isset($existing_uids[$uid]);

    if ($is_update) {
        // UPDATE - FIXM columns only
        $sql = "
            UPDATE dbo.swim_flights SET
                flight_key = ?, gufi = ?, callsign = ?, cid = ?, flight_id = ?,
                lat = ?, lon = ?, altitude_ft = ?, heading_deg = ?, groundspeed_kts = ?, vertical_rate_fpm = ?,
                fp_dept_icao = ?, fp_dest_icao = ?, fp_alt_icao = ?, fp_altitude_ft = ?, fp_tas_kts = ?,
                fp_route = ?, fp_remarks = ?, fp_rule = ?,
                fp_dept_artcc = ?, fp_dest_artcc = ?, fp_dept_tracon = ?, fp_dest_tracon = ?,
                dfix = ?, dp_name = ?, afix = ?, star_name = ?, dep_runway = ?, arr_runway = ?,
                phase = ?, is_active = ?, dist_to_dest_nm = ?, dist_flown_nm = ?, pct_complete = ?,
                gcd_nm = ?, route_total_nm = ?, current_artcc = ?, current_tracon = ?, current_zone = ?,
                first_seen_utc = ?, last_seen_utc = ?, logon_time_utc = ?,
                -- FIXM-aligned time columns
                estimated_time_of_arrival = ?, estimated_runway_arrival_time = ?,
                eta_source = ?, eta_method = ?, estimated_off_block_time = ?,
                actual_off_block_time = ?, actual_time_of_departure = ?,
                actual_landing_time = ?, actual_in_block_time = ?, ete_minutes = ?,
                controlled_time_of_departure = ?, controlled_time_of_arrival = ?, edct_utc = ?,
                -- TMI columns
                gs_held = ?, gs_release_utc = ?, ctl_type = ?, ctl_prgm = ?, ctl_element = ?,
                is_exempt = ?, exempt_reason = ?, slot_time_utc = ?, slot_status = ?,
                program_id = ?, slot_id = ?, delay_minutes = ?, delay_status = ?,
                aircraft_type = ?, aircraft_icao = ?, aircraft_faa = ?, weight_class = ?,
                wake_category = ?, engine_type = ?, airline_icao = ?, airline_name = ?,
                last_sync_utc = GETUTCDATE()
            WHERE flight_uid = ?
        ";
        $params = swim_build_params($flight);
        $params[] = $uid;
    } else {
        // INSERT - FIXM columns only
        $sql = "
            INSERT INTO dbo.swim_flights (
                flight_uid, flight_key, gufi, callsign, cid, flight_id,
                lat, lon, altitude_ft, heading_deg, groundspeed_kts, vertical_rate_fpm,
                fp_dept_icao, fp_dest_icao, fp_alt_icao, fp_altitude_ft, fp_tas_kts,
                fp_route, fp_remarks, fp_rule,
                fp_dept_artcc, fp_dest_artcc, fp_dept_tracon, fp_dest_tracon,
                dfix, dp_name, afix, star_name, dep_runway, arr_runway,
                phase, is_active, dist_to_dest_nm, dist_flown_nm, pct_complete,
                gcd_nm, route_total_nm, current_artcc, current_tracon, current_zone,
                first_seen_utc, last_seen_utc, logon_time_utc,
                -- FIXM-aligned time columns
                estimated_time_of_arrival, estimated_runway_arrival_time,
                eta_source, eta_method, estimated_off_block_time,
                actual_off_block_time, actual_time_of_departure,
                actual_landing_time, actual_in_block_time, ete_minutes,
                controlled_time_of_departure, controlled_time_of_arrival, edct_utc,
                -- TMI columns
                gs_held, gs_release_utc, ctl_type, ctl_prgm, ctl_element,
                is_exempt, exempt_reason, slot_time_utc, slot_status,
                program_id, slot_id, delay_minutes, delay_status,
                aircraft_type, aircraft_icao, aircraft_faa, weight_class,
                wake_category, engine_type, airline_icao, airline_name,
                last_sync_utc
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?,
                ?, ?, ?,
                ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                GETUTCDATE()
            )
        ";
        $params = [$uid];
        $params = array_merge($params, swim_build_params($flight));
    }

    $result = sqlsrv_query($conn_swim, $sql, $params);

    if ($result === false) {
        error_log('SWIM sync - upsert failed for flight_uid ' . $uid . ': ' . print_r(sqlsrv_errors(), true));
        return 'error';
    }

    sqlsrv_free_stmt($result);
    return $is_update ? 'updated' : 'inserted';
}

/**
 * Build parameter array for upsert
 *
 * FIXM-only: Maps ADL legacy column values to SWIM FIXM column names
 */
function swim_build_params($f) {
    return [
        // Core identity
        $f['flight_key'],
        $f['gufi'],
        $f['callsign'],
        $f['cid'],
        $f['flight_id'],
        // Position
        $f['lat'],
        $f['lon'],
        $f['altitude_ft'],
        $f['heading_deg'],
        $f['groundspeed_kts'],
        $f['vertical_rate_fpm'],
        // Flight plan
        $f['fp_dept_icao'],
        $f['fp_dest_icao'],
        $f['fp_alt_icao'],
        $f['fp_altitude_ft'],
        $f['fp_tas_kts'],
        $f['fp_route'],
        $f['fp_remarks'],
        $f['fp_rule'],
        $f['fp_dept_artcc'],
        $f['fp_dest_artcc'],
        $f['fp_dept_tracon'],
        $f['fp_dest_tracon'],
        $f['dfix'],
        $f['dp_name'],
        $f['afix'],
        $f['star_name'],
        $f['dep_runway'],
        $f['arr_runway'],
        // Status
        $f['phase'],
        $f['is_active'],
        $f['dist_to_dest_nm'],
        $f['dist_flown_nm'],
        $f['pct_complete'],
        $f['gcd_nm'],
        $f['route_total_nm'],
        $f['current_artcc'],
        $f['current_tracon'],
        $f['current_zone'],
        // Timestamps
        swim_format_datetime($f['first_seen_utc']),
        swim_format_datetime($f['last_seen_utc']),
        swim_format_datetime($f['logon_time_utc']),
        // ===== FIXM-ALIGNED TIME COLUMNS (mapped from ADL legacy names) =====
        swim_format_datetime($f['eta_utc']),          // -> estimated_time_of_arrival
        swim_format_datetime($f['eta_runway_utc']),   // -> estimated_runway_arrival_time
        $f['eta_source'],
        $f['eta_method'],
        swim_format_datetime($f['etd_utc']),          // -> estimated_off_block_time
        swim_format_datetime($f['out_utc']),          // -> actual_off_block_time
        swim_format_datetime($f['off_utc']),          // -> actual_time_of_departure
        swim_format_datetime($f['on_utc']),           // -> actual_landing_time
        swim_format_datetime($f['in_utc']),           // -> actual_in_block_time
        $f['ete_minutes'],
        swim_format_datetime($f['ctd_utc']),          // -> controlled_time_of_departure
        swim_format_datetime($f['cta_utc']),          // -> controlled_time_of_arrival
        swim_format_datetime($f['edct_utc']),
        // ===== TMI COLUMNS =====
        $f['gs_held'],
        swim_format_datetime($f['gs_release_utc']),
        $f['ctl_type'],
        $f['ctl_prgm'],
        $f['ctl_element'],
        $f['is_exempt'],
        $f['exempt_reason'],
        swim_format_datetime($f['slot_time_utc']),
        $f['slot_status'],
        $f['program_id'],
        $f['slot_id'],
        $f['delay_minutes'],
        $f['delay_status'],
        // Aircraft info
        $f['aircraft_type'],
        $f['aircraft_icao'],
        $f['aircraft_faa'],
        $f['weight_class'],
        $f['wake_category'],
        $f['engine_type'],
        $f['airline_icao'],
        $f['airline_name']
    ];
}

/**
 * Format datetime for SQL Server (legacy function)
 */
function swim_format_datetime($dt) {
    if ($dt === null) return null;
    if ($dt instanceof DateTime) {
        return $dt->format('Y-m-d H:i:s');
    }
    return $dt;
}

/**
 * Delete stale flights from SWIM_API (legacy function)
 */
function delete_stale_flights($conn_swim) {
    $sql = "
        DELETE FROM dbo.swim_flights
        WHERE is_active = 0 
          AND last_sync_utc < DATEADD(HOUR, -2, GETUTCDATE())
    ";
    
    $result = sqlsrv_query($conn_swim, $sql);
    if ($result === false) {
        return 0;
    }
    
    $deleted = sqlsrv_rows_affected($result);
    sqlsrv_free_stmt($result);
    
    return $deleted;
}

/**
 * Sync CTP-specific data to SWIM.
 * Three independent sub-steps, each in its own try/catch:
 *   1. Per-flight sync (resolved_nat_track → swim_flights)
 *   2. Metrics recompute (ctp_flight_control → swim_nat_track_metrics)
 *   3. Throughput utilization recompute (configs → swim_nat_track_throughput)
 *
 * @return array ['success' => bool, 'message' => string, 'skipped' => bool]
 */
function swim_sync_ctp_to_swim(): array {
    $conn_tmi = get_conn_tmi();
    $conn_swim = get_conn_swim();

    if (!$conn_tmi) {
        return ['success' => false, 'message' => 'TMI connection unavailable', 'skipped' => false];
    }
    if (!$conn_swim) {
        return ['success' => false, 'message' => 'SWIM connection unavailable', 'skipped' => false];
    }

    // Pre-check: any active CTP sessions?
    $sess_result = sqlsrv_query($conn_tmi,
        "SELECT session_id FROM dbo.ctp_sessions WHERE status IN ('ACTIVE', 'MONITORING')");
    if ($sess_result === false) {
        return ['success' => false, 'message' => 'Failed to check CTP sessions', 'skipped' => false];
    }
    $active_sessions = [];
    while ($row = sqlsrv_fetch_array($sess_result, SQLSRV_FETCH_ASSOC)) {
        $active_sessions[] = (int)$row['session_id'];
    }
    sqlsrv_free_stmt($sess_result);

    if (empty($active_sessions)) {
        return ['success' => true, 'message' => 'No active CTP sessions', 'skipped' => true];
    }

    $stats = ['flights_synced' => 0, 'metrics_bins' => 0, 'throughput_bins' => 0, 'errors' => 0];

    // Sub-step 1: Per-flight sync (resolved_nat_track → swim_flights)
    try {
        $stats['flights_synced'] = swim_sync_ctp_flights($conn_tmi, $conn_swim, $active_sessions);
    } catch (Throwable $e) {
        $stats['errors']++;
        swim_log("CTP flight sync error: " . $e->getMessage(), 'ERROR');
    }

    // Sub-step 2: Metrics recompute (only if flight sync had no errors)
    if ($stats['errors'] === 0) {
        try {
            $stats['metrics_bins'] = swim_sync_ctp_metrics($conn_tmi, $conn_swim, $active_sessions);
        } catch (Throwable $e) {
            $stats['errors']++;
            swim_log("CTP metrics sync error: " . $e->getMessage(), 'ERROR');
        }
    }

    // Sub-step 3: Throughput utilization recompute
    try {
        $stats['throughput_bins'] = swim_sync_ctp_throughput($conn_tmi, $conn_swim, $active_sessions);
    } catch (Throwable $e) {
        $stats['errors']++;
        swim_log("CTP throughput sync error: " . $e->getMessage(), 'ERROR');
    }

    // Update sync state watermarks
    try {
        sqlsrv_query($conn_swim,
            "UPDATE dbo.swim_sync_state SET last_sync_utc = SYSUTCDATETIME(), last_row_count = ? WHERE table_name = 'ctp_nat_track_metrics'",
            [$stats['metrics_bins']]);
        sqlsrv_query($conn_swim,
            "UPDATE dbo.swim_sync_state SET last_sync_utc = SYSUTCDATETIME(), last_row_count = ? WHERE table_name = 'ctp_nat_track_throughput'",
            [$stats['throughput_bins']]);
    } catch (Throwable $e) {
        // Non-fatal
    }

    $msg = sprintf("flights=%d metrics=%d throughput=%d errors=%d",
        $stats['flights_synced'], $stats['metrics_bins'], $stats['throughput_bins'], $stats['errors']);

    return ['success' => $stats['errors'] === 0, 'message' => $msg, 'skipped' => false];
}

/**
 * Sub-step 1: Sync per-flight NAT track data from TMI to SWIM.
 * Finds flights where swim_push_version > swim_pushed_version (delta detection).
 */
function swim_sync_ctp_flights($conn_tmi, $conn_swim, array $session_ids): int {
    $placeholders = implode(',', array_fill(0, count($session_ids), '?'));
    $sql = "SELECT ctp_control_id, flight_uid, resolved_nat_track, nat_track_resolved_at, nat_track_source
            FROM dbo.ctp_flight_control
            WHERE session_id IN ($placeholders)
              AND resolved_nat_track IS NOT NULL
              AND swim_push_version > ISNULL(swim_pushed_version, 0)";
    $stmt = sqlsrv_query($conn_tmi, $sql, $session_ids);
    if ($stmt === false) return 0;

    $synced = 0;
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        if (empty($row['flight_uid'])) continue;

        $upd = sqlsrv_query($conn_swim,
            "UPDATE dbo.swim_flights SET resolved_nat_track = ?, nat_track_resolved_at = ?, nat_track_source = ? WHERE flight_uid = ?",
            [$row['resolved_nat_track'], $row['nat_track_resolved_at'], $row['nat_track_source'], $row['flight_uid']]);
        if ($upd !== false) {
            sqlsrv_free_stmt($upd);
            // Mark as pushed
            sqlsrv_query($conn_tmi,
                "UPDATE dbo.ctp_flight_control SET swim_pushed_version = swim_push_version WHERE ctp_control_id = ?",
                [$row['ctp_control_id']]);
            $synced++;
        }
    }
    sqlsrv_free_stmt($stmt);
    return $synced;
}

/**
 * Sub-step 2: Recompute NAT track metrics bins in SWIM.
 * Aggregates ctp_flight_control by track + 15-min bin → MERGE into swim_nat_track_metrics.
 */
function swim_sync_ctp_metrics($conn_tmi, $conn_swim, array $session_ids): int {
    $total_bins = 0;

    foreach ($session_ids as $session_id) {
        // Aggregate per-track per-bin from TMI
        $agg_sql = "SELECT
                        resolved_nat_track AS track_name,
                        DATEADD(MINUTE, (DATEDIFF(MINUTE, 0, oceanic_entry_utc) / 15) * 15, 0) AS bin_start,
                        DATEADD(MINUTE, (DATEDIFF(MINUTE, 0, oceanic_entry_utc) / 15) * 15 + 15, 0) AS bin_end,
                        COUNT(*) AS flight_count,
                        SUM(CASE WHEN edct_utc IS NOT NULL THEN 1 ELSE 0 END) AS slotted_count,
                        AVG(CAST(delay_minutes AS FLOAT)) AS avg_delay_min
                    FROM dbo.ctp_flight_control
                    WHERE session_id = ?
                      AND resolved_nat_track IS NOT NULL
                      AND oceanic_entry_utc IS NOT NULL
                    GROUP BY resolved_nat_track,
                             DATEADD(MINUTE, (DATEDIFF(MINUTE, 0, oceanic_entry_utc) / 15) * 15, 0),
                             DATEADD(MINUTE, (DATEDIFF(MINUTE, 0, oceanic_entry_utc) / 15) * 15 + 15, 0)";

        $stmt = sqlsrv_query($conn_tmi, $agg_sql, [$session_id]);
        if ($stmt === false) continue;

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // MERGE into swim_nat_track_metrics
            $merge_sql = "MERGE dbo.swim_nat_track_metrics AS t
                          USING (SELECT ? AS session_id, ? AS track_name, ? AS bin_start, ? AS bin_end) AS s
                          ON t.session_id = s.session_id AND t.track_name = s.track_name AND t.bin_start_utc = s.bin_start
                          WHEN MATCHED THEN UPDATE SET
                              flight_count = ?, slotted_count = ?, avg_delay_min = ?,
                              peak_rate_hr = ? * 4, computed_at = SYSUTCDATETIME()
                          WHEN NOT MATCHED THEN INSERT
                              (session_id, track_name, bin_start_utc, bin_end_utc, flight_count, slotted_count, avg_delay_min, peak_rate_hr, source)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ? * 4, 'CTP');";

            $fc = (int)$row['flight_count'];
            $sc = (int)$row['slotted_count'];
            $ad = $row['avg_delay_min'];
            $bin_start = $row['bin_start'];
            $bin_end = $row['bin_end'];

            $params = [
                $session_id, $row['track_name'], $bin_start, $bin_end,
                $fc, $sc, $ad, $fc,
                $session_id, $row['track_name'], $bin_start, $bin_end, $fc, $sc, $ad, $fc
            ];

            $m = sqlsrv_query($conn_swim, $merge_sql, $params);
            if ($m !== false) {
                sqlsrv_free_stmt($m);
                $total_bins++;
            }
        }
        sqlsrv_free_stmt($stmt);
    }

    return $total_bins;
}

/**
 * Sub-step 3: Recompute throughput utilization bins in SWIM.
 * For each active config, aggregate matching flights per bin → MERGE into swim_nat_track_throughput.
 */
function swim_sync_ctp_throughput($conn_tmi, $conn_swim, array $session_ids): int {
    $total_bins = 0;

    foreach ($session_ids as $session_id) {
        // Fetch active configs
        $cfg_sql = "SELECT config_id, config_label, tracks_json, origins_json, destinations_json, max_acph
                    FROM dbo.ctp_track_throughput_config
                    WHERE session_id = ? AND is_active = 1";
        $cfg_stmt = sqlsrv_query($conn_tmi, $cfg_sql, [$session_id]);
        if ($cfg_stmt === false) continue;

        while ($cfg = sqlsrv_fetch_array($cfg_stmt, SQLSRV_FETCH_ASSOC)) {
            // Build WHERE conditions based on config filters (NULL = match all)
            $where = "session_id = ? AND oceanic_entry_utc IS NOT NULL";
            $params = [$session_id];

            $tracks = $cfg['tracks_json'] ? json_decode($cfg['tracks_json'], true) : null;
            if ($tracks && is_array($tracks) && !empty($tracks)) {
                $ph = implode(',', array_fill(0, count($tracks), '?'));
                $where .= " AND resolved_nat_track IN ($ph)";
                $params = array_merge($params, $tracks);
            }

            $origins = $cfg['origins_json'] ? json_decode($cfg['origins_json'], true) : null;
            if ($origins && is_array($origins) && !empty($origins)) {
                $ph = implode(',', array_fill(0, count($origins), '?'));
                $where .= " AND dep_airport IN ($ph)";
                $params = array_merge($params, $origins);
            }

            $dests = $cfg['destinations_json'] ? json_decode($cfg['destinations_json'], true) : null;
            if ($dests && is_array($dests) && !empty($dests)) {
                $ph = implode(',', array_fill(0, count($dests), '?'));
                $where .= " AND arr_airport IN ($ph)";
                $params = array_merge($params, $dests);
            }

            // Aggregate into 15-min bins
            $bin_sql = "SELECT
                            DATEADD(MINUTE, (DATEDIFF(MINUTE, 0, oceanic_entry_utc) / 15) * 15, 0) AS bin_start,
                            DATEADD(MINUTE, (DATEDIFF(MINUTE, 0, oceanic_entry_utc) / 15) * 15 + 15, 0) AS bin_end,
                            COUNT(*) AS actual_count
                        FROM dbo.ctp_flight_control
                        WHERE $where
                        GROUP BY
                            DATEADD(MINUTE, (DATEDIFF(MINUTE, 0, oceanic_entry_utc) / 15) * 15, 0),
                            DATEADD(MINUTE, (DATEDIFF(MINUTE, 0, oceanic_entry_utc) / 15) * 15 + 15, 0)";

            $bin_stmt = sqlsrv_query($conn_tmi, $bin_sql, $params);
            if ($bin_stmt === false) continue;

            while ($bin = sqlsrv_fetch_array($bin_stmt, SQLSRV_FETCH_ASSOC)) {
                $actual = (int)$bin['actual_count'];
                $rate_hr = $actual * 4; // 15-min bin → hourly rate
                $util_pct = $cfg['max_acph'] > 0 ? round(($rate_hr / $cfg['max_acph']) * 100, 1) : null;

                $merge_sql = "MERGE dbo.swim_nat_track_throughput AS t
                              USING (SELECT ? AS session_id, ? AS config_id, ? AS bin_start) AS s
                              ON t.session_id = s.session_id AND t.config_id = s.config_id AND t.bin_start_utc = s.bin_start
                              WHEN MATCHED THEN UPDATE SET
                                  actual_count = ?, actual_rate_hr = ?, utilization_pct = ?,
                                  computed_at = SYSUTCDATETIME()
                              WHEN NOT MATCHED THEN INSERT
                                  (session_id, config_id, config_label, tracks_json, origins_json, destinations_json,
                                   bin_start_utc, bin_end_utc, max_acph, actual_count, actual_rate_hr, utilization_pct)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);";

                $m_params = [
                    $session_id, (int)$cfg['config_id'], $bin['bin_start'],
                    $actual, $rate_hr, $util_pct,
                    $session_id, (int)$cfg['config_id'], $cfg['config_label'],
                    $cfg['tracks_json'], $cfg['origins_json'], $cfg['destinations_json'],
                    $bin['bin_start'], $bin['bin_end'], (int)$cfg['max_acph'],
                    $actual, $rate_hr, $util_pct
                ];

                $m = sqlsrv_query($conn_swim, $merge_sql, $m_params);
                if ($m !== false) {
                    sqlsrv_free_stmt($m);
                    $total_bins++;
                }
            }
            sqlsrv_free_stmt($bin_stmt);
        }
        sqlsrv_free_stmt($cfg_stmt);
    }

    return $total_bins;
}

// ============================================================================
// Run if executed directly (for testing)
// ============================================================================

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($argv[0] ?? '')) {
    echo "SWIM Sync V2 - Starting...\n";
    $result = swim_sync_from_adl();
    echo $result['message'] . "\n";
    if (!empty($result['stats'])) {
        echo "Stats: " . json_encode($result['stats']) . "\n";
    }
    exit($result['success'] ? 0 : 1);
}
