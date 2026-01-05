<?php
/**
 * Weather Radar API Endpoint
 * 
 * Provides metadata, timestamps, and radar station information
 * for the TSD weather radar display.
 * 
 * Endpoints:
 *   GET /api/data/weather.php                    - Current radar metadata
 *   GET /api/data/weather.php?action=stations    - NEXRAD station list
 *   GET /api/data/weather.php?action=products    - Available products
 *   GET /api/data/weather.php?action=timestamps  - Recent frame timestamps
 */

header('Content-Type: application/json');
header('Cache-Control: public, max-age=60'); // 1-minute cache
header('Access-Control-Allow-Origin: *');

// ============================================================================
// CONFIGURATION
// ============================================================================

// IEM data endpoints
const IEM_BASE = 'https://mesonet.agron.iastate.edu';
const IEM_RIDGE_META = '/data/gis/images/4326/ridge';
const IEM_NEXRAD_META = '/data/gis/images/4326/USCOMP';

// NEXRAD products available
const NEXRAD_PRODUCTS = [
    'N0Q' => ['name' => 'Base Reflectivity', 'unit' => 'dBZ', 'resolution' => '0.5 deg'],
    'N0U' => ['name' => 'Base Velocity', 'unit' => 'kts', 'resolution' => '0.5 deg'],
    'EET' => ['name' => 'Echo Tops', 'unit' => 'kft', 'resolution' => '1 deg'],
    'N0S' => ['name' => 'Storm Relative Velocity', 'unit' => 'kts', 'resolution' => '0.5 deg'],
];

// FAA ATC color table (HF-STD-010A)
const FAA_COLOR_TABLE = [
    ['severity' => 1, 'label' => 'Light', 'dbz_min' => 5, 'dbz_max' => 30, 'color' => '#173928'],
    ['severity' => 2, 'label' => 'Light-Moderate', 'dbz_min' => 20, 'dbz_max' => 30, 'color' => '#173928'],
    ['severity' => 3, 'label' => 'Moderate', 'dbz_min' => 30, 'dbz_max' => 40, 'color' => '#5A4A14'],
    ['severity' => 4, 'label' => 'Moderate-Heavy', 'dbz_min' => 35, 'dbz_max' => 45, 'color' => '#5A4A14'],
    ['severity' => 5, 'label' => 'Heavy', 'dbz_min' => 40, 'dbz_max' => 50, 'color' => '#5D2E59'],
    ['severity' => 6, 'label' => 'Extreme', 'dbz_min' => 50, 'dbz_max' => 999, 'color' => '#5D2E59'],
];

// NWS standard color table
const NWS_COLOR_TABLE = [
    ['dbz' => 5, 'color' => '#04e9e7', 'label' => '5 dBZ'],
    ['dbz' => 10, 'color' => '#019ff4', 'label' => '10 dBZ'],
    ['dbz' => 15, 'color' => '#0300f4', 'label' => '15 dBZ'],
    ['dbz' => 20, 'color' => '#02fd02', 'label' => '20 dBZ (Light)'],
    ['dbz' => 25, 'color' => '#01c501', 'label' => '25 dBZ'],
    ['dbz' => 30, 'color' => '#008e00', 'label' => '30 dBZ (Moderate)'],
    ['dbz' => 35, 'color' => '#fdf802', 'label' => '35 dBZ'],
    ['dbz' => 40, 'color' => '#e5bc00', 'label' => '40 dBZ (Heavy)'],
    ['dbz' => 45, 'color' => '#fd9500', 'label' => '45 dBZ'],
    ['dbz' => 50, 'color' => '#fd0000', 'label' => '50 dBZ (Extreme)'],
    ['dbz' => 55, 'color' => '#d40000', 'label' => '55 dBZ'],
    ['dbz' => 60, 'color' => '#bc0000', 'label' => '60 dBZ'],
    ['dbz' => 65, 'color' => '#f800fd', 'label' => '65 dBZ (Hail)'],
    ['dbz' => 70, 'color' => '#9854c6', 'label' => '70 dBZ'],
    ['dbz' => 75, 'color' => '#fdfdfd', 'label' => '75 dBZ'],
];

