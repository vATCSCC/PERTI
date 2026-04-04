<?php
/** RAD API: TOS (Trajectory Option Set) — GET/POST /api/rad/tos.php */
define('RAD_API_INCLUDED', true);
require_once __DIR__ . '/common.php';

$cid = rad_require_auth();
$svc = rad_get_service();
$method = $_SERVER['REQUEST_METHOD'];
$body = rad_read_payload();
$action = $body['action'] ?? $_GET['action'] ?? null;

if ($method === 'GET') {
    $amendment_id = (int)($_GET['amendment_id'] ?? 0);
    if (!$amendment_id) rad_respond_json(400, ['status' => 'error', 'message' => 'amendment_id required']);

    $tos = $svc->getTOS($amendment_id);
    if (!$tos) rad_respond_json(404, ['status' => 'error', 'message' => 'No TOS found']);
    rad_respond_json(200, ['status' => 'ok', 'data' => $tos]);

} elseif ($method === 'POST') {

    if ($action === 'resolve') {
        // TMU resolves TOS
        rad_require_tmu($cid);
        $tos_id = (int)($body['tos_id'] ?? 0);
        if (!$tos_id) rad_respond_json(400, ['status' => 'error', 'message' => 'tos_id required']);

        $resolve_action = strtoupper($body['resolve_action'] ?? '');
        if (!in_array($resolve_action, ['ACCEPT', 'COUNTER', 'FORCE'])) {
            rad_respond_json(400, ['status' => 'error', 'message' => 'resolve_action must be ACCEPT, COUNTER, or FORCE']);
        }

        $result = $svc->resolveTOS(
            $tos_id,
            $resolve_action,
            isset($body['accepted_rank']) ? (int)$body['accepted_rank'] : null,
            $body['counter_route'] ?? null,
            (int)$cid
        );
        if (isset($result['error'])) rad_respond_json(400, ['status' => 'error', 'message' => $result['error']]);
        rad_respond_json(200, ['status' => 'ok', 'data' => $result]);

    } else {
        // Submit TOS (ATC/Pilot/VA)
        require_once __DIR__ . '/../../load/services/VNASService.php';
        $role = rad_detect_role((int)$cid);
        if (!in_array($role, ['ATC', 'PILOT', 'VA', 'TMU'])) {
            rad_respond_json(403, ['status' => 'error', 'message' => 'Role not authorized for TOS submission']);
        }

        $amendment_id = (int)($body['amendment_id'] ?? 0);
        if (!$amendment_id) rad_respond_json(400, ['status' => 'error', 'message' => 'amendment_id required']);

        $options = $body['options'] ?? [];
        if (empty($options)) rad_respond_json(400, ['status' => 'error', 'message' => 'At least one option required']);

        $result = $svc->submitTOS($amendment_id, (int)$cid, $role, $options);
        if (isset($result['error'])) rad_respond_json(400, ['status' => 'error', 'message' => $result['error']]);
        rad_respond_json(201, ['status' => 'ok', 'data' => $result]);
    }

} else {
    rad_respond_json(405, ['status' => 'error', 'message' => 'Method not allowed']);
}
