<?php
/**
 * VATSWIM API v1 - Positions Endpoint
 * 
 * Returns bulk flight positions in GeoJSON format.
 * Uses denormalized swim_flights table from SWIM_API database.
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

$dept_icao = swim_get_param('dept_icao');
$dest_icao = swim_get_param('dest_icao');
$artcc = swim_get_param('artcc');
$bounds = swim_get_param('bounds');
$tmi_controlled = swim_get_param('tmi_controlled');
$phase = swim_get_param('phase');
$include_route = swim_get_param('include_route', 'false') === 'true';

// Build query
$where_clauses = [];
$params = [];

if ($use_swim_db) {
    // SWIM_API: Simple single-table queries against swim_flights
    $where_clauses = ["f.is_active = 1", "f.lat IS NOT NULL", "f.lon IS NOT NULL"];
    
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
    
    if ($bounds) {
        $bbox = array_map('floatval', explode(',', $bounds));
        if (count($bbox) === 4) {
            list($minLon, $minLat, $maxLon, $maxLat) = $bbox;
            $where_clauses[] = "f.lon BETWEEN ? AND ?";
            $where_clauses[] = "f.lat BETWEEN ? AND ?";
            array_push($params, $minLon, $maxLon, $minLat, $maxLat);
        }
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
    
    $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
    
    // Single-table query
    $sql = "
        SELECT 
            f.flight_uid, f.flight_key, f.callsign, f.phase,
            f.current_artcc,
            f.lat, f.lon, f.altitude_ft, f.heading_deg, f.groundspeed_kts,
            f.dist_to_dest_nm, f.pct_complete, f.vertical_rate_fpm,
            f.fp_dept_icao, f.fp_dest_icao, f.fp_dest_artcc,
            f.aircraft_type, f.fp_route, f.gcd_nm,
            f.eta_runway_utc, f.eta_utc, f.ete_minutes,
            f.gs_held, f.ctl_type, f.ctl_prgm, f.ctl_element,
            f.slot_time_utc, f.program_id,
            f.weight_class, f.wake_category
        FROM dbo.swim_flights f
        $where_sql
    ";
    
} else {
    // VATSIM_ADL fallback: JOIN across normalized tables
    $where_clauses = ["c.is_active = 1", "pos.lat IS NOT NULL", "pos.lon IS NOT NULL"];
    
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
    
    if ($bounds) {
        $bbox = array_map('floatval', explode(',', $bounds));
        if (count($bbox) === 4) {
            list($minLon, $minLat, $maxLon, $maxLat) = $bbox;
            $where_clauses[] = "pos.lon BETWEEN ? AND ?";
            $where_clauses[] = "pos.lat BETWEEN ? AND ?";
            array_push($params, $minLon, $maxLon, $minLat, $maxLat);
        }
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
    
    $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
    
    // JOIN query for legacy mode
    $sql = "
        SELECT 
            c.flight_uid, c.flight_key, c.callsign, c.phase,
            c.current_artcc,
            pos.lat, pos.lon, pos.altitude_ft, pos.heading_deg, pos.groundspeed_kts,
            pos.dist_to_dest_nm, pos.pct_complete, pos.vertical_rate_fpm,
            fp.fp_dept_icao, fp.fp_dest_icao, fp.fp_dest_artcc,
            fp.aircraft_type, fp.fp_route, fp.gcd_nm,
            t.eta_runway_utc, t.eta_utc, t.ete_minutes,
            tmi.gs_held, tmi.ctl_type, tmi.ctl_prgm, tmi.ctl_element,
            tmi.slot_time_utc, tmi.program_id,
            ac.weight_class, ac.wake_category
        FROM dbo.adl_flight_core c
        INNER JOIN dbo.adl_flight_position pos ON pos.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_tmi tmi ON tmi.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid
        $where_sql
    ";
}

$stmt = sqlsrv_query($conn, $sql, $params);
if ($stmt === false) {
    $errors = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}

$features = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $features[] = buildFeature($row, $include_route);
}
sqlsrv_free_stmt($stmt);

header('Content-Type: application/geo+json; charset=utf-8');
header('X-SWIM-Version: ' . SWIM_API_VERSION);
header('X-SWIM-Source: ' . ($use_swim_db ? 'swim_api' : 'vatsim_adl'));
header('Access-Control-Allow-Origin: *');
echo json_encode([
    'type' => 'FeatureCollection',
    'features' => $features,
    'metadata' => [
        'count' => count($features), 
        'timestamp' => gmdate('c'), 
        'source' => 'vatcscc',
        'database' => $use_swim_db ? 'SWIM_API' : 'VATSIM_ADL'
    ]
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;

function buildFeature($row, $include_route = false) {
    // Determine TMI status
    $tmi_status = 'none';
    if ($row['gs_held'] == 1) {
        $tmi_status = 'ground_stop';
    } elseif ($row['program_id'] !== null) {
        $tmi_status = 'gdp';
    } elseif ($row['ctl_type'] !== null) {
        $tmi_status = strtolower($row['ctl_type']);
    }
    
    $properties = [
        'flight_uid' => $row['flight_uid'],
        'callsign' => $row['callsign'],
        'flight_key' => $row['flight_key'],
        'aircraft' => $row['aircraft_type'],
        'weight_class' => $row['weight_class'],
        'wake_category' => $row['wake_category'],
        'departure' => trim($row['fp_dept_icao'] ?? ''),
        'destination' => trim($row['fp_dest_icao'] ?? ''),
        'phase' => $row['phase'],
        'dest_artcc' => $row['fp_dest_artcc'],
        'current_artcc' => $row['current_artcc'],
        'altitude' => $row['altitude_ft'] !== null ? intval($row['altitude_ft']) : null,
        'heading' => $row['heading_deg'] !== null ? intval($row['heading_deg']) : null,
        'groundspeed' => $row['groundspeed_kts'] !== null ? intval($row['groundspeed_kts']) : null,
        'vertical_rate' => $row['vertical_rate_fpm'],
        'distance_remaining_nm' => $row['dist_to_dest_nm'] !== null ? round(floatval($row['dist_to_dest_nm']), 1) : null,
        'gcd_nm' => $row['gcd_nm'] !== null ? round(floatval($row['gcd_nm']), 1) : null,
        'pct_complete' => $row['pct_complete'] !== null ? round(floatval($row['pct_complete']), 1) : null,
        'tmi_status' => $tmi_status
    ];
    
    // Add ETA if available
    $eta = $row['eta_runway_utc'] ?? $row['eta_utc'];
    if ($eta) {
        $properties['eta'] = ($eta instanceof DateTime) ? $eta->format('c') : $eta;
    }
    
    if ($row['ete_minutes']) {
        $properties['ete_minutes'] = $row['ete_minutes'];
    }
    
    if ($include_route && !empty($row['fp_route'])) {
        $properties['route_string'] = $row['fp_route'];
    }
    
    // Build coordinates (lon, lat, altitude)
    $coordinates = [
        round(floatval($row['lon']), SWIM_GEOJSON_PRECISION),
        round(floatval($row['lat']), SWIM_GEOJSON_PRECISION)
    ];
    if ($row['altitude_ft'] !== null) {
        $coordinates[] = intval($row['altitude_ft']);
    }
    
    return [
        'type' => 'Feature',
        'id' => $row['flight_uid'],
        'geometry' => ['type' => 'Point', 'coordinates' => $coordinates],
        'properties' => $properties
    ];
}
