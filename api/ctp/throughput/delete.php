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

// Method check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

$cid = ctp_require_auth();
$conn_tmi = ctp_get_conn_tmi();

// Read request payload
$payload = read_request_payload();

// Validate required fields
$config_id = isset($payload['config_id']) ? (int)$payload['config_id'] : 0;

if ($config_id <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'config_id required.']);
}

// Fetch config for session_id and audit logging
$config = ctp_fetch_one($conn_tmi,
    "SELECT session_id, config_label FROM dbo.ctp_track_throughput_config WHERE config_id = ? AND is_active = 1",
    [$config_id]);

if (!$config['success'] || !$config['data']) {
    respond_json(404, ['status' => 'error', 'message' => 'Config not found or already deleted.']);
}

$session_id = $config['data']['session_id'];
$config_label = $config['data']['config_label'];

// Soft delete
$delete_result = ctp_execute($conn_tmi,
    "UPDATE dbo.ctp_track_throughput_config
     SET is_active = 0, updated_at = SYSUTCDATETIME()
     WHERE config_id = ?",
    [$config_id]);

if (!$delete_result['success']) {
    respond_json(500, ['status' => 'error', 'message' => 'Delete failed.', 'error' => $delete_result['error']]);
}

if ($delete_result['rows_affected'] === 0) {
    respond_json(404, ['status' => 'error', 'message' => 'Config not found.']);
}

// Audit log
ctp_audit_log(
    $conn_tmi,
    $session_id,
    null,
    'THROUGHPUT_CONFIG_DELETE',
    [
        'config_id' => $config_id,
        'config_label' => $config_label
    ],
    $cid
);

respond_json(200, [
    'status' => 'ok',
    'message' => 'Throughput config deleted.'
]);
