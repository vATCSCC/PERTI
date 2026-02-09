<?php
/**
 * TRACON Data API
 * 
 * Returns TRACON information from [dbo].[tracons] table
 * 
 * GET: Returns all TRACONs or a specific one by ID
 *      ?id=PCT  - Get specific TRACON by tracon_id
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Use standalone ADL connection to avoid MySQL PDO errors
require_once __DIR__ . '/connect_adl.php';

if (!$conn_adl) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

try {
    $traconId = isset($_GET['id']) ? trim($_GET['id']) : null;
    
    // First, check what columns exist in the tracons table
    $columnCheckSql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tracons' AND TABLE_SCHEMA = 'dbo'";
    $columnStmt = sqlsrv_query($conn_adl, $columnCheckSql);
    
    $columns = [];
    if ($columnStmt) {
        while ($row = sqlsrv_fetch_array($columnStmt, SQLSRV_FETCH_ASSOC)) {
            $columns[] = strtolower($row['COLUMN_NAME']);
        }
        sqlsrv_free_stmt($columnStmt);
    }
    
    // Build SELECT clause based on available columns
    $selectCols = [];
    
    // Try to find the ID column (could be tracon_id, id, or tracon_name)
    if (in_array('tracon_id', $columns)) {
        $selectCols[] = 'tracon_id';
        $idColumn = 'tracon_id';
    } elseif (in_array('id', $columns)) {
        $selectCols[] = 'id as tracon_id';
        $idColumn = 'id';
    } else {
        $selectCols[] = 'tracon_name as tracon_id';
        $idColumn = 'tracon_name';
    }
    
    // Add other columns if they exist
    if (in_array('tracon_name', $columns)) $selectCols[] = 'tracon_name';
    if (in_array('airports_served', $columns)) $selectCols[] = 'airports_served';
    if (in_array('responsible_artcc', $columns)) $selectCols[] = 'responsible_artcc';
    if (in_array('dcc_region', $columns)) $selectCols[] = 'dcc_region';
    if (in_array('contains_aspm82', $columns)) $selectCols[] = 'contains_aspm82';
    if (in_array('contains_oep35', $columns)) $selectCols[] = 'contains_oep35';
    if (in_array('contains_core30', $columns)) $selectCols[] = 'contains_core30';
    
    $selectClause = implode(', ', $selectCols);
    
    if ($traconId) {
        // Get specific TRACON
        $sql = "SELECT {$selectClause} FROM [dbo].[tracons] WHERE {$idColumn} = ?";
        $stmt = sqlsrv_query($conn_adl, $sql, [$traconId]);
    } else {
        // Get all TRACONs
        $sql = "SELECT {$selectClause} FROM [dbo].[tracons] ORDER BY {$idColumn}";
        $stmt = sqlsrv_query($conn_adl, $sql);
    }
    
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        http_response_code(500);
        echo json_encode(['error' => 'Query failed', 'details' => $errors, 'sql' => $sql]);
        exit;
    }
    
    $tracons = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $tracon = [
            'tracon_id' => $row['tracon_id'] ?? null,
            'tracon_name' => $row['tracon_name'] ?? $row['tracon_id'] ?? null,
            'airports_served' => $row['airports_served'] ?? null,
            'responsible_artcc' => $row['responsible_artcc'] ?? null,
            'dcc_region' => $row['dcc_region'] ?? null,
            'contains_aspm82' => isset($row['contains_aspm82']) ? (bool)$row['contains_aspm82'] : null,
            'contains_oep35' => isset($row['contains_oep35']) ? (bool)$row['contains_oep35'] : null,
            'contains_core30' => isset($row['contains_core30']) ? (bool)$row['contains_core30'] : null
        ];
        $tracons[] = $tracon;
    }
    
    sqlsrv_free_stmt($stmt);
    
    if ($traconId && count($tracons) === 1) {
        // Return single TRACON object
        echo json_encode($tracons[0]);
    } else {
        echo json_encode($tracons);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
