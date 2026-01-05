<?php
/**
 * api/routes/public_update.php
 * POST: Update an existing public route (partial update support)
 * Fetches existing route, merges updates, calls sp_UpsertPublicRoute
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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
    if (!$input || !isset($input['id'])) {
        throw new Exception('Missing route ID');
    }
    
    $routeId = intval($input['id']);
    
    // First, fetch the existing route to get all current values
    $fetchSql = "SELECT * FROM dbo.public_routes WHERE id = ?";
    $fetchStmt = sqlsrv_query($conn_adl, $fetchSql, [$routeId]);
    
    if ($fetchStmt === false) {
        $errors = sqlsrv_errors();
        throw new Exception('Failed to fetch route: ' . ($errors[0]['message'] ?? 'Unknown error'));
    }
    
    $existing = sqlsrv_fetch_array($fetchStmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($fetchStmt);
    
    if (!$existing) {
        throw new Exception('Route not found: ' . $routeId);
    }
    
    // Convert DateTime objects to strings for merging
    foreach (['valid_start_utc', 'valid_end_utc', 'created_utc', 'updated_utc'] as $dateField) {
        if (isset($existing[$dateField]) && $existing[$dateField] instanceof DateTime) {
            $existing[$dateField] = $existing[$dateField]->format('Y-m-d\TH:i:s');
        }
    }
    
    // Merge updates - input values override existing values
    $allowedFields = [
        'name', 'adv_number', 'route_string', 'advisory_text', 
        'color', 'line_weight', 'line_style',
        'valid_start_utc', 'valid_end_utc',
        'constrained_area', 'reason', 'origin_filter', 'dest_filter',
        'facilities', 'created_by', 'route_geojson'
    ];
    
    foreach ($allowedFields as $field) {
        if (array_key_exists($field, $input)) {
            $existing[$field] = $input[$field];
        }
    }
    
    // Prepare parameters for stored procedure
    $paramValues = [
        $routeId,  // @id - use existing ID for update
        isset($existing['name']) ? substr($existing['name'], 0, 64) : null,
        isset($existing['adv_number']) ? substr($existing['adv_number'], 0, 16) : null,
        $existing['route_string'] ?? null,
        $existing['advisory_text'] ?? null,
        isset($existing['color']) ? substr($existing['color'], 0, 7) : '#e74c3c',
        isset($existing['line_weight']) ? (int)$existing['line_weight'] : 3,
        isset($existing['line_style']) ? substr($existing['line_style'], 0, 16) : 'solid',
        $existing['valid_start_utc'] ?? null,
        $existing['valid_end_utc'] ?? null,
        isset($existing['constrained_area']) ? substr($existing['constrained_area'], 0, 64) : null,
        isset($existing['reason']) ? substr($existing['reason'], 0, 256) : null,
        isset($existing['origin_filter']) ? (is_array($existing['origin_filter']) ? json_encode($existing['origin_filter']) : $existing['origin_filter']) : null,
        isset($existing['dest_filter']) ? (is_array($existing['dest_filter']) ? json_encode($existing['dest_filter']) : $existing['dest_filter']) : null,
        $existing['facilities'] ?? null,
        $existing['created_by'] ?? null,
        isset($existing['route_geojson']) ? (is_array($existing['route_geojson']) ? json_encode($existing['route_geojson']) : $existing['route_geojson']) : null
    ];
    
    // Call the stored procedure
    $sql = "EXEC dbo.sp_UpsertPublicRoute 
        @id = ?, @name = ?, @adv_number = ?, @route_string = ?, @advisory_text = ?,
        @color = ?, @line_weight = ?, @line_style = ?,
        @valid_start_utc = ?, @valid_end_utc = ?,
        @constrained_area = ?, @reason = ?, @origin_filter = ?, @dest_filter = ?,
        @facilities = ?, @created_by = ?, @route_geojson = ?";
    
    $stmt = sqlsrv_query($conn_adl, $sql, $paramValues);
    
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        throw new Exception('Update failed: ' . ($errors[0]['message'] ?? 'Unknown error'));
    }
    
    // Get the result
    $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    
    if ($result) {
        // Convert DateTime objects to ISO strings
        foreach (['valid_start_utc', 'valid_end_utc', 'created_utc', 'updated_utc'] as $dateField) {
            if (isset($result[$dateField]) && $result[$dateField] instanceof DateTime) {
                $result[$dateField] = $result[$dateField]->format('Y-m-d\TH:i:s\Z');
            }
        }
        
        // Don't return large GeoJSON in response
        if (isset($result['route_geojson']) && is_string($result['route_geojson']) && strlen($result['route_geojson']) > 1000) {
            $result['route_geojson'] = '[GeoJSON data - ' . strlen($result['route_geojson']) . ' bytes]';
        }
    }
    
    sqlsrv_free_stmt($stmt);
    
    echo json_encode([
        'success' => true,
        'route' => $result,
        'message' => 'Route updated successfully'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
