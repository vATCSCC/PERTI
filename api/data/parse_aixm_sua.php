<?php
/**
 * SUA AIXM Parser
 * 
 * Parses FAA NASR AIXM 5.0 Special Use Airspace XML files
 * and generates GeoJSON for TSD map display.
 * 
 * Usage:
 *   php parse_aixm_sua.php [input_dir] [output_file]
 *   
 * Example:
 *   php parse_aixm_sua.php /tmp/saa_data /var/www/html/api/data/sua_boundaries.json
 * 
 * Data Source:
 *   https://nfdc.faa.gov/webContent/28DaySub/YYYY-MM-DD/aixm5.0.zip
 */

// ============================================================================
// CONFIGURATION
// ============================================================================

// Default paths
$defaultInputDir = __DIR__ . '/saa_data';
$defaultOutputFile = __DIR__ . '/sua_boundaries.json';

// SUA type mappings
$SUA_TYPES = [
    'RA' => ['name' => 'Restricted', 'color' => '#ff0000', 'priority' => 1],
    'PA' => ['name' => 'Prohibited', 'color' => '#ff0000', 'priority' => 0],
    'WA' => ['name' => 'Warning', 'color' => '#ffff00', 'priority' => 2],
    'AA' => ['name' => 'Alert', 'color' => '#ff8800', 'priority' => 3],
    'MOA' => ['name' => 'Military Operations Area', 'color' => '#ff00ff', 'priority' => 4],
    'NSA' => ['name' => 'National Security Area', 'color' => '#0000ff', 'priority' => 5],
    'TRA' => ['name' => 'Temporary Reserved Airspace', 'color' => '#00ffff', 'priority' => 6],
    'ATCAA' => ['name' => 'ATC Assigned Airspace', 'color' => '#888888', 'priority' => 7],
];

// Schedule type mappings
$SCHEDULE_TYPES = [
    'H24' => 'Continuous (H24)',
    'NOTAM' => 'By NOTAM',
    'SR-SS' => 'Sunrise to Sunset',
    'SS-SR' => 'Sunset to Sunrise',
    'HJ' => 'Sunrise to Sunset',
    'HN' => 'Sunset to Sunrise',
];

// Circle approximation points
$CIRCLE_POINTS = 64;

// ============================================================================
// UTILITY FUNCTIONS
// ============================================================================

/**
 * Log message to console
 */
function logMsg($msg, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] [$level] $msg\n";
}

/**
 * Convert circle to polygon (approximate with N points)
 * @param float $centerLon Center longitude
 * @param float $centerLat Center latitude
 * @param float $radiusNm Radius in nautical miles
 * @param int $numPoints Number of points for approximation
 * @return array Array of [lon, lat] coordinates
 */
function circleToPolygon($centerLon, $centerLat, $radiusNm, $numPoints = 64) {
    $coords = [];
    $radiusKm = $radiusNm * 1.852; // NM to KM
    
    // Earth radius in km
    $earthRadiusKm = 6371;
    
    for ($i = 0; $i <= $numPoints; $i++) {
        $angle = (2 * M_PI * $i) / $numPoints;
        
        // Calculate point on circle
        $lat1 = deg2rad($centerLat);
        $lon1 = deg2rad($centerLon);
        $angularDist = $radiusKm / $earthRadiusKm;
        
        $lat2 = asin(
            sin($lat1) * cos($angularDist) +
            cos($lat1) * sin($angularDist) * cos($angle)
        );
        
        $lon2 = $lon1 + atan2(
            sin($angle) * sin($angularDist) * cos($lat1),
            cos($angularDist) - sin($lat1) * sin($lat2)
        );
        
        $coords[] = [rad2deg($lon2), rad2deg($lat2)];
    }
    
    return $coords;
}

/**
 * Parse altitude string to feet
 * @param string $alt Altitude string (e.g., "FL180", "12500", "GND")
 * @param string $uom Unit of measure
 * @return int|string Altitude in feet or 'GND'/'UNL'
 */
function parseAltitude($alt, $uom = 'FT') {
    $alt = strtoupper(trim($alt));
    
    if ($alt === 'GND' || $alt === 'SFC' || $alt === '0') {
        return 'GND';
    }
    if ($alt === 'UNL' || $alt === 'UNLIM' || $alt === 'UNLIMITED') {
        return 'UNL';
    }
    if (strpos($alt, 'FL') === 0) {
        return intval(substr($alt, 2)) * 100;
    }
    
    $numericAlt = intval(preg_replace('/[^0-9]/', '', $alt));
    
    // Convert based on UOM
    $uom = strtoupper($uom);
    if ($uom === 'M') {
        $numericAlt = round($numericAlt * 3.28084); // Meters to feet
    }
    
    return $numericAlt;
}

