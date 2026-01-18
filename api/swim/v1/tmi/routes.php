<?php
/**
 * VATSWIM API v1 - TMI Public Routes Endpoint
 * 
 * Returns public route display data for map visualization.
 * Data from VATSIM_TMI database (tmi_public_routes table).
 * Supports GeoJSON output for direct map integration.
 * 
 * GET /api/swim/v1/tmi/routes
 * GET /api/swim/v1/tmi/routes?format=geojson
 * GET /api/swim/v1/tmi/routes?type=playbook
 * GET /api/swim/v1/tmi/routes?active_only=1
 * 
 * @version 1.0.0
 */

require_once __DIR__ . '/../auth.php';

// TMI database connection
global $conn_tmi;

if (!$conn_tmi) {
    SwimResponse::error('TMI database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

$auth = swim_init_auth(false, false);  // Public access allowed

// Get filter parameters
$type = swim_get_param('type');              // Route type filter (playbook, cdr, reroute)
$origin = swim_get_param('origin');           // Origin filter
$dest = swim_get_param('dest');               // Destination filter
$status = swim_get_param('status');           // Filter by status
$active_only = swim_get_param('active_only', 'true') === 'true';
$format = swim_get_param('format', 'json');   // Output format (json, geojson)

// Build query
$where_clauses = [];
$params = [];

if ($active_only) {
    $where_clauses[] = "r.is_active = 1";
    $where_clauses[] = "(r.valid_until IS NULL OR r.valid_until > GETUTCDATE())";
}

if ($type) {
    $type_list = array_map('trim', explode(',', strtolower($type)));
    $placeholders = implode(',', array_fill(0, count($type_list), '?'));
    $where_clauses[] = "r.route_type IN ($placeholders)";
    $params = array_merge($params, $type_list);
}

if ($origin) {
    $origin_list = array_map('trim', explode(',', strtoupper($origin)));
    $placeholders = implode(',', array_fill(0, count($origin_list), '?'));
    $where_clauses[] = "(r.origin_facility IN ($placeholders) OR r.origin_airports LIKE '%' + ? + '%')";
    $params = array_merge($params, $origin_list, $origin_list);
}

if ($dest) {
    $dest_list = array_map('trim', explode(',', strtoupper($dest)));
    $placeholders = implode(',', array_fill(0, count($dest_list), '?'));
    $where_clauses[] = "(r.dest_facility IN ($placeholders) OR r.dest_airports LIKE '%' + ? + '%')";
    $params = array_merge($params, $dest_list, $dest_list);
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Main query
$sql = "
    SELECT 
        r.route_id,
        r.route_guid,
        r.route_name,
        r.route_type,
        r.origin_facility,
        r.origin_airports,
        r.dest_facility,
        r.dest_airports,
        r.route_string,
        r.protected_segment,
        r.avoid_segment,
        r.geometry_json,
        r.fix_coords_json,
        r.display_color,
        r.display_weight,
        r.display_opacity,
        r.is_active,
        r.valid_from,
        r.valid_until,
        r.reason_code,
        r.reason_detail,
        r.source_type,
        r.source_id,
        r.created_at
    FROM dbo.tmi_public_routes r
    $where_sql
    ORDER BY r.route_type, r.route_name
";

$stmt = sqlsrv_query($conn_tmi, $sql, $params);
if ($stmt === false) {
    $errors = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}

$routes = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $routes[] = $row;
}
sqlsrv_free_stmt($stmt);

// Output based on format
if ($format === 'geojson') {
    outputGeoJSON($routes);
} else {
    outputJSON($routes);
}


function outputJSON($routes) {
    $formatted = [];
    $stats = [
        'by_type' => [],
        'total' => count($routes)
    ];
    
    foreach ($routes as $row) {
        $formatted[] = formatRoute($row);
        
        $type = $row['route_type'];
        $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;
    }
    
    $response = [
        'success' => true,
        'data' => $formatted,
        'statistics' => $stats,
        'timestamp' => gmdate('c'),
        'meta' => [
            'source' => 'vatsim_tmi',
            'table' => 'tmi_public_routes'
        ]
    ];
    
    SwimResponse::json($response);
}


function outputGeoJSON($routes) {
    $features = [];
    
    foreach ($routes as $row) {
        $feature = formatGeoJSONFeature($row);
        if ($feature) {
            $features[] = $feature;
        }
    }
    
    $geoJson = [
        'type' => 'FeatureCollection',
        'features' => $features,
        'metadata' => [
            'generated' => gmdate('c'),
            'source' => 'vatsim_tmi',
            'count' => count($features)
        ]
    ];
    
    header('Content-Type: application/geo+json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($geoJson);
    exit;
}


function formatRoute($row) {
    // Parse JSON fields
    $originAirports = !empty($row['origin_airports']) ? json_decode($row['origin_airports'], true) : [];
    $destAirports = !empty($row['dest_airports']) ? json_decode($row['dest_airports'], true) : [];
    $fixCoords = !empty($row['fix_coords_json']) ? json_decode($row['fix_coords_json'], true) : [];
    
    return [
        'route_id' => $row['route_id'],
        'route_guid' => $row['route_guid'],
        'name' => $row['route_name'],
        'type' => $row['route_type'],
        
        'origin' => [
            'facility' => $row['origin_facility'],
            'airports' => $originAirports
        ],
        
        'destination' => [
            'facility' => $row['dest_facility'],
            'airports' => $destAirports
        ],
        
        'route' => [
            'string' => $row['route_string'],
            'protected' => $row['protected_segment'],
            'avoid' => $row['avoid_segment']
        ],
        
        'geometry' => [
            'fix_coordinates' => $fixCoords
        ],
        
        'display' => [
            'color' => $row['display_color'] ?? '#3388ff',
            'weight' => $row['display_weight'] ?? 2,
            'opacity' => $row['display_opacity'] ?? 0.8
        ],
        
        'validity' => [
            'is_active' => (bool)$row['is_active'],
            'from' => formatDT($row['valid_from']),
            'until' => formatDT($row['valid_until'])
        ],
        
        'reason' => [
            'code' => $row['reason_code'],
            'detail' => $row['reason_detail']
        ],
        
        'source' => [
            'type' => $row['source_type'],
            'id' => $row['source_id']
        ],
        
        '_created_at' => formatDT($row['created_at'])
    ];
}


function formatGeoJSONFeature($row) {
    // Try to get geometry from geometry_json first
    $geometry = null;
    
    if (!empty($row['geometry_json'])) {
        $geometry = json_decode($row['geometry_json'], true);
    }
    
    // Fall back to building geometry from fix_coords_json
    if (!$geometry && !empty($row['fix_coords_json'])) {
        $fixCoords = json_decode($row['fix_coords_json'], true);
        if (is_array($fixCoords) && count($fixCoords) >= 2) {
            $coordinates = [];
            foreach ($fixCoords as $fix) {
                if (isset($fix['lon']) && isset($fix['lat'])) {
                    $coordinates[] = [floatval($fix['lon']), floatval($fix['lat'])];
                }
            }
            if (count($coordinates) >= 2) {
                $geometry = [
                    'type' => 'LineString',
                    'coordinates' => $coordinates
                ];
            }
        }
    }
    
    // Skip routes without valid geometry
    if (!$geometry) {
        return null;
    }
    
    // Parse airports
    $originAirports = !empty($row['origin_airports']) ? json_decode($row['origin_airports'], true) : [];
    $destAirports = !empty($row['dest_airports']) ? json_decode($row['dest_airports'], true) : [];
    
    return [
        'type' => 'Feature',
        'id' => $row['route_id'],
        'geometry' => $geometry,
        'properties' => [
            'route_id' => $row['route_id'],
            'name' => $row['route_name'],
            'type' => $row['route_type'],
            'origin_facility' => $row['origin_facility'],
            'origin_airports' => is_array($originAirports) ? implode(',', $originAirports) : $originAirports,
            'dest_facility' => $row['dest_facility'],
            'dest_airports' => is_array($destAirports) ? implode(',', $destAirports) : $destAirports,
            'route_string' => $row['route_string'],
            'protected_segment' => $row['protected_segment'],
            'is_active' => (bool)$row['is_active'],
            'reason' => $row['reason_code'],
            'color' => $row['display_color'] ?? '#3388ff',
            'weight' => $row['display_weight'] ?? 2,
            'opacity' => $row['display_opacity'] ?? 0.8
        ]
    ];
}


function formatDT($dt) {
    if ($dt === null) return null;
    return ($dt instanceof DateTime) ? $dt->format('c') : $dt;
}
