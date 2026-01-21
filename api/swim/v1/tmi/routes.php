<?php
/**
 * VATSWIM API v1 - TMI Public Routes Endpoint
 *
 * CRUD operations for public route display data.
 * Data from VATSIM_TMI database (tmi_public_routes table).
 *
 * GET    /api/swim/v1/tmi/routes              - List routes (public)
 * GET    /api/swim/v1/tmi/routes?format=geojson
 * GET    /api/swim/v1/tmi/routes?id=123       - Get single route
 * POST   /api/swim/v1/tmi/routes              - Create route (auth required)
 * PUT    /api/swim/v1/tmi/routes?id=123       - Update route (auth required)
 * DELETE /api/swim/v1/tmi/routes?id=123       - Delete route (auth required)
 *
 * @version 2.0.0
 */

require_once __DIR__ . '/../auth.php';

// TMI database connection
global $conn_tmi;

if (!$conn_tmi) {
    SwimResponse::error('TMI database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

// Route by HTTP method
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id = swim_get_param('id');

switch ($method) {
    case 'GET':
        if ($id) {
            handleGetSingle($id);
        } else {
            handleGetList();
        }
        break;

    case 'POST':
        handleCreate();
        break;

    case 'PUT':
    case 'PATCH':
        if (!$id) SwimResponse::error('Route ID required', 400, 'MISSING_ID');
        handleUpdate($id);
        break;

    case 'DELETE':
        if (!$id) SwimResponse::error('Route ID required', 400, 'MISSING_ID');
        handleDelete($id);
        break;

    default:
        SwimResponse::error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
}

// ============================================================================
// GET - List Routes (Public Access)
// ============================================================================
function handleGetList() {
    global $conn_tmi;

    $auth = swim_init_auth(false, false);  // Public access allowed

    $filter = swim_get_param('filter', 'active');
    $origin = swim_get_param('origin');
    $dest = swim_get_param('dest');
    $format = swim_get_param('format', 'json');

    $where_clauses = [];
    $params = [];

    switch ($filter) {
        case 'active':
            $where_clauses[] = "r.status = 1";
            $where_clauses[] = "(r.valid_start_utc IS NULL OR r.valid_start_utc <= GETUTCDATE())";
            $where_clauses[] = "(r.valid_end_utc IS NULL OR r.valid_end_utc > GETUTCDATE())";
            break;
        case 'future':
            $where_clauses[] = "r.valid_start_utc > GETUTCDATE()";
            break;
        case 'past':
            $where_clauses[] = "r.valid_end_utc < GETUTCDATE()";
            break;
        case 'all':
        default:
            break;
    }

    if ($origin) {
        $where_clauses[] = "(r.origin_filter LIKE '%' + ? + '%' OR r.constrained_area = ?)";
        $params[] = $origin;
        $params[] = $origin;
    }

    if ($dest) {
        $where_clauses[] = "r.dest_filter LIKE '%' + ? + '%'";
        $params[] = $dest;
    }

    $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

    $sql = "
        SELECT
            r.route_id, r.route_guid, r.status, r.name, r.adv_number,
            r.advisory_id, r.reroute_id, r.route_string, r.advisory_text,
            r.color, r.line_weight, r.line_style,
            r.valid_start_utc, r.valid_end_utc,
            r.constrained_area, r.reason, r.origin_filter, r.dest_filter, r.facilities,
            r.route_geojson, r.created_by, r.created_at, r.updated_at
        FROM dbo.tmi_public_routes r
        $where_sql
        ORDER BY r.status DESC, r.valid_start_utc DESC
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

    if ($format === 'geojson') {
        outputGeoJSON($routes);
    } else {
        outputJSON($routes, $filter);
    }
}

// ============================================================================
// GET - Single Route
// ============================================================================
function handleGetSingle($id) {
    global $conn_tmi;

    $auth = swim_init_auth(false, false);

    $sql = "SELECT * FROM dbo.tmi_public_routes WHERE route_id = ?";
    $stmt = sqlsrv_query($conn_tmi, $sql, [$id]);

    if ($stmt === false) {
        SwimResponse::error('Database error', 500, 'DB_ERROR');
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$row) {
        SwimResponse::error('Route not found', 404, 'NOT_FOUND');
    }

    $format = swim_get_param('format', 'json');
    if ($format === 'geojson') {
        outputGeoJSON([$row]);
    } else {
        SwimResponse::success(formatRoute($row));
    }
}

// ============================================================================
// POST - Create Route (Auth Required)
// ============================================================================
function handleCreate() {
    global $conn_tmi;

    $auth = swim_init_auth(true, true);  // Require auth and write permission

    $body = swim_get_json_body();
    if (!$body) {
        SwimResponse::error('Request body required', 400, 'MISSING_BODY');
    }

    // Validate required fields
    $required = ['name', 'route_string', 'valid_start_utc', 'valid_end_utc'];
    foreach ($required as $field) {
        if (empty($body[$field])) {
            SwimResponse::error("Missing required field: $field", 400, 'MISSING_FIELD');
        }
    }

    // Build insert
    $sql = "INSERT INTO dbo.tmi_public_routes (
                status, name, adv_number, route_string, advisory_text,
                color, line_weight, line_style,
                valid_start_utc, valid_end_utc,
                constrained_area, reason, origin_filter, dest_filter, facilities,
                route_geojson, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);
            SELECT SCOPE_IDENTITY() AS route_id;";

    $params = [
        isset($body['status']) ? (int)$body['status'] : 1,
        $body['name'],
        $body['adv_number'] ?? null,
        $body['route_string'],
        $body['advisory_text'] ?? null,
        $body['color'] ?? '#e74c3c',
        isset($body['line_weight']) ? (int)$body['line_weight'] : 3,
        $body['line_style'] ?? 'solid',
        $body['valid_start_utc'],
        $body['valid_end_utc'],
        $body['constrained_area'] ?? null,
        $body['reason'] ?? null,
        isset($body['origin_filter']) ? (is_array($body['origin_filter']) ? json_encode($body['origin_filter']) : $body['origin_filter']) : null,
        isset($body['dest_filter']) ? (is_array($body['dest_filter']) ? json_encode($body['dest_filter']) : $body['dest_filter']) : null,
        $body['facilities'] ?? null,
        isset($body['route_geojson']) ? (is_array($body['route_geojson']) ? json_encode($body['route_geojson']) : $body['route_geojson']) : null,
        $body['created_by'] ?? $auth->getKeyInfo()['owner_name'] ?? 'API'
    ];

    $stmt = sqlsrv_query($conn_tmi, $sql, $params);
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        SwimResponse::error('Failed to create route: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
    }

    sqlsrv_next_result($stmt);
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $newId = $row['route_id'] ?? null;
    sqlsrv_free_stmt($stmt);

    if (!$newId) {
        SwimResponse::error('Failed to get new route ID', 500, 'DB_ERROR');
    }

    // Fetch and return created route
    $sql = "SELECT * FROM dbo.tmi_public_routes WHERE route_id = ?";
    $stmt = sqlsrv_query($conn_tmi, $sql, [$newId]);
    $created = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    http_response_code(201);
    SwimResponse::success(formatRoute($created), 'Route created');
}

// ============================================================================
// PUT - Update Route (Auth Required)
// ============================================================================
function handleUpdate($id) {
    global $conn_tmi;

    $auth = swim_init_auth(true, true);

    $body = swim_get_json_body();
    if (!$body) {
        SwimResponse::error('Request body required', 400, 'MISSING_BODY');
    }

    // Check route exists
    $check = sqlsrv_query($conn_tmi, "SELECT route_id FROM dbo.tmi_public_routes WHERE route_id = ?", [$id]);
    if (!$check || !sqlsrv_fetch_array($check, SQLSRV_FETCH_ASSOC)) {
        SwimResponse::error('Route not found', 404, 'NOT_FOUND');
    }
    sqlsrv_free_stmt($check);

    // Build dynamic update
    $updates = ['updated_at = GETUTCDATE()'];
    $params = [];

    $allowed = [
        'status', 'name', 'adv_number', 'route_string', 'advisory_text',
        'color', 'line_weight', 'line_style',
        'valid_start_utc', 'valid_end_utc',
        'constrained_area', 'reason', 'origin_filter', 'dest_filter', 'facilities',
        'route_geojson'
    ];

    foreach ($allowed as $field) {
        if (isset($body[$field])) {
            $updates[] = "$field = ?";
            $value = $body[$field];

            if (in_array($field, ['origin_filter', 'dest_filter', 'route_geojson']) && is_array($value)) {
                $value = json_encode($value);
            }
            if (in_array($field, ['status', 'line_weight'])) {
                $value = (int)$value;
            }

            $params[] = $value;
        }
    }

    $params[] = $id;  // WHERE clause param

    $sql = "UPDATE dbo.tmi_public_routes SET " . implode(', ', $updates) . " WHERE route_id = ?";
    $stmt = sqlsrv_query($conn_tmi, $sql, $params);

    if ($stmt === false) {
        $errors = sqlsrv_errors();
        SwimResponse::error('Failed to update route: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
    }
    sqlsrv_free_stmt($stmt);

    // Fetch and return updated route
    $sql = "SELECT * FROM dbo.tmi_public_routes WHERE route_id = ?";
    $stmt = sqlsrv_query($conn_tmi, $sql, [$id]);
    $updated = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    SwimResponse::success(formatRoute($updated), 'Route updated');
}

// ============================================================================
// DELETE - Delete/Expire Route (Auth Required)
// ============================================================================
function handleDelete($id) {
    global $conn_tmi;

    $auth = swim_init_auth(true, true);

    // Check route exists
    $check = sqlsrv_query($conn_tmi, "SELECT route_id FROM dbo.tmi_public_routes WHERE route_id = ?", [$id]);
    if (!$check || !sqlsrv_fetch_array($check, SQLSRV_FETCH_ASSOC)) {
        SwimResponse::error('Route not found', 404, 'NOT_FOUND');
    }
    sqlsrv_free_stmt($check);

    $hard = swim_get_param('hard') === '1';

    if ($hard) {
        $sql = "DELETE FROM dbo.tmi_public_routes WHERE route_id = ?";
        $message = 'Route permanently deleted';
    } else {
        $sql = "UPDATE dbo.tmi_public_routes SET status = 0, updated_at = GETUTCDATE() WHERE route_id = ?";
        $message = 'Route deactivated';
    }

    $stmt = sqlsrv_query($conn_tmi, $sql, [$id]);
    if ($stmt === false) {
        SwimResponse::error('Failed to delete route', 500, 'DB_ERROR');
    }
    sqlsrv_free_stmt($stmt);

    SwimResponse::success(['route_id' => (int)$id, 'action' => $hard ? 'deleted' : 'deactivated'], $message);
}


function outputJSON($routes, $filter = 'all') {
    $formatted = [];
    $stats = [
        'by_status' => ['active' => 0, 'future' => 0, 'past' => 0],
        'total' => count($routes)
    ];

    foreach ($routes as $row) {
        $route = formatRoute($row);
        $formatted[] = $route;

        // Count by computed status
        $status = $route['computed_status'] ?? 'active';
        $stats['by_status'][$status] = ($stats['by_status'][$status] ?? 0) + 1;
    }

    // Legacy-compatible response format (matches old api/routes/public.php)
    $response = [
        'success' => true,
        'filter' => $filter,
        'count' => count($formatted),
        'routes' => $formatted,  // Legacy field name
        'data' => $formatted,    // VATSWIM field name
        'statistics' => $stats,
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'meta' => [
            'source' => 'vatsim_tmi',
            'table' => 'tmi_public_routes'
        ]
    ];

    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
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
    $originFilter = !empty($row['origin_filter']) ? json_decode($row['origin_filter'], true) : [];
    $destFilter = !empty($row['dest_filter']) ? json_decode($row['dest_filter'], true) : [];
    $routeGeojson = !empty($row['route_geojson']) ? json_decode($row['route_geojson'], true) : null;

    // Compute status based on time
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $validStart = $row['valid_start_utc'] instanceof DateTime ? $row['valid_start_utc'] : null;
    $validEnd = $row['valid_end_utc'] instanceof DateTime ? $row['valid_end_utc'] : null;

    $computedStatus = 'active';
    if ($validStart && $validStart > $now) {
        $computedStatus = 'future';
    } elseif ($validEnd && $validEnd < $now) {
        $computedStatus = 'past';
    }

    return [
        // VATSWIM format
        'route_id' => $row['route_id'],
        'route_guid' => $row['route_guid'],
        'name' => $row['name'],
        'adv_number' => $row['adv_number'],

        'route' => [
            'string' => $row['route_string'],
            'advisory_text' => $row['advisory_text']
        ],

        'scope' => [
            'constrained_area' => $row['constrained_area'],
            'origin_filter' => $originFilter,
            'dest_filter' => $destFilter,
            'facilities' => $row['facilities']
        ],

        'display' => [
            'color' => $row['color'] ?? '#e74c3c',
            'weight' => (int)($row['line_weight'] ?? 3),
            'style' => $row['line_style'] ?? 'solid'
        ],

        'validity' => [
            'status' => (int)$row['status'],
            'computed_status' => $computedStatus,
            'start_utc' => formatDT($row['valid_start_utc']),
            'end_utc' => formatDT($row['valid_end_utc'])
        ],

        'reason' => $row['reason'],

        'links' => [
            'advisory_id' => $row['advisory_id'],
            'reroute_id' => $row['reroute_id']
        ],

        'geometry' => $routeGeojson,

        'metadata' => [
            'created_by' => $row['created_by'],
            'created_at' => formatDT($row['created_at']),
            'updated_at' => formatDT($row['updated_at'])
        ],

        // Legacy compatibility fields (for public-routes.js)
        'id' => $row['route_id'],
        'status' => (int)$row['status'],
        'route_string' => $row['route_string'],
        'advisory_text' => $row['advisory_text'],
        'color' => $row['color'] ?? '#e74c3c',
        'line_weight' => (int)($row['line_weight'] ?? 3),
        'line_style' => $row['line_style'] ?? 'solid',
        'valid_start_utc' => formatDT($row['valid_start_utc']),
        'valid_end_utc' => formatDT($row['valid_end_utc']),
        'constrained_area' => $row['constrained_area'],
        'origin_filter' => $originFilter,
        'dest_filter' => $destFilter,
        'facilities' => $row['facilities'],
        'route_geojson' => $routeGeojson,
        'computed_status' => $computedStatus
    ];
}


function formatGeoJSONFeature($row) {
    // Try to get geometry from route_geojson
    $geometry = null;

    if (!empty($row['route_geojson'])) {
        $geojson = json_decode($row['route_geojson'], true);
        // route_geojson might be a Feature, FeatureCollection, or raw geometry
        if (isset($geojson['geometry'])) {
            $geometry = $geojson['geometry'];
        } elseif (isset($geojson['type']) && in_array($geojson['type'], ['LineString', 'MultiLineString', 'Point'])) {
            $geometry = $geojson;
        } elseif (isset($geojson['features'][0]['geometry'])) {
            $geometry = $geojson['features'][0]['geometry'];
        }
    }

    // Skip routes without valid geometry
    if (!$geometry) {
        return null;
    }

    // Parse filter arrays
    $originFilter = !empty($row['origin_filter']) ? json_decode($row['origin_filter'], true) : [];
    $destFilter = !empty($row['dest_filter']) ? json_decode($row['dest_filter'], true) : [];

    // Compute status
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $validStart = $row['valid_start_utc'] instanceof DateTime ? $row['valid_start_utc'] : null;
    $validEnd = $row['valid_end_utc'] instanceof DateTime ? $row['valid_end_utc'] : null;
    $isActive = $row['status'] == 1 && (!$validStart || $validStart <= $now) && (!$validEnd || $validEnd > $now);

    return [
        'type' => 'Feature',
        'id' => $row['route_id'],
        'geometry' => $geometry,
        'properties' => [
            'route_id' => $row['route_id'],
            'name' => $row['name'],
            'adv_number' => $row['adv_number'],
            'route_string' => $row['route_string'],
            'advisory_text' => $row['advisory_text'],
            'constrained_area' => $row['constrained_area'],
            'origin_filter' => is_array($originFilter) ? implode(',', $originFilter) : $originFilter,
            'dest_filter' => is_array($destFilter) ? implode(',', $destFilter) : $destFilter,
            'facilities' => $row['facilities'],
            'reason' => $row['reason'],
            'is_active' => $isActive,
            'valid_start_utc' => formatDT($row['valid_start_utc']),
            'valid_end_utc' => formatDT($row['valid_end_utc']),
            'color' => $row['color'] ?? '#e74c3c',
            'weight' => (int)($row['line_weight'] ?? 3),
            'style' => $row['line_style'] ?? 'solid'
        ]
    ];
}


function formatDT($dt) {
    if ($dt === null) return null;
    return ($dt instanceof DateTime) ? $dt->format('c') : $dt;
}
