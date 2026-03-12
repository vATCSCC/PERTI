<?php
/**
 * CTP Sessions - Complete Session API
 *
 * POST /api/ctp/sessions/complete.php
 *
 * Marks a CTP session as COMPLETED or CANCELLED.
 *
 * Request body:
 * {
 *   "session_id": 1,
 *   "status": "COMPLETED"        (COMPLETED or CANCELLED)
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
$conn_tmi = ctp_get_conn_tmi();
$payload = read_request_payload();

$session_id = isset($payload['session_id']) ? (int)$payload['session_id'] : 0;
if ($session_id <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'session_id is required.']);
}

$new_status = isset($payload['status']) ? strtoupper(trim($payload['status'])) : 'COMPLETED';
if (!in_array($new_status, ['COMPLETED', 'CANCELLED'])) {
    respond_json(400, ['status' => 'error', 'message' => 'status must be COMPLETED or CANCELLED.']);
}

// Get session
$session = ctp_get_session($conn_tmi, $session_id);
if (!$session) {
    respond_json(404, ['status' => 'error', 'message' => 'Session not found.']);
}

$allowed_from = ['ACTIVE', 'MONITORING', 'DRAFT'];
if (!in_array($session['status'], $allowed_from)) {
    respond_json(409, ['status' => 'error', 'message' => 'Session cannot be completed from status: ' . $session['status']]);
}

// Update final stats
$stats_sql = "
    SELECT
        COUNT(*) AS total_flights,
        SUM(CASE WHEN edct_status != 'NONE' AND is_excluded = 0 THEN 1 ELSE 0 END) AS slotted_flights,
        SUM(CASE WHEN route_status = 'MODIFIED' AND is_excluded = 0 THEN 1 ELSE 0 END) AS modified_flights
    FROM dbo.ctp_flight_control WHERE session_id = ?
";
$stats_result = ctp_fetch_one($conn_tmi, $stats_sql, [$session_id]);
$stats = $stats_result['data'] ?? [];

$result = ctp_execute($conn_tmi,
    "UPDATE dbo.ctp_sessions SET
        status = ?,
        total_flights = ?,
        slotted_flights = ?,
        modified_flights = ?,
        updated_at = SYSUTCDATETIME()
     WHERE session_id = ?",
    [
        $new_status,
        (int)($stats['total_flights'] ?? 0),
        (int)($stats['slotted_flights'] ?? 0),
        (int)($stats['modified_flights'] ?? 0),
        $session_id
    ]
);

if (!$result['success']) {
    respond_json(500, ['status' => 'error', 'message' => 'Failed to complete session.']);
}

ctp_audit_log($conn_tmi, $session_id, null, 'SESSION_' . $new_status, [
    'old_status' => $session['status'],
    'final_stats' => $stats
], $cid);

ctp_push_swim_event('ctp.session.' . strtolower($new_status), [
    'session_id' => $session_id,
    'session_name' => $session['session_name'] ?? ''
]);

respond_json(200, [
    'status' => 'ok',
    'data' => [
        'session_id' => $session_id,
        'status' => $new_status
    ]
]);
