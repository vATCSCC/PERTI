<?php
/**
 * Migration 013 Runner - Web Endpoint
 *
 * Run via: https://perti.vatcscc.org/api/admin/run_migration_013.php
 * Requires admin authentication via VATSIM OAuth session.
 *
 * Adds current_sector column and related FIXM fields to swim_flights table.
 */

// Load config
require_once __DIR__ . '/../../load/config.php';
require_once __DIR__ . '/../../load/connect.php';

// Session check - require admin
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Check for authorization - require logged in admin OR internal API key
$authorized = false;
$auth_method = 'none';

// Check session-based admin auth
if (isset($_SESSION['VATSIM_CID']) && !empty($_SESSION['VATSIM_CID'])) {
    // Check if user is admin
    global $conn_sqli;
    $cid = $_SESSION['VATSIM_CID'];
    $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");
    if ($p_check && $p_check->num_rows > 0) {
        $authorized = true;
        $auth_method = 'session_admin';
    }
}

// Check API key auth (system tier only)
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!$authorized && preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
    $api_key = $matches[1];
    if ($api_key === 'swim_sys_vatcscc_internal_001') {
        $authorized = true;
        $auth_method = 'api_key';
    }
}

if (!$authorized) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized. Admin access required.',
    ]);
    exit;
}

// Get SWIM connection
global $conn_swim;
if (!$conn_swim) {
    echo json_encode([
        'success' => false,
        'error' => 'SWIM database connection not available',
    ]);
    exit;
}

$results = [
    'success' => true,
    'auth_method' => $auth_method,
    'migration' => '013_swim_fixm_airspace_position',
    'database' => 'SWIM_API',
    'table' => 'swim_flights',
    'columns' => [],
    'indexes' => [],
    'added' => 0,
    'skipped' => 0,
    'errors' => 0,
    'diagnostics' => [],
];

// Add diagnostics about the database and table
$dbQuery = sqlsrv_query($conn_swim, "SELECT DB_NAME() AS db_name, CURRENT_USER AS cur_user, SYSTEM_USER AS sys_user");
if ($dbQuery && $row = sqlsrv_fetch_array($dbQuery, SQLSRV_FETCH_ASSOC)) {
    $results['diagnostics']['db_name'] = $row['db_name'];
    $results['diagnostics']['current_user'] = $row['cur_user'];
    $results['diagnostics']['system_user'] = $row['sys_user'];
    sqlsrv_free_stmt($dbQuery);
}

// Check if swim_flights table exists using INFORMATION_SCHEMA
$tableCheck = sqlsrv_query($conn_swim, "SELECT TABLE_SCHEMA, TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'swim_flights'");
if ($tableCheck) {
    $tableRow = sqlsrv_fetch_array($tableCheck, SQLSRV_FETCH_ASSOC);
    if ($tableRow) {
        $results['diagnostics']['table_found'] = true;
        $results['diagnostics']['table_schema'] = $tableRow['TABLE_SCHEMA'];
    } else {
        $results['diagnostics']['table_found'] = false;
    }
    sqlsrv_free_stmt($tableCheck);
}

// If table doesn't exist, we can't proceed
if (!isset($results['diagnostics']['table_found']) || !$results['diagnostics']['table_found']) {
    $results['success'] = false;
    $results['error'] = 'swim_flights table not found. Check database permissions or table existence.';
    echo json_encode($results, JSON_PRETTY_PRINT);
    exit;
}

$tableSchema = $results['diagnostics']['table_schema'] ?? 'dbo';

