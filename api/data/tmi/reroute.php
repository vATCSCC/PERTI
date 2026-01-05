<?php
/**
 * api/data/tmi/reroute.php
 * 
 * GET - Get a single reroute definition by ID (Azure SQL)
 * 
 * Query params:
 *   id - Reroute ID (required)
 *   include_flights - If "1", includes assigned flights
 *   include_stats - If "1", includes compliance statistics
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../../load/connect.php';

$STATUS_LABELS = [
    0 => 'Draft',
    1 => 'Proposed',
    2 => 'Active',
    3 => 'Monitoring',
    4 => 'Expired',
    5 => 'Cancelled'
];

try {
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing or invalid id parameter']);
        exit;
    }
    
    $id = intval($_GET['id']);
    
    // Fetch reroute definition
    $sql = "SELECT * FROM dbo.tmi_reroutes WHERE id = ?";
    $params = [$id];
    $stmt = sqlsrv_query($conn_adl, $sql, $params);
    
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
    
    // Convert DateTime objects
    foreach (['created_utc', 'updated_utc', 'activated_utc'] as $field) {
        if (isset($reroute[$field]) && $reroute[$field] instanceof DateTime) {
            $reroute[$field] = $reroute[$field]->format('Y-m-d H:i:s');
        }
    }
    $reroute['status_label'] = $STATUS_LABELS[$reroute['status']] ?? 'Unknown';
    
    // Include flights if requested
    if (isset($_GET['include_flights']) && $_GET['include_flights'] === '1') {
        $flightSql = "
            SELECT 
                id, flight_key, callsign, dep_icao, dest_icao, ac_type,
                filed_altitude, route_at_assign, current_route,
                compliance_status, compliance_pct, 
                protected_fixes_crossed, avoid_fixes_crossed,
                last_lat, last_lon, last_altitude, last_position_utc,
                assigned_utc, departed_utc, arrived_utc,
                route_delta_nm, ete_delta_min,
                manual_status
            FROM dbo.tmi_reroute_flights 
            WHERE reroute_id = ?
            ORDER BY 
                CASE compliance_status 
                    WHEN 'NON_COMPLIANT' THEN 0
                    WHEN 'PARTIAL' THEN 1
                    WHEN 'MONITORING' THEN 2
                    WHEN 'PENDING' THEN 3
                    WHEN 'COMPLIANT' THEN 4
                    ELSE 5 
                END,
                callsign ASC
        ";
        $flightStmt = sqlsrv_query($conn_adl, $flightSql, [$id]);
        
        $flights = [];
        if ($flightStmt) {
            while ($row = sqlsrv_fetch_array($flightStmt, SQLSRV_FETCH_ASSOC)) {
                // Convert DateTime objects
                foreach (['last_position_utc', 'assigned_utc', 'departed_utc', 'arrived_utc'] as $field) {
                    if (isset($row[$field]) && $row[$field] instanceof DateTime) {
                        $row[$field] = $row[$field]->format('Y-m-d H:i:s');
                    }
                }
                $flights[] = $row;
            }
            sqlsrv_free_stmt($flightStmt);
        }
        $reroute['flights'] = $flights;
    }
    
    // Include statistics if requested
    if (isset($_GET['include_stats']) && $_GET['include_stats'] === '1') {
        $statsSql = "
            SELECT 
                COUNT(*) as total_flights,
                SUM(CASE WHEN compliance_status = 'COMPLIANT' THEN 1 ELSE 0 END) as compliant,
                SUM(CASE WHEN compliance_status = 'PARTIAL' THEN 1 ELSE 0 END) as partial,
                SUM(CASE WHEN compliance_status = 'NON_COMPLIANT' THEN 1 ELSE 0 END) as non_compliant,
                SUM(CASE WHEN compliance_status = 'MONITORING' THEN 1 ELSE 0 END) as monitoring,
                SUM(CASE WHEN compliance_status = 'PENDING' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN compliance_status = 'EXEMPT' THEN 1 ELSE 0 END) as exempt,
                AVG(CAST(compliance_pct AS FLOAT)) as avg_compliance_pct,
                AVG(CAST(route_delta_nm AS FLOAT)) as avg_route_delta_nm,
                AVG(CAST(ete_delta_min AS FLOAT)) as avg_ete_delta_min,
                SUM(route_delta_nm) as total_route_delta_nm,
                SUM(ete_delta_min) as total_ete_delta_min
            FROM dbo.tmi_reroute_flights 
            WHERE reroute_id = ?
        ";
        $statsStmt = sqlsrv_query($conn_adl, $statsSql, [$id]);
        
        if ($statsStmt) {
            $stats = sqlsrv_fetch_array($statsStmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($statsStmt);
            
            // Calculate compliance percentage
            $assessed = $stats['compliant'] + $stats['partial'] + $stats['non_compliant'];
            $stats['compliance_rate'] = $assessed > 0 
                ? round(($stats['compliant'] / $assessed) * 100, 1) 
                : null;
            
            $reroute['statistics'] = $stats;
        }
    }
    
    echo json_encode([
        'status' => 'ok',
        'reroute' => $reroute
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
