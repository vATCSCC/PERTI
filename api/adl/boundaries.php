<?php
/**
 * Phase 5E.2: Boundary API Endpoint
 * /api/adl/boundaries.php
 * 
 * Provides boundary data for map display and flight tracking
 * Updated: Multi-sector support with overlap detection
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once(__DIR__ . "/../../load/config.php");

if (!defined("ADL_SQL_HOST") || !defined("ADL_SQL_DATABASE") ||
    !defined("ADL_SQL_USERNAME") || !defined("ADL_SQL_PASSWORD")) {
    http_response_code(500);
    echo json_encode(['error' => 'ADL_SQL_* constants not defined']);
    exit;
}

$connInfo = [
    "Database" => ADL_SQL_DATABASE,
    "UID" => ADL_SQL_USERNAME,
    "PWD" => ADL_SQL_PASSWORD,
    "CharacterSet" => "UTF-8",
    "TrustServerCertificate" => true
];

$conn = sqlsrv_connect(ADL_SQL_HOST, $connInfo);
if ($conn === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$action = $_GET['action'] ?? 'list';

switch ($action) {
    
    /**
     * List boundaries by type
     * GET /api/adl/boundaries.php?action=list&type=ARTCC
     */
    case 'list':
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
            shape_area
        FROM adl_boundary WHERE is_active = 1";
        
        $params = [];
        if ($type) {
            $sql .= " AND boundary_type = ?";
            $params[] = $type;
        }
        if ($artcc) {
            $sql .= " AND (parent_artcc = ? OR boundary_code = ?)";
            $params[] = strtoupper($artcc);
            $params[] = strtoupper($artcc);
        }
        
        $sql .= " ORDER BY boundary_type, boundary_code";
        
        $stmt = sqlsrv_query($conn, $sql, $params);
        $boundaries = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $boundaries[] = $row;
        }
        
        echo json_encode([
            'success' => true,
            'count' => count($boundaries),
            'boundaries' => $boundaries
        ]);
        break;
    
    /**
     * Get GeoJSON for map display
     * GET /api/adl/boundaries.php?action=geojson&type=ARTCC
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
            $sql .= " AND boundary_type = ?";
            $params[] = $type;
        }
        if ($artcc) {
            $sql .= " AND (parent_artcc = ? OR boundary_code = ?)";
            $params[] = strtoupper($artcc);
            $params[] = strtoupper($artcc);
        }
        
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        $features = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
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
     * Get all boundaries containing a point
     * GET /api/adl/boundaries.php?action=contains&lat=38.8977&lon=-77.0365
     * 
     * Returns ALL overlapping sectors by type
     */
    case 'contains':
        $lat = floatval($_GET['lat'] ?? 0);
        $lon = floatval($_GET['lon'] ?? 0);
        $alt = isset($_GET['alt']) ? intval($_GET['alt']) : null;
        
        if ($lat == 0 || $lon == 0) {
            echo json_encode(['error' => 'Invalid coordinates']);
            break;
        }
        
        // Find ARTCC (single, prefer non-oceanic)
        $sql = "SELECT TOP 1 
            boundary_id, boundary_code, boundary_name, is_oceanic
        FROM adl_boundary
        WHERE boundary_type = 'ARTCC'
          AND is_active = 1
          AND boundary_geography.STContains(geography::Point(?, ?, 4326)) = 1
        ORDER BY is_oceanic ASC, boundary_geography.STArea() ASC";
        
        $stmt = sqlsrv_query($conn, $sql, [$lat, $lon]);
        $artcc = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        
        // Find ALL low sectors
        $sql = "SELECT 
            boundary_id, boundary_code, boundary_name, parent_artcc,
            floor_altitude, ceiling_altitude
        FROM adl_boundary
        WHERE boundary_type = 'SECTOR_LOW'
          AND is_active = 1
          AND boundary_geography.STContains(geography::Point(?, ?, 4326)) = 1
        ORDER BY boundary_code";
        
        $stmt = sqlsrv_query($conn, $sql, [$lat, $lon]);
        $sectors_low = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $sectors_low[] = $row;
        }
        
        // Find ALL high sectors
        $sql = "SELECT 
            boundary_id, boundary_code, boundary_name, parent_artcc,
            floor_altitude, ceiling_altitude
        FROM adl_boundary
        WHERE boundary_type = 'SECTOR_HIGH'
          AND is_active = 1
          AND boundary_geography.STContains(geography::Point(?, ?, 4326)) = 1
        ORDER BY boundary_code";
        
        $stmt = sqlsrv_query($conn, $sql, [$lat, $lon]);
        $sectors_high = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $sectors_high[] = $row;
        }
        
        // Find ALL superhigh sectors
        $sql = "SELECT 
            boundary_id, boundary_code, boundary_name, parent_artcc,
            floor_altitude, ceiling_altitude
        FROM adl_boundary
        WHERE boundary_type = 'SECTOR_SUPERHIGH'
          AND is_active = 1
          AND boundary_geography.STContains(geography::Point(?, ?, 4326)) = 1
        ORDER BY boundary_code";
        
        $stmt = sqlsrv_query($conn, $sql, [$lat, $lon]);
        $sectors_superhigh = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $sectors_superhigh[] = $row;
        }
        
        // Find TRACON (below FL180 only)
        $tracon = null;
        if ($alt === null || $alt < 18000) {
            $sql = "SELECT TOP 1 
                boundary_id, boundary_code, boundary_name, parent_artcc
            FROM adl_boundary
            WHERE boundary_type = 'TRACON'
              AND is_active = 1
              AND boundary_geography.STContains(geography::Point(?, ?, 4326)) = 1
            ORDER BY boundary_geography.STArea() ASC";
            
            $stmt = sqlsrv_query($conn, $sql, [$lat, $lon]);
            $tracon = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        }
        
        echo json_encode([
            'success' => true,
            'position' => ['lat' => $lat, 'lon' => $lon, 'alt' => $alt],
            'artcc' => $artcc ?: null,
            'sectors_low' => $sectors_low,
            'sectors_high' => $sectors_high,
            'sectors_superhigh' => $sectors_superhigh,
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
        
        $stmt = sqlsrv_query($conn, $sql);
        $summary = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $summary[] = $row;
        }
        
        echo json_encode([
            'success' => true,
            'summary' => $summary
        ]);
        break;
    
    /**
     * Get flight boundary status
     * GET /api/adl/boundaries.php?action=flight&callsign=AAL123
     * 
     * Returns multi-sector assignments
     */
    case 'flight':
        $flightUid = $_GET['uid'] ?? null;
        $callsign = $_GET['callsign'] ?? null;
        
        $sql = "SELECT 
            fc.flight_uid,
            fc.callsign,
            fc.current_artcc,
            ab1.boundary_name as artcc_name,
            fc.current_sector_low,
            fc.current_sector_low_ids,
            fc.current_sector_high,
            fc.current_sector_high_ids,
            fc.current_sector_superhigh,
            fc.current_sector_superhigh_ids,
            fc.current_tracon,
            ab2.boundary_name as tracon_name,
            fc.boundary_updated_at,
            fp.lat as latitude,
            fp.lon as longitude,
            fp.altitude_ft as altitude
        FROM adl_flight_core fc
        LEFT JOIN adl_flight_position fp ON fc.flight_uid = fp.flight_uid
        LEFT JOIN adl_boundary ab1 ON fc.current_artcc_id = ab1.boundary_id
        LEFT JOIN adl_boundary ab2 ON fc.current_tracon_id = ab2.boundary_id
        WHERE ";
        
        $params = [];
        if ($flightUid) {
            $sql .= "fc.flight_uid = ?";
            $params[] = $flightUid;
        } elseif ($callsign) {
            $sql .= "fc.callsign = ? AND fc.is_active = 1";
            $params[] = strtoupper($callsign);
        } else {
            echo json_encode(['error' => 'Flight UID or callsign required']);
            break;
        }
        
        $stmt = sqlsrv_query($conn, $sql, $params);
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        
        if ($row) {
            // Parse JSON IDs if present
            $flight = [
                'flight_uid' => (int)$row['flight_uid'],
                'callsign' => $row['callsign'],
                'position' => [
                    'lat' => $row['latitude'] ? (float)$row['latitude'] : null,
                    'lon' => $row['longitude'] ? (float)$row['longitude'] : null,
                    'altitude' => $row['altitude'] ? (int)$row['altitude'] : null
                ],
                'artcc' => [
                    'code' => $row['current_artcc'],
                    'name' => $row['artcc_name']
                ],
                'sectors_low' => $row['current_sector_low'] 
                    ? explode(',', $row['current_sector_low']) 
                    : [],
                'sectors_high' => $row['current_sector_high'] 
                    ? explode(',', $row['current_sector_high']) 
                    : [],
                'sectors_superhigh' => $row['current_sector_superhigh'] 
                    ? explode(',', $row['current_sector_superhigh']) 
                    : [],
                'tracon' => [
                    'code' => $row['current_tracon'],
                    'name' => $row['tracon_name']
                ],
                'boundary_updated_at' => $row['boundary_updated_at'] 
                    ? $row['boundary_updated_at']->format('Y-m-d H:i:s') 
                    : null
            ];
            
            echo json_encode([
                'success' => true,
                'flight' => $flight
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'flight' => null
            ]);
        }
        break;
    
    default:
        echo json_encode(['error' => 'Unknown action']);
}

