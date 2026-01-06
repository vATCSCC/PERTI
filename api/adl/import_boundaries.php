<?php
/**
 * Phase 5E.1: Boundary Import - Web Trigger (v4 with Improved Antimeridian Support)
 * /api/adl/import_boundaries.php
 * 
 * Fixes: Better detection of antimeridian-crossing polygons using bounding box analysis
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Simple security
$secretKey = 'perti_boundary_import_2025';

if (($_GET['key'] ?? '') !== $secretKey) {
    http_response_code(403);
    die('Unauthorized');
}

set_time_limit(600);
ini_set('memory_limit', '512M');

header('Content-Type: text/plain');

// Load config
require_once(__DIR__ . "/../../load/config.php");

if (!defined("ADL_SQL_HOST") || !defined("ADL_SQL_DATABASE") ||
    !defined("ADL_SQL_USERNAME") || !defined("ADL_SQL_PASSWORD")) {
    die("ERROR: ADL_SQL_* constants are not defined in config.php\n");
}

// Connect to database
$connInfo = [
    "Database" => ADL_SQL_DATABASE,
    "UID" => ADL_SQL_USERNAME,
    "PWD" => ADL_SQL_PASSWORD,
    "CharacterSet" => "UTF-8",
    "TrustServerCertificate" => true,
    "LoginTimeout" => 30,
    "ConnectionPooling" => true
];

$conn = sqlsrv_connect(ADL_SQL_HOST, $connInfo);
if ($conn === false) {
    die("ERROR: Database connection failed - " . print_r(sqlsrv_errors(), true) . "\n");
}

echo "=== Boundary Import (v4 with Improved Antimeridian Support) ===\n";
echo "Connected to database.\n\n";

// Generate unique run ID for this import batch
$runId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0x0fff) | 0x4000,
    mt_rand(0, 0x3fff) | 0x8000,
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
);
echo "Import Run ID: $runId\n\n";

$geojsonDir = __DIR__ . '/../../assets/geojson/';

// Debug mode for specific boundaries
$debugBoundaries = ['KZAK', 'NZZO', 'PAZA', 'NFFF', 'NZCM', 'UBBA', 'UHMM'];

// Stats with failure details
$stats = [
    'artcc' => ['imported' => 0, 'failed' => 0, 'failures' => [], 'antimeridian' => 0],
    'sectors' => ['imported' => 0, 'failed' => 0, 'failures' => [], 'antimeridian' => 0],
    'tracon' => ['imported' => 0, 'failed' => 0, 'failures' => [], 'antimeridian' => 0]
];

// Check if log table exists
$logTableExists = false;
$checkSql = "SELECT 1 FROM sys.tables WHERE name = 'adl_boundary_import_log'";
$checkStmt = sqlsrv_query($conn, $checkSql);
if ($checkStmt && sqlsrv_fetch_array($checkStmt)) {
    $logTableExists = true;
    echo "Failure logging enabled (adl_boundary_import_log table found)\n\n";
}
sqlsrv_free_stmt($checkStmt);

/**
 * Log import result to database
 */
function logImportResult($conn, $runId, $data, $status, $errorMsg = null, $errorCode = null, $wktLength = 0, $geomType = null, $pointCount = 0) {
    global $logTableExists;
    if (!$logTableExists) return;
    
    $sql = "INSERT INTO adl_boundary_import_log 
            (import_run_id, boundary_type, boundary_code, boundary_name, source_file, status, error_message, error_code, wkt_length, geometry_type, point_count)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $params = [
        $runId,
        $data['boundary_type'],
        $data['boundary_code'],
        $data['boundary_name'] ?? null,
        $data['source_file'] ?? null,
        $status,
        $errorMsg,
        $errorCode,
        $wktLength,
        $geomType,
        $pointCount
    ];
    
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt) sqlsrv_free_stmt($stmt);
}

/**
 * Count points in geometry
 */
function countPoints($geometry) {
    $count = 0;
    $type = $geometry['type'];
    $coords = $geometry['coordinates'];
    
    if ($type === 'Polygon') {
        foreach ($coords as $ring) {
            $count += count($ring);
        }
    } elseif ($type === 'MultiPolygon') {
        foreach ($coords as $polygon) {
            foreach ($polygon as $ring) {
                $count += count($ring);
            }
        }
    }
    return $count;
}

