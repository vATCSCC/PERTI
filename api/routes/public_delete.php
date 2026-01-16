<?php
/**
 * api/routes/public_delete.php
 * DELETE/POST: Delete or deactivate a public route
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../load/connect.php';

try {
    if (!isset($conn_adl)) {
        throw new Exception('Database connection not available');
    }
    
    // Get route ID from query string or JSON body
    $id = null;
    $hardDelete = false;
    
    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $hardDelete = isset($_GET['hard']) && $_GET['hard'] === '1';
    } else {
        $input = json_decode(file_get_contents('php://input'), true);
        if ($input) {
            $id = isset($input['id']) ? (int)$input['id'] : null;
            $hardDelete = isset($input['hard']) && $input['hard'];
        }
    }
    
    if (!$id) {
        throw new Exception('Missing route ID');
    }
    
    if ($hardDelete) {
        // Permanently delete the route
        $sql = "DELETE FROM dbo.public_routes WHERE id = ?";
        $stmt = sqlsrv_query($conn_adl, $sql, [$id]);
        
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            throw new Exception('Delete failed: ' . ($errors[0]['message'] ?? 'Unknown error'));
        }
        
        $affected = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);
        
        echo json_encode([
            'success' => true,
            'message' => $affected > 0 ? 'Route permanently deleted' : 'Route not found',
            'affected' => $affected
        ]);
    } else {
        // Soft delete - set status to inactive
        $sql = "UPDATE dbo.public_routes SET status = 0, updated_utc = SYSUTCDATETIME() WHERE id = ?";
        $stmt = sqlsrv_query($conn_adl, $sql, [$id]);
        
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            throw new Exception('Deactivate failed: ' . ($errors[0]['message'] ?? 'Unknown error'));
        }
        
        $affected = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);
        
        echo json_encode([
            'success' => true,
            'message' => $affected > 0 ? 'Route deactivated' : 'Route not found',
            'affected' => $affected
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