sqlsrv_close($conn);

/**
 * Convert WKT to GeoJSON geometry
 */
function wktToGeoJson($wkt) {
    if (!$wkt) return null;
    
    if (preg_match('/^POLYGON\s*\((.+)\)$/is', $wkt, $matches)) {
        return [
            'type' => 'Polygon',
            'coordinates' => parsePolygonCoords($matches[1])
        ];
    } elseif (preg_match('/^MULTIPOLYGON\s*\((.+)\)$/is', $wkt, $matches)) {
        return [
            'type' => 'MultiPolygon',
            'coordinates' => parseMultiPolygonCoords($matches[1])
        ];
    }
    
    return null;
}

function parsePolygonCoords($str) {
    $rings = [];
    preg_match_all('/\(([^()]+)\)/', $str, $ringMatches);
    
    foreach ($ringMatches[1] as $ringStr) {
        $ring = [];
        $points = explode(',', $ringStr);
        foreach ($points as $point) {
            $coords = preg_split('/\s+/', trim($point));
            if (count($coords) >= 2) {
                $ring[] = [(float)$coords[0], (float)$coords[1]];
            }
        }
        $rings[] = $ring;
    }
    
    return $rings;
}

function parseMultiPolygonCoords($str) {
    $polygons = [];
    preg_match_all('/\(\(([^)]+(?:\),[^)]+)*)\)\)/', $str, $polyMatches);
    
    foreach ($polyMatches[0] as $polyStr) {
        $polyStr = trim($polyStr, '()');
        $polygons[] = parsePolygonCoords($polyStr);
    }
    
    if (empty($polygons)) {
        $polygons[] = parsePolygonCoords($str);
    }
    
    return $polygons;
}
