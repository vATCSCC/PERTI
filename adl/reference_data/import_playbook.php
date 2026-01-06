<?php
/**
 * ADL Reference Data Import: Playbook Routes
 * 
 * Imports playbook routes from playbook_routes.csv into playbook_routes table.
 * Run from command line: php import_playbook.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load config
require_once(__DIR__ . '/../../load/config.php');

if (!defined("ADL_SQL_HOST") || !defined("ADL_SQL_DATABASE")) {
    die("ERROR: ADL_SQL_* constants not defined in config.php\n");
}

echo "=== ADL Reference Data Import: Playbook Routes ===\n";
echo "Started at: " . gmdate('Y-m-d H:i:s') . " UTC\n\n";

// Connect to ADL database
$connectionInfo = [
    "Database" => ADL_SQL_DATABASE,
    "UID" => ADL_SQL_USERNAME,
    "PWD" => ADL_SQL_PASSWORD,
    "LoginTimeout" => 30,
    "Encrypt" => true,
    "TrustServerCertificate" => false
];

$conn = sqlsrv_connect(ADL_SQL_HOST, $connectionInfo);
if ($conn === false) {
    die("ERROR: Connection failed - " . print_r(sqlsrv_errors(), true) . "\n");
}
echo "Connected to ADL database.\n";

// Path to data file
$dataDir = __DIR__ . '/../../assets/data/';
$playbookFile = $dataDir . 'playbook_routes.csv';

if (!file_exists($playbookFile)) {
    die("ERROR: File not found: $playbookFile\n");
}

// ============================================================================
// Truncate existing data
// ============================================================================
echo "Truncating playbook_routes table...\n";
sqlsrv_query($conn, "DELETE FROM dbo.playbook_routes");

// ============================================================================
// Import Playbook Routes
// ============================================================================
echo "\nImporting playbook routes from playbook_routes.csv...\n";

$handle = fopen($playbookFile, 'r');
if (!$handle) {
    die("ERROR: Cannot open file: $playbookFile\n");
}

$totalRoutes = 0;
$errors = 0;
$lineNum = 0;

$insertSql = "
    INSERT INTO dbo.playbook_routes 
    (play_name, full_route, origin_airports, origin_tracons, origin_artccs, 
     dest_airports, dest_tracons, dest_artccs, is_active, source)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 'playbook_routes.csv')
";

while (($line = fgets($handle)) !== false) {
    $lineNum++;
    $line = trim($line);
    if (empty($line)) continue;
    
    // Skip header row
    if ($lineNum == 1 && strpos($line, 'Play,') === 0) {
        echo "  Skipping header row\n";
        continue;
    }
    
    $parts = str_getcsv($line);
    if (count($parts) < 2) continue;
    
    // CSV columns: Play, Route String, Origins, Origin_TRACONs, Origin_ARTCCs, Destinations, Dest_TRACONs, Dest_ARTCCs
    $playName = trim($parts[0] ?? '');
    $fullRoute = trim($parts[1] ?? '');
    $origins = trim($parts[2] ?? '');
    $originTracons = trim($parts[3] ?? '');
    $originArtccs = trim($parts[4] ?? '');
    $destinations = trim($parts[5] ?? '');
    $destTracons = trim($parts[6] ?? '');
    $destArtccs = trim($parts[7] ?? '');
    
    if (empty($playName) || empty($fullRoute)) continue;
    
    // Convert empty strings to NULL
    $origins = empty($origins) ? null : $origins;
    $originTracons = empty($originTracons) ? null : $originTracons;
    $originArtccs = empty($originArtccs) ? null : $originArtccs;
    $destinations = empty($destinations) ? null : $destinations;
    $destTracons = empty($destTracons) ? null : $destTracons;
    $destArtccs = empty($destArtccs) ? null : $destArtccs;
    
    $params = [$playName, $fullRoute, $origins, $originTracons, $originArtccs, 
               $destinations, $destTracons, $destArtccs];
    $stmt = sqlsrv_query($conn, $insertSql, $params);
    
    if ($stmt === false) {
        $errors++;
        if ($errors <= 5) {
            echo "ERROR inserting play $playName: " . print_r(sqlsrv_errors(), true) . "\n";
        }
    } else {
        $totalRoutes++;
        sqlsrv_free_stmt($stmt);
    }
    
    // Progress indicator
    if ($totalRoutes % 500 == 0) {
        echo "  Processed $totalRoutes playbook routes...\n";
    }
}

fclose($handle);
echo "  Imported $totalRoutes playbook routes ($errors errors)\n";

// ============================================================================
// Summary
// ============================================================================
$countSql = "SELECT COUNT(*) AS cnt, COUNT(DISTINCT play_name) AS plays FROM dbo.playbook_routes";
$countStmt = sqlsrv_query($conn, $countSql);
$row = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($countStmt);

echo "\n=== Import Complete ===\n";
echo "Total routes in table: " . $row['cnt'] . "\n";
echo "Unique play names: " . $row['plays'] . "\n";
echo "Finished at: " . gmdate('Y-m-d H:i:s') . " UTC\n";

sqlsrv_close($conn);
