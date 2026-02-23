<?php
/**
 * api/data/fixes.php
 * 
 * GET - Retrieve fix coordinates from database or static file
 * 
 * Query params:
 *   names  - Comma-separated fix names (required)
 *   format - "json" (default) or "geojson"
 */

header('Content-Type: application/json');
header('Cache-Control: public, max-age=3600'); // Navaid fixes change only at AIRAC cycles (~28 days)

// Try database first, then fall back to static file
$fixes = [];

// Common fixes database (hardcoded for now - can be moved to DB table later)
$KNOWN_FIXES = [
    // Northeast Corridor
    'MERIT' => ['lat' => 40.8500, 'lon' => -73.3000],
    'GREKI' => ['lat' => 40.9833, 'lon' => -72.3333],
    'JUDDS' => ['lat' => 41.2167, 'lon' => -72.8833],
    'WHITE' => ['lat' => 41.0667, 'lon' => -73.6167],
    'COATE' => ['lat' => 40.7167, 'lon' => -73.8600],
    'DIXIE' => ['lat' => 40.4667, 'lon' => -74.0667],
    'LANNA' => ['lat' => 40.5333, 'lon' => -73.2333],
    'WAVEY' => ['lat' => 40.3500, 'lon' => -73.7800],
    'BETTE' => ['lat' => 40.1333, 'lon' => -73.6200],
    'PARCH' => ['lat' => 40.7700, 'lon' => -73.1000],
    'HAPIE' => ['lat' => 40.8500, 'lon' => -72.7300],
    'NEION' => ['lat' => 41.5000, 'lon' => -74.3700],
    'GAYEL' => ['lat' => 40.2833, 'lon' => -74.3333],
    'BIGGY' => ['lat' => 40.0167, 'lon' => -74.1333],
    'SKIPY' => ['lat' => 40.6167, 'lon' => -73.4833],
    
    // NY Metro Airports (as fixes)
    'JFK'  => ['lat' => 40.6413, 'lon' => -73.7781],
    'KJFK' => ['lat' => 40.6413, 'lon' => -73.7781],
    'LGA'  => ['lat' => 40.7769, 'lon' => -73.8740],
    'KLGA' => ['lat' => 40.7769, 'lon' => -73.8740],
    'EWR'  => ['lat' => 40.6895, 'lon' => -74.1745],
    'KEWR' => ['lat' => 40.6895, 'lon' => -74.1745],
    'TEB'  => ['lat' => 40.8501, 'lon' => -74.0608],
    'KTEB' => ['lat' => 40.8501, 'lon' => -74.0608],
    
    // NY Area VORs/Fixes
    'RBV'  => ['lat' => 40.2000, 'lon' => -74.5000],
    'SBJ'  => ['lat' => 40.8833, 'lon' => -74.5667],
    'FJC'  => ['lat' => 40.9333, 'lon' => -74.1500],
    'CMK'  => ['lat' => 41.0333, 'lon' => -74.1667],
    'STW'  => ['lat' => 40.5333, 'lon' => -74.4833],
    'COL'  => ['lat' => 41.1333, 'lon' => -75.6833],
    'LVZ'  => ['lat' => 40.6167, 'lon' => -75.5333],
    'PTW'  => ['lat' => 40.2167, 'lon' => -75.5500],
    
    // Boston Area
    'BOS'  => ['lat' => 42.3656, 'lon' => -71.0096],
    'KBOS' => ['lat' => 42.3656, 'lon' => -71.0096],
    'BOSOX' => ['lat' => 42.1500, 'lon' => -71.1500],
    'KORD' => ['lat' => 41.9742, 'lon' => -87.9073],
    'ORD'  => ['lat' => 41.9742, 'lon' => -87.9073],
    
    // DC Area
    'DCA'  => ['lat' => 38.8521, 'lon' => -77.0402],
    'KDCA' => ['lat' => 38.8521, 'lon' => -77.0402],
    'IAD'  => ['lat' => 38.9531, 'lon' => -77.4565],
    'KIAD' => ['lat' => 38.9531, 'lon' => -77.4565],
    'BWI'  => ['lat' => 39.1774, 'lon' => -76.6684],
    'KBWI' => ['lat' => 39.1774, 'lon' => -76.6684],
    
    // Southeast
    'ATL'  => ['lat' => 33.6407, 'lon' => -84.4277],
    'KATL' => ['lat' => 33.6407, 'lon' => -84.4277],
    'CLT'  => ['lat' => 35.2140, 'lon' => -80.9431],
    'KCLT' => ['lat' => 35.2140, 'lon' => -80.9431],
    'MIA'  => ['lat' => 25.7959, 'lon' => -80.2870],
    'KMIA' => ['lat' => 25.7959, 'lon' => -80.2870],
    
    // West Coast
    'LAX'  => ['lat' => 33.9425, 'lon' => -118.4081],
    'KLAX' => ['lat' => 33.9425, 'lon' => -118.4081],
    'SFO'  => ['lat' => 37.6213, 'lon' => -122.3790],
    'KSFO' => ['lat' => 37.6213, 'lon' => -122.3790],
    'SEA'  => ['lat' => 47.4502, 'lon' => -122.3088],
    'KSEA' => ['lat' => 47.4502, 'lon' => -122.3088],
    'DEN'  => ['lat' => 39.8561, 'lon' => -104.6737],
    'KDEN' => ['lat' => 39.8561, 'lon' => -104.6737],
    'PHX'  => ['lat' => 33.4373, 'lon' => -112.0078],
    'KPHX' => ['lat' => 33.4373, 'lon' => -112.0078],
    'LAS'  => ['lat' => 36.0840, 'lon' => -115.1537],
    'KLAS' => ['lat' => 36.0840, 'lon' => -115.1537],
    
    // Central
    'DFW'  => ['lat' => 32.8998, 'lon' => -97.0403],
    'KDFW' => ['lat' => 32.8998, 'lon' => -97.0403],
    'IAH'  => ['lat' => 29.9902, 'lon' => -95.3368],
    'KIAH' => ['lat' => 29.9902, 'lon' => -95.3368],
    'MSP'  => ['lat' => 44.8848, 'lon' => -93.2223],
    'KMSP' => ['lat' => 44.8848, 'lon' => -93.2223],
    'DTW'  => ['lat' => 42.2162, 'lon' => -83.3554],
    'KDTW' => ['lat' => 42.2162, 'lon' => -83.3554],
];

