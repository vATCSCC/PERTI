<?php
/**
 * SWIM Database Diagnostic Endpoint
 */
header('Content-Type: application/json');

// Minimal auth check
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (strpos($auth_header, 'swim_sys_vatcscc_internal_001') === false) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    require_once __DIR__ . '/../../load/config.php';
} catch (Throwable $e) {
    echo json_encode(['error' => 'Config load failed', 'message' => $e->getMessage()]);
    exit;
}

$results = [
    'step' => 'config_loaded',
    'swim_constants_defined' => [
        'SWIM_SQL_HOST' => defined('SWIM_SQL_HOST'),
        'SWIM_SQL_DATABASE' => defined('SWIM_SQL_DATABASE'),
        'SWIM_SQL_USERNAME' => defined('SWIM_SQL_USERNAME'),
        'SWIM_SQL_PASSWORD' => defined('SWIM_SQL_PASSWORD'),
    ],
];

// Check if sqlsrv is available
$results['sqlsrv_available'] = function_exists('sqlsrv_connect');

if (!$results['sqlsrv_available']) {
    $results['error'] = 'sqlsrv extension not loaded';
    echo json_encode($results, JSON_PRETTY_PRINT);
    exit;
}

if (!defined('SWIM_SQL_HOST') || !defined('SWIM_SQL_DATABASE') ||
    !defined('SWIM_SQL_USERNAME') || !defined('SWIM_SQL_PASSWORD')) {
    $results['error'] = 'SWIM SQL constants not defined';
    echo json_encode($results, JSON_PRETTY_PRINT);
    exit;
}

$results['step'] = 'connecting';
$results['server'] = SWIM_SQL_HOST;
$results['database'] = SWIM_SQL_DATABASE;

// Try to connect
$connectionInfo = [
    'Database' => SWIM_SQL_DATABASE,
    'UID' => SWIM_SQL_USERNAME,
    'PWD' => SWIM_SQL_PASSWORD,
    'Encrypt' => true,
    'TrustServerCertificate' => false,
    'LoginTimeout' => 30,
];

$conn = sqlsrv_connect(SWIM_SQL_HOST, $connectionInfo);

if ($conn === false) {
    $results['step'] = 'connection_failed';
    $results['errors'] = sqlsrv_errors();
    echo json_encode($results, JSON_PRETTY_PRINT);
    exit;
}

$results['step'] = 'connected';

// Get basic info
$dbQuery = sqlsrv_query($conn, "SELECT DB_NAME() AS db_name, CURRENT_USER AS cur_user");
if ($dbQuery) {
    $row = sqlsrv_fetch_array($dbQuery, SQLSRV_FETCH_ASSOC);
    $results['db_name'] = $row['db_name'];
    $results['cur_user'] = $row['cur_user'];
    sqlsrv_free_stmt($dbQuery);
}

// List tables
$tablesQuery = sqlsrv_query($conn, "
    SELECT TABLE_SCHEMA, TABLE_NAME
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_TYPE = 'BASE TABLE'
    ORDER BY TABLE_SCHEMA, TABLE_NAME
");
$results['tables'] = [];
if ($tablesQuery) {
    while ($row = sqlsrv_fetch_array($tablesQuery, SQLSRV_FETCH_ASSOC)) {
        $results['tables'][] = $row['TABLE_SCHEMA'] . '.' . $row['TABLE_NAME'];
    }
    sqlsrv_free_stmt($tablesQuery);
}

// Check swim_flights specifically
$sfCheck = sqlsrv_query($conn, "SELECT OBJECT_ID('dbo.swim_flights') AS obj_id");
if ($sfCheck) {
    $row = sqlsrv_fetch_array($sfCheck, SQLSRV_FETCH_ASSOC);
    $results['swim_flights_object_id'] = $row['obj_id'];
    sqlsrv_free_stmt($sfCheck);
}

// Get swim_flights columns if it exists
if (!empty($results['swim_flights_object_id'])) {
    $colQuery = sqlsrv_query($conn, "
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'swim_flights'
        ORDER BY ORDINAL_POSITION
    ");
    $results['swim_flights_columns'] = [];
    if ($colQuery) {
        while ($row = sqlsrv_fetch_array($colQuery, SQLSRV_FETCH_ASSOC)) {
            $results['swim_flights_columns'][] = $row['COLUMN_NAME'];
        }
        sqlsrv_free_stmt($colQuery);
    }
}

sqlsrv_close($conn);

$results['step'] = 'done';
$results['timestamp'] = gmdate('c');

echo json_encode($results, JSON_PRETTY_PRINT);