// Define columns to add
$columns = [
    ['name' => 'current_airspace', 'type' => 'NVARCHAR(16)', 'desc' => 'FIXM: currentAirspace'],
    ['name' => 'current_sector', 'type' => 'NVARCHAR(16)', 'desc' => 'FIXM: currentSector'],
    ['name' => 'parking_left_time', 'type' => 'DATETIME2(0)', 'desc' => 'vATCSCC: parkingLeftTime'],
    ['name' => 'taxiway_entered_time', 'type' => 'DATETIME2(0)', 'desc' => 'vATCSCC: taxiwayEnteredTime'],
    ['name' => 'hold_entered_time', 'type' => 'DATETIME2(0)', 'desc' => 'vATCSCC: holdEnteredTime'],
    ['name' => 'runway_entered_time', 'type' => 'DATETIME2(0)', 'desc' => 'vATCSCC: runwayEnteredTime'],
    ['name' => 'rotation_time', 'type' => 'DATETIME2(0)', 'desc' => 'vATCSCC: rotationTime'],
    ['name' => 'approach_start_time', 'type' => 'DATETIME2(0)', 'desc' => 'vATCSCC: approachStartTime'],
    ['name' => 'threshold_time', 'type' => 'DATETIME2(0)', 'desc' => 'vATCSCC: thresholdTime'],
    ['name' => 'touchdown_time', 'type' => 'DATETIME2(0)', 'desc' => 'vATCSCC: touchdownTime'],
    ['name' => 'rollout_end_time', 'type' => 'DATETIME2(0)', 'desc' => 'vATCSCC: rolloutEndTime'],
    ['name' => 'parking_entered_time', 'type' => 'DATETIME2(0)', 'desc' => 'vATCSCC: parkingEnteredTime'],
];

foreach ($columns as $col) {
    // Check if column exists using INFORMATION_SCHEMA (better permissions)
    $checkSql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'swim_flights' AND COLUMN_NAME = ?";
    $checkStmt = sqlsrv_query($conn_swim, $checkSql, [$tableSchema, $col['name']]);

    if ($checkStmt === false) {
        $err = sqlsrv_errors()[0] ?? ['message' => 'Unknown error'];
        $results['columns'][] = [
            'name' => $col['name'],
            'status' => 'error',
            'error' => $err['message'],
        ];
        $results['errors']++;
        continue;
    }

    $exists = sqlsrv_fetch_array($checkStmt);
    sqlsrv_free_stmt($checkStmt);

    if ($exists) {
        $results['columns'][] = [
            'name' => $col['name'],
            'status' => 'skipped',
            'reason' => 'already exists',
        ];
        $results['skipped']++;
    } else {
        // Add the column
        $alterSql = "ALTER TABLE [{$tableSchema}].swim_flights ADD {$col['name']} {$col['type']} NULL";
        $alterStmt = sqlsrv_query($conn_swim, $alterSql);

        if ($alterStmt === false) {
            $err = sqlsrv_errors()[0] ?? ['message' => 'Unknown error'];
            $results['columns'][] = [
                'name' => $col['name'],
                'status' => 'error',
                'error' => $err['message'],
            ];
            $results['errors']++;
        } else {
            $results['columns'][] = [
                'name' => $col['name'],
                'status' => 'added',
                'type' => $col['type'],
                'desc' => $col['desc'],
            ];
            sqlsrv_free_stmt($alterStmt);
            $results['added']++;
        }
    }
}

// Create index - skip check using sys.indexes since we don't have permission, just try to create
// If it already exists, it will fail with a specific error we can detect
$indexSql = "CREATE INDEX IX_swim_flights_sector ON [{$tableSchema}].swim_flights (current_airspace, current_sector) WHERE current_sector IS NOT NULL AND is_active = 1";
$indexStmt = sqlsrv_query($conn_swim, $indexSql);

if ($indexStmt === false) {
    $err = sqlsrv_errors()[0] ?? ['message' => 'Unknown error'];
    $errMsg = $err['message'] ?? '';

    // Check if error indicates index already exists
    if (strpos($errMsg, 'already exists') !== false || strpos($errMsg, 'IX_swim_flights_sector') !== false) {
        $results['indexes'][] = [
            'name' => 'IX_swim_flights_sector',
            'status' => 'skipped',
            'reason' => 'already exists (or similar named index)',
        ];
    } else {
        $results['indexes'][] = [
            'name' => 'IX_swim_flights_sector',
            'status' => 'error',
            'error' => $errMsg,
        ];
        $results['errors']++;
    }
} else {
    $results['indexes'][] = [
        'name' => 'IX_swim_flights_sector',
        'status' => 'created',
    ];
    sqlsrv_free_stmt($indexStmt);
}

$results['success'] = ($results['errors'] === 0);
$results['timestamp'] = gmdate('c');

echo json_encode($results, JSON_PRETTY_PRINT);
