<?php
/**
 * VATSWIM API v1 - CTOT Assignment Endpoint
 *
 * Authenticated endpoint: requires SWIM API key with write permission + CTP authority.
 *
 * CTP assigns Controlled Take-Off Times and optional routes/tracks.
 * PERTI derives EOBT/EDCT, stores in TMI pipeline, and immediately
 * recalculates ETAs, waypoint times, and boundary crossings.
 *
 * POST /api/swim/v1/ingest/ctot.php
 *
 * Delegates to CTOTCascade service for the 9-step recalculation cascade.
 *
 * @see docs/superpowers/specs/2026-04-23-ctp-ete-edct-api-design.md
 */

require_once __DIR__ . '/../auth.php';

global $conn_swim;
if (!$conn_swim) {
    SwimResponse::error('SWIM database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

// Require write + CTP authority
$auth = swim_init_auth(true, true);
if (!$auth->canWriteField('ctp')) {
    SwimResponse::error('CTP write authority required', 403, 'FORBIDDEN');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    SwimResponse::error('Method not allowed. Use POST.', 405, 'METHOD_NOT_ALLOWED');
}

$body = swim_get_json_body();
if (!$body || !isset($body['assignments']) || !is_array($body['assignments'])) {
    SwimResponse::error('Request body must contain an "assignments" array', 400, 'INVALID_REQUEST');
}

$assignments = $body['assignments'];
if (count($assignments) === 0) {
    SwimResponse::error('assignments array must not be empty', 400, 'INVALID_REQUEST');
}
if (count($assignments) > 50) {
    SwimResponse::error('assignments array must not exceed 50 items', 400, 'INVALID_REQUEST');
}

// Get all database connections
$conn_adl = get_conn_adl();
$conn_tmi = get_conn_tmi();
$conn_gis = get_conn_gis();

if (!$conn_adl || !$conn_tmi) {
    SwimResponse::error('Required database connections not available', 503, 'SERVICE_UNAVAILABLE');
}

// Load shared services
require_once __DIR__ . '/../../../../load/services/GISService.php';
require_once __DIR__ . '/../../../../load/services/CTOTCascade.php';

$gisService = $conn_gis ? new PERTI\Services\GISService($conn_gis) : null;
$cascade = new PERTI\Services\CTOTCascade($conn_adl, $conn_tmi, $conn_swim, $gisService);

$results = [];
$errors = [];
$unmatched = [];
$counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];

foreach ($assignments as $item) {
    $callsign = strtoupper(trim($item['callsign'] ?? ''));
    if (strlen($callsign) < 2 || strlen($callsign) > 12 || !preg_match('/^[A-Z0-9]+$/', $callsign)) {
        $unmatched[] = $callsign ?: '(invalid)';
        continue;
    }

    // Validate CTOT (required)
    $ctot_str = PERTI\Services\CTOTCascade::parseUtcDatetime($item['ctot'] ?? '');
    if (!$ctot_str) {
        $errors[] = ['callsign' => $callsign, 'error' => 'Missing or invalid ctot datetime'];
        continue;
    }

    // Validate assigned_track format if provided
    $assigned_track = $item['assigned_track'] ?? null;
    if ($assigned_track && !preg_match('/^[A-Z]{1,2}\d?$/', $assigned_track)) {
        $errors[] = ['callsign' => $callsign, 'error' => 'Invalid assigned_track format (expected: A, B, SM1, etc.)'];
        continue;
    }

    // Find matching flight
    $flight = PERTI\Services\CTOTCascade::findFlight($conn_swim, $callsign);
    if (!$flight) {
        $unmatched[] = $callsign;
        continue;
    }

    $cta_utc = !empty($item['cta_utc']) ? PERTI\Services\CTOTCascade::parseUtcDatetime($item['cta_utc']) : null;

    $result = $cascade->apply($flight, $ctot_str, [
        'delay_minutes' => isset($item['delay_minutes']) ? (int)$item['delay_minutes'] : null,
        'delay_reason' => $item['delay_reason'] ?? null,
        'program_name' => $item['program_name'] ?? null,
        'program_id' => isset($item['program_id']) ? (int)$item['program_id'] : null,
        'cta_utc' => $cta_utc,
        'assigned_route' => $item['assigned_route'] ?? null,
        'route_segments' => $item['route_segments'] ?? null,
        'assigned_track' => $assigned_track,
    ]);

    if ($result['status'] === 'error') {
        $errors[] = ['callsign' => $callsign, 'flight_uid' => $result['flight_uid'] ?? null, 'error' => $result['error'] ?? 'Cascade failed'];
        continue;
    }

    $results[] = $result;
    $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;
}

SwimResponse::success([
    'results' => $results,
    'errors' => $errors,
    'unmatched' => $unmatched,
], [
    'total_submitted' => count($assignments),
    'created' => $counts['created'],
    'updated' => $counts['updated'],
    'skipped' => $counts['skipped'],
    'total_errors' => count($errors),
    'unmatched' => count($unmatched),
]);
