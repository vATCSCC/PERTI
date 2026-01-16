<?php
/**
 * api/mgt/tmi/reroutes/delete.php
 * 
 * POST - Delete a reroute definition (Azure SQL)
 * 
 * POST params:
 *   id - Reroute ID to delete (required)
 * 
 * Note: This will cascade delete all associated flight records
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../../../load/connect.php';
require_once __DIR__ . '/../../../../sessions/handler.php';

// Permission check
if (!isset($_SESSION['VATSIM_CID']) && !defined('DEV')) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

try {
    if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing or invalid id parameter']);
        exit;
    }
    
    $id = post_int('id');
    
    // Check exists and get info for logging
    $checkSql = "SELECT id, name, status FROM dbo.tmi_reroutes WHERE id = ?";
    $checkStmt = sqlsrv_query($conn_adl, $checkSql, [$id]);
    
    if ($checkStmt === false) {
        throw new Exception('Query failed: ' . print_r(sqlsrv_errors(), true));
    }
    
    $existing = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($checkStmt);
    
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Reroute not found']);
        exit;
    }
    
    // Prevent deletion of active reroutes without explicit force flag
    if ($existing['status'] == 2 && (!isset($_POST['force']) || $_POST['force'] !== '1')) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error', 
            'message' => 'Cannot delete active reroute. Deactivate first or use force=1'
        ]);
        exit;
    }
    
    // Get count of associated flights for logging
    $countSql = "SELECT COUNT(*) as cnt FROM dbo.tmi_reroute_flights WHERE reroute_id = ?";
    $countStmt = sqlsrv_query($conn_adl, $countSql, [$id]);
    $countRow = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
    $flightCount = $countRow['cnt'] ?? 0;
    sqlsrv_free_stmt($countStmt);
    
    // Delete (cascades to tmi_reroute_flights and tmi_reroute_compliance_log)
    $deleteSql = "DELETE FROM dbo.tmi_reroutes WHERE id = ?";
    $deleteStmt = sqlsrv_query($conn_adl, $deleteSql, [$id]);
    
    if ($deleteStmt === false) {
        throw new Exception('Delete failed: ' . print_r(sqlsrv_errors(), true));
    }
    
    sqlsrv_free_stmt($deleteStmt);
    
    echo json_encode([
        'status' => 'ok',
        'action' => 'deleted',
        'id' => $id,
        'name' => $existing['name'],
        'flights_deleted' => $flightCount
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
