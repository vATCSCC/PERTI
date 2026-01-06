<?php
/**
 * Phase 5E.1: Boundary Import - Web Trigger
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

echo "=== Boundary Import ===\n";
echo "Connected to database.\n\n";

$geojsonDir = __DIR__ . '/../../assets/geojson/';

// Stats
$stats = [
    'artcc' => ['imported' => 0, 'failed' => 0],
    'sectors' => ['imported' => 0, 'failed' => 0],
    'tracon' => ['imported' => 0, 'failed' => 0]
];

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
                $rings[] = '(' . implode(', ', $points) . ')';
            }
            $polygons[] = '(' . implode(', ', $rings) . ')';
        }
        return 'MULTIPOLYGON (' . implode(', ', $polygons) . ')';
    }
    
    throw new Exception("Unsupported geometry type: $type");
}

/**
 * Import a single boundary
 */
function importBoundary($conn, $data) {
    global $stats;
    
    try {
        $wkt = geojsonToWkt($data['geometry']);
        
        $sql = "DECLARE @boundary_id INT;
            EXEC sp_ImportBoundary 
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
                @source_file = ?,
                @boundary_id = @boundary_id OUTPUT;
            SELECT @boundary_id as boundary_id;";
        
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
            // Uncomment for debugging:
            // echo "    Error: " . print_r(sqlsrv_errors(), true) . "\n";
            return false;
        }
        
        // Get result
        sqlsrv_next_result($stmt);
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        
        return $row && $row['boundary_id'] > 0;
        
    } catch (Exception $e) {
        // echo "    Error: " . $e->getMessage() . "\n";
        return false;
    }
}

/**
 * Import ARTCC boundaries
 */
function importArtcc($conn, $geojsonDir, &$stats) {
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
        ]);
        
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
    
    echo "  ARTCC complete: {$stats['artcc']['imported']} imported, {$stats['artcc']['failed']} failed\n\n";
    flush();
}

/**
 * Import sector boundaries
 */
function importSectors($conn, $geojsonDir, $type, &$stats) {
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
        ]);
        
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
function importTracon($conn, $geojsonDir, &$stats) {
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
        ]);
        
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
    
    echo "  TRACON complete: {$stats['tracon']['imported']} imported, {$stats['tracon']['failed']} failed\n\n";
    flush();
}

// Run import based on type parameter
$type = $_GET['type'] ?? 'all';

switch ($type) {
    case 'artcc':
        importArtcc($conn, $geojsonDir, $stats);
        break;
    case 'high':
    case 'low':
    case 'superhigh':
        importSectors($conn, $geojsonDir, $type, $stats);
        break;
    case 'tracon':
        importTracon($conn, $geojsonDir, $stats);
        break;
    case 'all':
    default:
        importArtcc($conn, $geojsonDir, $stats);
        importSectors($conn, $geojsonDir, 'high', $stats);
        importSectors($conn, $geojsonDir, 'low', $stats);
        importSectors($conn, $geojsonDir, 'superhigh', $stats);
        importTracon($conn, $geojsonDir, $stats);
        break;
}

// Print summary
echo "=== Import Summary ===\n";
echo "ARTCC:   {$stats['artcc']['imported']} imported, {$stats['artcc']['failed']} failed\n";
echo "Sectors: {$stats['sectors']['imported']} imported, {$stats['sectors']['failed']} failed\n";
echo "TRACON:  {$stats['tracon']['imported']} imported, {$stats['tracon']['failed']} failed\n";

$total = $stats['artcc']['imported'] + $stats['sectors']['imported'] + $stats['tracon']['imported'];
echo "Total:   $total boundaries imported\n";

sqlsrv_close($conn);
echo "\nDone.\n";
