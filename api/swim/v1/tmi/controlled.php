<?php
/**
 * VATSIM SWIM API v1 - TMI Controlled Flights Endpoint
 * 
 * Returns flights currently under Traffic Management Initiative control.
 * Includes flights affected by Ground Stops, GDPs, AFPs, and other TMI programs.
 * Uses JOINs across normalized ADL tables.
 * 
 * VERIFIED against normalized schema: 2026-01-15
 * 
 * GET /api/swim/v1/tmi/controlled
 * GET /api/swim/v1/tmi/controlled?type=gs&airport=KJFK
 * GET /api/swim/v1/tmi/controlled?artcc=ZNY,ZDC
 * 
 * @version 2.0.0 - Normalized ADL Schema
 */

require_once __DIR__ . '/../auth.php';

$auth = swim_init_auth(true, false);

// Get filter parameters
$type = swim_get_param('type', 'all');          // all, gs, gdp, afp, reroute
$airport = swim_get_param('airport');            // Filter by controlled airport
$artcc = swim_get_param('artcc');                // Filter by destination ARTCC
$dept_icao = swim_get_param('dept_icao');        // Filter by departure airport
$phase = swim_get_param('phase');                // Filter by flight phase
$include_exempt = swim_get_param('include_exempt', 'false') === 'true';

$page = swim_get_int_param('page', 1, 1, 1000);
$per_page = swim_get_int_param('per_page', SWIM_DEFAULT_PAGE_SIZE, 1, SWIM_MAX_PAGE_SIZE);
$offset = ($page - 1) * $per_page;

// Build query
$where_clauses = ['c.is_active = 1'];
$params = [];

// TMI type filter
switch ($type) {
    case 'gs':
        $where_clauses[] = 'tmi.gs_held = 1';
        break;
    case 'gdp':
        $where_clauses[] = "tmi.ctl_type = 'GDP'";
        break;
    case 'afp':
        $where_clauses[] = "tmi.ctl_type = 'AFP'";
        break;
    case 'reroute':
        $where_clauses[] = "(tmi.ctl_type = 'REROUTE' OR tmi.reroute_status IS NOT NULL)";
        break;
    case 'all':
    default:
        $where_clauses[] = '(tmi.gs_held = 1 OR tmi.ctl_type IS NOT NULL)';
        break;
}

// Exclude exempt flights unless requested
if (!$include_exempt) {
    $where_clauses[] = '(tmi.is_exempt IS NULL OR tmi.is_exempt = 0)';
}

// Airport filter
if ($airport) {
    $airport_list = array_map('trim', explode(',', strtoupper($airport)));
    $placeholders = implode(',', array_fill(0, count($airport_list), '?'));
    $where_clauses[] = "fp.fp_dest_icao IN ($placeholders)";
    $params = array_merge($params, $airport_list);
}

// ARTCC filter
if ($artcc) {
    $artcc_list = array_map('trim', explode(',', strtoupper($artcc)));
    $placeholders = implode(',', array_fill(0, count($artcc_list), '?'));
    $where_clauses[] = "fp.fp_dest_artcc IN ($placeholders)";
    $params = array_merge($params, $artcc_list);
}

// Departure filter
if ($dept_icao) {
    $dept_list = array_map('trim', explode(',', strtoupper($dept_icao)));
    $placeholders = implode(',', array_fill(0, count($dept_list), '?'));
    $where_clauses[] = "fp.fp_dept_icao IN ($placeholders)";
    $params = array_merge($params, $dept_list);
}

// Phase filter
if ($phase) {
    $phase_list = array_map('trim', explode(',', strtoupper($phase)));
    $placeholders = implode(',', array_fill(0, count($phase_list), '?'));
    $where_clauses[] = "c.phase IN ($placeholders)";
    $params = array_merge($params, $phase_list);
}

$where_sql = 'WHERE ' . implode(' AND ', $where_clauses);

// Count total
$count_sql = "
    SELECT COUNT(*) as total 
    FROM dbo.adl_flight_core c
    LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_tmi tmi ON tmi.flight_uid = c.flight_uid
    $where_sql
";
$count_stmt = sqlsrv_query($conn_adl, $count_sql, $params);
if ($count_stmt === false) {
    SwimResponse::error('Database error', 500, 'DB_ERROR');
}
$total = sqlsrv_fetch_array($count_stmt, SQLSRV_FETCH_ASSOC)['total'];
sqlsrv_free_stmt($count_stmt);

