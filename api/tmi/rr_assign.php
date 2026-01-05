<?php
/**
 * api/tmi/rr_assign.php
 * 
 * POST - Assign flights to a reroute (Azure SQL)
 * 
 * POST (JSON body):
 *   reroute_id - Required, the reroute to assign flights to
 *   flights    - Optional array of flight objects to assign
 *   mode       - "add" (default) or "replace" (clears existing first)
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../load/connect.php';
require_once __DIR__ . '/../../sessions/handler.php';

// Permission check
if (!isset($_SESSION['VATSIM_CID']) && !defined('DEV')) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

try {
    // Parse JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input']);
        exit;
    }
    
    if (!isset($input['reroute_id']) || !is_numeric($input['reroute_id'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing or invalid reroute_id']);
        exit;
    }
    
    $rerouteId = intval($input['reroute_id']);
    $mode = $input['mode'] ?? 'add';
    
    // Fetch reroute definition
    $sql = "SELECT * FROM dbo.tmi_reroutes WHERE id = ?";
    $stmt = sqlsrv_query($conn_adl, $sql, [$rerouteId]);
    
    if ($stmt === false) {
        throw new Exception('Query failed: ' . print_r(sqlsrv_errors(), true));
    }
    
    $reroute = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    
    if (!$reroute) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Reroute not found']);
        exit;
    }
    
    // Check reroute is in assignable state (active or monitoring)
    if (!in_array($reroute['status'], [2, 3])) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error', 
            'message' => 'Reroute must be Active or Monitoring to assign flights'
        ]);
        exit;
    }
    
    // If replace mode, clear existing assignments first
    if ($mode === 'replace') {
        $deleteSql = "DELETE FROM dbo.tmi_reroute_flights WHERE reroute_id = ?";
        $deleteStmt = sqlsrv_query($conn_adl, $deleteSql, [$rerouteId]);
        if ($deleteStmt) sqlsrv_free_stmt($deleteStmt);
    }
    
    // Get flights to assign
    $flightsToAssign = [];
    
    if (isset($input['flights']) && is_array($input['flights']) && !empty($input['flights'])) {
        $flightsToAssign = $input['flights'];
    } else {
        // Query ADL using reroute criteria
        $flightsToAssign = queryAdlForReroute($conn_adl, $reroute);
    }
    
    // Get existing flight_keys to avoid duplicates
    $existingSql = "SELECT flight_key FROM dbo.tmi_reroute_flights WHERE reroute_id = ?";
    $existingStmt = sqlsrv_query($conn_adl, $existingSql, [$rerouteId]);
    
    $existingKeys = [];
    if ($existingStmt) {
        while ($row = sqlsrv_fetch_array($existingStmt, SQLSRV_FETCH_ASSOC)) {
            $existingKeys[$row['flight_key']] = true;
        }
        sqlsrv_free_stmt($existingStmt);
    }
    
    $assigned = 0;
    $skipped = 0;
    $exempt = 0;
    
    foreach ($flightsToAssign as $flight) {
        $flightKey = $flight['flight_key'] ?? null;
        if (!$flightKey) continue;
        
        // Skip if already assigned
        if (isset($existingKeys[$flightKey])) {
            $skipped++;
            continue;
        }
        
        // Check if exempt
        $isExempt = $flight['is_exempt'] ?? false;
        $status = $isExempt ? 'EXEMPT' : 'PENDING';
        
        if ($isExempt) {
            $exempt++;
        }
        
        $callsign = $flight['callsign'] ?? '';
        $depIcao = $flight['fp_dept_icao'] ?? $flight['dep_icao'] ?? null;
        $destIcao = $flight['fp_dest_icao'] ?? $flight['dest_icao'] ?? null;
        $acType = $flight['aircraft_type'] ?? $flight['ac_type'] ?? null;
        $altitude = $flight['fp_altitude_ft'] ?? $flight['filed_altitude'] ?? null;
        $routeAtAssign = $flight['fp_route'] ?? $flight['route_at_assign'] ?? null;
        $assignedRoute = $reroute['protected_segment'];
        $gcdNm = $flight['gcd_nm'] ?? null;
        $eteMin = $flight['ete_minutes'] ?? null;
        
        $insertSql = "INSERT INTO dbo.tmi_reroute_flights (
            reroute_id, flight_key, callsign,
            dep_icao, dest_icao, ac_type, filed_altitude,
            route_at_assign, assigned_route,
            compliance_status,
            route_distance_original_nm, ete_original_min,
            assigned_utc
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETUTCDATE())";
        
        $insertParams = [
            $rerouteId, $flightKey, $callsign,
            $depIcao, $destIcao, $acType, $altitude,
            $routeAtAssign, $assignedRoute,
            $status,
            $gcdNm, $eteMin
        ];
        
        $insertStmt = sqlsrv_query($conn_adl, $insertSql, $insertParams);
        
        if ($insertStmt !== false) {
            $assigned++;
            $existingKeys[$flightKey] = true;
            sqlsrv_free_stmt($insertStmt);
        }
    }
    
    echo json_encode([
        'status' => 'ok',
        'reroute_id' => $rerouteId,
        'mode' => $mode,
        'assigned' => $assigned,
        'skipped_duplicates' => $skipped,
        'exempt' => $exempt,
        'total_now' => count($existingKeys)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}

/**
 * Query ADL for flights matching reroute criteria
 */
