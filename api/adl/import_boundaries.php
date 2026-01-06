<?php
/**
 * Phase 5E.1: Boundary Import - Debug Version
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

header('Content-Type: text/plain');

echo "=== Boundary Import Debug ===\n\n";

// Check config file
$configPath = __DIR__ . '/../../config/database.php';
echo "Config path: $configPath\n";
echo "Config exists: " . (file_exists($configPath) ? "YES" : "NO") . "\n\n";

if (!file_exists($configPath)) {
    die("ERROR: Config file not found at $configPath\n");
}

// Check geojson directory
$geojsonDir = __DIR__ . '/../../assets/geojson/';
echo "GeoJSON dir: $geojsonDir\n";
echo "GeoJSON dir exists: " . (is_dir($geojsonDir) ? "YES" : "NO") . "\n\n";

if (is_dir($geojsonDir)) {
    echo "Files in geojson dir:\n";
    foreach (scandir($geojsonDir) as $file) {
        if ($file !== '.' && $file !== '..') {
            echo "  - $file\n";
        }
    }
    echo "\n";
}

// Try to include config
echo "Loading config...\n";
try {
    require_once $configPath;
    echo "Config loaded OK\n\n";
} catch (Exception $e) {
    die("ERROR loading config: " . $e->getMessage() . "\n");
}

// Check if function exists
echo "Function getVatsimAdlConnection exists: " . (function_exists('getVatsimAdlConnection') ? "YES" : "NO") . "\n\n";

if (!function_exists('getVatsimAdlConnection')) {
    die("ERROR: getVatsimAdlConnection function not found\n");
}

// Try database connection
echo "Connecting to database...\n";
try {
    $pdo = getVatsimAdlConnection();
    echo "Database connected OK\n\n";
} catch (Exception $e) {
    die("ERROR connecting to database: " . $e->getMessage() . "\n");
}

// Check if stored procedure exists
echo "Checking sp_ImportBoundary exists...\n";
try {
    $stmt = $pdo->query("SELECT OBJECT_ID('sp_ImportBoundary') as proc_id");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "sp_ImportBoundary exists: " . ($row['proc_id'] ? "YES" : "NO") . "\n\n";
} catch (Exception $e) {
    echo "ERROR checking procedure: " . $e->getMessage() . "\n\n";
}

// Check if table exists
echo "Checking adl_boundary table exists...\n";
try {
    $stmt = $pdo->query("SELECT OBJECT_ID('adl_boundary') as table_id");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "adl_boundary exists: " . ($row['table_id'] ? "YES" : "NO") . "\n\n";
} catch (Exception $e) {
    echo "ERROR checking table: " . $e->getMessage() . "\n\n";
}

// Try a simple test import
echo "=== Testing single boundary import ===\n\n";

$artccFile = $geojsonDir . 'artcc.json';
if (!file_exists($artccFile)) {
    die("ERROR: artcc.json not found at $artccFile\n");
}

$geojson = json_decode(file_get_contents($artccFile), true);
if (!$geojson) {
    die("ERROR: Failed to parse artcc.json - " . json_last_error_msg() . "\n");
}

echo "artcc.json loaded, " . count($geojson['features']) . " features\n\n";

// Get first feature
$feature = $geojson['features'][0];
$props = $feature['properties'];

echo "Testing with first feature:\n";
echo "  Code: " . ($props['ICAOCODE'] ?? $props['FIRname']) . "\n";
echo "  Name: " . $props['FIRname'] . "\n";
echo "  Type: " . $feature['geometry']['type'] . "\n\n";

// Convert to WKT
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
    
    return null;
}

$wkt = geojsonToWkt($feature['geometry']);
echo "WKT generated, length: " . strlen($wkt) . " chars\n";
echo "WKT preview: " . substr($wkt, 0, 100) . "...\n\n";

// Try the import
echo "Executing sp_ImportBoundary...\n";
try {
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
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'ARTCC',
        $props['ICAOCODE'] ?? $props['FIRname'],
        $props['FIRname'],
        null,
        null,
        $props['ICAOCODE'] ?? null,
        $props['VATSIM Reg'] ?? null,
        $props['VATSIM Div'] ?? null,
        $props['VATSIM Sub'] ?? null,
        ($props['oceanic'] ?? 0) ? 1 : 0,
        $props['FLOOR'] ?? null,
        $props['CEILING'] ?? null,
        $props['label_lat'] ?? null,
        $props['label_lon'] ?? null,
        $wkt,
        null,
        null,
        null,
        $props['fid'] ?? null,
        'artcc.json'
    ]);
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Result: boundary_id = " . ($result['boundary_id'] ?? 'NULL') . "\n\n";
    
    if ($result && $result['boundary_id'] > 0) {
        echo "SUCCESS! Test import worked.\n";
        echo "You can now run the full import.\n";
    } else {
        echo "WARNING: Import returned boundary_id <= 0\n";
    }
    
} catch (Exception $e) {
    echo "ERROR during import: " . $e->getMessage() . "\n";
}

echo "\n=== Debug Complete ===\n";
