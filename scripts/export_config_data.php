<?php

/**
 * export_config_data.php
 *
 * Exports airport config data from MySQL to ADL SQL Server.
 * Creates normalized entries in airport_config, airport_config_runway, and airport_config_rate tables.
 *
 * Usage: https://perti.vatcscc.org/scripts/export_config_data.php?run=1
 *        Add &dry-run=1 to preview without inserting
 */

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$isCLI = (php_sapi_name() === 'cli');

if (!$isCLI) {
    if (!isset($_GET['run']) || $_GET['run'] != '1') {
        header('Content-Type: text/html');
        echo '<!DOCTYPE html><html><head><title>Export Config Data</title></head><body>';
        echo '<h1>Export Config Data (MySQL to ADL)</h1>';
        echo '<p>This will export airport config data from MySQL to ADL SQL Server.</p>';
        echo '<p><a href="?run=1&dry-run=1">Preview Export (Dry Run)</a></p>';
        echo '<p><a href="?run=1" onclick="return confirm(\'Are you sure you want to run the export?\')">Run Export</a></p>';
        echo '</body></html>';
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

echo "===========================================\n";
echo "MySQL to ADL Config Export\n";
echo "===========================================\n\n";

$dryRun = false;
if ($isCLI && in_array('--dry-run', $argv ?? [])) {
    $dryRun = true;
} elseif (!$isCLI && isset($_GET['dry-run']) && $_GET['dry-run'] == '1') {
    $dryRun = true;
}

if ($dryRun) {
    echo "** DRY RUN MODE - No data will be inserted **\n\n";
}

$scriptDir = __DIR__;
$baseDir = dirname($scriptDir);

require_once $baseDir . '/load/config.php';
require_once $baseDir . '/load/connect.php';

// Check connections
if (!$conn_sqli) {
    die("ERROR: MySQL connection not available.\n");
}
if (!$conn_adl) {
    die("ERROR: ADL connection not available.\n");
}

echo "Connections established.\n\n";

$stats = [
    'total' => 0,
    'configs' => 0,
    'runways' => 0,
    'rates' => 0,
    'skipped' => 0,
    'errors' => 0,
];

// First, clear existing ADL data (if not dry run)
if (!$dryRun) {
    echo "Clearing existing ADL data...\n";

    $clearSql = [
        "DELETE FROM dbo.airport_config_rate",
        "DELETE FROM dbo.airport_config_runway",
        "DELETE FROM dbo.airport_config",
        "DBCC CHECKIDENT ('dbo.airport_config', RESEED, 0)"
    ];

    foreach ($clearSql as $sql) {
        $result = sqlsrv_query($conn_adl, $sql);
        if ($result === false) {
            echo "Warning clearing data: " . adl_sql_error_message() . "\n";
        }
    }
    echo "Cleared.\n\n";
}

// Query MySQL for config data
$query = mysqli_query($conn_sqli, "SELECT * FROM config_data ORDER BY airport ASC");

if (!$query) {
    die("ERROR: Could not query MySQL: " . mysqli_error($conn_sqli) . "\n");
}

$rowCount = mysqli_num_rows($query);
echo "Found $rowCount rows in MySQL config_data.\n\n";

while ($row = mysqli_fetch_assoc($query)) {
    $stats['total']++;

    $airport = trim($row['airport'] ?? '');
    $arrRunways = trim($row['arr'] ?? '');
    $depRunways = trim($row['dep'] ?? '');

    // Skip empty airports
    if (empty($airport)) {
        $stats['skipped']++;
        continue;
    }

    // Generate ICAO code
    $faaCode = strtoupper($airport);
    if (strlen($faaCode) == 3) {
        // Check for Canadian airports
        if (preg_match('/^Y[A-Z]{2}$/', $faaCode)) {
            $icaoCode = 'C' . $faaCode;
        } else {
            $icaoCode = 'K' . $faaCode;
        }
    } else {
        $icaoCode = $faaCode;
    }

    // Generate config name from runways
    $configName = "Default";
    if (!empty($arrRunways)) {
        $configName = $arrRunways;
        if (!empty($depRunways) && $depRunways !== $arrRunways) {
            $configName .= " / " . $depRunways;
        }
    }

    if ($dryRun) {
        echo "Would insert: $faaCode ($icaoCode) - $configName\n";
        $stats['configs']++;
        continue;
    }

    // Insert into airport_config
    $insertConfigSql = "
        INSERT INTO dbo.airport_config (airport_faa, airport_icao, config_name)
        OUTPUT INSERTED.config_id
        VALUES (?, ?, ?)
    ";

    $configResult = sqlsrv_query($conn_adl, $insertConfigSql, [$faaCode, $icaoCode, $configName]);

    if ($configResult === false) {
        echo "ERROR inserting config for $airport: " . adl_sql_error_message() . "\n";
        $stats['errors']++;
        continue;
    }

    $configRow = sqlsrv_fetch_array($configResult, SQLSRV_FETCH_ASSOC);
    $configId = $configRow['config_id'];
    sqlsrv_free_stmt($configResult);

    $stats['configs']++;

    // Insert arrival runways
    if (!empty($arrRunways)) {
        $runways = preg_split('/[\/,\s]+/', $arrRunways);
        $priority = 1;
        foreach ($runways as $rwy) {
            $rwy = trim($rwy);
            if (empty($rwy)) continue;

            $insertRwySql = "
                INSERT INTO dbo.airport_config_runway (config_id, runway_id, runway_use, priority)
                VALUES (?, ?, 'ARR', ?)
            ";
            $rwyResult = sqlsrv_query($conn_adl, $insertRwySql, [$configId, $rwy, $priority]);
            if ($rwyResult !== false) {
                $stats['runways']++;
                $priority++;
            }
        }
    }

    // Insert departure runways
    if (!empty($depRunways)) {
        $runways = preg_split('/[\/,\s]+/', $depRunways);
        $priority = 1;
        foreach ($runways as $rwy) {
            $rwy = trim($rwy);
            if (empty($rwy)) continue;

            $insertRwySql = "
                INSERT INTO dbo.airport_config_runway (config_id, runway_id, runway_use, priority)
                VALUES (?, ?, 'DEP', ?)
            ";
            $rwyResult = sqlsrv_query($conn_adl, $insertRwySql, [$configId, $rwy, $priority]);
            if ($rwyResult !== false) {
                $stats['runways']++;
                $priority++;
            }
        }
    }

    // Insert VATSIM rates
    $rateMap = [
        ['vmc_aar', 'VMC', 'ARR'],
        ['lvmc_aar', 'LVMC', 'ARR'],
        ['imc_aar', 'IMC', 'ARR'],
        ['limc_aar', 'LIMC', 'ARR'],
        ['vmc_adr', 'VMC', 'DEP'],
        ['imc_adr', 'IMC', 'DEP'],
    ];

    foreach ($rateMap as $mapping) {
        list($column, $weather, $rateType) = $mapping;
        $value = $row[$column] ?? null;

        if ($value !== null && $value !== '' && is_numeric($value)) {
            $insertRateSql = "
                INSERT INTO dbo.airport_config_rate (config_id, source, weather, rate_type, rate_value)
                VALUES (?, 'VATSIM', ?, ?, ?)
            ";
            $rateResult = sqlsrv_query($conn_adl, $insertRateSql, [$configId, $weather, $rateType, intval($value)]);
            if ($rateResult !== false) {
                $stats['rates']++;
            }
        }
    }
}

echo "\n===========================================\n";
echo "Export Summary\n";
echo "===========================================\n";
echo "Total MySQL rows:    {$stats['total']}\n";
echo "Configs created:     {$stats['configs']}\n";
echo "Runways created:     {$stats['runways']}\n";
echo "Rates created:       {$stats['rates']}\n";
echo "Rows skipped:        {$stats['skipped']}\n";
echo "Errors:              {$stats['errors']}\n";
echo "\nExport " . ($dryRun ? "preview " : "") . "complete.\n";

if (!$dryRun) {
    echo "\nNext step: Run scripts/parse_runway_data.sql in SSMS to clean up the data.\n";
}

?>
