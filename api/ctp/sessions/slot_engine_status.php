<?php
/**
 * CTP Sessions - Slot Engine Status API
 *
 * GET /api/ctp/sessions/slot_engine_status.php?session_id=N
 *
 * Internal session-auth proxy to CTPSlotEngine::getSessionStatus().
 * Returns track utilization, flight status breakdown, and constraint config.
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('CTP_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond_json(405, ['status' => 'error', 'message' => 'Method not allowed. Use GET.']);
}

$conn_tmi = ctp_get_conn_tmi();
$conn_adl = ctp_get_conn_adl();

global $conn_swim;

$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
$session_name = isset($_GET['session_name']) ? trim($_GET['session_name']) : '';

$sessionRef = $session_name ?: ($session_id > 0 ? $session_id : 0);
if (!$sessionRef) {
    respond_json(400, ['status' => 'error', 'message' => 'session_id or session_name is required.']);
}

require_once(__DIR__ . '/../../../load/services/CTPSlotEngine.php');
$engine = new PERTI\Services\CTPSlotEngine($conn_adl, $conn_tmi, $conn_swim);

$result = $engine->getSessionStatus($sessionRef);

if (isset($result['error'])) {
    $code = ($result['code'] ?? '') === 'SESSION_NOT_FOUND' ? 404 : 400;
    respond_json($code, ['status' => 'error', 'message' => $result['error'], 'code' => $result['code'] ?? 'ERROR']);
}

respond_json(200, ['status' => 'ok', 'data' => $result]);
