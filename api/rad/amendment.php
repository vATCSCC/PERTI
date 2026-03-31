<?php
/** RAD API: Amendment CRUD — GET/POST /api/rad/amendment.php */
define('RAD_API_INCLUDED', true);
require_once __DIR__ . '/common.php';

$cid = rad_require_auth();
$svc = rad_get_service();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

// POST operations require TMU-level permission
if ($method === 'POST') {
    rad_require_tmu($cid);
}

if ($method === 'GET') {
    $filters = [
        'gufi'           => $_GET['gufi'] ?? null,
        'status'         => $_GET['status'] ?? null,
        'tmi_reroute_id' => $_GET['tmi_reroute_id'] ?? null,
        'page'           => $_GET['page'] ?? 1,
        'limit'          => $_GET['limit'] ?? 50,
    ];
    $result = $svc->getAmendments($filters);
    rad_respond_json(200, ['status' => 'ok', 'data' => $result]);

} elseif ($method === 'POST') {
    $body = rad_read_payload();

    if ($action === 'send') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) rad_respond_json(400, ['status' => 'error', 'message' => 'id required']);
        $result = $svc->sendAmendment($id, (int)$cid);
        if (isset($result['error'])) rad_respond_json(400, ['status' => 'error', 'message' => $result['error']]);
        rad_respond_json(200, ['status' => 'ok', 'data' => $result]);

    } elseif ($action === 'resend') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) rad_respond_json(400, ['status' => 'error', 'message' => 'id required']);
        $result = $svc->resendAmendment($id, (int)$cid);
        if (isset($result['error'])) rad_respond_json(400, ['status' => 'error', 'message' => $result['error']]);
        rad_respond_json(200, ['status' => 'ok', 'data' => $result]);

    } elseif ($action === 'cancel') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) rad_respond_json(400, ['status' => 'error', 'message' => 'id required']);
        $result = $svc->cancelAmendment($id, (int)$cid);
        if (isset($result['error'])) rad_respond_json(400, ['status' => 'error', 'message' => $result['error']]);
        rad_respond_json(200, ['status' => 'ok', 'data' => $result]);

    } else {
        // Create new amendment
        $gufi = $body['gufi'] ?? null;
        $route = $body['assigned_route'] ?? null;
        if (!$gufi || !$route) {
            rad_respond_json(400, ['status' => 'error', 'message' => 'gufi and assigned_route required']);
        }
        $options = [
            'delivery_channels' => $body['delivery_channels'] ?? 'CPDLC,SWIM',
            'tmi_reroute_id'    => $body['tmi_reroute_id'] ?? null,
            'tmi_id_label'      => $body['tmi_id_label'] ?? null,
            'route_color'       => $body['route_color'] ?? null,
            'notes'             => $body['notes'] ?? null,
            'send'              => !empty($body['send']),
            'created_by'        => (int)$cid,
        ];
        $result = $svc->createAmendment($gufi, $route, $options);
        if (isset($result['error'])) rad_respond_json(400, ['status' => 'error', 'message' => $result['error']]);
        rad_respond_json(201, ['status' => 'ok', 'data' => $result]);
    }

} else {
    rad_respond_json(405, ['status' => 'error', 'message' => 'Method not allowed']);
}
