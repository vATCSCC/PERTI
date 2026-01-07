<?php
/**
 * Phase 5E.1: Boundary Import - Web Trigger (v5 with Ring Normalization)
 * /api/adl/import_boundaries.php
 * 
 * Fixes: 
 * - Normalizes ±180 longitude to avoid SQL Server edge cases
 * - Ensures proper ring winding order (CCW for exterior, CW for holes)
 * - Removes duplicate consecutive points
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$secretKey = 'perti_boundary_import_2025';

if (($_GET['key'] ?? '') !== $secretKey) {
    http_response_code(403);
    die('Unauthorized');
}

set_time_limit(600);
ini_set('memory_limit', '512M');

header('Content-Type: text/plain');

require_once(__DIR__ . "/../../load/config.php");

if (!defined("ADL_SQL_HOST") || !defined("ADL_SQL_DATABASE") ||
    !defined("ADL_SQL_USERNAME") || !defined("ADL_SQL_PASSWORD")) {
    die("ERROR: ADL_SQL_* constants are not defined in config.php\n");
}

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

echo "=== Boundary Import (v5 with Ring Normalization) ===\n";
echo "Connected to database.\n\n";

$runId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0x0fff) | 0x4000,
    mt_rand(0, 0x3fff) | 0x8000,
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
);
echo "Import Run ID: $runId\n\n";

$geojsonDir = __DIR__ . '/../../assets/geojson/';

$debugBoundaries = ['GMAC-O', 'GMAC-OS', 'GMAC-W', 'GMAC-WS', 'GMMM-NE'];

$stats = [
    'artcc' => ['imported' => 0, 'failed' => 0, 'failures' => [], 'normalized' => 0],
    'sectors' => ['imported' => 0, 'failed' => 0, 'failures' => [], 'normalized' => 0],
    'tracon' => ['imported' => 0, 'failed' => 0, 'failures' => [], 'normalized' => 0]
];

$logTableExists = false;
$checkSql = "SELECT 1 FROM sys.tables WHERE name = 'adl_boundary_import_log'";
$checkStmt = sqlsrv_query($conn, $checkSql);
if ($checkStmt && sqlsrv_fetch_array($checkStmt)) {
    $logTableExists = true;
    echo "Failure logging enabled\n\n";
}
sqlsrv_free_stmt($checkStmt);

function logImportResult($conn, $runId, $data, $status, $errorMsg = null, $errorCode = null, $wktLength = 0, $geomType = null, $pointCount = 0) {
    global $logTableExists;
    if (!$logTableExists) return;
    
    $sql = "INSERT INTO adl_boundary_import_log 
            (import_run_id, boundary_type, boundary_code, boundary_name, source_file, status, error_message, error_code, wkt_length, geometry_type, point_count)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = sqlsrv_query($conn, $sql, [
        $runId, $data['boundary_type'], $data['boundary_code'], $data['boundary_name'] ?? null,
        $data['source_file'] ?? null, $status, $errorMsg, $errorCode, $wktLength, $geomType, $pointCount
    ]);
    if ($stmt) sqlsrv_free_stmt($stmt);
}

function countPoints($geometry) {
    $count = 0;
    $type = $geometry['type'];
    $coords = $geometry['coordinates'];
    
    if ($type === 'Polygon') {
        foreach ($coords as $ring) $count += count($ring);
    } elseif ($type === 'MultiPolygon') {
        foreach ($coords as $polygon) {
            foreach ($polygon as $ring) $count += count($ring);
        }
    }
    return $count;
}

/**
 * Normalize a coordinate - clamp longitude, handle ±180 edge
 */
function normalizeCoord($coord) {
    $lon = $coord[0];
    $lat = $coord[1];
    
    // Clamp latitude
    $lat = max(-89.999999, min(89.999999, $lat));
    
    // Handle longitude at exactly ±180 - shift slightly inward
    if ($lon >= 180) $lon = 179.999999;
    if ($lon <= -180) $lon = -179.999999;
    
    return [$lon, $lat];
}

