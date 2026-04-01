<?php
/** RAD API: Amendment CRUD — GET/POST/DELETE /api/rad/amendment.php */
define('RAD_API_INCLUDED', true);
require_once __DIR__ . '/common.php';

$cid = rad_require_auth();
$svc = rad_get_service();
$method = $_SERVER['REQUEST_METHOD'];
$body = rad_read_payload();
$action = $body['action'] ?? $_GET['action'] ?? null;

// POST and DELETE operations require TMU-level permission
if ($method === 'POST' || $method === 'DELETE') {
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
        // Create new amendment — accept both JS and direct field names
        $route = $body['route'] ?? $body['assigned_route'] ?? null;

        // JS sends flights[] (array of GUFIs) or gufi (single)
        $gufis = [];
        if (!empty($body['flights']) && is_array($body['flights'])) {
            $gufis = $body['flights'];
        } elseif (!empty($body['gufi'])) {
            $gufis = [$body['gufi']];
        }

        if (empty($gufis) || !$route) {
            rad_respond_json(400, ['status' => 'error', 'message' => 'flights/gufi and route/assigned_route required']);
        }

        // Channels: accept array or comma-separated string
        $channels = $body['channels'] ?? $body['delivery_channels'] ?? 'CPDLC,SWIM';
        if (is_array($channels)) $channels = implode(',', $channels);

        $options = [
            'delivery_channels' => $channels,
            'tmi_reroute_id'    => $body['tmi_reroute_id'] ?? null,
            'tmi_id_label'      => $body['tmi_id'] ?? $body['tmi_id_label'] ?? null,
            'route_color'       => $body['route_color'] ?? null,
            'notes'             => $body['notes'] ?? null,
            'send'              => ($action === 'create') ? false : !empty($body['send']),
            'created_by'        => (int)$cid,
        ];

        // Create amendment for each GUFI
        $results = [];
        $errors = [];
        foreach ($gufis as $gufi) {
            $result = $svc->createAmendment($gufi, $route, $options);
            if (isset($result['error'])) {
                $errors[] = $gufi . ': ' . $result['error'];
            } else {
                $results[] = $result;
            }
        }

        if (!empty($errors) && empty($results)) {
            rad_respond_json(400, ['status' => 'error', 'message' => implode('; ', $errors)]);
        }
        rad_respond_json(201, ['status' => 'ok', 'data' => $results, 'errors' => $errors]);
    }

} elseif ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? $body['id'] ?? 0);
    if (!$id) rad_respond_json(400, ['status' => 'error', 'message' => 'id required']);
    $result = $svc->cancelAmendment($id, (int)$cid);
    if (isset($result['error'])) rad_respond_json(400, ['status' => 'error', 'message' => $result['error']]);
    rad_respond_json(200, ['status' => 'ok', 'data' => $result]);

} else {
    rad_respond_json(405, ['status' => 'error', 'message' => 'Method not allowed']);
}
