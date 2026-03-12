<?php
/**
 * CTP Sessions - Activate API
 *
 * POST /api/ctp/sessions/activate.php
 *
 * Transitions a DRAFT session to ACTIVE status.
 *
 * Request body:
 * { "session_id": 1 }
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

// Allow DRAFT -> ACTIVE and MONITORING -> ACTIVE
if (!in_array($session['status'], ['DRAFT', 'MONITORING'])) {
    respond_json(409, [
        'status' => 'error',
        'message' => 'Session must be in DRAFT or MONITORING status to activate. Current: ' . $session['status']
    ]);
}

// Validate session has required config
if (empty($session['constrained_firs'])) {
    respond_json(400, ['status' => 'error', 'message' => 'Session must have constrained_firs configured before activation.']);
}

$result = ctp_execute($conn,
    "UPDATE dbo.ctp_sessions SET status = 'ACTIVE' WHERE session_id = ?",
    [$session_id]
);

if (!$result['success']) {
    respond_json(500, ['status' => 'error', 'message' => 'Failed to activate session.', 'errors' => $result['error']]);
}

ctp_audit_log($conn, $session_id, null, 'SESSION_ACTIVATE', [
    'previous_status' => $session['status'],
    'new_status' => 'ACTIVE'
], $cid);

// Push SWIM event
ctp_push_swim_event('ctp.session.activated', [
    'session_id' => $session_id,
    'session_name' => $session['session_name'],
    'direction' => $session['direction'],
]);

respond_json(200, [
    'status' => 'ok',
    'data' => ['message' => 'Session activated successfully.']
]);
