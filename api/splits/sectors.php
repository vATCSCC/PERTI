<?php
/**
 * Sector Boundary API
 * 
 * Returns sector GeoJSON boundaries for a given facility (ARTCC/FIR)
 * from the CRC extraction or alternative data sources.
 * 
 * GET Parameters:
 *   - facility: ARTCC/FIR code (e.g., ZOB, ZDC, CZEG)
 *   - filter: all|high|low|ultra (optional, default: all)
 *   - demo: 1 to force demo/sample data
 * 
 * Response: JSON with sectors array and bounds
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Cache-Control: max-age=3600'); // Cache for 1 hour

// Use config values
define('CRC_BASE_PATH', defined('SPLITS_CRC_BASE_PATH') ? SPLITS_CRC_BASE_PATH : '/mnt/data/CRC_extracted');
define('CRC_ARTCC_PATH', defined('SPLITS_CRC_ARTCC_PATH') ? SPLITS_CRC_ARTCC_PATH : dirname(__DIR__, 2) . '/data/ARTCCs');
define('FALLBACK_GEOJSON_PATH', defined('SPLITS_FALLBACK_PATH') ? SPLITS_FALLBACK_PATH : dirname(__DIR__, 2) . '/assets/geojson');

/**
 * Main entry point
 */
function main() {
    $facility = strtoupper($_GET['facility'] ?? '');
    $filter = strtolower($_GET['filter'] ?? 'all');
    $forceDemo = isset($_GET['demo']) && $_GET['demo'] === '1';
    
    if (!$facility) {
        jsonError('Missing facility parameter');
    }
    
    // Validate facility code
    if (!preg_match('/^[A-Z]{3,4}$/', $facility)) {
        jsonError('Invalid facility code');
    }
    
    $isDemo = false;
    $sectors = [];
    
    // Try to load sectors from various sources unless demo forced
    if (!$forceDemo) {
        $sectors = loadSectorsFromCRC($facility, $filter);
        
        if (empty($sectors)) {
            // Try fallback sources
            $sectors = loadSectorsFromFallback($facility, $filter);
        }
    }
    
    // If still no data and demo is enabled, use sample data
    if (empty($sectors) && (defined('SPLITS_ENABLE_DEMO') && SPLITS_ENABLE_DEMO || $forceDemo)) {
        require_once __DIR__ . '/sample.php';
        // sample.php will output and exit, but we can also call the function
        $sectors = generateSampleSectors($facility);
        $isDemo = true;
    }
    
    if (empty($sectors)) {
        jsonError("No sector data found for facility: $facility. Enable demo mode or provide CRC data.");
    }
    
    // Calculate bounds
    $bounds = calculateBounds($sectors);
    
    // Get facility center for initial zoom
    $center = defined('SPLITS_FACILITY_CENTERS') && isset(SPLITS_FACILITY_CENTERS[$facility]) 
        ? SPLITS_FACILITY_CENTERS[$facility] 
        : null;
    
    echo json_encode([
        'success' => true,
        'facility' => $facility,
        'filter' => $filter,
        'demo' => $isDemo,
        'sectors' => $sectors,
        'bounds' => $bounds,
        'center' => $center,
        'count' => count($sectors)
    ]);
}

/**
 * Load sectors from CRC extraction
 */
function loadSectorsFromCRC($facility, $filter) {
    $sectors = [];
    
    // Path to ARTCC JSON in CRC
    $artccJsonPath = CRC_BASE_PATH . "/ARTCCs/{$facility}.json";
    
    if (!file_exists($artccJsonPath)) {
        return [];
    }
    
    $artccData = json_decode(file_get_contents($artccJsonPath), true);
    if (!$artccData || !isset($artccData['videoMaps'])) {
        return [];
    }
    
    // Find sector-related videomaps
    $sectorMaps = [];
    foreach ($artccData['videoMaps'] as $map) {
        $name = strtoupper($map['name'] ?? '');
        $tags = array_map('strtoupper', $map['tags'] ?? []);
        $tagsStr = implode(' ', $tags);
        
        // Filter for sector boundaries
        $isSector = (
            strpos($name, 'SECTOR') !== false ||
            in_array('SECTOR', $tags) ||
            preg_match('/^' . $facility . '\d{2}$/', $name) || // e.g., ZOB11
            preg_match('/^' . $facility . '_\d{2}/', $name)    // e.g., ZOB_11
        );
        
        if (!$isSector) continue;
        
        // Apply high/low filter
        if ($filter === 'high' && strpos($tagsStr, 'HIGH') === false && strpos($name, 'HIGH') === false) continue;
        if ($filter === 'low' && strpos($tagsStr, 'LOW') === false && strpos($name, 'LOW') === false) continue;
        if ($filter === 'ultra' && strpos($tagsStr, 'ULTRA') === false) continue;
        
        $sectorMaps[] = $map;
    }
    
    // Load GeoJSON for each sector map
    foreach ($sectorMaps as $map) {
        $geojsonPath = CRC_BASE_PATH . '/VideoMaps/' . $facility . '/' . $map['id'] . '.geojson';
        
        if (!file_exists($geojsonPath)) continue;
        
        $geojson = json_decode(file_get_contents($geojsonPath), true);
        if (!$geojson) continue;
        
        // Extract polygon geometry
        $geometry = extractPolygonGeometry($geojson);
        if (!$geometry) continue;
        
        // Parse sector ID from name
        $sectorId = extractSectorId($map['name'], $facility);
        
        // Parse frequency if available
        $freq = extractFrequency($map['name'], $map['tags'] ?? []);
        
        $sectors[] = [
            'id' => $sectorId,
            'name' => $map['name'],
            'freq' => $freq,
            'tags' => $map['tags'] ?? [],
            'geometry' => $geometry,
            'centroid' => calculateCentroid($geometry)
        ];
    }
    
    return $sectors;
}

