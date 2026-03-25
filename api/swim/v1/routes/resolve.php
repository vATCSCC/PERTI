<?php
/**
 * VATSWIM API v1 - Route Resolve Endpoint
 *
 * Resolves a route string into fully processed waypoints with lat/lon using
 * PostGIS expand_route_with_artccs(). Performs airway expansion, oceanic
 * coordinate parsing, fix disambiguation, FBD projection, distance validation,
 * and duplicate filtering.
 *
 * GET /api/swim/v1/routes/resolve?route_string=CYYC+LOMLO+Q979+TULOV+...
 *
 * Query Parameters:
 *   route_string - (required) Space-separated route string
 *   origin       - (optional) Origin ICAO; prepended if not already first token
 *   dest         - (optional) Destination ICAO; appended if not already last token
 *
 * @version 1.0.0
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../../load/services/GISService.php';

// Require authentication
$auth = swim_init_auth(true);
$key_info = $auth->getKeyInfo();
SwimResponse::setTier($key_info['tier'] ?? 'public');

// Validate method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    SwimResponse::error('Method not allowed. Only GET is supported.', 405, 'METHOD_NOT_ALLOWED');
}

// Parse parameters
$route_string = trim(swim_get_param('route_string', ''));
$origin = strtoupper(trim(swim_get_param('origin', ''))) ?: null;
$dest = strtoupper(trim(swim_get_param('dest', ''))) ?: null;

if ($route_string === '') {
    SwimResponse::error('route_string parameter is required', 400, 'MISSING_PARAMETER');
}

// Build full route string with origin/dest bookends
$full_route = $route_string;
if ($origin) {
    $first_token = strtoupper(explode(' ', trim($full_route))[0] ?? '');
    if ($first_token !== $origin) {
        $full_route = $origin . ' ' . $full_route;
    }
}
if ($dest) {
    $last_token = strtoupper(trim(substr($full_route, strrpos($full_route, ' ') + 1)));
    if ($last_token !== $dest) {
        $full_route = $full_route . ' ' . $dest;
    }
}

// Get GIS service
$gis = GISService::getInstance();
if (!$gis) {
    SwimResponse::error('Route resolution service unavailable', 503, 'SERVICE_UNAVAILABLE');
}

// Expand route via PostGIS
$result = $gis->expandRoute($full_route);
if (!$result) {
    $err = $gis->getLastError();
    SwimResponse::error('Route expansion failed' . ($err ? ': ' . $err : ''), 400, 'EXPANSION_FAILED');
}

// Build waypoints array with sequential numbering
$waypoints = [];
foreach ($result['waypoints'] as $i => $wp) {
    $waypoints[] = [
        'seq'  => $wp['seq'] ?? ($i + 1),
        'fix'  => $wp['id'] ?? null,
        'lat'  => isset($wp['lat']) ? round((float)$wp['lat'], 6) : null,
        'lon'  => isset($wp['lon']) ? round((float)$wp['lon'], 6) : null,
        'type' => $wp['type'] ?? null,
    ];
}

// Build expanded route string from resolved waypoints
$expanded_tokens = array_map(fn($wp) => $wp['fix'], $waypoints);
$expanded_route = implode(' ', array_filter($expanded_tokens));

SwimResponse::success([
    'route_string'     => $route_string,
    'expanded_route'   => $expanded_route,
    'origin'           => $origin,
    'dest'             => $dest,
    'total_distance_nm' => $result['distance_nm'],
    'waypoint_count'   => count($waypoints),
    'waypoints'        => $waypoints,
    'artccs_traversed' => $result['artccs'],
]);
