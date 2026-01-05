<?php
/**
 * JATOC Ops Level API
 * GET: Public access
 * PUT: Requires VATSIM auth
 */
header('Content-Type: application/json');
include("../../sessions/handler.php");
if (session_status() == PHP_SESSION_NONE) session_start();
include("../../load/config.php");
include("../../load/connect.php");

$method = $_SERVER['REQUEST_METHOD'];

// PUT requires authentication
if ($method === 'PUT' && !isset($_SESSION['VATSIM_CID'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$OPS_LABELS = [1 => 'Steady State', 2 => 'Escalated Activity', 3 => 'Major Event'];
try {
    if ($method === 'GET') {
        $stmt = sqlsrv_query($conn_adl, "SELECT TOP 1 * FROM jatoc_ops_level ORDER BY id DESC");
        if ($stmt === false) throw new Exception('Query failed');
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row && isset($row['set_at']) && $row['set_at'] instanceof DateTime) $row['set_at'] = $row['set_at']->format('c');
        echo json_encode(['success' => true, 'data' => $row ?: ['ops_level' => 1]]);
    } elseif ($method === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true);
        $newLevel = intval($input['ops_level'] ?? 1);
        if ($newLevel < 1 || $newLevel > 3) $newLevel = 1;
        $reason = $input['reason'] ?? null;
        $setBy = $input['set_by'] ?? 'System';
        
        // Get current level
        $curStmt = sqlsrv_query($conn_adl, "SELECT TOP 1 ops_level FROM jatoc_ops_level ORDER BY id DESC");
        $curRow = sqlsrv_fetch_array($curStmt, SQLSRV_FETCH_ASSOC);
        $oldLevel = $curRow ? $curRow['ops_level'] : 1;
        
        // Insert new level
        $stmt = sqlsrv_query($conn_adl, "INSERT INTO jatoc_ops_level (ops_level, reason, set_by) VALUES (?, ?, ?)",
            [$newLevel, $reason, $setBy]);
        if ($stmt === false) throw new Exception('Insert failed');
        
        // If level changed, log to all active incidents as priority comment
        if ($newLevel != $oldLevel) {
            $oldLabel = $OPS_LABELS[$oldLevel] ?? 'Unknown';
            $newLabel = $OPS_LABELS[$newLevel] ?? 'Unknown';
            $priorityMsg = "< OPS LEVEL $oldLevel ($oldLabel) TO LEVEL $newLevel ($newLabel) >";
            if ($reason) $priorityMsg .= " Reason: $reason";
            
            // Get all active incidents
            $activeStmt = sqlsrv_query($conn_adl, "SELECT id FROM jatoc_incidents WHERE incident_status = 'ACTIVE'");
            $logged = 0;
            while ($inc = sqlsrv_fetch_array($activeStmt, SQLSRV_FETCH_ASSOC)) {
                sqlsrv_query($conn_adl,
                    "INSERT INTO jatoc_incident_updates (incident_id, update_type, remarks, created_by) VALUES (?, 'OPS_LEVEL', ?, ?)",
                    [$inc['id'], $priorityMsg, $setBy]
                );
                $logged++;
            }
        }
        
        echo json_encode(['success' => true, 'logged_incidents' => $logged ?? 0]);
    } else { http_response_code(405); echo json_encode(['success' => false, 'error' => 'Method not allowed']); }
} catch (Exception $e) { http_response_code(500); echo json_encode(['success' => false, 'error' => $e->getMessage()]); }
