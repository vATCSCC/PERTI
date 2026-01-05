<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
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
    $id = $_GET['id'] ?? null;
    
    // Single config with positions
    if ($id) {
        $sql = "SELECT id, artcc, config_name, status, start_time_utc, end_time_utc, created_at, updated_at
                FROM splits_configs WHERE id = ?";
        $stmt = sqlsrv_query($conn_adl, $sql, [$id]);
        
        if ($stmt === false) {
            http_response_code(500);
            echo json_encode(['error' => 'Query failed', 'details' => adl_sql_error_message()]);
            exit;
        }
        
        $config = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        
        if (!$config) {
            http_response_code(404);
            echo json_encode(['error' => 'Config not found']);
            exit;
        }
        
        // Convert DateTime objects
        foreach (['start_time_utc', 'end_time_utc', 'created_at', 'updated_at'] as $field) {
            if (isset($config[$field]) && $config[$field] instanceof DateTime) {
                $config[$field] = $config[$field]->format('Y-m-d H:i:s');
            }
        }
        
        // Get positions - try with new columns first, fallback if they don't exist
        $sql = "SELECT position_name, color, sectors, sort_order, start_time_utc, end_time_utc, frequency, controller_oi, filters
                FROM splits_positions WHERE config_id = ? ORDER BY sort_order";
        $stmt = sqlsrv_query($conn_adl, $sql, [$id]);
        
        // Fallback if new columns don't exist
        if ($stmt === false) {
            $sql = "SELECT position_name, color, sectors, sort_order, start_time_utc, end_time_utc
                    FROM splits_positions WHERE config_id = ? ORDER BY sort_order";
            $stmt = sqlsrv_query($conn_adl, $sql, [$id]);
        }
        
        $config['positions'] = [];
        if ($stmt !== false) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                if (isset($row['sectors']) && is_string($row['sectors'])) {
                    $row['sectors'] = json_decode($row['sectors'], true) ?? [];
                }
                // Parse filters JSON if present
                if (isset($row['filters']) && is_string($row['filters'])) {
                    $row['filters'] = json_decode($row['filters'], true);
                }
                foreach (['start_time_utc', 'end_time_utc'] as $field) {
                    if (isset($row[$field]) && $row[$field] instanceof DateTime) {
                        $row[$field] = $row[$field]->format('Y-m-d H:i:s');
                    }
                }
                $config['positions'][] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }
        
        echo json_encode(['config' => $config]);
        exit;
    }
    
    // List all configs with position counts
    $sql = "SELECT c.id, c.artcc, c.config_name, c.status, c.created_at,
                   (SELECT COUNT(*) FROM splits_positions WHERE config_id = c.id) as position_count
            FROM splits_configs c
            ORDER BY c.id DESC";
    $stmt = sqlsrv_query($conn_adl, $sql);
    
    if ($stmt === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Query failed', 'details' => adl_sql_error_message()]);
        exit;
    }
    
    $configs = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Convert DateTime to string
        if ($row['created_at'] instanceof DateTime) {
            $row['created_at'] = $row['created_at']->format('Y-m-d H:i:s');
        }
        $configs[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    
    echo json_encode(['configs' => $configs]);
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
    
    echo json_encode(['success' => true, 'message' => 'Config deleted']);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || empty($input['artcc']) || empty($input['config_name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing artcc or config_name']);
        exit;
    }
    
    $artcc = strtoupper(trim($input['artcc']));
    $config_name = trim($input['config_name']);
    $status = $input['status'] ?? 'draft';
    $created_by = $input['created_by'] ?? 'system';
    
    // Handle datetime - convert from datetime-local format if needed
    $start_time = null;
    $end_time = null;
    if (!empty($input['start_time_utc'])) {
        $start_time = str_replace('T', ' ', $input['start_time_utc']);
        if (strlen($start_time) === 16) $start_time .= ':00'; // Add seconds if missing
    }
    if (!empty($input['end_time_utc'])) {
        $end_time = str_replace('T', ' ', $input['end_time_utc']);
        if (strlen($end_time) === 16) $end_time .= ':00';
    }
    
    $positions = $input['positions'] ?? [];
    
    // Insert config and get ID in one query using OUTPUT clause
    $sql = "INSERT INTO splits_configs (artcc, config_name, start_time_utc, end_time_utc, status, created_by, created_at, updated_at)
            OUTPUT INSERTED.id
            VALUES (?, ?, ?, ?, ?, ?, GETUTCDATE(), GETUTCDATE())";
    
    $stmt = sqlsrv_query($conn_adl, $sql, [$artcc, $config_name, $start_time, $end_time, $status, $created_by]);
    
    if ($stmt === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Insert failed', 'details' => adl_sql_error_message()]);
        exit;
    }
    
    // Get the inserted ID from OUTPUT
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $config_id = $row['id'] ?? null;
    sqlsrv_free_stmt($stmt);
    
    if (!$config_id) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to get config ID after insert']);
        exit;
    }
    
    // Insert positions
    $positions_inserted = 0;
    foreach ($positions as $index => $pos) {
        $pos_name = trim($pos['position_name'] ?? '');
        if (empty($pos_name)) continue;
        
        $pos_color = $pos['color'] ?? '#808080';
        $pos_sectors = is_array($pos['sectors']) ? json_encode($pos['sectors']) : ($pos['sectors'] ?? '[]');
        $pos_order = $pos['sort_order'] ?? ($index + 1);
        $pos_frequency = isset($pos['frequency']) ? trim($pos['frequency']) : null;
        $pos_oi = isset($pos['controller_oi']) ? strtoupper(trim($pos['controller_oi'])) : null;
        $pos_filters = isset($pos['filters']) ? (is_array($pos['filters']) ? json_encode($pos['filters']) : $pos['filters']) : null;
        
        $sql = "INSERT INTO splits_positions (config_id, position_name, color, sectors, sort_order, frequency, controller_oi, filters, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, GETUTCDATE())";
        
        $pos_stmt = sqlsrv_query($conn_adl, $sql, [$config_id, $pos_name, $pos_color, $pos_sectors, $pos_order, $pos_frequency, $pos_oi, $pos_filters]);
        if ($pos_stmt !== false) {
            $positions_inserted++;
            sqlsrv_free_stmt($pos_stmt);
        } else {
            // If new columns don't exist, try without them
            $sql = "INSERT INTO splits_positions (config_id, position_name, color, sectors, sort_order, created_at)
                    VALUES (?, ?, ?, ?, ?, GETUTCDATE())";
            $pos_stmt = sqlsrv_query($conn_adl, $sql, [$config_id, $pos_name, $pos_color, $pos_sectors, $pos_order]);
            if ($pos_stmt !== false) {
                $positions_inserted++;
                sqlsrv_free_stmt($pos_stmt);
            }
        }
    }
    
    http_response_code(201);
    echo json_encode([
        'success' => true, 
        'id' => $config_id, 
        'positions_inserted' => $positions_inserted,
        'message' => "Config created with $positions_inserted positions"
    ]);
    exit;
}

