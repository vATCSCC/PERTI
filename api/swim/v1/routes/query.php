<?php
/**
 * VATSWIM API v1 - Unified Route Query
 *
 * Returns ranked route suggestions from playbook, CDR, and historical data
 * with optional boolean filter expressions and TMI impact annotations.
 *
 * POST /api/swim/v1/routes/query  — Full query with JSON body
 * GET  /api/swim/v1/routes/query  — Simple city-pair lookup via query params
 *
 * @version 1.0.0
 * @since 2026-03-31
 * @see docs/superpowers/specs/2026-03-30-vatswim-route-query-api-design.md
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../../load/services/RouteQueryService.php';

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    perti_set_cors();
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'POST') {
    SwimResponse::error('Method not allowed. GET and POST are supported.', 405, 'METHOD_NOT_ALLOWED');
}

// Auth required
swim_init_auth(true, false);

// Get SWIM_API connection
$conn_swim_api = get_conn_swim();
if (!$conn_swim_api) {
    SwimResponse::error('SWIM database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

// Parse request
$request = [];

if ($method === 'POST') {
    $body = swim_get_json_body();
    if ($body === null) {
        $body = [];
    }
    $request = $body;
} else {
    // GET: map query params to request structure
    $origin = swim_get_param('origin');
    $dest = swim_get_param('destination') ?? swim_get_param('dest');
    if ($origin !== null) $request['origin'] = $origin;
    if ($dest !== null) $request['destination'] = $dest;

    $filter = swim_get_param('filter');
    if ($filter !== null) $request['filter'] = $filter;

    $sources = swim_get_param('sources');
    if ($sources !== null) $request['sources'] = array_map('trim', explode(',', $sources));

    $include = swim_get_param('include');
    if ($include !== null) $request['include'] = array_map('trim', explode(',', $include));

    $sort = swim_get_param('sort');
    if ($sort !== null) $request['sort'] = $sort;

    $request['limit'] = swim_get_int_param('limit', 20, 1, 100);
    $request['offset'] = swim_get_int_param('offset', 0, 0, 10000);
}

// Validate required fields
$hasOrigin = !empty($request['origin']);
$hasDest = !empty($request['destination']);
$hasFilter = !empty($request['filter']);

if (!$hasOrigin && !$hasDest && !$hasFilter) {
    SwimResponse::error('At least one of origin, destination, or filter is required', 400, 'MISSING_PARAMETER');
}

// Validate sources
$validSources = ['playbook', 'cdr', 'historical'];
if (isset($request['sources'])) {
    foreach ($request['sources'] as $src) {
        if (!in_array($src, $validSources, true)) {
            SwimResponse::error("Unknown source: $src. Valid: " . implode(', ', $validSources), 400, 'INVALID_PARAMETER');
        }
    }
}

// Validate sort
$validSorts = ['score', 'popularity', 'distance', 'recency'];
$sort = $request['sort'] ?? 'score';
if (!in_array($sort, $validSorts, true)) {
    SwimResponse::error("Unknown sort: $sort. Valid: " . implode(', ', $validSorts), 400, 'INVALID_PARAMETER');
}
$request['sort'] = $sort;

// Clamp limit/offset
$request['limit'] = max(1, min(100, (int)($request['limit'] ?? 20)));
$request['offset'] = max(0, (int)($request['offset'] ?? 0));

// Execute query
$connTmi = get_conn_tmi();
$connPdo = $GLOBALS['conn_pdo'] ?? null;

$service = new \PERTI\Services\RouteQueryService($conn_swim_api, $connTmi, $connPdo);
$result = $service->query($request);

// Check for errors
if (isset($result['error'])) {
    SwimResponse::error($result['error'], $result['http_code'] ?? 400, 'QUERY_ERROR');
}

// Format results (strip internal fields)
$result['results'] = \PERTI\Services\RouteQueryService::formatResults($result['results']);

// Return response
SwimResponse::json([
    'success'  => true,
    'query'    => $result['query'],
    'results'  => $result['results'],
    'summary'  => $result['summary'],
    'warnings' => $result['warnings'],
    'metadata' => [
        'generated' => gmdate('c'),
        'source'    => 'vatswim_route_query_v1',
    ],
], 200);