/**
 * Load sectors from fallback GeoJSON files
 */
function loadSectorsFromFallback($facility, $filter) {
    $sectors = [];
    
    // Try facility-specific file
    $filePath = FALLBACK_GEOJSON_PATH . "/{$facility}_sectors.json";
    if (!file_exists($filePath)) {
        // Try generic sectors file
        $filePath = FALLBACK_GEOJSON_PATH . "/sectors/{$facility}.json";
    }
    
    if (!file_exists($filePath)) {
        return [];
    }
    
    $data = json_decode(file_get_contents($filePath), true);
    if (!$data) return [];
    
    // Handle GeoJSON FeatureCollection format
    if (isset($data['type']) && $data['type'] === 'FeatureCollection') {
        foreach ($data['features'] as $feature) {
            $props = $feature['properties'] ?? [];
            
            // Apply filter
            $level = strtolower($props['level'] ?? $props['type'] ?? '');
            if ($filter === 'high' && strpos($level, 'high') === false) continue;
            if ($filter === 'low' && strpos($level, 'low') === false) continue;
            
            $sectorId = $props['sector_id'] ?? $props['id'] ?? $props['name'] ?? '';
            
            $sectors[] = [
                'id' => $sectorId,
                'name' => $props['name'] ?? $sectorId,
                'freq' => $props['frequency'] ?? $props['freq'] ?? '',
                'tags' => [],
                'geometry' => $feature['geometry'],
                'centroid' => calculateCentroid($feature['geometry'])
            ];
        }
    }
    
    return $sectors;
}

/**
 * Extract polygon geometry from GeoJSON
 * CRC GeoJSON may contain multiple feature types; we want polygons
 */
function extractPolygonGeometry($geojson) {
    if (!$geojson) return null;
    
    // Direct geometry
    if (isset($geojson['type'])) {
        if ($geojson['type'] === 'Polygon' || $geojson['type'] === 'MultiPolygon') {
            return $geojson;
        }
        
        if ($geojson['type'] === 'Feature' && isset($geojson['geometry'])) {
            $geom = $geojson['geometry'];
            if ($geom['type'] === 'Polygon' || $geom['type'] === 'MultiPolygon') {
                return $geom;
            }
        }
        
        if ($geojson['type'] === 'FeatureCollection' && !empty($geojson['features'])) {
            // Try to find polygon features and merge them
            $polygons = [];
            foreach ($geojson['features'] as $feature) {
                if (!isset($feature['geometry'])) continue;
                $geom = $feature['geometry'];
                
                if ($geom['type'] === 'Polygon') {
                    $polygons[] = $geom['coordinates'];
                } elseif ($geom['type'] === 'MultiPolygon') {
                    foreach ($geom['coordinates'] as $poly) {
                        $polygons[] = $poly;
                    }
                }
            }
            
            if (count($polygons) === 1) {
                return ['type' => 'Polygon', 'coordinates' => $polygons[0]];
            } elseif (count($polygons) > 1) {
                return ['type' => 'MultiPolygon', 'coordinates' => $polygons];
            }
            
            // Try LineStrings and create polygon from them
            $lineCoords = [];
            foreach ($geojson['features'] as $feature) {
                if (!isset($feature['geometry'])) continue;
                $geom = $feature['geometry'];
                
                if ($geom['type'] === 'LineString') {
                    $lineCoords = array_merge($lineCoords, $geom['coordinates']);
                } elseif ($geom['type'] === 'MultiLineString') {
                    foreach ($geom['coordinates'] as $line) {
                        $lineCoords = array_merge($lineCoords, $line);
                    }
                }
            }
            
            if (count($lineCoords) >= 3) {
                // Close the ring if needed
                $first = $lineCoords[0];
                $last = $lineCoords[count($lineCoords) - 1];
                if ($first[0] !== $last[0] || $first[1] !== $last[1]) {
                    $lineCoords[] = $first;
                }
                return ['type' => 'Polygon', 'coordinates' => [$lineCoords]];
            }
        }
    }
    
    return null;
}

