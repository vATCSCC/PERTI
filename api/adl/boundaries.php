<?php
/**
 * Phase 5E.1: Boundary API Endpoint
 * /api/adl/boundaries.php
 * 
 * Provides boundary data for map display and flight tracking
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../config/database.php';

try {
    $pdo = getVatsimAdlConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$action = $_GET['action'] ?? 'list';

switch ($action) {
    
    /**
     * List boundaries by type
     * GET /api/adl/boundaries.php?action=list&type=ARTCC
     * GET /api/adl/boundaries.php?action=list&type=SECTOR_HIGH&artcc=ZDC
     */
    case 'list':
        $type = $_GET['type'] ?? null;
        $artcc = $_GET['artcc'] ?? null;
        $includeGeometry = ($_GET['geometry'] ?? '0') === '1';
        
        $sql = "SELECT 
            boundary_id,
            boundary_type,
            boundary_code,
            boundary_name,
            parent_artcc,
            sector_number,
            icao_code,
            vatsim_region,
            vatsim_division,
            is_oceanic,
            floor_altitude,
            ceiling_altitude,
            label_lat,
            label_lon,
            shape_area";
        
        if ($includeGeometry) {
            $sql .= ", boundary_geography.STAsText() as geometry_wkt";
        }
        
        $sql .= " FROM adl_boundary WHERE is_active = 1";
        
        $params = [];
        if ($type) {
            $sql .= " AND boundary_type = :type";
            $params[':type'] = $type;
        }
        if ($artcc) {
            $sql .= " AND (parent_artcc = :artcc OR boundary_code = :artcc2)";
            $params[':artcc'] = strtoupper($artcc);
            $params[':artcc2'] = strtoupper($artcc);
        }
        
        $sql .= " ORDER BY boundary_type, boundary_code";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $boundaries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'count' => count($boundaries),
            'boundaries' => $boundaries
        ]);
        break;
    
    /**
     * Get GeoJSON for map display
     * GET /api/adl/boundaries.php?action=geojson&type=ARTCC
     * GET /api/adl/boundaries.php?action=geojson&type=SECTOR_HIGH&artcc=ZDC
     */
    case 'geojson':
        $type = $_GET['type'] ?? null;
        $artcc = $_GET['artcc'] ?? null;
        
        $sql = "SELECT 
            boundary_id,
            boundary_type,
            boundary_code,
            boundary_name,
            parent_artcc,
            sector_number,
            icao_code,
            vatsim_region,
            vatsim_division,
            is_oceanic,
            floor_altitude,
            ceiling_altitude,
            label_lat,
            label_lon,
            boundary_geography.STAsText() as geometry_wkt
        FROM adl_boundary 
        WHERE is_active = 1";
        
        $params = [];
        if ($type) {
            $sql .= " AND boundary_type = :type";
            $params[':type'] = $type;
        }
        if ($artcc) {
            $sql .= " AND (parent_artcc = :artcc OR boundary_code = :artcc2)";
            $params[':artcc'] = strtoupper($artcc);
            $params[':artcc2'] = strtoupper($artcc);
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Build GeoJSON FeatureCollection
        $features = [];
        foreach ($rows as $row) {
            $geometry = wktToGeoJson($row['geometry_wkt']);
            
            $features[] = [
                'type' => 'Feature',
                'id' => $row['boundary_id'],
                'properties' => [
                    'boundary_id' => (int)$row['boundary_id'],
                    'boundary_type' => $row['boundary_type'],
                    'boundary_code' => $row['boundary_code'],
                    'boundary_name' => $row['boundary_name'],
                    'parent_artcc' => $row['parent_artcc'],
                    'sector_number' => $row['sector_number'],
                    'icao_code' => $row['icao_code'],
                    'vatsim_region' => $row['vatsim_region'],
                    'vatsim_division' => $row['vatsim_division'],
                    'is_oceanic' => (bool)$row['is_oceanic'],
                    'floor_altitude' => $row['floor_altitude'] ? (int)$row['floor_altitude'] : null,
                    'ceiling_altitude' => $row['ceiling_altitude'] ? (int)$row['ceiling_altitude'] : null,
                    'label_lat' => $row['label_lat'] ? (float)$row['label_lat'] : null,
                    'label_lon' => $row['label_lon'] ? (float)$row['label_lon'] : null
                ],
                'geometry' => $geometry
            ];
        }
        
        echo json_encode([
            'type' => 'FeatureCollection',
            'features' => $features
        ]);
        break;
    
    /**
     * Get boundary containing a point
     * GET /api/adl/boundaries.php?action=contains&lat=38.8977&lon=-77.0365
     */
    case 'contains':
        $lat = floatval($_GET['lat'] ?? 0);
        $lon = floatval($_GET['lon'] ?? 0);
        $alt = isset($_GET['alt']) ? intval($_GET['alt']) : null;
        
        if ($lat == 0 || $lon == 0) {
            echo json_encode(['error' => 'Invalid coordinates']);
            break;
        }
        
        // Find ARTCC
        $sql = "SELECT TOP 1 
            boundary_id, boundary_code, boundary_name, is_oceanic
        FROM adl_boundary
        WHERE boundary_type = 'ARTCC'
          AND is_active = 1
          AND boundary_geography.STContains(geography::Point(:lat1, :lon1, 4326)) = 1
        ORDER BY is_oceanic ASC, boundary_geography.STArea() ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':lat1' => $lat, ':lon1' => $lon]);
        $artcc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Find Sector
        $sql = "SELECT TOP 1 
            boundary_id, boundary_type, boundary_code, boundary_name, parent_artcc,
            floor_altitude, ceiling_altitude
        FROM adl_boundary
        WHERE boundary_type IN ('SECTOR_HIGH', 'SECTOR_LOW', 'SECTOR_SUPERHIGH')
          AND is_active = 1
          AND boundary_geography.STContains(geography::Point(:lat2, :lon2, 4326)) = 1
        ORDER BY boundary_geography.STArea() ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':lat2' => $lat, ':lon2' => $lon]);
        $sector = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Find TRACON
        $tracon = null;
        if ($alt === null || $alt < 18000) {
            $sql = "SELECT TOP 1 
                boundary_id, boundary_code, boundary_name, parent_artcc
            FROM adl_boundary
            WHERE boundary_type = 'TRACON'
              AND is_active = 1
              AND boundary_geography.STContains(geography::Point(:lat3, :lon3, 4326)) = 1
            ORDER BY boundary_geography.STArea() ASC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':lat3' => $lat, ':lon3' => $lon]);
            $tracon = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        echo json_encode([
            'success' => true,
            'position' => ['lat' => $lat, 'lon' => $lon, 'alt' => $alt],
            'artcc' => $artcc ?: null,
            'sector' => $sector ?: null,
            'tracon' => $tracon ?: null
        ]);
        break;
    
    /**
     * Get boundary types summary
     * GET /api/adl/boundaries.php?action=summary
     */
    case 'summary':
        $sql = "SELECT 
            boundary_type,
            COUNT(*) as total,
            COUNT(CASE WHEN is_active = 1 THEN 1 END) as active
        FROM adl_boundary
        GROUP BY boundary_type
        ORDER BY boundary_type";
        
        $stmt = $pdo->query($sql);
        $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'summary' => $summary
        ]);
        break;
    
    /**
     * Get flight boundary status
     * GET /api/adl/boundaries.php?action=flight&uid=123
     * GET /api/adl/boundaries.php?action=flight&callsign=AAL123
     */
    case 'flight':
        $flightUid = $_GET['uid'] ?? null;
        $callsign = $_GET['callsign'] ?? null;
        
        $sql = "SELECT 
            fc.flight_uid,
            fc.callsign,
            fc.current_artcc,
            ab1.boundary_name as artcc_name,
            fc.current_sector,
            ab2.boundary_name as sector_name,
            fc.current_tracon,
            ab3.boundary_name as tracon_name,
            fc.boundary_updated_at,
            fp.lat as latitude,
            fp.lon as longitude,
            fp.altitude_ft as altitude
        FROM adl_flight_core fc
        LEFT JOIN adl_flight_position fp ON fc.flight_uid = fp.flight_uid
        LEFT JOIN adl_boundary ab1 ON fc.current_artcc_id = ab1.boundary_id
        LEFT JOIN adl_boundary ab2 ON fc.current_sector_id = ab2.boundary_id
        LEFT JOIN adl_boundary ab3 ON fc.current_tracon_id = ab3.boundary_id
        WHERE ";
        
        if ($flightUid) {
            $sql .= "fc.flight_uid = :uid";
            $params = [':uid' => $flightUid];
        } elseif ($callsign) {
            $sql .= "fc.callsign = :callsign AND fc.is_active = 1";
            $params = [':callsign' => strtoupper($callsign)];
        } else {
            echo json_encode(['error' => 'Flight UID or callsign required']);
            break;
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $flight = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($flight) {
            // Get recent boundary transitions
            $logSql = "SELECT TOP 10
                bl.boundary_type,
                bl.boundary_code,
                ab.boundary_name,
                bl.entry_time,
                bl.exit_time,
                bl.entry_lat,
                bl.entry_lon
            FROM adl_flight_boundary_log bl
            LEFT JOIN adl_boundary ab ON bl.boundary_id = ab.boundary_id
            WHERE bl.flight_id = :fuid
            ORDER BY bl.entry_time DESC";
            
            $logStmt = $pdo->prepare($logSql);
            $logStmt->execute([':fuid' => $flight['flight_uid']]);
            $transitions = $logStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $flight['transitions'] = $transitions;
        }
        
        echo json_encode([
            'success' => true,
            'flight' => $flight ?: null
        ]);
        break;
    
    /**
     * Detect boundaries for a flight (trigger detection)
     * POST /api/adl/boundaries.php?action=detect
     * Body: { "flight_uid": 123, "lat": 38.8977, "lon": -77.0365, "alt": 35000 }
     */
    case 'detect':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['error' => 'POST required']);
            break;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $flightUid = $input['flight_uid'] ?? null;
        $lat = $input['lat'] ?? null;
        $lon = $input['lon'] ?? null;
        $alt = $input['alt'] ?? null;
        
        if (!$flightUid || !$lat || !$lon) {
            echo json_encode(['error' => 'flight_uid, lat, lon required']);
            break;
        }
        
        $sql = "EXEC sp_DetectFlightBoundaries :flight_uid, :lat, :lon, :alt";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':flight_uid' => $flightUid,
            ':lat' => $lat,
            ':lon' => $lon,
            ':alt' => $alt
        ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'boundaries' => $result
        ]);
        break;
    
    default:
        echo json_encode(['error' => 'Unknown action']);
}