// CONUS NEXRAD stations (subset - key sites)
const NEXRAD_STATIONS = [
    // Northeast
    ['id' => 'KOKX', 'name' => 'New York City, NY', 'lat' => 40.8656, 'lon' => -72.8639, 'artcc' => 'ZNY'],
    ['id' => 'KBOX', 'name' => 'Boston, MA', 'lat' => 41.9558, 'lon' => -71.1369, 'artcc' => 'ZBW'],
    ['id' => 'KPHL', 'name' => 'Philadelphia, PA', 'lat' => 39.9472, 'lon' => -75.0789, 'artcc' => 'ZNY'],
    ['id' => 'KDOX', 'name' => 'Dover AFB, DE', 'lat' => 38.8256, 'lon' => -75.4400, 'artcc' => 'ZDC'],
    
    // Southeast
    ['id' => 'KFFC', 'name' => 'Atlanta, GA', 'lat' => 33.3636, 'lon' => -84.5658, 'artcc' => 'ZTL'],
    ['id' => 'KAMX', 'name' => 'Miami, FL', 'lat' => 25.6111, 'lon' => -80.4128, 'artcc' => 'ZMA'],
    ['id' => 'KTBW', 'name' => 'Tampa Bay, FL', 'lat' => 27.7056, 'lon' => -82.4017, 'artcc' => 'ZJX'],
    ['id' => 'KMXX', 'name' => 'Maxwell AFB, AL', 'lat' => 32.5367, 'lon' => -85.7897, 'artcc' => 'ZTL'],
    ['id' => 'KCLX', 'name' => 'Charleston, SC', 'lat' => 32.6556, 'lon' => -81.0422, 'artcc' => 'ZJX'],
    ['id' => 'KRAX', 'name' => 'Raleigh, NC', 'lat' => 35.6656, 'lon' => -78.4900, 'artcc' => 'ZDC'],
    
    // Midwest
    ['id' => 'KLOT', 'name' => 'Chicago, IL', 'lat' => 41.6044, 'lon' => -88.0847, 'artcc' => 'ZAU'],
    ['id' => 'KDTX', 'name' => 'Detroit, MI', 'lat' => 42.6997, 'lon' => -83.4717, 'artcc' => 'ZOB'],
    ['id' => 'KIND', 'name' => 'Indianapolis, IN', 'lat' => 39.7075, 'lon' => -86.2803, 'artcc' => 'ZID'],
    ['id' => 'KILN', 'name' => 'Cincinnati, OH', 'lat' => 39.4203, 'lon' => -83.8217, 'artcc' => 'ZID'],
    ['id' => 'KMPX', 'name' => 'Minneapolis, MN', 'lat' => 44.8489, 'lon' => -93.5653, 'artcc' => 'ZMP'],
    ['id' => 'KEAX', 'name' => 'Kansas City, MO', 'lat' => 38.8103, 'lon' => -94.2644, 'artcc' => 'ZKC'],
    ['id' => 'KLSX', 'name' => 'St. Louis, MO', 'lat' => 38.6989, 'lon' => -90.6828, 'artcc' => 'ZKC'],
    
    // Southwest
    ['id' => 'KFWS', 'name' => 'Dallas/Ft Worth, TX', 'lat' => 32.5728, 'lon' => -97.3033, 'artcc' => 'ZFW'],
    ['id' => 'KHGX', 'name' => 'Houston, TX', 'lat' => 29.4719, 'lon' => -95.0792, 'artcc' => 'ZHU'],
    ['id' => 'KEWX', 'name' => 'San Antonio, TX', 'lat' => 29.7039, 'lon' => -98.0286, 'artcc' => 'ZHU'],
    ['id' => 'KIWA', 'name' => 'Phoenix, AZ', 'lat' => 33.2892, 'lon' => -111.6700, 'artcc' => 'ZAB'],
    ['id' => 'KABX', 'name' => 'Albuquerque, NM', 'lat' => 35.1497, 'lon' => -106.8239, 'artcc' => 'ZAB'],
    ['id' => 'KEYX', 'name' => 'Las Vegas, NV', 'lat' => 35.0978, 'lon' => -117.5608, 'artcc' => 'ZLA'],
    
    // West
    ['id' => 'KVTX', 'name' => 'Los Angeles, CA', 'lat' => 34.4117, 'lon' => -119.1792, 'artcc' => 'ZLA'],
    ['id' => 'KMUX', 'name' => 'San Francisco, CA', 'lat' => 37.1553, 'lon' => -121.8981, 'artcc' => 'ZOA'],
    ['id' => 'KRTX', 'name' => 'Portland, OR', 'lat' => 45.7150, 'lon' => -122.9656, 'artcc' => 'ZSE'],
    ['id' => 'KATX', 'name' => 'Seattle, WA', 'lat' => 48.1944, 'lon' => -122.4958, 'artcc' => 'ZSE'],
    ['id' => 'KBYX', 'name' => 'Key West, FL', 'lat' => 24.5975, 'lon' => -81.7033, 'artcc' => 'ZMA'],
    
    // Central/Mountain
    ['id' => 'KDEN', 'name' => 'Denver, CO', 'lat' => 39.7867, 'lon' => -104.5458, 'artcc' => 'ZDV'],
    ['id' => 'KSLC', 'name' => 'Salt Lake City, UT', 'lat' => 40.9697, 'lon' => -111.9300, 'artcc' => 'ZLC'],
    ['id' => 'KBOI', 'name' => 'Boise, ID', 'lat' => 43.4917, 'lon' => -116.1942, 'artcc' => 'ZLC'],
];

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Generate frame timestamps for animation
 * @param int $numFrames Number of frames to generate
 * @param int $intervalMinutes Interval between frames
 * @return array Frame timestamps
 */
