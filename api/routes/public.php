<?php
/**
 * api/routes/public.php
 * GET: List public routes with optional filtering
 * 
 * Query parameters:
 *   filter = active (default) | future | past | all
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../load/connect.php';

try {
    if (!isset($conn_adl)) {
        throw new Exception('Database connection not available');
    }
    
    // Get filter parameter (default: active)
    $filter = isset($_GET['filter']) ? strtolower($_GET['filter']) : 'active';
    $validFilters = ['active', 'future', 'past', 'all'];
    if (!in_array($filter, $validFilters)) {
        $filter = 'active';
    }
    
    // Build SQL based on filter
    $now = gmdate('Y-m-d H:i:s');
    
    switch ($filter) {
        case 'future':
            // Routes that haven't started yet
            $sql = "SELECT * FROM dbo.public_routes 
                    WHERE valid_start_utc > ? 
                    ORDER BY valid_start_utc ASC";
            $params = [$now];
            break;
            
        case 'past':
            // Expired routes (ended in the past)
            $sql = "SELECT * FROM dbo.public_routes 
                    WHERE valid_end_utc < ? 
                    ORDER BY valid_end_utc DESC";
            $params = [$now];
            break;
            
        case 'all':
            // All routes
            $sql = "SELECT * FROM dbo.public_routes 
                    ORDER BY 
                        CASE 
                            WHEN valid_start_utc <= ? AND valid_end_utc >= ? THEN 0
                            WHEN valid_start_utc > ? THEN 1
                            ELSE 2
                        END,
                        valid_start_utc DESC";
            $params = [$now, $now, $now];
            break;
            
        case 'active':
        default:
            // Currently active routes (within validity period)
            $sql = "SELECT * FROM dbo.public_routes 
                    WHERE valid_start_utc <= ? AND valid_end_utc >= ?
                    ORDER BY valid_end_utc ASC";
            $params = [$now, $now];
            break;
    }
    
    $stmt = sqlsrv_query($conn_adl, $sql, $params);
    
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        throw new Exception('Query failed: ' . ($errors[0]['message'] ?? 'Unknown error'));
    }
    
    $routes = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Convert DateTime objects to ISO strings
        foreach (['valid_start_utc', 'valid_end_utc', 'created_utc', 'updated_utc'] as $dateField) {
            if (isset($row[$dateField]) && $row[$dateField] instanceof DateTime) {
                $row[$dateField] = $row[$dateField]->format('Y-m-d\TH:i:s\Z');
            }
        }
        
        // Parse JSON fields
        foreach (['origin_filter', 'dest_filter'] as $jsonField) {
            if (isset($row[$jsonField]) && is_string($row[$jsonField])) {
                $decoded = json_decode($row[$jsonField], true);
                $row[$jsonField] = $decoded !== null ? $decoded : $row[$jsonField];
            }
        }
        
        // Parse GeoJSON if present
        if (isset($row['route_geojson']) && is_string($row['route_geojson'])) {
            $decoded = json_decode($row['route_geojson'], true);
            $row['route_geojson'] = $decoded !== null ? $decoded : null;
        }
        
        // Add computed status field for UI
        $startTime = strtotime($row['valid_start_utc']);
        $endTime = strtotime($row['valid_end_utc']);
        $nowTime = time();
        
        if ($nowTime < $startTime) {
            $row['computed_status'] = 'future';
        } elseif ($nowTime > $endTime) {
            $row['computed_status'] = 'past';
        } else {
            $row['computed_status'] = 'active';
        }
        
        $routes[] = $row;
    }
    
    sqlsrv_free_stmt($stmt);
    
    echo json_encode([
        'success' => true,
        'filter' => $filter,
        'count' => count($routes),
        'routes' => $routes,
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z')
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