/**
 * Get bounding box of a ring
 */
function getRingBounds($ring) {
    $minLon = PHP_FLOAT_MAX;
    $maxLon = -PHP_FLOAT_MAX;
    $minLat = PHP_FLOAT_MAX;
    $maxLat = -PHP_FLOAT_MAX;
    
    foreach ($ring as $coord) {
        $lon = $coord[0];
        $lat = $coord[1];
        $minLon = min($minLon, $lon);
        $maxLon = max($maxLon, $lon);
        $minLat = min($minLat, $lat);
        $maxLat = max($maxLat, $lat);
    }
    
    return [
        'minLon' => $minLon,
        'maxLon' => $maxLon,
        'minLat' => $minLat,
        'maxLat' => $maxLat,
        'lonSpan' => $maxLon - $minLon,
        'latSpan' => $maxLat - $minLat
    ];
}

/**
 * Check if a ring crosses the antimeridian using multiple detection methods
 */
function ringCrossesAntimeridian($ring) {
    // Method 1: Check for large longitude jumps between consecutive points
    for ($i = 0; $i < count($ring) - 1; $i++) {
        $lon1 = $ring[$i][0];
        $lon2 = $ring[$i + 1][0];
        $diff = abs($lon2 - $lon1);
        if ($diff > 180) {
            return 'jump';
        }
    }
    
    // Method 2: Check if bounding box spans > 180 degrees
    $bounds = getRingBounds($ring);
    if ($bounds['lonSpan'] > 180) {
        return 'span';
    }
    
    // Method 3: Check for coordinates near both +180 and -180
    $hasNearPositive180 = false;
    $hasNearNegative180 = false;
    foreach ($ring as $coord) {
        $lon = $coord[0];
        if ($lon > 170) $hasNearPositive180 = true;
        if ($lon < -170) $hasNearNegative180 = true;
    }
    if ($hasNearPositive180 && $hasNearNegative180) {
        return 'proximity';
    }
    
    return false;
}

/**
 * Normalize longitude to -180 to 180 range
 */
function normalizeLon($lon) {
    while ($lon > 180) $lon -= 360;
    while ($lon < -180) $lon += 360;
    return $lon;
}

/**
 * Split a ring at the antimeridian into eastern and western parts
 */
function splitRingAtAntimeridian($ring, $crossType) {
    $eastPoints = [];  // Longitude > 0 (or shifted)
    $westPoints = [];  // Longitude < 0 (or shifted)
    
    $n = count($ring);
    if ($n < 3) return [[], []];
    
    // For "proximity" and "span" types, we need to determine which side is which
    // by looking at the distribution of points
    $positiveCount = 0;
    $negativeCount = 0;
    foreach ($ring as $coord) {
        if ($coord[0] >= 0) $positiveCount++;
        else $negativeCount++;
    }
    
    for ($i = 0; $i < $n; $i++) {
        $curr = $ring[$i];
        $next = $ring[($i + 1) % $n];
        
        $currLon = $curr[0];
        $currLat = $curr[1];
        $nextLon = $next[0];
        $nextLat = $next[1];
        
        // Assign current point to east or west
        if ($currLon >= 0) {
            $eastPoints[] = [$currLon, $currLat];
        } else {
            $westPoints[] = [$currLon, $currLat];
        }
        
        // Check for crossing (large jump)
        $lonDiff = abs($nextLon - $currLon);
        if ($lonDiff > 180) {
            // Interpolate crossing point
            // Determine direction of crossing
            if ($currLon > 0 && $nextLon < 0) {
                // East to West crossing (e.g., 179 to -179)
                $t = (180 - $currLon) / (360 - $lonDiff);
                $crossLat = $currLat + $t * ($nextLat - $currLat);
                $eastPoints[] = [180.0, $crossLat];
                $westPoints[] = [-180.0, $crossLat];
            } else {
                // West to East crossing (e.g., -179 to 179)
                $t = (-180 - $currLon) / (-360 + $lonDiff);
                $crossLat = $currLat + $t * ($nextLat - $currLat);
                $westPoints[] = [-180.0, $crossLat];
                $eastPoints[] = [180.0, $crossLat];
            }
        }
    }
    
    return [$eastPoints, $westPoints];
}

/**
 * Ensure a ring is closed and valid
 */