/**
 * Remove duplicate consecutive points from a ring
 */
function removeDuplicates($ring) {
    if (count($ring) < 2) return $ring;
    
    $cleaned = [$ring[0]];
    for ($i = 1; $i < count($ring); $i++) {
        $prev = $cleaned[count($cleaned) - 1];
        $curr = $ring[$i];
        // Check if points are effectively the same (within ~1m)
        if (abs($curr[0] - $prev[0]) > 0.00001 || abs($curr[1] - $prev[1]) > 0.00001) {
            $cleaned[] = $curr;
        }
    }
    return $cleaned;
}

/**
 * Calculate signed area of a ring (positive = CCW, negative = CW)
 */
function ringSignedArea($ring) {
    $area = 0;
    $n = count($ring);
    for ($i = 0; $i < $n; $i++) {
        $j = ($i + 1) % $n;
        $area += $ring[$i][0] * $ring[$j][1];
        $area -= $ring[$j][0] * $ring[$i][1];
    }
    return $area / 2;
}

/**
 * Check if ring is counter-clockwise
 */
function isCounterClockwise($ring) {
    return ringSignedArea($ring) > 0;
}

/**
 * Ensure ring has correct winding order
 * For geography: exterior should be CCW, holes should be CW
 */
function ensureWindingOrder($ring, $isExterior = true) {
    $isCCW = isCounterClockwise($ring);
    
    // Exterior rings should be CCW, holes should be CW
    if ($isExterior && !$isCCW) {
        return array_reverse($ring);
    } elseif (!$isExterior && $isCCW) {
        return array_reverse($ring);
    }
    
    return $ring;
}

/**
 * Ensure ring is closed
 */
function closeRing($ring) {
    if (count($ring) < 3) return $ring;
    
    $first = $ring[0];
    $last = $ring[count($ring) - 1];
    
    if (abs($first[0] - $last[0]) > 0.000001 || abs($first[1] - $last[1]) > 0.000001) {
        $ring[] = $first;
    }
    
    return $ring;
}

/**
 * Normalize a complete ring: normalize coords, remove dupes, ensure winding, close
 */
function normalizeRing($ring, $isExterior = true) {
    // Step 1: Normalize each coordinate
    $normalized = array_map('normalizeCoord', $ring);
    
    // Step 2: Remove duplicate consecutive points
    $cleaned = removeDuplicates($normalized);
    
    // Need at least 3 unique points for a valid ring
    if (count($cleaned) < 3) return null;
    
    // Step 3: Ensure proper winding order
    $wound = ensureWindingOrder($cleaned, $isExterior);
    
    // Step 4: Ensure ring is closed
    $closed = closeRing($wound);
    
    // Need at least 4 points (3 + closure) for valid ring
    if (count($closed) < 4) return null;
    
    return $closed;
}

/**
 * Normalize entire geometry
 */
