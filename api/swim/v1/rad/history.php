<?php
/** VATSWIM API v1 - RAD Route History — GET */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../../load/services/RADService.php';

global $conn_swim, $conn_adl, $conn_tmi, $conn_gis;
if (!$conn_swim) SwimResponse::error('SWIM database unavailable', 503, 'SERVICE_UNAVAILABLE');
$auth = swim_init_auth(true, false);
swim_check_feature_access($auth, 'rad');
if (!$conn_adl) SwimResponse::error('ADL database unavailable', 503, 'SERVICE_UNAVAILABLE');
$svc = new RADService($conn_adl, $conn_tmi, $conn_gis);

$gufi = swim_get_param('gufi');
if (!$gufi) SwimResponse::error('gufi required', 400, 'BAD_REQUEST');

SwimResponse::json(['success' => true, 'data' => $svc->getRouteHistory($gufi), 'timestamp' => gmdate('c')]);
