<?php

/**
 * run_migration.php
 *
 * Runs a specific SQL migration file against the ADL database.
 *
 * Usage: https://perti.vatcscc.org/scripts/run_migration.php?file=082_rate_history_schema.sql&run=1
 */

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$isCLI = (php_sapi_name() === 'cli');

if (!$isCLI) {
    if (!isset($_GET['run']) || $_GET['run'] != '1') {
        header('Content-Type: text/html');
        echo '<!DOCTYPE html><html><head><title>Run Migration</title></head><body>';
        echo '<h1>Run ADL Migration</h1>';
        echo '<p>Available migrations:</p><ul>';

        $migrationDir = dirname(__DIR__) . '/adl/migrations';
        $files = glob($migrationDir . '/*.sql');
        foreach ($files as $file) {
            $filename = basename($file);
            echo '<li><a href="?file=' . urlencode($filename) . '&run=1" onclick="return confirm(\'Run ' . htmlspecialchars($filename) . '?\');">' . htmlspecialchars($filename) . '</a></li>';
        }

        echo '</ul></body></html>';
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$scriptDir = __DIR__;
$baseDir = dirname($scriptDir);

require_once $baseDir . '/load/config.php';
require_once $baseDir . '/load/connect.php';

if (!$conn_adl) {
    die("ERROR: ADL connection not available.\n");
}

$migrationFile = isset($_GET['file']) ? basename($_GET['file']) : '';
if (empty($migrationFile)) {
    die("ERROR: No migration file specified. Use ?file=filename.sql\n");
}

$migrationPath = $baseDir . '/adl/migrations/' . $migrationFile;
if (!file_exists($migrationPath)) {
    die("ERROR: Migration file not found: $migrationFile\n");
}

echo "===========================================\n";
echo "Running Migration: $migrationFile\n";
echo "===========================================\n\n";

$sql = file_get_contents($migrationPath);

// Split into batches by GO statements
$batches = preg_split('/^\s*GO\s*$/mi', $sql);

$success = 0;
$errors = 0;
$skipped = 0;

foreach ($batches as $i => $batch) {
    $batch = trim($batch);
    if (empty($batch)) continue;

    // Skip SET NOCOUNT ON (handled automatically)
    if (preg_match('/^SET\s+NOCOUNT\s+ON/i', $batch)) {
        continue;
    }

    // Execute PRINT statements as echo
    if (preg_match('/^PRINT\s+[\'"](.+)[\'"]/i', $batch, $matches)) {
        echo $matches[1] . "\n";
        continue;
    }

    // Skip empty PRINT
    if (preg_match('/^PRINT\s+/i', $batch)) {
        continue;
    }

    $result = sqlsrv_query($conn_adl, $batch);
    if ($result === false) {
        $err = sqlsrv_errors();
        $errMsg = is_array($err) && isset($err[0]['message']) ? $err[0]['message'] : json_encode($err);

        // Check for "already exists" - these are OK
        if (stripos($errMsg, 'already exists') !== false ||
            stripos($errMsg, 'There is already an object') !== false) {
            echo "SKIP: Object already exists\n";
            $skipped++;
        } else {
            echo "ERROR in batch $i: $errMsg\n";
            echo "SQL: " . substr($batch, 0, 200) . "...\n\n";
            $errors++;
        }
    } else {
        $success++;
        sqlsrv_free_stmt($result);
    }
}

echo "\n===========================================\n";
echo "Migration Summary\n";
echo "===========================================\n";
echo "Batches succeeded: $success\n";
echo "Batches skipped:   $skipped\n";
echo "Errors:            $errors\n";

// Verify tables
echo "\n--- Verification ---\n";
$tables = ['airport_config_rate_history', 'airport_config_history'];
foreach ($tables as $table) {
    $check = sqlsrv_query($conn_adl, "SELECT COUNT(*) as cnt FROM sys.tables WHERE name = '$table'");
    if ($check) {
        $row = sqlsrv_fetch_array($check, SQLSRV_FETCH_ASSOC);
        $status = $row['cnt'] > 0 ? 'OK' : 'MISSING';
        echo "Table $table: $status\n";
        sqlsrv_free_stmt($check);
    }
}

// Verify view
$viewCheck = sqlsrv_query($conn_adl, "SELECT COUNT(*) as cnt FROM sys.views WHERE name = 'vw_recent_rate_changes'");
if ($viewCheck) {
    $row = sqlsrv_fetch_array($viewCheck, SQLSRV_FETCH_ASSOC);
    $status = $row['cnt'] > 0 ? 'OK' : 'MISSING';
    echo "View vw_recent_rate_changes: $status\n";
    sqlsrv_free_stmt($viewCheck);
}

// Verify stored procedure
$spCheck = sqlsrv_query($conn_adl, "SELECT COUNT(*) as cnt FROM sys.procedures WHERE name = 'sp_LogRateChange'");
if ($spCheck) {
    $row = sqlsrv_fetch_array($spCheck, SQLSRV_FETCH_ASSOC);
    $status = $row['cnt'] > 0 ? 'OK' : 'MISSING';
    echo "Procedure sp_LogRateChange: $status\n";
    sqlsrv_free_stmt($spCheck);
}

echo "\nMigration complete.\n";

?>
