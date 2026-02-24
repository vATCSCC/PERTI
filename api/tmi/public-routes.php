<?php
/**
 * TMI Public Routes API
 * 
 * CRUD operations for public route display on the map.
 * Routes can be standalone or linked to advisories/reroutes.
 * 
 * Endpoints:
 *   GET    /api/tmi/public-routes.php           - List routes (with filters)
 *   GET    /api/tmi/public-routes.php?id=123    - Get single route
 *   POST   /api/tmi/public-routes.php           - Create new route
 *   PUT    /api/tmi/public-routes.php?id=123    - Update route
 *   DELETE /api/tmi/public-routes.php?id=123    - Delete/expire route
 * 
 * Query Parameters (GET list):
 *   status       - Filter by status (0=inactive, 1=active, 2=expired)
 *   active_only  - Set to 1 to show only currently active routes (default behavior)
 *   include_expired - Set to 1 to include expired routes
 *   geojson      - Set to 1 to return as GeoJSON FeatureCollection
 *   page         - Page number (default: 1)
 *   per_page     - Items per page (default: 100, max: 500)
 * 
 * @package PERTI
 * @subpackage TMI
 */

require_once __DIR__ . '/helpers.php';

$method = tmi_method();
$id = tmi_param('id');

switch ($method) {
    case 'GET':
        if ($id) {
            getRoute($id);
        } else {
            listRoutes();
        }
        break;
    
    case 'POST':
        createRoute();
        break;
    
    case 'PUT':
    case 'PATCH':
        if (!$id) TmiResponse::error('Route ID required', 400);
        updateRoute($id);
        break;
    
    case 'DELETE':
        if (!$id) TmiResponse::error('Route ID required', 400);
        deleteRoute($id);
        break;
    
    default:
        TmiResponse::error('Method not allowed', 405);
}

/**
 * List routes with filters
 */
function listRoutes() {
    global $conn_tmi;
    
    tmi_init(false);
    
    $where = [];
    $params = [];
    
    // Status filter
    $status = tmi_param('status');
    if ($status !== null) {
        $where[] = "status = ?";
        $params[] = (int)$status;
    }
    
    // Active only (default behavior unless include_expired)
    if (tmi_param('include_expired') !== '1' && $status === null) {
        $where[] = "status = 1";
        $where[] = "valid_end_utc > SYSUTCDATETIME()";
    }
    
    // Pagination
    $page = tmi_int_param('page', 1, 1);
    $per_page = tmi_int_param('per_page', 100, 1, 500);
    $offset = ($page - 1) * $per_page;
    
    $where_sql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Get total count
    $total = tmi_count('tmi_public_routes', implode(' AND ', $where), $params);
    
    // Get routes
    $sql = "SELECT 
                route_id, route_guid, status, name, adv_number,
                advisory_id, reroute_id, route_string, advisory_text,
                color, line_weight, line_style,
                valid_start_utc, valid_end_utc,
                constrained_area, reason, origin_filter, dest_filter, facilities,
                route_geojson, created_by, created_at, updated_at
            FROM dbo.tmi_public_routes
            $where_sql
            ORDER BY created_at DESC
            OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";
    
    $params[] = $offset;
    $params[] = $per_page;
    
    $result = sqlsrv_query($conn_tmi, $sql, $params);
    $routes = tmi_fetch_all($result);
    
    // Return as GeoJSON if requested
    if (tmi_param('geojson') === '1') {
        outputGeoJson($routes);
        return;
    }
    
    TmiResponse::paginated($routes, $total, $page, $per_page);
}

/**
 * Output routes as GeoJSON FeatureCollection
 */
