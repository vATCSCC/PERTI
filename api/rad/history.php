<?php
/** RAD API: Route Change History — GET /api/rad/history.php */
define('RAD_API_INCLUDED', true);
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    rad_respond_json(405, ['status' => 'error', 'message' => 'GET only']);
}

rad_require_auth();
$svc = rad_get_service();

$gufi = $_GET['gufi'] ?? null;
if (!$gufi) rad_respond_json(400, ['status' => 'error', 'message' => 'gufi required']);

$result = $svc->getRouteHistory($gufi);
rad_respond_json(200, ['status' => 'ok', 'data' => $result]);
