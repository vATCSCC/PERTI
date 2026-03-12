<?php
/**
 * CTP Flights - Remove EDCT API
 *
 * POST /api/ctp/flights/remove_edct.php
 *
 * Removes the assigned EDCT from a CTP-managed flight.
 *
 * Request body:
 * {
 *   "ctp_control_id": 12345
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

$ctp_control_id = isset($payload['ctp_control_id']) ? (int)$payload['ctp_control_id'] : 0;
if ($ctp_control_id <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'ctp_control_id is required.']);
}

// Get flight
$flight_result = ctp_fetch_one($conn_tmi,
    "SELECT ctp_control_id, session_id, callsign, edct_utc, edct_status, tmi_control_id
     FROM dbo.ctp_flight_control WHERE ctp_control_id = ?",
    [$ctp_control_id]
);
if (!$flight_result['success'] || !$flight_result['data']) {
    respond_json(404, ['status' => 'error', 'message' => 'Flight not found.']);
}
$flight = $flight_result['data'];
$session_id = (int)$flight['session_id'];

// Validate session
$session = ctp_get_session($conn_tmi, $session_id);
if (!$session) {
    respond_json(404, ['status' => 'error', 'message' => 'Session not found.']);
}
if (!in_array($session['status'], ['ACTIVE', 'MONITORING'])) {
    respond_json(409, ['status' => 'error', 'message' => 'Session must be ACTIVE or MONITORING.']);
}

$old_edct = $flight['edct_utc'];

// Clear EDCT fields
$result = ctp_execute($conn_tmi,
    "UPDATE dbo.ctp_flight_control SET
        edct_utc = NULL,
        edct_status = 'NONE',
        slot_delay_min = NULL,
        edct_assigned_by = NULL,
        edct_assigned_at = NULL,
        swim_push_version = swim_push_version + 1
     WHERE ctp_control_id = ?",
    [$ctp_control_id]
);
if (!$result['success']) {
    respond_json(500, ['status' => 'error', 'message' => 'Failed to remove EDCT.']);
}

// Remove tmi_flight_control record if exists
if ($flight['tmi_control_id']) {
    ctp_execute($conn_tmi,
        "DELETE FROM dbo.tmi_flight_control WHERE control_id = ?",
        [(int)$flight['tmi_control_id']]
    );
    ctp_execute($conn_tmi,
        "UPDATE dbo.ctp_flight_control SET tmi_control_id = NULL WHERE ctp_control_id = ?",
        [$ctp_control_id]
    );
}

// Audit log
ctp_audit_log($conn_tmi, $session_id, $ctp_control_id, 'EDCT_REMOVE', [
    'old_edct' => $old_edct ? datetime_to_iso($old_edct) : null
], $cid);

// SWIM push
ctp_push_swim_event('ctp.edct.removed', [
    'session_id' => $session_id,
    'ctp_control_id' => $ctp_control_id,
    'callsign' => $flight['callsign']
]);

respond_json(200, [
    'status' => 'ok',
    'data' => [
        'ctp_control_id' => $ctp_control_id,
        'edct_status' => 'NONE'
    ]
]);