function closeAndValidateRing($points) {
    if (count($points) < 3) return null;
    
    // Remove duplicates
    $cleaned = [];
    $lastPoint = null;
    foreach ($points as $pt) {
        if ($lastPoint === null || $pt[0] !== $lastPoint[0] || $pt[1] !== $lastPoint[1]) {
            $cleaned[] = $pt;
            $lastPoint = $pt;
        }
    }
    
    if (count($cleaned) < 3) return null;
    
    // Close the ring
    $first = $cleaned[0];
    $last = $cleaned[count($cleaned) - 1];
    if ($first[0] !== $last[0] || $first[1] !== $last[1]) {
        $cleaned[] = $first;
    }
    
    // Need at least 4 points for a valid ring (triangle + closure)
    if (count($cleaned) < 4) return null;
    
    return $cleaned;
}

/**
 * Process geometry to handle antimeridian crossings
 */
function handleAntimeridian($geometry, $boundaryCode = '') {
    global $debugBoundaries;
    $debug = in_array($boundaryCode, $debugBoundaries);
    
    $type = $geometry['type'];
    $coords = $geometry['coordinates'];
    
    if ($type === 'Polygon') {
        $exteriorRing = $coords[0];
        $crossType = ringCrossesAntimeridian($exteriorRing);
        
        if ($debug) {
            $bounds = getRingBounds($exteriorRing);
            echo "    DEBUG $boundaryCode: crossType=$crossType, lonSpan={$bounds['lonSpan']}, ";
            echo "bounds=[{$bounds['minLon']}, {$bounds['maxLon']}]\n";
        }
        
        if (!$crossType) {
            return ['geometry' => $geometry, 'split' => false];
        }
        
        if ($debug) {
            echo "    DEBUG $boundaryCode: Attempting split (type: $crossType)\n";
        }
        
        // Split the exterior ring
        list($eastPoints, $westPoints) = splitRingAtAntimeridian($exteriorRing, $crossType);
        
        $eastRing = closeAndValidateRing($eastPoints);
        $westRing = closeAndValidateRing($westPoints);
        
        if ($debug) {
            echo "    DEBUG $boundaryCode: eastRing=" . ($eastRing ? count($eastRing) : 'null') . " pts, ";
            echo "westRing=" . ($westRing ? count($westRing) : 'null') . " pts\n";
        }
        
        // Build result
        $polygons = [];
        if ($eastRing) $polygons[] = [$eastRing];
        if ($westRing) $polygons[] = [$westRing];
        
        if (count($polygons) === 0) {
            // Split failed, return original
            if ($debug) echo "    DEBUG $boundaryCode: Split failed, using original\n";
            return ['geometry' => $geometry, 'split' => false];
        } elseif (count($polygons) === 1) {
            return [
                'geometry' => ['type' => 'Polygon', 'coordinates' => $polygons[0]],
                'split' => true
            ];
        } else {
            return [
                'geometry' => ['type' => 'MultiPolygon', 'coordinates' => $polygons],
                'split' => true
            ];
        }
        
    } elseif ($type === 'MultiPolygon') {
        $newPolygons = [];
        $anySplit = false;
        
        foreach ($coords as $polygon) {
            $singleGeom = ['type' => 'Polygon', 'coordinates' => $polygon];
            $result = handleAntimeridian($singleGeom, $boundaryCode);
            
            if ($result['split']) $anySplit = true;
            
            if ($result['geometry']['type'] === 'Polygon') {
                $newPolygons[] = $result['geometry']['coordinates'];
            } else {
                foreach ($result['geometry']['coordinates'] as $part) {
                    $newPolygons[] = $part;
                }
            }
        }
        
        return [
            'geometry' => ['type' => 'MultiPolygon', 'coordinates' => $newPolygons],
            'split' => $anySplit
        ];
    }
    
    return ['geometry' => $geometry, 'split' => false];
}

/**
 * Convert GeoJSON geometry to WKT
 */
