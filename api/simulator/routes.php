<?php
/**
 * api/simulator/routes.php
 * 
 * Route service for ATFM simulator
 * Provides route data and waypoint expansion for flight plans
 * 
 * Actions:
 *   preferred   - Get preferred route for O-D pair
 *   expand      - Expand route string to waypoints with coordinates
 *   lookup      - Look up fix coordinates
 *   common      - Get common routes for major city pairs
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../../load/config.php';
require_once __DIR__ . '/../../load/connect.php';

try {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    switch ($action) {
        case 'preferred':
            handlePreferred();
            break;
        case 'expand':
            handleExpand();
            break;
        case 'lookup':
            handleLookup();
            break;
        case 'common':
            handleCommon();
            break;
        case 'batch':
            handleBatch();
            break;
        default:
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid action. Use: preferred, expand, lookup, common, batch'
            ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

/**
 * Get preferred route for O-D pair
 * Uses playbook routes, CDRs, or generates a sensible default
 */
function handlePreferred() {
    global $conn_adl;
    
    $origin = strtoupper(trim($_GET['origin'] ?? ''));
    $dest = strtoupper(trim($_GET['dest'] ?? ''));
    
    if (empty($origin) || empty($dest)) {
        echo json_encode(['status' => 'error', 'message' => 'origin and dest required']);
        return;
    }
    
    // Normalize to ICAO
    if (strlen($origin) === 3) $origin = 'K' . $origin;
    if (strlen($dest) === 3) $dest = 'K' . $dest;
    
    $route = null;
    $source = 'none';
    
    // Try database sources
    if ($conn_adl) {
        // 1. Try playbook routes
        $sql = "SELECT TOP 1 route_string, dp_name, star_name
                FROM dbo.nav_playbook 
                WHERE origin_icao = ? AND dest_icao = ?
                ORDER BY effective_date DESC";
        $stmt = sqlsrv_query($conn_adl, $sql, [$origin, $dest]);
        if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $route = $row['route_string'];
            $source = 'playbook';
            sqlsrv_free_stmt($stmt);
        }
        
        // 2. Try CDRs if no playbook
        if (!$route) {
            $sql = "SELECT TOP 1 full_route, cdr_code
                    FROM dbo.coded_departure_routes
                    WHERE origin_icao = ? AND dest_icao = ?";
            $stmt = sqlsrv_query($conn_adl, $sql, [$origin, $dest]);
            if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $route = $row['full_route'];
                $source = 'cdr';
                sqlsrv_free_stmt($stmt);
            }
        }
        
        // 3. Try public routes table
        if (!$route) {
            $sql = "SELECT TOP 1 route_string
                    FROM dbo.public_routes
                    WHERE origin_icao = ? AND dest_icao = ? AND is_active = 1
                    ORDER BY created_at DESC";
            $stmt = sqlsrv_query($conn_adl, $sql, [$origin, $dest]);
            if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $route = $row['route_string'];
                $source = 'public_routes';
                sqlsrv_free_stmt($stmt);
            }
        }
    }
    
    // 4. Fall back to common routes
    if (!$route) {
        $route = getCommonRoute($origin, $dest);
        $source = $route ? 'common' : 'direct';
    }
    
    // If still no route, create direct
    if (!$route) {
        $route = 'DCT';
    }
    
    echo json_encode([
        'status' => 'ok',
        'origin' => $origin,
        'dest' => $dest,
        'route' => $route,
        'source' => $source
    ]);
}

/**
 * Expand route string to waypoints with coordinates
 */
