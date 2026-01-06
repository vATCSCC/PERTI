<?php
/**
 * PERTI Weather Alert Import
 * 
 * Fetches SIGMET/AIRMET data from aviationweather.gov and imports into VATSIM_ADL
 * 
 * Usage:
 *   php import_weather_alerts.php                    # Full import
 *   php import_weather_alerts.php --type=sigmet     # SIGMET only
 *   php import_weather_alerts.php --type=airmet     # AIRMET only
 *   php import_weather_alerts.php --dry-run         # Test without importing
 * 
 * Scheduled: Run every 5 minutes via cron or Windows Task Scheduler
 * 
 * @version 1.0
 * @date 2026-01-06
 */

// Configuration
define('AWC_BASE_URL', 'https://aviationweather.gov/api/data/airsigmet');
define('DB_SERVER', 'vatsim.database.windows.net');
define('DB_NAME', 'VATSIM_ADL');

// Parse command line arguments
$options = getopt('', ['type:', 'dry-run', 'verbose', 'help']);

if (isset($options['help'])) {
    echo "PERTI Weather Alert Import\n";
    echo "Usage: php import_weather_alerts.php [options]\n\n";
    echo "Options:\n";
    echo "  --type=sigmet|airmet   Import only specified type\n";
    echo "  --dry-run              Fetch and parse but don't import\n";
    echo "  --verbose              Show detailed output\n";
    echo "  --help                 Show this help\n";
    exit(0);
}

$typeFilter = $options['type'] ?? null;
$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);

// ============================================================================
// Main
// ============================================================================

echo "=======================================================================\n";
echo "  PERTI Weather Alert Import\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================================\n\n";

$startTime = microtime(true);

try {
    // Fetch weather data from AWC
    $alerts = fetchWeatherAlerts($typeFilter, $verbose);
    
    echo "Fetched " . count($alerts) . " alerts from aviationweather.gov\n\n";
    
    if ($verbose) {
        summarizeAlerts($alerts);
    }
    
    if ($dryRun) {
        echo "[DRY RUN] Skipping database import\n";
        
        // Show sample data
        if (count($alerts) > 0) {
            echo "\nSample alert:\n";
            print_r($alerts[0]);
        }
    } else {
        // Import to database
        $result = importAlerts($alerts, $verbose);
        
        echo "\nImport Results:\n";
        echo "  Inserted: {$result['inserted']}\n";
        echo "  Updated:  {$result['updated']}\n";
        echo "  Expired:  {$result['expired']}\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

$elapsed = round(microtime(true) - $startTime, 2);
echo "\nCompleted in {$elapsed} seconds\n";

// ============================================================================
// Functions
// ============================================================================

/**
 * Fetch SIGMET/AIRMET data from Aviation Weather Center
 */
function fetchWeatherAlerts($typeFilter = null, $verbose = false) {
    $alerts = [];
    
    // Build URL
    $params = [
        'format' => 'json',
        'date' => gmdate('Ymd_Hi')  // Current UTC time
    ];
    
    if ($typeFilter) {
        $params['type'] = $typeFilter;
    }
    
    $url = AWC_BASE_URL . '?' . http_build_query($params);
    
    if ($verbose) {
        echo "Fetching: $url\n\n";
    }
    
    // Fetch with cURL for better error handling
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'PERTI-WeatherImport/1.0',
        CURLOPT_HTTPHEADER => ['Accept: application/json']
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception("cURL error: $error");
    }
    
    if ($httpCode !== 200) {
        throw new Exception("HTTP $httpCode from aviationweather.gov");
    }
    
    // Parse JSON response
    $data = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON: " . json_last_error_msg());
    }
    
    // AWC returns array directly or nested in 'features'
    $items = $data['features'] ?? $data;
    
    if (!is_array($items)) {
        if ($verbose) {
            echo "No alerts in response\n";
        }
        return [];
    }
    
    // Process each alert
    foreach ($items as $item) {
        $alert = parseAlert($item);
        if ($alert) {
            $alerts[] = $alert;
        }
    }
    
    return $alerts;
}

/**
 * Parse a single alert into our format
 */
