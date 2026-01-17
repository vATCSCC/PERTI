<?php
/**
 * Deploy SP Optimizations to VATSIM_ADL
 *
 * Deploys the following changes:
 *   1. sp_Adl_RefreshFromVatsim_Staged V9.1.0 (position skip-unchanged)
 *   2. Delta sync indexes (005_delta_sync_indexes.sql)
 *   3. sp_ProcessZoneDetectionBatch_Tiered (tiered zone detection)
 *
 * Usage:
 *   php deploy_sp_optimizations.php [--dry-run] [--indexes-only] [--sp-only]
 *
 * Options:
 *   --dry-run       Show what would be deployed without executing
 *   --indexes-only  Only deploy the indexes
 *   --sp-only       Only deploy the stored procedures
 *
 * @package PERTI
 * @version 1.0.0
 */

// Parse command line arguments
$options = getopt('', ['dry-run', 'indexes-only', 'sp-only', 'help', 'user:', 'pass:', 'server:']);
$dryRun = isset($options['dry-run']);
$indexesOnly = isset($options['indexes-only']);
$spOnly = isset($options['sp-only']);
$showHelp = isset($options['help']);

// Allow override of credentials via command line
$cliUser = $options['user'] ?? null;
$cliPass = $options['pass'] ?? null;
$cliServer = $options['server'] ?? 'vatsim.database.windows.net';

if ($showHelp) {
    echo "Deploy SP Optimizations to VATSIM_ADL\n\n";
    echo "Usage: php deploy_sp_optimizations.php [options]\n\n";
    echo "Options:\n";
    echo "  --dry-run       Show what would be deployed without executing\n";
    echo "  --indexes-only  Only deploy the indexes\n";
    echo "  --sp-only       Only deploy the stored procedures\n";
    echo "  --user=USER     SQL Server username (overrides config)\n";
    echo "  --pass=PASS     SQL Server password (overrides config)\n";
    echo "  --server=HOST   SQL Server hostname (default: vatsim.database.windows.net)\n";
    echo "  --help          Show this help message\n\n";
    echo "Example:\n";
    echo "  php deploy_sp_optimizations.php --user=perti_admin --pass=MyPassword123\n\n";
    exit(0);
}

echo "==============================================\n";
echo "PERTI SP Optimization Deployment\n";
echo "==============================================\n";
echo "Mode: " . ($dryRun ? "DRY RUN (no changes)" : "LIVE DEPLOYMENT") . "\n";
echo "Scope: " . ($indexesOnly ? "Indexes only" : ($spOnly ? "SPs only" : "All")) . "\n";
echo "==============================================\n\n";

// Change to web root
$wwwroot = dirname(__DIR__);
chdir($wwwroot);

// Load configuration
$configFile = __DIR__ . '/../load/config.php';
if (file_exists($configFile)) {
    require_once $configFile;
}
require_once __DIR__ . '/../load/connect.php';

// Connect using CLI credentials or config.php
$conn = null;
if ($cliUser && $cliPass) {
    echo "Using CLI credentials for connection...\n";
    if (!function_exists('sqlsrv_connect')) {
        echo "ERROR: sqlsrv extension not loaded\n";
        exit(1);
    }
    $connectionInfo = [
        "Database" => "VATSIM_ADL",
        "UID" => $cliUser,
        "PWD" => $cliPass,
        "ConnectionPooling" => 1
    ];
    $conn = sqlsrv_connect($cliServer, $connectionInfo);
    if ($conn === false) {
        $errors = sqlsrv_errors();
        echo "ERROR: Connection failed - " . ($errors[0]['message'] ?? 'Unknown') . "\n";
        exit(1);
    }
} else {
    // Try using config.php
    $conn = function_exists('get_conn_adl') ? get_conn_adl() : null;
    if (!$conn) {
        echo "ERROR: Failed to connect to VATSIM_ADL\n";
        echo "Either:\n";
        echo "  1. Create load/config.php with ADL_SQL_* credentials, or\n";
        echo "  2. Use --user and --pass command line options\n";
        exit(1);
    }
}

echo "Connected to VATSIM_ADL\n\n";

