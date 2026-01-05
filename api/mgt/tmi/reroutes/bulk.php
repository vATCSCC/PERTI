<?php
/**
 * api/mgt/tmi/reroutes/bulk.php
 * 
 * POST - Bulk operations on multiple reroutes (Azure SQL)
 * 
 * POST (JSON body):
 *   action - "expire", "cancel", "delete", "refresh_compliance"
 *   ids    - Array of reroute IDs
 *   force  - For delete: skip confirmation (default: false)
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../../../load/connect.php';
require_once __DIR__ . '/../../../../sessions/handler.php';

if (!isset($_SESSION['VATSIM_CID']) && !defined('DEV')) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    if (!isset($input['action']) || !isset($input['ids'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing action or ids']);
        exit;
    }
    
    $action = strtolower($input['action']);
    $ids = is_array($input['ids']) ? $input['ids'] : explode(',', $input['ids']);
    $ids = array_filter(array_map('intval', $ids));
    $force = isset($input['force']) && $input['force'];
    
    if (empty($ids)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'No valid IDs provided']);
        exit;
    }
    
    $results = [
        'action' => $action,
        'requested' => count($ids),
        'success' => 0,
        'failed' => 0,
        'details' => []
    ];
    
    switch ($action) {
        case 'expire':
            // Move active/monitoring reroutes to expired status
            foreach ($ids as $id) {
                $sql = "UPDATE dbo.tmi_reroutes SET status = 4, updated_utc = GETUTCDATE() 
                        WHERE id = ? AND status IN (2, 3)";
                $stmt = sqlsrv_query($conn_adl, $sql, [$id]);
                if ($stmt && sqlsrv_rows_affected($stmt) > 0) {
                    $results['success']++;
                    $results['details'][] = ['id' => $id, 'result' => 'expired'];
                } else {
                    $results['failed']++;
                    $results['details'][] = ['id' => $id, 'result' => 'not_updated'];
                }
                if ($stmt) sqlsrv_free_stmt($stmt);
            }
            break;
            
        case 'cancel':
            // Cancel reroutes (any status except already cancelled/expired)
            foreach ($ids as $id) {
                $sql = "UPDATE dbo.tmi_reroutes SET status = 5, updated_utc = GETUTCDATE() 
                        WHERE id = ? AND status NOT IN (4, 5)";
                $stmt = sqlsrv_query($conn_adl, $sql, [$id]);
                if ($stmt && sqlsrv_rows_affected($stmt) > 0) {
                    $results['success']++;
                    $results['details'][] = ['id' => $id, 'result' => 'cancelled'];
                } else {
                    $results['failed']++;
                    $results['details'][] = ['id' => $id, 'result' => 'not_updated'];
                }
                if ($stmt) sqlsrv_free_stmt($stmt);
            }
            break;
            
        case 'delete':
            // Delete reroutes and their flights
            foreach ($ids as $id) {
                // Check if has active flights
                if (!$force) {
                    $checkSql = "SELECT r.status, COUNT(f.id) as flight_count 
                                 FROM dbo.tmi_reroutes r
                                 LEFT JOIN dbo.tmi_reroute_flights f ON r.id = f.reroute_id
                                 WHERE r.id = ?
                                 GROUP BY r.status";
                    $checkStmt = sqlsrv_query($conn_adl, $checkSql, [$id]);
                    $check = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);
                    sqlsrv_free_stmt($checkStmt);
                    
                    if ($check && $check['status'] == 2 && $check['flight_count'] > 0) {
                        $results['failed']++;
                        $results['details'][] = [
                            'id' => $id, 
                            'result' => 'blocked', 
                            'reason' => 'Active reroute with flights. Use force=true or expire first.'
                        ];
                        continue;
                    }
                }
                
                // Delete compliance logs
                $logSql = "DELETE l FROM dbo.tmi_reroute_compliance_log l
                           INNER JOIN dbo.tmi_reroute_flights f ON l.reroute_flight_id = f.id
                           WHERE f.reroute_id = ?";
                sqlsrv_query($conn_adl, $logSql, [$id]);
                
                // Delete flights
                $flightSql = "DELETE FROM dbo.tmi_reroute_flights WHERE reroute_id = ?";
                $flightStmt = sqlsrv_query($conn_adl, $flightSql, [$id]);
                $flightsDeleted = $flightStmt ? sqlsrv_rows_affected($flightStmt) : 0;
                if ($flightStmt) sqlsrv_free_stmt($flightStmt);
                
                // Delete reroute
                $sql = "DELETE FROM dbo.tmi_reroutes WHERE id = ?";
                $stmt = sqlsrv_query($conn_adl, $sql, [$id]);
                if ($stmt && sqlsrv_rows_affected($stmt) > 0) {
                    $results['success']++;
                    $results['details'][] = [
                        'id' => $id, 
                        'result' => 'deleted',
                        'flights_deleted' => $flightsDeleted
                    ];
                } else {
                    $results['failed']++;
                    $results['details'][] = ['id' => $id, 'result' => 'not_found'];
                }
                if ($stmt) sqlsrv_free_stmt($stmt);
            }
            break;
            
        case 'refresh_compliance':
            // Trigger compliance refresh for multiple reroutes
            foreach ($ids as $id) {
                // Just verify reroute exists and is active/monitoring
                $checkSql = "SELECT id FROM dbo.tmi_reroutes WHERE id = ? AND status IN (2, 3)";
                $checkStmt = sqlsrv_query($conn_adl, $checkSql, [$id]);
                $exists = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);
                sqlsrv_free_stmt($checkStmt);
                
                if (!$exists) {
                    $results['failed']++;
                    $results['details'][] = ['id' => $id, 'result' => 'not_active'];
                    continue;
                }
                
                // Mark all flights as needing refresh
                $updateSql = "UPDATE dbo.tmi_reroute_flights 
                              SET compliance_status = 'MONITORING', updated_utc = GETUTCDATE()
                              WHERE reroute_id = ? AND compliance_status NOT IN ('EXEMPT')";
                $updateStmt = sqlsrv_query($conn_adl, $updateSql, [$id]);
                $updated = $updateStmt ? sqlsrv_rows_affected($updateStmt) : 0;
                if ($updateStmt) sqlsrv_free_stmt($updateStmt);
                
                $results['success']++;
                $results['details'][] = ['id' => $id, 'result' => 'queued', 'flights_queued' => $updated];
            }
            break;
            
        case 'activate':
            // Activate draft/proposed reroutes
            foreach ($ids as $id) {
                $sql = "UPDATE dbo.tmi_reroutes 
                        SET status = 2, activated_utc = GETUTCDATE(), updated_utc = GETUTCDATE() 
                        WHERE id = ? AND status IN (0, 1)";
                $stmt = sqlsrv_query($conn_adl, $sql, [$id]);
                if ($stmt && sqlsrv_rows_affected($stmt) > 0) {
                    $results['success']++;
                    $results['details'][] = ['id' => $id, 'result' => 'activated'];
                } else {
                    $results['failed']++;
                    $results['details'][] = ['id' => $id, 'result' => 'not_updated'];
                }
                if ($stmt) sqlsrv_free_stmt($stmt);
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode([
                'status' => 'error', 
                'message' => 'Invalid action. Valid actions: expire, cancel, delete, refresh_compliance, activate'
            ]);
            exit;
    }
    
    echo json_encode([
        'status' => 'ok',
        'results' => $results
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