function generateFrameTimestamps($numFrames = 12, $intervalMinutes = 5) {
    $frames = [];
    $now = time();
    
    // Round down to nearest interval
    $now = $now - ($now % ($intervalMinutes * 60));
    
    for ($i = $numFrames - 1; $i >= 0; $i--) {
        $frameTime = $now - ($i * $intervalMinutes * 60);
        $frames[] = [
            'index' => $numFrames - 1 - $i,
            'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z', $frameTime),
            'display' => gmdate('H:i', $frameTime) . 'Z',
            'minutes_ago' => $i * $intervalMinutes
        ];
    }
    
    return $frames;
}

/**
 * Fetch IEM radar metadata JSON
 * @param string $station Station ID (e.g., 'DMX')
 * @param string $product Product code (e.g., 'N0Q')
 * @return array|null Metadata or null on failure
 */
function fetchIemMetadata($station, $product = 'N0Q') {
    $url = IEM_BASE . IEM_RIDGE_META . "/$station/{$product}_0.json";
    
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 5,
            'ignore_errors' => true
        ]
    ]);
    
    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) {
        return null;
    }
    
    return json_decode($response, true);
}

// ============================================================================
// REQUEST HANDLING
// ============================================================================

$action = $_GET['action'] ?? 'metadata';

switch ($action) {
    case 'stations':
        // Return NEXRAD station list
        echo json_encode([
            'success' => true,
            'stations' => NEXRAD_STATIONS,
            'count' => count(NEXRAD_STATIONS)
        ]);
        break;
        
    case 'products':
        // Return available products
        echo json_encode([
            'success' => true,
            'products' => NEXRAD_PRODUCTS
        ]);
        break;
        
    case 'timestamps':
        // Return animation frame timestamps
        $numFrames = intval($_GET['frames'] ?? 12);
        $interval = intval($_GET['interval'] ?? 5);
        
        $numFrames = max(1, min(24, $numFrames));
        $interval = max(5, min(30, $interval));
        
        echo json_encode([
            'success' => true,
            'frames' => generateFrameTimestamps($numFrames, $interval),
            'generated_utc' => gmdate('Y-m-d\TH:i:s\Z')
        ]);
        break;
        
    case 'station_meta':
        // Return metadata for specific station
        $station = strtoupper($_GET['station'] ?? '');
        $product = strtoupper($_GET['product'] ?? 'N0Q');
        
        if (empty($station) || strlen($station) !== 4) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid station ID']);
            break;
        }
        
        $meta = fetchIemMetadata($station, $product);
        if ($meta === null) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Station metadata not found']);
            break;
        }
        
        echo json_encode([
            'success' => true,
            'station' => $station,
            'product' => $product,
            'metadata' => $meta
        ]);
        break;
        
    case 'legend':
        // Return color legend
        $table = $_GET['table'] ?? 'NWS';
        
        if ($table === 'FAA') {
            echo json_encode([
                'success' => true,
                'table' => 'FAA_ATC',
                'name' => 'FAA ATC (HF-STD-010A)',
                'description' => 'FAA standard colors for air traffic control displays',
                'colors' => FAA_COLOR_TABLE
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'table' => 'NWS',
                'name' => 'NWS Standard',
                'description' => 'National Weather Service standard radar colors',
                'colors' => NWS_COLOR_TABLE
            ]);
        }
        break;
        
    case 'metadata':
    default:
        // Return general metadata
        echo json_encode([
            'success' => true,
            'source' => 'Iowa Environmental Mesonet (IEM)',
            'source_url' => 'https://mesonet.agron.iastate.edu',
            'data_type' => 'NEXRAD Level III / MRMS',
            'coverage' => 'CONUS, Alaska, Hawaii, Caribbean, Guam',
            'update_frequency' => '~5 minutes',
            'tile_endpoints' => [
                'realtime' => IEM_BASE . '/cache/tile.py/1.0.0/{layer}/{z}/{x}/{y}.png',
                'static' => IEM_BASE . '/c/tile.py/1.0.0/{layer}/{z}/{x}/{y}.png'
            ],
            'available_layers' => [
                'nexrad-n0q' => 'NEXRAD Base Reflectivity (current)',
                'nexrad-n0q-mXXm' => 'NEXRAD Base Reflectivity (XX minutes ago)',
                'nexrad-eet' => 'NEXRAD Echo Tops',
                'q2-hsr' => 'MRMS Hybrid-Scan Reflectivity',
                'q2-n1p' => 'MRMS 1-Hour Precipitation',
                'q2-p24h' => 'MRMS 24-Hour Precipitation'
            ],
            'color_tables' => ['NWS', 'FAA_ATC', 'SCOPE'],
            'animation' => [
                'frames' => generateFrameTimestamps(12, 5),
                'interval_minutes' => 5
            ],
            'stations_count' => count(NEXRAD_STATIONS),
            'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z')
        ]);
        break;
}
