<?php
/**
 * api/tmi/rr_compliance_refresh.php
 * 
 * POST - Refresh compliance status for flights in a reroute (Azure SQL)
 * 
 * POST (JSON body):
 *   reroute_id - Required, the reroute to refresh
 *   flight_ids - Optional array of specific flight record IDs to refresh
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../load/connect.php';
require_once __DIR__ . '/../../sessions/handler.php';

try {
    // Parse JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    if (!isset($input['reroute_id']) || !is_numeric($input['reroute_id'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing or invalid reroute_id']);
        exit;
    }
    
    $rerouteId = intval($input['reroute_id']);
    $specificIds = isset($input['flight_ids']) ? $input['flight_ids'] : null;
    
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
    
    // Parse protected and avoid fixes
    $protectedFixes = parseFixList($reroute['protected_fixes']);
    $avoidFixes = parseFixList($reroute['avoid_fixes']);
    
    // Fetch tracked flights
    $flightSql = "SELECT * FROM dbo.tmi_reroute_flights WHERE reroute_id = ?";
    if (!$specificIds) {
        // Exclude already completed/exempt flights unless specifically requested
        $flightSql .= " AND compliance_status NOT IN ('COMPLIANT', 'EXEMPT') 
                        AND arrived_utc IS NULL";
    }
    
    $flightStmt = sqlsrv_query($conn_adl, $flightSql, [$rerouteId]);
    
    if ($flightStmt === false) {
        throw new Exception('Query failed: ' . print_r(sqlsrv_errors(), true));
    }
    
    $trackedFlights = [];
    $flightKeys = [];
    while ($row = sqlsrv_fetch_array($flightStmt, SQLSRV_FETCH_ASSOC)) {
        // Filter by specific IDs if provided
        if ($specificIds && !in_array($row['id'], $specificIds)) {
            continue;
        }
        $trackedFlights[$row['flight_key']] = $row;
        $flightKeys[] = $row['flight_key'];
    }
    sqlsrv_free_stmt($flightStmt);
    
    if (empty($flightKeys)) {
        echo json_encode([
            'status' => 'ok',
            'message' => 'No flights to refresh',
            'updated' => 0
        ]);
        exit;
    }
    
    // Query current ADL data for these flights
    $placeholders = implode(',', array_map(function($k) { return "'$k'"; }, $flightKeys));
    $adlSql = "SELECT 
                   flight_key, callsign, phase,
                   fp_route, dfix, afix,
                   lat, lon, altitude_ft,
                   eta_runway_utc, on_utc, in_utc
               FROM dbo.adl_flights 
               WHERE flight_key IN ($placeholders)";
    
    $adlStmt = sqlsrv_query($conn_adl, $adlSql);
    
    if ($adlStmt === false) {
        throw new Exception('ADL query failed: ' . print_r(sqlsrv_errors(), true));
    }
    
    $adlData = [];
    while ($row = sqlsrv_fetch_array($adlStmt, SQLSRV_FETCH_ASSOC)) {
        $adlData[$row['flight_key']] = $row;
    }
    sqlsrv_free_stmt($adlStmt);
    
    $updated = 0;
    $results = [];
    
    foreach ($trackedFlights as $flightKey => $tracked) {
        $adl = $adlData[$flightKey] ?? null;
        
        if (!$adl) {
            // Flight no longer in ADL - might have completed or been removed
            if (!in_array($tracked['compliance_status'], ['COMPLIANT', 'NON_COMPLIANT', 'EXEMPT'])) {
                $updateSql = "UPDATE dbo.tmi_reroute_flights 
                              SET compliance_status = 'UNKNOWN', updated_utc = GETUTCDATE()
                              WHERE id = ?";
                $updateStmt = sqlsrv_query($conn_adl, $updateSql, [$tracked['id']]);
                if ($updateStmt) {
                    sqlsrv_free_stmt($updateStmt);
                    $updated++;
                }
            }
            continue;
        }
        
        // Calculate compliance
        $currentRoute = $adl['fp_route'] ?? '';
        $routeFixes = parseRouteToFixes($currentRoute);
        
        $compliance = calculateCompliance(
            $protectedFixes, 
            $avoidFixes, 
            $routeFixes,
            $adl['phase']
        );
        
        // Check if flight has arrived
        $arrivedUtc = null;
        if (isset($adl['in_utc']) && $adl['in_utc'] instanceof DateTime) {
            $arrivedUtc = $adl['in_utc']->format('Y-m-d H:i:s');
        } elseif (isset($adl['on_utc']) && $adl['on_utc'] instanceof DateTime) {
            $arrivedUtc = $adl['on_utc']->format('Y-m-d H:i:s');
        }
        
        // Check if departed
        $departedUtc = null;
        if ($adl['phase'] === 'AIRBORNE' && !$tracked['departed_utc']) {
            $departedUtc = date('Y-m-d H:i:s');
        }
        
        // Update record
        $lat = $adl['lat'];
        $lon = $adl['lon'];
        $alt = $adl['altitude_ft'];
        $protectedCrossed = implode(',', $compliance['protected_crossed']);
        $avoidCrossed = implode(',', $compliance['avoid_crossed']);
        
        $updateSql = "UPDATE dbo.tmi_reroute_flights SET
            current_route = ?,
            current_route_utc = GETUTCDATE(),
            last_lat = ?,
            last_lon = ?,
            last_altitude = ?,
            last_position_utc = GETUTCDATE(),
            compliance_status = ?,
            compliance_pct = ?,
            protected_fixes_crossed = ?,
            avoid_fixes_crossed = ?" .
            ($departedUtc ? ", departed_utc = COALESCE(departed_utc, '$departedUtc')" : "") .
            ($arrivedUtc ? ", arrived_utc = '$arrivedUtc'" : "") .
            " WHERE id = ?";
        
        $updateParams = [
            $currentRoute,
            $lat,
            $lon,
            $alt,
            $compliance['status'],
            $compliance['pct'],
            $protectedCrossed,
            $avoidCrossed,
            $tracked['id']
        ];
        
        $updateStmt = sqlsrv_query($conn_adl, $updateSql, $updateParams);
        
        if ($updateStmt !== false) {
            sqlsrv_free_stmt($updateStmt);
            
            // Log compliance snapshot
            $logSql = "INSERT INTO dbo.tmi_reroute_compliance_log 
                (reroute_flight_id, compliance_status, compliance_pct, 
                 lat, lon, altitude, route_string, fixes_crossed, snapshot_utc)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, GETUTCDATE())";
            
            $logParams = [
                $tracked['id'],
                $compliance['status'],
                $compliance['pct'],
                $lat,
                $lon,
                $alt,
                $currentRoute,
                $protectedCrossed
            ];
            
            $logStmt = sqlsrv_query($conn_adl, $logSql, $logParams);
            if ($logStmt) sqlsrv_free_stmt($logStmt);
            
            $updated++;
            
            $results[] = [
                'flight_key' => $flightKey,
                'callsign' => $tracked['callsign'],
                'old_status' => $tracked['compliance_status'],
                'new_status' => $compliance['status'],
                'compliance_pct' => $compliance['pct']
            ];
        }
    }
    
    // Get updated statistics
    $statsSql = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN compliance_status = 'COMPLIANT' THEN 1 ELSE 0 END) as compliant,
            SUM(CASE WHEN compliance_status = 'PARTIAL' THEN 1 ELSE 0 END) as partial,
            SUM(CASE WHEN compliance_status = 'NON_COMPLIANT' THEN 1 ELSE 0 END) as non_compliant,
            SUM(CASE WHEN compliance_status = 'MONITORING' THEN 1 ELSE 0 END) as monitoring,
            SUM(CASE WHEN compliance_status = 'PENDING' THEN 1 ELSE 0 END) as pending,
            AVG(CAST(compliance_pct AS FLOAT)) as avg_compliance_pct
        FROM dbo.tmi_reroute_flights WHERE reroute_id = ?";
    
    $statsStmt = sqlsrv_query($conn_adl, $statsSql, [$rerouteId]);
    $stats = null;
    if ($statsStmt) {
        $stats = sqlsrv_fetch_array($statsStmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($statsStmt);
    }
    
    echo json_encode([
        'status' => 'ok',
        'reroute_id' => $rerouteId,
        'updated' => $updated,
        'statistics' => $stats,
        'flights' => $results
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}

/**
 * Parse comma-separated fix list
 */