/**
 * Extract sector ID from name
 */
function extractSectorId($name, $facility) {
    // Try to extract sector number/ID
    // Patterns: "ZOB HIGH SECTORS" -> just use name
    //           "ZOB11" or "ZOB_11" -> ZOB11
    //           "EHIGH_ #17 (SECTOR)" -> extract number
    
    if (preg_match('/^(' . $facility . ')[\s_]?(\d{2,3})/', strtoupper($name), $m)) {
        return $facility . $m[2];
    }
    
    if (preg_match('/#(\d+)/', $name, $m)) {
        return $facility . $m[1];
    }
    
    // Clean up name for ID
    $id = preg_replace('/[^A-Z0-9_]/', '', strtoupper($name));
    return substr($id, 0, 20);
}

/**
 * Extract frequency from name or tags
 */
function extractFrequency($name, $tags) {
    // Look for frequency pattern (e.g., 125.400, 132.7)
    $combined = $name . ' ' . implode(' ', $tags);
    
    if (preg_match('/\b(\d{3}\.\d{2,3})\b/', $combined, $m)) {
        return $m[1];
    }
    
    return '';
}

/**
 * Calculate centroid of geometry
 */
function calculateCentroid($geometry) {
    if (!$geometry || !isset($geometry['coordinates'])) {
        return null;
    }
    
    $coords = [];
    
    if ($geometry['type'] === 'Polygon') {
        $coords = $geometry['coordinates'][0]; // Outer ring
    } elseif ($geometry['type'] === 'MultiPolygon') {
        // Use largest polygon
        $maxArea = 0;
        foreach ($geometry['coordinates'] as $poly) {
            $area = approximateArea($poly[0]);
            if ($area > $maxArea) {
                $maxArea = $area;
                $coords = $poly[0];
            }
        }
    }
    
    if (empty($coords)) return null;
    
    // Simple centroid calculation
    $sumX = 0;
    $sumY = 0;
    $n = count($coords);
    
    foreach ($coords as $coord) {
        $sumX += $coord[0];
        $sumY += $coord[1];
    }
    
    return [$sumX / $n, $sumY / $n];
}

/**
 * Approximate polygon area (for finding largest)
 */
function approximateArea($coords) {
    $area = 0;
    $n = count($coords);
    
    for ($i = 0; $i < $n; $i++) {
        $j = ($i + 1) % $n;
        $area += $coords[$i][0] * $coords[$j][1];
        $area -= $coords[$j][0] * $coords[$i][1];
    }
    
    return abs($area / 2);
}

/**
 * Calculate bounding box for all sectors
 */
function calculateBounds($sectors) {
    if (empty($sectors)) return null;
    
    $minLng = 180;
    $maxLng = -180;
    $minLat = 90;
    $maxLat = -90;
    
    foreach ($sectors as $sector) {
        if (!isset($sector['geometry']['coordinates'])) continue;
        
        $coords = $sector['geometry']['coordinates'];
        
        // Handle Polygon
        if ($sector['geometry']['type'] === 'Polygon') {
            foreach ($coords[0] as $coord) {
                $minLng = min($minLng, $coord[0]);
                $maxLng = max($maxLng, $coord[0]);
                $minLat = min($minLat, $coord[1]);
                $maxLat = max($maxLat, $coord[1]);
            }
        }
        // Handle MultiPolygon
        elseif ($sector['geometry']['type'] === 'MultiPolygon') {
            foreach ($coords as $poly) {
                foreach ($poly[0] as $coord) {
                    $minLng = min($minLng, $coord[0]);
                    $maxLng = max($maxLng, $coord[0]);
                    $minLat = min($minLat, $coord[1]);
                    $maxLat = max($maxLat, $coord[1]);
                }
            }
        }
    }
    
    return [[$minLng, $minLat], [$maxLng, $maxLat]];
}

/**
 * Return JSON error
 */
function jsonError($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

// Run
main();
