<?php
/**
 * JATOC Daily Ops API - GET/PUT
 * GET: Public access
 * PUT: Requires VATSIM auth
 */
header('Content-Type: application/json');

include("../../sessions/handler.php");
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
include("../../load/config.php");
include("../../load/connect.php");

$method = $_SERVER['REQUEST_METHOD'];

// PUT requires authentication
if ($method === 'PUT' && !isset($_SESSION['VATSIM_CID'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

try {
    $today = gmdate('Y-m-d');
    
    if ($method === 'GET') {
        $itemType = $_GET['item_type'] ?? null;
        
        if ($itemType) {
            $stmt = sqlsrv_query($conn_adl, 
                "SELECT * FROM jatoc_daily_ops WHERE effective_date = ? AND item_type = ?", 
                [$today, $itemType]);
        } else {
            $stmt = sqlsrv_query($conn_adl, 
                "SELECT * FROM jatoc_daily_ops WHERE effective_date = ? ORDER BY 
                 CASE item_type WHEN 'POTUS' THEN 1 WHEN 'SPACE' THEN 2 ELSE 99 END", 
                [$today]);
        }
        if ($stmt === false) throw new Exception('Query failed');
        
        $items = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if (isset($row['effective_date']) && $row['effective_date'] instanceof DateTime) $row['effective_date'] = $row['effective_date']->format('Y-m-d');
            if (isset($row['updated_at']) && $row['updated_at'] instanceof DateTime) $row['updated_at'] = $row['updated_at']->format('c');
            $items[] = $row;
        }
        
        if (!$itemType) {
            $existing = array_column($items, 'item_type');
            foreach (['POTUS', 'SPACE'] as $type) {
                if (!in_array($type, $existing)) {
                    $items[] = ['id' => null, 'item_type' => $type, 'content' => null, 'effective_date' => $today];
                }
            }
        }
        
        echo json_encode(['success' => true, 'data' => $items]);
        
    } elseif ($method === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (empty($input['item_type'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing item_type']);
            return;
        }
        
        $checkStmt = sqlsrv_query($conn_adl, "SELECT id FROM jatoc_daily_ops WHERE item_type = ? AND effective_date = ?", [$input['item_type'], $today]);
        $existing = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);
        
        if ($existing) {
            $sql = "UPDATE jatoc_daily_ops SET content = ?, updated_by = ?, updated_at = SYSUTCDATETIME() WHERE item_type = ? AND effective_date = ?";
            sqlsrv_query($conn_adl, $sql, [$input['content'] ?? null, $input['updated_by'] ?? null, $input['item_type'], $today]);
        } else {
            $sql = "INSERT INTO jatoc_daily_ops (item_type, content, effective_date, updated_by) VALUES (?, ?, ?, ?)";
            sqlsrv_query($conn_adl, $sql, [$input['item_type'], $input['content'] ?? null, $today, $input['updated_by'] ?? null]);
        }
        
        echo json_encode(['success' => true]);
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
