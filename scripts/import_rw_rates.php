<?php

/**
 * import_rw_rates.php
 *
 * Imports real-world rates from ATCSCC Advisory Builder CSV into ADL.
 * Matches configs by airport + runway pattern and inserts RW source rates.
 *
 * Usage: https://perti.vatcscc.org/scripts/import_rw_rates.php?run=1
 *        Add &dry-run=1 to preview without inserting
 */

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$isCLI = (php_sapi_name() === 'cli');

if (!$isCLI) {
    if (!isset($_GET['run']) || $_GET['run'] != '1') {
        header('Content-Type: text/html');
        echo '<!DOCTYPE html><html><head><title>Import RW Rates</title></head><body>';
        echo '<h1>Import Real-World Rates</h1>';
        echo '<p>This will import real-world rates from ATCSCC Advisory Builder into ADL.</p>';
        echo '<p><a href="?run=1&dry-run=1">Preview Import (Dry Run)</a></p>';
        echo '<p><a href="?run=1" onclick="return confirm(\'Are you sure you want to run the import?\')">Run Import</a></p>';
        echo '</body></html>';
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

echo "===========================================\n";
echo "Real-World Rates Import\n";
echo "===========================================\n\n";

$dryRun = false;
$verbose = false;
if ($isCLI) {
    $dryRun = in_array('--dry-run', $argv ?? []);
    $verbose = in_array('--verbose', $argv ?? []) || in_array('-v', $argv ?? []);
} else {
    $dryRun = isset($_GET['dry-run']) && $_GET['dry-run'] == '1';
    $verbose = isset($_GET['verbose']) && $_GET['verbose'] == '1';
}

if ($dryRun) {
    echo "** DRY RUN MODE - No data will be inserted **\n\n";
}
if ($verbose) {
    echo "** VERBOSE MODE - Showing detailed matching info **\n\n";
}

$scriptDir = __DIR__;
$baseDir = dirname($scriptDir);

require_once $baseDir . '/load/config.php';
require_once $baseDir . '/load/connect.php';

if (!$conn_adl) {
    die("ERROR: ADL connection not available.\n");
}

// CSV file path - check multiple possible locations
$possiblePaths = [
    'C:\\Users\\jerem.DESKTOP-T926IG8\\OneDrive - Virtual Air Traffic Control System Command Center\\Documents - Virtual Air Traffic Control System Command Center\\DCC\\ATCSCC Advisory Builder.csv',
    $baseDir . '/../../../DCC/ATCSCC Advisory Builder.csv',
    $baseDir . '/assets/data/ATCSCC Advisory Builder.csv',
];

// Allow override via GET or CLI argument
if (!$isCLI && isset($_GET['csv'])) {
    array_unshift($possiblePaths, $_GET['csv']);
} elseif ($isCLI) {
    foreach ($argv as $i => $arg) {
        if ($arg === '--csv' && isset($argv[$i + 1])) {
            array_unshift($possiblePaths, $argv[$i + 1]);
            break;
        }
    }
}

$csvPath = null;
foreach ($possiblePaths as $path) {
    $normalizedPath = realpath($path);
    if ($normalizedPath && file_exists($normalizedPath)) {
        $csvPath = $normalizedPath;
        break;
    }
}

if (!$csvPath) {
    echo "ERROR: CSV file not found. Checked paths:\n";
    foreach ($possiblePaths as $p) {
        echo "  - $p\n";
    }
    die("\nUse ?csv=PATH or --csv PATH to specify the file location.\n");
}

echo "Using CSV file: $csvPath\n\n";

// Verify database has configs
$checkSql = "SELECT COUNT(*) AS cnt FROM dbo.airport_config";
$checkResult = sqlsrv_query($conn_adl, $checkSql);
if ($checkResult) {
    $row = sqlsrv_fetch_array($checkResult, SQLSRV_FETCH_ASSOC);
    echo "Database has {$row['cnt']} airport configs.\n";
    if ($row['cnt'] == 0) {
        die("ERROR: No airport configs in database. Run the migration/import first.\n");
    }
    sqlsrv_free_stmt($checkResult);
} else {
    echo "WARNING: Could not check config count.\n";
}

// Sample some airport codes from database
$sampleSql = "SELECT TOP 5 airport_faa, airport_icao FROM dbo.airport_config ORDER BY airport_faa";
$sampleResult = sqlsrv_query($conn_adl, $sampleSql);
if ($sampleResult) {
    echo "Sample airports in DB: ";
    $samples = [];
    while ($s = sqlsrv_fetch_array($sampleResult, SQLSRV_FETCH_ASSOC)) {
        $samples[] = $s['airport_faa'] . '/' . $s['airport_icao'];
    }
    echo implode(', ', $samples) . "\n\n";
    sqlsrv_free_stmt($sampleResult);
}

// Read and parse CSV
$handle = fopen($csvPath, 'r');
if (!$handle) {
    die("ERROR: Could not open CSV file.\n");
}

// Skip header
$header = fgetcsv($handle);
echo "CSV Columns: " . implode(', ', $header) . "\n\n";

$stats = [
    'total' => 0,
    'matched' => 0,
    'inserted' => 0,
    'updated' => 0,
    'skipped' => 0,
    'errors' => 0,
];

// Helper function to normalize airport code to FAA
function normalizeToFaa($code) {
    $code = strtoupper(trim($code));
    // Remove K prefix for US airports
    if (strlen($code) == 4 && $code[0] == 'K') {
        return substr($code, 1);
    }
    // Canadian airports: remove C prefix
    if (strlen($code) == 4 && $code[0] == 'C' && $code[1] == 'Y') {
        return substr($code, 1);
    }
    return $code;
}

// Helper to normalize runway string for comparison
function normalizeRunways($rwy) {
    $rwy = strtoupper(trim($rwy));
    // Replace common separators
    $rwy = preg_replace('/[\s,]+/', '/', $rwy);
    // Sort runways for consistent comparison
    $parts = explode('/', $rwy);
    sort($parts);
    return implode('/', $parts);
}

// Helper to parse rate value (handles XXX, empty, numbers)
function parseRate($val) {
    $val = trim($val);
    if ($val === '' || strtoupper($val) === 'XXX' || $val === ' ') {
        return null;
    }
    // Handle date-like values that Excel may have converted (e.g., "03-Aug" should be null)
    if (preg_match('/^[0-9]+-[A-Za-z]+$/', $val)) {
        return null;
    }
    return intval($val);
}

// First, delete all existing RW rates
if (!$dryRun) {
    $deleteSql = "DELETE FROM dbo.airport_config_rate WHERE source = 'RW'";
    $deleteResult = sqlsrv_query($conn_adl, $deleteSql);
    if ($deleteResult === false) {
        echo "WARNING: Could not delete existing RW rates: " . adl_sql_error_message() . "\n";
    } else {
        $deletedRows = sqlsrv_rows_affected($deleteResult);
        echo "Deleted $deletedRows existing RW rate entries.\n\n";
    }
}

// Process each row
while (($row = fgetcsv($handle)) !== false) {
    $stats['total']++;

    // Parse row
    $airport = trim($row[0] ?? '');
    $arrRwys = trim($row[1] ?? '');
    $depRwys = trim($row[2] ?? '');
    $vmcAar = parseRate($row[3] ?? '');
    $lvmcAar = parseRate($row[4] ?? '');
    $imcAar = parseRate($row[5] ?? '');
    $limcAar = parseRate($row[6] ?? '');
    $vmcAdr = parseRate($row[7] ?? '');
    $imcAdr = parseRate($row[8] ?? '');

    // Skip empty rows
    if (empty($airport)) {
        continue;
    }

    // Skip "OTHER" row
    if ($airport === 'OTHER') {
        $stats['skipped']++;
        continue;
    }

    // Normalize airport to FAA code
    $faaCode = normalizeToFaa($airport);

    // Find matching config in ADL - use LEFT JOIN so we get configs even without summary
    $findSql = "
        SELECT c.config_id, c.airport_faa, c.airport_icao, c.config_name,
               ISNULL(s.arr_runways, '') AS arr_runways,
               ISNULL(s.dep_runways, '') AS dep_runways
        FROM dbo.airport_config c
        LEFT JOIN dbo.vw_airport_config_summary s ON c.config_id = s.config_id
        WHERE c.airport_faa = ? OR c.airport_icao = ?
    ";

    $stmt = sqlsrv_query($conn_adl, $findSql, [$faaCode, $airport]);
    if ($stmt === false) {
        echo "ERROR finding config for $airport: " . adl_sql_error_message() . "\n";
        $stats['errors']++;
        continue;
    }

    $configs = [];
    while ($config = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $configs[] = $config;
    }
    sqlsrv_free_stmt($stmt);

    if (empty($configs)) {
        if ($verbose) {
            echo "  No configs found for $faaCode\n";
        }
        $stats['skipped']++;
        continue;
    }

    if ($verbose) {
        echo "  $faaCode: Found " . count($configs) . " config(s), CSV runways: ARR=$arrRwys DEP=$depRwys\n";
    }

    $matchedConfig = null;
    $normalizedCsvArr = normalizeRunways($arrRwys);
    $normalizedCsvDep = normalizeRunways($depRwys);

    foreach ($configs as $config) {
        $dbArr = normalizeRunways($config['arr_runways'] ?? '');
        $dbDep = normalizeRunways($config['dep_runways'] ?? '');

        if ($verbose) {
            echo "    Config {$config['config_id']} ({$config['config_name']}): DB ARR=$dbArr DEP=$dbDep\n";
        }

        // Try to match by runways (flexible matching)
        if ($dbArr === $normalizedCsvArr && $dbDep === $normalizedCsvDep) {
            // Exact match
            $matchedConfig = $config;
            if ($verbose) echo "      -> Exact match!\n";
            break;
        } elseif ($dbArr === $normalizedCsvArr || $dbDep === $normalizedCsvDep) {
            // Partial match - use if no exact match found
            if ($matchedConfig === null) {
                $matchedConfig = $config;
                if ($verbose) echo "      -> Partial match\n";
            }
        }
    }

    // If only one config exists for airport and no match yet, use it
    if ($matchedConfig === null && count($configs) === 1) {
        $matchedConfig = $configs[0];
        if ($verbose) echo "    -> Using only available config\n";
    }

    if (!$matchedConfig) {
        // No config found - skip (we only update existing configs)
        $stats['skipped']++;
        continue;
    }

    $stats['matched']++;
    $configId = $matchedConfig['config_id'];

    // Insert RW rates
    $rates = [
        ['VMC', 'ARR', $vmcAar],
        ['LVMC', 'ARR', $lvmcAar],
        ['IMC', 'ARR', $imcAar],
        ['LIMC', 'ARR', $limcAar],
        ['VMC', 'DEP', $vmcAdr],
        ['IMC', 'DEP', $imcAdr],
    ];

    foreach ($rates as $rate) {
        list($weather, $rateType, $value) = $rate;

        if ($value === null) {
            continue; // Skip null rates
        }

        if ($dryRun) {
            echo "Would insert: $airport ($configId) RW $weather $rateType = $value\n";
            $stats['inserted']++;
        } else {
            // Insert or update rate
            $insertSql = "
                IF NOT EXISTS (
                    SELECT 1 FROM dbo.airport_config_rate
                    WHERE config_id = ? AND source = 'RW' AND weather = ? AND rate_type = ?
                )
                INSERT INTO dbo.airport_config_rate (config_id, source, weather, rate_type, rate_value)
                VALUES (?, 'RW', ?, ?, ?)
                ELSE
                UPDATE dbo.airport_config_rate
                SET rate_value = ?
                WHERE config_id = ? AND source = 'RW' AND weather = ? AND rate_type = ?
            ";

            $params = [
                $configId, $weather, $rateType,
                $configId, $weather, $rateType, $value,
                $value, $configId, $weather, $rateType
            ];

            $insertResult = sqlsrv_query($conn_adl, $insertSql, $params);
            if ($insertResult === false) {
                echo "ERROR inserting rate for $airport: " . adl_sql_error_message() . "\n";
                $stats['errors']++;
            } else {
                $stats['inserted']++;
            }
        }
    }
}

fclose($handle);

echo "\n===========================================\n";
echo "Import Summary\n";
echo "===========================================\n";
echo "Total CSV rows:     {$stats['total']}\n";
echo "Matched configs:    {$stats['matched']}\n";
echo "Rates inserted:     {$stats['inserted']}\n";
echo "Rows skipped:       {$stats['skipped']}\n";
echo "Errors:             {$stats['errors']}\n";
echo "\nImport " . ($dryRun ? "preview " : "") . "complete.\n";

?>