// Main query - VERIFIED column names against normalized schema
$sql = "
    SELECT 
        -- adl_flight_core
        c.flight_uid, c.flight_key, c.callsign, c.cid,
        c.phase, c.is_active, c.last_seen_utc,
        c.current_artcc,
        
        -- adl_flight_position
        pos.lat, pos.lon, pos.altitude_ft, pos.heading_deg, pos.groundspeed_kts,
        pos.dist_to_dest_nm, pos.pct_complete,
        
        -- adl_flight_plan
        fp.fp_dept_icao, fp.fp_dest_icao, fp.fp_alt_icao,
        fp.fp_altitude_ft, fp.fp_route,
        fp.fp_dept_artcc, fp.fp_dest_artcc,
        fp.dfix, fp.dp_name, fp.afix, fp.star_name,
        fp.gcd_nm, fp.aircraft_type,
        
        -- adl_flight_times
        t.etd_utc, t.eta_runway_utc, t.eta_utc,
        t.out_utc, t.off_utc,
        t.ete_minutes, t.ctd_utc, t.cta_utc, t.edct_utc,
        
        -- adl_flight_tmi (full details)
        tmi.ctl_type, tmi.ctl_prgm, tmi.ctl_element,
        tmi.ctl_exempt, tmi.ctl_exempt_reason,
        tmi.is_exempt, tmi.exempt_reason,
        tmi.slot_time_utc, tmi.slot_status, tmi.aslot,
        tmi.delay_minutes, tmi.delay_status, tmi.delay_source,
        tmi.gs_held, tmi.gs_release_utc,
        tmi.program_id, tmi.slot_id,
        tmi.absolute_delay_min, tmi.schedule_variation_min,
        tmi.is_popup, tmi.ecr_pending,
        tmi.reroute_status, tmi.reroute_id,
        
        -- adl_flight_aircraft
        ac.aircraft_icao, ac.weight_class, ac.wake_category,
        ac.airline_icao, ac.airline_name
        
    FROM dbo.adl_flight_core c
    LEFT JOIN dbo.adl_flight_position pos ON pos.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_tmi tmi ON tmi.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid
    $where_sql
    ORDER BY 
        tmi.gs_held DESC,
        tmi.ctl_type,
        fp.fp_dest_icao,
        t.eta_runway_utc
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";

$params[] = $offset;
$params[] = $per_page;

$stmt = sqlsrv_query($conn_adl, $sql, $params);
if ($stmt === false) {
    $errors = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}

$flights = [];
$stats = [
    'ground_stop' => 0,
    'gdp' => 0,
    'afp' => 0,
    'reroute' => 0,
    'other' => 0,
    'by_airport' => [],
    'by_artcc' => []
];

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $flight = formatControlledFlight($row);
    $flights[] = $flight;
    
    // Update stats
    if ($row['gs_held'] == 1) {
        $stats['ground_stop']++;
    } elseif ($row['ctl_type'] === 'GDP') {
        $stats['gdp']++;
    } elseif ($row['ctl_type'] === 'AFP') {
        $stats['afp']++;
    } elseif ($row['ctl_type'] === 'REROUTE' || $row['reroute_status'] !== null) {
        $stats['reroute']++;
    } else {
        $stats['other']++;
    }
    
    // By airport
    $dest = trim($row['fp_dest_icao'] ?? '');
    if ($dest) {
        $stats['by_airport'][$dest] = ($stats['by_airport'][$dest] ?? 0) + 1;
    }
    
    // By ARTCC
    $dest_artcc = $row['fp_dest_artcc'];
    if ($dest_artcc) {
        $stats['by_artcc'][$dest_artcc] = ($stats['by_artcc'][$dest_artcc] ?? 0) + 1;
    }
}
sqlsrv_free_stmt($stmt);

// Sort stats by count descending
arsort($stats['by_airport']);
arsort($stats['by_artcc']);

// Build response
$response = [
    'success' => true,
    'data' => $flights,
    'statistics' => $stats,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => ceil($total / $per_page),
        'has_more' => $page < ceil($total / $per_page)
    ],
    'filters' => [
        'type' => $type,
        'airport' => $airport,
        'artcc' => $artcc,
        'include_exempt' => $include_exempt
    ],
    'timestamp' => gmdate('c'),
    'schema' => 'normalized_v2'
];

SwimResponse::json($response);


