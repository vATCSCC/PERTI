<?php
/**
 * GIS Boundaries API
 *
 * PostGIS-powered spatial queries for boundary analysis.
 *
 * Endpoints:
 *   GET  ?action=at_point&lat=X&lon=Y&alt=Z     - Point-in-polygon lookup
 *   GET  ?action=route_artccs&waypoints=[...]   - ARTCCs traversed by route
 *   GET  ?action=route_tracons&waypoints=[...]  - TRACONs traversed by route
 *   GET  ?action=route_full&waypoints=[...]     - All boundaries traversed
 *   POST ?action=analyze_tmi_route              - TMI route analysis
 *
 * @version 1.0.0
 * @date 2026-01-29
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../load/connect.php';
require_once __DIR__ . '/../../load/services/GISService.php';

// Get GIS service instance
$gis = GISService::getInstance();

if (!$gis) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error' => 'GIS service unavailable',
        'error_code' => 'SERVICE_UNAVAILABLE',
        'message' => 'PostGIS database connection not configured or unavailable'
    ]);
    exit;
}

// Get action from query string
$action = $_GET['action'] ?? 'help';

try {
    switch ($action) {

        // =====================================================================
        // Point-in-polygon lookup
        // =====================================================================
        case 'at_point':
            $lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
            $lon = isset($_GET['lon']) ? (float)$_GET['lon'] : null;
            $alt = isset($_GET['alt']) ? (int)$_GET['alt'] : null;

            if ($lat === null || $lon === null) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing required parameters: lat, lon',
                    'error_code' => 'MISSING_PARAMS'
                ]);
                exit;
            }

            $result = $gis->getBoundariesAtPoint($lat, $lon, $alt);

            echo json_encode([
                'success' => true,
                'data' => $result,
                'query' => [
                    'lat' => $lat,
                    'lon' => $lon,
                    'alt' => $alt
                ]
            ]);
            break;

        // =====================================================================
        // ARTCCs traversed by route
        // =====================================================================
        case 'route_artccs':
            $waypoints = getWaypointsParam();

            if (empty($waypoints)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing or invalid waypoints parameter',
                    'error_code' => 'MISSING_WAYPOINTS',
                    'hint' => 'Provide waypoints as JSON array: [{lat,lon},{lat,lon},...] or [[lon,lat],[lon,lat],...]'
                ]);
                exit;
            }

            $artccs = $gis->getRouteARTCCs($waypoints);

            echo json_encode([
                'success' => true,
                'artccs' => $artccs,
                'artcc_codes' => array_column($artccs, 'artcc_code'),
                'count' => count($artccs),
                'waypoint_count' => count($waypoints)
            ]);
            break;

        // =====================================================================
        // TRACONs traversed by route
        // =====================================================================
        case 'route_tracons':
            $waypoints = getWaypointsParam();

            if (empty($waypoints)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing or invalid waypoints parameter',
                    'error_code' => 'MISSING_WAYPOINTS'
                ]);
                exit;
            }

            $tracons = $gis->getRouteTRACONs($waypoints);

            echo json_encode([
                'success' => true,
                'tracons' => $tracons,
                'tracon_codes' => array_column($tracons, 'tracon_code'),
                'count' => count($tracons)
            ]);
            break;

        // =====================================================================
        // Full boundary analysis (ARTCCs + TRACONs + all sectors)
        // =====================================================================
        case 'route_full':
        case 'route_boundaries':
            $waypoints = getWaypointsParam();
            $altitude = isset($_GET['altitude']) ? (int)$_GET['altitude'] : 35000;
            $includeSectors = !isset($_GET['sectors']) || $_GET['sectors'] !== '0';

            if (empty($waypoints)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing or invalid waypoints parameter',
                    'error_code' => 'MISSING_WAYPOINTS'
                ]);
                exit;
            }

            $boundaries = $gis->getRouteBoundaries($waypoints, $altitude, $includeSectors);

            echo json_encode([
                'success' => true,
                'boundaries' => $boundaries,
                'summary' => [
                    'artcc_count' => count($boundaries['artccs']),
                    'artcc_codes' => array_column($boundaries['artccs'], 'code'),
                    'tracon_count' => count($boundaries['tracons']),
                    'sector_low_count' => count($boundaries['sectors_low']),
                    'sector_high_count' => count($boundaries['sectors_high']),
                    'sector_superhigh_count' => count($boundaries['sectors_superhigh'])
                ],
                'query' => [
                    'altitude' => $altitude,
                    'include_sectors' => $includeSectors,
                    'waypoint_count' => count($waypoints)
                ]
            ]);
            break;

        // =====================================================================
        // TMI Route Analysis (POST)
        // =====================================================================
        case 'analyze_tmi_route':
        case 'tmi_analysis':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                // Also support GET with query params for testing
                $geojson = $_GET['geojson'] ?? $_GET['route'] ?? null;
                $origin = $_GET['origin'] ?? null;
                $dest = $_GET['destination'] ?? $_GET['dest'] ?? null;
                $altitude = isset($_GET['altitude']) ? (int)$_GET['altitude'] : 35000;

                if ($geojson) {
                    $geojson = json_decode($geojson, true);
                }
            } else {
                $body = json_decode(file_get_contents('php://input'), true);
                $geojson = $body['route_geojson'] ?? $body['geometry'] ?? $body['coordinates'] ?? null;
                $origin = $body['origin'] ?? $body['origin_icao'] ?? null;
                $dest = $body['destination'] ?? $body['dest'] ?? $body['dest_icao'] ?? null;
                $altitude = isset($body['altitude']) ? (int)$body['altitude'] : 35000;
            }

            if (!$geojson) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing route geometry',
                    'error_code' => 'MISSING_GEOMETRY',
                    'hint' => 'POST body should include route_geojson (GeoJSON LineString or coordinates array)'
                ]);
                exit;
            }

            $analysis = $gis->analyzeTMIRoute($geojson, $origin, $dest, $altitude);

            echo json_encode([
                'success' => true,
                'analysis' => $analysis,
                'facilities_string' => implode('/', $analysis['facilities_traversed']),
                'query' => [
                    'origin' => $origin,
                    'destination' => $dest,
                    'altitude' => $altitude
                ]
            ]);
            break;

        // =====================================================================
        // Get ARTCC for airport
        // =====================================================================
        case 'airport_artcc':
            $icao = strtoupper($_GET['icao'] ?? '');

            if (strlen($icao) < 3 || strlen($icao) > 4) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid ICAO code',
                    'error_code' => 'INVALID_ICAO'
                ]);
                exit;
            }

            $artcc = $gis->getAirportARTCC($icao);

            echo json_encode([
                'success' => true,
                'icao' => $icao,
                'artcc' => $artcc
            ]);
            break;

        // =====================================================================
        // Get airports in ARTCC
        // =====================================================================
        case 'artcc_airports':
            $artcc = strtoupper($_GET['artcc'] ?? '');

            if (strlen($artcc) < 2 || strlen($artcc) > 4) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid ARTCC code',
                    'error_code' => 'INVALID_ARTCC'
                ]);
                exit;
            }

            $airports = $gis->getAirportsInARTCC($artcc);

            echo json_encode([
                'success' => true,
                'artcc' => $artcc,
                'airports' => $airports,
                'count' => count($airports)
            ]);
            break;

        // =====================================================================
        // Health check
        // =====================================================================
        case 'health':
        case 'status':
            echo json_encode([
                'success' => true,
                'status' => 'ok',
                'service' => 'GIS Boundaries API',
                'database' => 'PostGIS',
                'connected' => $gis->isConnected(),
                'timestamp' => gmdate('c')
            ]);
            break;

        // =====================================================================
        // Help / Documentation
        // =====================================================================
        case 'help':
        default:
            echo json_encode([
                'success' => true,
                'service' => 'PERTI GIS Boundaries API',
                'version' => '1.0.0',
                'endpoints' => [
                    'at_point' => [
                        'method' => 'GET',
                        'params' => ['lat' => 'float (required)', 'lon' => 'float (required)', 'alt' => 'int (optional)'],
                        'description' => 'Get boundaries containing a point at given altitude'
                    ],
                    'route_artccs' => [
                        'method' => 'GET',
                        'params' => ['waypoints' => 'JSON array (required)'],
                        'description' => 'Get ARTCCs traversed by a route'
                    ],
                    'route_tracons' => [
                        'method' => 'GET',
                        'params' => ['waypoints' => 'JSON array (required)'],
                        'description' => 'Get TRACONs traversed by a route'
                    ],
                    'route_full' => [
                        'method' => 'GET',
                        'params' => ['waypoints' => 'JSON array', 'altitude' => 'int (default 35000)', 'sectors' => '0|1'],
                        'description' => 'Get all boundaries (ARTCCs, TRACONs, sectors) traversed'
                    ],
                    'analyze_tmi_route' => [
                        'method' => 'POST',
                        'body' => ['route_geojson' => 'GeoJSON', 'origin' => 'ICAO', 'destination' => 'ICAO', 'altitude' => 'int'],
                        'description' => 'Analyze TMI route proposal for facility coordination'
                    ],
                    'airport_artcc' => [
                        'method' => 'GET',
                        'params' => ['icao' => 'string (required)'],
                        'description' => 'Get ARTCC containing an airport'
                    ],
                    'health' => [
                        'method' => 'GET',
                        'description' => 'Service health check'
                    ]
                ],
                'waypoints_format' => [
                    'option1' => '[{"lat": 40.64, "lon": -73.78}, ...]',
                    'option2' => '[[-73.78, 40.64], ...]  (GeoJSON style: [lon, lat])',
                    'option3' => '[{"latitude": 40.64, "longitude": -73.78}, ...]'
                ],
                'example' => '/api/gis/boundaries?action=route_artccs&waypoints=[{"lat":32.897,"lon":-97.038},{"lat":28.429,"lon":-81.309}]'
            ]);
            break;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'error_code' => 'SERVER_ERROR',
        'message' => $e->getMessage()
    ]);
}

// =========================================================================
// Helper Functions
// =========================================================================

/**
 * Get waypoints from request (supports multiple formats)
 *
 * @return array Normalized waypoints array
 */
