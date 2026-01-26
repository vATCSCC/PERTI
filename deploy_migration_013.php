<?php
/**
 * Deploy Migration 013: FIXM Airspace & Position Fields
 *
 * Adds current_sector, current_airspace, and zone detection time fields
 * to the swim_flights table in SWIM_API database.
 *
 * Usage:
 *   php deploy_migration_013.php [--dry-run]
 *
 * @package PERTI
 * @version 1.0.0
 */

// Parse command line arguments
$options = getopt('', ['dry-run', 'help']);
$dryRun = isset($options['dry-run']);
$showHelp = isset($options['help']);

if ($showHelp) {
    echo "Deploy Migration 013: FIXM Airspace & Position Fields\n\n";
    echo "Usage: php deploy_migration_013.php [options]\n\n";
    echo "Options:\n";
    echo "  --dry-run  Show what would be deployed without executing\n";
    echo "  --help     Show this help message\n\n";
    exit(0);
}

echo "==============================================\n";
echo "  Migration 013: FIXM Airspace & Position\n";
echo "  Target: SWIM_API.dbo.swim_flights\n";
echo "==============================================\n\n";

// Load configuration
$configPath = __DIR__ . '/load/config.php';
if (!file_exists($configPath)) {
    die("Error: Config file not found at $configPath\n");
}
require_once $configPath;

// Check for required constants
if (!defined('SWIM_SQL_SERVER') || !defined('SWIM_SQL_USER') || !defined('SWIM_SQL_PASS') || !defined('SWIM_SQL_DATABASE')) {
    die("Error: SWIM_SQL_* constants not defined in config.php\n");
}

echo "Server: " . SWIM_SQL_SERVER . "\n";
echo "Database: " . SWIM_SQL_DATABASE . "\n";
echo "Mode: " . ($dryRun ? "DRY RUN (no changes)" : "LIVE") . "\n\n";

if ($dryRun) {
    echo "[DRY RUN] Would execute the following changes:\n\n";
    echo "1. Add column: current_airspace NVARCHAR(16) NULL\n";
    echo "2. Add column: current_sector NVARCHAR(16) NULL\n";
    echo "3. Add column: parking_left_time DATETIME2(0) NULL\n";
    echo "4. Add column: taxiway_entered_time DATETIME2(0) NULL\n";
    echo "5. Add column: hold_entered_time DATETIME2(0) NULL\n";
    echo "6. Add column: runway_entered_time DATETIME2(0) NULL\n";
    echo "7. Add column: rotation_time DATETIME2(0) NULL\n";
    echo "8. Add column: approach_start_time DATETIME2(0) NULL\n";
    echo "9. Add column: threshold_time DATETIME2(0) NULL\n";
    echo "10. Add column: touchdown_time DATETIME2(0) NULL\n";
    echo "11. Add column: rollout_end_time DATETIME2(0) NULL\n";
    echo "12. Add column: parking_entered_time DATETIME2(0) NULL\n";
    echo "13. Create index: IX_swim_flights_sector\n\n";
    echo "No changes made. Remove --dry-run to execute.\n";
    exit(0);
}

// Connect to SWIM_API database
$connectionInfo = [
    'Database' => SWIM_SQL_DATABASE,
    'UID' => SWIM_SQL_USER,
    'PWD' => SWIM_SQL_PASS,
    'Encrypt' => true,
    'TrustServerCertificate' => false,
    'ConnectRetryCount' => 3,
    'ConnectRetryInterval' => 10,
    'LoginTimeout' => 30,
];

echo "Connecting to database...\n";
$conn = sqlsrv_connect(SWIM_SQL_SERVER, $connectionInfo);

if ($conn === false) {
    $errors = sqlsrv_errors();
    echo "Connection failed:\n";
    print_r($errors);
    exit(1);
}
echo "Connected successfully.\n\n";

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

$added = 0;
$skipped = 0;
$errors = 0;

foreach ($columns as $col) {
    // Check if column exists
    $checkSql = "SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = ?";
    $checkStmt = sqlsrv_query($conn, $checkSql, [$col['name']]);

    if ($checkStmt === false) {
        echo "ERROR checking column {$col['name']}: ";
        print_r(sqlsrv_errors());
        $errors++;
        continue;
    }

    $exists = sqlsrv_fetch_array($checkStmt);
    sqlsrv_free_stmt($checkStmt);

    if ($exists) {
        echo "= {$col['name']} already exists\n";
        $skipped++;
    } else {
        // Add the column
        $alterSql = "ALTER TABLE dbo.swim_flights ADD {$col['name']} {$col['type']} NULL";
        $alterStmt = sqlsrv_query($conn, $alterSql);

        if ($alterStmt === false) {
            echo "ERROR adding column {$col['name']}: ";
            print_r(sqlsrv_errors());
            $errors++;
        } else {
            echo "+ Added {$col['name']} ({$col['desc']})\n";
            sqlsrv_free_stmt($alterStmt);
            $added++;
        }
    }
}

// Create index
echo "\nCreating index...\n";
$indexCheckSql = "SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'IX_swim_flights_sector'";
$indexCheckStmt = sqlsrv_query($conn, $indexCheckSql);
$indexExists = sqlsrv_fetch_array($indexCheckStmt);
sqlsrv_free_stmt($indexCheckStmt);

if ($indexExists) {
    echo "= Index IX_swim_flights_sector already exists\n";
} else {
    $indexSql = "CREATE INDEX IX_swim_flights_sector ON dbo.swim_flights (current_airspace, current_sector) WHERE current_sector IS NOT NULL AND is_active = 1";
    $indexStmt = sqlsrv_query($conn, $indexSql);

    if ($indexStmt === false) {
        echo "ERROR creating index: ";
        print_r(sqlsrv_errors());
        $errors++;
    } else {
        echo "+ Created index IX_swim_flights_sector\n";
        sqlsrv_free_stmt($indexStmt);
    }
}

sqlsrv_close($conn);

echo "\n==============================================\n";
echo "  Migration 013 Complete\n";
echo "  Added: $added columns\n";
echo "  Skipped: $skipped (already exist)\n";
echo "  Errors: $errors\n";
echo "==============================================\n";

exit($errors > 0 ? 1 : 0);