function formatControlledFlight($row) {
    $gufi = swim_generate_gufi($row['callsign'], $row['fp_dept_icao'], $row['fp_dest_icao']);
    
    // Calculate delay from slot time vs ETD
    $delay_minutes = $row['delay_minutes'];
    if ($delay_minutes === null && $row['slot_time_utc'] && $row['etd_utc']) {
        $slot_time = $row['slot_time_utc'] instanceof DateTime 
            ? $row['slot_time_utc']->getTimestamp() 
            : strtotime($row['slot_time_utc']);
        $etd_time = $row['etd_utc'] instanceof DateTime 
            ? $row['etd_utc']->getTimestamp() 
            : strtotime($row['etd_utc']);
        
        if ($slot_time && $etd_time) {
            $delay_minutes = round(($slot_time - $etd_time) / 60);
            if ($delay_minutes < 0) $delay_minutes = null;
        }
    }
    
    // Calculate time to destination
    $time_to_dest = null;
    if ($row['groundspeed_kts'] > 50 && $row['dist_to_dest_nm'] > 0) {
        $time_to_dest = round(($row['dist_to_dest_nm'] / $row['groundspeed_kts']) * 60, 1);
    } elseif ($row['ete_minutes']) {
        $time_to_dest = $row['ete_minutes'];
    }
    
    // Determine TMI type
    $tmi_type = 'UNKNOWN';
    if ($row['gs_held'] == 1) {
        $tmi_type = 'GROUND_STOP';
    } elseif ($row['ctl_type']) {
        $tmi_type = $row['ctl_type'];
    } elseif ($row['reroute_status']) {
        $tmi_type = 'REROUTE';
    }
    
    return [
        'gufi' => $gufi,
        'flight_uid' => $row['flight_uid'],
        'flight_key' => $row['flight_key'],
        'callsign' => $row['callsign'],
        'cid' => $row['cid'],
        
        'aircraft' => [
            'type' => $row['aircraft_type'],
            'icao' => $row['aircraft_icao'],
            'weight_class' => $row['weight_class'],
            'wake_category' => $row['wake_category'],
            'airline_icao' => $row['airline_icao'],
            'airline_name' => $row['airline_name']
        ],
        
        'route' => [
            'departure' => trim($row['fp_dept_icao'] ?? ''),
            'destination' => trim($row['fp_dest_icao'] ?? ''),
            'alternate' => trim($row['fp_alt_icao'] ?? ''),
            'departure_artcc' => $row['fp_dept_artcc'],
            'destination_artcc' => $row['fp_dest_artcc'],
            'departure_fix' => $row['dfix'],
            'arrival_fix' => $row['afix']
        ],
        
        'position' => [
            'latitude' => $row['lat'] !== null ? floatval($row['lat']) : null,
            'longitude' => $row['lon'] !== null ? floatval($row['lon']) : null,
            'altitude_ft' => $row['altitude_ft'],
            'ground_speed_kts' => $row['groundspeed_kts'],
            'heading' => $row['heading_deg'],
            'phase' => $row['phase'],
            'current_artcc' => $row['current_artcc']
        ],
        
        'progress' => [
            'distance_remaining_nm' => $row['dist_to_dest_nm'] !== null ? floatval($row['dist_to_dest_nm']) : null,
            'gcd_nm' => $row['gcd_nm'] !== null ? floatval($row['gcd_nm']) : null,
            'pct_complete' => $row['pct_complete'] !== null ? floatval($row['pct_complete']) : null,
            'time_to_dest_min' => $time_to_dest
        ],
        
        'times' => [
            'etd' => formatDT($row['etd_utc']),
            'eta' => formatDT($row['eta_runway_utc'] ?? $row['eta_utc']),
            'off' => formatDT($row['off_utc']),
            'ctd' => formatDT($row['ctd_utc']),
            'cta' => formatDT($row['cta_utc']),
            'edct' => formatDT($row['edct_utc'])
        ],
        
        'tmi' => [
            'type' => $tmi_type,
            'program' => $row['ctl_prgm'],
            'element' => $row['ctl_element'],
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
                'minutes' => $delay_minutes,
                'status' => $row['delay_status'],
                'source' => $row['delay_source'],
                'absolute_min' => $row['absolute_delay_min'],
                'schedule_variation_min' => $row['schedule_variation_min']
            ],
            'reroute' => [
                'status' => $row['reroute_status'],
                'id' => $row['reroute_id']
            ],
            'flags' => [
                'is_popup' => (bool)$row['is_popup'],
                'ecr_pending' => (bool)$row['ecr_pending']
            ]
        ],
        
        '_last_seen' => formatDT($row['last_seen_utc'])
    ];
}

function formatDT($dt) {
    if ($dt === null) return null;
    return ($dt instanceof DateTime) ? $dt->format('c') : $dt;
}
