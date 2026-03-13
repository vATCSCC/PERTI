<?php
/**
 * VATSWIM API v1 - Playbook Route Analysis Endpoint
 *
 * Provides facility traversal, distance, and time analysis for playbook routes.
 * Proxies to the internal analysis API with SWIM API key authentication.
 *
 * GET /api/swim/v1/playbook/analysis?route_id=123
 * GET /api/swim/v1/playbook/analysis?route_string=...&origin=KJFK&dest=EGLL
 *
 * @version 1.0.0
 */

require_once __DIR__ . '/../auth.php';

// Require authentication for analysis endpoint
$auth = swim_init_auth(true);
$key_info = $auth->getKeyInfo();
SwimResponse::setTier($key_info['tier'] ?? 'public');

// Validate method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    SwimResponse::error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
}

// Forward to internal analysis API
$query_string = $_SERVER['QUERY_STRING'] ?? '';
$internal_url = dirname(dirname(dirname(dirname(__DIR__)))) . '/api/data/playbook/analysis.php';

// Rather than HTTP call, include the internal API directly with output buffering
// This avoids the overhead of a loopback HTTP request
$_orig_method = $_SERVER['REQUEST_METHOD'];

ob_start();
include $internal_url;
$response = ob_get_clean();

$data = json_decode($response, true);
if ($data === null) {
    SwimResponse::error('Analysis engine returned invalid response', 500, 'INTERNAL_ERROR');
}

if (isset($data['status']) && $data['status'] === 'error') {
    $code = 400;
    if (strpos($data['message'] ?? '', 'not found') !== false) $code = 404;
    if (strpos($data['message'] ?? '', 'unavailable') !== false) $code = 503;
    SwimResponse::error($data['message'] ?? 'Analysis failed', $code);
}

// Wrap in SWIM response format
SwimResponse::success($data);