function normalizeGeometry($geometry, $boundaryCode = '') {
    global $debugBoundaries;
    $debug = in_array($boundaryCode, $debugBoundaries);
    
    $type = $geometry['type'];
    $coords = $geometry['coordinates'];
    $wasNormalized = false;
    
    if ($type === 'Polygon') {
        $newRings = [];
        foreach ($coords as $i => $ring) {
            $isExterior = ($i === 0);
            $normalized = normalizeRing($ring, $isExterior);
            if ($normalized !== null) {
                $newRings[] = $normalized;
                // Check if normalization changed anything
                if ($ring !== $normalized) $wasNormalized = true;
            }
        }
        
        if (count($newRings) === 0) {
            if ($debug) echo "    DEBUG $boundaryCode: Polygon normalization produced no valid rings\n";
            return ['geometry' => $geometry, 'normalized' => false, 'valid' => false];
        }
        
        return [
            'geometry' => ['type' => 'Polygon', 'coordinates' => $newRings],
            'normalized' => $wasNormalized,
            'valid' => true
        ];
        
    } elseif ($type === 'MultiPolygon') {
        $newPolygons = [];
        foreach ($coords as $polygon) {
            $newRings = [];
            foreach ($polygon as $i => $ring) {
                $isExterior = ($i === 0);
                $normalized = normalizeRing($ring, $isExterior);
                if ($normalized !== null) {
                    $newRings[] = $normalized;
                    if ($ring !== $normalized) $wasNormalized = true;
                }
            }
            // Only include polygon if it has at least an exterior ring
            if (count($newRings) > 0) {
                $newPolygons[] = $newRings;
            }
        }
        
        if (count($newPolygons) === 0) {
            if ($debug) echo "    DEBUG $boundaryCode: MultiPolygon normalization produced no valid polygons\n";
            return ['geometry' => $geometry, 'normalized' => false, 'valid' => false];
        }
        
        // If only one polygon remains, convert to Polygon
        if (count($newPolygons) === 1) {
            return [
                'geometry' => ['type' => 'Polygon', 'coordinates' => $newPolygons[0]],
                'normalized' => true,
                'valid' => true
            ];
        }
        
        return [
            'geometry' => ['type' => 'MultiPolygon', 'coordinates' => $newPolygons],
            'normalized' => $wasNormalized,
            'valid' => true
        ];
    }
    
    return ['geometry' => $geometry, 'normalized' => false, 'valid' => true];
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
                $points[] = round($coord[0], 6) . ' ' . round($coord[1], 6);
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
                    $points[] = round($coord[0], 6) . ' ' . round($coord[1], 6);
                }
                $rings[] = '(' . implode(', ', $points) . ')';
            }
            $polygons[] = '(' . implode(', ', $rings) . ')';
        }
        return 'MULTIPOLYGON (' . implode(', ', $polygons) . ')';
    }
    
    throw new Exception("Unsupported geometry type: $type");
}

function extractErrorCode($errors) {
    if (is_array($errors) && isset($errors[0]['code'])) {
        return 'SQLSTATE_' . $errors[0]['code'];
    }
    return 'UNKNOWN';
}

function extractErrorMessage($errors) {
    if (is_array($errors) && isset($errors[0]['message'])) {
        return substr($errors[0]['message'], 0, 500);
    }
    return 'Unknown error';
}