/**
 * Parse AIXM pos string to coordinates
 * @param string $posStr Position string (e.g., "-114.925556 33.258333")
 * @return array [lon, lat] or null
 */
function parsePos($posStr) {
    $parts = preg_split('/\s+/', trim($posStr));
    if (count($parts) >= 2) {
        return [floatval($parts[0]), floatval($parts[1])];
    }
    return null;
}

/**
 * Determine ARTCC from coordinates
 * @param float $lon Longitude
 * @param float $lat Latitude
 * @return string ARTCC code or 'UNK'
 */
function determineArtcc($lon, $lat) {
    // Simplified ARTCC determination based on rough boundaries
    // A full implementation would use actual ARTCC boundary polygons
    
    // Alaska
    if ($lat > 54 || ($lon < -130 && $lat > 48)) return 'ZAN';
    
    // Hawaii
    if ($lon < -150 && $lat < 30 && $lat > 18) return 'ZHN';
    
    // Simplified CONUS grid
    if ($lat > 42) {
        if ($lon > -75) return 'ZBW';
        if ($lon > -85) return 'ZOB';
        if ($lon > -95) return 'ZMP';
        if ($lon > -110) return 'ZLC';
        return 'ZSE';
    }
    if ($lat > 35) {
        if ($lon > -75) return 'ZDC';
        if ($lon > -82) return 'ZID';
        if ($lon > -90) return 'ZKC';
        if ($lon > -105) return 'ZDV';
        if ($lon > -115) return 'ZLA';
        return 'ZOA';
    }
    if ($lat > 28) {
        if ($lon > -80) return 'ZJX';
        if ($lon > -85) return 'ZTL';
        if ($lon > -95) return 'ZME';
        if ($lon > -100) return 'ZFW';
        if ($lon > -110) return 'ZAB';
        return 'ZLA';
    }
    if ($lon > -82) return 'ZMA';
    if ($lon > -98) return 'ZHU';
    return 'ZAB';
}

// ============================================================================
// AIXM PARSING
// ============================================================================

/**
 * Parse single AIXM XML file
 * @param string $filePath Path to XML file
 * @return array|null Parsed SUA data or null on failure
 */
