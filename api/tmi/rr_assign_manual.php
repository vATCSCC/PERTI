<?php
/**
 * api/tmi/rr_assign_manual.php
 * 
 * POST - Manually add or remove specific flights from a reroute (Azure SQL)
 * 
 * POST (JSON body):
 *   reroute_id - Required
 *   action     - "add" or "remove"
 *   flights    - Array of flight objects (for add) or flight_keys (for remove)
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../load/connect.php';
require_once __DIR__ . '/../../sessions/handler.php';

if (!isset($_SESSION['VATSIM_CID']) && !defined('DEV')) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input']);
        exit;
    }
    
    if (!isset($input['reroute_id']) || !is_numeric($input['reroute_id'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing reroute_id']);
        exit;
    }
    
    if (!isset($input['action']) || !in_array($input['action'], ['add', 'remove'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Action must be "add" or "remove"']);
        exit;
    }
    
    if (!isset($input['flights']) || !is_array($input['flights']) || empty($input['flights'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Flights array required']);
        exit;
    }
    
    $rerouteId = intval($input['reroute_id']);
    $action = $input['action'];
    $flights = $input['flights'];
    
    // Verify reroute exists
    $checkSql = "SELECT id, status, protected_segment FROM dbo.tmi_reroutes WHERE id = ?";
    $checkStmt = sqlsrv_query($conn_adl, $checkSql, [$rerouteId]);
    $reroute = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($checkStmt);
    
    if (!$reroute) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Reroute not found']);
        exit;
    }
    
    $affected = 0;
    
    if ($action === 'add') {
        // Get existing flight_keys
        $existingSql = "SELECT flight_key FROM dbo.tmi_reroute_flights WHERE reroute_id = ?";
        $existingStmt = sqlsrv_query($conn_adl, $existingSql, [$rerouteId]);
        $existingKeys = [];
        while ($row = sqlsrv_fetch_array($existingStmt, SQLSRV_FETCH_ASSOC)) {
            $existingKeys[$row['flight_key']] = true;
        }
        sqlsrv_free_stmt($existingStmt);
        
        foreach ($flights as $flight) {
            // Can be either a flight object or just a flight_key string
            $flightKey = is_array($flight) ? ($flight['flight_key'] ?? null) : $flight;
            if (!$flightKey || isset($existingKeys[$flightKey])) continue;
            
            // If just a key, look up from ADL
            if (!is_array($flight)) {
                $adlSql = "SELECT flight_key, callsign, fp_dept_icao, fp_dest_icao, 
                                  aircraft_type, fp_altitude_ft, fp_route, gcd_nm, ete_minutes
                           FROM dbo.adl_flights WHERE flight_key = ?";
                $adlStmt = sqlsrv_query($conn_adl, $adlSql, [$flightKey]);
                $flight = sqlsrv_fetch_array($adlStmt, SQLSRV_FETCH_ASSOC);
                sqlsrv_free_stmt($adlStmt);
                
                if (!$flight) continue;
            }
            
            $insertSql = "INSERT INTO dbo.tmi_reroute_flights (
                reroute_id, flight_key, callsign,
                dep_icao, dest_icao, ac_type, filed_altitude,
                route_at_assign, assigned_route,
                compliance_status,
                route_distance_original_nm, ete_original_min,
                assigned_utc
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', ?, ?, GETUTCDATE())";
            
            $insertParams = [
                $rerouteId,
                $flight['flight_key'],
                $flight['callsign'] ?? '',
                $flight['fp_dept_icao'] ?? $flight['dep_icao'] ?? null,
                $flight['fp_dest_icao'] ?? $flight['dest_icao'] ?? null,
                $flight['aircraft_type'] ?? $flight['ac_type'] ?? null,
                $flight['fp_altitude_ft'] ?? $flight['filed_altitude'] ?? null,
                $flight['fp_route'] ?? $flight['route_at_assign'] ?? null,
                $reroute['protected_segment'],
                $flight['gcd_nm'] ?? null,
                $flight['ete_minutes'] ?? null
            ];
            
            $insertStmt = sqlsrv_query($conn_adl, $insertSql, $insertParams);
            if ($insertStmt !== false) {
                $affected++;
                sqlsrv_free_stmt($insertStmt);
            }
        }
        
    } else {
        // Remove
        foreach ($flights as $flightKey) {
            if (is_array($flightKey)) {
                $flightKey = $flightKey['flight_key'] ?? null;
            }
            if (!$flightKey) continue;
            
            $deleteSql = "DELETE FROM dbo.tmi_reroute_flights 
                          WHERE reroute_id = ? AND flight_key = ?";
            $deleteStmt = sqlsrv_query($conn_adl, $deleteSql, [$rerouteId, $flightKey]);
            if ($deleteStmt !== false) {
                $rows = sqlsrv_rows_affected($deleteStmt);
                $affected += $rows;
                sqlsrv_free_stmt($deleteStmt);
            }
        }
    }
    
    // Get new total
    $countSql = "SELECT COUNT(*) as cnt FROM dbo.tmi_reroute_flights WHERE reroute_id = ?";
    $countStmt = sqlsrv_query($conn_adl, $countSql, [$rerouteId]);
    $countRow = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
    $total = $countRow['cnt'] ?? 0;
    sqlsrv_free_stmt($countStmt);
    
    echo json_encode([
        'status' => 'ok',
        'action' => $action,
        'affected' => $affected,
        'total_flights' => $total
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
