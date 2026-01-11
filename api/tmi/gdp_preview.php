<?php
/**
 * GDP Preview API
 * 
 * Fetches flights from live ADL matching GDP filter criteria.
 * Unlike GS (departure-based), GDP filters by ARRIVAL time window.
 * 
 * Input (JSON POST):
 *   - gdp_airport: Destination airport (CTL element)
 *   - gdp_origin_airports: Origin airport filter (optional)
 *   - gdp_origin_centers: Origin ARTCC filter (optional)
 *   - gdp_flt_incl_carrier: Carrier filter (optional)
 *   - gdp_flt_incl_type: Aircraft type filter (ALL/JET/PROP)
 *   - gdp_start: Program start time (UTC)
 *   - gdp_end: Program end time (UTC)
 *   - exemptions: Exemption rules (optional JSON)
 * 
 * Output:
 *   - flights: Array of matching flights
 *   - summary: Counts by origin center, carrier, etc.
 */

header('Content-Type: application/json; charset=utf-8');

require_once(__DIR__ . '/../../load/connect.php');

// -------------------------------
// Helpers
// -------------------------------
function split_codes($val) {
    if (is_array($val)) {
        $val = implode(' ', $val);
    }
    if (!is_string($val)) return [];
    $val = strtoupper(trim($val));
    if ($val === '') return [];
    $val = str_replace([",", ";", "\n", "\r", "\t"], " ", $val);
    $parts = preg_split('/\s+/', $val);
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') $out[] = $p;
    }
    // unique, preserve order
    $seen = [];
    $uniq = [];
    foreach ($out as $p) {
        if (!isset($seen[$p])) { $seen[$p] = true; $uniq[] = $p; }
    }
    return $uniq;
}

function parse_utc_datetime($s) {
    if (!is_string($s) || trim($s) === '') return null;
    try {
        $dt = new DateTime(trim($s));
    } catch (Exception $e) {
        return null;
    }
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}

function datetime_to_iso($val) {
    if ($val === null) return null;
    if ($val instanceof \DateTimeInterface) {
        $utc = clone $val;
        if (method_exists($utc, 'setTimezone')) {
            $utc->setTimezone(new \DateTimeZone('UTC'));
        }
        return $utc->format('Y-m-d\TH:i:s') . 'Z';
    }
    if (is_string($val)) return $val;
    return $val;
}

/**
 * Check if a flight is exempt based on exemption rules
 */
function is_flight_exempt($flight, $exemptions) {
    if (empty($exemptions)) return false;
    
    // Origin airport exemption
    if (!empty($exemptions['orig_airports'])) {
        $exempt_origins = split_codes($exemptions['orig_airports']);
        if (in_array(strtoupper($flight['fp_dept_icao'] ?? ''), $exempt_origins)) {
            return true;
        }
    }
    
    // Origin TRACON exemption
    if (!empty($exemptions['orig_tracons'])) {
        $exempt_tracons = split_codes($exemptions['orig_tracons']);
        if (in_array(strtoupper($flight['fp_dept_tracon'] ?? ''), $exempt_tracons)) {
            return true;
        }
    }
    
    // Origin ARTCC exemption
    if (!empty($exemptions['orig_artccs'])) {
        $exempt_artccs = split_codes($exemptions['orig_artccs']);
        if (in_array(strtoupper($flight['fp_dept_artcc'] ?? ''), $exempt_artccs)) {
            return true;
        }
    }
    
    // Destination airport exemption
    if (!empty($exemptions['dest_airports'])) {
        $exempt_dests = split_codes($exemptions['dest_airports']);
        if (in_array(strtoupper($flight['fp_dest_icao'] ?? ''), $exempt_dests)) {
            return true;
        }
    }
    
    // Destination TRACON exemption
    if (!empty($exemptions['dest_tracons'])) {
        $exempt_tracons = split_codes($exemptions['dest_tracons']);
        if (in_array(strtoupper($flight['fp_dest_tracon'] ?? ''), $exempt_tracons)) {
            return true;
        }
    }
    
    // Destination ARTCC exemption
    if (!empty($exemptions['dest_artccs'])) {
        $exempt_artccs = split_codes($exemptions['dest_artccs']);
        if (in_array(strtoupper($flight['fp_dest_artcc'] ?? ''), $exempt_artccs)) {
            return true;
        }
    }
    
    // Carrier exemption
    if (!empty($exemptions['carriers'])) {
        $exempt_carriers = split_codes($exemptions['carriers']);
        if (in_array(strtoupper($flight['major_carrier'] ?? ''), $exempt_carriers)) {
            return true;
        }
    }
    
    // Callsign exemption
    if (!empty($exemptions['callsigns'])) {
        $exempt_callsigns = split_codes($exemptions['callsigns']);
        if (in_array(strtoupper($flight['callsign'] ?? ''), $exempt_callsigns)) {
            return true;
        }
    }
    
    // Aircraft type exemption
    if (!empty($exemptions['type_jet']) && strtoupper($flight['ac_cat'] ?? '') === 'JET') {
        return true;
    }
    if (!empty($exemptions['type_prop']) && strtoupper($flight['ac_cat'] ?? '') === 'PROP') {
        return true;
    }
    
    // Airborne exemption - flights already in the air
    if (!empty($exemptions['airborne'])) {
        $phase = strtolower($flight['phase'] ?? '');
        if (in_array($phase, ['departed', 'enroute', 'descending'])) {
            return true;
        }
    }
    
    return false;
}

