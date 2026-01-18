<?php
/**
 * VATSWIM API v1 - TMI Controlled Flights Endpoint
 * 
 * Returns flights currently under Traffic Management Initiative control.
 * Uses swim_flights table from SWIM_API database (not VATSIM_ADL).
 * 
 * GET /api/swim/v1/tmi/controlled
 * GET /api/swim/v1/tmi/controlled?type=gs&airport=KJFK
 * GET /api/swim/v1/tmi/controlled?artcc=ZNY,ZDC
 * 
 * @version 3.0.0 - SWIM_API database only
 */

require_once __DIR__ . '/../auth.php';

// Use SWIM_API database exclusively - swim_flights table
global $conn_swim;

if (!$conn_swim) {
    SwimResponse::error('SWIM database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

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

// Build query against swim_flights
$where_clauses = ['f.is_active = 1'];
$params = [];

// TMI type filter - must have some TMI control
switch ($type) {
    case 'gs':
        $where_clauses[] = 'f.gs_held = 1';
        break;
    case 'gdp':
        $where_clauses[] = "f.ctl_type = 'GDP'";
        break;
    case 'afp':
        $where_clauses[] = "f.ctl_type = 'AFP'";
        break;
    case 'reroute':
        $where_clauses[] = "f.ctl_type = 'REROUTE'";
        break;
    case 'all':
    default:
        $where_clauses[] = '(f.gs_held = 1 OR f.ctl_type IS NOT NULL)';
        break;
}

// Exclude exempt flights unless requested
if (!$include_exempt) {
    $where_clauses[] = '(f.is_exempt IS NULL OR f.is_exempt = 0)';
}

// Airport filter (destination)
if ($airport) {
    $airport_list = array_map('trim', explode(',', strtoupper($airport)));
    $placeholders = implode(',', array_fill(0, count($airport_list), '?'));
    $where_clauses[] = "f.fp_dest_icao IN ($placeholders)";
    $params = array_merge($params, $airport_list);
}

// ARTCC filter
if ($artcc) {
    $artcc_list = array_map('trim', explode(',', strtoupper($artcc)));
    $placeholders = implode(',', array_fill(0, count($artcc_list), '?'));
    $where_clauses[] = "f.fp_dest_artcc IN ($placeholders)";
    $params = array_merge($params, $artcc_list);
}

// Departure filter
if ($dept_icao) {
    $dept_list = array_map('trim', explode(',', strtoupper($dept_icao)));
    $placeholders = implode(',', array_fill(0, count($dept_list), '?'));
    $where_clauses[] = "f.fp_dept_icao IN ($placeholders)";
    $params = array_merge($params, $dept_list);
}

// Phase filter
if ($phase) {
    $phase_list = array_map('trim', explode(',', strtoupper($phase)));
    $placeholders = implode(',', array_fill(0, count($phase_list), '?'));
    $where_clauses[] = "f.phase IN ($placeholders)";
    $params = array_merge($params, $phase_list);
}

$where_sql = 'WHERE ' . implode(' AND ', $where_clauses);

// Count total
$count_sql = "SELECT COUNT(*) as total FROM dbo.swim_flights f $where_sql";
$count_stmt = sqlsrv_query($conn_swim, $count_sql, $params);
if ($count_stmt === false) {
    $errors = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}
$total = sqlsrv_fetch_array($count_stmt, SQLSRV_FETCH_ASSOC)['total'];
sqlsrv_free_stmt($count_stmt);

// Main query against swim_flights
$sql = "
    SELECT 
        f.flight_uid, f.flight_key, f.gufi, f.callsign, f.cid,
        f.phase, f.is_active, f.last_seen_utc,
        f.current_artcc,
        
        -- Position
        f.lat, f.lon, f.altitude_ft, f.heading_deg, f.groundspeed_kts,
        f.dist_to_dest_nm, f.pct_complete,
        
        -- Flight plan
        f.fp_dept_icao, f.fp_dest_icao, f.fp_alt_icao,
        f.fp_altitude_ft, f.fp_route,
        f.fp_dept_artcc, f.fp_dest_artcc,
        f.dfix, f.dp_name, f.afix, f.star_name,
        f.gcd_nm, f.aircraft_type,
        
        -- Times
        f.etd_utc, f.eta_runway_utc, f.eta_utc,
        f.out_utc, f.off_utc,
        f.ete_minutes, f.ctd_utc, f.cta_utc, f.edct_utc,
        
        -- TMI details
        f.ctl_type, f.ctl_prgm, f.ctl_element,
        f.is_exempt, f.exempt_reason,
        f.slot_time_utc, f.slot_status,
        f.delay_minutes, f.delay_status,
        f.gs_held, f.gs_release_utc,
        f.program_id, f.slot_id,
        
        -- Aircraft
        f.aircraft_icao, f.weight_class, f.wake_category,
        f.airline_icao, f.airline_name
        
    FROM dbo.swim_flights f
    $where_sql
    ORDER BY 
        f.gs_held DESC,
        f.ctl_type,
        f.fp_dest_icao,
        f.eta_runway_utc
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";

$params[] = $offset;
$params[] = $per_page;

$stmt = sqlsrv_query($conn_swim, $sql, $params);
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
    } elseif ($row['ctl_type'] === 'REROUTE') {
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
    'meta' => [
        'source' => 'swim_api',
        'table' => 'swim_flights'
    ]
];

SwimResponse::json($response);


function formatControlledFlight($row) {
    $gufi = $row['gufi'] ?? swim_generate_gufi($row['callsign'], $row['fp_dept_icao'], $row['fp_dest_icao']);
    
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
            'is_exempt' => (bool)$row['is_exempt'],
            'exempt_reason' => $row['exempt_reason'],
            'ground_stop' => [
                'held' => $row['gs_held'] == 1,
                'release_time' => formatDT($row['gs_release_utc'])
            ],
            'slot' => [
                'time' => formatDT($row['slot_time_utc']),
                'status' => $row['slot_status'],
                'program_id' => $row['program_id'],
                'slot_id' => $row['slot_id']
            ],
            'delay' => [
                'minutes' => $delay_minutes,
                'status' => $row['delay_status']
            ]
        ],
        
        '_last_seen' => formatDT($row['last_seen_utc'])
    ];
}

function formatDT($dt) {
    if ($dt === null) return null;
    return ($dt instanceof DateTime) ? $dt->format('c') : $dt;
}
