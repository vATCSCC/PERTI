<?php
/**
 * VATSWIM Connector Health Endpoint
 *
 * Lightweight aggregate health check across all VATSWIM external connectors.
 * Returns overall status (OK/DEGRADED/DOWN) and per-connector summary.
 *
 * Access: Any valid SWIM API key OR localhost
 *
 * GET /api/swim/v1/connectors/health.php
 *
 * Response:
 *   {
 *     "status": "OK",
 *     "connectors": [
 *       {"name": "vNAS", "key": "vnas", "status": "OK"},
 *       {"name": "SimTraffic", "key": "simtraffic", "status": "DOWN"},
 *       ...
 *     ],
 *     "checked_at": "2026-03-06T12:00:00Z"
 *   }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once(__DIR__ . '/../../../../load/config.php');

// Auth: localhost or any valid-looking SWIM key
$isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost']);
$apiKey = $_GET['key'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$apiKey = str_replace('Bearer ', '', $apiKey);
$validKeyFormat = preg_match('/^swim_(sys|par|dev|pub)_/', $apiKey);
$monitoringKey = (defined('MONITORING_API_KEY') && $apiKey === MONITORING_API_KEY);

if (!$isLocalhost && !$validKeyFormat && !$monitoringKey) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'message' => 'Provide a valid SWIM API key or access from localhost']);
    exit;
}

// Load connector framework
require_once(__DIR__ . '/../../../../lib/connectors/CircuitBreaker.php');
require_once(__DIR__ . '/../../../../lib/connectors/ConnectorInterface.php');
require_once(__DIR__ . '/../../../../lib/connectors/AbstractConnector.php');
require_once(__DIR__ . '/../../../../lib/connectors/ConnectorRegistry.php');
require_once(__DIR__ . '/../../../../lib/connectors/ConnectorHealth.php');

use PERTI\Lib\Connectors\ConnectorHealth;

$result = ConnectorHealth::getAggregate();

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
