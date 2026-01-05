<?php
/**
 * api/tmi/rr_export.php
 * 
 * GET - Export reroute data as CSV or JSON (Azure SQL)
 * 
 * Query params:
 *   id     - Reroute ID (required)
 *   format - "csv" or "json" (default: json)
 *   type   - "flights", "summary", "history" (default: flights)
 */

require_once __DIR__ . '/../../load/connect.php';

try {
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Missing id parameter']);
        exit;
    }
    
    $id = intval($_GET['id']);
    $format = strtolower($_GET['format'] ?? 'json');
    $type = strtolower($_GET['type'] ?? 'flights');
    
    // Fetch reroute info
    $rerouteSql = "SELECT * FROM dbo.tmi_reroutes WHERE id = ?";
    $rerouteStmt = sqlsrv_query($conn_adl, $rerouteSql, [$id]);
    $reroute = sqlsrv_fetch_array($rerouteStmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($rerouteStmt);
    
    if (!$reroute) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Reroute not found']);
        exit;
    }
    
    $data = [];
    $filename = 'reroute_' . $id . '_' . $type . '_' . date('Ymd_His');
    
    if ($type === 'flights') {
        $sql = "SELECT 
                f.callsign, f.dep_icao, f.dest_icao, f.ac_type, f.filed_altitude,
                f.compliance_status, f.compliance_pct,
                f.route_at_assign, f.current_route,
                f.protected_fixes_crossed, f.avoid_fixes_crossed,
                f.route_delta_nm, f.ete_delta_min,
                f.assigned_utc, f.departed_utc, f.arrived_utc,
                f.manual_status, f.override_reason
            FROM dbo.tmi_reroute_flights f
            WHERE f.reroute_id = ?
            ORDER BY f.callsign";
        
        $stmt = sqlsrv_query($conn_adl, $sql, [$id]);
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Convert DateTime objects
            foreach (['assigned_utc', 'departed_utc', 'arrived_utc'] as $field) {
                if (isset($row[$field]) && $row[$field] instanceof DateTime) {
                    $row[$field] = $row[$field]->format('Y-m-d H:i:s');
                }
            }
            $data[] = $row;
        }
        sqlsrv_free_stmt($stmt);
        
    } elseif ($type === 'summary') {
        // Get statistics
        $statsSql = "SELECT 
                COUNT(*) as total_flights,
                SUM(CASE WHEN compliance_status = 'COMPLIANT' THEN 1 ELSE 0 END) as compliant,
                SUM(CASE WHEN compliance_status = 'PARTIAL' THEN 1 ELSE 0 END) as partial,
                SUM(CASE WHEN compliance_status = 'NON_COMPLIANT' THEN 1 ELSE 0 END) as non_compliant,
                SUM(CASE WHEN compliance_status = 'MONITORING' THEN 1 ELSE 0 END) as monitoring,
                SUM(CASE WHEN compliance_status = 'PENDING' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN compliance_status = 'EXEMPT' THEN 1 ELSE 0 END) as exempt,
                AVG(CAST(compliance_pct AS FLOAT)) as avg_compliance_pct,
                AVG(CAST(route_delta_nm AS FLOAT)) as avg_route_delta_nm,
                AVG(CAST(ete_delta_min AS FLOAT)) as avg_ete_delta_min
            FROM dbo.tmi_reroute_flights WHERE reroute_id = ?";
        
        $statsStmt = sqlsrv_query($conn_adl, $statsSql, [$id]);
        $stats = sqlsrv_fetch_array($statsStmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($statsStmt);
        
        // Convert DateTime in reroute
        foreach (['created_utc', 'updated_utc', 'activated_utc'] as $field) {
            if (isset($reroute[$field]) && $reroute[$field] instanceof DateTime) {
                $reroute[$field] = $reroute[$field]->format('Y-m-d H:i:s');
            }
        }
        
        $data = [
            'reroute' => $reroute,
            'statistics' => $stats
        ];
        
    } elseif ($type === 'history') {
        $sql = "SELECT 
                f.callsign, 
                h.snapshot_utc, h.compliance_status, h.compliance_pct,
                h.lat, h.lon, h.altitude, h.route_string, h.fixes_crossed
            FROM dbo.tmi_reroute_compliance_log h
            JOIN dbo.tmi_reroute_flights f ON h.reroute_flight_id = f.id
            WHERE f.reroute_id = ?
            ORDER BY h.snapshot_utc DESC";
        
        $stmt = sqlsrv_query($conn_adl, $sql, [$id]);
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if (isset($row['snapshot_utc']) && $row['snapshot_utc'] instanceof DateTime) {
                $row['snapshot_utc'] = $row['snapshot_utc']->format('Y-m-d H:i:s');
            }
            $data[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
    
    // Output based on format
    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        if ($type === 'summary') {
            // Summary as key-value pairs
            fputcsv($output, ['Field', 'Value']);
            foreach ($data['reroute'] as $key => $value) {
                fputcsv($output, ['reroute_' . $key, $value]);
            }
            foreach ($data['statistics'] as $key => $value) {
                fputcsv($output, ['stat_' . $key, $value]);
            }
        } else {
            // Flights/History as table
            if (!empty($data)) {
                fputcsv($output, array_keys($data[0]));
                foreach ($data as $row) {
                    fputcsv($output, $row);
                }
            }
        }
        
        fclose($output);
        
    } else {
        header('Content-Type: application/json');
        if (isset($_GET['download'])) {
            header('Content-Disposition: attachment; filename="' . $filename . '.json"');
        }
        
        echo json_encode([
            'status' => 'ok',
            'reroute_id' => $id,
            'reroute_name' => $reroute['name'],
            'export_type' => $type,
            'export_time' => date('Y-m-d H:i:s'),
            'record_count' => is_array($data) && isset($data[0]) ? count($data) : 1,
            'data' => $data
        ], JSON_PRETTY_PRINT);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
