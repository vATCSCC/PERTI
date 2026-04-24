<?php
/**
 * VATSWIM API v1 - CTP Release Slot Endpoint
 *
 * Flowcontrol releases a flight's slot assignment. The tmi_slot returns
 * to OPEN status and ctp_flight_control moves to RELEASED. Frozen slots
 * (airborne) can only be released with reason=DISCONNECT.
 *
 * POST /api/swim/v1/ctp/release-slot.php
 */

require_once __DIR__ . '/../auth.php';

global $conn_swim;
if (!$conn_swim) SwimResponse::error('SWIM database not available', 503, 'SERVICE_UNAVAILABLE');

$auth = swim_init_auth(true, true);
if (!$auth->canWriteField('ctp')) {
    SwimResponse::error('CTP write authority required', 403, 'FORBIDDEN');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    SwimResponse::error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
}

$conn_adl = get_conn_adl();
$conn_tmi = get_conn_tmi();
if (!$conn_adl || !$conn_tmi) {
    SwimResponse::error('Required database connections not available', 503, 'SERVICE_UNAVAILABLE');
}

$body = swim_get_json_body();
if (!$body) SwimResponse::error('Invalid JSON body', 400, 'INVALID_REQUEST');

$callsign = strtoupper(trim($body['callsign'] ?? ''));
if (!$callsign || !preg_match('/^[A-Z0-9]{2,12}$/', $callsign)) {
    SwimResponse::error('Valid callsign required', 400, 'INVALID_REQUEST');
}

$sessionRef = $body['session_name'] ?? $body['session_id'] ?? null;
if (!$sessionRef) SwimResponse::error('session_name or session_id required', 400, 'INVALID_REQUEST');

$reason = strtoupper(trim($body['reason'] ?? 'COORDINATOR_RELEASE'));
$validReasons = ['COORDINATOR_RELEASE', 'DISCONNECT', 'MISSED_REASSIGN'];
if (!in_array($reason, $validReasons)) {
    SwimResponse::error('reason must be one of: ' . implode(', ', $validReasons), 400, 'INVALID_REQUEST');
}

require_once __DIR__ . '/../../../../load/services/CTPSlotEngine.php';
$engine = new PERTI\Services\CTPSlotEngine($conn_adl, $conn_tmi, $conn_swim);

$result = $engine->releaseSlot([
    'session_name' => $body['session_name'] ?? null,
    'session_id'   => $body['session_id'] ?? null,
    'callsign'     => $callsign,
    'reason'       => $reason,
]);

if (isset($result['error'])) {
    $httpCode = match ($result['code'] ?? '') {
        'SESSION_NOT_FOUND' => 404,
        'NO_SLOT'           => 404,
        'SLOT_FROZEN'       => 409,
        'QUERY_FAILED'      => 500,
        default => 400,
    };
    SwimResponse::error($result['error'], $httpCode, $result['code'] ?? 'ERROR');
}

SwimResponse::success($result);
