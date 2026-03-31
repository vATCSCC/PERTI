<?php
/** RAD API: Compliance Polling — GET /api/rad/compliance.php */
define('RAD_API_INCLUDED', true);
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    rad_respond_json(405, ['status' => 'error', 'message' => 'GET only']);
}

rad_require_auth();
$svc = rad_get_service();

$filters = [
    'amendment_ids'  => $_GET['amendment_ids'] ?? null,
    'tmi_reroute_id' => $_GET['tmi_reroute_id'] ?? null,
];

$result = $svc->getCompliance($filters);
rad_respond_json(200, ['status' => 'ok', 'data' => $result]);