function outputGeoJson($routes) {
    $features = [];
    
    foreach ($routes as $route) {
        // If we have cached GeoJSON, parse it
        if (!empty($route['route_geojson'])) {
            $geometry = json_decode($route['route_geojson'], true);
        } else {
            // Otherwise, we'd need to compute it from route_string
            // For now, return null geometry
            $geometry = null;
        }
        
        $features[] = [
            'type' => 'Feature',
            'id' => $route['route_id'],
            'geometry' => $geometry,
            'properties' => [
                'route_id' => $route['route_id'],
                'name' => $route['name'],
                'adv_number' => $route['adv_number'],
                'route_string' => $route['route_string'],
                'advisory_text' => $route['advisory_text'],
                'color' => $route['color'],
                'line_weight' => $route['line_weight'],
                'line_style' => $route['line_style'],
                'valid_start_utc' => $route['valid_start_utc'],
                'valid_end_utc' => $route['valid_end_utc'],
                'constrained_area' => $route['constrained_area'],
                'reason' => $route['reason'],
                'facilities' => $route['facilities']
            ]
        ];
    }
    
    $geojson = [
        'type' => 'FeatureCollection',
        'features' => $features,
        'metadata' => [
            'generated' => gmdate('c'),
            'count' => count($features),
            'source' => 'VATSIM TMI API'
        ]
    ];
    
    header('Content-Type: application/geo+json; charset=utf-8');
    echo json_encode($geojson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Get single route by ID
 */
function getRoute($id) {
    global $conn_tmi;
    
    tmi_init(false);
    
    $route = tmi_query_one("SELECT * FROM dbo.tmi_public_routes WHERE route_id = ?", [$id]);
    
    if (!$route) {
        TmiResponse::error('Route not found', 404);
    }
    
    // Return as GeoJSON feature if requested
    if (tmi_param('geojson') === '1') {
        outputGeoJson([$route]);
        return;
    }
    
    TmiResponse::success($route);
}

/**
 * Create new route
 */
function createRoute() {
    global $conn_tmi;
    
    $auth = tmi_init(true);
    $body = tmi_get_json_body();
    
    if (!$body) {
        TmiResponse::error('Request body required', 400);
    }
    
    // Validate required fields
    $required = ['name', 'route_string', 'valid_start_utc', 'valid_end_utc'];
    foreach ($required as $field) {
        if (empty($body[$field])) {
            TmiResponse::error("Missing required field: $field", 400);
        }
    }
    
    // Parse times
    $valid_start = tmi_parse_datetime($body['valid_start_utc']);
    $valid_end = tmi_parse_datetime($body['valid_end_utc']);
    
    if (!$valid_start || !$valid_end) {
        TmiResponse::error('Invalid valid_start_utc or valid_end_utc format', 400);
    }
    
    // Org-scope: validate facilities if provided
    if (!empty($body['facilities'])) {
        global $conn_sqli, $conn_adl;
        $fac_list = is_array($body['facilities']) ? $body['facilities'] : array_filter(preg_split('/[\s,]+/', $body['facilities']));
        if (!empty($fac_list)) {
            require_facilities_scope($fac_list, $conn_sqli, $conn_adl);
        }
    }

    // Build insert data
    $data = [
        'status' => isset($body['status']) ? (int)$body['status'] : 1,
        'name' => $body['name'],
        'adv_number' => $body['adv_number'] ?? null,
        'advisory_id' => isset($body['advisory_id']) ? (int)$body['advisory_id'] : null,
        'reroute_id' => isset($body['reroute_id']) ? (int)$body['reroute_id'] : null,
        'route_string' => $body['route_string'],
        'advisory_text' => $body['advisory_text'] ?? null,
        'color' => $body['color'] ?? '#e74c3c',
        'line_weight' => isset($body['line_weight']) ? (int)$body['line_weight'] : 3,
        'line_style' => $body['line_style'] ?? 'solid',
        'valid_start_utc' => $valid_start,
        'valid_end_utc' => $valid_end,
        'constrained_area' => $body['constrained_area'] ?? null,
        'reason' => $body['reason'] ?? null,
        'origin_filter' => isset($body['origin_filter']) ? json_encode($body['origin_filter']) : null,
        'dest_filter' => isset($body['dest_filter']) ? json_encode($body['dest_filter']) : null,
        'facilities' => $body['facilities'] ?? null,
        'route_geojson' => isset($body['route_geojson']) ? 
            (is_string($body['route_geojson']) ? $body['route_geojson'] : json_encode($body['route_geojson'])) : null,
        'created_by' => $auth->getUserId()
    ];
    
    // Validate color format
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $data['color'])) {
        $data['color'] = '#e74c3c';
    }
    
    // Validate line style
    if (!in_array($data['line_style'], ['solid', 'dashed', 'dotted'])) {
        $data['line_style'] = 'solid';
    }
    
    $id = tmi_insert('tmi_public_routes', $data);
    
    if ($id === false) {
        TmiResponse::error('Failed to create route: ' . tmi_sql_errors(), 500);
    }
    
    // Log event
    tmi_log_event('ROUTE', $id, 'CREATE', [
        'detail' => "Route: {$data['name']}",
        'source_type' => 'API',
        'actor_id' => $auth->getUserId(),
        'actor_name' => $auth->getUserName()
    ]);
    
    // Fetch and return created route
    $route = tmi_query_one("SELECT * FROM dbo.tmi_public_routes WHERE route_id = ?", [$id]);
    
    TmiResponse::created($route);
}