/**
 * Calculate great circle distance between two points using Haversine formula
 * Returns distance in nautical miles
 */
function haversine_nm($lat1, $lon1, $lat2, $lon2) {
    $earth_radius_nm = 3440.065; // Earth radius in nautical miles
    
    $lat1_rad = deg2rad($lat1);
    $lat2_rad = deg2rad($lat2);
    $delta_lat = deg2rad($lat2 - $lat1);
    $delta_lon = deg2rad($lon2 - $lon1);
    
    $a = sin($delta_lat / 2) * sin($delta_lat / 2) +
         cos($lat1_rad) * cos($lat2_rad) *
         sin($delta_lon / 2) * sin($delta_lon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    
    return $earth_radius_nm * $c;
}

// -------------------------------
// Input
// -------------------------------
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

// GDP is arrival-based: filter by destination airport
$gdp_airport         = isset($input['gdp_airport']) ? strtoupper(trim($input['gdp_airport'])) : '';
$gdp_origin_airports = isset($input['gdp_origin_airports']) ? $input['gdp_origin_airports'] : '';
$gdp_origin_centers  = isset($input['gdp_origin_centers']) ? $input['gdp_origin_centers'] : '';
$gdp_dep_facilities  = isset($input['gdp_dep_facilities']) ? $input['gdp_dep_facilities'] : '';
$gdp_flt_incl_type   = isset($input['gdp_flt_incl_type']) ? strtoupper(trim($input['gdp_flt_incl_type'])) : 'ALL';
$gdp_flt_incl_carrier= isset($input['gdp_flt_incl_carrier']) ? $input['gdp_flt_incl_carrier'] : '';
$gdp_start_raw       = isset($input['gdp_start']) ? $input['gdp_start'] : null;
$gdp_end_raw         = isset($input['gdp_end']) ? $input['gdp_end'] : null;
$distance_nm         = isset($input['distance_nm']) ? (int)$input['distance_nm'] : 0;
$exemptions          = isset($input['exemptions']) ? $input['exemptions'] : [];

// Normalize lists
$origin_airports = split_codes($gdp_origin_airports);
$carriers        = split_codes($gdp_flt_incl_carrier);

// Origin centers: use dep_facilities (the expanded list of actual ARTCC codes)
// gdp_origin_centers contains scope codes like "1stTier" which aren't valid for filtering
// gdp_dep_facilities contains the actual ARTCC codes like "ZTL ZDC ZNY"
$scope_codes = split_codes($gdp_origin_centers);  // For reference only
$dep_centers = split_codes($gdp_dep_facilities);  // The actual ARTCC codes to filter

// If dep_facilities is "ALL", don't filter by center
if (count($dep_centers) > 0 && $dep_centers[0] === 'ALL') {
    $dep_centers = [];
}
$origin_centers = $dep_centers;  // Use only the expanded facility list

$gdp_start = parse_utc_datetime($gdp_start_raw);
$gdp_end   = parse_utc_datetime($gdp_end_raw);

// Validate required fields
if ($gdp_airport === '') {
    echo json_encode([
        'status'  => 'error',
        'message' => 'gdp_airport (CTL element) is required.'
    ], JSON_PRETTY_PRINT);
    exit;
}

// -------------------------------
// Connection
// -------------------------------
$conn = isset($conn_adl) ? $conn_adl : null;
if (!$conn) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'ADL SQL connection not established (conn_adl is null).',
        'errors'  => function_exists('sqlsrv_errors') ? sqlsrv_errors() : null
    ], JSON_PRETTY_PRINT);
    exit;
}

