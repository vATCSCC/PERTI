<?php
/** RAD API: Flight Search — GET /api/rad/search.php */
define('RAD_API_INCLUDED', true);
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    rad_respond_json(405, ['status' => 'error', 'message' => 'GET only']);
}

rad_require_auth();
$svc = rad_get_service();

$filters = [
    'cs'           => $_GET['cs'] ?? null,
    'orig'         => $_GET['orig'] ?? null,
    'dest'         => $_GET['dest'] ?? null,
    'orig_tracon'  => $_GET['orig_tracon'] ?? null,
    'orig_center'  => $_GET['orig_center'] ?? null,
    'dest_tracon'  => $_GET['dest_tracon'] ?? null,
    'dest_center'  => $_GET['dest_center'] ?? null,
    'type'         => $_GET['type'] ?? null,
    'carrier'      => $_GET['carrier'] ?? null,
    'time_field'   => $_GET['time_field'] ?? 'etd',
    'time_start'   => $_GET['time_start'] ?? null,
    'time_end'     => $_GET['time_end'] ?? null,
    'route'        => $_GET['route'] ?? null,
    'status'       => $_GET['status'] ?? null,
    'page'         => $_GET['page'] ?? 1,
    'limit'        => $_GET['limit'] ?? 50,
];

$result = $svc->searchFlights($filters);
rad_respond_json(200, ['status' => 'ok', 'data' => $result['flights'], 'total' => $result['total']]);
