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
$session_id = isset($payload['session_id']) ? (int)$payload['session_id'] : 0;
$config_label = isset($payload['config_label']) ? trim($payload['config_label']) : '';
$max_acph = isset($payload['max_acph']) ? (int)$payload['max_acph'] : 0;

if ($session_id <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'session_id required.']);
}
if ($config_label === '') {
    respond_json(400, ['status' => 'error', 'message' => 'config_label required.']);
}
if ($max_acph <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'max_acph must be greater than 0.']);
}

// Verify session exists
$session = ctp_get_session($conn_tmi, $session_id);
if (!$session) {
    respond_json(404, ['status' => 'error', 'message' => 'Session not found.']);
}

// Prepare JSON fields
$tracks_json = null;
if (isset($payload['tracks_json'])) {
    if (is_array($payload['tracks_json'])) {
        $tracks_json = json_encode($payload['tracks_json']);
    } elseif (is_string($payload['tracks_json'])) {
        $tracks_json = $payload['tracks_json'];
    }
}

$origins_json = null;
if (isset($payload['origins_json'])) {
    if (is_array($payload['origins_json'])) {
        $origins_json = json_encode($payload['origins_json']);
    } elseif (is_string($payload['origins_json'])) {
        $origins_json = $payload['origins_json'];
    }
}

$destinations_json = null;
if (isset($payload['destinations_json'])) {
    if (is_array($payload['destinations_json'])) {
        $destinations_json = json_encode($payload['destinations_json']);
    } elseif (is_string($payload['destinations_json'])) {
        $destinations_json = $payload['destinations_json'];
    }
}

$priority = isset($payload['priority']) ? (int)$payload['priority'] : 100;
$notes = isset($payload['notes']) ? trim($payload['notes']) : null;

// Insert new config
$insert_result = ctp_execute($conn_tmi,
    "INSERT INTO dbo.ctp_track_throughput_config
     (session_id, config_label, tracks_json, origins_json, destinations_json, max_acph, priority, notes, is_active, created_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)",
    [$session_id, $config_label, $tracks_json, $origins_json, $destinations_json, $max_acph, $priority, $notes, $cid]);

if (!$insert_result['success']) {
    respond_json(500, ['status' => 'error', 'message' => 'Failed to create config.', 'error' => $insert_result['error']]);
}

// Get the new config_id
$id_result = ctp_fetch_value($conn_tmi, "SELECT SCOPE_IDENTITY() AS config_id", []);
$config_id = $id_result[0];

if (!$config_id) {
    respond_json(500, ['status' => 'error', 'message' => 'Failed to retrieve new config_id.']);
}

// Audit log
ctp_audit_log(
    $conn_tmi,
    $session_id,
    null,
    'THROUGHPUT_CONFIG_CREATE',
    [
        'config_id' => $config_id,
        'config_label' => $config_label,
        'max_acph' => $max_acph
    ],
    $cid
);

respond_json(201, [
    'status' => 'ok',
    'message' => 'Throughput config created.',
    'config_id' => (int)$config_id
]);