// -------------------------------
// Query building
// -------------------------------
$where = [];
$params = [];

// GDP filters by DESTINATION airport (the constrained airport)
$where[] = "fp_dest_icao = ?";
$params[] = $gdp_airport;

// GDP filters by ARRIVAL time window (not departure like GS)
// Include flights arriving during the program period
// If no time specified, include all future arrivals
if ($gdp_start !== null && $gdp_end !== null) {
    $where[] = "(eta_runway_utc >= ? AND eta_runway_utc <= ?)";
    $params[] = $gdp_start;
    $params[] = $gdp_end;
} elseif ($gdp_end !== null) {
    $where[] = "(eta_runway_utc <= ?)";
    $params[] = $gdp_end;
} elseif ($gdp_start !== null) {
    $where[] = "(eta_runway_utc >= ?)";
    $params[] = $gdp_start;
}
// If no times specified, don't filter by time - show all arrivals to this airport

// Origin airport filter (only if not using distance mode)
if ($distance_nm == 0 && count($origin_airports) > 0) {
    $where[] = "fp_dept_icao IN (" . implode(',', array_fill(0, count($origin_airports), '?')) . ")";
    foreach ($origin_airports as $o) { $params[] = $o; }
}

// Origin center filter - use dep_facilities (the expanded list)
// Only apply if we have specific centers selected AND not using distance mode
if ($distance_nm == 0 && count($origin_centers) > 0) {
    $where[] = "fp_dept_artcc IN (" . implode(',', array_fill(0, count($origin_centers), '?')) . ")";
    foreach ($origin_centers as $c) { $params[] = $c; }
}

// Aircraft type filter
if ($gdp_flt_incl_type !== '' && $gdp_flt_incl_type !== 'ALL') {
    if ($gdp_flt_incl_type === 'JET') {
        $where[] = "(UPPER(ISNULL(ac_cat,'')) = 'JET')";
    } elseif ($gdp_flt_incl_type === 'PROP') {
        $where[] = "(UPPER(ISNULL(ac_cat,'')) = 'PROP')";
    }
}

// Carrier filter
if (count($carriers) > 0) {
    $where[] = "major_carrier IN (" . implode(',', array_fill(0, count($carriers), '?')) . ")";
    foreach ($carriers as $mc) { $params[] = $mc; }
}

// Exclude flights that have already landed
// phase values: prefile, taxiing, departed, enroute, descending, arrived
$where[] = "(phase IS NULL OR phase != 'arrived')";

// -------------------------------
// Distance-based scope (if specified)
// -------------------------------
$gdp_coords = null;
$origin_airports_by_distance = [];

if ($distance_nm > 0) {
    // Get GDP airport coordinates from airports table
    $apt_stmt = sqlsrv_query($conn, "
        SELECT icao_code, latitude, longitude 
        FROM dbo.airports 
        WHERE icao_code = ?
    ", [$gdp_airport]);
    
    if ($apt_stmt !== false && ($apt_row = sqlsrv_fetch_array($apt_stmt, SQLSRV_FETCH_ASSOC))) {
        $gdp_coords = [
            'lat' => (float)$apt_row['latitude'],
            'lon' => (float)$apt_row['longitude']
        ];
        
        // Get all airports within distance
        $all_apts_stmt = sqlsrv_query($conn, "
            SELECT icao_code, latitude, longitude 
            FROM dbo.airports 
            WHERE latitude IS NOT NULL AND longitude IS NOT NULL
        ");
        
        if ($all_apts_stmt !== false) {
            while ($apt = sqlsrv_fetch_array($all_apts_stmt, SQLSRV_FETCH_ASSOC)) {
                $dist = haversine_nm(
                    $gdp_coords['lat'], $gdp_coords['lon'],
                    (float)$apt['latitude'], (float)$apt['longitude']
                );
                if ($dist <= $distance_nm) {
                    $origin_airports_by_distance[] = $apt['icao_code'];
                }
            }
        }
        
        // Add distance filter to WHERE clause
        if (count($origin_airports_by_distance) > 0) {
            $where[] = "fp_dept_icao IN (" . implode(',', array_fill(0, count($origin_airports_by_distance), '?')) . ")";
            foreach ($origin_airports_by_distance as $apt) { $params[] = $apt; }
        } else {
            // No airports within distance - return empty result
            echo json_encode([
                'status'  => 'ok',
                'message' => 'No origin airports found within ' . $distance_nm . ' NM of ' . $gdp_airport,
                'total'   => 0,
                'affected' => 0,
                'exempt'  => 0,
                'filters' => [
                    'gdp_airport' => $gdp_airport,
                    'distance_nm' => $distance_nm
                ],
                'summary' => [],
                'flights' => [],
                'exempt_flights' => []
            ], JSON_PRETTY_PRINT);
            exit;
        }
    }
}

// Final SQL - order by ETA for slot allocation (query normalized tables via view)
$sql = "SELECT * FROM dbo.vw_adl_flights";
if (count($where) > 0) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY eta_runway_utc ASC";

// -------------------------------
// Execute
// -------------------------------
$stmt = null;
if (count($params) > 0) {
    $stmt = sqlsrv_query($conn, $sql, $params);
} else {
    $stmt = sqlsrv_query($conn, $sql);
}

if ($stmt === false) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Query failed',
        'errors'  => sqlsrv_errors()
    ], JSON_PRETTY_PRINT);
    exit;
}