function parseAixmFile($filePath) {
    global $SUA_TYPES, $SCHEDULE_TYPES, $CIRCLE_POINTS;
    
    // Load XML
    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($filePath);
    
    if ($xml === false) {
        logMsg("Failed to parse: $filePath", 'WARN');
        return null;
    }
    
    // Register namespaces
    $namespaces = $xml->getNamespaces(true);
    
    // Common AIXM 5.0 namespaces
    $ns = [
        'aixm' => 'http://www.aixm.aero/schema/5.0',
        'gml' => 'http://www.opengis.net/gml/3.2',
        'xlink' => 'http://www.w3.org/1999/xlink',
    ];
    
    foreach ($ns as $prefix => $uri) {
        if (!isset($namespaces[$prefix])) {
            $xml->registerXPathNamespace($prefix, $uri);
        }
    }
    
    $result = [];
    
    try {
        // Extract designator
        $designator = '';
        $designatorNodes = $xml->xpath('//aixm:designator') ?: $xml->xpath('//*[local-name()="designator"]');
        if (!empty($designatorNodes)) {
            $designator = (string)$designatorNodes[0];
        }
        
        if (empty($designator)) {
            return null; // Skip files without designator
        }
        
        // Extract name
        $name = '';
        $nameNodes = $xml->xpath('//aixm:name') ?: $xml->xpath('//*[local-name()="name"]');
        if (!empty($nameNodes)) {
            $name = (string)$nameNodes[0];
        }
        
        // Extract SUA type
        $suaType = '';
        $typeNodes = $xml->xpath('//aixm:type') ?: $xml->xpath('//*[local-name()="type"]');
        foreach ($typeNodes as $typeNode) {
            $type = strtoupper((string)$typeNode);
            if (isset($SUA_TYPES[$type])) {
                $suaType = $type;
                break;
            }
        }
        
        // Also check suaType element
        if (empty($suaType)) {
            $suaTypeNodes = $xml->xpath('//*[local-name()="suaType"]');
            if (!empty($suaTypeNodes)) {
                $suaType = strtoupper((string)$suaTypeNodes[0]);
            }
        }
        
        if (empty($suaType)) {
            // Try to infer from designator
            if (preg_match('/^R-?\d/', $designator)) $suaType = 'RA';
            elseif (preg_match('/^P-?\d/', $designator)) $suaType = 'PA';
            elseif (preg_match('/^W-?\d/', $designator)) $suaType = 'WA';
            elseif (preg_match('/^A-?\d/', $designator)) $suaType = 'AA';
            elseif (stripos($name, 'MOA') !== false) $suaType = 'MOA';
            else $suaType = 'MOA'; // Default
        }
        
        // Extract altitudes
        $upperLimit = 'UNL';
        $lowerLimit = 'GND';
        
        $upperNodes = $xml->xpath('//*[local-name()="upperLimit"]');
        if (!empty($upperNodes)) {
            $uom = (string)($upperNodes[0]['uom'] ?? 'FT');
            $upperLimit = parseAltitude((string)$upperNodes[0], $uom);
        }
        
        $lowerNodes = $xml->xpath('//*[local-name()="lowerLimit"]');
        if (!empty($lowerNodes)) {
            $uom = (string)($lowerNodes[0]['uom'] ?? 'FT');
            $lowerLimit = parseAltitude((string)$lowerNodes[0], $uom);
        }
        
        // Extract schedule/working hours
        $schedule = 'NOTAM';
        $scheduleNodes = $xml->xpath('//*[local-name()="workingHours"]') ?: 
                         $xml->xpath('//*[local-name()="timeInterval"]');
        if (!empty($scheduleNodes)) {
            $schedule = strtoupper(trim((string)$scheduleNodes[0]));
        }
        
        // Extract geometry
        $geometry = null;
        
        // Try CircleByCenterPoint first
        $circleNodes = $xml->xpath('//*[local-name()="CircleByCenterPoint"]');
        if (!empty($circleNodes)) {
            $circle = $circleNodes[0];
            
            // Get center position
            $posNodes = $circle->xpath('.//*[local-name()="pos"]');
            $radiusNodes = $circle->xpath('.//*[local-name()="radius"]');
            
            if (!empty($posNodes) && !empty($radiusNodes)) {
                $center = parsePos((string)$posNodes[0]);
                $radius = floatval((string)$radiusNodes[0]);
                $radiusUom = (string)($radiusNodes[0]['uom'] ?? 'NM');
                
                // Convert to NM if needed
                if (strtoupper($radiusUom) === 'KM') {
                    $radius = $radius / 1.852;
                } elseif (strtoupper($radiusUom) === 'M') {
                    $radius = $radius / 1852;
                }
                
                if ($center && $radius > 0) {
                    $polygonCoords = circleToPolygon($center[0], $center[1], $radius, $CIRCLE_POINTS);
                    $geometry = [
                        'type' => 'Polygon',
                        'coordinates' => [$polygonCoords]
                    ];
                }
            }
        }
        
        // Try LinearRing/Polygon
        if (!$geometry) {
            $posListNodes = $xml->xpath('//*[local-name()="posList"]');
            $posNodes = $xml->xpath('//*[local-name()="LinearRing"]//*[local-name()="pos"]');
            
            if (!empty($posListNodes)) {
                // Parse posList (space-separated coordinates)
                $posList = (string)$posListNodes[0];
                $values = preg_split('/\s+/', trim($posList));
                $coords = [];
                
                for ($i = 0; $i < count($values) - 1; $i += 2) {
                    $coords[] = [floatval($values[$i]), floatval($values[$i + 1])];
                }
                
                if (count($coords) >= 3) {
                    // Ensure closed polygon
                    if ($coords[0] !== $coords[count($coords) - 1]) {
                        $coords[] = $coords[0];
                    }
                    $geometry = [
                        'type' => 'Polygon',
                        'coordinates' => [$coords]
                    ];
                }
            } elseif (!empty($posNodes)) {
                // Parse individual pos elements
                $coords = [];
                foreach ($posNodes as $pos) {
                    $coord = parsePos((string)$pos);
                    if ($coord) {
                        $coords[] = $coord;
                    }
                }
                
                if (count($coords) >= 3) {
                    // Ensure closed polygon
                    if ($coords[0] !== $coords[count($coords) - 1]) {
                        $coords[] = $coords[0];
                    }
                    $geometry = [
                        'type' => 'Polygon',
                        'coordinates' => [$coords]
                    ];
                }
            }
        }
        
        if (!$geometry) {
            logMsg("No geometry found in: $filePath", 'WARN');
            return null;
        }
        
        // Calculate centroid for ARTCC determination
        $centroidLon = 0;
        $centroidLat = 0;
        $numPoints = count($geometry['coordinates'][0]) - 1;
        
        foreach (array_slice($geometry['coordinates'][0], 0, $numPoints) as $coord) {
            $centroidLon += $coord[0];
            $centroidLat += $coord[1];
        }
        $centroidLon /= $numPoints;
        $centroidLat /= $numPoints;
        
        $artcc = determineArtcc($centroidLon, $centroidLat);
        
        // Build result
        $result = [
            'designator' => $designator,
            'name' => $name ?: $designator,
            'type' => $suaType,
            'type_name' => $SUA_TYPES[$suaType]['name'] ?? $suaType,
            'upper_limit' => $upperLimit,
            'lower_limit' => $lowerLimit,
            'schedule' => $schedule,
            'schedule_desc' => $SCHEDULE_TYPES[$schedule] ?? $schedule,
            'artcc' => $artcc,
            'centroid' => [$centroidLon, $centroidLat],
            'geometry' => $geometry,
            'priority' => $SUA_TYPES[$suaType]['priority'] ?? 99,
            'color' => $SUA_TYPES[$suaType]['color'] ?? '#888888'
        ];
        
        return $result;
        
    } catch (Exception $e) {
        logMsg("Error parsing $filePath: " . $e->getMessage(), 'ERROR');
        return null;
    }
}