function handleExpand() {
    global $conn_adl;
    
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        $data = [
            'origin' => $_GET['origin'] ?? '',
            'dest' => $_GET['dest'] ?? '',
            'route' => $_GET['route'] ?? ''
        ];
    }
    
    $origin = strtoupper(trim($data['origin'] ?? ''));
    $dest = strtoupper(trim($data['dest'] ?? ''));
    $route = strtoupper(trim($data['route'] ?? ''));
    
    if (empty($origin) || empty($dest)) {
        echo json_encode(['status' => 'error', 'message' => 'origin and dest required']);
        return;
    }
    
    if (strlen($origin) === 3) $origin = 'K' . $origin;
    if (strlen($dest) === 3) $dest = 'K' . $dest;
    
    // Parse route string into elements
    $elements = parseRouteString($route);
    
    // Start with origin
    $waypoints = [];
    $originCoords = getFixCoordinates($origin);
    if ($originCoords) {
        $waypoints[] = [
            'name' => $origin,
            'lat' => $originCoords['lat'],
            'lon' => $originCoords['lon'],
            'type' => 'AIRPORT',
            'altitude' => null,
            'speed' => null
        ];
    }
    
    // Expand each route element
    foreach ($elements as $element) {
        if ($element === 'DCT' || $element === '..') continue;
        
        // Skip airways for now (would need full airway expansion)
        if (preg_match('/^[JQTV]\d+$/', $element)) {
            continue;
        }
        
        // Skip SIDs/STARs (would need procedure expansion)
        if (preg_match('/\d$/', $element) && strlen($element) > 4) {
            continue;
        }
        
        // Look up as fix
        $coords = getFixCoordinates($element);
        if ($coords) {
            $waypoints[] = [
                'name' => $element,
                'lat' => $coords['lat'],
                'lon' => $coords['lon'],
                'type' => $coords['type'] ?? 'FIX',
                'altitude' => null,
                'speed' => null
            ];
        }
    }
    
    // End with destination
    $destCoords = getFixCoordinates($dest);
    if ($destCoords) {
        $waypoints[] = [
            'name' => $dest,
            'lat' => $destCoords['lat'],
            'lon' => $destCoords['lon'],
            'type' => 'AIRPORT',
            'altitude' => 0, // Signal for descent
            'speed' => null
        ];
    }
    
    // Calculate approximate route distance
    $totalDistance = 0;
    for ($i = 1; $i < count($waypoints); $i++) {
        $totalDistance += haversineDistance(
            $waypoints[$i-1]['lat'], $waypoints[$i-1]['lon'],
            $waypoints[$i]['lat'], $waypoints[$i]['lon']
        );
    }
    
    echo json_encode([
        'status' => 'ok',
        'origin' => $origin,
        'dest' => $dest,
        'routeString' => $route ?: 'DCT',
        'waypoints' => $waypoints,
        'waypointCount' => count($waypoints),
        'distanceNm' => round($totalDistance, 1)
    ]);
}

/**
 * Look up fix coordinates
 */
function handleLookup() {
    $fix = strtoupper(trim($_GET['fix'] ?? ''));
    
    if (empty($fix)) {
        echo json_encode(['status' => 'error', 'message' => 'fix parameter required']);
        return;
    }
    
    $coords = getFixCoordinates($fix);
    
    if ($coords) {
        echo json_encode([
            'status' => 'ok',
            'fix' => $fix,
            'lat' => $coords['lat'],
            'lon' => $coords['lon'],
            'type' => $coords['type'] ?? 'FIX'
        ]);
    } else {
        echo json_encode(['status' => 'ok', 'fix' => $fix, 'found' => false]);
    }
}

/**
 * Get common routes for training scenarios
 */
function handleCommon() {
    $origin = strtoupper(trim($_GET['origin'] ?? ''));
    $dest = strtoupper(trim($_GET['dest'] ?? ''));
    
    if (strlen($origin) === 3) $origin = 'K' . $origin;
    if (strlen($dest) === 3) $dest = 'K' . $dest;
    
    // Return all common routes if no filter
    if (empty($origin) && empty($dest)) {
        echo json_encode([
            'status' => 'ok',
            'routes' => getAllCommonRoutes()
        ]);
        return;
    }
    
    $route = getCommonRoute($origin, $dest);
    
    if ($route) {
        echo json_encode([
            'status' => 'ok',
            'origin' => $origin,
            'dest' => $dest,
            'route' => $route
        ]);
    } else {
        echo json_encode([
            'status' => 'ok',
            'origin' => $origin,
            'dest' => $dest,
            'route' => null,
            'message' => 'No common route defined'
        ]);
    }
}

/**
 * Batch expand multiple routes at once
 * POST body: { routes: [ { origin, dest, route? }, ... ] }
 */
