<?php
/**
 * SWIM Database Diagnostic Endpoint
 *
 * Checks database connectivity, table existence, and permissions.
 */

require_once __DIR__ . '/../../load/config.php';
require_once __DIR__ . '/../../load/connect.php';

header('Content-Type: application/json');

// Auth check
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/Bearer\s+swim_sys_vatcscc_internal_001$/i', $auth_header)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

global $conn_swim;

$results = [
    'connection' => null,
    'database_name' => null,
    'current_user' => null,
    'tables' => [],
    'swim_flights_exists' => false,
    'swim_flights_schema' => null,
    'swim_flights_columns' => [],
    'permissions' => [],
];

if (!$conn_swim) {
    $results['connection'] = 'FAILED - no connection';
    echo json_encode($results, JSON_PRETTY_PRINT);
    exit;
}

$results['connection'] = 'OK';

// Get current database name
$dbQuery = sqlsrv_query($conn_swim, "SELECT DB_NAME() AS db_name");
if ($dbQuery && $row = sqlsrv_fetch_array($dbQuery, SQLSRV_FETCH_ASSOC)) {
    $results['database_name'] = $row['db_name'];
}
sqlsrv_free_stmt($dbQuery);

// Get current user
$userQuery = sqlsrv_query($conn_swim, "SELECT CURRENT_USER AS current_user, SYSTEM_USER AS system_user");
if ($userQuery && $row = sqlsrv_fetch_array($userQuery, SQLSRV_FETCH_ASSOC)) {
    $results['current_user'] = $row;
}
sqlsrv_free_stmt($userQuery);

// List all tables
$tablesQuery = sqlsrv_query($conn_swim, "
    SELECT TABLE_SCHEMA, TABLE_NAME, TABLE_TYPE
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_TYPE = 'BASE TABLE'
    ORDER BY TABLE_SCHEMA, TABLE_NAME
");
if ($tablesQuery) {
    while ($row = sqlsrv_fetch_array($tablesQuery, SQLSRV_FETCH_ASSOC)) {
        $results['tables'][] = $row;
        if ($row['TABLE_NAME'] === 'swim_flights') {
            $results['swim_flights_exists'] = true;
            $results['swim_flights_schema'] = $row['TABLE_SCHEMA'];
        }
    }
    sqlsrv_free_stmt($tablesQuery);
}

// If swim_flights exists, get its columns
if ($results['swim_flights_exists']) {
    $schema = $results['swim_flights_schema'];
    $colQuery = sqlsrv_query($conn_swim, "
        SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = '$schema' AND TABLE_NAME = 'swim_flights'
        ORDER BY ORDINAL_POSITION
    ");
    if ($colQuery) {
        while ($row = sqlsrv_fetch_array($colQuery, SQLSRV_FETCH_ASSOC)) {
            $results['swim_flights_columns'][] = $row;
        }
        sqlsrv_free_stmt($colQuery);
    }
}

// Check permissions on swim_flights
$permQuery = sqlsrv_query($conn_swim, "
    SELECT
        HAS_PERMS_BY_NAME('dbo.swim_flights', 'OBJECT', 'SELECT') AS can_select,
        HAS_PERMS_BY_NAME('dbo.swim_flights', 'OBJECT', 'INSERT') AS can_insert,
        HAS_PERMS_BY_NAME('dbo.swim_flights', 'OBJECT', 'UPDATE') AS can_update,
        HAS_PERMS_BY_NAME('dbo.swim_flights', 'OBJECT', 'DELETE') AS can_delete,
        HAS_PERMS_BY_NAME('dbo.swim_flights', 'OBJECT', 'ALTER') AS can_alter
");
if ($permQuery && $row = sqlsrv_fetch_array($permQuery, SQLSRV_FETCH_ASSOC)) {
    $results['permissions'] = $row;
}
sqlsrv_free_stmt($permQuery);

// Check db-level ALTER permission
$dbPermQuery = sqlsrv_query($conn_swim, "SELECT HAS_PERMS_BY_NAME(DB_NAME(), 'DATABASE', 'ALTER') AS can_alter_db");
if ($dbPermQuery && $row = sqlsrv_fetch_array($dbPermQuery, SQLSRV_FETCH_ASSOC)) {
    $results['permissions']['can_alter_db'] = $row['can_alter_db'];
}
sqlsrv_free_stmt($dbPermQuery);

$results['timestamp'] = gmdate('c');

echo json_encode($results, JSON_PRETTY_PRINT);
