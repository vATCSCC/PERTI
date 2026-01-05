<?php
/**
 * Scheduled Splits API
 * 
 * Returns configurations with status = 'scheduled' or configs with start_time_utc in the future
 * Supports GET (list), PUT (update), DELETE operations
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/connect_adl.php';

if (!$conn_adl) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed', 'details' => $conn_adl_error]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Get scheduled configs (status = 'scheduled' OR start_time_utc > now with status = 'draft')
    // Ordered by start_time ascending (soonest first)
    $sql = "SELECT id, artcc, config_name, status, start_time_utc, end_time_utc, created_at, updated_at
            FROM splits_configs 
            WHERE status = 'scheduled' 
               OR (start_time_utc > GETUTCDATE() AND status IN ('draft', 'scheduled'))
            ORDER BY start_time_utc ASC, artcc ASC";
    
    $stmt = sqlsrv_query($conn_adl, $sql);
    
    if ($stmt === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Query failed', 'details' => adl_sql_error_message()]);
        exit;
    }
    
    $configs = [];
    $config_ids = [];
    
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Convert DateTime objects to strings
        foreach (['start_time_utc', 'end_time_utc', 'created_at', 'updated_at'] as $field) {
            if (isset($row[$field]) && $row[$field] instanceof DateTime) {
                $row[$field] = $row[$field]->format('Y-m-d H:i:s');
            }
        }
        $row['positions'] = [];
        $configs[$row['id']] = $row;
        $config_ids[] = $row['id'];
    }
    sqlsrv_free_stmt($stmt);
    
    // Get positions if we have configs
    if (!empty($config_ids)) {
        $placeholders = implode(',', array_fill(0, count($config_ids), '?'));
        $sql = "SELECT config_id, position_name, color, sectors, sort_order,
                       frequency, controller_oi, filters, start_time_utc, end_time_utc
                FROM splits_positions 
                WHERE config_id IN ($placeholders)
                ORDER BY config_id, sort_order";
        
        $stmt = sqlsrv_query($conn_adl, $sql, $config_ids);
        
        // Fallback if new columns don't exist yet
        if ($stmt === false) {
            $sql = "SELECT config_id, position_name, color, sectors, sort_order, start_time_utc, end_time_utc
                    FROM splits_positions 
                    WHERE config_id IN ($placeholders)
                    ORDER BY config_id, sort_order";
            $stmt = sqlsrv_query($conn_adl, $sql, $config_ids);
        }
        
        if ($stmt !== false) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $cid = $row['config_id'];
                unset($row['config_id']);
                if (isset($row['sectors']) && is_string($row['sectors'])) {
                    $row['sectors'] = json_decode($row['sectors'], true) ?? [];
                }
                // Parse filters JSON if present
                if (isset($row['filters']) && is_string($row['filters'])) {
                    $row['filters'] = json_decode($row['filters'], true);
                }
                // Convert DateTime objects to strings
                foreach (['start_time_utc', 'end_time_utc'] as $field) {
                    if (isset($row[$field]) && $row[$field] instanceof DateTime) {
                        $row[$field] = $row[$field]->format('Y-m-d H:i:s');
                    }
                }
                if (isset($configs[$cid])) {
                    $configs[$cid]['positions'][] = $row;
                }
            }
            sqlsrv_free_stmt($stmt);
        }
    }
    
    echo json_encode([
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'configs' => array_values($configs)
    ]);
    exit;
}

if ($method === 'PUT') {
    // Update a scheduled config's status (e.g., activate it or cancel it)
    $id = $_GET['id'] ?? null;
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing config id']);
        exit;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body']);
        exit;
    }
    
    // Build update query dynamically based on provided fields
    $updates = [];
    $params = [];
    
    if (isset($input['status'])) {
        $updates[] = 'status = ?';
        $params[] = $input['status'];
    }
    if (array_key_exists('start_time_utc', $input)) {
        $updates[] = 'start_time_utc = ?';
        $start = $input['start_time_utc'];
        if (!empty($start)) {
            $start = str_replace('T', ' ', $start);
            if (strlen($start) === 16) $start .= ':00';
        } else {
            $start = null;
        }
        $params[] = $start;
    }
    if (array_key_exists('end_time_utc', $input)) {
        $updates[] = 'end_time_utc = ?';
        $end = $input['end_time_utc'];
        if (!empty($end)) {
            $end = str_replace('T', ' ', $end);
            if (strlen($end) === 16) $end .= ':00';
        } else {
            $end = null;
        }
        $params[] = $end;
    }
    
    // Always update updated_at
    $updates[] = 'updated_at = GETUTCDATE()';
    
    if (count($updates) <= 1) { // Only has updated_at
        http_response_code(400);
        echo json_encode(['error' => 'No fields to update']);
        exit;
    }
    
    $sql = "UPDATE splits_configs SET " . implode(', ', $updates) . " WHERE id = ?";
    $params[] = $id;
    
    $stmt = sqlsrv_query($conn_adl, $sql, $params);
    
    if ($stmt === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Update failed', 'details' => adl_sql_error_message()]);
        exit;
    }
    
    $rows_affected = sqlsrv_rows_affected($stmt);
    sqlsrv_free_stmt($stmt);
    
    if ($rows_affected === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Config not found or no changes made']);
        exit;
    }
    
    echo json_encode(['success' => true, 'message' => 'Scheduled config updated']);
    exit;
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? null;
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing config id']);
        exit;
    }
    
    // Delete positions first (foreign key)
    $sql = "DELETE FROM splits_positions WHERE config_id = ?";
    sqlsrv_query($conn_adl, $sql, [$id]);
    
    // Delete config
    $sql = "DELETE FROM splits_configs WHERE id = ?";
    $stmt = sqlsrv_query($conn_adl, $sql, [$id]);
    
    if ($stmt === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Delete failed', 'details' => adl_sql_error_message()]);
        exit;
    }
    
    $rows_affected = sqlsrv_rows_affected($stmt);
    sqlsrv_free_stmt($stmt);
    
    if ($rows_affected === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Config not found']);
        exit;
    }
    
    echo json_encode(['success' => true, 'message' => 'Scheduled config deleted']);
    exit;
}

echo json_encode(['error' => 'Method not supported: ' . $method]);
