<?php
/**
 * api/adl/snapshot_history.php
 * 
 * POST - Trigger ADL history snapshot (Azure SQL)
 * 
 * POST params:
 *   source      - Source identifier (default: 'ADL')
 *   flight_keys - Optional comma-separated list of specific flights
 *   active_only - Whether to only snapshot active flights (default: 1)
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../load/connect.php';
require_once __DIR__ . '/../../sessions/handler.php';

// Require authentication for manual snapshots
if (!isset($_SESSION['VATSIM_CID']) && !defined('DEV')) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

try {
    $source = $_POST['source'] ?? 'ADL';
    $flightKeys = isset($_POST['flight_keys']) ? trim($_POST['flight_keys']) : null;
    $activeOnly = isset($_POST['active_only']) ? intval($_POST['active_only']) : 1;
    
    // Check if stored procedure exists
    $checkSql = "SELECT COUNT(*) as cnt FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.sp_snapshot_adl_to_history') AND type = 'P'";
    $checkStmt = sqlsrv_query($conn_adl, $checkSql);
    $checkRow = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($checkStmt);
    
    if (!$checkRow || $checkRow['cnt'] == 0) {
        // Stored procedure doesn't exist - do inline insert instead
        $snapshotTime = gmdate('Y-m-d H:i:s');
        
        if ($flightKeys) {
            // Specific flights
            $keys = array_map('trim', explode(',', $flightKeys));
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            
            $sql = "INSERT INTO dbo.adl_flights_history (
                flight_key, callsign, cid,
                lat, lon, altitude_ft, groundspeed_kts, heading,
                fp_dept_icao, fp_dest_icao, fp_route, fp_altitude_ft, aircraft_type,
                etd_runway_utc, eta_runway_utc, ctd_utc, cta_utc,
                ctl_type, ctl_element, delay_status, phase,
                snapshot_utc, snapshot_source
            )
            SELECT 
                flight_key, callsign, cid,
                lat, lon, altitude_ft, groundspeed_kts, heading,
                fp_dept_icao, fp_dest_icao, fp_route, fp_altitude_ft, aircraft_type,
                etd_runway_utc, eta_runway_utc, ctd_utc, cta_utc,
                ctl_type, ctl_element, delay_status, phase,
                ?, ?
            FROM dbo.adl_flights
            WHERE flight_key IN ($placeholders)";
            
            $params = array_merge([$snapshotTime, $source], $keys);
        } else {
            // All active flights
            $sql = "INSERT INTO dbo.adl_flights_history (
                flight_key, callsign, cid,
                lat, lon, altitude_ft, groundspeed_kts, heading,
                fp_dept_icao, fp_dest_icao, fp_route, fp_altitude_ft, aircraft_type,
                etd_runway_utc, eta_runway_utc, ctd_utc, cta_utc,
                ctl_type, ctl_element, delay_status, phase,
                snapshot_utc, snapshot_source
            )
            SELECT 
                flight_key, callsign, cid,
                lat, lon, altitude_ft, groundspeed_kts, heading,
                fp_dept_icao, fp_dest_icao, fp_route, fp_altitude_ft, aircraft_type,
                etd_runway_utc, eta_runway_utc, ctd_utc, cta_utc,
                ctl_type, ctl_element, delay_status, phase,
                ?, ?
            FROM dbo.adl_flights
            WHERE is_active = 1 OR ? = 0";
            
            $params = [$snapshotTime, $source, $activeOnly];
        }
        
        $stmt = sqlsrv_query($conn_adl, $sql, $params);
        
        if ($stmt === false) {
            // History table might not exist
            $errors = sqlsrv_errors();
            if (strpos(print_r($errors, true), 'Invalid object name') !== false) {
                echo json_encode([
                    'status' => 'error', 
                    'message' => 'History table not found. Run 002_adl_history_stored_procedure.sql first.',
                    'sql_errors' => $errors
                ]);
                exit;
            }
            throw new Exception('Query failed: ' . print_r($errors, true));
        }
        
        $rowsInserted = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);
        
        echo json_encode([
            'status' => 'ok',
            'method' => 'inline',
            'rows_inserted' => $rowsInserted,
            'snapshot_utc' => $snapshotTime,
            'source' => $source
        ]);
        
    } else {
        // Use stored procedure
        $sql = "EXEC dbo.sp_snapshot_adl_to_history @source = ?, @flight_keys = ?, @active_only = ?";
        $params = [$source, $flightKeys, $activeOnly];
        
        $stmt = sqlsrv_query($conn_adl, $sql, $params);
        
        if ($stmt === false) {
            throw new Exception('Stored procedure failed: ' . print_r(sqlsrv_errors(), true));
        }
        
        $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        
        echo json_encode([
            'status' => 'ok',
            'method' => 'stored_procedure',
            'rows_inserted' => $result['rows_inserted'] ?? 0,
            'snapshot_utc' => $result['snapshot_utc'] instanceof DateTime 
                ? $result['snapshot_utc']->format('Y-m-d H:i:s') 
                : $result['snapshot_utc'],
            'source' => $source
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
