<?php
/**
 * api/routes/public_post.php
 * POST: Create or update a public route
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../load/connect.php';

try {
    if (!isset($conn_adl)) {
        throw new Exception('Database connection not available');
    }
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }
    
    // Debug logging
    error_log('[PublicRoutes API] Received keys: ' . implode(', ', array_keys($input)));
    error_log('[PublicRoutes API] route_geojson present: ' . (isset($input['route_geojson']) ? 'yes' : 'no'));
    if (isset($input['route_geojson'])) {
        error_log('[PublicRoutes API] route_geojson length: ' . strlen($input['route_geojson']));
    }
    
    // Validate required fields
    $required = ['name', 'route_string', 'valid_start_utc', 'valid_end_utc'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }
    
    // Prepare parameters
    $params = [
        ['id', isset($input['id']) ? (int)$input['id'] : null, SQLSRV_PARAM_IN, SQLSRV_PHPTYPE_INT],
        ['name', substr($input['name'], 0, 64)],
        ['adv_number', isset($input['adv_number']) ? substr($input['adv_number'], 0, 16) : null],
        ['route_string', $input['route_string']],
        ['advisory_text', $input['advisory_text'] ?? null],
        ['color', isset($input['color']) ? substr($input['color'], 0, 7) : '#e74c3c'],
        ['line_weight', isset($input['line_weight']) ? (int)$input['line_weight'] : 3],
        ['line_style', isset($input['line_style']) ? substr($input['line_style'], 0, 16) : 'solid'],
        ['valid_start_utc', $input['valid_start_utc']],
        ['valid_end_utc', $input['valid_end_utc']],
        ['constrained_area', isset($input['constrained_area']) ? substr($input['constrained_area'], 0, 64) : null],
        ['reason', isset($input['reason']) ? substr($input['reason'], 0, 256) : null],
        ['origin_filter', isset($input['origin_filter']) ? (is_array($input['origin_filter']) ? json_encode($input['origin_filter']) : $input['origin_filter']) : null],
        ['dest_filter', isset($input['dest_filter']) ? (is_array($input['dest_filter']) ? json_encode($input['dest_filter']) : $input['dest_filter']) : null],
        ['facilities', $input['facilities'] ?? null],
        ['created_by', $input['created_by'] ?? null],
        ['route_geojson', isset($input['route_geojson']) ? (is_array($input['route_geojson']) ? json_encode($input['route_geojson']) : $input['route_geojson']) : null]
    ];
    
    // Debug: log the route_geojson value
    $geojsonValue = $params[17][1];  // Index 17 is route_geojson
    error_log('[PublicRoutes API] route_geojson param value is null: ' . ($geojsonValue === null ? 'yes' : 'no'));
    if ($geojsonValue !== null) {
        error_log('[PublicRoutes API] route_geojson param length: ' . strlen($geojsonValue));
    }
    
    // Build SQL with parameters
    $sql = "EXEC dbo.sp_UpsertPublicRoute 
        @id = ?, @name = ?, @adv_number = ?, @route_string = ?, @advisory_text = ?,
        @color = ?, @line_weight = ?, @line_style = ?,
        @valid_start_utc = ?, @valid_end_utc = ?,
        @constrained_area = ?, @reason = ?, @origin_filter = ?, @dest_filter = ?,
        @facilities = ?, @created_by = ?, @route_geojson = ?";
    
    $paramValues = array_map(function($p) { return $p[1]; }, $params);
    
    $stmt = sqlsrv_query($conn_adl, $sql, $paramValues);
    
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        throw new Exception('Query failed: ' . ($errors[0]['message'] ?? 'Unknown error'));
    }
    
    // Get the result
    $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    
    if ($result) {
        // Convert DateTime objects
        foreach (['valid_start_utc', 'valid_end_utc', 'created_utc', 'updated_utc'] as $dateField) {
            if (isset($result[$dateField]) && $result[$dateField] instanceof DateTime) {
                $result[$dateField] = $result[$dateField]->format('Y-m-d\TH:i:s\Z');
            }
        }
    }
    
    sqlsrv_free_stmt($stmt);
    
    echo json_encode([
        'success' => true,
        'route' => $result,
        'message' => isset($input['id']) ? 'Route updated successfully' : 'Route created successfully'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
