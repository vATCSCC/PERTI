<?php
/** VATSWIM API v1 - RAD Compliance — GET */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../../load/services/RADService.php';

global $conn_swim, $conn_adl, $conn_tmi, $conn_gis;
if (!$conn_swim) SwimResponse::error('SWIM database unavailable', 503, 'SERVICE_UNAVAILABLE');

$auth = swim_init_auth(true, false);
swim_check_feature_access($auth, 'rad');

if (!$conn_tmi) SwimResponse::error('TMI database unavailable', 503, 'SERVICE_UNAVAILABLE');

$svc = new RADService($conn_adl, $conn_tmi, $conn_gis);

$filters = [
    'amendment_ids'  => swim_get_param('amendment_ids'),
    'tmi_reroute_id' => swim_get_param('tmi_reroute_id'),
];

$result = $svc->getCompliance($filters);
SwimResponse::json(['success' => true, 'data' => $result, 'timestamp' => gmdate('c')]);
