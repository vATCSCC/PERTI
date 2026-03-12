<?php
/**
 * CTP Sessions - Update API
 *
 * POST /api/ctp/sessions/update.php
 *
 * Updates an existing CTP session's configuration.
 * Only DRAFT and ACTIVE sessions can be updated.
 *
 * Request body:
 * {
 *   "session_id": 1,
 *   "session_name": "...",
 *   "constrained_firs": [...],
 *   "constraint_window_start": "...",
 *   "constraint_window_end": "...",
 *   "slot_interval_min": 5,
 *   "max_slots_per_hour": 12,
 *   "validation_rules_json": {...},
 *   "managing_orgs": [...],
 *   "perspective_orgs_json": {...}
 * }
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('CTP_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond_json(405, ['status' => 'error', 'message' => 'Method not allowed. Use POST.']);
}

$cid = ctp_require_auth();
$conn = ctp_get_conn_tmi();
$payload = read_request_payload();

$session_id = isset($payload['session_id']) ? (int)$payload['session_id'] : 0;
if ($session_id <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'session_id is required.']);
}

$session = ctp_get_session($conn, $session_id);
if (!$session) {
    respond_json(404, ['status' => 'error', 'message' => 'Session not found.']);
}

if (!in_array($session['status'], ['DRAFT', 'ACTIVE', 'MONITORING'])) {
    respond_json(409, ['status' => 'error', 'message' => 'Session cannot be updated in status: ' . $session['status']]);
}

// Build SET clauses for provided fields
$sets = [];
$params = [];

$updatable = [
    'session_name'            => 'string',
    'direction'               => 'direction',
    'constraint_window_start' => 'datetime',
    'constraint_window_end'   => 'datetime',
    'slot_interval_min'       => 'int',
    'max_slots_per_hour'      => 'int_null',
];

$json_fields = ['constrained_firs', 'validation_rules_json', 'managing_orgs', 'perspective_orgs_json'];

foreach ($updatable as $field => $type) {
    if (!array_key_exists($field, $payload)) continue;

    $val = $payload[$field];
    switch ($type) {
        case 'string':
            $sets[] = "{$field} = ?";
            $params[] = trim((string)$val);
            break;
        case 'direction':
            $val = strtoupper(trim((string)$val));
            if (!in_array($val, ['WESTBOUND', 'EASTBOUND', 'BOTH'])) {
                respond_json(400, ['status' => 'error', 'message' => 'direction must be WESTBOUND, EASTBOUND, or BOTH.']);
            }
            $sets[] = "{$field} = ?";
            $params[] = $val;
            break;
        case 'datetime':
            $parsed = parse_utc_datetime($val);
            if (!$parsed) {
                respond_json(400, ['status' => 'error', 'message' => "{$field} is not a valid datetime."]);
            }
            $sets[] = "{$field} = ?";
            $params[] = $parsed;
            break;
        case 'int':
            $sets[] = "{$field} = ?";
            $params[] = (int)$val;
            break;
        case 'int_null':
            $sets[] = "{$field} = ?";
            $params[] = ($val !== null && $val !== '') ? (int)$val : null;
            break;
    }
}

foreach ($json_fields as $field) {
    if (!array_key_exists($field, $payload)) continue;
    $val = $payload[$field];
    $sets[] = "{$field} = ?";
    $params[] = is_array($val) ? json_encode($val) : $val;
}

if (empty($sets)) {
    respond_json(400, ['status' => 'error', 'message' => 'No fields to update.']);
}

$params[] = $session_id;
$sql = "UPDATE dbo.ctp_sessions SET " . implode(', ', $sets) . " WHERE session_id = ?";
$result = ctp_execute($conn, $sql, $params);

if (!$result['success']) {
    respond_json(500, ['status' => 'error', 'message' => 'Failed to update session.', 'errors' => $result['error']]);
}

ctp_audit_log($conn, $session_id, null, 'SESSION_UPDATE', [
    'updated_fields' => array_keys(array_intersect_key($payload, array_flip(array_merge(array_keys($updatable), $json_fields))))
], $cid);

respond_json(200, [
    'status' => 'ok',
    'data' => ['message' => 'Session updated successfully.']
]);