// Define deployment files
$deployments = [
    'indexes' => [
        'file' => __DIR__ . '/../adl/migrations/performance/005_delta_sync_indexes.sql',
        'name' => 'Delta Sync Indexes',
        'description' => 'Indexes for position_updated_utc, times_updated_utc, tmi_updated_utc',
    ],
    'sp_refresh' => [
        'file' => __DIR__ . '/../adl/procedures/sp_Adl_RefreshFromVatsim_Staged.sql',
        'name' => 'sp_Adl_RefreshFromVatsim_Staged V9.1.0',
        'description' => 'Position skip-unchanged optimization, @skip_zone_detection parameter',
    ],
    'sp_zone_tiered' => [
        'file' => __DIR__ . '/../adl/procedures/sp_ProcessZoneDetectionBatch_Tiered.sql',
        'name' => 'sp_ProcessZoneDetectionBatch_Tiered V1.0',
        'description' => 'Tiered zone detection with tier_mask parameter',
    ],
];

// Filter deployments based on options
if ($indexesOnly) {
    $deployments = ['indexes' => $deployments['indexes']];
} elseif ($spOnly) {
    unset($deployments['indexes']);
}

$successCount = 0;
$errorCount = 0;

foreach ($deployments as $key => $deployment) {
    echo "----------------------------------------\n";
    echo "Deploying: {$deployment['name']}\n";
    echo "File: {$deployment['file']}\n";
    echo "Description: {$deployment['description']}\n";
    echo "----------------------------------------\n";

    if (!file_exists($deployment['file'])) {
        echo "ERROR: File not found\n\n";
        $errorCount++;
        continue;
    }

    // Read the SQL file
    $sql = file_get_contents($deployment['file']);

    if ($sql === false) {
        echo "ERROR: Could not read file\n\n";
        $errorCount++;
        continue;
    }

    if ($dryRun) {
        echo "DRY RUN: Would execute " . strlen($sql) . " bytes of SQL\n";
        echo "First 500 chars:\n";
        echo substr($sql, 0, 500) . "...\n\n";
        $successCount++;
        continue;
    }

    // Split SQL by GO statements (SQL Server batch separator)
    $batches = preg_split('/^\s*GO\s*$/mi', $sql);

    $batchCount = 0;
    $batchErrors = 0;

    foreach ($batches as $batch) {
        $batch = trim($batch);
        if (empty($batch)) {
            continue;
        }

        // Skip PRINT statements for cleaner output
        if (preg_match('/^\s*PRINT\s+/i', $batch)) {
            continue;
        }

        $batchCount++;
        $stmt = @sqlsrv_query($conn, $batch);

        if ($stmt === false) {
            $errors = sqlsrv_errors();
            $errorMsg = $errors[0]['message'] ?? 'Unknown error';

            // Skip "already exists" errors for CREATE INDEX
            if (strpos($errorMsg, 'already exists') !== false) {
                echo "  Batch #{$batchCount}: Skipped (already exists)\n";
                continue;
            }

            echo "  Batch #{$batchCount}: ERROR - {$errorMsg}\n";
            $batchErrors++;
        } else {
            sqlsrv_free_stmt($stmt);
            echo "  Batch #{$batchCount}: OK\n";
        }
    }

    if ($batchErrors === 0) {
        echo "SUCCESS: Deployed {$deployment['name']} ({$batchCount} batches)\n\n";
        $successCount++;
    } else {
        echo "PARTIAL: Deployed with {$batchErrors} errors\n\n";
        $errorCount++;
    }
}

echo "==============================================\n";
echo "Deployment Complete\n";
echo "==============================================\n";
echo "Successful: {$successCount}\n";
echo "Errors: {$errorCount}\n";

if ($dryRun) {
    echo "\nThis was a DRY RUN. Run without --dry-run to deploy.\n";
}

if ($errorCount > 0) {
    exit(1);
}

echo "\nNext steps:\n";
echo "1. Enable zone_daemon in vatsim_adl_daemon.php:\n";
echo "   'zone_daemon_enabled' => true,\n";
echo "2. Start the zone daemon:\n";
echo "   php scripts/zone_daemon.php\n";
echo "\n";

exit(0);
