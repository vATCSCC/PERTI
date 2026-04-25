<?php
/**
 * VATSWIM API v1 - CTP Generate Slots Endpoint
 *
 * Triggers slot grid generation for a CTP session. Creates tmi_programs
 * entries for each track and generates tmi_slots via sp_TMI_GenerateSlots.
 * Must be called after push-tracks and before request-slot.
 *
 * Idempotent — re-calling skips tracks that already have a program_id.
 *
 * POST /api/swim/v1/ctp/generate-slots.php
 * Body: { "session_name": "CTPE26" } or { "session_id": 9 }
 *
 * @version 1.0.0
 * @since 2026-04-25
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

$sessionRef = $body['session_name'] ?? $body['session_id'] ?? null;
if (!$sessionRef) SwimResponse::error('session_name or session_id required', 400, 'INVALID_REQUEST');

require_once __DIR__ . '/../../../../load/services/GISService.php';
require_once __DIR__ . '/../../../../load/services/CTPSlotEngine.php';

$gisService = GISService::getInstance();
$engine = new PERTI\Services\CTPSlotEngine($conn_adl, $conn_tmi, $conn_swim, $gisService);

$session = $engine->resolveSession($sessionRef);
if (!$session) SwimResponse::error('Session not found', 404, 'SESSION_NOT_FOUND');

$status = $session['status'] ?? '';
if (!in_array($status, ['DRAFT', 'ACTIVE'])) {
    SwimResponse::error('Session must be DRAFT or ACTIVE to generate slots', 409, 'SESSION_NOT_ACTIVE');
}

$result = $engine->generateSlotGrid((int)$session['session_id']);

if (isset($result['error'])) {
    $httpCode = match ($result['code'] ?? '') {
        'SESSION_NOT_FOUND' => 404,
        'NO_TRACKS_CONFIGURED' => 409,
        'QUERY_FAILED' => 500,
        default => 400,
    };
    SwimResponse::error($result['error'], $httpCode, $result['code'] ?? 'ERROR');
}

SwimResponse::success($result);