function geojsonToWkt($geometry) {
    $type = $geometry['type'];
    $coords = $geometry['coordinates'];
    
    if ($type === 'Polygon') {
        $rings = [];
        foreach ($coords as $ring) {
            $points = [];
            foreach ($ring as $coord) {
                $points[] = $coord[0] . ' ' . $coord[1];
            }
            // Ensure ring is closed
            if (count($ring) > 0) {
                $first = $ring[0][0] . ' ' . $ring[0][1];
                $last = $points[count($points) - 1];
                if ($first !== $last) {
                    $points[] = $first;
                }
            }
            $rings[] = '(' . implode(', ', $points) . ')';
        }
        return 'POLYGON (' . implode(', ', $rings) . ')';
    } elseif ($type === 'MultiPolygon') {
        $polygons = [];
        foreach ($coords as $polygon) {
            $rings = [];
            foreach ($polygon as $ring) {
                $points = [];
                foreach ($ring as $coord) {
                    $points[] = $coord[0] . ' ' . $coord[1];
                }
                if (count($ring) > 0) {
                    $first = $ring[0][0] . ' ' . $ring[0][1];
                    $last = $points[count($points) - 1];
                    if ($first !== $last) {
                        $points[] = $first;
                    }
                }
                $rings[] = '(' . implode(', ', $points) . ')';
            }
            $polygons[] = '(' . implode(', ', $rings) . ')';
        }
        return 'MULTIPOLYGON (' . implode(', ', $polygons) . ')';
    }
    
    throw new Exception("Unsupported geometry type: $type");
}

/**
 * Extract error code from SQL Server error
 */
function extractErrorCode($errors) {
    if (is_array($errors) && isset($errors[0]['code'])) {
        return 'SQLSTATE_' . $errors[0]['code'];
    }
    return 'UNKNOWN';
}

/**
 * Extract concise error message
 */
function extractErrorMessage($errors) {
    if (is_array($errors) && isset($errors[0]['message'])) {
        return substr($errors[0]['message'], 0, 500);
    }
    return 'Unknown error';
}

/**
 * Import a single boundary
 */
function importBoundary($conn, $data, $runId, $category) {
    global $stats, $debugBoundaries;
    
    $boundaryCode = $data['boundary_code'];
    $wktLength = 0;
    $pointCount = 0;
    $geomType = $data['geometry']['type'] ?? 'Unknown';
    $debug = in_array($boundaryCode, $debugBoundaries);
    
    try {
        // Handle antimeridian crossings
        $amResult = handleAntimeridian($data['geometry'], $boundaryCode);
        $geometry = $amResult['geometry'];
        if ($amResult['split']) {
            $stats[$category]['antimeridian']++;
            $geomType .= ' (split)';
            if ($debug) echo "    DEBUG $boundaryCode: Split successful\n";
        }
        
        $pointCount = countPoints($geometry);
        $wkt = geojsonToWkt($geometry);
        $wktLength = strlen($wkt);
        
        if ($debug) {
            echo "    DEBUG $boundaryCode: WKT length=$wktLength, points=$pointCount, type={$geometry['type']}\n";
        }
        
        $sql = "EXEC sp_ImportBoundary 
                @boundary_type = ?,
                @boundary_code = ?,
                @boundary_name = ?,
                @parent_artcc = ?,
                @sector_number = ?,
                @icao_code = ?,
                @vatsim_region = ?,
                @vatsim_division = ?,
                @vatsim_subdivision = ?,
                @is_oceanic = ?,
                @floor_altitude = ?,
                @ceiling_altitude = ?,
                @label_lat = ?,
                @label_lon = ?,
                @wkt_geometry = ?,
                @shape_length = ?,
                @shape_area = ?,
                @source_object_id = ?,
                @source_fid = ?,
                @source_file = ?;";
        
        $params = [
            $data['boundary_type'],
            $data['boundary_code'],
            $data['boundary_name'] ?? null,
            $data['parent_artcc'] ?? null,
            $data['sector_number'] ?? null,
            $data['icao_code'] ?? null,
            $data['vatsim_region'] ?? null,
            $data['vatsim_division'] ?? null,
            $data['vatsim_subdivision'] ?? null,
            $data['is_oceanic'] ?? 0,
            $data['floor_altitude'] ?? null,
            $data['ceiling_altitude'] ?? null,
            $data['label_lat'] ?? null,
            $data['label_lon'] ?? null,
            $wkt,
            $data['shape_length'] ?? null,
            $data['shape_area'] ?? null,
            $data['source_object_id'] ?? null,
            $data['source_fid'] ?? null,
            $data['source_file'] ?? null
        ];
        
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            $errorCode = extractErrorCode($errors);
            $errorMsg = extractErrorMessage($errors);
            
            if ($debug) echo "    DEBUG $boundaryCode: SQL Error - $errorMsg\n";
            
            logImportResult($conn, $runId, $data, 'FAILED', $errorMsg, $errorCode, $wktLength, $geomType, $pointCount);
            
            $stats[$category]['failures'][] = [
                'code' => $data['boundary_code'],
                'error_code' => $errorCode,
                'error' => substr($errorMsg, 0, 100),
                'points' => $pointCount,
                'wkt_len' => $wktLength
            ];
            
            return false;
        }
        
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        
        $success = $row && isset($row['boundary_id']) && $row['boundary_id'] > 0;
        
        if ($success) {
            if ($debug) echo "    DEBUG $boundaryCode: SUCCESS (id={$row['boundary_id']})\n";
            logImportResult($conn, $runId, $data, 'SUCCESS', null, null, $wktLength, $geomType, $pointCount);
        } else {
            if ($debug) echo "    DEBUG $boundaryCode: FAILED - no boundary_id returned\n";
            logImportResult($conn, $runId, $data, 'FAILED', 'SP returned no boundary_id', 'NO_ID', $wktLength, $geomType, $pointCount);
            $stats[$category]['failures'][] = [
                'code' => $data['boundary_code'],
                'error_code' => 'NO_ID',
                'error' => 'SP returned no boundary_id',
                'points' => $pointCount,
                'wkt_len' => $wktLength
            ];
        }
        
        return $success;
        
    } catch (Exception $e) {
        if ($debug) echo "    DEBUG $boundaryCode: EXCEPTION - {$e->getMessage()}\n";
        logImportResult($conn, $runId, $data, 'FAILED', $e->getMessage(), 'EXCEPTION', $wktLength, $geomType, $pointCount);
        $stats[$category]['failures'][] = [
            'code' => $data['boundary_code'],
            'error_code' => 'EXCEPTION',
            'error' => substr($e->getMessage(), 0, 100),
            'points' => $pointCount,
            'wkt_len' => $wktLength
        ];
        return false;
    }
}

