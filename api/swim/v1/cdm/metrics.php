<?php
/**
 * CDM Metrics API Endpoint
 *
 * Returns CDM effectiveness metrics — delivery rates, compliance rates,
 * pilot participation, and readiness signal adoption.
 *
 * GET /api/swim/v1/cdm/metrics
 * GET /api/swim/v1/cdm/metrics?program_id=123
 *
 * Access: Requires valid SWIM API key
 * SWIM-isolated: reads from SWIM_API mirror tables only
 *
 * @package PERTI
 * @subpackage CDM
 * @version 2.0.0
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../../load/services/CDMService.php';

SwimResponse::handlePreflight();
$auth = swim_init_auth(true);

$conn_swim = get_conn_swim();
if (!$conn_swim) {
    SwimResponse::error('SWIM database connection not available', 503);
}

$program_id = swim_get_param('program_id');
if ($program_id) $program_id = (int)$program_id;

$cdm = new CDMService($conn_swim);
$metrics = $cdm->getMetrics($program_id);

SwimResponse::success([
    'program_id' => $program_id,
    'metrics' => $metrics,
    'timestamp' => gmdate('c')
]);
