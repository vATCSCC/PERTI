<?php
/**
 * SimTraffic Inbound Webhook Receiver
 *
 * Receives lifecycle events from SimTraffic via REST webhook.
 * Verifies HMAC-SHA256 signature, deduplicates by event_id,
 * and processes flights through processSimTrafficFlight().
 *
 * POST /api/swim/v1/webhooks/simtraffic.php
 *
 * Headers:
 *   X-SimTraffic-Signature: sha256=<hmac>
 *   X-SimTraffic-Timestamp: <unix_epoch>
 *
 * Body: {"events": [...], "count": N}
 *   or:  {"event_id": "...", "event_type": "...", "data": {...}}  (single event)
 *
 * @package PERTI\SWIM\Webhooks
 */

// Load dependencies via auth.php (handles config, connect, swim_config)
require_once __DIR__ . '/../auth.php';

// Load WebhookReceiver library
require_once __DIR__ . '/../../../../lib/webhooks/WebhookReceiver.php';

// Load processSimTrafficFlight() without executing top-level request handling
define('SIMTRAFFIC_LIBRARY_MODE', true);
require_once __DIR__ . '/../ingest/simtraffic.php';

use PERTI\Lib\Webhooks\WebhookReceiver;

header('Content-Type: application/json');

// Only accept POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$conn = get_conn_swim();

// Read raw body before any parsing
$rawBody = file_get_contents('php://input');
if (empty($rawBody)) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty request body']);
    exit;
}

// Check for HMAC signature headers
$signatureHeader = $_SERVER['HTTP_X_SIMTRAFFIC_SIGNATURE'] ?? '';
$timestampHeader = $_SERVER['HTTP_X_SIMTRAFFIC_TIMESTAMP'] ?? '';

$hmacPresent = !empty($signatureHeader) && !empty($timestampHeader);

if ($hmacPresent) {
    // HMAC verification path
    $secret = WebhookReceiver::loadSecret($conn, 'simtraffic', 'inbound');
    if (!$secret) {
        http_response_code(500);
        echo json_encode(['error' => 'Webhook subscription not configured']);
        exit;
    }

    $receiver = new WebhookReceiver($conn, $secret);
    $verification = $receiver->verify($signatureHeader, $timestampHeader, $rawBody);

    if (!$verification['valid']) {
        http_response_code(401);
        echo json_encode(['error' => 'Signature verification failed', 'detail' => $verification['error']]);
        exit;
    }
} else {
    // Fallback: API key auth (backward compatibility during transition)
    $auth = swim_init_auth(true, true);
    if (!$auth->canWriteField('times')) {
        http_response_code(403);
        echo json_encode(['error' => 'Requires System or Partner tier with times authority']);
        exit;
    }
    // Create receiver without HMAC (dedup only)
    $receiver = new WebhookReceiver($conn, '', 300, 24);
}

// Parse JSON
$body = json_decode($rawBody, true);
if ($body === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Normalize to events array (support single event or batch)
if (isset($body['events']) && is_array($body['events'])) {
    $events = $body['events'];
} elseif (isset($body['event_id'])) {
    $events = [$body];
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Expected "events" array or single event with "event_id"']);
    exit;
}

// Enforce batch limit
if (count($events) > 500) {
    http_response_code(400);
    echo json_encode(['error' => 'Batch size exceeded. Maximum 500 events per request.']);
    exit;
}

// Process events
$accepted = 0;
$duplicates = 0;
$notFound = 0;
$errors = [];

foreach ($events as $event) {
    $eventId = $event['event_id'] ?? null;
    $eventType = $event['event_type'] ?? 'unknown';
    $data = $event['data'] ?? $event;

    if (!$eventId) {
        $errors[] = ['error' => 'Missing event_id', 'event_type' => $eventType];
        continue;
    }

    // Dedup check
    if ($receiver->isDuplicate($eventId)) {
        $duplicates++;
        continue;
    }

    // Convert lifecycle event data to SimTraffic ingest format
    // The data payload matches the existing processSimTrafficFlight() record format
    $record = $data;

    try {
        $result = processSimTrafficFlight($conn, $record, 'simtraffic');

        // Log to webhook events table
        $receiver->logInboundEvent(
            $eventId,
            $eventType,
            'simtraffic',
            'rest',
            json_encode($event),
            $result['flight_uid'] ?? null,
            $result['callsign'] ?? ($data['callsign'] ?? null)
        );

        if ($result['status'] === 'updated') {
            $accepted++;
        } elseif ($result['status'] === 'not_found') {
            $notFound++;
        } else {
            $accepted++; // no_changes still counts as accepted
        }
    } catch (\Exception $e) {
        $errors[] = [
            'event_id' => $eventId,
            'error' => $e->getMessage(),
        ];
    }
}

// Update subscription last_success
if ($accepted > 0 || $duplicates > 0) {
    $sql = "UPDATE dbo.swim_webhook_subscriptions
            SET last_success_utc = SYSUTCDATETIME(),
                consecutive_failures = 0,
                updated_utc = SYSUTCDATETIME()
            WHERE source_id = 'simtraffic' AND direction = 'inbound' AND is_active = 1";
    sqlsrv_query($conn, $sql);
}

// Return appropriate HTTP status
$errorCount = count($errors);
if ($accepted === 0 && $duplicates === 0 && $errorCount > 0) {
    http_response_code(422); // All events failed
} elseif ($errorCount > 0) {
    http_response_code(207); // Partial success
} else {
    http_response_code(200);
}
echo json_encode([
    'success' => $errorCount === 0,
    'accepted' => $accepted,
    'duplicates' => $duplicates,
    'not_found' => $notFound,
    'errors' => $errorCount,
    'error_details' => array_slice($errors, 0, 10),
]);