/**
 * Import ARTCC boundaries
 */
function importArtcc($conn, $geojsonDir, &$stats, $runId) {
    $file = $geojsonDir . 'artcc.json';
    echo "Importing ARTCC boundaries from: $file\n";
    
    if (!file_exists($file)) {
        echo "  ERROR: File not found\n";
        return;
    }
    
    $geojson = json_decode(file_get_contents($file), true);
    if (!$geojson || !isset($geojson['features'])) {
        echo "  ERROR: Invalid GeoJSON\n";
        return;
    }
    
    $count = count($geojson['features']);
    echo "  Found $count ARTCC features\n";
    flush();
    
    foreach ($geojson['features'] as $i => $feature) {
        $props = $feature['properties'];
        $result = importBoundary($conn, [
            'boundary_type' => 'ARTCC',
            'boundary_code' => $props['ICAOCODE'] ?? $props['FIRname'],
            'boundary_name' => $props['FIRname'],
            'icao_code' => $props['ICAOCODE'] ?? null,
            'vatsim_region' => $props['VATSIM Reg'] ?? null,
            'vatsim_division' => $props['VATSIM Div'] ?? null,
            'vatsim_subdivision' => $props['VATSIM Sub'] ?? null,
            'is_oceanic' => ($props['oceanic'] ?? 0) ? 1 : 0,
            'floor_altitude' => $props['FLOOR'] ?? null,
            'ceiling_altitude' => $props['CEILING'] ?? null,
            'label_lat' => $props['label_lat'] ?? null,
            'label_lon' => $props['label_lon'] ?? null,
            'geometry' => $feature['geometry'],
            'source_fid' => $props['fid'] ?? null,
            'source_file' => 'artcc.json'
        ], $runId, 'artcc');
        
        if ($result) {
            $stats['artcc']['imported']++;
        } else {
            $stats['artcc']['failed']++;
        }
        
        if (($i + 1) % 50 == 0) {
            echo "  Processed " . ($i + 1) . "/$count\n";
            flush();
        }
    }
    
    echo "  ARTCC complete: {$stats['artcc']['imported']} imported, {$stats['artcc']['failed']} failed";
    if ($stats['artcc']['antimeridian'] > 0) {
        echo " ({$stats['artcc']['antimeridian']} antimeridian splits)";
    }
    echo "\n\n";
    flush();
}

