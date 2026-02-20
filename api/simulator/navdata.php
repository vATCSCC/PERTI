<?php
/**
 * api/simulator/navdata.php
 * 
 * Navigation data API for the ATFM flight engine
 * Queries Azure SQL nav_fixes table for fix/airport coordinates
 * 
 * GET Parameters:
 *   action - 'fix' or 'airport'
 *   name   - Fix name (for action=fix)
 *   icao   - Airport ICAO code (for action=airport)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Include database connection
require_once __DIR__ . '/../../load/config.php';
require_once __DIR__ . '/../../load/connect.php';

try {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'fix':
            handleFixQuery();
            break;
        case 'airport':
            handleAirportQuery();
            break;
        case 'airports_batch':
            handleAirportsBatch();
            break;
        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action. Use: fix, airport, airports_batch']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

/**
 * Query fix by name - returns all matches (same name can exist in multiple regions)
 */
function handleFixQuery() {
    global $conn_adl;
    
    $name = strtoupper(trim($_GET['name'] ?? ''));
    
    if (empty($name)) {
        echo json_encode(['status' => 'error', 'message' => 'Missing name parameter']);
        return;
    }
    
    // If ADL connection is available, query nav_fixes
    if ($conn_adl) {
        $sql = "SELECT fix_name, lat, lon, fix_type, artcc_id, country_code
                FROM nav_fixes
                WHERE fix_name = ?";
        
        $params = [$name];
        $stmt = sqlsrv_query($conn_adl, $sql, $params);
        
        if ($stmt === false) {
            // Fall back to hardcoded data
            echo json_encode(getFallbackFix($name));
            return;
        }
        
        $fixes = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $fixes[] = [
                'fix_name' => $row['fix_name'],
                'lat' => floatval($row['lat']),
                'lon' => floatval($row['lon']),
                'fix_type' => $row['fix_type'],
                'artcc_id' => $row['artcc_id'],
                'country_code' => $row['country_code']
            ];
        }
        
        sqlsrv_free_stmt($stmt);
        
        if (count($fixes) > 0) {
            echo json_encode(['status' => 'ok', 'fixes' => $fixes]);
            return;
        }
    }
    
    // Fall back to hardcoded airports
    echo json_encode(getFallbackFix($name));
}

/**
 * Query airport by ICAO code
 */
function handleAirportQuery() {
    global $conn_adl;
    
    $icao = strtoupper(trim($_GET['icao'] ?? ''));
    
    if (empty($icao)) {
        echo json_encode(['status' => 'error', 'message' => 'Missing icao parameter']);
        return;
    }
    
    // Also accept 3-letter FAA codes - prepend K for CONUS
    if (strlen($icao) === 3) {
        $icao = 'K' . $icao;
    }
    
    if ($conn_adl) {
        // Try nav_fixes table first
        $sql = "SELECT fix_name, lat, lon, elevation_ft
                FROM nav_fixes
                WHERE fix_name = ? AND fix_type = 'AIRPORT'";
        
        $params = [$icao];
        $stmt = sqlsrv_query($conn_adl, $sql, $params);
        
        if ($stmt !== false) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            
            if ($row) {
                echo json_encode([
                    'status' => 'ok',
                    'airport' => [
                        'icao' => $row['fix_name'],
                        'lat' => floatval($row['lat']),
                        'lon' => floatval($row['lon']),
                        'elevation_ft' => intval($row['elevation_ft'] ?? 0),
                        'name' => $row['fix_name']
                    ]
                ]);
                return;
            }
        }
    }
    
    // Fall back to hardcoded airports
    $fallback = getFallbackAirport($icao);
    echo json_encode($fallback);
}

/**
 * Batch query multiple airports
 */
function handleAirportsBatch() {
    global $conn_adl;
    
    $icaos = $_GET['icaos'] ?? '';
    
    if (empty($icaos)) {
        echo json_encode(['status' => 'error', 'message' => 'Missing icaos parameter']);
        return;
    }
    
    $icaoList = array_map('strtoupper', array_map('trim', explode(',', $icaos)));
    $icaoList = array_filter($icaoList);
    
    // Normalize 3-letter codes
    $icaoList = array_map(function($code) {
        return (strlen($code) === 3) ? 'K' . $code : $code;
    }, $icaoList);
    
    $airports = [];
    
    if ($conn_adl && count($icaoList) > 0) {
        $placeholders = implode(',', array_fill(0, count($icaoList), '?'));
        
        $sql = "SELECT fix_name, lat, lon, elevation_ft
                FROM nav_fixes
                WHERE fix_name IN ($placeholders) AND fix_type = 'AIRPORT'";
        
        $stmt = sqlsrv_query($conn_adl, $sql, $icaoList);
        
        if ($stmt !== false) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $airports[$row['fix_name']] = [
                    'icao' => $row['fix_name'],
                    'lat' => floatval($row['lat']),
                    'lon' => floatval($row['lon']),
                    'elevation_ft' => intval($row['elevation_ft'] ?? 0)
                ];
            }
            sqlsrv_free_stmt($stmt);
        }
    }
    
    // Fill in missing with fallback data
    foreach ($icaoList as $icao) {
        if (!isset($airports[$icao])) {
            $fb = getFallbackAirport($icao);
            if ($fb['status'] === 'ok') {
                $airports[$icao] = $fb['airport'];
            }
        }
    }
    
    echo json_encode(['status' => 'ok', 'airports' => $airports]);
}

/**
 * Fallback fix/airport data for common locations
 */
function getFallbackFix($name) {
    $known = getKnownAirports();
    
    if (isset($known[$name])) {
        return [
            'status' => 'ok',
            'fixes' => [[
                'fix_name' => $name,
                'lat' => $known[$name]['lat'],
                'lon' => $known[$name]['lon'],
                'fix_type' => 'AIRPORT',
                'artcc_id' => null,
                'country_code' => 'US'
            ]]
        ];
    }
    
    return ['status' => 'ok', 'fixes' => []];
}