// -------------------------------
// Process results and apply exemptions
// -------------------------------
$flights = [];
$exempt_flights = [];
$summary = [
    'by_origin_center' => [],
    'by_origin_airport' => [],
    'by_carrier' => [],
    'by_hour' => [],
    'by_ac_cat' => []
];

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    // Convert DateTime objects to ISO strings
    foreach ($row as $key => $val) {
        if ($val instanceof \DateTimeInterface) {
            $row[$key] = datetime_to_iso($val);
        }
    }
    
    // Check exemptions
    $row['is_exempt'] = is_flight_exempt($row, $exemptions);
    
    if ($row['is_exempt']) {
        $exempt_flights[] = $row;
    } else {
        $flights[] = $row;
        
        // Build summary counts for non-exempt flights
        $octr = $row['fp_dept_artcc'] ?? 'UNK';
        $oapt = $row['fp_dept_icao'] ?? 'UNK';
        $carr = $row['major_carrier'] ?? 'UNK';
        $acat = $row['ac_cat'] ?? 'UNK';
        
        // Extract hour from ETA
        $eta_str = $row['eta_runway_utc'] ?? '';
        $hour = 'UNK';
        if ($eta_str && preg_match('/T(\d{2}):/', $eta_str, $m)) {
            $hour = $m[1] . '00Z';
        }
        
        $summary['by_origin_center'][$octr] = ($summary['by_origin_center'][$octr] ?? 0) + 1;
        $summary['by_origin_airport'][$oapt] = ($summary['by_origin_airport'][$oapt] ?? 0) + 1;
        $summary['by_carrier'][$carr] = ($summary['by_carrier'][$carr] ?? 0) + 1;
        $summary['by_hour'][$hour] = ($summary['by_hour'][$hour] ?? 0) + 1;
        $summary['by_ac_cat'][$acat] = ($summary['by_ac_cat'][$acat] ?? 0) + 1;
    }
}

// Sort summary arrays by count descending
foreach ($summary as $key => $arr) {
    arsort($summary[$key]);
}

// Sort by_hour chronologically
ksort($summary['by_hour']);

echo json_encode([
    'status'  => 'ok',
    'message' => 'GDP preview retrieved',
    'total'   => count($flights) + count($exempt_flights),
    'affected' => count($flights),
    'exempt'  => count($exempt_flights),
    'filters' => [
        'gdp_airport'      => $gdp_airport,
        'origin_airports'  => $origin_airports,
        'origin_centers'   => $origin_centers,
        'scope_codes'      => $scope_codes,
        'carriers'         => $carriers,
        'aircraft_filter'  => $gdp_flt_incl_type,
        'gdp_start_utc'    => $gdp_start,
        'gdp_end_utc'      => $gdp_end,
        'distance_nm'      => $distance_nm,
        'distance_airports_count' => count($origin_airports_by_distance)
    ],
    'summary' => $summary,
    'flights' => $flights,
    'exempt_flights' => $exempt_flights
], JSON_PRETTY_PRINT);
?>
