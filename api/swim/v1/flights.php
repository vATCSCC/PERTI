<?php
/**
 * VATSIM SWIM API v1 - Flights Endpoint
 * 
 * Returns flight data from the denormalized swim_flights table in SWIM_API database.
 * Data is synced from VATSIM_ADL normalized tables every 15 seconds.
 * 
 * @version 3.0.0 - SWIM_API Database (Azure SQL Basic)
 */

require_once __DIR__ . '/auth.php';

// Get database connection - prefer SWIM_API, fall back to VATSIM_ADL during migration
global $conn_swim, $conn_adl;
$conn = $conn_swim ?: $conn_adl;
$use_swim_db = ($conn_swim !== null);

if (!$conn) {
    SwimResponse::error('Database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

$auth = swim_init_auth(true, false);

// Get filter parameters
$status = swim_get_param('status', 'active');
$dept_icao = swim_get_param('dept_icao');
$dest_icao = swim_get_param('dest_icao');
$artcc = swim_get_param('artcc');
$callsign = swim_get_param('callsign');
$tmi_controlled = swim_get_param('tmi_controlled');
$phase = swim_get_param('phase');

$page = swim_get_int_param('page', 1, 1, 1000);
$per_page = swim_get_int_param('per_page', SWIM_DEFAULT_PAGE_SIZE, 1, SWIM_MAX_PAGE_SIZE);
$offset = ($page - 1) * $per_page;

// Build query - different table aliases based on database
$where_clauses = [];
$params = [];

if ($use_swim_db) {
    // SWIM_API: Simple single-table queries against swim_flights
    $table_alias = 'f';
    $table_name = 'dbo.swim_flights';
    
    if ($status === 'active') {
        $where_clauses[] = "f.is_active = 1";
    } elseif ($status === 'completed') {
        $where_clauses[] = "f.is_active = 0";
    }
    
    if ($dept_icao) {
        $dept_list = array_map('trim', explode(',', strtoupper($dept_icao)));
        $placeholders = implode(',', array_fill(0, count($dept_list), '?'));
        $where_clauses[] = "f.fp_dept_icao IN ($placeholders)";
        $params = array_merge($params, $dept_list);
    }
    
    if ($dest_icao) {
        $dest_list = array_map('trim', explode(',', strtoupper($dest_icao)));
        $placeholders = implode(',', array_fill(0, count($dest_list), '?'));
        $where_clauses[] = "f.fp_dest_icao IN ($placeholders)";
        $params = array_merge($params, $dest_list);
    }
    
    if ($artcc) {
        $artcc_list = array_map('trim', explode(',', strtoupper($artcc)));
        $placeholders = implode(',', array_fill(0, count($artcc_list), '?'));
        $where_clauses[] = "f.fp_dest_artcc IN ($placeholders)";
        $params = array_merge($params, $artcc_list);
    }
    
    if ($callsign) {
        $callsign_pattern = strtoupper(str_replace('*', '%', $callsign));
        $where_clauses[] = "f.callsign LIKE ?";
        $params[] = $callsign_pattern;
    }
    
    if ($tmi_controlled === 'true' || $tmi_controlled === '1') {
        $where_clauses[] = "(f.gs_held = 1 OR f.ctl_type IS NOT NULL)";
    }
    
    if ($phase) {
        $phase_list = array_map('trim', explode(',', strtoupper($phase)));
        $placeholders = implode(',', array_fill(0, count($phase_list), '?'));
        $where_clauses[] = "f.phase IN ($placeholders)";
        $params = array_merge($params, $phase_list);
    }
    
    $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
    
    // Count total
    $count_sql = "SELECT COUNT(*) as total FROM $table_name f $where_sql";
    
    // Main query - single table, no JOINs needed
    $sql = "
        SELECT 
            f.flight_uid, f.flight_key, f.gufi, f.callsign, f.cid, f.flight_id,
            f.lat, f.lon, f.altitude_ft, f.heading_deg, f.groundspeed_kts, f.vertical_rate_fpm,
            f.fp_dept_icao, f.fp_dest_icao, f.fp_alt_icao, f.fp_altitude_ft, f.fp_tas_kts,
            f.fp_route, f.fp_remarks, f.fp_rule,
            f.fp_dept_artcc, f.fp_dest_artcc, f.fp_dept_tracon, f.fp_dest_tracon,
            f.dfix, f.dp_name, f.afix, f.star_name, f.dep_runway, f.arr_runway,
            f.phase, f.is_active, f.dist_to_dest_nm, f.dist_flown_nm, f.pct_complete,
            f.gcd_nm, f.route_total_nm, f.current_artcc, f.current_tracon, f.current_zone,
            f.first_seen_utc, f.last_seen_utc, f.logon_time_utc,
            f.eta_utc, f.eta_runway_utc, f.eta_source, f.eta_method, f.etd_utc,
            f.out_utc, f.off_utc, f.on_utc, f.in_utc, f.ete_minutes,
            f.ctd_utc, f.cta_utc, f.edct_utc,
            f.gs_held, f.gs_release_utc, f.ctl_type, f.ctl_prgm, f.ctl_element,
            f.is_exempt, f.exempt_reason, f.slot_time_utc, f.slot_status,
            f.program_id, f.slot_id, f.delay_minutes, f.delay_status,
            f.aircraft_type, f.aircraft_icao, f.aircraft_faa, f.weight_class,
            f.wake_category, f.engine_type, f.airline_icao, f.airline_name,
            f.last_sync_utc
        FROM $table_name f
        $where_sql
        ORDER BY f.callsign
        OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
    ";
    
} else {
    // VATSIM_ADL fallback: JOIN across normalized tables (legacy mode during migration)
    if ($status === 'active') {
        $where_clauses[] = "c.is_active = 1";
    } elseif ($status === 'completed') {
        $where_clauses[] = "c.is_active = 0";
    }
    
    if ($dept_icao) {
        $dept_list = array_map('trim', explode(',', strtoupper($dept_icao)));
        $placeholders = implode(',', array_fill(0, count($dept_list), '?'));
        $where_clauses[] = "fp.fp_dept_icao IN ($placeholders)";
        $params = array_merge($params, $dept_list);
    }
    
    if ($dest_icao) {
        $dest_list = array_map('trim', explode(',', strtoupper($dest_icao)));
        $placeholders = implode(',', array_fill(0, count($dest_list), '?'));
        $where_clauses[] = "fp.fp_dest_icao IN ($placeholders)";
        $params = array_merge($params, $dest_list);
    }
    
    if ($artcc) {
        $artcc_list = array_map('trim', explode(',', strtoupper($artcc)));
        $placeholders = implode(',', array_fill(0, count($artcc_list), '?'));
        $where_clauses[] = "fp.fp_dest_artcc IN ($placeholders)";
        $params = array_merge($params, $artcc_list);
    }
    
    if ($callsign) {
        $callsign_pattern = strtoupper(str_replace('*', '%', $callsign));
        $where_clauses[] = "c.callsign LIKE ?";
        $params[] = $callsign_pattern;
    }
    
    if ($tmi_controlled === 'true' || $tmi_controlled === '1') {
        $where_clauses[] = "(tmi.gs_held = 1 OR tmi.ctl_type IS NOT NULL)";
    }
    
    if ($phase) {
        $phase_list = array_map('trim', explode(',', strtoupper($phase)));
        $placeholders = implode(',', array_fill(0, count($phase_list), '?'));
        $where_clauses[] = "c.phase IN ($placeholders)";
        $params = array_merge($params, $phase_list);
    }
    
    $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
    
    // Count total
    $count_sql = "
        SELECT COUNT(*) as total 
        FROM dbo.adl_flight_core c
        LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_tmi tmi ON tmi.flight_uid = c.flight_uid
        $where_sql
    ";
    
    // Main query using normalized ADL tables (legacy)
    $sql = "
        SELECT 
            c.flight_uid, c.flight_key, c.callsign, c.cid, c.flight_id,
            c.phase, c.is_active,
            c.first_seen_utc, c.last_seen_utc, c.logon_time_utc,
            c.current_artcc, c.current_tracon, c.current_zone,
            pos.lat, pos.lon, pos.altitude_ft, pos.heading_deg, pos.groundspeed_kts,
            pos.true_airspeed_kts, pos.vertical_rate_fpm,
            pos.dist_to_dest_nm, pos.dist_flown_nm, pos.pct_complete,
            fp.fp_dept_icao, fp.fp_dest_icao, fp.fp_alt_icao,
            fp.fp_altitude_ft, fp.fp_tas_kts, fp.fp_route, fp.fp_remarks, fp.fp_rule,
            fp.fp_dept_time_z, fp.fp_enroute_minutes,
            fp.fp_dept_artcc, fp.fp_dest_artcc, fp.fp_dept_tracon, fp.fp_dest_tracon,
            fp.dfix, fp.dp_name, fp.afix, fp.star_name,
            fp.gcd_nm, fp.aircraft_type, fp.route_total_nm,
            fp.arr_runway, fp.dep_runway,
            t.eta_runway_utc, t.eta_utc, t.etd_runway_utc, t.etd_utc,
            t.out_utc, t.off_utc, t.on_utc, t.in_utc,
            t.ete_minutes, t.ctd_utc, t.cta_utc, t.edct_utc,
            t.eta_source, t.eta_method,
            tmi.ctl_type, tmi.ctl_prgm, tmi.ctl_element,
            tmi.is_exempt, tmi.exempt_reason,
            tmi.slot_time_utc, tmi.delay_minutes, tmi.delay_status,
            tmi.gs_held, tmi.gs_release_utc,
            tmi.program_id, tmi.slot_id,
            ac.aircraft_icao, ac.aircraft_faa, ac.weight_class, ac.wake_category,
            ac.engine_type, ac.airline_icao, ac.airline_name
        FROM dbo.adl_flight_core c
        LEFT JOIN dbo.adl_flight_position pos ON pos.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_tmi tmi ON tmi.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid
        $where_sql
        ORDER BY c.callsign
        OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
    ";
}

// Execute count query
$count_stmt = sqlsrv_query($conn, $count_sql, $params);
if ($count_stmt === false) {
    $errors = sqlsrv_errors();
    SwimResponse::error('Database error (count): ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}
$total = sqlsrv_fetch_array($count_stmt, SQLSRV_FETCH_ASSOC)['total'];
sqlsrv_free_stmt($count_stmt);

// Execute main query
$params[] = $offset;
$params[] = $per_page;

$stmt = sqlsrv_query($conn, $sql, $params);
if ($stmt === false) {
    $errors = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}

$flights = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $flights[] = formatFlightRecord($row, $use_swim_db);
}
sqlsrv_free_stmt($stmt);

SwimResponse::paginated($flights, $total, $page, $per_page);

function formatFlightRecord($row, $use_swim_db = false) {
    // Use pre-computed GUFI from swim_flights if available
    $gufi = $row['gufi'] ?? swim_generate_gufi($row['callsign'], $row['fp_dept_icao'], $row['fp_dest_icao']);
    
    // Calculate time to destination
    $time_to_dest = null;
    if ($row['groundspeed_kts'] > 50 && $row['dist_to_dest_nm'] > 0) {
        $time_to_dest = round(($row['dist_to_dest_nm'] / $row['groundspeed_kts']) * 60, 1);
    } elseif ($row['ete_minutes']) {
        $time_to_dest = $row['ete_minutes'];
    }
    
    $result = [
        'gufi' => $gufi,
        'flight_uid' => $row['flight_uid'],
        'flight_key' => $row['flight_key'],
        'identity' => [
            'callsign' => $row['callsign'],
            'cid' => $row['cid'],
            'aircraft_type' => $row['aircraft_type'],
            'aircraft_icao' => $row['aircraft_icao'],
            'aircraft_faa' => $row['aircraft_faa'],
            'weight_class' => $row['weight_class'],
            'wake_category' => $row['wake_category'],
            'airline_icao' => $row['airline_icao'],
            'airline_name' => $row['airline_name']
        ],
        'flight_plan' => [
            'departure' => trim($row['fp_dept_icao'] ?? ''),
            'destination' => trim($row['fp_dest_icao'] ?? ''),
            'alternate' => trim($row['fp_alt_icao'] ?? ''),
            'cruise_altitude' => $row['fp_altitude_ft'],
            'cruise_speed' => $row['fp_tas_kts'],
            'route' => $row['fp_route'],
            'remarks' => $row['fp_remarks'],
            'flight_rules' => $row['fp_rule'],
            'departure_artcc' => $row['fp_dept_artcc'],
            'destination_artcc' => $row['fp_dest_artcc'],
            'departure_tracon' => $row['fp_dept_tracon'],
            'destination_tracon' => $row['fp_dest_tracon'],
            'departure_fix' => $row['dfix'],
            'departure_procedure' => $row['dp_name'],
            'arrival_fix' => $row['afix'],
            'arrival_procedure' => $row['star_name'],
            'departure_runway' => $row['dep_runway'],
            'arrival_runway' => $row['arr_runway']
        ],
        'position' => [
            'latitude' => $row['lat'] !== null ? floatval($row['lat']) : null,
            'longitude' => $row['lon'] !== null ? floatval($row['lon']) : null,
            'altitude_ft' => $row['altitude_ft'],
            'heading' => $row['heading_deg'],
            'ground_speed_kts' => $row['groundspeed_kts'],
            'true_airspeed_kts' => $row['true_airspeed_kts'] ?? null,
            'vertical_rate_fpm' => $row['vertical_rate_fpm'],
            'current_artcc' => $row['current_artcc'],
            'current_tracon' => $row['current_tracon'],
            'current_zone' => $row['current_zone']
        ],
        'progress' => [
            'phase' => $row['phase'],
            'is_active' => (bool)$row['is_active'],
            'distance_remaining_nm' => $row['dist_to_dest_nm'] !== null ? floatval($row['dist_to_dest_nm']) : null,
            'distance_flown_nm' => $row['dist_flown_nm'] !== null ? floatval($row['dist_flown_nm']) : null,
            'gcd_nm' => $row['gcd_nm'] !== null ? floatval($row['gcd_nm']) : null,
            'route_total_nm' => $row['route_total_nm'] !== null ? floatval($row['route_total_nm']) : null,
            'pct_complete' => $row['pct_complete'] !== null ? floatval($row['pct_complete']) : null,
            'time_to_dest_min' => $time_to_dest
        ],
        'times' => [
            'etd' => formatDT($row['etd_utc']),
            'etd_runway' => formatDT($row['etd_runway_utc'] ?? null),
            'eta' => formatDT($row['eta_utc']),
            'eta_runway' => formatDT($row['eta_runway_utc']),
            'eta_source' => $row['eta_source'],
            'eta_method' => $row['eta_method'],
            'ete_minutes' => $row['ete_minutes'],
            'out' => formatDT($row['out_utc']),
            'off' => formatDT($row['off_utc']),
            'on' => formatDT($row['on_utc']),
            'in' => formatDT($row['in_utc']),
            'ctd' => formatDT($row['ctd_utc']),
            'cta' => formatDT($row['cta_utc']),
            'edct' => formatDT($row['edct_utc'])
        ],
        'tmi' => [
            'is_controlled' => ($row['gs_held'] == 1 || $row['ctl_type'] !== null),
            'ground_stop_held' => $row['gs_held'] == 1,
            'gs_release' => formatDT($row['gs_release_utc']),
            'control_type' => $row['ctl_type'],
            'control_program' => $row['ctl_prgm'],
            'control_element' => $row['ctl_element'],
            'is_exempt' => (bool)$row['is_exempt'],
            'exempt_reason' => $row['exempt_reason'],
            'delay_minutes' => $row['delay_minutes'],
            'delay_status' => $row['delay_status'],
            'slot_time' => formatDT($row['slot_time_utc']),
            'program_id' => $row['program_id'],
            'slot_id' => $row['slot_id']
        ],
        '_source' => 'vatcscc',
        '_first_seen' => formatDT($row['first_seen_utc']),
        '_last_seen' => formatDT($row['last_seen_utc']),
        '_logon_time' => formatDT($row['logon_time_utc'])
    ];
    
    // Add sync metadata if using SWIM_API database
    if ($use_swim_db && isset($row['last_sync_utc'])) {
        $result['_last_sync'] = formatDT($row['last_sync_utc']);
    }
    
    return $result;
}

function formatDT($dt) {
    if ($dt === null) return null;
    return ($dt instanceof DateTime) ? $dt->format('c') : $dt;
}