/**
 * Parse all AIXM files in directory
 * @param string $inputDir Input directory path
 * @return array Array of parsed SUA features
 */
function parseAllAixmFiles($inputDir) {
    $features = [];
    $fileCount = 0;
    $successCount = 0;
    
    // Find all XML files
    $files = glob("$inputDir/*.xml");
    $totalFiles = count($files);
    
    logMsg("Found $totalFiles XML files to process");
    
    foreach ($files as $file) {
        $fileCount++;
        
        // Progress update every 100 files
        if ($fileCount % 100 === 0) {
            logMsg("Processing file $fileCount / $totalFiles...");
        }
        
        $sua = parseAixmFile($file);
        
        if ($sua) {
            // Create GeoJSON feature
            $feature = [
                'type' => 'Feature',
                'properties' => [
                    'id' => $sua['designator'],
                    'designator' => $sua['designator'],
                    'name' => $sua['name'],
                    'sua_type' => $sua['type'],
                    'type_name' => $sua['type_name'],
                    'upper_limit' => $sua['upper_limit'],
                    'lower_limit' => $sua['lower_limit'],
                    'schedule' => $sua['schedule'],
                    'schedule_desc' => $sua['schedule_desc'],
                    'artcc' => $sua['artcc'],
                    'priority' => $sua['priority'],
                    'color' => $sua['color']
                ],
                'geometry' => $sua['geometry']
            ];
            
            $features[] = $feature;
            $successCount++;
        }
    }
    
    logMsg("Successfully parsed $successCount / $totalFiles files");
    
    // Sort by priority (lower = more important = render on top)
    usort($features, function($a, $b) {
        return $a['properties']['priority'] - $b['properties']['priority'];
    });
    
    return $features;
}

// ============================================================================
// MAIN EXECUTION
// ============================================================================

// Get command line arguments or use defaults
$inputDir = $argv[1] ?? $defaultInputDir;
$outputFile = $argv[2] ?? $defaultOutputFile;

logMsg("SUA AIXM Parser Starting");
logMsg("Input directory: $inputDir");
logMsg("Output file: $outputFile");

// Validate input directory
if (!is_dir($inputDir)) {
    logMsg("Input directory does not exist: $inputDir", 'ERROR');
    exit(1);
}

// Parse all files
$features = parseAllAixmFiles($inputDir);

if (empty($features)) {
    logMsg("No SUA features parsed!", 'ERROR');
    exit(1);
}

// Build GeoJSON FeatureCollection
$geoJson = [
    'type' => 'FeatureCollection',
    'name' => 'FAA Special Use Airspace',
    'generated' => gmdate('Y-m-d\TH:i:s\Z'),
    'source' => 'FAA NASR AIXM 5.0',
    'count' => count($features),
    'types' => [],
    'features' => $features
];

// Count by type
$typeCounts = [];
foreach ($features as $f) {
    $type = $f['properties']['sua_type'];
    $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
}
$geoJson['types'] = $typeCounts;

// Write output file
$outputDir = dirname($outputFile);
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

$jsonOptions = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
$result = file_put_contents($outputFile, json_encode($geoJson, $jsonOptions));

if ($result === false) {
    logMsg("Failed to write output file: $outputFile", 'ERROR');
    exit(1);
}

$fileSizeKb = round(filesize($outputFile) / 1024);
logMsg("Output written: $outputFile ({$fileSizeKb} KB)");
logMsg("Total SUA features: " . count($features));
logMsg("By type: " . json_encode($typeCounts));
logMsg("Done!");