function parseFixList($fixString) {
    if (empty($fixString)) return [];
    return array_map('trim', array_filter(explode(',', strtoupper($fixString))));
}

/**
 * Parse route string into array of fixes
 */
function parseRouteToFixes($routeString) {
    if (empty($routeString)) return [];
    
    $parts = preg_split('/\s+/', strtoupper($routeString));
    
    $fixes = [];
    foreach ($parts as $part) {
        if (in_array($part, ['DCT', 'DIRECT', '..'])) continue;
        if (preg_match('/^[VJQT]\d+$/', $part)) continue;
        if (preg_match('/^[FA]\d{3,}$/', $part)) continue;
        
        if (preg_match('/^[A-Z]{2,5}$/', $part) || preg_match('/^[A-Z]{3,5}\d*$/', $part)) {
            $fixes[] = $part;
        }
    }
    
    return $fixes;
}

/**
 * Calculate compliance based on protected/avoid fixes vs current route
 */
function calculateCompliance($protectedFixes, $avoidFixes, $routeFixes, $phase) {
    if (empty($protectedFixes)) {
        return [
            'status' => 'COMPLIANT',
            'pct' => 100.0,
            'protected_crossed' => [],
            'avoid_crossed' => []
        ];
    }
    
    $protectedCrossed = array_intersect($protectedFixes, $routeFixes);
    $protectedPct = (count($protectedCrossed) / count($protectedFixes)) * 100;
    
    $avoidCrossed = array_intersect($avoidFixes, $routeFixes);
    $avoidPenalty = count($avoidCrossed) * 30;
    
    $finalPct = max(0, $protectedPct - $avoidPenalty);
    
    $status = 'MONITORING';
    
    if ($phase === 'AIRBORNE') {
        if ($finalPct >= 100 && count($avoidCrossed) === 0) {
            $status = 'COMPLIANT';
        } elseif ($finalPct >= 50) {
            $status = 'PARTIAL';
        } else {
            $status = 'MONITORING';
        }
    } else {
        $status = 'PENDING';
    }
    
    return [
        'status' => $status,
        'pct' => round($finalPct, 1),
        'protected_crossed' => array_values($protectedCrossed),
        'avoid_crossed' => array_values($avoidCrossed)
    ];
}
