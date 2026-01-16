<?php
/**
 * Discord Webhook Handler Endpoint
 *
 * Receives and processes incoming webhook events from Discord.
 * Implements Ed25519 signature verification for security.
 *
 * Discord will send events here for:
 * - Interactions (slash commands, buttons, etc.)
 * - Gateway events (if using outgoing webhooks)
 *
 * Setup:
 * 1. Go to Discord Developer Portal > Your App > General Information
 * 2. Set "Interactions Endpoint URL" to this endpoint's URL
 * 3. Discord will verify by sending a PING - this endpoint handles that
 */

// Do NOT set Content-Type header yet - we need to handle the raw body first
// and respond appropriately for Discord's verification

// Include dependencies
$config_path = realpath(__DIR__ . '/../../load/config.php');
$connect_path = realpath(__DIR__ . '/../../load/connect.php');
$webhook_handler_path = realpath(__DIR__ . '/../../load/discord/DiscordWebhookHandler.php');
$message_parser_path = realpath(__DIR__ . '/../../load/discord/DiscordMessageParser.php');

if ($config_path) require_once($config_path);
if ($connect_path) require_once($connect_path);
if ($webhook_handler_path) require_once($webhook_handler_path);
if ($message_parser_path) require_once($message_parser_path);

// Only accept POST requests
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    sendJsonError(405, 'Method not allowed');
}

// Get raw body and headers
$rawBody = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_SIGNATURE_ED25519'] ?? '';
$timestamp = $_SERVER['HTTP_X_SIGNATURE_TIMESTAMP'] ?? '';

// Initialize handler
$handler = new DiscordWebhookHandler(
    defined('DISCORD_PUBLIC_KEY') ? DISCORD_PUBLIC_KEY : null,
    $conn_adl ?? null
);

// Verify signature (required for Discord interactions)
$publicKey = defined('DISCORD_PUBLIC_KEY') ? DISCORD_PUBLIC_KEY : '';

if (!empty($publicKey)) {
    if (!$handler->verifySignature($signature, $timestamp, $rawBody)) {
        // Discord expects 401 for invalid signatures
        sendJsonError(401, 'Invalid request signature', [
            'details' => $handler->getLastError()
        ]);
    }
}

// Parse the payload
$payload = json_decode($rawBody, true);

if ($payload === null) {
    sendJsonError(400, 'Invalid JSON payload');
}

// Handle the event
$response = $handler->handleEvent($payload);

// Send response
header('Content-Type: application/json');
echo json_encode($response);
exit;

/**
 * Send JSON error response
 */
function sendJsonError($code, $message, $details = null) {
    http_response_code($code);
    header('Content-Type: application/json');

    $response = [
        'success' => false,
        'error' => $message,
        'error_code' => $code
    ];

    if ($details) {
        $response = array_merge($response, $details);
    }

    echo json_encode($response);
    exit;
}
