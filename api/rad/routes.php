<?php
/** RAD API: Route Options & Recently Sent — GET /api/rad/routes.php */
define('RAD_API_INCLUDED', true);
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    rad_respond_json(405, ['status' => 'error', 'message' => 'GET only']);
}

rad_require_auth();
$svc = rad_get_service();
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

} else {
    rad_respond_json(400, ['status' => 'error', 'message' => 'Invalid source param']);
}