function parseAlert($item) {
    // Handle GeoJSON format
    $props = $item['properties'] ?? $item;
    $geom = $item['geometry'] ?? null;
    
    // Determine alert type and hazard
    $rawType = strtoupper($props['airSigmetType'] ?? $props['type'] ?? '');
    $hazard = strtoupper($props['hazard'] ?? $props['wxType'] ?? 'UNKNOWN');
    
    // Map to our categories
    $alertType = 'SIGMET';
    if (strpos($rawType, 'AIRMET') !== false) {
        $alertType = 'AIRMET';
    } elseif (strpos($rawType, 'OUTLOOK') !== false || strpos($rawType, 'OTLK') !== false) {
        $alertType = 'OUTLOOK';
    } elseif ($hazard === 'CONVECTIVE' || strpos($rawType, 'CONVECTIVE') !== false) {
        $alertType = 'CONVECTIVE';
    }
    
    // Parse coordinates into WKT
    $wkt = null;
    $centerLat = null;
    $centerLon = null;
    
    if ($geom && isset($geom['coordinates'])) {
        // GeoJSON format
        $wkt = geojsonToWkt($geom);
        $center = calculateCentroid($geom['coordinates']);
        $centerLat = $center['lat'];
        $centerLon = $center['lon'];
    } elseif (isset($props['coords']) && is_array($props['coords'])) {
        // AWC coords array format
        $wkt = coordsToWkt($props['coords']);
        $center = calculateCentroidFromCoords($props['coords']);
        $centerLat = $center['lat'];
        $centerLon = $center['lon'];
    }
    
    if (!$wkt) {
        return null; // Skip alerts without valid geometry
    }
    
    // Parse times
    $validFrom = parseTime($props['validTimeFrom'] ?? $props['validFrom'] ?? $props['issueTime'] ?? null);
    $validTo = parseTime($props['validTimeTo'] ?? $props['validTo'] ?? $props['expireTime'] ?? null);
    
    if (!$validFrom || !$validTo) {
        return null; // Skip alerts without valid times
    }
    
    // Parse altitudes
    $floorFl = parseAltitude($props['altitudeLow1'] ?? $props['base'] ?? $props['loAlt'] ?? null);
    $ceilingFl = parseAltitude($props['altitudeHi1'] ?? $props['top'] ?? $props['hiAlt'] ?? null);
    
    // Parse movement
    $direction = intval($props['dir'] ?? $props['movementDir'] ?? 0) ?: null;
    $speed = intval($props['spd'] ?? $props['movementSpd'] ?? 0) ?: null;
    
    // Source ID
    $sourceId = $props['airSigmetId'] ?? $props['id'] ?? $props['seriesId'] ?? generateSourceId($alertType, $hazard);
    
    return [
        'alert_type' => $alertType,
        'hazard' => $hazard,
        'severity' => strtoupper($props['severity'] ?? '') ?: null,
        'source_id' => $sourceId,
        'valid_from' => $validFrom,
        'valid_to' => $validTo,
        'floor_fl' => $floorFl,
        'ceiling_fl' => $ceilingFl,
        'direction' => $direction,
        'speed' => $speed,
        'wkt' => $wkt,
        'center_lat' => $centerLat,
        'center_lon' => $centerLon,
        'raw_text' => $props['rawAirSigmet'] ?? $props['rawText'] ?? null
    ];
}

/**
 * Convert GeoJSON geometry to WKT
 */
function geojsonToWkt($geom) {
    $type = $geom['type'] ?? '';
    $coords = $geom['coordinates'] ?? [];
    
    if ($type === 'Polygon' && !empty($coords)) {
        // Polygon: array of rings, first ring is outer boundary
        $ring = $coords[0];
        $points = [];
        foreach ($ring as $coord) {
            $points[] = $coord[0] . ' ' . $coord[1]; // lon lat
        }
        // Ensure closed
        if ($points[0] !== end($points)) {
            $points[] = $points[0];
        }
        return 'POLYGON((' . implode(', ', $points) . '))';
    }
    
    if ($type === 'MultiPolygon' && !empty($coords)) {
        // Take first polygon only for simplicity
        $ring = $coords[0][0];
        $points = [];
        foreach ($ring as $coord) {
            $points[] = $coord[0] . ' ' . $coord[1];
        }
        if ($points[0] !== end($points)) {
            $points[] = $points[0];
        }
        return 'POLYGON((' . implode(', ', $points) . '))';
    }
    
    return null;
}

/**
 * Convert AWC coords array to WKT
 * Format: [{lat: 35.0, lon: -85.0}, ...]
 */
function coordsToWkt($coords) {
    if (count($coords) < 3) {
        return null;
    }
    
    $points = [];
    foreach ($coords as $coord) {
        $lat = $coord['lat'] ?? $coord['latitude'] ?? $coord[1] ?? null;
        $lon = $coord['lon'] ?? $coord['longitude'] ?? $coord[0] ?? null;
        
        // AWC uses positive west longitude sometimes
        if ($lon > 0 && $lon > 30) {
            $lon = -$lon; // Convert to negative for western hemisphere
        }
        
        if ($lat !== null && $lon !== null) {
            $points[] = "$lon $lat";
        }
    }
    
    if (count($points) < 3) {
        return null;
    }
    
    // Ensure closed polygon
    if ($points[0] !== end($points)) {
        $points[] = $points[0];
    }
    
    return 'POLYGON((' . implode(', ', $points) . '))';
}

/**
 * Calculate centroid from GeoJSON coordinates
 */
