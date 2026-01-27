<?php
/**
 * VATSWIM API v1 - Single Flight Endpoint
 * 
 * Returns a single flight record by GUFI, flight_uid, or flight_key.
 * Uses JOINs across normalized ADL tables.
 * 
 * Supports `?format=fixm` parameter for FIXM 4.3.0 aligned field names.
 * 
 * VERIFIED against normalized schema: 2026-01-15
 * 
 * GET /api/swim/v1/flight?gufi=VAT-20260115-UAL123-KJFK-KLAX
 * GET /api/swim/v1/flight?flight_uid=123456
 * GET /api/swim/v1/flight?flight_key=...
 * GET /api/swim/v1/flight?gufi=...&format=fixm
 * 
 * @version 2.1.0 - Added FIXM format support
 */

require_once __DIR__ . '/auth.php';

// Single flight lookups use VATSIM_ADL for full detail (minimal cost impact)
// The swim_flights table doesn't have all detailed columns
global $conn_swim, $conn_adl;
$conn = $conn_adl ?: $conn_swim;  // Prefer ADL for full detail

if (!$conn) {
    SwimResponse::error('Database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

$auth = swim_init_auth(true, false);

// Get format parameter - FIXM only after transition
// Legacy format redirects to FIXM for backward compatibility
$format = swim_get_param('format', 'fixm');
$format = 'fixm';  // FIXM is the only supported format

// Get identifier parameters
$gufi = swim_get_param('gufi');
$flight_uid = swim_get_param('flight_uid');
$flight_key = swim_get_param('flight_key');
$include_history = swim_get_param('include_history', 'false') === 'true';

// Validate - need at least one identifier
if (!$gufi && !$flight_uid && !$flight_key) {
    SwimResponse::error('Missing required parameter: gufi, flight_uid, or flight_key', 400, 'MISSING_PARAM');
}

// Build query based on identifier type
$where_clause = '';
$params = [];

if ($flight_uid) {
    $where_clause = 'c.flight_uid = ?';
    $params[] = intval($flight_uid);
} elseif ($flight_key) {
    $where_clause = 'c.flight_key = ?';
    $params[] = $flight_key;
} elseif ($gufi) {
    $gufi_parts = swim_parse_gufi($gufi);
    
    if (!$gufi_parts) {
        SwimResponse::error('Invalid GUFI format. Expected: VAT-YYYYMMDD-CALLSIGN-DEPT-DEST', 400, 'INVALID_GUFI');
    }
    
    $where_clause = 'c.callsign = ? AND fp.fp_dept_icao = ? AND fp.fp_dest_icao = ?';
    $params[] = $gufi_parts['callsign'];
    $params[] = $gufi_parts['dept'];
    $params[] = $gufi_parts['dest'];
    
    if (!$include_history) {
        $gufi_date = $gufi_parts['date'];
        if (strlen($gufi_date) === 8) {
            $year = substr($gufi_date, 0, 4);
            $month = substr($gufi_date, 4, 2);
            $day = substr($gufi_date, 6, 2);
            $date_str = "$year-$month-$day";
            $where_clause .= ' AND CAST(c.first_seen_utc AS DATE) = ?';
            $params[] = $date_str;
        }
    }
}

if (!$include_history && !$flight_uid) {
    $where_clause .= ' AND c.is_active = 1';
}

// Main query - VERIFIED column names against schema
$sql = "
    SELECT TOP 1
        -- adl_flight_core
        c.flight_uid, c.flight_key, c.callsign, c.cid, c.flight_id,
        c.phase, c.is_active,
        c.first_seen_utc, c.last_seen_utc, c.logon_time_utc,
        c.current_artcc, c.current_tracon, c.current_zone, c.current_zone_airport,
        c.current_sector_low, c.current_sector_high,
        c.weather_impact, c.weather_alert_ids,
        
        -- adl_flight_position
        pos.lat, pos.lon, pos.altitude_ft, pos.heading_deg, pos.groundspeed_kts,
        pos.altitude_assigned, pos.altitude_cleared,
        pos.true_airspeed_kts, pos.mach, pos.vertical_rate_fpm, pos.track_deg,
        pos.qnh_in_hg, pos.qnh_mb,
        pos.dist_to_dest_nm, pos.dist_flown_nm, pos.pct_complete,
        pos.route_dist_to_dest_nm, pos.route_pct_complete,
        pos.next_waypoint_name, pos.dist_to_next_waypoint_nm,
        
        -- adl_flight_plan
        fp.fp_dept_icao, fp.fp_dest_icao, fp.fp_alt_icao,
        fp.fp_altitude_ft, fp.fp_tas_kts, fp.fp_route, fp.fp_route_expanded, fp.fp_remarks, fp.fp_rule,
        fp.fp_dept_time_z, fp.fp_enroute_minutes, fp.fp_fuel_minutes,
        fp.fp_dept_artcc, fp.fp_dest_artcc, fp.fp_dept_tracon, fp.fp_dest_tracon,
        fp.dfix, fp.dp_name, fp.dtrsn, fp.afix, fp.star_name, fp.strsn,
        fp.approach, fp.arr_runway, fp.dep_runway,
        fp.gcd_nm, fp.route_total_nm, fp.aircraft_type, fp.aircraft_equip,
        fp.waypoint_count, fp.parse_status,
        fp.is_simbrief, fp.simbrief_id,
        
        -- adl_flight_times
        t.std_utc, t.sta_utc,
        t.etd_utc, t.etd_runway_utc, t.etd_source,
        t.eta_utc, t.eta_runway_utc, t.eta_source, t.eta_method,
        t.atd_utc, t.atd_runway_utc, t.ata_utc, t.ata_runway_utc,
        t.ctd_utc, t.cta_utc, t.edct_utc, t.octd_utc, t.octa_utc,
        t.out_utc, t.off_utc, t.on_utc, t.in_utc,
        t.ete_minutes, t.ate_minutes, t.delay_minutes as time_delay_minutes,
        t.eta_confidence, t.eta_wind_component_kts,
        
        -- adl_flight_tmi
        tmi.ctl_type, tmi.ctl_prgm, tmi.ctl_element,
        tmi.ctl_exempt, tmi.ctl_exempt_reason,
        tmi.slot_time_utc, tmi.slot_status, tmi.aslot,
        tmi.delay_minutes, tmi.delay_status, tmi.delay_source,
        tmi.gs_held, tmi.gs_release_utc,
        tmi.is_exempt, tmi.exempt_reason,
        tmi.program_id, tmi.slot_id,
        tmi.is_popup, tmi.popup_detected_utc,
        tmi.absolute_delay_min, tmi.schedule_variation_min,
        
        -- adl_flight_aircraft
        ac.aircraft_icao, ac.aircraft_faa, ac.weight_class, ac.wake_category,
        ac.engine_type, ac.engine_count, ac.cruise_tas_kts, ac.ceiling_ft,
        ac.airline_icao, ac.airline_name
        
    FROM dbo.adl_flight_core c
    LEFT JOIN dbo.adl_flight_position pos ON pos.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_tmi tmi ON tmi.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid
    WHERE $where_clause
    ORDER BY c.last_seen_utc DESC
";

$stmt = sqlsrv_query($conn, $sql, $params);
if ($stmt === false) {
    $errors = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}

$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($stmt);

if (!$row) {
    SwimResponse::error('Flight not found', 404, 'NOT_FOUND');
}

// FIXM format only after transition
$flight = formatDetailedFlightRecordFIXM($row);

SwimResponse::success($flight, [
    'source' => 'vatcscc',
    'lookup_method' => $flight_uid ? 'flight_uid' : ($flight_key ? 'flight_key' : 'gufi'),
    'schema_version' => 'normalized_v2'
]);


function formatDetailedFlightRecord($row) {
    $gufi = swim_generate_gufi($row['callsign'], $row['fp_dept_icao'], $row['fp_dest_icao']);
    
    $time_to_dest = null;
    if ($row['groundspeed_kts'] > 50 && $row['dist_to_dest_nm'] > 0) {
        $time_to_dest = round(($row['dist_to_dest_nm'] / $row['groundspeed_kts']) * 60, 1);
    } elseif ($row['ete_minutes']) {
        $time_to_dest = $row['ete_minutes'];
    }
    
    return [
        'gufi' => $gufi,
        'flight_uid' => $row['flight_uid'],
        'flight_key' => $row['flight_key'],
        'flight_id' => $row['flight_id'],
        
        'identity' => [
            'callsign' => $row['callsign'],
            'cid' => $row['cid'],
            'aircraft_type' => $row['aircraft_type'],
            'aircraft_icao' => $row['aircraft_icao'],
            'aircraft_faa' => $row['aircraft_faa'],
            'equipment' => $row['aircraft_equip'],
            'weight_class' => $row['weight_class'],
            'wake_category' => $row['wake_category'],
            'engine_type' => $row['engine_type'],
            'engine_count' => $row['engine_count'],
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
            'route_expanded' => $row['fp_route_expanded'],
            'remarks' => $row['fp_remarks'],
            'flight_rules' => trim($row['fp_rule'] ?? ''),
            'departure_time' => $row['fp_dept_time_z'],
            'enroute_time' => $row['fp_enroute_minutes'],
            'fuel_time' => $row['fp_fuel_minutes'],
            'departure_artcc' => $row['fp_dept_artcc'],
            'destination_artcc' => $row['fp_dest_artcc'],
            'departure_tracon' => $row['fp_dept_tracon'],
            'destination_tracon' => $row['fp_dest_tracon'],
            'departure_fix' => $row['dfix'],
            'departure_procedure' => $row['dp_name'],
            'departure_transition' => $row['dtrsn'],
            'arrival_fix' => $row['afix'],
            'arrival_procedure' => $row['star_name'],
            'arrival_transition' => $row['strsn'],
            'approach' => $row['approach'],
            'departure_runway' => $row['dep_runway'],
            'arrival_runway' => $row['arr_runway'],
            'waypoint_count' => $row['waypoint_count'],
            'parse_status' => $row['parse_status']
        ],
        
        'simbrief' => [
            'is_simbrief' => (bool)$row['is_simbrief'],
            'ofp_id' => $row['simbrief_id']
        ],
        
        'position' => [
            'latitude' => $row['lat'] !== null ? floatval($row['lat']) : null,
            'longitude' => $row['lon'] !== null ? floatval($row['lon']) : null,
            'altitude_ft' => $row['altitude_ft'],
            'altitude_assigned' => $row['altitude_assigned'],
            'altitude_cleared' => $row['altitude_cleared'],
            'heading' => $row['heading_deg'],
            'track' => $row['track_deg'],
            'ground_speed_kts' => $row['groundspeed_kts'],
            'true_airspeed_kts' => $row['true_airspeed_kts'],
            'mach' => $row['mach'] !== null ? floatval($row['mach']) : null,
            'vertical_rate_fpm' => $row['vertical_rate_fpm'],
            'qnh_in_hg' => $row['qnh_in_hg'] !== null ? floatval($row['qnh_in_hg']) : null,
            'qnh_mb' => $row['qnh_mb']
        ],
        
        'airspace' => [
            'current_artcc' => $row['current_artcc'],
            'current_tracon' => $row['current_tracon'],
            'current_zone' => $row['current_zone'],
            'current_zone_airport' => $row['current_zone_airport'],
            'current_sector_low' => $row['current_sector_low'],
            'current_sector_high' => $row['current_sector_high'],
            'weather_impact' => $row['weather_impact'],
            'weather_alerts' => $row['weather_alert_ids']
        ],
        
        'progress' => [
            'phase' => $row['phase'],
            'is_active' => (bool)$row['is_active'],
            'gcd_nm' => $row['gcd_nm'] !== null ? floatval($row['gcd_nm']) : null,
            'route_total_nm' => $row['route_total_nm'] !== null ? floatval($row['route_total_nm']) : null,
            'distance_remaining_nm' => $row['dist_to_dest_nm'] !== null ? floatval($row['dist_to_dest_nm']) : null,
            'distance_flown_nm' => $row['dist_flown_nm'] !== null ? floatval($row['dist_flown_nm']) : null,
            'pct_complete' => $row['pct_complete'] !== null ? floatval($row['pct_complete']) : null,
            'route_dist_remaining_nm' => $row['route_dist_to_dest_nm'] !== null ? floatval($row['route_dist_to_dest_nm']) : null,
            'route_pct_complete' => $row['route_pct_complete'] !== null ? floatval($row['route_pct_complete']) : null,
            'time_to_dest_min' => $time_to_dest,
            'next_waypoint' => $row['next_waypoint_name'],
            'dist_to_next_waypoint_nm' => $row['dist_to_next_waypoint_nm'] !== null ? floatval($row['dist_to_next_waypoint_nm']) : null
        ],
        
        'times' => [
            'scheduled' => [
                'departure' => formatDT($row['std_utc']),
                'arrival' => formatDT($row['sta_utc'])
            ],
            'estimated' => [
                'departure' => formatDT($row['etd_utc']),
                'departure_runway' => formatDT($row['etd_runway_utc']),
                'departure_source' => $row['etd_source'],
                'arrival' => formatDT($row['eta_utc']),
                'arrival_runway' => formatDT($row['eta_runway_utc']),
                'arrival_source' => $row['eta_source'],
                'arrival_method' => $row['eta_method'],
                'confidence' => $row['eta_confidence'] !== null ? floatval($row['eta_confidence']) : null,
                'wind_component_kts' => $row['eta_wind_component_kts']
            ],
            'actual' => [
                'departure' => formatDT($row['atd_utc']),
                'departure_runway' => formatDT($row['atd_runway_utc']),
                'arrival' => formatDT($row['ata_utc']),
                'arrival_runway' => formatDT($row['ata_runway_utc'])
            ],
            'controlled' => [
                'departure' => formatDT($row['ctd_utc']),
                'arrival' => formatDT($row['cta_utc']),
                'edct' => formatDT($row['edct_utc']),
                'original_ctd' => formatDT($row['octd_utc']),
                'original_cta' => formatDT($row['octa_utc'])
            ],
            'oooi' => [
                'out' => formatDT($row['out_utc']),
                'off' => formatDT($row['off_utc']),
                'on' => formatDT($row['on_utc']),
                'in' => formatDT($row['in_utc'])
            ],
            'ete_minutes' => $row['ete_minutes'],
            'ate_minutes' => $row['ate_minutes']
        ],
        
        'tmi' => [
            'is_controlled' => ($row['gs_held'] == 1 || $row['ctl_type'] !== null),
            'control_type' => $row['ctl_type'],
            'control_program' => $row['ctl_prgm'],
            'control_element' => $row['ctl_element'],
            'is_exempt' => (bool)($row['is_exempt'] || $row['ctl_exempt']),
            'exempt_reason' => $row['exempt_reason'] ?? $row['ctl_exempt_reason'],
            'ground_stop' => [
                'held' => $row['gs_held'] == 1,
                'release_time' => formatDT($row['gs_release_utc'])
            ],
            'slot' => [
                'time' => formatDT($row['slot_time_utc']),
                'status' => $row['slot_status'],
                'aslot' => $row['aslot'],
                'program_id' => $row['program_id'],
                'slot_id' => $row['slot_id']
            ],
            'delay' => [
                'minutes' => $row['delay_minutes'],
                'status' => $row['delay_status'],
                'source' => $row['delay_source'],
                'absolute_min' => $row['absolute_delay_min'],
                'schedule_variation_min' => $row['schedule_variation_min']
            ],
            'popup' => [
                'is_popup' => (bool)$row['is_popup'],
                'detected_utc' => formatDT($row['popup_detected_utc'])
            ]
        ],
        
        '_source' => 'vatcscc',
        '_schema' => 'normalized_v2',
        '_first_seen' => formatDT($row['first_seen_utc']),
        '_last_seen' => formatDT($row['last_seen_utc']),
        '_logon_time' => formatDT($row['logon_time_utc'])
    ];
}

function formatDT($dt) {
    if ($dt === null) return null;
    return ($dt instanceof DateTime) ? $dt->format('c') : $dt;
}

/**
 * Format detailed flight record using FIXM 4.3.0 aligned field names
 */
function formatDetailedFlightRecordFIXM($row) {
    $gufi = swim_generate_gufi($row['callsign'], $row['fp_dept_icao'], $row['fp_dest_icao']);
    
    $time_to_dest = null;
    if ($row['groundspeed_kts'] > 50 && $row['dist_to_dest_nm'] > 0) {
        $time_to_dest = round(($row['dist_to_dest_nm'] / $row['groundspeed_kts']) * 60, 1);
    } elseif ($row['ete_minutes']) {
        $time_to_dest = $row['ete_minutes'];
    }
    
    return [
        'gufi' => $gufi,
        'flight_uid' => $row['flight_uid'],
        'flight_key' => $row['flight_key'],
        'flight_id' => $row['flight_id'],
        
        'identity' => [
            'aircraft_identification' => $row['callsign'],
            'pilot_cid' => $row['cid'],
            'aircraft_type' => $row['aircraft_type'],
            'aircraft_type_icao' => $row['aircraft_icao'],
            'aircraft_type_faa' => $row['aircraft_faa'],
            'equipment' => $row['aircraft_equip'],
            'weight_class' => $row['weight_class'],
            'wake_turbulence' => $row['wake_category'],
            'engine_type' => $row['engine_type'],
            'engine_count' => $row['engine_count'],
            'operator_icao' => $row['airline_icao'],
            'operator_name' => $row['airline_name']
        ],
        
        'flight_plan' => [
            'departure_aerodrome' => trim($row['fp_dept_icao'] ?? ''),
            'arrival_aerodrome' => trim($row['fp_dest_icao'] ?? ''),
            'alternate_aerodrome' => trim($row['fp_alt_icao'] ?? ''),
            'cruising_level' => $row['fp_altitude_ft'],
            'cruising_speed' => $row['fp_tas_kts'],
            'route_text' => $row['fp_route'],
            'route_expanded' => $row['fp_route_expanded'],
            'remarks' => $row['fp_remarks'],
            'flight_rules_category' => trim($row['fp_rule'] ?? ''),
            'departure_time' => $row['fp_dept_time_z'],
            'enroute_time' => $row['fp_enroute_minutes'],
            'fuel_time' => $row['fp_fuel_minutes'],
            'departure_airspace' => $row['fp_dept_artcc'],
            'arrival_airspace' => $row['fp_dest_artcc'],
            'departure_tracon' => $row['fp_dept_tracon'],
            'arrival_tracon' => $row['fp_dest_tracon'],
            'departure_point' => $row['dfix'],
            'sid' => $row['dp_name'],
            'departure_transition' => $row['dtrsn'],
            'arrival_point' => $row['afix'],
            'star' => $row['star_name'],
            'arrival_transition' => $row['strsn'],
            'approach' => $row['approach'],
            'departure_runway' => $row['dep_runway'],
            'arrival_runway' => $row['arr_runway'],
            'waypoint_count' => $row['waypoint_count'],
            'parse_status' => $row['parse_status']
        ],
        
        'simbrief' => [
            'is_simbrief' => (bool)$row['is_simbrief'],
            'ofp_id' => $row['simbrief_id']
        ],
        
        'position' => [
            'latitude' => $row['lat'] !== null ? floatval($row['lat']) : null,
            'longitude' => $row['lon'] !== null ? floatval($row['lon']) : null,
            'altitude' => $row['altitude_ft'],
            'altitude_assigned' => $row['altitude_assigned'],
            'altitude_cleared' => $row['altitude_cleared'],
            'track' => $row['heading_deg'],
            'track_true' => $row['track_deg'],
            'ground_speed' => $row['groundspeed_kts'],
            'true_airspeed' => $row['true_airspeed_kts'],
            'mach' => $row['mach'] !== null ? floatval($row['mach']) : null,
            'vertical_rate' => $row['vertical_rate_fpm'],
            'qnh_in_hg' => $row['qnh_in_hg'] !== null ? floatval($row['qnh_in_hg']) : null,
            'qnh_mb' => $row['qnh_mb']
        ],
        
        'airspace' => [
            'current_airspace' => $row['current_artcc'],
            'current_tracon' => $row['current_tracon'],
            'current_airport_zone' => $row['current_zone'],
            'current_zone_airport' => $row['current_zone_airport'],
            'current_sector_low' => $row['current_sector_low'],
            'current_sector_high' => $row['current_sector_high'],
            'weather_impact' => $row['weather_impact'],
            'weather_alerts' => $row['weather_alert_ids']
        ],
        
        'progress' => [
            'flight_status' => $row['phase'],
            'is_active' => (bool)$row['is_active'],
            'great_circle_distance' => $row['gcd_nm'] !== null ? floatval($row['gcd_nm']) : null,
            'total_flight_distance' => $row['route_total_nm'] !== null ? floatval($row['route_total_nm']) : null,
            'distance_to_destination' => $row['dist_to_dest_nm'] !== null ? floatval($row['dist_to_dest_nm']) : null,
            'distance_flown' => $row['dist_flown_nm'] !== null ? floatval($row['dist_flown_nm']) : null,
            'percent_complete' => $row['pct_complete'] !== null ? floatval($row['pct_complete']) : null,
            'route_distance_remaining' => $row['route_dist_to_dest_nm'] !== null ? floatval($row['route_dist_to_dest_nm']) : null,
            'route_percent_complete' => $row['route_pct_complete'] !== null ? floatval($row['route_pct_complete']) : null,
            'time_to_destination' => $time_to_dest,
            'next_waypoint' => $row['next_waypoint_name'],
            'distance_to_next_waypoint' => $row['dist_to_next_waypoint_nm'] !== null ? floatval($row['dist_to_next_waypoint_nm']) : null
        ],
        
        'times' => [
            'scheduled' => [
                'off_block_time' => formatDT($row['std_utc']),
                'arrival_time' => formatDT($row['sta_utc'])
            ],
            'estimated' => [
                'off_block_time' => formatDT($row['etd_utc']),
                'time_of_departure' => formatDT($row['etd_runway_utc']),
                'departure_source' => $row['etd_source'],
                'time_of_arrival' => formatDT($row['eta_utc']),
                'runway_arrival' => formatDT($row['eta_runway_utc']),
                'arrival_source' => $row['eta_source'],
                'arrival_method' => $row['eta_method'],
                'confidence' => $row['eta_confidence'] !== null ? floatval($row['eta_confidence']) : null,
                'wind_component' => $row['eta_wind_component_kts']
            ],
            'actual' => [
                'time_of_departure' => formatDT($row['atd_utc']),
                'departure_runway' => formatDT($row['atd_runway_utc']),
                'time_of_arrival' => formatDT($row['ata_utc']),
                'arrival_runway' => formatDT($row['ata_runway_utc'])
            ],
            'controlled' => [
                'time_of_departure' => formatDT($row['ctd_utc']),
                'time_of_arrival' => formatDT($row['cta_utc']),
                'edct' => formatDT($row['edct_utc']),
                'original_ctd' => formatDT($row['octd_utc']),
                'original_cta' => formatDT($row['octa_utc'])
            ],
            'oooi' => [
                'actual_off_block_time' => formatDT($row['out_utc']),
                'actual_time_of_departure' => formatDT($row['off_utc']),
                'actual_landing_time' => formatDT($row['on_utc']),
                'actual_in_block_time' => formatDT($row['in_utc'])
            ],
            'estimated_elapsed_time' => $row['ete_minutes'],
            'actual_elapsed_time' => $row['ate_minutes']
        ],
        
        'tmi' => [
            'is_controlled' => ($row['gs_held'] == 1 || $row['ctl_type'] !== null),
            'control_type' => $row['ctl_type'],
            'program_name' => $row['ctl_prgm'],
            'control_element' => $row['ctl_element'],
            'exempt_indicator' => (bool)($row['is_exempt'] || $row['ctl_exempt']),
            'exempt_reason' => $row['exempt_reason'] ?? $row['ctl_exempt_reason'],
            'ground_stop' => [
                'held' => $row['gs_held'] == 1,
                'release_time' => formatDT($row['gs_release_utc'])
            ],
            'slot' => [
                'time' => formatDT($row['slot_time_utc']),
                'status' => $row['slot_status'],
                'aslot' => $row['aslot'],
                'program_id' => $row['program_id'],
                'slot_id' => $row['slot_id']
            ],
            'delay' => [
                'value' => $row['delay_minutes'],
                'status' => $row['delay_status'],
                'source' => $row['delay_source'],
                'absolute_min' => $row['absolute_delay_min'],
                'schedule_variation_min' => $row['schedule_variation_min']
            ],
            'popup' => [
                'is_popup' => (bool)$row['is_popup'],
                'detected_time' => formatDT($row['popup_detected_utc'])
            ]
        ],
        
        'data_source' => 'vatcscc',
        'schema_version' => 'normalized_v2_fixm',
        'first_tracked_time' => formatDT($row['first_seen_utc']),
        'position_time' => formatDT($row['last_seen_utc']),
        'logon_time' => formatDT($row['logon_time_utc'])
    ];
}
