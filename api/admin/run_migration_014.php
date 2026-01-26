<?php
/**
 * Migration 014 Runner - Add current_sector_strata column
 *
 * Run via: https://perti.vatcscc.org/api/admin/run_migration_014.php
 * Requires admin authentication via VATSIM OAuth session or API key.
 */

require_once __DIR__ . '/../../load/config.php';
require_once __DIR__ . '/../../load/connect.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Auth check
$authorized = false;
$auth_method = 'none';

if (isset($_SESSION['VATSIM_CID']) && !empty($_SESSION['VATSIM_CID'])) {
    global $conn_sqli;
    $cid = $_SESSION['VATSIM_CID'];
    $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");
    if ($p_check && $p_check->num_rows > 0) {
        $authorized = true;
        $auth_method = 'session_admin';
    }
}

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
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

global $conn_swim;
if (!$conn_swim) {
    echo json_encode(['success' => false, 'error' => 'SWIM database connection not available']);
    exit;
}

$results = [
    'success' => true,
    'auth_method' => $auth_method,
    'migration' => '014_swim_sector_strata',
    'operations' => [],
    'errors' => 0,
];

// Check if column exists
$checkSql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'swim_flights' AND COLUMN_NAME = 'current_sector_strata'";
$checkStmt = sqlsrv_query($conn_swim, $checkSql);

if ($checkStmt === false) {
    $results['success'] = false;
    $results['error'] = 'Failed to check column existence';
    $results['sql_errors'] = sqlsrv_errors();
    echo json_encode($results, JSON_PRETTY_PRINT);
    exit;
}

$exists = sqlsrv_fetch_array($checkStmt);
sqlsrv_free_stmt($checkStmt);

if ($exists) {
    $results['operations'][] = [
        'type' => 'column',
        'name' => 'current_sector_strata',
        'status' => 'skipped',
        'reason' => 'already exists',
    ];
} else {
    // Add the column
    $alterSql = "ALTER TABLE dbo.swim_flights ADD current_sector_strata NVARCHAR(10) NULL";
    $alterStmt = sqlsrv_query($conn_swim, $alterSql);

    if ($alterStmt === false) {
        $err = sqlsrv_errors()[0] ?? ['message' => 'Unknown error'];
        $results['operations'][] = [
            'type' => 'column',
            'name' => 'current_sector_strata',
            'status' => 'error',
            'error' => $err['message'],
        ];
        $results['errors']++;
    } else {
        $results['operations'][] = [
            'type' => 'column',
            'name' => 'current_sector_strata',
            'status' => 'added',
        ];
        sqlsrv_free_stmt($alterStmt);
    }
}

// Try to create index (will fail if exists, which is fine)
$indexSql = "CREATE INDEX IX_swim_flights_strata ON dbo.swim_flights (current_sector_strata) WHERE current_sector_strata IS NOT NULL AND is_active = 1";
$indexStmt = sqlsrv_query($conn_swim, $indexSql);

if ($indexStmt === false) {
    $err = sqlsrv_errors()[0] ?? ['message' => 'Unknown error'];
    $errMsg = $err['message'] ?? '';

    if (strpos($errMsg, 'already exists') !== false || strpos($errMsg, 'IX_swim_flights_strata') !== false) {
        $results['operations'][] = [
            'type' => 'index',
            'name' => 'IX_swim_flights_strata',
            'status' => 'skipped',
            'reason' => 'already exists',
        ];
    } else {
        $results['operations'][] = [
            'type' => 'index',
            'name' => 'IX_swim_flights_strata',
            'status' => 'error',
            'error' => $errMsg,
        ];
        $results['errors']++;
    }
} else {
    $results['operations'][] = [
        'type' => 'index',
        'name' => 'IX_swim_flights_strata',
        'status' => 'created',
    ];
    sqlsrv_free_stmt($indexStmt);
}

$results['success'] = ($results['errors'] === 0);
$results['timestamp'] = gmdate('c');

echo json_encode($results, JSON_PRETTY_PRINT);
