<?php
/**
 * VATSWIM vFDS Webhook Receiver
 *
 * Receives webhooks from vFDS for real-time updates.
 *
 * @package VATSWIM
 * @subpackage vFDS Integration
 * @version 1.0.0
 */

// Load configuration
$config = require __DIR__ . '/config.php';

// Verify webhook is enabled
if (!$config['webhook']['enabled']) {
    http_response_code(404);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

// Get raw input
$rawInput = file_get_contents('php://input');

// Verify signature if secret is configured
$secret = $config['webhook']['secret'];
if ($secret && $secret !== 'your_webhook_secret') {
    $signature = $_SERVER['HTTP_X_VFDS_SIGNATURE'] ?? '';

    if (!$signature) {
        http_response_code(401);
        echo json_encode(['error' => 'Missing signature']);
        exit;
    }

    $expectedSignature = 'sha256=' . hash_hmac('sha256', $rawInput, $secret);

    if (!hash_equals($expectedSignature, $signature)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
}

// Parse JSON payload
$payload = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Autoload
spl_autoload_register(function ($class) {
    $prefix = 'VatSwim\\VFDS\\';
    $baseDir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) require $file;
});

use VatSwim\VFDS\EDSTClient;
use VatSwim\VFDS\TDLSSync;
use VatSwim\VFDS\DepartureSequencer;
use VatSwim\VFDS\SWIMBridge;

// Initialize components
$edstClient = new EDSTClient(
    $config['vfds']['base_url'],
    $config['vfds']['api_key'],
    $config['vfds']['facility_id']
);

$tdlsSync = new TDLSSync(
    $edstClient,
    $config['vatswim']['api_key'],
    $config['vatswim']['base_url']
);

$sequencer = new DepartureSequencer();

$bridge = new SWIMBridge(
    $edstClient,
    $tdlsSync,
    $sequencer,
    $config['vatswim']['api_key'],
    $config['vatswim']['base_url']
);

$bridge->setVerbose($config['logging']['verbose']);

// Log webhook receipt
if ($config['logging']['verbose']) {
    $event = $payload['event'] ?? 'unknown';
    error_log("[VATSWIM-vFDS] Received webhook: $event");
}

// Process webhook
$success = $bridge->handleWebhook($payload);

// Send response
header('Content-Type: application/json');

if ($success) {
    http_response_code(200);
    echo json_encode(['status' => 'ok']);
} else {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Failed to process webhook']);
}
