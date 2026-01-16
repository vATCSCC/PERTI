<?php
/**
 * api/splits/areas.php - REST API for Sector Area Groups
 * 
 * GET    - List all areas (optionally filtered by ?artcc=XXX)
 * POST   - Create new area
 * PUT    - Update existing area (requires ?id=N)
 * PATCH  - Partial update (e.g., color only) (requires ?id=N)
 * DELETE - Delete area (requires ?id=N)
 */

// Absolute first: suppress any stray output
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    ob_end_clean();
    http_response_code(204);
    exit;
}

// Suppress PHP errors from appearing in output
ini_set('display_errors', '0');
error_reporting(0);

// Include the standalone ADL connection
require_once __DIR__ . '/connect_adl.php';

// Clear any accidental output, start fresh buffer for JSON
ob_end_clean();
ob_start();

// Check connection
if (!isset($conn_adl) || $conn_adl === false) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database connection failed',
        'details' => $conn_adl_error ?? 'Unknown error'
    ]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET':
            // List areas, optionally filtered by ARTCC
            $artcc = isset($_GET['artcc']) ? get_upper('artcc') : null;
            
            if ($artcc) {
                $sql = "SELECT id, artcc, area_name, sectors, description, color, created_by, 
                               FORMAT(created_at, 'yyyy-MM-dd HH:mm:ss') as created_at,
                               FORMAT(updated_at, 'yyyy-MM-dd HH:mm:ss') as updated_at
                        FROM splits_areas 
                        WHERE artcc = ? 
                        ORDER BY area_name";
                $params = [$artcc];
            } else {
                $sql = "SELECT id, artcc, area_name, sectors, description, color, created_by,
                               FORMAT(created_at, 'yyyy-MM-dd HH:mm:ss') as created_at,
                               FORMAT(updated_at, 'yyyy-MM-dd HH:mm:ss') as updated_at
                        FROM splits_areas 
                        ORDER BY artcc, area_name";
                $params = [];
            }
            
            $stmt = sqlsrv_query($conn_adl, $sql, $params);
            
            if ($stmt === false) {
                throw new Exception("Query failed: " . adl_sql_error_message());
            }
            
            $areas = [];
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                // Parse sectors JSON if stored as string
                if (isset($row['sectors']) && is_string($row['sectors'])) {
                    $row['sectors'] = json_decode($row['sectors'], true) ?? [];
                }
                $areas[] = $row;
            }
            sqlsrv_free_stmt($stmt);
            
            echo json_encode(['areas' => $areas]);
            break;
            
        case 'POST':
            // Create new area
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid JSON input']);
                exit;
            }
            
            // Validate required fields
            $required = ['artcc', 'area_name', 'sectors'];
            foreach ($required as $field) {
                if (empty($input[$field])) {
                    http_response_code(400);
                    echo json_encode(['error' => "Missing required field: $field"]);
                    exit;
                }
            }
            
            $artcc = strtoupper(trim($input['artcc']));
            $area_name = trim($input['area_name']);
            $sectors = is_array($input['sectors']) ? json_encode($input['sectors']) : $input['sectors'];
            $description = $input['description'] ?? '';
            $color = $input['color'] ?? null;
            $created_by = $input['created_by'] ?? 'system';
            
            $sql = "INSERT INTO splits_areas (artcc, area_name, sectors, description, color, created_by, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, GETUTCDATE(), GETUTCDATE());
                    SELECT SCOPE_IDENTITY() AS id;";
            
            $params = [$artcc, $area_name, $sectors, $description, $color, $created_by];
            $stmt = sqlsrv_query($conn_adl, $sql, $params);
            
            if ($stmt === false) {
                throw new Exception("Insert failed: " . adl_sql_error_message());
            }
            
            // Get the inserted ID
            sqlsrv_next_result($stmt);
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            $new_id = $row['id'] ?? null;
            sqlsrv_free_stmt($stmt);
            
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'id' => $new_id,
                'message' => "Area '$area_name' created successfully"
            ]);
            break;
            
        case 'PUT':
            // Update existing area (full update)
            if (!isset($_GET['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing area ID in query string']);
                exit;
            }
            
            $id = get_int('id');
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid JSON input']);
                exit;
            }
            
            // Build update query dynamically based on provided fields
            $updates = [];
            $params = [];
            
            if (isset($input['area_name'])) {
                $updates[] = "area_name = ?";
                $params[] = trim($input['area_name']);
            }
            if (isset($input['sectors'])) {
                $updates[] = "sectors = ?";
                $params[] = is_array($input['sectors']) ? json_encode($input['sectors']) : $input['sectors'];
            }
            if (isset($input['description'])) {
                $updates[] = "description = ?";
                $params[] = $input['description'];
            }
            if (array_key_exists('color', $input)) {
                $updates[] = "color = ?";
                $params[] = $input['color'];
            }
            
            if (empty($updates)) {
                http_response_code(400);
                echo json_encode(['error' => 'No fields to update']);
                exit;
            }
            
            $updates[] = "updated_at = GETUTCDATE()";
            $params[] = $id;
            
            $sql = "UPDATE splits_areas SET " . implode(", ", $updates) . " WHERE id = ?";
            $stmt = sqlsrv_query($conn_adl, $sql, $params);
            
            if ($stmt === false) {
                throw new Exception("Update failed: " . adl_sql_error_message());
            }
            
            $affected = sqlsrv_rows_affected($stmt);
            sqlsrv_free_stmt($stmt);
            
            if ($affected === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Area not found']);
                exit;
            }
            
            echo json_encode(['success' => true, 'message' => 'Area updated successfully']);
            break;
            
        case 'PATCH':
            // Partial update (e.g., color only)
            if (!isset($_GET['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing area ID in query string']);
                exit;
            }
            
            $id = get_int('id');
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid JSON input']);
                exit;
            }
            
            // Build update query for provided fields only
            $updates = [];
            $params = [];
            
            if (array_key_exists('color', $input)) {
                $updates[] = "color = ?";
                $params[] = $input['color'];
            }
            if (array_key_exists('description', $input)) {
                $updates[] = "description = ?";
                $params[] = $input['description'];
            }
            
            if (empty($updates)) {
                http_response_code(400);
                echo json_encode(['error' => 'No valid fields to update']);
                exit;
            }
            
            $updates[] = "updated_at = GETUTCDATE()";
            $params[] = $id;
            
            $sql = "UPDATE splits_areas SET " . implode(", ", $updates) . " WHERE id = ?";
            $stmt = sqlsrv_query($conn_adl, $sql, $params);
            
            if ($stmt === false) {
                throw new Exception("Update failed: " . adl_sql_error_message());
            }
            
            $affected = sqlsrv_rows_affected($stmt);
            sqlsrv_free_stmt($stmt);
            
            if ($affected === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Area not found']);
                exit;
            }
            
            echo json_encode(['success' => true, 'message' => 'Area updated successfully']);
            break;
            
        case 'DELETE':
            // Delete area
            if (!isset($_GET['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing area ID in query string']);
                exit;
            }
            
            $id = get_int('id');
            
            $sql = "DELETE FROM splits_areas WHERE id = ?";
            $stmt = sqlsrv_query($conn_adl, $sql, [$id]);
            
            if ($stmt === false) {
                throw new Exception("Delete failed: " . adl_sql_error_message());
            }
            
            $affected = sqlsrv_rows_affected($stmt);
            sqlsrv_free_stmt($stmt);
            
            if ($affected === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Area not found']);
                exit;
            }
            
            echo json_encode(['success' => true, 'message' => 'Area deleted successfully']);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error',
        'message' => $e->getMessage()
    ]);
}

ob_end_flush();
?>