/**
 * Update existing route
 */
function updateRoute($id) {
    global $conn_tmi;
    
    $auth = tmi_init(true);
    $body = tmi_get_json_body();
    
    if (!$body) {
        TmiResponse::error('Request body required', 400);
    }
    
    // Check route exists
    $existing = tmi_query_one("SELECT * FROM dbo.tmi_public_routes WHERE route_id = ?", [$id]);
    if (!$existing) {
        TmiResponse::error('Route not found', 404);
    }
    
    $data = ['updated_at' => gmdate('Y-m-d H:i:s')];
    
    // Update allowed fields
    $allowed_fields = [
        'status', 'name', 'adv_number', 'advisory_id', 'reroute_id',
        'route_string', 'advisory_text', 'color', 'line_weight', 'line_style',
        'valid_start_utc', 'valid_end_utc', 'constrained_area', 'reason',
        'facilities', 'route_geojson'
    ];
    
    foreach ($allowed_fields as $field) {
        if (isset($body[$field])) {
            if (in_array($field, ['valid_start_utc', 'valid_end_utc'])) {
                $data[$field] = tmi_parse_datetime($body[$field]);
            } elseif (in_array($field, ['status', 'advisory_id', 'reroute_id', 'line_weight'])) {
                $data[$field] = (int)$body[$field];
            } elseif (in_array($field, ['origin_filter', 'dest_filter'])) {
                $data[$field] = json_encode($body[$field]);
            } elseif ($field === 'route_geojson') {
                $data[$field] = is_string($body[$field]) ? $body[$field] : json_encode($body[$field]);
            } else {
                $data[$field] = $body[$field];
            }
        }
    }
    
    // Validate color if provided
    if (isset($data['color']) && !preg_match('/^#[0-9A-Fa-f]{6}$/', $data['color'])) {
        unset($data['color']);
    }
    
    // Validate line style if provided
    if (isset($data['line_style']) && !in_array($data['line_style'], ['solid', 'dashed', 'dotted'])) {
        unset($data['line_style']);
    }
    
    $rows = tmi_update('tmi_public_routes', $data, 'route_id = ?', [$id]);
    
    if ($rows === false) {
        TmiResponse::error('Failed to update route: ' . tmi_sql_errors(), 500);
    }
    
    // Log event
    tmi_log_event('ROUTE', $id, 'UPDATE', [
        'detail' => isset($body['status']) ? "Status changed to {$body['status']}" : 'Route updated',
        'source_type' => 'API',
        'actor_id' => $auth->getUserId(),
        'actor_name' => $auth->getUserName()
    ]);
    
    $route = tmi_query_one("SELECT * FROM dbo.tmi_public_routes WHERE route_id = ?", [$id]);
    
    TmiResponse::success($route);
}

/**
 * Delete/expire route
 */
function deleteRoute($id) {
    global $conn_tmi;
    
    $auth = tmi_init(true);
    
    $existing = tmi_query_one("SELECT * FROM dbo.tmi_public_routes WHERE route_id = ?", [$id]);
    if (!$existing) {
        TmiResponse::error('Route not found', 404);
    }
    
    // Check if hard delete is requested
    $hard_delete = tmi_param('hard') === '1';
    
    if ($hard_delete) {
        // Actually delete the record
        $rows = tmi_delete('tmi_public_routes', 'route_id = ?', [$id]);
        $message = 'Route deleted';
    } else {
        // Soft delete - mark as expired
        $data = [
            'status' => 2, // expired
            'updated_at' => gmdate('Y-m-d H:i:s')
        ];
        $rows = tmi_update('tmi_public_routes', $data, 'route_id = ?', [$id]);
        $message = 'Route expired';
    }
    
    if ($rows === false) {
        TmiResponse::error('Failed to delete route', 500);
    }
    
    // Log event
    tmi_log_event('ROUTE', $id, $hard_delete ? 'DELETE' : 'STATUS_CHANGE', [
        'detail' => $message,
        'source_type' => 'API',
        'actor_id' => $auth->getUserId(),
        'actor_name' => $auth->getUserName()
    ]);
    
    TmiResponse::success(['message' => $message, 'route_id' => $id]);
}
