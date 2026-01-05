<?php
/**
 * api/mgt/tmi/reroutes/activate.php
 * 
 * POST - Change reroute status (activate, deactivate, expire, cancel) (Azure SQL)
 * 
 * POST params:
 *   id      - Reroute ID (required)
 *   action  - One of: draft, propose, activate, monitor, expire, cancel (required)
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

// Status mapping
$ACTIONS = [
    'draft'      => 0,
    'propose'    => 1,
    'activate'   => 2,
    'monitor'    => 3,
    'expire'     => 4,
    'cancel'     => 5
];

$STATUS_LABELS = [
    0 => 'Draft',
    1 => 'Proposed',
    2 => 'Active',
    3 => 'Monitoring',
    4 => 'Expired',
    5 => 'Cancelled'
];

try {
    if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing or invalid id parameter']);
        exit;
    }
    
    if (!isset($_POST['action']) || !isset($ACTIONS[$_POST['action']])) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error', 
            'message' => 'Invalid action. Must be one of: ' . implode(', ', array_keys($ACTIONS))
        ]);
        exit;
    }
    
    $id = intval($_POST['id']);
    $action = $_POST['action'];
    $newStatus = $ACTIONS[$action];
    
    // Check exists and get current status
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
    
    $oldStatus = intval($existing['status']);
    
    // Validate state transitions
    $validTransitions = [
        0 => [1, 2, 5],     // draft -> proposed, active, cancelled
        1 => [0, 2, 5],     // proposed -> draft, active, cancelled
        2 => [3, 4, 5],     // active -> monitoring, expired, cancelled
        3 => [4],           // monitoring -> expired
        4 => [],            // expired -> (none, final state)
        5 => [0]            // cancelled -> draft (reopen)
    ];
    
    if (!in_array($newStatus, $validTransitions[$oldStatus])) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => "Invalid transition from {$STATUS_LABELS[$oldStatus]} to {$STATUS_LABELS[$newStatus]}"
        ]);
        exit;
    }
    
    // Build update query
    $sql = "UPDATE dbo.tmi_reroutes SET status = ?, updated_utc = GETUTCDATE()";
    $params = [$newStatus];
    
    // Track activation time
    if ($newStatus == 2 && $oldStatus != 2) {
        $sql .= ", activated_utc = GETUTCDATE()";
    }
    
    $sql .= " WHERE id = ?";
    $params[] = $id;
    
    $stmt = sqlsrv_query($conn_adl, $sql, $params);
    
    if ($stmt === false) {
        throw new Exception('Update failed: ' . print_r(sqlsrv_errors(), true));
    }
    
    $rowsAffected = sqlsrv_rows_affected($stmt);
    sqlsrv_free_stmt($stmt);
    
    echo json_encode([
        'status' => 'ok',
        'action' => $action,
        'id' => $id,
        'old_status' => $STATUS_LABELS[$oldStatus],
        'new_status' => $STATUS_LABELS[$newStatus],
        'affected_rows' => $rowsAffected
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
