<?php
/**
 * Phase 5E.1: Boundary Import Script
 * Imports ARTCC, sector, and TRACON boundaries from GeoJSON files
 * Converts GeoJSON geometry to WKT for SQL Server
 * 
 * Usage: php import_boundaries.php [--type=artcc|sectors|tracon|all] [--file=path]
 */

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
        // Connect to VATSIM_ADL database
        $this->pdo = getVatsimAdlConnection();
        $this->geojsonDir = __DIR__ . '/../../assets/geojson/';
    }
    
    /**
     * Import all boundary types
     */
    public function importAll() {
        echo "Starting full boundary import...\n\n";
        
        $this->importArtcc();
        $this->importSectors('high');
        $this->importSectors('low');
        $this->importSectors('superhigh');
        $this->importTracon();
        
        $this->printSummary();
    }
    
    /**
     * Import ARTCC/FIR boundaries from artcc.json
     */
    public function importArtcc($filePath = null) {
        $file = $filePath ?? $this->geojsonDir . 'artcc.json';
        echo "Importing ARTCC boundaries from: $file\n";
        
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
        
        foreach ($geojson['features'] as $i => $feature) {
            $props = $feature['properties'];
            $result = $this->importBoundary([
                'boundary_type' => 'ARTCC',
                'boundary_code' => $props['ICAOCODE'] ?? $props['FIRname'],
                'boundary_name' => $props['FIRname'],
                'icao_code' => $props['ICAOCODE'],
                'vatsim_region' => $props['VATSIM Reg'] ?? null,
                'vatsim_division' => $props['VATSIM Div'] ?? null,
                'vatsim_subdivision' => $props['VATSIM Sub'] ?? null,
                'is_oceanic' => ($props['oceanic'] ?? 0) ? 1 : 0,
                'floor_altitude' => $props['FLOOR'],
                'ceiling_altitude' => $props['CEILING'],
                'label_lat' => $props['label_lat'],
                'label_lon' => $props['label_lon'],
                'geometry' => $feature['geometry'],
                'source_fid' => $props['fid'],
                'source_file' => 'artcc.json'
            ]);
            
            if ($result) {
                $this->stats['artcc']['imported']++;
            } else {
                $this->stats['artcc']['failed']++;
            }
            
            // Progress indicator
            if (($i + 1) % 50 == 0) {
                echo "  Processed " . ($i + 1) . "/$count\n";
            }
        }
        
        echo "  ARTCC import complete: {$this->stats['artcc']['imported']} imported, {$this->stats['artcc']['failed']} failed\n\n";
        return true;
    }
    
    /**
     * Import sector boundaries (high, low, superhigh)
     */
    public function importSectors($type = 'high', $filePath = null) {
        $file = $filePath ?? $this->geojsonDir . $type . '.json';
        $boundaryType = 'SECTOR_' . strtoupper($type);
        
        echo "Importing $type sector boundaries from: $file\n";
        
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
        
        foreach ($geojson['features'] as $i => $feature) {
            $props = $feature['properties'];
            $result = $this->importBoundary([
                'boundary_type' => $boundaryType,
                'boundary_code' => $props['label'] ?? ($props['artcc'] . $props['sector']),
                'boundary_name' => $props['label'],
                'parent_artcc' => strtoupper($props['artcc']),
                'sector_number' => $props['sector'],
                'geometry' => $feature['geometry'],
                'shape_length' => $props['Shape_Length'],
                'shape_area' => $props['Shape_Area'],
                'source_object_id' => $props['OBJECTID'],
                'source_file' => $type . '.json'
            ]);
            
            if ($result) {
                $this->stats['sectors']['imported']++;
            } else {
                $this->stats['sectors']['failed']++;
            }
            
            // Progress indicator
            if (($i + 1) % 100 == 0) {
                echo "  Processed " . ($i + 1) . "/$count\n";
            }
        }
        
        echo "  $type sector import complete\n\n";
        return true;
    }
    
    /**
     * Import TRACON boundaries
     */
    public function importTracon($filePath = null) {
        $file = $filePath ?? $this->geojsonDir . 'tracon.json';
        echo "Importing TRACON boundaries from: $file\n";
        
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
        
        foreach ($geojson['features'] as $i => $feature) {
            $props = $feature['properties'];
            $result = $this->importBoundary([
                'boundary_type' => 'TRACON',
                'boundary_code' => $props['sector'] ?? $props['label'],
                'boundary_name' => $props['label'],
                'parent_artcc' => strtoupper($props['artcc']),
                'sector_number' => $props['sector'],
                'label_lat' => $props['label_lat'],
                'label_lon' => $props['label_lon'],
                'geometry' => $feature['geometry'],
                'shape_length' => $props['Shape_Length'],
                'shape_area' => $props['Shape_Area'],
                'source_object_id' => $props['OBJECTID'],
                'source_file' => 'tracon.json'
            ]);
            
            if ($result) {
                $this->stats['tracon']['imported']++;
            } else {
                $this->stats['tracon']['failed']++;
            }
            
            // Progress indicator
            if (($i + 1) % 50 == 0) {
                echo "  Processed " . ($i + 1) . "/$count\n";
            }
        }
        
        echo "  TRACON import complete: {$this->stats['tracon']['imported']} imported, {$this->stats['tracon']['failed']} failed\n\n";
        return true;
    }
    
    /**
     * Convert GeoJSON geometry to WKT
     */
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
    
    /**
     * Convert polygon coordinates to WKT
     */
    private function polygonToWkt($coords) {
        $rings = [];
        foreach ($coords as $ring) {
            $points = [];
            foreach ($ring as $coord) {
                // GeoJSON is [lon, lat], WKT needs "lon lat"
                $points[] = $coord[0] . ' ' . $coord[1];
            }
            $rings[] = '(' . implode(', ', $points) . ')';
        }
        return 'POLYGON (' . implode(', ', $rings) . ')';
    }
    
    /**
     * Convert multipolygon coordinates to WKT
     */
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
    
    /**
     * Import a single boundary using stored procedure
     */
    private function importBoundary($data) {
        try {
            // Convert geometry to WKT
            $wkt = $this->geojsonToWkt($data['geometry']);
            
            $sql = "DECLARE @boundary_id INT;
                EXEC sp_ImportBoundary 
                    @boundary_type = :boundary_type,
                    @boundary_code = :boundary_code,
                    @boundary_name = :boundary_name,
                    @parent_artcc = :parent_artcc,
                    @sector_number = :sector_number,
                    @icao_code = :icao_code,
                    @vatsim_region = :vatsim_region,
                    @vatsim_division = :vatsim_division,
                    @vatsim_subdivision = :vatsim_subdivision,
                    @is_oceanic = :is_oceanic,
                    @floor_altitude = :floor_altitude,
                    @ceiling_altitude = :ceiling_altitude,
                    @label_lat = :label_lat,
                    @label_lon = :label_lon,
                    @wkt_geometry = :wkt_geometry,
                    @shape_length = :shape_length,
                    @shape_area = :shape_area,
                    @source_object_id = :source_object_id,
                    @source_fid = :source_fid,
                    @source_file = :source_file,
                    @boundary_id = @boundary_id OUTPUT;
                SELECT @boundary_id as boundary_id;";
            
            $stmt = $this->pdo->prepare($sql);
            
            $stmt->bindValue(':boundary_type', $data['boundary_type']);
            $stmt->bindValue(':boundary_code', $data['boundary_code']);
            $stmt->bindValue(':boundary_name', $data['boundary_name'] ?? null);
            $stmt->bindValue(':parent_artcc', $data['parent_artcc'] ?? null);
            $stmt->bindValue(':sector_number', $data['sector_number'] ?? null);
            $stmt->bindValue(':icao_code', $data['icao_code'] ?? null);
            $stmt->bindValue(':vatsim_region', $data['vatsim_region'] ?? null);
            $stmt->bindValue(':vatsim_division', $data['vatsim_division'] ?? null);
            $stmt->bindValue(':vatsim_subdivision', $data['vatsim_subdivision'] ?? null);
            $stmt->bindValue(':is_oceanic', $data['is_oceanic'] ?? 0);
            $stmt->bindValue(':floor_altitude', $data['floor_altitude'] ?? null);
            $stmt->bindValue(':ceiling_altitude', $data['ceiling_altitude'] ?? null);
            $stmt->bindValue(':label_lat', $data['label_lat'] ?? null);
            $stmt->bindValue(':label_lon', $data['label_lon'] ?? null);
            $stmt->bindValue(':wkt_geometry', $wkt);
            $stmt->bindValue(':shape_length', $data['shape_length'] ?? null);
            $stmt->bindValue(':shape_area', $data['shape_area'] ?? null);
            $stmt->bindValue(':source_object_id', $data['source_object_id'] ?? null);
            $stmt->bindValue(':source_fid', $data['source_fid'] ?? null);
            $stmt->bindValue(':source_file', $data['source_file'] ?? null);
            
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result && $result['boundary_id'] > 0;
            
        } catch (PDOException $e) {
            // For debugging
            echo "    Error: " . $e->getMessage() . "\n";
            return false;
        } catch (Exception $e) {
            echo "    Error: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Print import summary
     */
    private function printSummary() {
        echo "=== Import Summary ===\n";
        echo "ARTCC:   {$this->stats['artcc']['imported']} imported, {$this->stats['artcc']['failed']} failed\n";
        echo "Sectors: {$this->stats['sectors']['imported']} imported, {$this->stats['sectors']['failed']} failed\n";
        echo "TRACON:  {$this->stats['tracon']['imported']} imported, {$this->stats['tracon']['failed']} failed\n";
        
        $total = $this->stats['artcc']['imported'] + $this->stats['sectors']['imported'] + $this->stats['tracon']['imported'];
        echo "Total:   $total boundaries imported\n";
    }
    
    /**
     * Get statistics
     */
    public function getStats() {
        return $this->stats;
    }
}

// CLI execution
if (php_sapi_name() === 'cli') {
    $options = getopt('', ['type::', 'file::']);
    $type = $options['type'] ?? 'all';
    $file = $options['file'] ?? null;
    
    $importer = new BoundaryImporter();
    
    switch ($type) {
        case 'artcc':
            $importer->importArtcc($file);
            break;
        case 'high':
        case 'low':
        case 'superhigh':
            $importer->importSectors($type, $file);
            break;
        case 'tracon':
            $importer->importTracon($file);
            break;
        case 'all':
        default:
            $importer->importAll();
            break;
    }
}
