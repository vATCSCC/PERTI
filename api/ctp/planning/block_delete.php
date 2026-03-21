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

$block_id = isset($payload['block_id']) ? (int)$payload['block_id'] : null;
if (!$block_id) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'block_id is required'
    ]);
}

// Verify ownership via scenario
$check_sql = "SELECT b.block_id, b.scenario_id, b.block_label, s.created_by, s.session_id
              FROM dbo.ctp_planning_traffic_blocks b
              JOIN dbo.ctp_planning_scenarios s ON b.scenario_id = s.scenario_id
              WHERE b.block_id = ?";
$check_result = ctp_fetch_one($conn, $check_sql, [$block_id]);
if (!$check_result['success'] || !$check_result['data']) {
    respond_json(404, [
        'status' => 'error',
        'message' => 'Block not found'
    ]);
}

if ($check_result['data']['created_by'] !== $cid) {
    respond_json(403, [
        'status' => 'error',
        'message' => 'You do not have permission to delete this block'
    ]);
}

$session_id = $check_result['data']['session_id'];
$scenario_id = $check_result['data']['scenario_id'];
$block_label = $check_result['data']['block_label'];

// Delete (CASCADE will auto-delete assignments)
$sql = "DELETE FROM dbo.ctp_planning_traffic_blocks WHERE block_id = ?";
$result = ctp_execute($conn, $sql, [$block_id]);

if (!$result['success']) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to delete block',
        'error' => $result['error']
    ]);
}

respond_json(200, [
    'status' => 'ok',
    'message' => 'Block deleted'
]);
