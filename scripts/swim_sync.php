<?php
/**
 * SWIM API Data Sync
 *
 * Syncs flight data from VATSIM_ADL to SWIM_API database.
 * Called after each ADL refresh cycle (~60 seconds).
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
 * @version 3.0.0
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
            // Fall back to row-by-row if SP doesn't exist
            return swim_sync_delta_legacy($flights, $stats);
        }

        $stats['inserted'] = $result['inserted'] ?? 0;
        $stats['updated'] = $result['updated'] ?? 0;
        $stats['deleted'] = $result['deleted'] ?? 0;
        $stats['duration_ms'] = round((microtime(true) - $stats['start_time']) * 1000);

        return [
            'success' => true,
            'message' => sprintf(
                'Delta sync: %d changed, %d ins, %d upd in %dms',
                $stats['flights_changed'], $stats['inserted'], $stats['updated'], $stats['duration_ms']
            ),
            'stats' => $stats
        ];

    } catch (Exception $e) {
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
 * Fetch only flights that changed since last sync from VATSIM_ADL
 * Uses position_updated_utc, times_updated_utc, tmi_updated_utc as change indicators
 *
 * @param resource $conn_adl VATSIM_ADL connection
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
            c.flight_uid, c.flight_key, c.callsign, c.cid, c.flight_id,
            c.phase, c.is_active,
            c.first_seen_utc, c.last_seen_utc, c.logon_time_utc,
            c.current_artcc, c.current_tracon, c.current_zone,

            pos.lat, pos.lon, pos.altitude_ft, pos.heading_deg, pos.groundspeed_kts,
            pos.vertical_rate_fpm, pos.dist_to_dest_nm, pos.dist_flown_nm, pos.pct_complete,

            fp.fp_dept_icao, fp.fp_dest_icao, fp.fp_alt_icao,
            fp.fp_altitude_ft, fp.fp_tas_kts, fp.fp_route, fp.fp_remarks, fp.fp_rule,
            fp.fp_dept_artcc, fp.fp_dest_artcc, fp.fp_dept_tracon, fp.fp_dest_tracon,
            fp.dfix, fp.dp_name, fp.afix, fp.star_name, fp.dep_runway, fp.arr_runway,
            fp.gcd_nm, fp.route_total_nm, fp.aircraft_type,

            t.eta_utc, t.eta_runway_utc, t.eta_source, t.eta_method, t.etd_utc,
            t.out_utc, t.off_utc, t.on_utc, t.in_utc, t.ete_minutes,
            t.ctd_utc, t.cta_utc, t.edct_utc,

            tmi.gs_held, tmi.gs_release_utc, tmi.ctl_type, tmi.ctl_prgm, tmi.ctl_element,
            tmi.is_exempt, tmi.exempt_reason, tmi.slot_time_utc, tmi.slot_status,
            tmi.program_id, tmi.slot_id, tmi.delay_minutes, tmi.delay_status,

            ac.aircraft_icao, ac.aircraft_faa, ac.weight_class, ac.wake_category,
            ac.engine_type, ac.airline_icao, ac.airline_name

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
          )
    ";

    $params = [$syncTime, $syncTime, $syncTime, $syncTime];
    $stmt = sqlsrv_query($conn_adl, $sql, $params);

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
 * @return array|false Stats array on success, false if SP doesn't exist
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
        error_log('SWIM bulk upsert failed: ' . json_encode($errors));
        return false;
    }
    
    // Get result row
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    
    if (!$row) {
        return ['inserted' => 0, 'updated' => 0, 'deleted' => 0];
    }
    
    return [
        'inserted' => $row['inserted'] ?? 0,
        'updated' => $row['updated'] ?? 0,
        'deleted' => $row['deleted'] ?? 0,
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
 */
