<?php
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}
define('CTP_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(405, ['status' => 'error', 'message' => 'Method not allowed']);
}

$cid = ctp_require_auth();
$payload = read_request_payload();
$conn = ctp_get_conn_tmi();

$assignment_id = isset($payload['assignment_id']) ? (int)$payload['assignment_id'] : null;
if (!$assignment_id) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'assignment_id is required'
    ]);
}

// Verify ownership via block → scenario
$check_sql = "SELECT a.assignment_id, a.track_name, s.created_by, s.session_id
              FROM dbo.ctp_planning_track_assignments a
              JOIN dbo.ctp_planning_traffic_blocks b ON a.block_id = b.block_id
              JOIN dbo.ctp_planning_scenarios s ON b.scenario_id = s.scenario_id
              WHERE a.assignment_id = ?";
$check_result = ctp_fetch_one($conn, $check_sql, [$assignment_id]);
if (!$check_result['success'] || !$check_result['data']) {
    respond_json(404, [
        'status' => 'error',
        'message' => 'Assignment not found'
    ]);
}

if ($check_result['data']['created_by'] !== $cid) {
    respond_json(403, [
        'status' => 'error',
        'message' => 'You do not have permission to delete this assignment'
    ]);
}

$session_id = $check_result['data']['session_id'];

// Delete
$sql = "DELETE FROM dbo.ctp_planning_track_assignments WHERE assignment_id = ?";
$result = ctp_execute($conn, $sql, [$assignment_id]);

if (!$result['success']) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to delete assignment',
        'error' => $result['error']
    ]);
}

respond_json(200, [
    'status' => 'success',
    'message' => 'Assignment deleted'
]);
