<?php
/** VATSWIM API v1 - RAD Route Options — GET */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../../load/services/RADService.php';

global $conn_swim, $conn_adl, $conn_tmi, $conn_gis;
if (!$conn_swim) SwimResponse::error('SWIM database unavailable', 503, 'SERVICE_UNAVAILABLE');
$auth = swim_init_auth(true, false);
swim_check_feature_access($auth, 'rad');
if (!$conn_tmi) SwimResponse::error('TMI database unavailable', 503, 'SERVICE_UNAVAILABLE');
$svc = new RADService($conn_adl, $conn_tmi, $conn_gis);

$source = swim_get_param('source', 'options');

if ($source === 'recent') {
    $origin = swim_get_param('origin');
    $dest = swim_get_param('destination');
    if (!$origin || !$dest) SwimResponse::error('origin and destination required', 400, 'BAD_REQUEST');
    SwimResponse::json(['success' => true, 'data' => $svc->getRecentRoutes($origin, $dest)]);
} else {
    $gufi = swim_get_param('gufi');
    if (!$gufi) SwimResponse::error('gufi required', 400, 'BAD_REQUEST');
    $result = $svc->getRouteOptions($gufi);
    if (isset($result['error'])) SwimResponse::error($result['error'], 404, 'NOT_FOUND');
    SwimResponse::json(['success' => true, 'data' => $result]);
}