function handleBatch() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['routes'])) {
        echo json_encode(['status' => 'error', 'message' => 'routes array required']);
        return;
    }
    
    $results = [];
    
    foreach ($data['routes'] as $item) {
        $origin = strtoupper(trim($item['origin'] ?? ''));
        $dest = strtoupper(trim($item['dest'] ?? ''));
        $route = $item['route'] ?? '';
        
        if (strlen($origin) === 3) $origin = 'K' . $origin;
        if (strlen($dest) === 3) $dest = 'K' . $dest;
        
        // Get route if not provided
        if (empty($route)) {
            $route = getCommonRoute($origin, $dest) ?: 'DCT';
        }
        
        // Parse and expand
        $elements = parseRouteString($route);
        $waypoints = [];
        
        // Add origin
        $coords = getFixCoordinates($origin);
        if ($coords) {
            $waypoints[] = ['name' => $origin, 'lat' => $coords['lat'], 'lon' => $coords['lon']];
        }
        
        // Add fixes
        foreach ($elements as $el) {
            if ($el === 'DCT' || $el === '..') continue;
            if (preg_match('/^[JQTV]\d+$/', $el)) continue;
            
            $coords = getFixCoordinates($el);
            if ($coords) {
                $waypoints[] = ['name' => $el, 'lat' => $coords['lat'], 'lon' => $coords['lon']];
            }
        }
        
        // Add destination
        $coords = getFixCoordinates($dest);
        if ($coords) {
            $waypoints[] = ['name' => $dest, 'lat' => $coords['lat'], 'lon' => $coords['lon']];
        }
        
        $results[] = [
            'origin' => $origin,
            'dest' => $dest,
            'route' => $route,
            'waypoints' => $waypoints
        ];
    }
    
    echo json_encode([
        'status' => 'ok',
        'count' => count($results),
        'routes' => $results
    ]);
}

// ============================================================================
// Helper Functions
// ============================================================================

function parseRouteString($route) {
    if (empty($route)) return [];
    
    // Clean up route string
    $route = preg_replace('/\s+/', ' ', trim($route));
    $route = str_replace(['..', '/'], ' DCT ', $route);
    
    // Split into elements
    $elements = preg_split('/\s+/', $route);
    $elements = array_filter($elements, function($e) {
        return !empty($e) && $e !== 'DCT';
    });
    
    return array_values($elements);
}

function getFixCoordinates($fix) {
    global $conn_adl;
    
    // Try database first
    if ($conn_adl) {
        $sql = "SELECT lat, lon, fix_type FROM nav_fixes WHERE fix_name = ?";
        $stmt = sqlsrv_query($conn_adl, $sql, [$fix]);
        if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            sqlsrv_free_stmt($stmt);
            return [
                'lat' => floatval($row['lat']),
                'lon' => floatval($row['lon']),
                'type' => $row['fix_type']
            ];
        }
    }
    
    // Fall back to hardcoded airports/fixes
    $known = getKnownFixes();
    if (isset($known[$fix])) {
        return $known[$fix];
    }
    
    return null;
}

function getCommonRoute($origin, $dest) {
    $routes = getAllCommonRoutes();
    $key = $origin . '-' . $dest;
    return $routes[$key] ?? null;
}

