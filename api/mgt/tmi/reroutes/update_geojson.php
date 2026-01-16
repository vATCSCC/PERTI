<?php
/**
 * Update route_geojson for public routes
 * 
 * POST /api/mgt/tmi/reroutes/update_geojson.php
 * 
 * Request body (JSON):
 * {
 *   "id": 7,                           // Route ID to update
 *   "route_geojson": { ... }           // GeoJSON FeatureCollection
 * }
 * 
 * Or for bulk update:
 * {
 *   "routes": [
 *     { "id": 7, "route_geojson": { ... } },
 *     { "id": 8, "route_geojson": { ... } }
 *   ]
 * }
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
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Include database config
require_once __DIR__ . '/../../../../includes/db_azure.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }
    
    $updated = [];
    $errors = [];
    
    // Handle single route update
    if (isset($input['id'])) {
        $routes = [['id' => $input['id'], 'route_geojson' => $input['route_geojson'] ?? null]];
    }
    // Handle bulk update
    elseif (isset($input['routes']) && is_array($input['routes'])) {
        $routes = $input['routes'];
    }
    else {
        throw new Exception('Missing required field: id or routes');
    }
    
    foreach ($routes as $route) {
        $id = intval($route['id'] ?? 0);
        $geojson = $route['route_geojson'] ?? null;
        
        if ($id <= 0) {
            $errors[] = ['id' => $id, 'error' => 'Invalid route ID'];
            continue;
        }
        
        // Validate GeoJSON structure if provided
        if ($geojson !== null) {
            if (!is_array($geojson) && !is_object($geojson)) {
                // Try to decode if string
                if (is_string($geojson)) {
                    $geojson = json_decode($geojson, true);
                }
            }
            
            if (!$geojson || !isset($geojson['type']) || $geojson['type'] !== 'FeatureCollection') {
                $errors[] = ['id' => $id, 'error' => 'Invalid GeoJSON: must be a FeatureCollection'];
                continue;
            }
            
            // Encode for storage
            $geojsonStr = json_encode($geojson);
        } else {
            $geojsonStr = null;
        }
        
        // Update the route
        $sql = "UPDATE dbo.public_routes SET route_geojson = ?, updated_utc = GETUTCDATE() WHERE id = ?";
        $params = [$geojsonStr, $id];
        
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        if ($stmt === false) {
            $sqlErrors = sqlsrv_errors();
            $errors[] = ['id' => $id, 'error' => 'Database error: ' . ($sqlErrors[0]['message'] ?? 'Unknown')];
            continue;
        }
        
        $rowsAffected = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);
        
        if ($rowsAffected === 0) {
            $errors[] = ['id' => $id, 'error' => 'Route not found'];
        } else {
            $updated[] = [
                'id' => $id, 
                'features' => $geojson ? count($geojson['features'] ?? []) : 0
            ];
        }
    }
    
    echo json_encode([
        'success' => count($errors) === 0,
        'updated' => $updated,
        'errors' => $errors,
        'summary' => [
            'total' => count($routes),
            'updated' => count($updated),
            'failed' => count($errors)
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