function fetch_adl_flights($conn_adl) {
    $sql = "
        SELECT 
            c.flight_uid, c.flight_key, c.callsign, c.cid, c.flight_id,
            c.phase, c.is_active,
            c.first_seen_utc, c.last_seen_utc, c.logon_time_utc,
            c.current_artcc, c.current_tracon, c.current_zone,
            
            pos.lat, pos.lon, pos.altitude_ft, pos.heading_deg, pos.groundspeed_kts,
            pos.vertical_rate_fpm, pos.dist_to_dest_nm, pos.dist_flown_nm, pos.pct_complete,
            
            fp.fp_dept_icao, fp.fp_dest_icao, fp.fp_alt_icao,
            fp.fp_altitude_ft, fp.fp_tas_kts, fp.fp_route, fp.fp_remarks, fp.fp_rule,
            fp.fp_dept_artcc, fp.fp_dest_artcc, fp.fp_dept_tracon, fp.fp_dest_tracon,
            fp.dfix, fp.dp_name, fp.afix, fp.star_name, fp.dep_runway, fp.arr_runway,
            fp.gcd_nm, fp.route_total_nm, fp.aircraft_type,
            
            t.eta_utc, t.eta_runway_utc, t.eta_source, t.eta_method, t.etd_utc,
            t.out_utc, t.off_utc, t.on_utc, t.in_utc, t.ete_minutes,
            t.ctd_utc, t.cta_utc, t.edct_utc,
            
            tmi.gs_held, tmi.gs_release_utc, tmi.ctl_type, tmi.ctl_prgm, tmi.ctl_element,
            tmi.is_exempt, tmi.exempt_reason, tmi.slot_time_utc, tmi.slot_status,
            tmi.program_id, tmi.slot_id, tmi.delay_minutes, tmi.delay_status,
            
            ac.aircraft_icao, ac.aircraft_faa, ac.weight_class, ac.wake_category,
            ac.engine_type, ac.airline_icao, ac.airline_name
            
        FROM dbo.adl_flight_core c
        LEFT JOIN dbo.adl_flight_position pos ON pos.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_tmi tmi ON tmi.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid
        WHERE c.is_active = 1 
           OR c.last_seen_utc > DATEADD(HOUR, -2, GETUTCDATE())
    ";
    
    $stmt = sqlsrv_query($conn_adl, $sql);
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
 */
function upsert_swim_flight($conn_swim, $flight, $existing_uids) {
    $uid = $flight['flight_uid'];
    $is_update = isset($existing_uids[$uid]);
    
    if ($is_update) {
        // UPDATE
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
                eta_utc = ?, eta_runway_utc = ?, eta_source = ?, eta_method = ?, etd_utc = ?,
                out_utc = ?, off_utc = ?, on_utc = ?, in_utc = ?, ete_minutes = ?,
                ctd_utc = ?, cta_utc = ?, edct_utc = ?,
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
        // INSERT
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
                eta_utc, eta_runway_utc, eta_source, eta_method, etd_utc,
                out_utc, off_utc, on_utc, in_utc, ete_minutes,
                ctd_utc, cta_utc, edct_utc,
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
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
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
 * Build parameter array for upsert (legacy function)
 */
function swim_build_params($f) {
    return [
        $f['flight_key'],
        $f['gufi'],
        $f['callsign'],
        $f['cid'],
        $f['flight_id'],
        $f['lat'],
        $f['lon'],
        $f['altitude_ft'],
        $f['heading_deg'],
        $f['groundspeed_kts'],
        $f['vertical_rate_fpm'],
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
        swim_format_datetime($f['first_seen_utc']),
        swim_format_datetime($f['last_seen_utc']),
        swim_format_datetime($f['logon_time_utc']),
        swim_format_datetime($f['eta_utc']),
        swim_format_datetime($f['eta_runway_utc']),
        $f['eta_source'],
        $f['eta_method'],
        swim_format_datetime($f['etd_utc']),
        swim_format_datetime($f['out_utc']),
        swim_format_datetime($f['off_utc']),
        swim_format_datetime($f['on_utc']),
        swim_format_datetime($f['in_utc']),
        $f['ete_minutes'],
        swim_format_datetime($f['ctd_utc']),
        swim_format_datetime($f['cta_utc']),
        swim_format_datetime($f['edct_utc']),
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
