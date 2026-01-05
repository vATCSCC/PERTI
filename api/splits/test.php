<?php
/**
 * api/splits/test.php - Debug endpoint to check database connectivity and schema
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/connect_adl.php';

$results = [
    'timestamp' => gmdate('Y-m-d H:i:s') . ' UTC',
    'connection' => null,
    'tables' => [],
    'test_queries' => []
];

// Check connection
if (!isset($conn_adl) || $conn_adl === false) {
    $results['connection'] = [
        'status' => 'FAILED',
        'error' => $conn_adl_error ?? 'Unknown error'
    ];
    echo json_encode($results, JSON_PRETTY_PRINT);
    exit;
}

$results['connection'] = ['status' => 'OK'];

// Check if tables exist
$tables_to_check = ['splits_areas', 'splits_configs', 'splits_positions'];

foreach ($tables_to_check as $table) {
    $sql = "SELECT TOP 1 * FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ?";
    $stmt = sqlsrv_query($conn_adl, $sql, [$table]);
    
    if ($stmt && sqlsrv_fetch_array($stmt)) {
        // Table exists, get column info
        $sql2 = "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE 
                 FROM INFORMATION_SCHEMA.COLUMNS 
                 WHERE TABLE_NAME = ? 
                 ORDER BY ORDINAL_POSITION";
        $stmt2 = sqlsrv_query($conn_adl, $sql2, [$table]);
        
        $columns = [];
        while ($row = sqlsrv_fetch_array($stmt2, SQLSRV_FETCH_ASSOC)) {
            $columns[] = $row['COLUMN_NAME'] . ' (' . $row['DATA_TYPE'] . ')';
        }
        
        $results['tables'][$table] = [
            'exists' => true,
            'columns' => $columns
        ];
        
        // Count rows
        $sql3 = "SELECT COUNT(*) as cnt FROM [$table]";
        $stmt3 = sqlsrv_query($conn_adl, $sql3);
        if ($stmt3) {
            $row = sqlsrv_fetch_array($stmt3, SQLSRV_FETCH_ASSOC);
            $results['tables'][$table]['row_count'] = $row['cnt'];
        }
    } else {
        $results['tables'][$table] = [
            'exists' => false,
            'error' => 'Table not found'
        ];
    }
}

// Test simple query on splits_configs
$sql = "SELECT TOP 5 id, artcc, config_name, status FROM splits_configs ORDER BY id DESC";
$stmt = sqlsrv_query($conn_adl, $sql);

if ($stmt === false) {
    $results['test_queries']['splits_configs_select'] = [
        'status' => 'FAILED',
        'error' => adl_sql_error_message()
    ];
} else {
    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $row;
    }
    $results['test_queries']['splits_configs_select'] = [
        'status' => 'OK',
        'rows' => $rows
    ];
}

// Test simple query on splits_areas
$sql = "SELECT TOP 5 id, artcc, area_name FROM splits_areas ORDER BY id DESC";
$stmt = sqlsrv_query($conn_adl, $sql);

if ($stmt === false) {
    $results['test_queries']['splits_areas_select'] = [
        'status' => 'FAILED',
        'error' => adl_sql_error_message()
    ];
} else {
    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $row;
    }
    $results['test_queries']['splits_areas_select'] = [
        'status' => 'OK',
        'rows' => $rows
    ];
}

echo json_encode($results, JSON_PRETTY_PRINT);
?>