/**
 * Import sector boundaries
 */
function importSectors($conn, $geojsonDir, $type, &$stats, $runId) {
    $file = $geojsonDir . $type . '.json';
    $boundaryType = 'SECTOR_' . strtoupper($type);
    
    echo "Importing $type sector boundaries from: $file\n";
    
    if (!file_exists($file)) {
        echo "  ERROR: File not found\n";
        return;
    }
    
    $geojson = json_decode(file_get_contents($file), true);
    if (!$geojson || !isset($geojson['features'])) {
        echo "  ERROR: Invalid GeoJSON\n";
        return;
    }
    
    $count = count($geojson['features']);
    echo "  Found $count $type sector features\n";
    flush();
    
    foreach ($geojson['features'] as $i => $feature) {
        $props = $feature['properties'];
        $result = importBoundary($conn, [
            'boundary_type' => $boundaryType,
            'boundary_code' => $props['label'] ?? ($props['artcc'] . $props['sector']),
            'boundary_name' => $props['label'] ?? null,
            'parent_artcc' => strtoupper($props['artcc'] ?? ''),
            'sector_number' => $props['sector'] ?? null,
            'geometry' => $feature['geometry'],
            'shape_length' => $props['Shape_Length'] ?? null,
            'shape_area' => $props['Shape_Area'] ?? null,
            'source_object_id' => $props['OBJECTID'] ?? null,
            'source_file' => $type . '.json'
        ], $runId, 'sectors');
        
        if ($result) {
            $stats['sectors']['imported']++;
        } else {
            $stats['sectors']['failed']++;
        }
        
        if (($i + 1) % 100 == 0) {
            echo "  Processed " . ($i + 1) . "/$count\n";
            flush();
        }
    }
    
    echo "  $type sector complete\n\n";
    flush();
}

/**
 * Import TRACON boundaries
 */
function importTracon($conn, $geojsonDir, &$stats, $runId) {
    $file = $geojsonDir . 'tracon.json';
    echo "Importing TRACON boundaries from: $file\n";
    
    if (!file_exists($file)) {
        echo "  ERROR: File not found\n";
        return;
    }
    
    $geojson = json_decode(file_get_contents($file), true);
    if (!$geojson || !isset($geojson['features'])) {
        echo "  ERROR: Invalid GeoJSON\n";
        return;
    }
    
    $count = count($geojson['features']);
    echo "  Found $count TRACON features\n";
    flush();
    
    foreach ($geojson['features'] as $i => $feature) {
        $props = $feature['properties'];
        $result = importBoundary($conn, [
            'boundary_type' => 'TRACON',
            'boundary_code' => $props['sector'] ?? $props['label'],
            'boundary_name' => $props['label'] ?? null,
            'parent_artcc' => strtoupper($props['artcc'] ?? ''),
            'sector_number' => $props['sector'] ?? null,
            'label_lat' => $props['label_lat'] ?? null,
            'label_lon' => $props['label_lon'] ?? null,
            'geometry' => $feature['geometry'],
            'shape_length' => $props['Shape_Length'] ?? null,
            'shape_area' => $props['Shape_Area'] ?? null,
            'source_object_id' => $props['OBJECTID'] ?? null,
            'source_file' => 'tracon.json'
        ], $runId, 'tracon');
        
        if ($result) {
            $stats['tracon']['imported']++;
        } else {
            $stats['tracon']['failed']++;
        }
        
        if (($i + 1) % 50 == 0) {
            echo "  Processed " . ($i + 1) . "/$count\n";
            flush();
        }
    }
    
    echo "  TRACON complete: {$stats['tracon']['imported']} imported, {$stats['tracon']['failed']} failed";
    if ($stats['tracon']['antimeridian'] > 0) {
        echo " ({$stats['tracon']['antimeridian']} antimeridian splits)";
    }
    echo "\n\n";
    flush();
}

// Clear existing data if requested
if (isset($_GET['clear']) && $_GET['clear'] === 'true') {
    echo "Clearing existing boundary data...\n";
    sqlsrv_query($conn, "TRUNCATE TABLE adl_boundary");
    echo "Cleared.\n\n";
}

// Run import based on type parameter
$type = $_GET['type'] ?? 'all';

