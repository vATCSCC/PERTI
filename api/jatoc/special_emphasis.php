<?php
/**
 * JATOC Special Emphasis API
 * GET: Public access
 * POST/DELETE: Requires VATSIM auth
 */
header('Content-Type: application/json');
include("../../sessions/handler.php");
if (session_status() == PHP_SESSION_NONE) session_start();
include("../../load/config.php");
include("../../load/connect.php");

$method = $_SERVER['REQUEST_METHOD'];

// POST and DELETE require authentication
if (($method === 'POST' || $method === 'DELETE') && !isset($_SESSION['VATSIM_CID'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$today = gmdate('Y-m-d');
try {
    if ($method === 'GET') {
        $stmt = sqlsrv_query($conn_adl, "SELECT * FROM jatoc_special_emphasis WHERE active = 1 AND (effective_start IS NULL OR effective_start <= ?) AND (effective_end IS NULL OR effective_end >= ?) ORDER BY priority DESC, id", [$today, $today]);
        if ($stmt === false) throw new Exception('Query failed');
        $data = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) { $data[] = $row; }
        echo json_encode(['success' => true, 'data' => $data]);
    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (empty($input['content'])) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'Missing content']); return; }
        $stmt = sqlsrv_query($conn_adl, "INSERT INTO jatoc_special_emphasis (content, priority, effective_start, effective_end, created_by) VALUES (?, ?, ?, ?, ?)",
            [$input['content'], $input['priority'] ?? 0, $input['effective_start'] ?? null, $input['effective_end'] ?? null, $input['created_by'] ?? null]);
        if ($stmt === false) throw new Exception('Insert failed');
        echo json_encode(['success' => true]);
    } elseif ($method === 'DELETE') {
        $id = $_GET['id'] ?? null;
        if (!$id) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'Missing id']); return; }
        sqlsrv_query($conn_adl, "DELETE FROM jatoc_special_emphasis WHERE id = ?", [$id]);
        echo json_encode(['success' => true]);
    } else { http_response_code(405); echo json_encode(['success' => false, 'error' => 'Method not allowed']); }
} catch (Exception $e) { http_response_code(500); echo json_encode(['success' => false, 'error' => $e->getMessage()]); }
