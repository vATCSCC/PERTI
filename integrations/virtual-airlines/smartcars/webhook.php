<?php
/**
 * VATSWIM smartCARS Webhook Endpoint
 *
 * Deploy this file to your web server and configure smartCARS to send webhooks here.
 *
 * Configuration:
 *   1. Set VATSWIM_API_KEY environment variable or edit below
 *   2. Set SMARTCARS_WEBHOOK_SECRET environment variable
 *   3. Configure smartCARS webhook URL to point to this file
 *
 * @package VATSWIM
 * @subpackage smartCARS Integration
 * @version 1.0.0
 */

// Configuration
$config = [
    'vatswim_api_key' => getenv('VATSWIM_API_KEY') ?: 'your_vatswim_api_key_here',
    'vatswim_base_url' => getenv('VATSWIM_BASE_URL') ?: 'https://perti.vatcscc.org/api/swim/v1',
    'webhook_secret' => getenv('SMARTCARS_WEBHOOK_SECRET') ?: '',
    'verbose' => getenv('VATSWIM_VERBOSE') === 'true'
];

// Autoload classes
spl_autoload_register(function ($class) {
    $prefix = 'VatSwim\\SmartCars\\';
    $baseDir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

use VatSwim\SmartCars\WebhookReceiver;
use VatSwim\SmartCars\SWIMSync;

// Handle request
header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get request data
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_SMARTCARS_SIGNATURE'] ?? '';

if (empty($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Empty request body']);
    exit;
}

// Initialize sync and receiver
$swimSync = new SWIMSync($config['vatswim_api_key'], $config['vatswim_base_url']);
$swimSync->setVerbose($config['verbose']);

$receiver = new WebhookReceiver($config['webhook_secret'], $swimSync);

// Process webhook
$result = $receiver->handle($payload, $signature);

// Send response
$httpCode = $result['code'] ?? ($result['success'] ? 200 : 500);
unset($result['code']);

http_response_code($httpCode);
echo json_encode($result);
