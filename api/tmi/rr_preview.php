<?php
/**
 * api/tmi/rr_preview.php
 * 
 * POST - Preview flights matching reroute criteria (Azure SQL)
 * 
 * This endpoint queries the ADL and returns flights that would be
 * affected by a reroute with the given criteria. Read-only operation.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../load/connect.php';

try {
    // Parse JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    // Helper function to parse comma-separated values
    function parseList($value) {
        if (empty($value)) return [];
        return array_map('trim', array_filter(explode(',', strtoupper($value))));
    }
    
    // Build WHERE clauses for ADL query
    $where = ['is_active = 1'];
    $params = [];
    
    // Origin filters
    $originAirports = parseList($input['origin_airports'] ?? '');
    $originCenters = parseList($input['origin_centers'] ?? '');
    $originTracons = parseList($input['origin_tracons'] ?? '');
    
    $originClauses = [];
    if (!empty($originAirports)) {
        $placeholders = implode(',', array_map(function($a) { return "'$a'"; }, $originAirports));
        $originClauses[] = "fp_dept_icao IN ($placeholders)";
    }
    if (!empty($originCenters)) {
        $placeholders = implode(',', array_map(function($a) { return "'$a'"; }, $originCenters));
        $originClauses[] = "fp_dept_artcc IN ($placeholders)";
    }
    if (!empty($originTracons)) {
        $placeholders = implode(',', array_map(function($a) { return "'$a'"; }, $originTracons));
        $originClauses[] = "fp_dept_tracon IN ($placeholders)";
    }
    
    if (!empty($originClauses)) {
        $where[] = '(' . implode(' OR ', $originClauses) . ')';
    }
    
    // Destination filters
    $destAirports = parseList($input['dest_airports'] ?? '');
    $destCenters = parseList($input['dest_centers'] ?? '');
    $destTracons = parseList($input['dest_tracons'] ?? '');
    
    $destClauses = [];
    if (!empty($destAirports)) {
        $placeholders = implode(',', array_map(function($a) { return "'$a'"; }, $destAirports));
        $destClauses[] = "fp_dest_icao IN ($placeholders)";
    }
    if (!empty($destCenters)) {
        $placeholders = implode(',', array_map(function($a) { return "'$a'"; }, $destCenters));
        $destClauses[] = "fp_dest_artcc IN ($placeholders)";
    }
    if (!empty($destTracons)) {
        $placeholders = implode(',', array_map(function($a) { return "'$a'"; }, $destTracons));
        $destClauses[] = "fp_dest_tracon IN ($placeholders)";
    }
    
    if (!empty($destClauses)) {
        $where[] = '(' . implode(' OR ', $destClauses) . ')';
    }
    
    // Fix filters
    $departureFix = trim($input['departure_fix'] ?? '');
    if (!empty($departureFix)) {
        $where[] = "dfix = ?";
        $params[] = strtoupper($departureFix);
    }
    
    $arrivalFix = trim($input['arrival_fix'] ?? '');
    if (!empty($arrivalFix)) {
        $where[] = "afix = ?";
        $params[] = strtoupper($arrivalFix);
    }
    
    // Thru fixes (check route string contains fix)
    $thruFixes = parseList($input['thru_fixes'] ?? '');
    foreach ($thruFixes as $fix) {
        $where[] = "(fp_route LIKE ? OR fp_route LIKE ? OR fp_route LIKE ?)";
        $params[] = $fix . ' %';
        $params[] = '% ' . $fix . ' %';
        $params[] = '% ' . $fix;
    }
    
    // Aircraft category
    $acCat = strtoupper(trim($input['include_ac_cat'] ?? 'ALL'));
    if ($acCat !== 'ALL' && $acCat !== '') {
        $cats = parseList($acCat);
        if (!empty($cats)) {
            $placeholders = implode(',', array_map(function($a) { return "'$a'"; }, $cats));
            $where[] = "ac_cat IN ($placeholders)";
        }
    }
    
    // Carriers
    $carriers = parseList($input['include_carriers'] ?? '');
    if (!empty($carriers) && $carriers[0] !== 'ALL') {
        $placeholders = implode(',', array_map(function($a) { return "'$a'"; }, $carriers));
        $where[] = "major_carrier IN ($placeholders)";
    }
    
    // Altitude
    $altMin = isset($input['altitude_min']) && $input['altitude_min'] !== '' 
        ? intval($input['altitude_min']) : null;
    $altMax = isset($input['altitude_max']) && $input['altitude_max'] !== '' 
        ? intval($input['altitude_max']) : null;
    
    if ($altMin !== null) {
        $where[] = "fp_altitude_ft >= " . ($altMin * 100);
    }
    if ($altMax !== null) {
        $where[] = "fp_altitude_ft <= " . ($altMax * 100);
    }
    
    // Airborne filter
    $airborneFilter = strtoupper(trim($input['airborne_filter'] ?? 'NOT_AIRBORNE'));
    if ($airborneFilter === 'AIRBORNE') {
        $where[] = "phase = 'AIRBORNE'";
    } elseif ($airborneFilter === 'NOT_AIRBORNE') {
        $where[] = "(phase IS NULL OR phase != 'AIRBORNE')";
    }
    
    // Time window
    $timeBasis = strtoupper(trim($input['time_basis'] ?? 'ETD'));
    $timeColumn = $timeBasis === 'ETA' ? 'eta_runway_utc' : 'etd_runway_utc';
    
    $startUtc = trim($input['start_utc'] ?? '');
    $endUtc = trim($input['end_utc'] ?? '');
    
    if (!empty($startUtc)) {
        $where[] = "$timeColumn >= ?";
        $params[] = $startUtc;
    }
    if (!empty($endUtc)) {
        $where[] = "$timeColumn <= ?";
        $params[] = $endUtc;
    }
    
    // Build exempt list for post-processing
    $exemptAirports = parseList($input['exempt_airports'] ?? '');
    $exemptCarriers = parseList($input['exempt_carriers'] ?? '');
    $exemptFlights = parseList($input['exempt_flights'] ?? '');
    
    // Build query
    $sql = "SELECT 
                flight_key, callsign, 
                fp_dept_icao, fp_dest_icao, 
                fp_dept_artcc, fp_dest_artcc,
                fp_dept_tracon, fp_dest_tracon,
                ac_cat, aircraft_type, major_carrier, weight_class,
                fp_altitude_ft, fp_route,
                dfix, afix, dp_name, star_name,
                etd_prefix, etd_runway_utc,
                eta_prefix, eta_runway_utc,
                ete_minutes, gcd_nm,
                lat, lon, altitude_ft, groundspeed_kts,
                phase
            FROM dbo.adl_flights
            WHERE " . implode(' AND ', $where) . "
            ORDER BY $timeColumn ASC";
    
    // Execute
    $stmt = sqlsrv_query($conn_adl, $sql, $params);
    
    if ($stmt === false) {
        throw new Exception('Query failed: ' . print_r(sqlsrv_errors(), true));
    }
    
    $flights = [];
    $exemptCount = 0;
    
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Check exemptions
        $isExempt = false;
        $exemptReason = null;
        
        if (in_array($row['fp_dept_icao'], $exemptAirports)) {
            $isExempt = true;
            $exemptReason = 'Origin airport exempt';
        } elseif (in_array($row['major_carrier'], $exemptCarriers)) {
            $isExempt = true;
            $exemptReason = 'Carrier exempt';
        } elseif (in_array(strtoupper($row['callsign']), $exemptFlights)) {
            $isExempt = true;
            $exemptReason = 'Flight explicitly exempt';
        }
        
        if ($isExempt) {
            $exemptCount++;
            $row['is_exempt'] = true;
            $row['exempt_reason'] = $exemptReason;
        } else {
            $row['is_exempt'] = false;
        }
        
        // Format datetime fields
        foreach (['etd_runway_utc', 'eta_runway_utc'] as $field) {
            if (isset($row[$field]) && $row[$field] instanceof DateTime) {
                $row[$field] = $row[$field]->format('Y-m-d H:i:s');
            }
        }
        
        $flights[] = $row;
    }
    
    sqlsrv_free_stmt($stmt);
    
    // Summary statistics
    $summary = [
        'total' => count($flights),
        'affected' => count($flights) - $exemptCount,
        'exempt' => $exemptCount,
        'by_origin_center' => [],
        'by_dest_center' => [],
        'by_carrier' => [],
        'by_ac_cat' => []
    ];
    
    foreach ($flights as $f) {
        if (!$f['is_exempt']) {
            $oc = $f['fp_dept_artcc'] ?? 'UNK';
            $dc = $f['fp_dest_artcc'] ?? 'UNK';
            $carr = $f['major_carrier'] ?? 'UNK';
            $cat = $f['ac_cat'] ?? 'UNK';
            
            $summary['by_origin_center'][$oc] = ($summary['by_origin_center'][$oc] ?? 0) + 1;
            $summary['by_dest_center'][$dc] = ($summary['by_dest_center'][$dc] ?? 0) + 1;
            $summary['by_carrier'][$carr] = ($summary['by_carrier'][$carr] ?? 0) + 1;
            $summary['by_ac_cat'][$cat] = ($summary['by_ac_cat'][$cat] ?? 0) + 1;
        }
    }
    
    echo json_encode([
        'status' => 'ok',
        'summary' => $summary,
        'filters' => [
            'origin_airports' => $originAirports,
            'origin_centers' => $originCenters,
            'dest_airports' => $destAirports,
            'dest_centers' => $destCenters,
            'departure_fix' => $departureFix,
            'arrival_fix' => $arrivalFix,
            'thru_fixes' => $thruFixes,
            'ac_cat' => $acCat,
            'carriers' => $carriers,
            'altitude_min' => $altMin,
            'altitude_max' => $altMax,
            'airborne_filter' => $airborneFilter,
            'time_basis' => $timeBasis,
            'start_utc' => $startUtc,
            'end_utc' => $endUtc
        ],
        'flights' => $flights
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