function importBoundary($conn, $data, $runId, $category) {
    global $stats, $debugBoundaries;
    
    $boundaryCode = $data['boundary_code'];
    $wktLength = 0;
    $pointCount = 0;
    $geomType = $data['geometry']['type'] ?? 'Unknown';
    $debug = in_array($boundaryCode, $debugBoundaries);
    
    try {
        // Normalize geometry
        $normResult = normalizeGeometry($data['geometry'], $boundaryCode);
        
        if (!$normResult['valid']) {
            if ($debug) echo "    DEBUG $boundaryCode: Invalid geometry after normalization\n";
            $stats[$category]['failures'][] = [
                'code' => $boundaryCode, 'error_code' => 'INVALID_GEOM',
                'error' => 'Geometry invalid after normalization', 'points' => 0, 'wkt_len' => 0
            ];
            logImportResult($conn, $runId, $data, 'FAILED', 'Geometry invalid after normalization', 'INVALID_GEOM', 0, $geomType, 0);
            return false;
        }
        
        $geometry = $normResult['geometry'];
        if ($normResult['normalized']) {
            $stats[$category]['normalized']++;
            $geomType .= ' (normalized)';
            if ($debug) echo "    DEBUG $boundaryCode: Geometry normalized\n";
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
            $data['boundary_type'], $data['boundary_code'], $data['boundary_name'] ?? null,
            $data['parent_artcc'] ?? null, $data['sector_number'] ?? null, $data['icao_code'] ?? null,
            $data['vatsim_region'] ?? null, $data['vatsim_division'] ?? null, $data['vatsim_subdivision'] ?? null,
            $data['is_oceanic'] ?? 0, $data['floor_altitude'] ?? null, $data['ceiling_altitude'] ?? null,
            $data['label_lat'] ?? null, $data['label_lon'] ?? null, $wkt,
            $data['shape_length'] ?? null, $data['shape_area'] ?? null,
            $data['source_object_id'] ?? null, $data['source_fid'] ?? null, $data['source_file'] ?? null
        ];
        
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            $errorCode = extractErrorCode($errors);
            $errorMsg = extractErrorMessage($errors);
            
            if ($debug) echo "    DEBUG $boundaryCode: SQL Error - $errorMsg\n";
            
            logImportResult($conn, $runId, $data, 'FAILED', $errorMsg, $errorCode, $wktLength, $geomType, $pointCount);
            $stats[$category]['failures'][] = [
                'code' => $boundaryCode, 'error_code' => $errorCode,
                'error' => substr($errorMsg, 0, 100), 'points' => $pointCount, 'wkt_len' => $wktLength
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
            if ($debug) echo "    DEBUG $boundaryCode: FAILED - no boundary_id\n";
            logImportResult($conn, $runId, $data, 'FAILED', 'SP returned no boundary_id', 'NO_ID', $wktLength, $geomType, $pointCount);
            $stats[$category]['failures'][] = [
                'code' => $boundaryCode, 'error_code' => 'NO_ID',
                'error' => 'SP returned no boundary_id', 'points' => $pointCount, 'wkt_len' => $wktLength
            ];
        }
        
        return $success;
        
    } catch (Exception $e) {
        if ($debug) echo "    DEBUG $boundaryCode: EXCEPTION - {$e->getMessage()}\n";
        logImportResult($conn, $runId, $data, 'FAILED', $e->getMessage(), 'EXCEPTION', $wktLength, $geomType, $pointCount);
        $stats[$category]['failures'][] = [
            'code' => $boundaryCode, 'error_code' => 'EXCEPTION',
            'error' => substr($e->getMessage(), 0, 100), 'points' => $pointCount, 'wkt_len' => $wktLength
        ];
        return false;
    }
}

function importArtcc($conn, $geojsonDir, &$stats, $runId) {
    $file = $geojsonDir . 'artcc.json';
    echo "Importing ARTCC boundaries from: $file\n";
    
    if (!file_exists($file)) { echo "  ERROR: File not found\n"; return; }
    
    $geojson = json_decode(file_get_contents($file), true);
    if (!$geojson || !isset($geojson['features'])) { echo "  ERROR: Invalid GeoJSON\n"; return; }
    
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
        
        if ($result) $stats['artcc']['imported']++;
        else $stats['artcc']['failed']++;
        
        if (($i + 1) % 50 == 0) { echo "  Processed " . ($i + 1) . "/$count\n"; flush(); }
    }
    
    echo "  ARTCC complete: {$stats['artcc']['imported']} imported, {$stats['artcc']['failed']} failed";
    if ($stats['artcc']['normalized'] > 0) echo " ({$stats['artcc']['normalized']} normalized)";
    echo "\n\n";
    flush();
}

function importSectors($conn, $geojsonDir, $type, &$stats, $runId) {
    $file = $geojsonDir . $type . '.json';
    $boundaryType = 'SECTOR_' . strtoupper($type);
    
    echo "Importing $type sector boundaries from: $file\n";
    
    if (!file_exists($file)) { echo "  ERROR: File not found\n"; return; }
    
    $geojson = json_decode(file_get_contents($file), true);
    if (!$geojson || !isset($geojson['features'])) { echo "  ERROR: Invalid GeoJSON\n"; return; }
    
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
        
        if ($result) $stats['sectors']['imported']++;
        else $stats['sectors']['failed']++;
        
        if (($i + 1) % 100 == 0) { echo "  Processed " . ($i + 1) . "/$count\n"; flush(); }
    }
    
    echo "  $type sector complete\n\n";
    flush();
}