function queryAdlForReroute($conn_adl, $reroute) {
    $parseList = function($value) {
        if (empty($value)) return [];
        return array_map('trim', array_filter(explode(',', strtoupper($value))));
    };
    
    $where = ['is_active = 1'];
    $params = [];
    
    // Origin filters
    $originAirports = $parseList($reroute['origin_airports'] ?? '');
    $originCenters = $parseList($reroute['origin_centers'] ?? '');
    
    $originClauses = [];
    if (!empty($originAirports)) {
        $placeholders = implode(',', array_map(function($a) { return "'$a'"; }, $originAirports));
        $originClauses[] = "fp_dept_icao IN ($placeholders)";
    }
    if (!empty($originCenters)) {
        $placeholders = implode(',', array_map(function($a) { return "'$a'"; }, $originCenters));
        $originClauses[] = "fp_dept_artcc IN ($placeholders)";
    }
    
    if (!empty($originClauses)) {
        $where[] = '(' . implode(' OR ', $originClauses) . ')';
    }
    
    // Destination filters
    $destAirports = $parseList($reroute['dest_airports'] ?? '');
    $destCenters = $parseList($reroute['dest_centers'] ?? '');
    
    $destClauses = [];
    if (!empty($destAirports)) {
        $placeholders = implode(',', array_map(function($a) { return "'$a'"; }, $destAirports));
        $destClauses[] = "fp_dest_icao IN ($placeholders)";
    }
    if (!empty($destCenters)) {
        $placeholders = implode(',', array_map(function($a) { return "'$a'"; }, $destCenters));
        $destClauses[] = "fp_dest_artcc IN ($placeholders)";
    }
    
    if (!empty($destClauses)) {
        $where[] = '(' . implode(' OR ', $destClauses) . ')';
    }
    
    // Aircraft category
    $acCat = strtoupper(trim($reroute['include_ac_cat'] ?? 'ALL'));
    if ($acCat !== 'ALL' && $acCat !== '') {
        $cats = $parseList($acCat);
        if (!empty($cats)) {
            $placeholders = implode(',', array_map(function($a) { return "'$a'"; }, $cats));
            $where[] = "ac_cat IN ($placeholders)";
        }
    }
    
    // Airborne filter
    $airborneFilter = strtoupper(trim($reroute['airborne_filter'] ?? 'NOT_AIRBORNE'));
    if ($airborneFilter === 'AIRBORNE') {
        $where[] = "phase = 'AIRBORNE'";
    } elseif ($airborneFilter === 'NOT_AIRBORNE') {
        $where[] = "(phase IS NULL OR phase != 'AIRBORNE')";
    }
    
    // Time window
    $timeBasis = strtoupper(trim($reroute['time_basis'] ?? 'ETD'));
    $timeColumn = $timeBasis === 'ETA' ? 'eta_runway_utc' : 'etd_runway_utc';
    
    $startUtc = trim($reroute['start_utc'] ?? '');
    $endUtc = trim($reroute['end_utc'] ?? '');
    
    if (!empty($startUtc)) {
        $where[] = "$timeColumn >= ?";
        $params[] = $startUtc;
    }
    if (!empty($endUtc)) {
        $where[] = "$timeColumn <= ?";
        $params[] = $endUtc;
    }
    
    // Build exemption lists
    $exemptAirports = $parseList($reroute['exempt_airports'] ?? '');
    $exemptCarriers = $parseList($reroute['exempt_carriers'] ?? '');
    $exemptFlights = $parseList($reroute['exempt_flights'] ?? '');
    
    // Query
    $sql = "SELECT 
                flight_key, callsign, 
                fp_dept_icao, fp_dest_icao, 
                fp_dept_artcc, fp_dest_artcc,
                ac_cat, aircraft_type, major_carrier,
                fp_altitude_ft, fp_route,
                ete_minutes, gcd_nm, phase
            FROM dbo.adl_flights
            WHERE " . implode(' AND ', $where);
    
    $stmt = sqlsrv_query($conn_adl, $sql, $params);
    
    if ($stmt === false) {
        return [];
    }
    
    $flights = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Check exemptions
        $isExempt = false;
        if (in_array($row['fp_dept_icao'], $exemptAirports)) {
            $isExempt = true;
        } elseif (in_array($row['major_carrier'], $exemptCarriers)) {
            $isExempt = true;
        } elseif (in_array(strtoupper($row['callsign']), $exemptFlights)) {
            $isExempt = true;
        }
        
        $row['is_exempt'] = $isExempt;
        $flights[] = $row;
    }
    
    sqlsrv_free_stmt($stmt);
    return $flights;
}
