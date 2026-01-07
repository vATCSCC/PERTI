<?php

/**
 * migrate_config_data.php
 *
 * One-time migration script to move config_data from MySQL to SQL Server ADL.
 *
 * Usage (Web): https://yoursite.com/scripts/migrate_config_data.php?run=1
 *              Add &dry-run=1 to preview without inserting
 *
 * Usage (CLI): php scripts/migrate_config_data.php [--dry-run]
 */

// Session check for web access
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Determine if running from CLI or web
$isCLI = (php_sapi_name() === 'cli');

if (!$isCLI) {
    // Web mode - require confirmation parameter
    if (!isset($_GET['run']) || $_GET['run'] != '1') {
        header('Content-Type: text/html');
        echo '<!DOCTYPE html><html><head><title>Config Migration</title></head><body>';
        echo '<h1>Config Data Migration</h1>';
        echo '<p>This will migrate config_data from MySQL to SQL Server ADL.</p>';
        echo '<p><a href="?run=1&dry-run=1">Preview Migration (Dry Run)</a></p>';
        echo '<p><a href="?run=1" onclick="return confirm(\'Are you sure you want to run the migration?\')">Run Migration</a></p>';
        echo '</body></html>';
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

echo "===========================================\n";
echo "Config Data Migration: MySQL -> SQL Server\n";
echo "===========================================\n\n";

// Check for dry-run flag
$dryRun = false;
if ($isCLI && in_array('--dry-run', $argv ?? [])) {
    $dryRun = true;
} elseif (!$isCLI && isset($_GET['dry-run']) && $_GET['dry-run'] == '1') {
    $dryRun = true;
}

if ($dryRun) {
    echo "** DRY RUN MODE - No data will be inserted **\n\n";
}

// Include connection files
$scriptDir = __DIR__;
$baseDir = dirname($scriptDir);

require_once $baseDir . '/load/config.php';
require_once $baseDir . '/load/connect.php';

// Verify connections
if (!$conn_sqli) {
    die("ERROR: MySQL connection not available\n");
}

if (!$conn_adl) {
    die("ERROR: ADL SQL Server connection not available\n");
}

echo "Connections established.\n";
echo "  - MySQL: OK\n";
echo "  - SQL Server ADL: OK\n\n";

// Fetch existing data from MySQL
echo "Fetching data from MySQL config_data table...\n";

$query = mysqli_query($conn_sqli, "SELECT * FROM config_data ORDER BY airport ASC");

if (!$query) {
    die("ERROR: Failed to query MySQL: " . mysqli_error($conn_sqli) . "\n");
}

$rows = [];
while ($row = mysqli_fetch_assoc($query)) {
    $rows[] = $row;
}

$totalRows = count($rows);
echo "Found {$totalRows} configurations to migrate.\n\n";

if ($totalRows === 0) {
    echo "Nothing to migrate. Exiting.\n";
    exit(0);
}

// Migration counters
$migrated = 0;
$skipped = 0;
$errors = 0;

// Process each row
foreach ($rows as $index => $row) {
    $num = $index + 1;
    $airport = strtoupper(trim($row['airport']));
    $icao = (strlen($airport) == 3) ? 'K' . $airport : $airport;

    echo "[{$num}/{$totalRows}] Migrating {$airport} ({$icao})...\n";

    // Check if already exists in ADL
    $checkSql = "SELECT config_id FROM dbo.airport_config WHERE airport_icao = ? AND config_name = 'Default'";
    $checkStmt = sqlsrv_query($conn_adl, $checkSql, [$icao]);

    if ($checkStmt && sqlsrv_fetch_array($checkStmt)) {
        echo "  -> SKIPPED: Already exists in ADL\n";
        sqlsrv_free_stmt($checkStmt);
        $skipped++;
        continue;
    }
    if ($checkStmt) sqlsrv_free_stmt($checkStmt);

    if ($dryRun) {
        echo "  -> Would migrate:\n";
        echo "     Config: {$airport} / {$icao} / Default\n";
        echo "     ARR Runways: {$row['arr']}\n";
        echo "     DEP Runways: {$row['dep']}\n";
        echo "     VMC AAR: {$row['vmc_aar']}, LVMC: {$row['lvmc_aar']}, IMC: {$row['imc_aar']}, LIMC: {$row['limc_aar']}\n";
        echo "     VMC ADR: {$row['vmc_adr']}, IMC ADR: {$row['imc_adr']}\n";
        $migrated++;
        continue;
    }

    // Begin transaction
    sqlsrv_begin_transaction($conn_adl);

    try {
        // 1. Insert into airport_config
        $sql = "INSERT INTO dbo.airport_config (airport_faa, airport_icao, config_name, config_code)
                OUTPUT INSERTED.config_id
                VALUES (?, ?, 'Default', NULL)";
        $stmt = sqlsrv_query($conn_adl, $sql, [$airport, $icao]);

        if ($stmt === false) {
            throw new Exception("Failed to insert config: " . adl_sql_error_message());
        }

        $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $configId = $result['config_id'];
        sqlsrv_free_stmt($stmt);

        echo "  -> Created config_id: {$configId}\n";

        // 2. Insert arrival runways
        $arrRunways = trim($row['arr']);
        if (!empty($arrRunways)) {
            $runways = preg_split('/[,\/\s]+/', $arrRunways);
            $priority = 1;
            foreach ($runways as $rwy) {
                $rwy = strtoupper(trim($rwy));
                if (empty($rwy)) continue;

                $sql = "INSERT INTO dbo.airport_config_runway (config_id, runway_id, runway_use, priority) VALUES (?, ?, 'ARR', ?)";
                $stmt = sqlsrv_query($conn_adl, $sql, [$configId, $rwy, $priority]);
                if ($stmt === false) {
                    throw new Exception("Failed to insert ARR runway {$rwy}: " . adl_sql_error_message());
                }
                sqlsrv_free_stmt($stmt);
                $priority++;
            }
            echo "  -> Inserted " . ($priority - 1) . " arrival runways\n";
        }

        // 3. Insert departure runways
        $depRunways = trim($row['dep']);
        if (!empty($depRunways)) {
            $runways = preg_split('/[,\/\s]+/', $depRunways);
            $priority = 1;
            foreach ($runways as $rwy) {
                $rwy = strtoupper(trim($rwy));
                if (empty($rwy)) continue;

                $sql = "INSERT INTO dbo.airport_config_runway (config_id, runway_id, runway_use, priority) VALUES (?, ?, 'DEP', ?)";
                $stmt = sqlsrv_query($conn_adl, $sql, [$configId, $rwy, $priority]);
                if ($stmt === false) {
                    throw new Exception("Failed to insert DEP runway {$rwy}: " . adl_sql_error_message());
                }
                sqlsrv_free_stmt($stmt);
                $priority++;
            }
            echo "  -> Inserted " . ($priority - 1) . " departure runways\n";
        }

        // 4. Insert VATSIM rates
        $rates = [
            ['VMC', 'ARR', intval($row['vmc_aar'])],
            ['LVMC', 'ARR', intval($row['lvmc_aar'])],
            ['IMC', 'ARR', intval($row['imc_aar'])],
            ['LIMC', 'ARR', intval($row['limc_aar'])],
            ['VMC', 'DEP', intval($row['vmc_adr'])],
            ['IMC', 'DEP', intval($row['imc_adr'])],
        ];

        $rateCount = 0;
        foreach ($rates as $rate) {
            list($weather, $type, $value) = $rate;
            if ($value > 0) {
                $sql = "INSERT INTO dbo.airport_config_rate (config_id, source, weather, rate_type, rate_value) VALUES (?, 'VATSIM', ?, ?, ?)";
                $stmt = sqlsrv_query($conn_adl, $sql, [$configId, $weather, $type, $value]);
                if ($stmt === false) {
                    throw new Exception("Failed to insert rate {$weather}/{$type}: " . adl_sql_error_message());
                }
                sqlsrv_free_stmt($stmt);
                $rateCount++;
            }
        }
        echo "  -> Inserted {$rateCount} VATSIM rates\n";

        // Commit transaction
        sqlsrv_commit($conn_adl);
        $migrated++;
        echo "  -> SUCCESS\n";

    } catch (Exception $e) {
        sqlsrv_rollback($conn_adl);
        echo "  -> ERROR: " . $e->getMessage() . "\n";
        $errors++;
    }
}

// Summary
echo "\n";
echo "===========================================\n";
echo "Migration Complete\n";
echo "===========================================\n";
echo "  Total processed: {$totalRows}\n";
echo "  Migrated:        {$migrated}\n";
echo "  Skipped:         {$skipped}\n";
echo "  Errors:          {$errors}\n";

if ($dryRun) {
    echo "\n** This was a dry run - no data was actually inserted **\n";
    echo "Run without --dry-run to perform the migration.\n";
}

echo "\nDone.\n";

?>
