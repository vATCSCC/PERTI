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

// Get action from query string (early for diagnostic)
$action = $_GET['action'] ?? 'help';

// Diagnostic action - runs BEFORE GIS check for debugging connection issues
if ($action === 'diag' || $action === 'diagnostic') {
    $diag = [
        'php_version' => PHP_VERSION,
        'pdo_drivers' => PDO::getAvailableDrivers(),
        'pdo_pgsql_loaded' => extension_loaded('pdo_pgsql'),
        'pgsql_loaded' => extension_loaded('pgsql'),
        'connect_loaded' => defined('CONNECT_PHP_LOADED'),
        'sql_username_defined' => defined('SQL_USERNAME'),
        'adl_sql_host_defined' => defined('ADL_SQL_HOST'),
        'gis_constants_defined' => [
            'GIS_SQL_HOST' => defined('GIS_SQL_HOST'),
            'GIS_SQL_PORT' => defined('GIS_SQL_PORT'),
            'GIS_SQL_DATABASE' => defined('GIS_SQL_DATABASE'),
            'GIS_SQL_USERNAME' => defined('GIS_SQL_USERNAME'),
            'GIS_SQL_PASSWORD' => defined('GIS_SQL_PASSWORD')
        ],
        'gis_host' => defined('GIS_SQL_HOST') ? GIS_SQL_HOST : 'NOT_DEFINED',
        'gis_database' => defined('GIS_SQL_DATABASE') ? GIS_SQL_DATABASE : 'NOT_DEFINED',
        'config_path_check' => file_exists(__DIR__ . '/../../load/config.php') ? 'EXISTS' : 'MISSING',
        'error_log_path' => ini_get('error_log'),
        'server_time' => date('Y-m-d H:i:s T')
    ];

    // Try a manual connection to provide detailed error
    if (extension_loaded('pdo_pgsql') && defined('GIS_SQL_HOST')) {
        try {
            $port = defined('GIS_SQL_PORT') ? GIS_SQL_PORT : '5432';
            $dsn = "pgsql:host=" . GIS_SQL_HOST . ";port=" . $port . ";dbname=" . GIS_SQL_DATABASE;
            $testConn = new PDO($dsn, GIS_SQL_USERNAME, GIS_SQL_PASSWORD, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5
            ]);
            $diag['direct_connection'] = 'SUCCESS';
            $diag['server_version'] = $testConn->getAttribute(PDO::ATTR_SERVER_VERSION);
            $testConn = null;
        } catch (PDOException $e) {
            $diag['direct_connection'] = 'FAILED';
            $diag['connection_error'] = $e->getMessage();
        }
    } else {
        $diag['direct_connection'] = 'SKIPPED';
        $diag['skip_reason'] = !extension_loaded('pdo_pgsql') ? 'pdo_pgsql not loaded' : 'GIS_SQL_HOST not defined';
    }

    // Check GIS service status
    $gis = GISService::getInstance();
    $diag['gis_service_available'] = ($gis !== null);

    echo json_encode(['success' => true, 'diagnostic' => $diag], JSON_PRETTY_PRINT);
    exit;
}

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
        // Expand route string - parse and get ARTCCs (NEW)
        // =====================================================================
        case 'expand_route':
        case 'expand':
            $routeString = $_GET['route'] ?? $_GET['route_string'] ?? null;

            // Also support POST
            if (!$routeString && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $body = json_decode(file_get_contents('php://input'), true);
                $routeString = $body['route'] ?? $body['route_string'] ?? null;
            }

            if (!$routeString) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing route parameter',
                    'error_code' => 'MISSING_ROUTE',
                    'hint' => 'Provide route as query param: ?action=expand_route&route=KDFW BNA KMCO'
                ]);
                exit;
            }

            $result = $gis->expandRoute($routeString);

            if (!$result) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => 'Route expansion failed',
                    'error_code' => 'EXPANSION_FAILED',
                    'message' => $gis->getLastError()
                ]);
                exit;
            }

            echo json_encode([
                'success' => true,
                'route' => $result['route'],
                'artccs' => $result['artccs'],
                'artccs_display' => $result['artccs_display'],
                'waypoints' => $result['waypoints'],
                'waypoint_count' => count($result['waypoints']),
                'distance_nm' => $result['distance_nm'],
                'geojson' => $result['geojson']
            ]);
            break;

        // =====================================================================
        // Batch expand multiple routes (NEW)
        // =====================================================================
        case 'expand_routes':
        case 'expand_batch':
            $routes = null;

            // Check query param first
            if (isset($_GET['routes'])) {
                $routes = json_decode($_GET['routes'], true);
            }

            // Also support POST
            if (!$routes && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $body = json_decode(file_get_contents('php://input'), true);
                $routes = $body['routes'] ?? null;
            }

            if (!$routes || !is_array($routes)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing routes parameter',
                    'error_code' => 'MISSING_ROUTES',
                    'hint' => 'Provide routes as JSON array: ["KDFW BNA KMCO", "KJFK KMIA"]'
                ]);
                exit;
            }

            $results = $gis->expandRoutesBatch($routes);

            echo json_encode([
                'success' => true,
                'routes' => $results,
                'count' => count($results),
                'artccs_all' => array_values(array_unique(array_merge(...array_column($results, 'artccs'))))
            ]);
            break;

        // =====================================================================
        // Expand playbook route (NEW)
        // =====================================================================
        case 'expand_playbook':
        case 'playbook':
            $pbCode = $_GET['code'] ?? $_GET['pb_code'] ?? null;

            if (!$pbCode) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing playbook code',
                    'error_code' => 'MISSING_CODE',
                    'hint' => 'Provide code as: ?action=expand_playbook&code=PB.ROD.KSAN.KJFK'
                ]);
                exit;
            }

            $result = $gis->expandPlaybookRoute($pbCode);

            if (!$result) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => 'Playbook route not found',
                    'error_code' => 'NOT_FOUND',
                    'code' => $pbCode
                ]);
                exit;
            }

            echo json_encode([
                'success' => true,
                'pb_code' => $result['pb_code'],
                'route_string' => $result['route_string'],
                'artccs' => $result['artccs'],
                'artccs_display' => $result['artccs_display'],
                'waypoints' => $result['waypoints'],
                'waypoint_count' => count($result['waypoints']),
                'geojson' => $result['geojson']
            ]);
            break;

        // =====================================================================
        // Full route analysis with sectors (NEW)
        // =====================================================================
        case 'analyze_route':
        case 'route_analysis':
            $routeString = $_GET['route'] ?? $_GET['route_string'] ?? null;
            $altitude = isset($_GET['altitude']) ? (int)$_GET['altitude'] : 35000;

            // Also support POST
            if (!$routeString && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $body = json_decode(file_get_contents('php://input'), true);
                $routeString = $body['route'] ?? $body['route_string'] ?? null;
                $altitude = isset($body['altitude']) ? (int)$body['altitude'] : $altitude;
            }

            if (!$routeString) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing route parameter',
                    'error_code' => 'MISSING_ROUTE'
                ]);
                exit;
            }

            $result = $gis->analyzeRouteFull($routeString, $altitude);

            if (!$result) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => 'Route analysis failed',
                    'error_code' => 'ANALYSIS_FAILED',
                    'message' => $gis->getLastError()
                ]);
                exit;
            }

            echo json_encode([
                'success' => true,
                'route' => $result['route'],
                'artccs' => $result['artccs'],
                'sectors_low' => $result['sectors_low'],
                'sectors_high' => $result['sectors_high'],
                'sectors_superhi' => $result['sectors_superhi'],
                'tracons' => $result['tracons'],
                'waypoint_count' => count($result['waypoints']),
                'distance_nm' => $result['distance_nm'],
                'geojson' => $result['geojson'],
                'altitude' => $altitude
            ]);
            break;

        // =====================================================================
        // Routes to GeoJSON FeatureCollection (NEW)
        // =====================================================================
        case 'routes_geojson':
        case 'geojson':
            $routes = null;

            if (isset($_GET['routes'])) {
                $routes = json_decode($_GET['routes'], true);
            }

            if (!$routes && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $body = json_decode(file_get_contents('php://input'), true);
                $routes = $body['routes'] ?? null;
            }

            if (!$routes || !is_array($routes)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing routes parameter',
                    'error_code' => 'MISSING_ROUTES'
                ]);
                exit;
            }

            $featureCollection = $gis->routesToGeoJSON($routes);

            echo json_encode($featureCollection);
            break;

        // =====================================================================
        // Resolve waypoint/fix to coordinates (NEW)
        // =====================================================================
        case 'resolve_waypoint':
        case 'resolve_fix':
        case 'waypoint':
            $fix = strtoupper($_GET['fix'] ?? $_GET['waypoint'] ?? '');

            if (!$fix) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing fix parameter',
                    'error_code' => 'MISSING_FIX',
                    'hint' => 'Provide fix name: ?action=resolve_waypoint&fix=BNA'
                ]);
                exit;
            }

            $result = $gis->resolveWaypoint($fix);

            if (!$result) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => 'Waypoint not found',
                    'error_code' => 'NOT_FOUND',
                    'fix' => $fix
                ]);
                exit;
            }

            echo json_encode([
                'success' => true,
                'fix' => $result['fix_id'],
                'lat' => $result['lat'],
                'lon' => $result['lon'],
                'source' => $result['source']
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
        // Trajectory ARTCC crossings (NEW)
        // =====================================================================
        case 'trajectory_crossings':
        case 'artcc_crossings':
            $waypoints = getWaypointsParam();

            if (count($waypoints) < 2) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Need at least 2 waypoints for trajectory',
                    'error_code' => 'INSUFFICIENT_WAYPOINTS'
                ]);
                exit;
            }

            // Add sequence numbers if not present
            foreach ($waypoints as $i => &$wp) {
                if (!isset($wp['sequence_num'])) {
                    $wp['sequence_num'] = $i;
                }
            }
            unset($wp);

            $crossings = $gis->getTrajectoryArtccCrossings($waypoints);

            $response = [
                'success' => true,
                'crossings' => $crossings,
                'count' => count($crossings),
                'waypoint_count' => count($waypoints)
            ];

            // Debug mode: show additional info
            if (isset($_GET['debug'])) {
                $response['debug'] = [
                    'waypoints_formatted' => $waypoints,
                    'last_error' => $gis->getLastError()
                ];
            }

            echo json_encode($response);
            break;

        // =====================================================================
        // Trajectory sector crossings (NEW)
        // =====================================================================
        case 'sector_crossings':
            $waypoints = getWaypointsParam();
            $sectorType = strtoupper($_GET['type'] ?? 'HIGH');

            if (count($waypoints) < 2) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Need at least 2 waypoints for trajectory',
                    'error_code' => 'INSUFFICIENT_WAYPOINTS'
                ]);
                exit;
            }

            foreach ($waypoints as $i => &$wp) {
                if (!isset($wp['sequence_num'])) {
                    $wp['sequence_num'] = $i;
                }
            }
            unset($wp);

            $crossings = $gis->getTrajectorySectorCrossings($waypoints, $sectorType);

            echo json_encode([
                'success' => true,
                'crossings' => $crossings,
                'sector_type' => $sectorType,
                'count' => count($crossings),
                'waypoint_count' => count($waypoints)
            ]);
            break;

        // =====================================================================
        // All trajectory crossings (ARTCC + sectors) (NEW)
        // =====================================================================
        case 'all_crossings':
            $waypoints = getWaypointsParam();

            if (count($waypoints) < 2) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Need at least 2 waypoints for trajectory',
                    'error_code' => 'INSUFFICIENT_WAYPOINTS'
                ]);
                exit;
            }

            foreach ($waypoints as $i => &$wp) {
                if (!isset($wp['sequence_num'])) {
                    $wp['sequence_num'] = $i;
                }
            }
            unset($wp);

            $crossings = $gis->getTrajectoryAllCrossings($waypoints);

            echo json_encode([
                'success' => true,
                'crossings' => $crossings,
                'count' => count($crossings),
                'waypoint_count' => count($waypoints)
            ]);
            break;

        // =====================================================================
        // ARTCCs traversed (simple list) (NEW)
        // =====================================================================
        case 'artccs_traversed':
            $waypoints = getWaypointsParam();

            if (count($waypoints) < 2) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Need at least 2 waypoints',
                    'error_code' => 'INSUFFICIENT_WAYPOINTS'
                ]);
                exit;
            }

            foreach ($waypoints as $i => &$wp) {
                if (!isset($wp['sequence_num'])) {
                    $wp['sequence_num'] = $i;
                }
            }
            unset($wp);

            $artccs = $gis->getArtccsTraversed($waypoints);

            $response = [
                'success' => true,
                'artccs' => $artccs,
                'artccs_display' => implode('/', $artccs),
                'count' => count($artccs),
                'waypoint_count' => count($waypoints)
            ];

            if (isset($_GET['debug'])) {
                $response['debug'] = [
                    'waypoints_formatted' => $waypoints,
                    'last_error' => $gis->getLastError()
                ];
            }

            echo json_encode($response);
            break;

        // =====================================================================
        // Crossing ETAs (NEW)
        // =====================================================================
        case 'crossing_etas':
            $waypoints = getWaypointsParam();
            $currentLat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
            $currentLon = isset($_GET['lon']) ? (float)$_GET['lon'] : null;
            $distFlown = isset($_GET['dist_flown']) ? (float)$_GET['dist_flown'] : 0;
            $groundspeed = isset($_GET['groundspeed']) ? (int)$_GET['groundspeed'] : 450;

            if (count($waypoints) < 2) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Need at least 2 waypoints',
                    'error_code' => 'INSUFFICIENT_WAYPOINTS'
                ]);
                exit;
            }

            if ($currentLat === null || $currentLon === null) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing current position: lat, lon required',
                    'error_code' => 'MISSING_POSITION'
                ]);
                exit;
            }

            foreach ($waypoints as $i => &$wp) {
                if (!isset($wp['sequence_num'])) {
                    $wp['sequence_num'] = $i;
                }
            }
            unset($wp);

            $etas = $gis->calculateCrossingEtas(
                $waypoints,
                $currentLat,
                $currentLon,
                $distFlown,
                $groundspeed
            );

            echo json_encode([
                'success' => true,
                'crossing_etas' => $etas,
                'count' => count($etas),
                'query' => [
                    'current_position' => ['lat' => $currentLat, 'lon' => $currentLon],
                    'dist_flown_nm' => $distFlown,
                    'groundspeed_kts' => $groundspeed,
                    'waypoint_count' => count($waypoints)
                ]
            ]);
            break;

        // =====================================================================
        // BOUNDARY ADJACENCY NETWORK ENDPOINTS
        // =====================================================================

        // Compute all adjacencies (heavy operation - run after importing new boundaries)
        case 'compute_adjacencies':
            $results = $gis->computeAllAdjacencies();

            $totalInserted = array_sum(array_column($results, 'inserted'));
            $totalElapsed = array_sum(array_column($results, 'elapsed_ms'));

            echo json_encode([
                'success' => true,
                'message' => 'Adjacencies computed',
                'results' => $results,
                'summary' => [
                    'total_pairs_inserted' => $totalInserted,
                    'total_elapsed_ms' => round($totalElapsed, 1)
                ]
            ]);
            break;

        // Get neighbors of a specific boundary
        case 'boundary_neighbors':
            $boundaryType = strtoupper($_GET['type'] ?? '');
            $boundaryCode = strtoupper($_GET['code'] ?? '');
            $adjacencyClass = isset($_GET['adjacency']) ? strtoupper($_GET['adjacency']) : null;

            if (!$boundaryType || !$boundaryCode) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing required params: type, code',
                    'error_code' => 'MISSING_PARAMS'
                ]);
                exit;
            }

            $neighbors = $gis->getBoundaryNeighbors($boundaryType, $boundaryCode, $adjacencyClass);

            echo json_encode([
                'success' => true,
                'boundary_type' => $boundaryType,
                'boundary_code' => $boundaryCode,
                'filter_adjacency' => $adjacencyClass,
                'neighbors' => $neighbors,
                'count' => count($neighbors)
            ]);
            break;

        // Get adjacency network statistics
        case 'adjacency_stats':
            $stats = $gis->getAdjacencyStats();

            echo json_encode([
                'success' => true,
                'stats' => $stats,
                'count' => count($stats)
            ]);
            break;

        // Export adjacency network as edge list for graph tools
        case 'adjacency_edges':
            $types = isset($_GET['types']) ? json_decode($_GET['types'], true) : null;
            $minAdjacency = strtoupper($_GET['min_adjacency'] ?? 'LINE');

            $edges = $gis->exportAdjacencyEdges($types, $minAdjacency);

            echo json_encode([
                'success' => true,
                'min_adjacency' => $minAdjacency,
                'filter_types' => $types,
                'edges' => $edges,
                'count' => count($edges)
            ]);
            break;

        // Find path between two boundaries
        case 'boundary_path':
            $srcType = strtoupper($_GET['src_type'] ?? '');
            $srcCode = strtoupper($_GET['src_code'] ?? '');
            $tgtType = strtoupper($_GET['tgt_type'] ?? '');
            $tgtCode = strtoupper($_GET['tgt_code'] ?? '');
            $maxHops = (int)($_GET['max_hops'] ?? 10);
            $sameTypeOnly = ($_GET['same_type'] ?? '0') === '1';

            if (!$srcType || !$srcCode || !$tgtType || !$tgtCode) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing required params: src_type, src_code, tgt_type, tgt_code',
                    'error_code' => 'MISSING_PARAMS'
                ]);
                exit;
            }

            $path = $gis->findBoundaryPath($srcType, $srcCode, $tgtType, $tgtCode, $maxHops, $sameTypeOnly);

            echo json_encode([
                'success' => true,
                'source' => ['type' => $srcType, 'code' => $srcCode],
                'target' => ['type' => $tgtType, 'code' => $tgtCode],
                'path_found' => !empty($path),
                'path' => $path,
                'hops' => count($path)
            ]);
            break;

        // Get ARTCC adjacency map (ARTCC-to-ARTCC connections)
        case 'artcc_adjacency_map':
            $lineOnly = ($_GET['line_only'] ?? '1') !== '0';

            $map = $gis->getArtccAdjacencyMap($lineOnly);

            echo json_encode([
                'success' => true,
                'line_only' => $lineOnly,
                'artcc_map' => $map,
                'artcc_count' => count($map)
            ]);
            break;

        // Get sector adjacency within an ARTCC
        case 'sector_adjacency':
            $artccCode = strtoupper($_GET['artcc'] ?? '');
            $sectorType = strtoupper($_GET['sector_type'] ?? 'HIGH');

            if (!$artccCode) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing required param: artcc',
                    'error_code' => 'MISSING_PARAMS'
                ]);
                exit;
            }

            $adjacencies = $gis->getSectorAdjacencyInArtcc($artccCode, $sectorType);

            echo json_encode([
                'success' => true,
                'artcc' => $artccCode,
                'sector_type' => $sectorType,
                'adjacencies' => $adjacencies,
                'count' => count($adjacencies)
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
                'version' => '1.1.0',
                'endpoints' => [
                    // Route String Expansion (NEW)
                    'expand_route' => [
                        'method' => 'GET',
                        'params' => ['route' => 'string (required)'],
                        'description' => 'Expand route string to waypoints and get ARTCCs traversed',
                        'example' => '?action=expand_route&route=KDFW BNA KMCO'
                    ],
                    'expand_routes' => [
                        'method' => 'GET/POST',
                        'params' => ['routes' => 'JSON array of route strings'],
                        'description' => 'Batch expand multiple route strings'
                    ],
                    'expand_playbook' => [
                        'method' => 'GET',
                        'params' => ['code' => 'string (e.g., PB.ROD.KSAN.KJFK)'],
                        'description' => 'Expand playbook route code to full route'
                    ],
                    'analyze_route' => [
                        'method' => 'GET/POST',
                        'params' => ['route' => 'string', 'altitude' => 'int (default 35000)'],
                        'description' => 'Full route analysis with ARTCCs, sectors, and TRACONs'
                    ],
                    'resolve_waypoint' => [
                        'method' => 'GET',
                        'params' => ['fix' => 'string (e.g., BNA, KDFW, ZFW)'],
                        'description' => 'Resolve fix/airport/ARTCC to coordinates'
                    ],
                    'routes_geojson' => [
                        'method' => 'GET/POST',
                        'params' => ['routes' => 'JSON array of route strings'],
                        'description' => 'Convert routes to GeoJSON FeatureCollection'
                    ],
                    // Waypoint-based queries
                    'at_point' => [
                        'method' => 'GET',
                        'params' => ['lat' => 'float (required)', 'lon' => 'float (required)', 'alt' => 'int (optional)'],
                        'description' => 'Get boundaries containing a point at given altitude'
                    ],
                    'route_artccs' => [
                        'method' => 'GET',
                        'params' => ['waypoints' => 'JSON array (required)'],
                        'description' => 'Get ARTCCs traversed by waypoints'
                    ],
                    'route_tracons' => [
                        'method' => 'GET',
                        'params' => ['waypoints' => 'JSON array (required)'],
                        'description' => 'Get TRACONs traversed by waypoints'
                    ],
                    'route_full' => [
                        'method' => 'GET',
                        'params' => ['waypoints' => 'JSON array', 'altitude' => 'int (default 35000)', 'sectors' => '0|1'],
                        'description' => 'Get all boundaries (ARTCCs, TRACONs, sectors) from waypoints'
                    ],
                    // TMI Analysis
                    'analyze_tmi_route' => [
                        'method' => 'POST',
                        'body' => ['route_geojson' => 'GeoJSON', 'origin' => 'ICAO', 'destination' => 'ICAO', 'altitude' => 'int'],
                        'description' => 'Analyze TMI route proposal for facility coordination'
                    ],
                    // Airport queries
                    'airport_artcc' => [
                        'method' => 'GET',
                        'params' => ['icao' => 'string (required)'],
                        'description' => 'Get ARTCC containing an airport'
                    ],
                    'artcc_airports' => [
                        'method' => 'GET',
                        'params' => ['artcc' => 'string (required)'],
                        'description' => 'Get airports within an ARTCC'
                    ],
                    'health' => [
                        'method' => 'GET',
                        'description' => 'Service health check'
                    ],
                    // Trajectory crossings (NEW)
                    'trajectory_crossings' => [
                        'method' => 'GET',
                        'params' => ['waypoints' => 'JSON array (required)'],
                        'description' => 'Get precise ARTCC boundary crossings along trajectory'
                    ],
                    'sector_crossings' => [
                        'method' => 'GET',
                        'params' => ['waypoints' => 'JSON array', 'type' => 'LOW|HIGH|SUPERHIGH (default HIGH)'],
                        'description' => 'Get sector boundary crossings along trajectory'
                    ],
                    'all_crossings' => [
                        'method' => 'GET',
                        'params' => ['waypoints' => 'JSON array (required)'],
                        'description' => 'Get all boundary crossings (ARTCC + sectors) along trajectory'
                    ],
                    'artccs_traversed' => [
                        'method' => 'GET',
                        'params' => ['waypoints' => 'JSON array (required)'],
                        'description' => 'Get simple list of ARTCCs crossed by trajectory'
                    ],
                    'crossing_etas' => [
                        'method' => 'GET',
                        'params' => ['waypoints' => 'JSON array', 'lat' => 'float (current)', 'lon' => 'float (current)', 'dist_flown' => 'nm', 'groundspeed' => 'kts'],
                        'description' => 'Calculate ETAs for upcoming boundary crossings'
                    ],
                    // Boundary Adjacency Network (NEW)
                    'compute_adjacencies' => [
                        'method' => 'GET',
                        'description' => 'Compute all boundary adjacencies (heavy operation - run after importing new boundaries)'
                    ],
                    'boundary_neighbors' => [
                        'method' => 'GET',
                        'params' => ['type' => 'ARTCC|TRACON|SECTOR_LOW|SECTOR_HIGH|SECTOR_SUPERHIGH', 'code' => 'string', 'adjacency' => 'POINT|LINE|POLY (optional)'],
                        'description' => 'Get all boundaries adjacent to a given boundary'
                    ],
                    'adjacency_stats' => [
                        'method' => 'GET',
                        'description' => 'Get summary statistics of the adjacency network'
                    ],
                    'adjacency_edges' => [
                        'method' => 'GET',
                        'params' => ['types' => 'JSON array (optional)', 'min_adjacency' => 'POINT|LINE|POLY (default LINE)'],
                        'description' => 'Export adjacency network as edge list for graph analysis tools'
                    ],
                    'boundary_path' => [
                        'method' => 'GET',
                        'params' => ['src_type' => 'string', 'src_code' => 'string', 'tgt_type' => 'string', 'tgt_code' => 'string', 'max_hops' => 'int (default 10)', 'same_type' => '0|1'],
                        'description' => 'Find traversal path between two boundaries using BFS'
                    ],
                    'artcc_adjacency_map' => [
                        'method' => 'GET',
                        'params' => ['line_only' => '0|1 (default 1)'],
                        'description' => 'Get ARTCC-to-ARTCC adjacency map (all center borders)'
                    ],
                    'sector_adjacency' => [
                        'method' => 'GET',
                        'params' => ['artcc' => 'string (required)', 'sector_type' => 'LOW|HIGH|SUPERHIGH (default HIGH)'],
                        'description' => 'Get sector adjacency within an ARTCC'
                    ]
                ],
                'route_string_format' => [
                    'direct' => 'KDFW BNA KMCO (space-separated fixes)',
                    'airway' => 'KDFW J4 ABI KABQ (includes J/Q airways)',
                    'playbook' => 'PB.PLAY.ORIGIN.DEST (e.g., PB.ROD.KSAN.KJFK)'
                ],
                'examples' => [
                    'expand' => '/api/gis/boundaries?action=expand_route&route=KDFW BNA KMCO',
                    'playbook' => '/api/gis/boundaries?action=expand_playbook&code=PB.ROD.KSAN.KJFK',
                    'waypoints' => '/api/gis/boundaries?action=route_artccs&waypoints=[{"lat":32.897,"lon":-97.038},{"lat":28.429,"lon":-81.309}]'
                ]
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