function importTracon($conn, $geojsonDir, &$stats, $runId) {
    $file = $geojsonDir . 'tracon.json';
    echo "Importing TRACON boundaries from: $file\n";
    
    if (!file_exists($file)) { echo "  ERROR: File not found\n"; return; }
    
    $geojson = json_decode(file_get_contents($file), true);
    if (!$geojson || !isset($geojson['features'])) { echo "  ERROR: Invalid GeoJSON\n"; return; }
    
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
        
        if ($result) $stats['tracon']['imported']++;
        else $stats['tracon']['failed']++;
        
        if (($i + 1) % 50 == 0) { echo "  Processed " . ($i + 1) . "/$count\n"; flush(); }
    }
    
    echo "  TRACON complete: {$stats['tracon']['imported']} imported, {$stats['tracon']['failed']} failed";
    if ($stats['tracon']['normalized'] > 0) echo " ({$stats['tracon']['normalized']} normalized)";
    echo "\n\n";
    flush();
}

// Clear existing data if requested
if (isset($_GET['clear']) && $_GET['clear'] === 'true') {
    echo "Clearing existing boundary data...\n";
    sqlsrv_query($conn, "TRUNCATE TABLE adl_boundary");
    echo "Cleared.\n\n";
}

// Run import
$type = $_GET['type'] ?? 'all';

switch ($type) {
    case 'artcc': importArtcc($conn, $geojsonDir, $stats, $runId); break;
    case 'high': case 'low': case 'superhigh': importSectors($conn, $geojsonDir, $type, $stats, $runId); break;
    case 'tracon': importTracon($conn, $geojsonDir, $stats, $runId); break;
    case 'all': default:
        importArtcc($conn, $geojsonDir, $stats, $runId);
        importSectors($conn, $geojsonDir, 'high', $stats, $runId);
        importSectors($conn, $geojsonDir, 'low', $stats, $runId);
        importSectors($conn, $geojsonDir, 'superhigh', $stats, $runId);
        importTracon($conn, $geojsonDir, $stats, $runId);
        break;
}

// Print summary
echo "=== Import Summary ===\n";
$totalImported = $stats['artcc']['imported'] + $stats['sectors']['imported'] + $stats['tracon']['imported'];
$totalFailed = $stats['artcc']['failed'] + $stats['sectors']['failed'] + $stats['tracon']['failed'];
$totalNormalized = $stats['artcc']['normalized'] + $stats['sectors']['normalized'] + $stats['tracon']['normalized'];

echo "ARTCC:   {$stats['artcc']['imported']} imported, {$stats['artcc']['failed']} failed\n";
echo "Sectors: {$stats['sectors']['imported']} imported, {$stats['sectors']['failed']} failed\n";
echo "TRACON:  {$stats['tracon']['imported']} imported, {$stats['tracon']['failed']} failed\n";
echo "Total:   $totalImported imported, $totalFailed failed";
if ($totalNormalized > 0) echo " ($totalNormalized normalized)";
echo "\n\n";

if ($totalFailed > 0) {
    echo "=== Failure Details ===\n";
    $allFailures = array_merge($stats['artcc']['failures'], $stats['sectors']['failures'], $stats['tracon']['failures']);
    
    $byErrorCode = [];
    foreach ($allFailures as $f) {
        $code = $f['error_code'];
        if (!isset($byErrorCode[$code])) $byErrorCode[$code] = ['count' => 0, 'sample_error' => $f['error'], 'boundaries' => []];
        $byErrorCode[$code]['count']++;
        if (count($byErrorCode[$code]['boundaries']) < 10) $byErrorCode[$code]['boundaries'][] = $f['code'];
    }
    
    echo "\nFailures by error type:\n";
    foreach ($byErrorCode as $code => $info) {
        echo "  $code ({$info['count']}): {$info['sample_error']}\n";
        echo "    Sample boundaries: " . implode(', ', $info['boundaries']) . "\n";
    }
}

if ($logTableExists) {
    echo "\nLogs: SELECT * FROM adl_boundary_import_log WHERE import_run_id = '$runId' AND status = 'FAILED'\n";
}

sqlsrv_close($conn);
echo "\nDone.\n";