switch ($type) {
    case 'artcc':
        importArtcc($conn, $geojsonDir, $stats, $runId);
        break;
    case 'high':
    case 'low':
    case 'superhigh':
        importSectors($conn, $geojsonDir, $type, $stats, $runId);
        break;
    case 'tracon':
        importTracon($conn, $geojsonDir, $stats, $runId);
        break;
    case 'all':
    default:
        importArtcc($conn, $geojsonDir, $stats, $runId);
        importSectors($conn, $geojsonDir, 'high', $stats, $runId);
        importSectors($conn, $geojsonDir, 'low', $stats, $runId);
        importSectors($conn, $geojsonDir, 'superhigh', $stats, $runId);
        importTracon($conn, $geojsonDir, $stats, $runId);
        break;
}

// Print summary
echo "=== Import Summary ===\n";
echo "ARTCC:   {$stats['artcc']['imported']} imported, {$stats['artcc']['failed']} failed";
if ($stats['artcc']['antimeridian'] > 0) echo " ({$stats['artcc']['antimeridian']} antimeridian)";
echo "\n";
echo "Sectors: {$stats['sectors']['imported']} imported, {$stats['sectors']['failed']} failed";
if ($stats['sectors']['antimeridian'] > 0) echo " ({$stats['sectors']['antimeridian']} antimeridian)";
echo "\n";
echo "TRACON:  {$stats['tracon']['imported']} imported, {$stats['tracon']['failed']} failed";
if ($stats['tracon']['antimeridian'] > 0) echo " ({$stats['tracon']['antimeridian']} antimeridian)";
echo "\n";

$totalImported = $stats['artcc']['imported'] + $stats['sectors']['imported'] + $stats['tracon']['imported'];
$totalFailed = $stats['artcc']['failed'] + $stats['sectors']['failed'] + $stats['tracon']['failed'];
$totalAntimeridian = $stats['artcc']['antimeridian'] + $stats['sectors']['antimeridian'] + $stats['tracon']['antimeridian'];
echo "Total:   $totalImported imported, $totalFailed failed";
if ($totalAntimeridian > 0) echo " ($totalAntimeridian antimeridian splits handled)";
echo "\n\n";

// Print failure details
if ($totalFailed > 0) {
    echo "=== Failure Details ===\n";
    
    $allFailures = array_merge(
        $stats['artcc']['failures'],
        $stats['sectors']['failures'],
        $stats['tracon']['failures']
    );
    
    // Group by error code
    $byErrorCode = [];
    foreach ($allFailures as $f) {
        $code = $f['error_code'];
        if (!isset($byErrorCode[$code])) {
            $byErrorCode[$code] = ['count' => 0, 'sample_error' => $f['error'], 'boundaries' => []];
        }
        $byErrorCode[$code]['count']++;
        if (count($byErrorCode[$code]['boundaries']) < 10) {
            $byErrorCode[$code]['boundaries'][] = $f['code'];
        }
    }
    
    echo "\nFailures by error type:\n";
    foreach ($byErrorCode as $code => $info) {
        echo "  $code ({$info['count']}): {$info['sample_error']}\n";
        echo "    Sample boundaries: " . implode(', ', $info['boundaries']) . "\n";
    }
    
    // Show stats on geometry characteristics of failures
    echo "\nFailure geometry stats:\n";
    $avgPoints = 0;
    $avgWkt = 0;
    $maxPoints = 0;
    $maxWkt = 0;
    foreach ($allFailures as $f) {
        $avgPoints += $f['points'];
        $avgWkt += $f['wkt_len'];
        $maxPoints = max($maxPoints, $f['points']);
        $maxWkt = max($maxWkt, $f['wkt_len']);
    }
    if (count($allFailures) > 0) {
        echo "  Avg points: " . round($avgPoints / count($allFailures)) . "\n";
        echo "  Max points: $maxPoints\n";
        echo "  Avg WKT length: " . round($avgWkt / count($allFailures)) . "\n";
        echo "  Max WKT length: $maxWkt\n";
    }
}

if ($logTableExists) {
    echo "\nDetailed logs saved to adl_boundary_import_log table.\n";
    echo "Query with: SELECT * FROM adl_boundary_import_log WHERE import_run_id = '$runId' AND status = 'FAILED'\n";
}

sqlsrv_close($conn);
echo "\nDone.\n";
