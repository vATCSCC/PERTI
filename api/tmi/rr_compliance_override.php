<?php
/**
 * api/tmi/rr_compliance_override.php
 * 
 * POST - Manually override compliance status for a flight (Azure SQL)
 * 
 * POST (JSON body):
 *   flight_id - ID from tmi_reroute_flights (required)
 *   status    - New compliance status (required)
 *   reason    - Reason for override (required)
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../load/connect.php';
require_once __DIR__ . '/../../sessions/handler.php';

if (!isset($_SESSION['VATSIM_CID']) && !defined('DEV')) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$VALID_STATUSES = ['PENDING', 'MONITORING', 'COMPLIANT', 'PARTIAL', 'NON_COMPLIANT', 'EXEMPT', 'UNKNOWN'];

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    if (!isset($input['flight_id']) || !is_numeric($input['flight_id'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing flight_id']);
        exit;
    }
    
    if (!isset($input['status']) || !in_array($input['status'], $VALID_STATUSES)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error', 
            'message' => 'Invalid status. Must be one of: ' . implode(', ', $VALID_STATUSES)
        ]);
        exit;
    }
    
    if (!isset($input['reason']) || trim($input['reason']) === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Reason is required for manual override']);
        exit;
    }
    
    $flightId = intval($input['flight_id']);
    $newStatus = $input['status'];
    $reason = trim($input['reason']);
    $overrideBy = $_SESSION['VATSIM_CID'] ?? 0;
    
    // Verify flight exists
    $checkSql = "SELECT id, reroute_id, callsign, compliance_status FROM dbo.tmi_reroute_flights WHERE id = ?";
    $checkStmt = sqlsrv_query($conn_adl, $checkSql, [$flightId]);
    $flight = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($checkStmt);
    
    if (!$flight) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Flight not found']);
        exit;
    }
    
    $oldStatus = $flight['compliance_status'];
    
    // Update the flight
    $updateSql = "UPDATE dbo.tmi_reroute_flights SET
        compliance_status = ?,
        manual_status = 1,
        override_by = ?,
        override_utc = GETUTCDATE(),
        override_reason = ?,
        compliance_notes = CONCAT(COALESCE(compliance_notes, ''), ' | Manual override: ', ?)
        WHERE id = ?";
    
    $updateParams = [$newStatus, $overrideBy, $reason, $reason, $flightId];
    $updateStmt = sqlsrv_query($conn_adl, $updateSql, $updateParams);
    
    if ($updateStmt === false) {
        throw new Exception('Update failed: ' . print_r(sqlsrv_errors(), true));
    }
    sqlsrv_free_stmt($updateStmt);
    
    // Log the override
    $logSql = "INSERT INTO dbo.tmi_reroute_compliance_log 
        (reroute_flight_id, compliance_status, compliance_pct, 
         route_string, fixes_crossed, snapshot_utc)
        VALUES (?, ?, NULL, ?, ?, GETUTCDATE())";
    
    $logParams = [
        $flightId,
        $newStatus,
        "MANUAL OVERRIDE by CID $overrideBy",
        $reason
    ];
    
    $logStmt = sqlsrv_query($conn_adl, $logSql, $logParams);
    if ($logStmt) sqlsrv_free_stmt($logStmt);
    
    echo json_encode([
        'status' => 'ok',
        'flight_id' => $flightId,
        'callsign' => $flight['callsign'],
        'old_status' => $oldStatus,
        'new_status' => $newStatus,
        'override_by' => $overrideBy
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
