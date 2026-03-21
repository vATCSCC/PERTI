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

$scenario_id = isset($payload['scenario_id']) ? (int)$payload['scenario_id'] : null;
if (!$scenario_id) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'scenario_id is required'
    ]);
}

// Verify ownership
$check_sql = "SELECT scenario_id, created_by, session_id, scenario_name FROM dbo.ctp_planning_scenarios WHERE scenario_id = ?";
$check_result = ctp_fetch_one($conn, $check_sql, [$scenario_id]);
if (!$check_result['success'] || !$check_result['data']) {
    respond_json(404, [
        'status' => 'error',
        'message' => 'Scenario not found'
    ]);
}

if ($check_result['data']['created_by'] !== $cid) {
    respond_json(403, [
        'status' => 'error',
        'message' => 'You do not have permission to delete this scenario'
    ]);
}

$session_id = $check_result['data']['session_id'];
$scenario_name = $check_result['data']['scenario_name'];

// Delete (CASCADE will auto-delete blocks and assignments)
$sql = "DELETE FROM dbo.ctp_planning_scenarios WHERE scenario_id = ?";
$result = ctp_execute($conn, $sql, [$scenario_id]);

if (!$result['success']) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to delete scenario',
        'error' => $result['error']
    ]);
}

ctp_audit_log($conn, $session_id, null, 'SCENARIO_DELETE', [
    'scenario_id' => $scenario_id,
    'scenario_name' => $scenario_name
], $cid);

respond_json(200, [
    'status' => 'ok',
    'message' => 'Scenario deleted'
]);
