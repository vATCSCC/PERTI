<?php
/**
 * VATSWIM API v1 - RAD Amendments
 * GET  /api/swim/v1/rad/amendments — list amendments
 * POST /api/swim/v1/rad/amendments — create/send/cancel
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../../load/services/RADService.php';

global $conn_swim, $conn_adl, $conn_tmi, $conn_gis;
if (!$conn_swim) SwimResponse::error('SWIM database unavailable', 503, 'SERVICE_UNAVAILABLE');

$method = $_SERVER['REQUEST_METHOD'];
$require_write = ($method === 'POST');
$auth = swim_init_auth(true, $require_write);
swim_check_feature_access($auth, 'rad');

if (!$conn_tmi) SwimResponse::error('TMI database unavailable', 503, 'SERVICE_UNAVAILABLE');

$svc = new RADService($conn_adl, $conn_tmi, $conn_gis);

if ($method === 'GET') {
    $filters = [
        'gufi'           => swim_get_param('gufi'),
        'status'         => swim_get_param('status'),
        'tmi_reroute_id' => swim_get_param('tmi_reroute_id'),
        'page'           => swim_get_int_param('page', 1, 1, 1000),
        'limit'          => swim_get_int_param('per_page', 50, 1, 200),
    ];
    $result = $svc->getAmendments($filters);
    SwimResponse::json(['success' => true, 'data' => $result, 'timestamp' => gmdate('c')]);

} elseif ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = swim_get_param('action');

    if ($action === 'send') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) SwimResponse::error('id required', 400, 'BAD_REQUEST');
        $result = $svc->sendAmendment($id);
        if (isset($result['error'])) SwimResponse::error($result['error'], 400, 'BAD_REQUEST');
        SwimResponse::json(['success' => true, 'data' => $result]);

    } elseif ($action === 'cancel') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) SwimResponse::error('id required', 400, 'BAD_REQUEST');
        $result = $svc->cancelAmendment($id);
        if (isset($result['error'])) SwimResponse::error($result['error'], 400, 'BAD_REQUEST');
        SwimResponse::json(['success' => true, 'data' => $result]);

    } else {
        $gufi = $body['gufi'] ?? null;
        $route = $body['assigned_route'] ?? null;
        if (!$gufi || !$route) SwimResponse::error('gufi and assigned_route required', 400, 'BAD_REQUEST');
        $options = [
            'delivery_channels' => $body['delivery_channels'] ?? 'CPDLC,SWIM',
            'tmi_reroute_id'    => $body['tmi_reroute_id'] ?? null,
            'tmi_id_label'      => $body['tmi_id_label'] ?? null,
            'route_color'       => $body['route_color'] ?? null,
            'notes'             => $body['notes'] ?? null,
            'send'              => !empty($body['send']),
        ];
        $result = $svc->createAmendment($gufi, $route, $options);
        if (isset($result['error'])) SwimResponse::error($result['error'], 400, 'BAD_REQUEST');
        SwimResponse::json(['success' => true, 'data' => $result], 201);
    }
} else {
    SwimResponse::error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
}
