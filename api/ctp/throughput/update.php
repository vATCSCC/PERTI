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
$expected_updated_at = isset($payload['expected_updated_at']) ? trim($payload['expected_updated_at']) : '';

if ($config_id <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'config_id required.']);
}
if ($expected_updated_at === '') {
    respond_json(400, ['status' => 'error', 'message' => 'expected_updated_at required for optimistic concurrency.']);
}

// Parse expected_updated_at to SQL Server format
$expected_updated_at_sql = parse_utc_datetime($expected_updated_at);
if (!$expected_updated_at_sql) {
    respond_json(400, ['status' => 'error', 'message' => 'Invalid expected_updated_at format.']);
}

// Fetch current config for session_id and audit logging
$current = ctp_fetch_one($conn_tmi,
    "SELECT session_id, config_label, updated_at FROM dbo.ctp_track_throughput_config WHERE config_id = ?",
    [$config_id]);

if (!$current['success'] || !$current['data']) {
    respond_json(404, ['status' => 'error', 'message' => 'Config not found.']);
}

$session_id = $current['data']['session_id'];

// Prepare update fields (only update what's provided)
$config_label = isset($payload['config_label']) ? trim($payload['config_label']) : $current['data']['config_label'];

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

$max_acph = isset($payload['max_acph']) ? (int)$payload['max_acph'] : null;
if ($max_acph !== null && $max_acph <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'max_acph must be greater than 0.']);
}

$priority = isset($payload['priority']) ? (int)$payload['priority'] : null;
$notes = isset($payload['notes']) ? trim($payload['notes']) : null;

// Build dynamic UPDATE query based on what fields are provided
$update_fields = [];
$update_params = [];

if (isset($payload['config_label'])) {
    $update_fields[] = "config_label = ?";
    $update_params[] = $config_label;
}
if (isset($payload['tracks_json'])) {
    $update_fields[] = "tracks_json = ?";
    $update_params[] = $tracks_json;
}
if (isset($payload['origins_json'])) {
    $update_fields[] = "origins_json = ?";
    $update_params[] = $origins_json;
}
if (isset($payload['destinations_json'])) {
    $update_fields[] = "destinations_json = ?";
    $update_params[] = $destinations_json;
}
if (isset($payload['max_acph'])) {
    $update_fields[] = "max_acph = ?";
    $update_params[] = $max_acph;
}
if (isset($payload['priority'])) {
    $update_fields[] = "priority = ?";
    $update_params[] = $priority;
}
if (isset($payload['notes'])) {
    $update_fields[] = "notes = ?";
    $update_params[] = $notes;
}

if (empty($update_fields)) {
    respond_json(400, ['status' => 'error', 'message' => 'No fields to update.']);
}

// Always update updated_at
$update_fields[] = "updated_at = SYSUTCDATETIME()";

// Add WHERE conditions
$update_params[] = $config_id;
$update_params[] = $expected_updated_at_sql;

$update_sql = "UPDATE dbo.ctp_track_throughput_config
                SET " . implode(", ", $update_fields) . "
                WHERE config_id = ? AND updated_at = ?";

// Execute update with optimistic concurrency check
$update_result = ctp_execute($conn_tmi, $update_sql, $update_params);

if (!$update_result['success']) {
    respond_json(500, ['status' => 'error', 'message' => 'Update failed.', 'error' => $update_result['error']]);
}

// Check if any rows were affected (optimistic concurrency check)
if ($update_result['rows_affected'] === 0) {
    // Fetch current state for client reconciliation
    $current_state = ctp_fetch_one($conn_tmi,
        "SELECT config_id, session_id, config_label, tracks_json, origins_json, destinations_json,
                max_acph, priority, is_active, notes, created_by, created_at, updated_at
         FROM dbo.ctp_track_throughput_config
         WHERE config_id = ?",
        [$config_id]);

    if ($current_state['success'] && $current_state['data']) {
        // Parse JSON fields
        $state = $current_state['data'];
        $state['tracks_json'] = $state['tracks_json'] ? json_decode($state['tracks_json'], true) : null;
        $state['origins_json'] = $state['origins_json'] ? json_decode($state['origins_json'], true) : null;
        $state['destinations_json'] = $state['destinations_json'] ? json_decode($state['destinations_json'], true) : null;

        respond_json(409, [
            'status' => 'conflict',
            'message' => 'Config was modified by another user. Please refresh and retry.',
            'current_state' => $state
        ]);
    }

    respond_json(409, [
        'status' => 'conflict',
        'message' => 'Config was modified or deleted. Please refresh.'
    ]);
}

// Audit log
ctp_audit_log(
    $conn_tmi,
    $session_id,
    null,
    'THROUGHPUT_CONFIG_UPDATE',
    [
        'config_id' => $config_id,
        'config_label' => $config_label,
        'updated_fields' => array_keys(array_filter([
            'config_label' => isset($payload['config_label']),
            'tracks_json' => isset($payload['tracks_json']),
            'origins_json' => isset($payload['origins_json']),
            'destinations_json' => isset($payload['destinations_json']),
            'max_acph' => isset($payload['max_acph']),
            'priority' => isset($payload['priority']),
            'notes' => isset($payload['notes'])
        ]))
    ],
    $cid
);

respond_json(200, [
    'status' => 'ok',
    'message' => 'Throughput config updated.'
]);