/**
 * Convert WKT to GeoJSON geometry
 */
function wktToGeoJson($wkt) {
    // Parse WKT type
    if (preg_match('/^(POLYGON|MULTIPOLYGON)\s*\((.+)\)$/is', $wkt, $matches)) {
        $type = strtoupper($matches[1]);
        $coordStr = $matches[2];
        
        if ($type === 'POLYGON') {
            return [
                'type' => 'Polygon',
                'coordinates' => parsePolygonCoords($coordStr)
            ];
        } else {
            return [
                'type' => 'MultiPolygon',
                'coordinates' => parseMultiPolygonCoords($coordStr)
            ];
        }
    }
    
    return null;
}

/**
 * Parse polygon coordinates from WKT
 */
function parsePolygonCoords($str) {
    $rings = [];
    // Match each ring: (x1 y1, x2 y2, ...)
    preg_match_all('/\(([^()]+)\)/', $str, $ringMatches);
    
    foreach ($ringMatches[1] as $ringStr) {
        $ring = [];
        $points = explode(',', $ringStr);
        foreach ($points as $point) {
            $coords = preg_split('/\s+/', trim($point));
            if (count($coords) >= 2) {
                // WKT is lon lat, GeoJSON is [lon, lat]
                $ring[] = [(float)$coords[0], (float)$coords[1]];
            }
        }
        $rings[] = $ring;
    }
    
    return $rings;
}

/**
 * Parse multipolygon coordinates from WKT
 */
function parseMultiPolygonCoords($str) {
    $polygons = [];
    // Match each polygon: ((ring1), (ring2))
    preg_match_all('/\(\(([^)]+(?:\),[^)]+)*)\)\)/', $str, $polyMatches);
    
    foreach ($polyMatches[0] as $polyStr) {
        // Remove outer parens
        $polyStr = trim($polyStr, '()');
        $polygons[] = parsePolygonCoords($polyStr);
    }
    
    // If no matches, try simpler pattern
    if (empty($polygons)) {
        // Fall back to treating entire string as single polygon
        $polygons[] = parsePolygonCoords($str);
    }
    
    return $polygons;
}
