<?php
/** RAD API: Route Options, CDR Lookup, Recently Sent, Validate — GET/POST /api/rad/routes.php */
define('RAD_API_INCLUDED', true);
require_once __DIR__ . '/common.php';

rad_require_auth();
$svc = rad_get_service();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    // POST: route validation
    $body = rad_read_payload();
    $action = $body['action'] ?? null;

    if ($action === 'validate') {
        $route = $body['route'] ?? null;
        if (!$route) rad_respond_json(400, ['status' => 'error', 'message' => 'route required']);
        $result = $svc->validateRoute($route);
        if ($result['valid']) {
            rad_respond_json(200, ['status' => 'ok', 'message' => 'Route valid (' . ($result['waypoints'] ?? 0) . ' waypoints)']);
        } else {
            rad_respond_json(200, ['status' => 'error', 'message' => $result['error'] ?? $result['warning'] ?? 'Route validation failed']);
        }
    } else {
        rad_respond_json(400, ['status' => 'error', 'message' => 'Unknown POST action']);
    }

} elseif ($method === 'GET') {
    $source = $_GET['source'] ?? 'options';

    if ($source === 'recent') {
        $origin = $_GET['origin'] ?? null;
        $dest = $_GET['destination'] ?? null;
        if (!$origin || !$dest) {
            rad_respond_json(400, ['status' => 'error', 'message' => 'origin and destination required']);
        }
        $result = $svc->getRecentRoutes($origin, $dest);
        rad_respond_json(200, ['status' => 'ok', 'data' => $result]);

    } elseif ($source === 'options') {
        $gufi = $_GET['gufi'] ?? null;
        if (!$gufi) rad_respond_json(400, ['status' => 'error', 'message' => 'gufi required']);
        $result = $svc->getRouteOptions($gufi);
        if (isset($result['error'])) rad_respond_json(404, ['status' => 'error', 'message' => $result['error']]);
        rad_respond_json(200, ['status' => 'ok', 'data' => $result]);

    } elseif ($source === 'cdr') {
        $code = $_GET['code'] ?? null;
        if (!$code) rad_respond_json(400, ['status' => 'error', 'message' => 'code required']);
        $result = $svc->getCDRRoute($code);
        if (isset($result['error'])) rad_respond_json(404, ['status' => 'error', 'message' => $result['error']]);
        rad_respond_json(200, ['status' => 'ok', 'data' => $result]);

    } else {
        rad_respond_json(400, ['status' => 'error', 'message' => 'Invalid source param']);
    }

} else {
    rad_respond_json(405, ['status' => 'error', 'message' => 'Method not allowed']);
}