function calculateCentroid($coords) {
    // Handle nested arrays
    while (is_array($coords[0]) && is_array($coords[0][0])) {
        $coords = $coords[0];
    }
    
    $latSum = 0;
    $lonSum = 0;
    $count = 0;
    
    foreach ($coords as $coord) {
        if (is_array($coord) && count($coord) >= 2) {
            $lonSum += $coord[0];
            $latSum += $coord[1];
            $count++;
        }
    }
    
    return $count > 0 ? [
        'lat' => round($latSum / $count, 7),
        'lon' => round($lonSum / $count, 7)
    ] : ['lat' => null, 'lon' => null];
}

/**
 * Calculate centroid from AWC coords array
 */
function calculateCentroidFromCoords($coords) {
    $latSum = 0;
    $lonSum = 0;
    $count = 0;
    
    foreach ($coords as $coord) {
        $lat = $coord['lat'] ?? $coord['latitude'] ?? null;
        $lon = $coord['lon'] ?? $coord['longitude'] ?? null;
        
        if ($lat !== null && $lon !== null) {
            if ($lon > 0 && $lon > 30) $lon = -$lon;
            $latSum += $lat;
            $lonSum += $lon;
            $count++;
        }
    }
    
    return $count > 0 ? [
        'lat' => round($latSum / $count, 7),
        'lon' => round($lonSum / $count, 7)
    ] : ['lat' => null, 'lon' => null];
}

/**
 * Parse time string to ISO format
 */
function parseTime($timeStr) {
    if (!$timeStr) return null;
    
    // Try various formats
    $timestamp = strtotime($timeStr);
    if ($timestamp) {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
    
    return null;
}

/**
 * Parse altitude to flight level (100s of feet)
 */
function parseAltitude($alt) {
    if ($alt === null || $alt === '') return null;
    
    $alt = strtoupper(trim($alt));
    
    // Already in flight levels
    if (is_numeric($alt) && $alt <= 600) {
        return intval($alt);
    }
    
    // FL prefix
    if (preg_match('/^FL?(\d+)$/i', $alt, $m)) {
        return intval($m[1]);
    }
    
    // Feet - convert to FL
    if (is_numeric($alt) && $alt > 600) {
        return intval($alt / 100);
    }
    
    // SFC = surface
    if ($alt === 'SFC' || $alt === 'SURFACE') {
        return 0;
    }
    
    return null;
}

/**
 * Generate source ID if not provided
 */
function generateSourceId($type, $hazard) {
    $prefix = substr($type, 0, 3);
    $suffix = substr($hazard, 0, 4);
    return strtoupper("{$prefix}_{$suffix}_" . time());
}

/**
 * Summarize alerts by type
 */
function summarizeAlerts($alerts) {
    $summary = [];
    foreach ($alerts as $a) {
        $key = "{$a['alert_type']}/{$a['hazard']}";
        $summary[$key] = ($summary[$key] ?? 0) + 1;
    }
    
    echo "Alert Summary:\n";
    foreach ($summary as $type => $count) {
        echo "  $type: $count\n";
    }
    echo "\n";
}

/**
 * Import alerts to database
 */
function importAlerts($alerts, $verbose = false) {
    // Convert to JSON for SQL procedure
    $json = json_encode($alerts);
    
    if ($verbose) {
        echo "JSON payload: " . strlen($json) . " bytes\n";
    }
    
    // Connect to database
    $connInfo = [
        "Database" => DB_NAME,
        "Authentication" => "ActiveDirectoryMsi",
        "TrustServerCertificate" => true
    ];
    
    // Try MSI auth first (Azure), fall back to env vars
    $conn = @sqlsrv_connect(DB_SERVER, $connInfo);
    
    if (!$conn) {
        // Try with username/password from environment
        $user = getenv('SQL_USER');
        $pass = getenv('SQL_PASS');
        
        if ($user && $pass) {
            $connInfo = [
                "Database" => DB_NAME,
                "UID" => $user,
                "PWD" => $pass,
                "TrustServerCertificate" => true
            ];
            $conn = sqlsrv_connect(DB_SERVER, $connInfo);
        }
    }
    
    if (!$conn) {
        $errors = sqlsrv_errors();
        throw new Exception("Database connection failed: " . print_r($errors, true));
    }
    
    if ($verbose) {
        echo "Connected to database\n";
    }
    
    // Call import procedure
    $sql = "EXEC dbo.sp_ImportWeatherAlerts @json = ?, @source_url = ?";
    $params = [$json, AWC_BASE_URL];
    
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if (!$stmt) {
        $errors = sqlsrv_errors();
        throw new Exception("Import failed: " . print_r($errors, true));
    }
    
    // Get results
    $result = [
        'inserted' => 0,
        'updated' => 0,
        'expired' => 0
    ];
    
    if ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $result['inserted'] = $row['inserted'] ?? 0;
        $result['updated'] = $row['updated'] ?? 0;
        $result['expired'] = $row['expired'] ?? 0;
    }
    
    sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);
    
    return $result;
}
