<?php
/**
 * VATSWIM API v1 - Route Resolve Endpoint
 *
 * Resolves route strings into fully processed waypoints with lat/lon using
 * PostGIS expand_route_with_artccs(). Performs airway expansion, oceanic
 * coordinate parsing, fix disambiguation, FBD projection, distance validation,
 * and duplicate filtering.
 *
 * GET  /api/swim/v1/routes/resolve?route_string=CYYC+LOMLO+Q979+TULOV+...
 *   Single route resolution via query parameters.
 *
 * POST /api/swim/v1/routes/resolve
 *   Batch resolution. JSON body:
 *   {"routes": [{"route_string": "...", "origin": "KJFK", "dest": "EGLL"}, ...]}
 *   Max 50 routes per request.
 *
 * @version 1.1.0
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../../load/services/GISService.php';

// Require authentication
$auth = swim_init_auth(true);
$key_info = $auth->getKeyInfo();
SwimResponse::setTier($key_info['tier'] ?? 'public');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'GET' && $method !== 'POST') {
    SwimResponse::error('Method not allowed. GET or POST supported.', 405, 'METHOD_NOT_ALLOWED');
}

// Get GIS service (needed for both modes)
$gis = GISService::getInstance();
if (!$gis) {
    SwimResponse::error('Route resolution service unavailable', 503, 'SERVICE_UNAVAILABLE');
}

// ---------------------------------------------------------------------------
// Helper: build full route string with origin/dest bookends
// ---------------------------------------------------------------------------
function _build_full_route(string $route_string, ?string $origin, ?string $dest): string
{
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
    return $full_route;
}

// ---------------------------------------------------------------------------
// Helper: format a single expand result into the response shape
// ---------------------------------------------------------------------------
function _format_result(array $result, string $route_string, ?string $origin, ?string $dest): array
{
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

    $expanded_tokens = array_map(fn($wp) => $wp['fix'], $waypoints);
    $expanded_route  = implode(' ', array_filter($expanded_tokens));

    return [
        'route_string'      => $route_string,
        'expanded_route'    => $expanded_route,
        'origin'            => $origin,
        'dest'              => $dest,
        'total_distance_nm' => $result['distance_nm'],
        'waypoint_count'    => count($waypoints),
        'waypoints'         => $waypoints,
        'artccs_traversed'  => $result['artccs'],
    ];
}

// ===========================================================================
// POST — Batch mode
// ===========================================================================
if ($method === 'POST') {
    $body = swim_get_json_body();
    if (!$body || !isset($body['routes']) || !is_array($body['routes'])) {
        SwimResponse::error('Request body must contain a "routes" array', 400, 'INVALID_BODY');
    }

    $routes = $body['routes'];
    if (count($routes) === 0) {
        SwimResponse::error('routes array must not be empty', 400, 'EMPTY_ROUTES');
    }
    if (count($routes) > 50) {
        SwimResponse::error('Maximum 50 routes per batch request', 400, 'BATCH_LIMIT_EXCEEDED');
    }

    // Build the full route strings with bookends
    $route_inputs = [];   // original request items (for response metadata)
    $full_routes  = [];   // strings to send to PostGIS
    foreach ($routes as $i => $item) {
        if (!is_array($item) || empty(trim($item['route_string'] ?? ''))) {
            SwimResponse::error("routes[$i]: route_string is required", 400, 'MISSING_PARAMETER');
        }
        $rs     = trim($item['route_string']);
        $origin = !empty($item['origin']) ? strtoupper(trim($item['origin'])) : null;
        $dest   = !empty($item['dest'])   ? strtoupper(trim($item['dest']))   : null;

        $route_inputs[] = ['route_string' => $rs, 'origin' => $origin, 'dest' => $dest];
        $full_routes[]  = _build_full_route($rs, $origin, $dest);
    }

    // Single PostGIS round-trip via expand_routes_batch()
    $conn_gis = get_conn_gis();
    if (!$conn_gis) {
        SwimResponse::error('Route resolution service unavailable', 503, 'SERVICE_UNAVAILABLE');
    }

    $pg_array = '{' . implode(',', array_map(function ($s) {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }, $full_routes)) . '}';

    try {
        $sql = "SELECT route_index, route_input, waypoints, artccs_traversed,
                       ST_Length(route_geometry::geography) / 1852.0 AS distance_nm,
                       error_message
                FROM expand_routes_batch(:routes)";
        $stmt = $conn_gis->prepare($sql);
        $stmt->execute([':routes' => $pg_array]);

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $idx  = (int)$row['route_index'] - 1; // 1-based → 0-based
            $meta = $route_inputs[$idx];

            if ($row['error_message']) {
                $results[] = [
                    'route_string' => $meta['route_string'],
                    'origin'       => $meta['origin'],
                    'dest'         => $meta['dest'],
                    'error'        => $row['error_message'],
                ];
                continue;
            }

            $waypoints_raw = json_decode($row['waypoints'], true) ?? [];
            $artccs_raw    = trim($row['artccs_traversed'] ?? '', '{}');
            $artccs        = $artccs_raw ? array_map('trim', explode(',', $artccs_raw)) : [];
            // Clean ARTCC codes (remove K prefix from ICAO-style)
            $artccs = array_values(array_map(function ($a) {
                $a = trim($a, '"');
                return (strlen($a) === 4 && $a[0] === 'K') ? substr($a, 1) : $a;
            }, $artccs));

            $result_item = _format_result(
                ['waypoints' => $waypoints_raw, 'distance_nm' => round((float)($row['distance_nm'] ?? 0), 1), 'artccs' => $artccs],
                $meta['route_string'],
                $meta['origin'],
                $meta['dest']
            );
            $results[] = $result_item;
        }

        SwimResponse::success([
            'count'   => count($results),
            'routes'  => $results,
        ]);

    } catch (PDOException $e) {
        error_log('SWIM routes/resolve batch error: ' . $e->getMessage());
        SwimResponse::error('Batch route expansion failed', 500, 'INTERNAL_ERROR');
    }
}

// ===========================================================================
// GET — Single route mode
// ===========================================================================
$route_string = trim(swim_get_param('route_string', ''));
$origin = strtoupper(trim(swim_get_param('origin', ''))) ?: null;
$dest   = strtoupper(trim(swim_get_param('dest', ''))) ?: null;

if ($route_string === '') {
    SwimResponse::error('route_string parameter is required', 400, 'MISSING_PARAMETER');
}

$full_route = _build_full_route($route_string, $origin, $dest);

$result = $gis->expandRoute($full_route);
if (!$result) {
    $err = $gis->getLastError();
    SwimResponse::error('Route expansion failed' . ($err ? ': ' . $err : ''), 400, 'EXPANSION_FAILED');
}

SwimResponse::success(_format_result($result, $route_string, $origin, $dest));
