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
require_once __DIR__ . '/../../../../load/services/GISService.php';

// Discord coordination
define('DISCORD_COORDINATION_CHANNEL', '1466013550450577491');

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
            // TMI authoritative: exclude routes pending coordination approval
            $where_clauses[] = "(r.coordination_status IS NULL OR r.coordination_status = 'APPROVED')";
            break;
        case 'pending':
            // Routes awaiting TMI coordination approval
            $where_clauses[] = "r.coordination_status = 'PENDING'";
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
            r.route_geojson, r.created_by, r.created_at, r.updated_at,
            r.coordination_status, r.coordination_proposal_id,
            r.discord_message_id, r.discord_channel_id, r.discord_posted_at
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

    // Generate advisory number from database (auto-increment with all advisories)
    $clientAdvNumber = $body['adv_number'] ?? null;
    $serverAdvNumber = null;

    try {
        $advSql = "DECLARE @num NVARCHAR(16); EXEC sp_GetNextAdvisoryNumber @next_number = @num OUTPUT; SELECT @num AS adv_num;";
        $advStmt = sqlsrv_query($conn_tmi, $advSql);
        if ($advStmt && ($advRow = sqlsrv_fetch_array($advStmt, SQLSRV_FETCH_ASSOC))) {
            $serverAdvNumber = $advRow['adv_num'];
        }
        if ($advStmt) sqlsrv_free_stmt($advStmt);
    } catch (Exception $e) {
        // Log error but continue - will use client number as fallback
        error_log('Failed to get advisory number: ' . $e->getMessage());
    }

    // Use server-assigned number, fall back to client number if stored procedure failed
    $advNumber = $serverAdvNumber ?? $clientAdvNumber ?? ('ADV' . date('His'));

    // Extract the 3-digit number from the server-assigned advisory number
    // Format may be "ADVZY 047" or just "047" or "47"
    $serverDigits = preg_replace('/[^0-9]/', '', $advNumber);
    $serverDigits = str_pad($serverDigits, 3, '0', STR_PAD_LEFT);

    // Replace client advisory number in advisory_text with server-assigned number
    $advisoryText = $body['advisory_text'] ?? null;
    if (!empty($advisoryText) && $serverAdvNumber) {
        // 1. Replace ADVZY header pattern: "ADVZY 001" or "ADVZY 021" -> "ADVZY 047"
        $advisoryText = preg_replace('/ADVZY\s+\d{3}/', 'ADVZY ' . $serverDigits, $advisoryText, 1);

        // 2. Replace TMI ID pattern: "RRDCC001" or "TMI ID: RRDCC001" -> "RRDCC047"
        // TMI ID format for routes is RR + facility (3 chars) + 3-digit number
        $advisoryText = preg_replace('/(RR[A-Z]{2,3})\d{3}/', '$1' . $serverDigits, $advisoryText, 1);

        $body['advisory_text'] = $advisoryText;
    }

    // Extract facilities for coordination BEFORE insert (to determine initial coordination_status)
    $facilitiesStr = $body['facilities'] ?? null;
    $facilities = [];
    $gisCalculated = false;

    // Try PostGIS-based facility detection first (if route geometry provided)
    if (!empty($body['route_geojson'])) {
        $gis = GISService::getInstance();
        if ($gis) {
            $routeGeojson = is_array($body['route_geojson']) ? $body['route_geojson'] : json_decode($body['route_geojson'], true);
            $analysis = $gis->analyzeTMIRoute(
                $routeGeojson,
                $body['origin'] ?? $body['constrained_area'] ?? null,
                $body['destination'] ?? null,
                (int)($body['altitude'] ?? 35000)
            );
            $facilities = $analysis['artccs_traversed'] ?? [];
            if (!empty($facilities)) {
                $gisCalculated = true;
                // Update the facilities field with GIS-calculated values
                $body['facilities'] = implode('/', $facilities);
                $facilitiesStr = $body['facilities'];
            }
        }
    }

    // Fallback to text parsing if PostGIS unavailable or returned no results
    if (empty($facilities)) {
        $facilities = parseFacilityCodes($facilitiesStr, $advisoryText);
    }

    // Determine initial coordination_status based on facility count
    // Multi-facility = PENDING (requires coordination), single/none = NULL (no coordination needed)
    $initialCoordinationStatus = (count($facilities) > 1) ? 'PENDING' : null;

    // Build insert
    $sql = "INSERT INTO dbo.tmi_public_routes (
                status, name, adv_number, route_string, advisory_text,
                color, line_weight, line_style,
                valid_start_utc, valid_end_utc,
                constrained_area, reason, origin_filter, dest_filter, facilities,
                route_geojson, created_by, coordination_status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);
            SELECT SCOPE_IDENTITY() AS route_id;";

    $params = [
        isset($body['status']) ? (int)$body['status'] : 1,
        $body['name'],
        $advNumber,  // Server-assigned advisory number
        $body['route_string'],
        $advisoryText,  // Updated advisory text with server-assigned number
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
        $body['created_by'] ?? $auth->getKeyInfo()['owner_name'] ?? 'API',
        $initialCoordinationStatus  // coordination_status: PENDING for multi-facility, NULL otherwise
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

    // Fetch created route
    $sql = "SELECT * FROM dbo.tmi_public_routes WHERE route_id = ?";
    $stmt = sqlsrv_query($conn_tmi, $sql, [$newId]);
    $created = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    // facilities already extracted above before INSERT
    // Determine if coordination is needed (multiple facilities = requires coordination)
    $coordinationResult = null;
    $directPublish = false;

    if (count($facilities) > 1) {
        // Multi-facility route: Submit to coordination queue
        // Route will be published to advisories channel only after approval
        $routeData = [
            'name' => $body['name'],
            'adv_number' => $advNumber,
            'route_string' => $body['route_string'],
            'advisory_text' => $advisoryText,
            'constrained_area' => $body['constrained_area'] ?? null,
            'reason' => $body['reason'] ?? null,
            'valid_start_utc' => $body['valid_start_utc'],
            'valid_end_utc' => $body['valid_end_utc'],
            'facilities' => $facilitiesStr
        ];

        $coordinationResult = createRouteCoordinationProposal(
            $conn_tmi,
            $newId,
            $routeData,
            $facilities,
            $body['created_by'] ?? $auth->getKeyInfo()['owner_name'] ?? 'API'
        );

        // Link the proposal back to the route
        if ($coordinationResult && $coordinationResult['proposal_id']) {
            $updateSql = "UPDATE dbo.tmi_public_routes SET coordination_proposal_id = ? WHERE route_id = ?";
            sqlsrv_query($conn_tmi, $updateSql, [$coordinationResult['proposal_id'], $newId]);

            // Re-fetch the route to get updated coordination fields
            $stmt = sqlsrv_query($conn_tmi, "SELECT * FROM dbo.tmi_public_routes WHERE route_id = ?", [$newId]);
            $created = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
        }
    } elseif (count($facilities) === 1) {
        // Single-facility route: Auto-approve and publish directly
        // (DCC internal or only affects one facility)
        $directPublish = true;
    } else {
        // No facilities specified: Publish directly (legacy behavior)
        $directPublish = true;
    }

    // Only publish directly to Discord if not going through coordination
    $discordResult = null;
    if ($directPublish && !empty($advisoryText)) {
        $discordResult = publishRouteToDiscord($conn_tmi, $newId, $advisoryText, $body['name'] ?? 'Route');
    }

    http_response_code(201);
    $response = formatRoute($created);

    if ($coordinationResult) {
        $response['requires_coordination'] = true;
        $response['coordination'] = $coordinationResult;
        $response['discord_published'] = false;
        $response['message'] = 'Route submitted for multi-facility coordination. Will be published upon approval.';
    } else {
        $response['requires_coordination'] = false;
        $response['discord_published'] = ($discordResult && $discordResult['success']) ? true : false;
        $response['discord_result'] = $discordResult;
    }

    SwimResponse::success($response, $coordinationResult ? 'Route created - pending coordination approval' : 'Route created');
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

        // TMI coordination status - authoritative source for route activation
        'coordination' => [
            'status' => $row['coordination_status'] ?? null,  // NULL = no coordination needed, PENDING, APPROVED, DENIED
            'proposal_id' => $row['coordination_proposal_id'] ?? null,
            'requires_approval' => !empty($row['coordination_status']) && $row['coordination_status'] !== 'APPROVED'
        ],

        // Discord publishing info
        'discord' => [
            'message_id' => $row['discord_message_id'] ?? null,
            'channel_id' => $row['discord_channel_id'] ?? null,
            'posted_at' => formatDT($row['discord_posted_at'] ?? null)
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


// ============================================================================
// Facility Extraction for Coordination
// ============================================================================

/**
 * Parse facility codes from a facilities string or advisory text
 * Handles formats like: "ZAU/ZBW/ZDV/ZLC/ZMP/ZNY/ZOA/ZSE/CZYZ"
 * Or from advisory text: "FACILITIES INCLUDED: ZAU/ZBW/ZDV/ZLC..."
 *
 * @param string|null $facilitiesStr Direct facilities string (e.g., "ZAU/ZBW/ZDV")
 * @param string|null $advisoryText Fallback: full advisory text to parse
 * @return array Array of facility codes (e.g., ['ZAU', 'ZBW', 'ZDV'])
 */
function parseFacilityCodes(?string $facilitiesStr, ?string $advisoryText = null): array {
    $parseString = null;

    // Use facilities string directly if provided
    if (!empty($facilitiesStr)) {
        $parseString = $facilitiesStr;
    }
    // Fall back to parsing from advisory text
    elseif (!empty($advisoryText)) {
        if (preg_match('/FACILITIES\s+INCLUDED[:\s]+([A-Z0-9\/,\s]+)/i', $advisoryText, $matches)) {
            $parseString = trim($matches[1]);
        }
    }

    if (empty($parseString)) {
        return [];
    }

    // Split by / or , and clean up
    $facilities = preg_split('/[\/,]+/', $parseString);
    $facilities = array_map('trim', $facilities);
    $facilities = array_filter($facilities, function($f) {
        return !empty($f) && preg_match('/^C?Z[A-Z]{1,3}$/i', $f); // Matches ZAU, CZYZ, etc.
    });
    return array_map('strtoupper', array_values($facilities));
}

// ============================================================================
// Coordination Proposal Creation
// ============================================================================

/**
 * Create a coordination proposal for a route advisory
 * Routes use DCC as the requester and extracted facilities as providers
 *
 * @param resource $conn SQL Server connection
 * @param int $routeId The route ID to link to
 * @param array $routeData The route data
 * @param array $facilities Array of facility codes requiring approval
 * @param string $createdBy User who created the route
 * @return array Result with proposal_id, proposal_guid, etc.
 */
function createRouteCoordinationProposal($conn, int $routeId, array $routeData, array $facilities, string $createdBy): array {
    $result = [
        'success' => false,
        'proposal_id' => null,
        'proposal_guid' => null,
        'auto_approved' => false,
        'discord_posted' => false,
        'error' => null
    ];

    if (empty($facilities)) {
        $result['error'] = 'No facilities to coordinate with';
        return $result;
    }

    try {
        // Build entry data JSON for the proposal
        $entryData = [
            'type' => 'ROUTE',
            'route_id' => $routeId,
            'name' => $routeData['name'] ?? null,
            'adv_number' => $routeData['adv_number'] ?? null,
            'route_string' => $routeData['route_string'] ?? null,
            'constrained_area' => $routeData['constrained_area'] ?? null,
            'reason' => $routeData['reason'] ?? null,
            'valid_start' => $routeData['valid_start_utc'] ?? null,
            'valid_end' => $routeData['valid_end_utc'] ?? null,
            'facilities' => $facilities
        ];

        // Calculate approval deadline (valid_start minus 1 minute)
        $validStart = $routeData['valid_start_utc'] ?? null;
        if ($validStart) {
            $deadline = new DateTime($validStart, new DateTimeZone('UTC'));
            $deadline->modify('-1 minute');
        } else {
            // Default to 1 hour from now if no start time
            $deadline = new DateTime('now', new DateTimeZone('UTC'));
            $deadline->modify('+1 hour');
        }

        // Raw text for the proposal (the advisory text)
        $rawText = $routeData['advisory_text'] ?? '';

        // Insert proposal - DCC is always the requester for route advisories
        $sql = "INSERT INTO dbo.tmi_proposals (
                    entry_type, requesting_facility, providing_facility, ctl_element,
                    entry_data_json, raw_text,
                    approval_deadline_utc, valid_from, valid_until,
                    facilities_required,
                    created_by, created_by_name, route_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);
                SELECT SCOPE_IDENTITY() AS proposal_id;";

        // providing_facility = first facility in the list (or null for multi)
        $providingFacility = count($facilities) === 1 ? $facilities[0] : null;

        $params = [
            'ROUTE',                           // entry_type
            'DCC',                             // requesting_facility - always DCC for routes
            $providingFacility,                // providing_facility
            $routeData['constrained_area'] ?? null, // ctl_element
            json_encode($entryData),           // entry_data_json (includes route_id)
            $rawText,                          // raw_text
            $deadline->format('Y-m-d H:i:s'),  // approval_deadline_utc
            $validStart,                       // valid_from
            $routeData['valid_end_utc'] ?? null, // valid_until
            count($facilities),                // facilities_required
            $createdBy,                        // created_by
            $createdBy,                        // created_by_name
            $routeId                           // route_id (direct column link)
        ];

        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            $result['error'] = 'Failed to create proposal: ' . ($errors[0]['message'] ?? 'Unknown');
            return $result;
        }

        // Get the proposal ID
        sqlsrv_next_result($stmt);
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $proposalId = $row['proposal_id'] ?? null;
        sqlsrv_free_stmt($stmt);

        if (!$proposalId) {
            $result['error'] = 'Failed to get proposal ID';
            return $result;
        }

        // Get the proposal_guid
        $guidSql = "SELECT proposal_guid FROM dbo.tmi_proposals WHERE proposal_id = ?";
        $guidStmt = sqlsrv_query($conn, $guidSql, [$proposalId]);
        $guidRow = sqlsrv_fetch_array($guidStmt, SQLSRV_FETCH_ASSOC);
        $proposalGuid = $guidRow['proposal_guid'] ?? null;
        sqlsrv_free_stmt($guidStmt);

        $result['proposal_id'] = $proposalId;
        $result['proposal_guid'] = $proposalGuid;

        // Insert required facilities
        foreach ($facilities as $facilityCode) {
            $facSql = "INSERT INTO dbo.tmi_proposal_facilities (
                           proposal_id, facility_code, facility_name, approval_emoji
                       ) VALUES (?, ?, ?, ?)";
            sqlsrv_query($conn, $facSql, [
                $proposalId,
                strtoupper($facilityCode),
                null, // facility_name - we don't have this
                null  // approval_emoji - let the coordination system assign
            ]);
        }

        // Post to Discord coordination channel
        $discordResult = postRouteCoordinationToDiscord($proposalId, $routeData, $deadline, $facilities, $createdBy);

        if ($discordResult && isset($discordResult['id'])) {
            // Update proposal with Discord IDs
            $channelId = $discordResult['thread_id'] ?? DISCORD_COORDINATION_CHANNEL;
            $messageId = $discordResult['thread_message_id'] ?? $discordResult['id'];

            $updateSql = "UPDATE dbo.tmi_proposals SET
                              discord_channel_id = ?,
                              discord_message_id = ?,
                              discord_posted_at = GETUTCDATE()
                          WHERE proposal_id = ?";
            sqlsrv_query($conn, $updateSql, [$channelId, $messageId, $proposalId]);

            $result['discord_posted'] = true;
            $result['discord_channel_id'] = $channelId;
            $result['discord_message_id'] = $messageId;
            $result['discord_thread_id'] = $discordResult['thread_id'] ?? null;
        }

        $result['success'] = true;

    } catch (Exception $e) {
        $result['error'] = 'Exception: ' . $e->getMessage();
    }

    return $result;
}

