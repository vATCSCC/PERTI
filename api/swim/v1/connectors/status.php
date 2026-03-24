<?php
/**
 * VATSWIM Connector Status Endpoint
 *
 * Detailed per-connector status including health, circuit breaker state,
 * endpoints, configuration, and daemon status.
 *
 * Access: Requires swim_sys_ or swim_par_ tier API key
 *
 * GET /api/swim/v1/connectors/status.php
 *
 * Response:
 *   {
 *     "status": "OK",
 *     "connectors": {
 *       "vnas": { "name": "vNAS", "type": "push", "status": "OK", ... },
 *       "simtraffic": { "name": "SimTraffic", "type": "bidirectional", ... },
 *       ...
 *     },
 *     "checked_at": "2026-03-06T12:00:00Z"
 *   }
 */

require_once(__DIR__ . '/../../../../load/config.php');
require_once(__DIR__ . '/../../../../load/perti_constants.php');

header('Content-Type: application/json; charset=utf-8');
perti_set_cors();
header('Cache-Control: no-cache, no-store, must-revalidate');

// Auth: require system or partner tier key
$apiKey = $_GET['key'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$apiKey = str_replace('Bearer ', '', $apiKey);
$isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost']);
$isSystemOrPartner = preg_match('/^swim_(sys|par)_/', $apiKey);
$monitoringKey = (defined('MONITORING_API_KEY') && $apiKey === MONITORING_API_KEY);

if (!$isLocalhost && !$isSystemOrPartner && !$monitoringKey) {
    http_response_code(403);
    echo json_encode([
        'error' => 'Forbidden',
        'message' => 'Requires system (swim_sys_) or partner (swim_par_) tier API key',
    ]);
    exit;
}

// Load connector framework
require_once(__DIR__ . '/../../../../lib/connectors/CircuitBreaker.php');
require_once(__DIR__ . '/../../../../lib/connectors/ConnectorInterface.php');
require_once(__DIR__ . '/../../../../lib/connectors/AbstractConnector.php');
require_once(__DIR__ . '/../../../../lib/connectors/ConnectorRegistry.php');
require_once(__DIR__ . '/../../../../lib/connectors/ConnectorHealth.php');

use PERTI\Lib\Connectors\ConnectorHealth;

$result = ConnectorHealth::getDetailed();

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