function getAllCommonRoutes() {
    return [
        // Northeast to/from Southeast
        'KJFK-KATL' => 'MERIT J209 SBJ J42 NAVHO RPTOR2',
        'KATL-KJFK' => 'JACCC CUTTN J209 MERIT',
        'KJFK-KMIA' => 'MERIT J209 SAV J79 CEBEE HILEY3',
        'KMIA-KJFK' => 'WINCO J79 SAV J209 MERIT',
        'KBOS-KATL' => 'HAYED J42 NAVHO RPTOR2',
        'KATL-KBOS' => 'JACCC CUTTN J42 HAYED',
        
        // Northeast to/from Midwest
        'KJFK-KORD' => 'MERIT J584 AIR J94 DENNT',
        'KORD-KJFK' => 'EARND J94 AIR J584 MERIT',
        'KBOS-KORD' => 'HAYED J584 AIR J94 DENNT',
        'KORD-KBOS' => 'EARND J94 AIR J584 HAYED',
        
        // Northeast to/from West
        'KJFK-KLAX' => 'BIGGY J80 SLT J146 PKE J60 DAGGZ SEAVU4',
        'KLAX-KJFK' => 'DOTSS HLYWD J146 SLT J80 BIGGY',
        'KJFK-KSFO' => 'BIGGY J80 SLT J146 PKE J60 DAGGZ SERFR1',
        'KSFO-KJFK' => 'SSTIK TRUKN J146 SLT J80 BIGGY',
        
        // Midwest to/from Southeast
        'KORD-KATL' => 'EARND J94 HNN J42 NAVHO RPTOR2',
        'KATL-KORD' => 'JACCC CUTTN J42 HNN J94 DENNT',
        'KORD-KMIA' => 'EARND J94 HNN J79 SAV HILEY3',
        'KMIA-KORD' => 'WINCO J79 HNN J94 DENNT',
        
        // Midwest to/from West
        'KORD-KLAX' => 'EARND J94 DBQ J10 OBH J80 DAGGZ SEAVU4',
        'KLAX-KORD' => 'DOTSS HLYWD J80 OBH J10 DBQ J94 DENNT',
        'KORD-KSFO' => 'EARND J94 DBQ J10 OBH J80 SERFR1',
        'KSFO-KORD' => 'SSTIK TRUKN J80 OBH J10 DBQ J94 DENNT',
        
        // West Coast
        'KLAX-KSFO' => 'VNY SADDE AVE DYAMD SERFR2',
        'KSFO-KLAX' => 'SSTIK TRUKN SEAVU4',
        'KLAX-KSEA' => 'VNY SADDE J5 OAL GLASR HAWKZ4',
        'KSEA-KLAX' => 'SUMMA2 TOU J5 AVE SEAVU4',
        
        // Southeast to/from West
        'KATL-KLAX' => 'JACCC J42 MEI J2 DFW J80 DAGGZ SEAVU4',
        'KLAX-KATL' => 'DOTSS HLYWD J80 DFW J2 MEI J42 RPTOR2',
        'KMIA-KLAX' => 'WINCO J79 MEI J2 DFW J80 DAGGZ SEAVU4',
        'KLAX-KMIA' => 'DOTSS HLYWD J80 DFW J2 MEI J79 HILEY3',
        
        // DFW Hub
        'KDFW-KJFK' => 'AKUNA J24 MEM J42 MERIT',
        'KJFK-KDFW' => 'MERIT J42 MEM J24 FINGR',
        'KDFW-KORD' => 'AKUNA J24 STL J94 DENNT',
        'KORD-KDFW' => 'EARND J94 STL J24 FINGR',
        'KDFW-KLAX' => 'LOWGN J80 DAGGZ SEAVU4',
        'KLAX-KDFW' => 'DOTSS HLYWD J80 FINGR',
        
        // DEN Hub
        'KDEN-KJFK' => 'TOMSN J80 SLT J146 PKE J60 MERIT',
        'KJFK-KDEN' => 'BIGGY J80 SLT RAMMS',
        'KDEN-KORD' => 'TOMSN J10 OBH J94 DENNT',
        'KORD-KDEN' => 'EARND J94 OBH J10 RAMMS',
        'KDEN-KLAX' => 'TOMSN J80 DAGGZ SEAVU4',
        'KLAX-KDEN' => 'DOTSS HLYWD J80 RAMMS',
        
        // Additional common pairs
        'KEWR-KATL' => 'BIGGY J209 SBJ J42 NAVHO RPTOR2',
        'KATL-KEWR' => 'JACCC CUTTN J42 SBJ J209 BIGGY',
        'KLGA-KATL' => 'ELIOT J209 SBJ J42 NAVHO RPTOR2',
        'KATL-KLGA' => 'JACCC CUTTN J42 SBJ J209 ELIOT',
        'KPHL-KATL' => 'JIFFY J42 NAVHO RPTOR2',
        'KATL-KPHL' => 'JACCC CUTTN J42 JIFFY',
        
        // Southeast pairs
        'KATL-KMCO' => 'JACCC J79 OMN CWRLD2',
        'KMCO-KATL' => 'CWRLD2 OMN J79 RPTOR2',
        'KATL-KFLL' => 'JACCC J79 CEBEE HILEY3',
        'KFLL-KATL' => 'WINCO J79 RPTOR2',
        
        // Short-haul Northeast
        'KJFK-KBOS' => 'MERIT J42 BOSTN',
        'KBOS-KJFK' => 'HAYED J42 MERIT',
        'KJFK-KDCA' => 'MERIT J209 SIE DOCCS2',
        'KDCA-KJFK' => 'JCOBY J209 MERIT',
        'KJFK-KPHL' => 'MERIT J209 BUNTS',
        'KPHL-KJFK' => 'JIFFY J209 MERIT',
    ];
}

