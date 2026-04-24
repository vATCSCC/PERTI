<?php
/**
 * VATSWIM API v1 - CTP Request Slot Endpoint
 *
 * Flowcontrol requests ranked slot candidates for a flight. Returns the
 * recommended slot (fewest advisories, preferred track priority) plus up
 * to 5 alternatives. Does NOT assign — use confirm-slot.php to claim.
 *
 * POST /api/swim/v1/ctp/request-slot.php
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

require_once __DIR__ . '/../../../../load/services/GISService.php';
require_once __DIR__ . '/../../../../load/services/CTPSlotEngine.php';

$conn_gis = get_conn_gis();
$gisService = $conn_gis ? new PERTI\Services\GISService($conn_gis) : null;
$engine = new PERTI\Services\CTPSlotEngine($conn_adl, $conn_tmi, $conn_swim, $gisService);

$result = $engine->requestSlot([
    'session_name'    => $body['session_name'] ?? null,
    'session_id'      => $body['session_id'] ?? null,
    'callsign'        => $callsign,
    'origin'          => strtoupper(trim($body['origin'] ?? '')),
    'destination'     => strtoupper(trim($body['destination'] ?? '')),
    'aircraft_type'   => strtoupper(trim($body['aircraft_type'] ?? '')),
    'preferred_track' => strtoupper(trim($body['preferred_track'] ?? '')),
    'tobt'            => $body['tobt'] ?? null,
    'is_airborne'     => (bool)($body['is_airborne'] ?? false),
    'na_route'        => $body['na_route'] ?? '',
    'eu_route'        => $body['eu_route'] ?? '',
]);

if (isset($result['error'])) {
    $httpCode = match ($result['code'] ?? '') {
        'SESSION_NOT_FOUND' => 404,
        'FLIGHT_NOT_FOUND'  => 404,
        'SESSION_NOT_ACTIVE', 'SLOTS_NOT_READY', 'NO_TRACKS_CONFIGURED' => 409,
        default => 400,
    };
    SwimResponse::error($result['error'], $httpCode, $result['code'] ?? 'ERROR');
}

SwimResponse::success($result);
