<?php
/**
 * Phase 5E.2: Boundary API Endpoint
 * /api/adl/boundaries.php
 * 
 * Provides boundary data for map display and flight tracking
 * Updated: Multi-sector support with overlap detection
 */

header('Content-Type: application/json');
perti_set_cors();
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once(__DIR__ . "/../../load/config.php");
require_once(__DIR__ . "/../../load/input.php");

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

$action = get_lower('action') ?: 'list';

switch ($action) {

    /**
     * List boundaries by type
     * GET /api/adl/boundaries.php?action=list&type=ARTCC
     * GET /api/adl/boundaries.php?action=list&type=ARTCC&include_sub=1  (includes ARTCC_SUB)
     */
    case 'list':
        $type = has_get('type') ? get_upper('type') : null;
        $artcc = has_get('artcc') ? get_upper('artcc') : null;
        $includeSub = has_get('include_sub') ? (int)get_input('include_sub') : 0;

        $level = has_get('level') ? get_int('level') : null;

        $sql = "SELECT
            boundary_id,
            boundary_type,
            boundary_code,
            boundary_name,
            parent_artcc,
            parent_fir,
            sector_number,
            icao_code,
            vatsim_region,
            vatsim_division,
            is_oceanic,
            floor_altitude,
            ceiling_altitude,
            label_lat,
            label_lon,
            shape_area,
            hierarchy_level,
            hierarchy_type,
            CASE WHEN boundary_type LIKE 'ARTCC_SUB%' THEN 1 ELSE 0 END as is_sub_area
        FROM adl_boundary WHERE is_active = 1";

        $params = [];
        if ($type) {
            if ($type === 'ARTCC' && $includeSub) {
                $sql .= " AND boundary_type LIKE 'ARTCC%'";
            } else {
                $sql .= " AND boundary_type = ?";
                $params[] = $type;
            }
        }
        if ($artcc) {
            $sql .= " AND (parent_artcc = ? OR parent_fir = ? OR boundary_code = ?)";
            $params[] = strtoupper($artcc);
            $params[] = strtoupper($artcc);
            $params[] = strtoupper($artcc);
        }
        if ($level !== null) {
            $sql .= " AND hierarchy_level = ?";
            $params[] = $level;
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
     * GET /api/adl/boundaries.php?action=geojson&type=ARTCC&include_sub=1
     */
    case 'geojson':
        $type = has_get('type') ? get_upper('type') : null;
        $artcc = has_get('artcc') ? get_upper('artcc') : null;
        $includeSub = has_get('include_sub') ? (int)get_input('include_sub') : 0;
        $level = has_get('level') ? get_int('level') : null;

        $sql = "SELECT
            boundary_id,
            boundary_type,
            boundary_code,
            boundary_name,
            parent_artcc,
            parent_fir,
            sector_number,
            icao_code,
            vatsim_region,
            vatsim_division,
            is_oceanic,
            floor_altitude,
            ceiling_altitude,
            label_lat,
            label_lon,
            hierarchy_level,
            hierarchy_type,
            boundary_geography.STAsText() as geometry_wkt
        FROM adl_boundary
        WHERE is_active = 1";

        $params = [];
        if ($type) {
            if ($type === 'ARTCC' && $includeSub) {
                $sql .= " AND boundary_type LIKE 'ARTCC%'";
            } else {
                $sql .= " AND boundary_type = ?";
                $params[] = $type;
            }
        }
        if ($artcc) {
            $sql .= " AND (parent_artcc = ? OR parent_fir = ? OR boundary_code = ?)";
            $params[] = strtoupper($artcc);
            $params[] = strtoupper($artcc);
            $params[] = strtoupper($artcc);
        }
        if ($level !== null) {
            $sql .= " AND hierarchy_level = ?";
            $params[] = $level;
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
                    'parent_fir' => $row['parent_fir'],
                    'is_sub_area' => (strpos($row['boundary_type'], 'ARTCC_SUB') === 0),
                    'sector_number' => $row['sector_number'],
                    'icao_code' => $row['icao_code'],
                    'vatsim_region' => $row['vatsim_region'],
                    'vatsim_division' => $row['vatsim_division'],
                    'is_oceanic' => (bool)$row['is_oceanic'],
                    'floor_altitude' => $row['floor_altitude'] ? (int)$row['floor_altitude'] : null,
                    'ceiling_altitude' => $row['ceiling_altitude'] ? (int)$row['ceiling_altitude'] : null,
                    'label_lat' => $row['label_lat'] ? (float)$row['label_lat'] : null,
                    'label_lon' => $row['label_lon'] ? (float)$row['label_lon'] : null,
                    'hierarchy_level' => $row['hierarchy_level'] !== null ? (int)$row['hierarchy_level'] : null,
                    'hierarchy_type' => $row['hierarchy_type']
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
        $lat = get_float('lat');
        $lon = get_float('lon');
        $alt = has_get('alt') ? get_int('alt') : null;
        
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
        $flightUid = has_get('uid') ? get_input('uid') : null;
        $callsign = has_get('callsign') ? get_upper('callsign') : null;
        
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
    
    /**
     * Get boundary hierarchy subtree
     * GET /api/adl/boundaries.php?action=hierarchy&code=EDGG&depth=5
     * Returns all descendants of the given boundary code
     */
    case 'hierarchy':
        $code = has_get('code') ? get_upper('code') : null;
        $depth = has_get('depth') ? get_int('depth') : 5;

        if (!$code) {
            echo json_encode(['error' => 'Boundary code required']);
            break;
        }

        // Find the root boundary
        $sql = "SELECT boundary_id, boundary_type, boundary_code, boundary_name,
                       hierarchy_level, hierarchy_type, parent_artcc, parent_fir
                FROM adl_boundary
                WHERE boundary_code = ? AND is_active = 1";
        $stmt = sqlsrv_query($conn, $sql, [$code]);
        $root = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if (!$root) {
            echo json_encode(['success' => true, 'root' => null, 'children' => []]);
            break;
        }

        // Recursive CTE to find all descendants
        $sql = "WITH hierarchy_cte AS (
                    SELECT bh.child_boundary_id, bh.parent_boundary_id,
                           bh.child_code, bh.parent_code, bh.relationship_type, bh.coverage_ratio,
                           1 as depth
                    FROM boundary_hierarchy bh
                    WHERE bh.parent_code = ?
                    UNION ALL
                    SELECT bh2.child_boundary_id, bh2.parent_boundary_id,
                           bh2.child_code, bh2.parent_code, bh2.relationship_type, bh2.coverage_ratio,
                           h.depth + 1
                    FROM boundary_hierarchy bh2
                    INNER JOIN hierarchy_cte h ON bh2.parent_boundary_id = h.child_boundary_id
                    WHERE h.depth < ?
                )
                SELECT h.child_code, h.parent_code, h.relationship_type, h.coverage_ratio, h.depth,
                       b.boundary_type, b.boundary_name, b.hierarchy_level, b.hierarchy_type
                FROM hierarchy_cte h
                INNER JOIN adl_boundary b ON h.child_boundary_id = b.boundary_id
                ORDER BY h.depth, h.child_code";

        $stmt = sqlsrv_query($conn, $sql, [$code, $depth]);
        $children = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $children[] = [
                'code' => $row['child_code'],
                'parent_code' => $row['parent_code'],
                'name' => $row['boundary_name'],
                'boundary_type' => $row['boundary_type'],
                'hierarchy_level' => $row['hierarchy_level'] !== null ? (int)$row['hierarchy_level'] : null,
                'hierarchy_type' => $row['hierarchy_type'],
                'relationship' => $row['relationship_type'],
                'coverage' => $row['coverage_ratio'] !== null ? (float)$row['coverage_ratio'] : null,
                'depth' => (int)$row['depth']
            ];
        }
        sqlsrv_free_stmt($stmt);

        echo json_encode([
            'success' => true,
            'root' => [
                'code' => $root['boundary_code'],
                'name' => $root['boundary_name'],
                'boundary_type' => $root['boundary_type'],
                'hierarchy_level' => $root['hierarchy_level'] !== null ? (int)$root['hierarchy_level'] : null,
                'hierarchy_type' => $root['hierarchy_type']
            ],
            'children' => $children,
            'count' => count($children)
        ]);
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