if ($method === 'PUT') {
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
    
    if (isset($input['config_name'])) {
        $updates[] = 'config_name = ?';
        $params[] = trim($input['config_name']);
    }
    if (isset($input['artcc'])) {
        $updates[] = 'artcc = ?';
        $params[] = strtoupper(trim($input['artcc']));
    }
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
    
    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['error' => 'No fields to update']);
        exit;
    }
    
    // Update config
    $sql = "UPDATE splits_configs SET " . implode(', ', $updates) . " WHERE id = ?";
    $params[] = $id;
    
    $stmt = sqlsrv_query($conn_adl, $sql, $params);
    
    if ($stmt === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Update failed', 'details' => adl_sql_error_message()]);
        exit;
    }
    sqlsrv_free_stmt($stmt);
    
    // If positions are provided, replace them
    if (isset($input['positions']) && is_array($input['positions'])) {
        // Delete existing positions
        $sql = "DELETE FROM splits_positions WHERE config_id = ?";
        sqlsrv_query($conn_adl, $sql, [$id]);
        
        // Insert new positions
        $positions_inserted = 0;
        foreach ($input['positions'] as $index => $pos) {
            $pos_name = trim($pos['position_name'] ?? '');
            if (empty($pos_name)) continue;
            
            $pos_color = $pos['color'] ?? '#808080';
            $pos_sectors = is_array($pos['sectors']) ? json_encode($pos['sectors']) : ($pos['sectors'] ?? '[]');
            $pos_order = $pos['sort_order'] ?? ($index + 1);
            $pos_frequency = isset($pos['frequency']) ? trim($pos['frequency']) : null;
            $pos_oi = isset($pos['controller_oi']) ? strtoupper(trim($pos['controller_oi'])) : null;
            $pos_filters = isset($pos['filters']) ? (is_array($pos['filters']) ? json_encode($pos['filters']) : $pos['filters']) : null;
            
            $sql = "INSERT INTO splits_positions (config_id, position_name, color, sectors, sort_order, frequency, controller_oi, filters, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, GETUTCDATE())";
            
            $pos_stmt = sqlsrv_query($conn_adl, $sql, [$id, $pos_name, $pos_color, $pos_sectors, $pos_order, $pos_frequency, $pos_oi, $pos_filters]);
            if ($pos_stmt !== false) {
                $positions_inserted++;
                sqlsrv_free_stmt($pos_stmt);
            } else {
                // Fallback if new columns don't exist
                $sql = "INSERT INTO splits_positions (config_id, position_name, color, sectors, sort_order, created_at)
                        VALUES (?, ?, ?, ?, ?, GETUTCDATE())";
                $pos_stmt = sqlsrv_query($conn_adl, $sql, [$id, $pos_name, $pos_color, $pos_sectors, $pos_order]);
                if ($pos_stmt !== false) {
                    $positions_inserted++;
                    sqlsrv_free_stmt($pos_stmt);
                }
            }
        }
        
        echo json_encode([
            'success' => true,
            'id' => $id,
            'positions_updated' => $positions_inserted,
            'message' => "Config updated with $positions_inserted positions"
        ]);
    } else {
        echo json_encode(['success' => true, 'id' => $id, 'message' => 'Config updated']);
    }
    exit;
}

echo json_encode(['error' => 'Method not supported: ' . $method]);
