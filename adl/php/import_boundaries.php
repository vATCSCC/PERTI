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
        'tracon' => ['imported' => 0, 'failed' => 0],
        'hierarchy' => ['imported' => 0, 'failed' => 0]
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
        $this->importHierarchy();

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
            $boundaryCode = $props['ICAOCODE'] ?? $props['FIRname'];

            // Determine boundary_type from enriched hierarchy properties
            if (isset($props['hierarchy_type'])) {
                // Enriched GeoJSON from classification script
                $hierarchyType = $props['hierarchy_type'];
                $hierarchyLevel = $props['hierarchy_level'] ?? null;

                if ($hierarchyType === 'SUPER_CENTER') {
                    $boundaryType = 'ARTCC_SUPER';
                } elseif ($hierarchyLevel == 1) {
                    $boundaryType = 'ARTCC';
                } elseif ($hierarchyLevel == 2) {
                    $boundaryType = 'ARTCC_SUB';
                } elseif ($hierarchyLevel >= 3) {
                    $boundaryType = 'ARTCC_SUB_' . $hierarchyLevel;
                } else {
                    $boundaryType = 'ARTCC_SUB';
                }
                $parentFir = $props['parent_fir'] ?? null;
            } else {
                // Raw GeoJSON (pre-classification, backward compat)
                $isSubArea = !empty($props['is_sub_area']) || (strpos($boundaryCode, '-') !== false);
                $boundaryType = $isSubArea ? 'ARTCC_SUB' : 'ARTCC';
                $hierarchyLevel = null;
                $hierarchyType = null;
                $parentFir = $isSubArea
                    ? ($props['parent_fir'] ?? substr($boundaryCode, 0, strpos($boundaryCode, '-')))
                    : null;
            }

            $result = $this->importBoundary([
                'boundary_type' => $boundaryType,
                'boundary_code' => $boundaryCode,
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
                'source_file' => 'artcc.json',
                'parent_fir' => $parentFir,
                'hierarchy_level' => $hierarchyLevel,
                'hierarchy_type' => $hierarchyType,
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

            // Use enriched fields from classification script if available
            // After enrichment: tracon = facility code, sector = subdivision identifier
            // Before enrichment: sector = facility code, artcc = airport-derived code
            if (isset($props['tracon'])) {
                // Enriched GeoJSON (post-classification)
                $boundaryCode = $props['sector'];  // Unique per feature (subdivision identifier)
                $parentArtcc = $props['tracon'];    // TRACON facility code (e.g., "A80")
                $parentFir = $props['parent_fir'] ?? null;  // Parent ARTCC (e.g., "ZTL")
                $hierarchyLevel = $props['hierarchy_level'] ?? null;
                $hierarchyType = $props['hierarchy_type'] ?? null;
            } else {
                // Raw GeoJSON (pre-classification, backward compat)
                $boundaryCode = $props['sector'] ?? $props['label'];
                $parentArtcc = strtoupper($props['artcc'] ?? '');
                $parentFir = null;
                $hierarchyLevel = null;
                $hierarchyType = null;
            }

            $result = $this->importBoundary([
                'boundary_type' => 'TRACON',
                'boundary_code' => $boundaryCode,
                'boundary_name' => $props['label'] ?? null,
                'parent_artcc' => $parentArtcc,
                'sector_number' => $props['sector'] ?? null,
                'label_lat' => $props['label_lat'] ?? null,
                'label_lon' => $props['label_lon'] ?? null,
                'geometry' => $feature['geometry'],
                'shape_length' => $props['Shape_Length'] ?? null,
                'shape_area' => $props['Shape_Area'] ?? null,
                'source_object_id' => $props['OBJECTID'] ?? null,
                'source_file' => 'tracon.json',
                'parent_fir' => $parentFir,
                'hierarchy_level' => $hierarchyLevel,
                'hierarchy_type' => $hierarchyType,
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
                    @parent_fir = :parent_fir,
                    @hierarchy_level = :hierarchy_level,
                    @hierarchy_type = :hierarchy_type,
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
            $stmt->bindValue(':parent_fir', $data['parent_fir'] ?? null);
            $stmt->bindValue(':hierarchy_level', $data['hierarchy_level'] ?? null);
            $stmt->bindValue(':hierarchy_type', $data['hierarchy_type'] ?? null);

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
     * Import boundary hierarchy edges from boundary_hierarchy.json
     */
    public function importHierarchy($filePath = null) {
        $file = $filePath ?? $this->geojsonDir . 'boundary_hierarchy.json';
        echo "Importing boundary hierarchy from: $file\n";

        if (!file_exists($file)) {
            echo "  Skipping (file not found)\n\n";
            return false;
        }

        $data = json_decode(file_get_contents($file), true);
        if (!$data || !isset($data['edges'])) {
            echo "  ERROR: Invalid hierarchy JSON\n";
            return false;
        }

        $edges = $data['edges'];
        $count = count($edges);
        echo "  Found $count edges\n";

        // Clear existing edges
        $this->pdo->exec("DELETE FROM boundary_hierarchy");

        // Build lookup: boundary_code -> boundary_id
        $stmt = $this->pdo->query("SELECT boundary_id, boundary_code, boundary_type FROM adl_boundary WHERE is_active = 1");
        $codeMap = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = $row['boundary_type'] . ':' . $row['boundary_code'];
            $codeMap[$key] = $row['boundary_id'];
            // Also map by code only for ARTCC-family
            if (strpos($row['boundary_type'], 'ARTCC') === 0) {
                if (!isset($codeMap['ARTCC_ANY:' . $row['boundary_code']])) {
                    $codeMap['ARTCC_ANY:' . $row['boundary_code']] = $row['boundary_id'];
                }
            }
        }

        $insertSql = "INSERT INTO boundary_hierarchy (parent_boundary_id, child_boundary_id, parent_code, child_code, relationship_type, coverage_ratio)
                      VALUES (:parent_id, :child_id, :parent_code, :child_code, :rel_type, :coverage)";
        $insertStmt = $this->pdo->prepare($insertSql);

        foreach ($edges as $i => $edge) {
            $parentCode = $edge['parent'] ?? '';
            $childCode = $edge['child'] ?? '';
            $relType = $edge['type'] ?? 'CONTAINS';
            $coverage = $edge['coverage'] ?? null;

            // Resolve boundary IDs
            $parentId = null;
            $childId = null;

            if ($relType === 'CONTAINS' || $relType === 'TILES') {
                $parentId = $codeMap['ARTCC_ANY:' . $parentCode] ?? null;
                $childId = $codeMap['ARTCC_ANY:' . $childCode] ?? null;
            } elseif ($relType === 'SECTOR_OF') {
                // Parent is sector/TRACON, child is ARTCC
                $parentId = $codeMap['SECTOR_HIGH:' . $parentCode]
                    ?? $codeMap['SECTOR_LOW:' . $parentCode]
                    ?? $codeMap['SECTOR_SUPERHIGH:' . $parentCode]
                    ?? $codeMap['TRACON:' . $parentCode]
                    ?? null;
                $childId = $codeMap['ARTCC_ANY:' . $childCode] ?? null;
            } elseif ($relType === 'TRACON_OF') {
                // Both are TRACONs: parent = facility code, child = subdivision code
                $parentId = $codeMap['TRACON:' . $parentCode] ?? null;
                $childId = $codeMap['TRACON:' . $childCode] ?? null;
            }

            if ($parentId === null || $childId === null) {
                $this->stats['hierarchy']['failed']++;
                continue;
            }

            try {
                $insertStmt->execute([
                    ':parent_id' => $parentId,
                    ':child_id' => $childId,
                    ':parent_code' => substr($parentCode, 0, 50),
                    ':child_code' => substr($childCode, 0, 50),
                    ':rel_type' => substr($relType, 0, 20),
                    ':coverage' => $coverage,
                ]);
                $this->stats['hierarchy']['imported']++;
            } catch (PDOException $e) {
                $this->stats['hierarchy']['failed']++;
            }

            if (($i + 1) % 200 == 0) {
                echo "  Processed " . ($i + 1) . "/$count\n";
            }
        }

        echo "  Hierarchy import complete: {$this->stats['hierarchy']['imported']} imported, {$this->stats['hierarchy']['failed']} failed\n\n";
        return true;
    }

    /**
     * Print import summary
     */
    private function printSummary() {
        echo "=== Import Summary ===\n";
        echo "ARTCC:     {$this->stats['artcc']['imported']} imported, {$this->stats['artcc']['failed']} failed\n";
        echo "Sectors:   {$this->stats['sectors']['imported']} imported, {$this->stats['sectors']['failed']} failed\n";
        echo "TRACON:    {$this->stats['tracon']['imported']} imported, {$this->stats['tracon']['failed']} failed\n";
        echo "Hierarchy: {$this->stats['hierarchy']['imported']} imported, {$this->stats['hierarchy']['failed']} failed\n";

        $total = $this->stats['artcc']['imported'] + $this->stats['sectors']['imported'] + $this->stats['tracon']['imported'];
        echo "Total:     $total boundaries imported + {$this->stats['hierarchy']['imported']} hierarchy edges\n";
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
        case 'hierarchy':
            $importer->importHierarchy($file);
            break;
        case 'all':
        default:
            $importer->importAll();
            break;
    }
}
