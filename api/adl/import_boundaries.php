<?php
/**
 * Phase 5E.1: Boundary Import - Web Trigger
 * /api/adl/import_boundaries.php
 * 
 * Run from browser: /api/adl/import_boundaries.php?type=all&key=YOUR_SECRET
 */

// Simple security - change this key
$secretKey = 'perti_boundary_import_2025';

if (($_GET['key'] ?? '') !== $secretKey) {
    http_response_code(403);
    die('Unauthorized');
}

// Increase limits for large import
set_time_limit(600);
ini_set('memory_limit', '512M');

header('Content-Type: text/plain');

require_once __DIR__ . '/../../config/database.php';

class BoundaryImporter {
    private $pdo;
    private $geojsonDir;
    private $stats = [
        'artcc' => ['imported' => 0, 'failed' => 0],
        'sectors' => ['imported' => 0, 'failed' => 0],
        'tracon' => ['imported' => 0, 'failed' => 0]
    ];
    
    public function __construct() {
        $this->pdo = getVatsimAdlConnection();
        $this->geojsonDir = __DIR__ . '/../../assets/geojson/';
    }
    
    public function importAll() {
        echo "Starting full boundary import...\n\n";
        flush();
        
        $this->importArtcc();
        $this->importSectors('high');
        $this->importSectors('low');
        $this->importSectors('superhigh');
        $this->importTracon();
        
        $this->printSummary();
    }
    
    public function importArtcc($filePath = null) {
        $file = $filePath ?? $this->geojsonDir . 'artcc.json';
        echo "Importing ARTCC boundaries from: $file\n";
        flush();
        
        if (!file_exists($file)) {
            echo "  ERROR: File not found\n";
            return false;
        }
        
        $geojson = json_decode(file_get_contents($file), true);
        if (!$geojson || !isset($geojson['features'])) {
            echo "  ERROR: Invalid GeoJSON\n";
            return false;
        }
        
        $count = count($geojson['features']);
        echo "  Found $count ARTCC features\n";
        flush();
        
        foreach ($geojson['features'] as $i => $feature) {
            $props = $feature['properties'];
            $result = $this->importBoundary([
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
                $this->stats['artcc']['imported']++;
            } else {
                $this->stats['artcc']['failed']++;
            }
            
            if (($i + 1) % 50 == 0) {
                echo "  Processed " . ($i + 1) . "/$count\n";
                flush();
            }
        }
        
        echo "  ARTCC import complete: {$this->stats['artcc']['imported']} imported, {$this->stats['artcc']['failed']} failed\n\n";
        flush();
        return true;
    }
    
    public function importSectors($type = 'high', $filePath = null) {
        $file = $filePath ?? $this->geojsonDir . $type . '.json';
        $boundaryType = 'SECTOR_' . strtoupper($type);
        
        echo "Importing $type sector boundaries from: $file\n";
        flush();
        
        if (!file_exists($file)) {
            echo "  ERROR: File not found\n";
            return false;
        }
        
        $geojson = json_decode(file_get_contents($file), true);
        if (!$geojson || !isset($geojson['features'])) {
            echo "  ERROR: Invalid GeoJSON\n";
            return false;
        }
        
        $count = count($geojson['features']);
        echo "  Found $count $type sector features\n";
        flush();
        
        foreach ($geojson['features'] as $i => $feature) {
            $props = $feature['properties'];
            $result = $this->importBoundary([
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
                $this->stats['sectors']['imported']++;
            } else {
                $this->stats['sectors']['failed']++;
            }
            
            if (($i + 1) % 100 == 0) {
                echo "  Processed " . ($i + 1) . "/$count\n";
                flush();
            }
        }
        
        echo "  $type sector import complete\n\n";
        flush();
        return true;
    }
    
    public function importTracon($filePath = null) {
        $file = $filePath ?? $this->geojsonDir . 'tracon.json';
        echo "Importing TRACON boundaries from: $file\n";
        flush();
        
        if (!file_exists($file)) {
            echo "  ERROR: File not found\n";
            return false;
        }
        
        $geojson = json_decode(file_get_contents($file), true);
        if (!$geojson || !isset($geojson['features'])) {
            echo "  ERROR: Invalid GeoJSON\n";
            return false;
        }
        
        $count = count($geojson['features']);
        echo "  Found $count TRACON features\n";
        flush();
        
        foreach ($geojson['features'] as $i => $feature) {
            $props = $feature['properties'];
            $result = $this->importBoundary([
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
                $this->stats['tracon']['imported']++;
            } else {
                $this->stats['tracon']['failed']++;
            }
            
            if (($i + 1) % 50 == 0) {
                echo "  Processed " . ($i + 1) . "/$count\n";
                flush();
            }
        }
        
        echo "  TRACON import complete: {$this->stats['tracon']['imported']} imported, {$this->stats['tracon']['failed']} failed\n\n";
        flush();
        return true;
    }
    
    private function geojsonToWkt($geometry) {
        $type = $geometry['type'];
        $coords = $geometry['coordinates'];
        
        switch ($type) {
            case 'Polygon':
                return $this->polygonToWkt($coords);
            case 'MultiPolygon':
                return $this->multiPolygonToWkt($coords);
            default:
                throw new Exception("Unsupported geometry type: $type");
        }
    }
    
    private function polygonToWkt($coords) {
        $rings = [];
        foreach ($coords as $ring) {
            $points = [];
            foreach ($ring as $coord) {
                $points[] = $coord[0] . ' ' . $coord[1];
            }
            $rings[] = '(' . implode(', ', $points) . ')';
        }
        return 'POLYGON (' . implode(', ', $rings) . ')';
    }
    
    private function multiPolygonToWkt($coords) {
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
    
    private function importBoundary($data) {
        try {
            $wkt = $this->geojsonToWkt($data['geometry']);
            
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
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
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
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result && $result['boundary_id'] > 0;
            
        } catch (Exception $e) {
            // Uncomment for debugging:
            // echo "    Error ({$data['boundary_code']}): " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    private function printSummary() {
        echo "=== Import Summary ===\n";
        echo "ARTCC:   {$this->stats['artcc']['imported']} imported, {$this->stats['artcc']['failed']} failed\n";
        echo "Sectors: {$this->stats['sectors']['imported']} imported, {$this->stats['sectors']['failed']} failed\n";
        echo "TRACON:  {$this->stats['tracon']['imported']} imported, {$this->stats['tracon']['failed']} failed\n";
        
        $total = $this->stats['artcc']['imported'] + $this->stats['sectors']['imported'] + $this->stats['tracon']['imported'];
        echo "Total:   $total boundaries imported\n";
    }
}

// Run import
$type = $_GET['type'] ?? 'all';
$importer = new BoundaryImporter();

switch ($type) {
    case 'artcc':
        $importer->importArtcc();
        break;
    case 'high':
    case 'low':
    case 'superhigh':
        $importer->importSectors($type);
        break;
    case 'tracon':
        $importer->importTracon();
        break;
    case 'all':
    default:
        $importer->importAll();
        break;
}

echo "\nDone.\n";