try {
    if (!isset($_GET['names']) || trim($_GET['names']) === '') {
        echo json_encode(['status' => 'ok', 'fixes' => [], 'message' => 'No fix names provided']);
        exit;
    }
    
    $names = array_map('strtoupper', array_map('trim', explode(',', $_GET['names'])));
    $names = array_filter($names);
    $format = strtolower($_GET['format'] ?? 'json');
    
    $found = [];
    $notFound = [];
    
    foreach ($names as $name) {
        if (isset($KNOWN_FIXES[$name])) {
            $found[$name] = $KNOWN_FIXES[$name];
        } else {
            $notFound[] = $name;
        }
    }
    
    // Try to load from airports CSV for any not found
    if (!empty($notFound)) {
        $aptFile = __DIR__ . '/../../assets/data/apts.csv';
        if (file_exists($aptFile)) {
            $lines = file($aptFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $i => $line) {
                if ($i === 0) continue; // Skip header
                $parts = str_getcsv($line);
                if (count($parts) >= 3) {
                    $icao = strtoupper(trim($parts[0]));
                    if (in_array($icao, $notFound)) {
                        $found[$icao] = [
                            'lat' => floatval($parts[1]),
                            'lon' => floatval($parts[2])
                        ];
                        $notFound = array_diff($notFound, [$icao]);
                    }
                }
            }
        }
    }
    
    if ($format === 'geojson') {
        $features = [];
        foreach ($found as $name => $coords) {
            $features[] = [
                'type' => 'Feature',
                'properties' => ['name' => $name],
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [$coords['lon'], $coords['lat']]
                ]
            ];
        }
        echo json_encode([
            'type' => 'FeatureCollection',
            'features' => $features
        ]);
    } else {
        echo json_encode([
            'status' => 'ok',
            'found' => count($found),
            'not_found' => $notFound,
            'fixes' => $found
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
