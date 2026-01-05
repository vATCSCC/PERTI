<?php
/**
 * JATOC Incident Updates API - GET/POST
 * GET: Public access
 * POST: Profile required (no server auth - checked client-side)
 */
header('Content-Type: application/json');

include("../../sessions/handler.php");
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
include("../../load/config.php");
include("../../load/connect.php");

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $incidentId = $_GET['incident_id'] ?? null;
        if (!$incidentId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing incident_id']);
            return;
        }
        
        $stmt = sqlsrv_query($conn_adl, 
            "SELECT * FROM jatoc_incident_updates WHERE incident_id = ? ORDER BY created_utc DESC", 
            [$incidentId]);
        if ($stmt === false) throw new Exception('Query failed');
        
        $updates = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if (isset($row['created_utc']) && $row['created_utc'] instanceof DateTime) {
                $row['created_utc'] = $row['created_utc']->format('Y-m-d H:i:s');
            }
            $updates[] = $row;
        }
        
        echo json_encode(['success' => true, 'data' => $updates]);
        
    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (empty($input['incident_id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing incident_id']);
            return;
        }
        
        $sql = "INSERT INTO jatoc_incident_updates (incident_id, update_type, remarks, created_by) VALUES (?, ?, ?, ?)";
        $params = [
            $input['incident_id'],
            $input['update_type'] ?? 'REMARK',
            $input['remarks'] ?? null,
            $input['created_by'] ?? null
        ];
        
        $stmt = sqlsrv_query($conn_adl, $sql, $params);
        if ($stmt === false) throw new Exception('Insert failed');
        
        // Update parent incident's update_utc
        sqlsrv_query($conn_adl, "UPDATE jatoc_incidents SET update_utc = SYSUTCDATETIME() WHERE id = ?", [$input['incident_id']]);
        
        // Sync updates to report if exists
        syncReportUpdates($conn_adl, $input['incident_id']);
        
        echo json_encode(['success' => true]);
        
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function syncReportUpdates($conn, $incidentId) {
    // Check if report exists
    $check = sqlsrv_query($conn, "SELECT id FROM jatoc_reports WHERE incident_id = ?", [$incidentId]);
    if (!sqlsrv_fetch_array($check, SQLSRV_FETCH_ASSOC)) return;
    
    // Get all updates
    $updStmt = sqlsrv_query($conn, "SELECT * FROM jatoc_incident_updates WHERE incident_id = ? ORDER BY created_utc ASC", [$incidentId]);
    $updates = [];
    while ($row = sqlsrv_fetch_array($updStmt, SQLSRV_FETCH_ASSOC)) {
        $ts = $row['created_utc'];
        if ($ts instanceof DateTime) $ts = $ts->format('Y-m-d H:i:s') . 'Z';
        $updates[] = ['id' => $row['id'], 'type' => $row['update_type'], 'remarks' => $row['remarks'], 'created_by' => $row['created_by'], 'timestamp' => $ts];
    }
    
    sqlsrv_query($conn, "UPDATE jatoc_reports SET updates_json = ?, updated_at = SYSUTCDATETIME() WHERE incident_id = ?", 
        [json_encode($updates), $incidentId]);
}
