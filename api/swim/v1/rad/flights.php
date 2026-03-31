<?php
/**
 * VATSWIM API v1 - RAD Flight Search
 * GET /api/swim/v1/rad/flights
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../../load/services/RADService.php';

global $conn_swim;
if (!$conn_swim) SwimResponse::error('SWIM database unavailable', 503, 'SERVICE_UNAVAILABLE');

$auth = swim_init_auth(true, false);

// RAD feature gate check
swim_check_feature_access($auth, 'rad');

// Need ADL connection for flight search
global $conn_adl, $conn_tmi, $conn_gis;
if (!$conn_adl) SwimResponse::error('ADL database unavailable', 503, 'SERVICE_UNAVAILABLE');

$svc = new RADService($conn_adl, $conn_tmi, $conn_gis);

$filters = [
    'cs'           => swim_get_param('cs'),
    'orig'         => swim_get_param('orig'),
    'dest'         => swim_get_param('dest'),
    'orig_tracon'  => swim_get_param('orig_tracon'),
    'orig_center'  => swim_get_param('orig_center'),
    'dest_tracon'  => swim_get_param('dest_tracon'),
    'dest_center'  => swim_get_param('dest_center'),
    'type'         => swim_get_param('type'),
    'carrier'      => swim_get_param('carrier'),
    'time_field'   => swim_get_param('time_field', 'etd'),
    'time_start'   => swim_get_param('time_start'),
    'time_end'     => swim_get_param('time_end'),
    'route'        => swim_get_param('route'),
    'status'       => swim_get_param('status'),
    'page'         => swim_get_int_param('page', 1, 1, 1000),
    'limit'        => swim_get_int_param('per_page', 50, 1, 200),
];

$result = $svc->searchFlights($filters);

SwimResponse::json([
    'success' => true,
    'data' => $result['flights'],
    'pagination' => [
        'total' => $result['total'],
        'page' => (int)$filters['page'],
        'per_page' => (int)$filters['limit'],
    ],
    'timestamp' => gmdate('c'),
]);