function getWaypointsParam(): array
{
    $raw = $_GET['waypoints'] ?? $_GET['route'] ?? $_GET['coordinates'] ?? null;

    if (!$raw) {
        // Try POST body
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $body = json_decode(file_get_contents('php://input'), true);
            $raw = $body['waypoints'] ?? $body['coordinates'] ?? null;
            if (is_array($raw)) {
                return normalizeWaypoints($raw);
            }
        }
        return [];
    }

    // URL-decode if necessary
    if (is_string($raw)) {
        $raw = urldecode($raw);
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return normalizeWaypoints($decoded);
        }
    }

    return [];
}

/**
 * Normalize waypoints to consistent format
 *
 * @param array $waypoints Raw waypoints array
 * @return array Normalized [{lat, lon}, ...]
 */
function normalizeWaypoints(array $waypoints): array
{
    $normalized = [];

    foreach ($waypoints as $wp) {
        if (isset($wp['lat']) && isset($wp['lon'])) {
            $normalized[] = ['lat' => (float)$wp['lat'], 'lon' => (float)$wp['lon']];
        } elseif (isset($wp['lat']) && isset($wp['lng'])) {
            $normalized[] = ['lat' => (float)$wp['lat'], 'lon' => (float)$wp['lng']];
        } elseif (isset($wp['latitude']) && isset($wp['longitude'])) {
            $normalized[] = ['lat' => (float)$wp['latitude'], 'lon' => (float)$wp['longitude']];
        } elseif (is_array($wp) && count($wp) >= 2) {
            // GeoJSON convention: [lon, lat]
            $normalized[] = ['lat' => (float)$wp[1], 'lon' => (float)$wp[0]];
        }
    }

    return $normalized;
}