function getKnownFixes() {
    return [
        // Major airports
        'KATL' => ['lat' => 33.6407, 'lon' => -84.4277, 'type' => 'AIRPORT'],
        'KORD' => ['lat' => 41.9742, 'lon' => -87.9073, 'type' => 'AIRPORT'],
        'KDFW' => ['lat' => 32.8998, 'lon' => -97.0403, 'type' => 'AIRPORT'],
        'KDEN' => ['lat' => 39.8561, 'lon' => -104.6737, 'type' => 'AIRPORT'],
        'KJFK' => ['lat' => 40.6413, 'lon' => -73.7781, 'type' => 'AIRPORT'],
        'KLAX' => ['lat' => 33.9425, 'lon' => -118.4081, 'type' => 'AIRPORT'],
        'KSFO' => ['lat' => 37.6213, 'lon' => -122.3790, 'type' => 'AIRPORT'],
        'KSEA' => ['lat' => 47.4502, 'lon' => -122.3088, 'type' => 'AIRPORT'],
        'KMIA' => ['lat' => 25.7959, 'lon' => -80.2870, 'type' => 'AIRPORT'],
        'KBOS' => ['lat' => 42.3656, 'lon' => -71.0096, 'type' => 'AIRPORT'],
        'KEWR' => ['lat' => 40.6895, 'lon' => -74.1745, 'type' => 'AIRPORT'],
        'KLGA' => ['lat' => 40.7769, 'lon' => -73.8740, 'type' => 'AIRPORT'],
        'KPHL' => ['lat' => 39.8719, 'lon' => -75.2411, 'type' => 'AIRPORT'],
        'KDCA' => ['lat' => 38.8521, 'lon' => -77.0402, 'type' => 'AIRPORT'],
        'KIAD' => ['lat' => 38.9531, 'lon' => -77.4565, 'type' => 'AIRPORT'],
        'KBWI' => ['lat' => 39.1774, 'lon' => -76.6684, 'type' => 'AIRPORT'],
        'KCLT' => ['lat' => 35.2140, 'lon' => -80.9431, 'type' => 'AIRPORT'],
        'KMSP' => ['lat' => 44.8848, 'lon' => -93.2223, 'type' => 'AIRPORT'],
        'KDTW' => ['lat' => 42.2162, 'lon' => -83.3554, 'type' => 'AIRPORT'],
        'KPHX' => ['lat' => 33.4373, 'lon' => -112.0078, 'type' => 'AIRPORT'],
        'KLAS' => ['lat' => 36.0840, 'lon' => -115.1537, 'type' => 'AIRPORT'],
        'KIAH' => ['lat' => 29.9902, 'lon' => -95.3368, 'type' => 'AIRPORT'],
        'KMCO' => ['lat' => 28.4312, 'lon' => -81.3081, 'type' => 'AIRPORT'],
        'KFLL' => ['lat' => 26.0742, 'lon' => -80.1506, 'type' => 'AIRPORT'],
        'KTPA' => ['lat' => 27.9756, 'lon' => -82.5333, 'type' => 'AIRPORT'],
        'KSAN' => ['lat' => 32.7336, 'lon' => -117.1897, 'type' => 'AIRPORT'],
        'KPDX' => ['lat' => 45.5898, 'lon' => -122.5951, 'type' => 'AIRPORT'],
        'KSLC' => ['lat' => 40.7884, 'lon' => -111.9778, 'type' => 'AIRPORT'],
        'KSTL' => ['lat' => 38.7487, 'lon' => -90.3700, 'type' => 'AIRPORT'],
        'KMDW' => ['lat' => 41.7868, 'lon' => -87.7522, 'type' => 'AIRPORT'],
        
        // Common fixes (subset for fallback)
        'MERIT' => ['lat' => 40.2500, 'lon' => -73.6667, 'type' => 'FIX'],
        'BIGGY' => ['lat' => 40.4000, 'lon' => -74.2167, 'type' => 'FIX'],
        'HAYED' => ['lat' => 42.1833, 'lon' => -71.2333, 'type' => 'FIX'],
        'JACCC' => ['lat' => 33.8833, 'lon' => -84.3000, 'type' => 'FIX'],
        'CUTTN' => ['lat' => 34.1167, 'lon' => -84.1500, 'type' => 'FIX'],
        'NAVHO' => ['lat' => 33.5167, 'lon' => -84.6667, 'type' => 'FIX'],
        'WINCO' => ['lat' => 25.9000, 'lon' => -80.1500, 'type' => 'FIX'],
        'EARND' => ['lat' => 41.8667, 'lon' => -87.5833, 'type' => 'FIX'],
        'DENNT' => ['lat' => 41.9833, 'lon' => -87.8333, 'type' => 'FIX'],
        'DOTSS' => ['lat' => 33.7833, 'lon' => -118.4500, 'type' => 'FIX'],
        'DAGGZ' => ['lat' => 34.8500, 'lon' => -116.8500, 'type' => 'FIX'],
        'SSTIK' => ['lat' => 37.4167, 'lon' => -122.2500, 'type' => 'FIX'],
        'SERFR' => ['lat' => 37.2500, 'lon' => -122.0000, 'type' => 'FIX'],
        'TOMSN' => ['lat' => 39.7000, 'lon' => -104.5000, 'type' => 'FIX'],
        'RAMMS' => ['lat' => 39.6500, 'lon' => -104.8500, 'type' => 'FIX'],
        'LOWGN' => ['lat' => 33.0833, 'lon' => -97.2000, 'type' => 'FIX'],
        'FINGR' => ['lat' => 32.8333, 'lon' => -97.0000, 'type' => 'FIX'],
        'AKUNA' => ['lat' => 33.0000, 'lon' => -96.8500, 'type' => 'FIX'],
        
        // VORs
        'SLT' => ['lat' => 40.8500, 'lon' => -111.9667, 'type' => 'VOR'],
        'PKE' => ['lat' => 40.9667, 'lon' => -118.5667, 'type' => 'VOR'],
        'OBH' => ['lat' => 41.4500, 'lon' => -100.6833, 'type' => 'VOR'],
        'DBQ' => ['lat' => 42.4000, 'lon' => -90.7000, 'type' => 'VOR'],
        'SBJ' => ['lat' => 39.8500, 'lon' => -74.5333, 'type' => 'VOR'],
        'HNN' => ['lat' => 38.4333, 'lon' => -82.6667, 'type' => 'VOR'],
        'AIR' => ['lat' => 40.4667, 'lon' => -80.7500, 'type' => 'VOR'],
        'MEM' => ['lat' => 35.0500, 'lon' => -89.9833, 'type' => 'VOR'],
        'MEI' => ['lat' => 32.3333, 'lon' => -88.7500, 'type' => 'VOR'],
        'SAV' => ['lat' => 32.1333, 'lon' => -81.2000, 'type' => 'VOR'],
        'OMN' => ['lat' => 29.1833, 'lon' => -81.1167, 'type' => 'VOR'],
        'AVE' => ['lat' => 34.9500, 'lon' => -118.4333, 'type' => 'VOR'],
        'STL' => ['lat' => 38.8667, 'lon' => -90.4833, 'type' => 'VOR'],
    ];
}

function haversineDistance($lat1, $lon1, $lat2, $lon2) {
    $R = 3440.065; // Earth radius in NM
    
    $lat1 = deg2rad($lat1);
    $lat2 = deg2rad($lat2);
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    
    $a = sin($dLat/2) * sin($dLat/2) +
         cos($lat1) * cos($lat2) * sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    
    return $R * $c;
}
