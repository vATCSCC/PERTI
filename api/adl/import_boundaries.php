<?php
/**
 * Phase 5E.1: Boundary Import - Web Trigger (v3 with Antimeridian Support)
 * /api/adl/import_boundaries.php
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

echo "=== Boundary Import (v3 with Antimeridian Support) ===\n";
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
 * Check if a ring crosses the antimeridian (180° longitude)
 * Returns true if there's a longitude jump > 180° between consecutive points
 */
function ringCrossesAntimeridian($ring) {
    for ($i = 0; $i < count($ring) - 1; $i++) {
        $lon1 = $ring[$i][0];
        $lon2 = $ring[$i + 1][0];
        $diff = abs($lon2 - $lon1);
        if ($diff > 180) {
            return true;
        }
    }
    return false;
}

/**
 * Interpolate latitude at the antimeridian crossing
 */
function interpolateLatAtAntimeridian($lon1, $lat1, $lon2, $lat2) {
    // Normalize longitudes for the crossing calculation
    if ($lon1 > 0 && $lon2 < 0) {
        // Crossing from east to west (e.g., 170 to -170)
        $lon2_norm = $lon2 + 360; // -170 -> 190
        $target = 180;
    } else {
        // Crossing from west to east (e.g., -170 to 170)
        $lon1_norm = $lon1 + 360; // -170 -> 190
        $lon1 = $lon1_norm;
        $target = 180;
    }
    
    // Linear interpolation
    if (abs($lon2 - $lon1) < 0.0001) {
        return ($lat1 + $lat2) / 2;
    }
    
    // For east-to-west crossing
    if ($lon1 > 0 && $lon2 < 0) {
        $t = (180 - $lon1) / ((360 + $lon2) - $lon1);
    } else {
        // west-to-east
        $t = (180 - ($lon1 > 0 ? $lon1 : $lon1 + 360)) / (($lon2 > 0 ? $lon2 : $lon2 + 360) - ($lon1 > 0 ? $lon1 : $lon1 + 360));
    }
    
    return $lat1 + $t * ($lat2 - $lat1);
}

/**
 * Split a ring at the antimeridian into two rings (east and west)
 */
function splitRingAtAntimeridian($ring) {
    $eastRing = [];  // Points with positive longitude (or shifted to positive)
    $westRing = [];  // Points with negative longitude (or shifted to negative)
    
    $n = count($ring);
    
    for ($i = 0; $i < $n; $i++) {
        $curr = $ring[$i];
        $next = $ring[($i + 1) % $n];
        
        $currLon = $curr[0];
        $currLat = $curr[1];
        $nextLon = $next[0];
        $nextLat = $next[1];
        
        // Determine which side current point is on
        $currIsEast = $currLon >= 0;
        
        // Add current point to appropriate ring
        if ($currIsEast) {
            $eastRing[] = $curr;
        } else {
            $westRing[] = $curr;
        }
        
        // Check if we're crossing the antimeridian to next point
        $lonDiff = abs($nextLon - $currLon);
        if ($lonDiff > 180 && $i < $n - 1) {  // Skip last-to-first (handled by closure)
            // We're crossing - interpolate the crossing point
            $crossLat = interpolateLatAtAntimeridian($currLon, $currLat, $nextLon, $nextLat);
            
            // Add crossing points to both rings
            if ($currIsEast) {
                // Going from east to west
                $eastRing[] = [180.0, $crossLat];
                $westRing[] = [-180.0, $crossLat];
            } else {
                // Going from west to east
                $westRing[] = [-180.0, $crossLat];
                $eastRing[] = [180.0, $crossLat];
            }
        }
    }
    
    return [$eastRing, $westRing];
}

/**
 * Ensure a ring is closed (first point = last point)
 */
function closeRing($ring) {
    if (count($ring) < 3) return $ring;
    
    $first = $ring[0];
    $last = $ring[count($ring) - 1];
    
    if ($first[0] !== $last[0] || $first[1] !== $last[1]) {
        $ring[] = $first;
    }
    
    return $ring;
}

/**
 * Process geometry to handle antimeridian crossings
 * Returns modified geometry (may convert Polygon to MultiPolygon)
 */
function handleAntimeridian($geometry) {
    $type = $geometry['type'];
    $coords = $geometry['coordinates'];
    
    if ($type === 'Polygon') {
        // Check if any ring crosses the antimeridian
        $crosses = false;
        foreach ($coords as $ring) {
            if (ringCrossesAntimeridian($ring)) {
                $crosses = true;
                break;
            }
        }
        
        if (!$crosses) {
            return ['geometry' => $geometry, 'split' => false];
        }
        
        // Split the polygon - for simplicity, we'll handle the exterior ring
        // and assume holes don't cross (or we ignore holes for split polygons)
        $exteriorRing = $coords[0];
        list($eastRing, $westRing) = splitRingAtAntimeridian($exteriorRing);
        
        // Close the rings
        $eastRing = closeRing($eastRing);
        $westRing = closeRing($westRing);
        
        // Build MultiPolygon from valid rings
        $polygons = [];
        if (count($eastRing) >= 4) {  // Need at least 4 points for a valid ring (3 + closure)
            $polygons[] = [$eastRing];
        }
        if (count($westRing) >= 4) {
            $polygons[] = [$westRing];
        }
        
        if (count($polygons) === 0) {
            // Fallback - return original
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
        // Process each polygon in the MultiPolygon
        $newPolygons = [];
        $anySplit = false;
        
        foreach ($coords as $polygon) {
            $singleGeom = ['type' => 'Polygon', 'coordinates' => $polygon];
            $result = handleAntimeridian($singleGeom);
            
            if ($result['split']) {
                $anySplit = true;
            }
            
            if ($result['geometry']['type'] === 'Polygon') {
                $newPolygons[] = $result['geometry']['coordinates'];
            } else {
                // It was split into MultiPolygon - add each part
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
 * Ensures rings are closed (first point = last point)
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
    global $stats;
    
    $wktLength = 0;
    $pointCount = 0;
    $geomType = $data['geometry']['type'] ?? 'Unknown';
    
    try {
        // Handle antimeridian crossings
        $amResult = handleAntimeridian($data['geometry']);
        $geometry = $amResult['geometry'];
        if ($amResult['split']) {
            $stats[$category]['antimeridian']++;
            $geomType .= ' (split)';
        }
        
        $pointCount = countPoints($geometry);
        $wkt = geojsonToWkt($geometry);
        $wktLength = strlen($wkt);
        
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
            
            // Log failure
            logImportResult($conn, $runId, $data, 'FAILED', $errorMsg, $errorCode, $wktLength, $geomType, $pointCount);
            
            // Track in stats
            $stats[$category]['failures'][] = [
                'code' => $data['boundary_code'],
                'error_code' => $errorCode,
                'error' => substr($errorMsg, 0, 100),
                'points' => $pointCount,
                'wkt_len' => $wktLength
            ];
            
            return false;
        }
        
        // Fetch the result - SP now always returns SELECT at the end
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        
        $success = $row && isset($row['boundary_id']) && $row['boundary_id'] > 0;
        
        if ($success) {
            logImportResult($conn, $runId, $data, 'SUCCESS', null, null, $wktLength, $geomType, $pointCount);
        } else {
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