function getFallbackAirport($icao) {
    $known = getKnownAirports();
    
    if (isset($known[$icao])) {
        return [
            'status' => 'ok',
            'airport' => [
                'icao' => $icao,
                'lat' => $known[$icao]['lat'],
                'lon' => $known[$icao]['lon'],
                'elevation_ft' => $known[$icao]['elev'] ?? 0,
                'name' => $known[$icao]['name'] ?? $icao
            ]
        ];
    }
    
    return ['status' => 'ok', 'airport' => null];
}

function getKnownAirports() {
    return [
        // Major US Hubs
        'KATL' => ['lat' => 33.6407, 'lon' => -84.4277, 'elev' => 1026, 'name' => 'Atlanta Hartsfield-Jackson'],
        'KORD' => ['lat' => 41.9742, 'lon' => -87.9073, 'elev' => 672, 'name' => 'Chicago O\'Hare'],
        'KDFW' => ['lat' => 32.8998, 'lon' => -97.0403, 'elev' => 607, 'name' => 'Dallas/Fort Worth'],
        'KDEN' => ['lat' => 39.8561, 'lon' => -104.6737, 'elev' => 5433, 'name' => 'Denver'],
        'KJFK' => ['lat' => 40.6413, 'lon' => -73.7781, 'elev' => 13, 'name' => 'New York JFK'],
        'KLAX' => ['lat' => 33.9425, 'lon' => -118.4081, 'elev' => 128, 'name' => 'Los Angeles'],
        'KSFO' => ['lat' => 37.6213, 'lon' => -122.3790, 'elev' => 13, 'name' => 'San Francisco'],
        'KSEA' => ['lat' => 47.4502, 'lon' => -122.3088, 'elev' => 433, 'name' => 'Seattle-Tacoma'],
        'KMIA' => ['lat' => 25.7959, 'lon' => -80.2870, 'elev' => 8, 'name' => 'Miami'],
        'KBOS' => ['lat' => 42.3656, 'lon' => -71.0096, 'elev' => 19, 'name' => 'Boston Logan'],
        'KEWR' => ['lat' => 40.6895, 'lon' => -74.1745, 'elev' => 18, 'name' => 'Newark'],
        'KLGA' => ['lat' => 40.7769, 'lon' => -73.8740, 'elev' => 21, 'name' => 'New York LaGuardia'],
        'KPHL' => ['lat' => 39.8719, 'lon' => -75.2411, 'elev' => 36, 'name' => 'Philadelphia'],
        'KDCA' => ['lat' => 38.8521, 'lon' => -77.0402, 'elev' => 15, 'name' => 'Washington National'],
        'KIAD' => ['lat' => 38.9531, 'lon' => -77.4565, 'elev' => 313, 'name' => 'Washington Dulles'],
        'KBWI' => ['lat' => 39.1774, 'lon' => -76.6684, 'elev' => 146, 'name' => 'Baltimore'],
        'KCLT' => ['lat' => 35.2140, 'lon' => -80.9431, 'elev' => 748, 'name' => 'Charlotte'],
        'KMSP' => ['lat' => 44.8848, 'lon' => -93.2223, 'elev' => 841, 'name' => 'Minneapolis'],
        'KDTW' => ['lat' => 42.2162, 'lon' => -83.3554, 'elev' => 645, 'name' => 'Detroit'],
        'KPHX' => ['lat' => 33.4373, 'lon' => -112.0078, 'elev' => 1135, 'name' => 'Phoenix'],
        'KLAS' => ['lat' => 36.0840, 'lon' => -115.1537, 'elev' => 2181, 'name' => 'Las Vegas'],
        'KIAH' => ['lat' => 29.9902, 'lon' => -95.3368, 'elev' => 97, 'name' => 'Houston Intercontinental'],
        'KMCO' => ['lat' => 28.4312, 'lon' => -81.3081, 'elev' => 96, 'name' => 'Orlando'],
        'KFLL' => ['lat' => 26.0742, 'lon' => -80.1506, 'elev' => 9, 'name' => 'Fort Lauderdale'],
        'KTPA' => ['lat' => 27.9756, 'lon' => -82.5333, 'elev' => 26, 'name' => 'Tampa'],
        'KSAN' => ['lat' => 32.7336, 'lon' => -117.1897, 'elev' => 17, 'name' => 'San Diego'],
        'KPDX' => ['lat' => 45.5898, 'lon' => -122.5951, 'elev' => 31, 'name' => 'Portland'],
        'KSLC' => ['lat' => 40.7884, 'lon' => -111.9778, 'elev' => 4227, 'name' => 'Salt Lake City'],
        'KSTL' => ['lat' => 38.7487, 'lon' => -90.3700, 'elev' => 618, 'name' => 'St. Louis'],
        'KMDW' => ['lat' => 41.7868, 'lon' => -87.7522, 'elev' => 620, 'name' => 'Chicago Midway'],
        
        // Secondary without K prefix aliases
        'ATL' => ['lat' => 33.6407, 'lon' => -84.4277, 'elev' => 1026],
        'ORD' => ['lat' => 41.9742, 'lon' => -87.9073, 'elev' => 672],
        'DFW' => ['lat' => 32.8998, 'lon' => -97.0403, 'elev' => 607],
        'JFK' => ['lat' => 40.6413, 'lon' => -73.7781, 'elev' => 13],
        'LAX' => ['lat' => 33.9425, 'lon' => -118.4081, 'elev' => 128],
        'SFO' => ['lat' => 37.6213, 'lon' => -122.3790, 'elev' => 13],
    ];
}
