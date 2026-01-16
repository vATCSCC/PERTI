<?php
/**
 * SWIM WebSocket Internal Publish Endpoint
 * 
 * Receives events from the ADL daemon and forwards them to the WebSocket server.
 * This endpoint is for internal use only and should not be exposed publicly.
 * 
 * POST /api/swim/v1/ws/publish
 * 
 * @package PERTI\SWIM\WebSocket
 * @version 1.0.0
 * @since 2026-01-16
 */

// Security: Only allow internal requests
$allowedIps = ['127.0.0.1', '::1', 'localhost'];
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';

// Also check X-Internal-Key header for daemon authentication
$internalKey = $_SERVER['HTTP_X_INTERNAL_KEY'] ?? '';
$expectedKey = getenv('SWIM_WS_INTERNAL_KEY') ?: 'dev-internal-key';

if (!in_array($clientIp, $allowedIps) && $internalKey !== $expectedKey) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// Only accept POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Parse JSON body
$body = file_get_contents('php://input');
$data = json_decode($body, true);

if ($data === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$events = $data['events'] ?? [];

if (empty($events)) {
    http_response_code(400);
    echo json_encode(['error' => 'No events provided']);
    exit;
}

// Write events to shared file for WebSocket server to read
// This is a simple IPC mechanism; could be replaced with Redis/ZMQ in production
$eventFile = sys_get_temp_dir() . '/swim_ws_events.json';
$existingEvents = [];

if (file_exists($eventFile)) {
    $content = @file_get_contents($eventFile);
    if ($content) {
        $existingEvents = json_decode($content, true) ?: [];
    }
}

// Append new events with timestamp
foreach ($events as $event) {
    $existingEvents[] = array_merge($event, [
        '_received_at' => gmdate('Y-m-d\TH:i:s.v\Z'),
    ]);
}

// Limit queue size to prevent runaway growth
if (count($existingEvents) > 10000) {
    $existingEvents = array_slice($existingEvents, -5000);
}

// Write atomically
$tempFile = $eventFile . '.tmp';
file_put_contents($tempFile, json_encode($existingEvents));
rename($tempFile, $eventFile);

// Return success
http_response_code(200);
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'queued' => count($events),
    'total_pending' => count($existingEvents),
]);