/**
 * Post route coordination proposal to Discord
 *
 * @param int $proposalId The proposal ID
 * @param array $routeData The route data
 * @param DateTime $deadline Approval deadline
 * @param array $facilities Facility codes requiring approval
 * @param string $userName User who created the proposal
 * @return array|null Discord API response
 */
function postRouteCoordinationToDiscord(int $proposalId, array $routeData, DateTime $deadline, array $facilities, string $userName): ?array {
    try {
        $discordApiPath = __DIR__ . '/../../../../load/discord/DiscordAPI.php';
        if (!file_exists($discordApiPath)) {
            error_log('Discord API not available for route coordination');
            return null;
        }

        require_once $discordApiPath;
        $discord = new DiscordAPI();

        if (!$discord->isConfigured()) {
            error_log('Discord not configured for route coordination');
            return null;
        }

        // Build thread title
        $advNum = $routeData['adv_number'] ?? 'RTE';
        $constrainedArea = $routeData['constrained_area'] ?? '';
        $facilitiesList = implode('/', $facilities);
        $threadTitle = "ROUTE #{$proposalId} | {$advNum} | {$constrainedArea} | {$facilitiesList}";
        $threadTitle = substr($threadTitle, 0, 100); // Discord thread title limit

        // Step 1: Post starter message to coordination channel
        $starterMessage = "**{$threadTitle}**\n_Click thread to view details and react to approve/deny_";
        $starterResult = $discord->createMessage(DISCORD_COORDINATION_CHANNEL, [
            'content' => $starterMessage
        ]);

        if (!$starterResult || !isset($starterResult['id'])) {
            error_log('Failed to post starter message for route coordination');
            return null;
        }

        // Step 2: Create thread from the starter message
        $threadResult = $discord->createThreadFromMessage(
            DISCORD_COORDINATION_CHANNEL,
            $starterResult['id'],
            $threadTitle,
            1440 // Auto-archive after 24 hours
        );

        if ($threadResult && isset($threadResult['id'])) {
            $threadId = $threadResult['id'];
            $starterResult['thread_id'] = $threadId;

            // Step 3: Post full coordination details inside the thread
            $content = formatRouteCoordinationMessage($proposalId, $routeData, $deadline, $facilities, $userName);
            $threadMessage = $discord->sendMessageToThread($threadId, [
                'content' => $content
            ]);

            if ($threadMessage && isset($threadMessage['id'])) {
                $starterResult['thread_message_id'] = $threadMessage['id'];

                // Step 4: Add facility approval emoji reactions
                addFacilityReactionsToThread($discord, $threadId, $threadMessage['id'], $facilities);
            }
        }

        return $starterResult;

    } catch (Exception $e) {
        error_log('Route coordination Discord error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Format route coordination message for Discord thread
 */
function formatRouteCoordinationMessage(int $proposalId, array $routeData, DateTime $deadline, array $facilities, string $userName): string {
    $lines = [];
    $lines[] = "```";
    $lines[] = "═══════════════════════════════════════════════════════";
    $lines[] = "ROUTE COORDINATION REQUEST #{$proposalId}";
    $lines[] = "═══════════════════════════════════════════════════════";
    $lines[] = "";
    $lines[] = "REQUESTER: DCC";
    $lines[] = "SUBMITTED BY: {$userName}";
    $lines[] = "";
    $lines[] = "ADVISORY: " . ($routeData['adv_number'] ?? 'N/A');
    $lines[] = "NAME: " . ($routeData['name'] ?? 'N/A');
    $lines[] = "CONSTRAINED AREA: " . ($routeData['constrained_area'] ?? 'N/A');
    $lines[] = "";
    $lines[] = "ROUTE: " . ($routeData['route_string'] ?? 'N/A');
    $lines[] = "";
    $lines[] = "REASON: " . ($routeData['reason'] ?? 'N/A');
    $lines[] = "";
    $lines[] = "VALID: " . ($routeData['valid_start_utc'] ?? 'N/A') . " - " . ($routeData['valid_end_utc'] ?? 'N/A');
    $lines[] = "";
    $lines[] = "───────────────────────────────────────────────────────";
    $lines[] = "FACILITIES REQUIRING APPROVAL:";
    foreach ($facilities as $fac) {
        $lines[] = "  • {$fac} - ⏳ PENDING";
    }
    $lines[] = "";
    $lines[] = "APPROVAL DEADLINE: " . $deadline->format('Y-m-d H:i:s') . " UTC";
    $lines[] = "───────────────────────────────────────────────────────";
    $lines[] = "";
    $lines[] = "React with your facility emoji to APPROVE";
    $lines[] = "React with ❌ to DENY";
    $lines[] = "```";

    return implode("\n", $lines);
}

/**
 * Add facility emoji reactions to Discord message
 */
function addFacilityReactionsToThread(DiscordAPI $discord, string $threadId, string $messageId, array $facilities): void {
    // Regional indicator emoji mapping (same as coordinate.php)
    $facilityEmojiMap = [
        'ZAB' => '🇦', 'ZAN' => '🇬', 'ZAU' => '🇺', 'ZBW' => '🇧', 'ZDC' => '🇩',
        'ZDV' => '🇻', 'ZFW' => '🇫', 'ZHN' => '🇭', 'ZHU' => '🇼', 'ZID' => '🇮',
        'ZJX' => '🇯', 'ZKC' => '🇰', 'ZLA' => '🇱', 'ZLC' => '🇨', 'ZMA' => '🇲',
        'ZME' => '🇪', 'ZMP' => '🇵', 'ZNY' => '🇳', 'ZOA' => '🇴', 'ZOB' => '🇷',
        'ZSE' => '🇸', 'ZTL' => '🇹', 'CZEG' => '🇽', 'CZVR' => '🇾', 'CZWG' => '🇿',
        'CZYZ' => '🇶', 'CZQM' => '🇿', 'CZQX' => '🇽', 'CZQO' => '🇶', 'CZUL' => '🇾'
    ];

    foreach ($facilities as $facCode) {
        // Try custom guild emoji first, fall back to regional indicator
        $customEmoji = strtoupper($facCode);
        $success = $discord->createReaction($threadId, $messageId, $customEmoji);

        // If custom emoji failed, try regional indicator
        if (!$success) {
            $emoji = $facilityEmojiMap[$facCode] ?? null;
            if ($emoji) {
                $discord->createReaction($threadId, $messageId, $emoji);
            }
        }

        usleep(100000); // 100ms delay between reactions for rate limiting
    }

    // Add deny reaction
    $discord->createReaction($threadId, $messageId, '❌');
}

// ============================================================================
// Discord Publishing for Routes
// ============================================================================
/**
 * Split a long message into chunks that fit Discord's 2000 char limit
 */
function splitRouteMessageForDiscord(string $message, int $maxLen = 1980): array {
    if (strlen($message) <= $maxLen) {
        return [$message];
    }

    $chunks = [];
    $lines = explode("\n", $message);
    $currentChunk = '';

    foreach ($lines as $line) {
        $tentative = $currentChunk . ($currentChunk ? "\n" : '') . $line;

        if (strlen($tentative) <= $maxLen) {
            $currentChunk = $tentative;
        } else {
            if ($currentChunk !== '') {
                $chunks[] = $currentChunk;
            }

            if (strlen($line) > $maxLen) {
                $lineChunks = str_split($line, $maxLen);
                foreach ($lineChunks as $i => $lineChunk) {
                    if ($i < count($lineChunks) - 1) {
                        $chunks[] = $lineChunk;
                    } else {
                        $currentChunk = $lineChunk;
                    }
                }
            } else {
                $currentChunk = $line;
            }
        }
    }

    if ($currentChunk !== '') {
        $chunks[] = $currentChunk;
    }

    return $chunks;
}

function publishRouteToDiscord($conn, $routeId, $advisoryText, $routeName) {
    $result = [
        'success' => false,
        'message_id' => null,
        'channel_id' => null,
        'error' => null,
        'chunks_posted' => 0
    ];

    try {
        // Load Discord API if available
        $discordApiPath = __DIR__ . '/../../../../load/discord/DiscordAPI.php';
        $multiDiscordPath = __DIR__ . '/../../../../load/discord/MultiDiscordAPI.php';

        if (!file_exists($discordApiPath)) {
            $result['error'] = 'Discord API not available';
            return $result;
        }

        require_once $discordApiPath;
        if (file_exists($multiDiscordPath)) {
            require_once $multiDiscordPath;
        }

        // Split message if it exceeds Discord's 2000 char limit (accounting for code block markers)
        $messageChunks = splitRouteMessageForDiscord($advisoryText, 1988);
        $totalChunks = count($messageChunks);

        // Try MultiDiscordAPI first (posts to multiple orgs)
        if (class_exists('MultiDiscordAPI')) {
            $multiDiscord = new MultiDiscordAPI();
            if ($multiDiscord->isConfigured()) {
                // Post each chunk to vatcscc advisories channel
                $firstMessageId = null;
                $allSuccess = true;
                $lastError = null;

                foreach ($messageChunks as $chunkIndex => $chunk) {
                    $partIndicator = ($totalChunks > 1) ? " (" . ($chunkIndex + 1) . "/{$totalChunks})" : '';
                    $chunkMessage = "```\n{$chunk}\n```" . ($totalChunks > 1 ? $partIndicator : '');

                    $postResult = $multiDiscord->postToChannel('vatcscc', 'advisories', ['content' => $chunkMessage]);

                    if ($chunkIndex === 0 && $postResult && $postResult['success']) {
                        $firstMessageId = $postResult['message_id'] ?? null;
                        $result['channel_id'] = $postResult['channel_id'] ?? null;
                    }

                    if (!$postResult || !$postResult['success']) {
                        $allSuccess = false;
                        $lastError = $postResult['error'] ?? 'MultiDiscord post failed';
                    }

                    // Small delay between chunks to maintain order
                    if ($chunkIndex < $totalChunks - 1) {
                        usleep(100000); // 100ms
                    }
                }

                if ($firstMessageId) {
                    $result['success'] = true;
                    $result['message_id'] = $firstMessageId;
                    $result['chunks_posted'] = $totalChunks;

                    // Update database with Discord message ID
                    if ($conn) {
                        $updateSql = "UPDATE dbo.tmi_public_routes SET discord_message_id = ? WHERE route_id = ?";
                        sqlsrv_query($conn, $updateSql, [$firstMessageId, $routeId]);
                    }

                    return $result;
                } else {
                    $result['error'] = $lastError ?? 'MultiDiscord post failed';
                }
            }
        }

        // Fallback to single DiscordAPI
        if (class_exists('DiscordAPI')) {
            $discord = new DiscordAPI();
            if ($discord->isConfigured()) {
                // Get advisories channel
                $channelId = $discord->getChannelByPurpose('advisories');
                if (!$channelId) {
                    $channelId = $discord->getChannelByPurpose('tmi');
                }

                if ($channelId) {
                    $firstMessageId = null;
                    $allSuccess = true;
                    $lastError = null;

                    foreach ($messageChunks as $chunkIndex => $chunk) {
                        $partIndicator = ($totalChunks > 1) ? " (" . ($chunkIndex + 1) . "/{$totalChunks})" : '';
                        $chunkMessage = "```\n{$chunk}\n```" . ($totalChunks > 1 ? $partIndicator : '');

                        $response = $discord->createMessage($channelId, ['content' => $chunkMessage]);

                        if ($chunkIndex === 0 && $response && isset($response['id'])) {
                            $firstMessageId = $response['id'];
                        }

                        if (!$response || !isset($response['id'])) {
                            $allSuccess = false;
                            $lastError = $discord->getLastError() ?? 'Discord post failed';
                        }

                        // Small delay between chunks to maintain order
                        if ($chunkIndex < $totalChunks - 1) {
                            usleep(100000); // 100ms
                        }
                    }

                    if ($firstMessageId) {
                        $result['success'] = true;
                        $result['message_id'] = $firstMessageId;
                        $result['channel_id'] = $channelId;
                        $result['chunks_posted'] = $totalChunks;

                        // Update database with Discord message ID
                        if ($conn) {
                            $updateSql = "UPDATE dbo.tmi_public_routes SET discord_message_id = ? WHERE route_id = ?";
                            sqlsrv_query($conn, $updateSql, [$firstMessageId, $routeId]);
                        }

                        return $result;
                    } else {
                        $result['error'] = $lastError ?? 'Discord post failed';
                    }
                } else {
                    $result['error'] = 'No advisories channel configured';
                }
            } else {
                $result['error'] = 'Discord not configured';
            }
        }

    } catch (Exception $e) {
        $result['error'] = 'Discord error: ' . $e->getMessage();
    }

    return $result;
}
