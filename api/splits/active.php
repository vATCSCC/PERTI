<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
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

$sql = "SELECT id, artcc, config_name, status, start_time_utc, end_time_utc FROM splits_configs WHERE status = 'active' ORDER BY artcc";
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
    foreach (['start_time_utc', 'end_time_utc'] as $field) {
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
