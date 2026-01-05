<?php
/**
 * api/tmi/rr_stats.php
 * 
 * GET - Get aggregate statistics for a reroute (Azure SQL)
 * 
 * Query params:
 *   id - Reroute ID (required)
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../load/connect.php';

try {
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing or invalid id parameter']);
        exit;
    }
    
    $id = intval($_GET['id']);
    
    // Basic statistics
    $sql = "SELECT 
            COUNT(*) as total_flights,
            SUM(CASE WHEN compliance_status = 'COMPLIANT' THEN 1 ELSE 0 END) as compliant,
            SUM(CASE WHEN compliance_status = 'PARTIAL' THEN 1 ELSE 0 END) as partial,
            SUM(CASE WHEN compliance_status = 'NON_COMPLIANT' THEN 1 ELSE 0 END) as non_compliant,
            SUM(CASE WHEN compliance_status = 'MONITORING' THEN 1 ELSE 0 END) as monitoring,
            SUM(CASE WHEN compliance_status = 'PENDING' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN compliance_status = 'EXEMPT' THEN 1 ELSE 0 END) as exempt,
            
            AVG(CAST(compliance_pct AS FLOAT)) as avg_compliance_pct,
            MIN(compliance_pct) as min_compliance_pct,
            MAX(compliance_pct) as max_compliance_pct,
            
            AVG(CAST(route_delta_nm AS FLOAT)) as avg_route_delta_nm,
            SUM(route_delta_nm) as total_route_delta_nm,
            AVG(CAST(ete_delta_min AS FLOAT)) as avg_ete_delta_min,
            SUM(ete_delta_min) as total_ete_delta_min,
            
            SUM(CASE WHEN departed_utc IS NOT NULL THEN 1 ELSE 0 END) as departed,
            SUM(CASE WHEN arrived_utc IS NOT NULL THEN 1 ELSE 0 END) as arrived
        FROM dbo.tmi_reroute_flights 
        WHERE reroute_id = ?";
    
    $stmt = sqlsrv_query($conn_adl, $sql, [$id]);
    
    if ($stmt === false) {
        throw new Exception('Query failed: ' . print_r(sqlsrv_errors(), true));
    }
    
    $stats = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    
    // Calculate rates
    $assessed = ($stats['compliant'] ?? 0) + ($stats['partial'] ?? 0) + ($stats['non_compliant'] ?? 0);
    $stats['compliance_rate'] = $assessed > 0 
        ? round(($stats['compliant'] / $assessed) * 100, 1) 
        : null;
    
    $stats['partial_rate'] = $assessed > 0
        ? round(($stats['partial'] / $assessed) * 100, 1)
        : null;
        
    $stats['non_compliant_rate'] = $assessed > 0
        ? round(($stats['non_compliant'] / $assessed) * 100, 1)
        : null;
    
    // Breakdown by origin
    $originSql = "SELECT 
            COALESCE(dep_icao, 'UNK') as origin,
            COUNT(*) as count,
            SUM(CASE WHEN compliance_status = 'COMPLIANT' THEN 1 ELSE 0 END) as compliant,
            SUM(CASE WHEN compliance_status = 'NON_COMPLIANT' THEN 1 ELSE 0 END) as non_compliant
        FROM dbo.tmi_reroute_flights 
        WHERE reroute_id = ?
        GROUP BY dep_icao
        ORDER BY COUNT(*) DESC
        OFFSET 0 ROWS FETCH NEXT 10 ROWS ONLY";
    
    $originStmt = sqlsrv_query($conn_adl, $originSql, [$id]);
    $byOrigin = [];
    if ($originStmt) {
        while ($row = sqlsrv_fetch_array($originStmt, SQLSRV_FETCH_ASSOC)) {
            $byOrigin[] = $row;
        }
        sqlsrv_free_stmt($originStmt);
    }
    
    // Breakdown by destination
    $destSql = "SELECT 
            COALESCE(dest_icao, 'UNK') as destination,
            COUNT(*) as count,
            SUM(CASE WHEN compliance_status = 'COMPLIANT' THEN 1 ELSE 0 END) as compliant,
            SUM(CASE WHEN compliance_status = 'NON_COMPLIANT' THEN 1 ELSE 0 END) as non_compliant
        FROM dbo.tmi_reroute_flights 
        WHERE reroute_id = ?
        GROUP BY dest_icao
        ORDER BY COUNT(*) DESC
        OFFSET 0 ROWS FETCH NEXT 10 ROWS ONLY";
    
    $destStmt = sqlsrv_query($conn_adl, $destSql, [$id]);
    $byDest = [];
    if ($destStmt) {
        while ($row = sqlsrv_fetch_array($destStmt, SQLSRV_FETCH_ASSOC)) {
            $byDest[] = $row;
        }
        sqlsrv_free_stmt($destStmt);
    }
    
    // Hourly breakdown (for timeline)
    $hourlySql = "SELECT 
            FORMAT(assigned_utc, 'yyyy-MM-dd HH:00:00') as hour,
            COUNT(*) as count,
            SUM(CASE WHEN compliance_status = 'COMPLIANT' THEN 1 ELSE 0 END) as compliant
        FROM dbo.tmi_reroute_flights 
        WHERE reroute_id = ? AND assigned_utc IS NOT NULL
        GROUP BY FORMAT(assigned_utc, 'yyyy-MM-dd HH:00:00')
        ORDER BY FORMAT(assigned_utc, 'yyyy-MM-dd HH:00:00')";
    
    $hourlyStmt = sqlsrv_query($conn_adl, $hourlySql, [$id]);
    $byHour = [];
    if ($hourlyStmt) {
        while ($row = sqlsrv_fetch_array($hourlyStmt, SQLSRV_FETCH_ASSOC)) {
            $byHour[] = $row;
        }
        sqlsrv_free_stmt($hourlyStmt);
    }
    
    echo json_encode([
        'status' => 'ok',
        'reroute_id' => $id,
        'statistics' => $stats,
        'by_origin' => $byOrigin,
        'by_destination' => $byDest,
        'by_hour' => $byHour
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
