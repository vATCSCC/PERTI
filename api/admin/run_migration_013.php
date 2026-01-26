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
];

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
    // Check if column exists
    $checkSql = "SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = ?";
    $checkStmt = sqlsrv_query($conn_swim, $checkSql, [$col['name']]);

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
        $alterSql = "ALTER TABLE dbo.swim_flights ADD {$col['name']} {$col['type']} NULL";
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

// Create index
$indexCheckSql = "SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'IX_swim_flights_sector'";
$indexCheckStmt = sqlsrv_query($conn_swim, $indexCheckSql);
$indexExists = sqlsrv_fetch_array($indexCheckStmt);
sqlsrv_free_stmt($indexCheckStmt);

if ($indexExists) {
    $results['indexes'][] = [
        'name' => 'IX_swim_flights_sector',
        'status' => 'skipped',
        'reason' => 'already exists',
    ];
} else {
    $indexSql = "CREATE INDEX IX_swim_flights_sector ON dbo.swim_flights (current_airspace, current_sector) WHERE current_sector IS NOT NULL AND is_active = 1";
    $indexStmt = sqlsrv_query($conn_swim, $indexSql);

    if ($indexStmt === false) {
        $err = sqlsrv_errors()[0] ?? ['message' => 'Unknown error'];
        $results['indexes'][] = [
            'name' => 'IX_swim_flights_sector',
            'status' => 'error',
            'error' => $err['message'],
        ];
        $results['errors']++;
    } else {
        $results['indexes'][] = [
            'name' => 'IX_swim_flights_sector',
            'status' => 'created',
        ];
        sqlsrv_free_stmt($indexStmt);
    }
}

$results['success'] = ($results['errors'] === 0);
$results['timestamp'] = gmdate('c');

echo json_encode($results, JSON_PRETTY_PRINT);
