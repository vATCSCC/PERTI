<?php
/**
 * JATOC Personnel API
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
try {
    if ($method === 'GET') {
        $stmt = sqlsrv_query($conn_adl, "SELECT * FROM jatoc_personnel ORDER BY CASE WHEN element = 'SUP' THEN 0 ELSE 1 END, element");
        if ($stmt === false) throw new Exception('Query failed');
        $data = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if (isset($row['updated_at']) && $row['updated_at'] instanceof DateTime) $row['updated_at'] = $row['updated_at']->format('c');
            $data[] = $row;
        }
        echo json_encode(['success' => true, 'data' => $data]);
    } elseif ($method === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (empty($input['element'])) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'Missing element']); return; }
        $stmt = sqlsrv_query($conn_adl, "UPDATE jatoc_personnel SET initials = ?, name = ?, updated_by = ?, updated_at = SYSUTCDATETIME() WHERE element = ?",
            [$input['initials'] ?? null, $input['name'] ?? null, $input['updated_by'] ?? null, $input['element']]);
        if ($stmt === false) throw new Exception('Update failed');
        echo json_encode(['success' => true]);
    } else { http_response_code(405); echo json_encode(['success' => false, 'error' => 'Method not allowed']); }
} catch (Exception $e) { http_response_code(500); echo json_encode(['success' => false, 'error' => $e->getMessage()]); }
