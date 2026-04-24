<?php
/**
 * VATSWIM API v1 - CTP Session Status Endpoint
 *
 * Read-only view of session health: track utilization, configured constraints,
 * and flight status breakdown. Used by flowcontrol dashboards and the CTP UI.
 *
 * GET /api/swim/v1/ctp/session-status.php?session_name=CTPE26
 * GET /api/swim/v1/ctp/session-status.php?session_id=1
 */

require_once __DIR__ . '/../auth.php';

global $conn_swim;
if (!$conn_swim) SwimResponse::error('SWIM database not available', 503, 'SERVICE_UNAVAILABLE');

$auth = swim_init_auth(true, false);

$conn_adl = get_conn_adl();
$conn_tmi = get_conn_tmi();
if (!$conn_tmi) SwimResponse::error('TMI database not available', 503, 'SERVICE_UNAVAILABLE');

$sessionRef = swim_get_param('session_name') ?? swim_get_int_param('session_id', 0);
if (!$sessionRef) {
    SwimResponse::error('session_name or session_id query parameter required', 400, 'INVALID_REQUEST');
}

require_once __DIR__ . '/../../../../load/services/CTPSlotEngine.php';
$engine = new PERTI\Services\CTPSlotEngine($conn_adl, $conn_tmi, $conn_swim);

$result = $engine->getSessionStatus($sessionRef);

if (isset($result['error'])) {
    $httpCode = ($result['code'] ?? '') === 'SESSION_NOT_FOUND' ? 404 : 400;
    SwimResponse::error($result['error'], $httpCode, $result['code'] ?? 'ERROR');
}

SwimResponse::success($result);
