<?php
/**
 * ADL Reference Data Import: Coded Departure Routes (CDRs)
 * 
 * Imports CDRs from cdrs.csv into coded_departure_routes table.
 * Run from command line: php import_cdrs.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load config
require_once(__DIR__ . '/../../load/config.php');

if (!defined("ADL_SQL_HOST") || !defined("ADL_SQL_DATABASE")) {
    die("ERROR: ADL_SQL_* constants not defined in config.php\n");
}

echo "=== ADL Reference Data Import: Coded Departure Routes ===\n";
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
$cdrsFile = $dataDir . 'cdrs.csv';

if (!file_exists($cdrsFile)) {
    die("ERROR: File not found: $cdrsFile\n");
}

// ============================================================================
// Truncate existing data
// ============================================================================
echo "Truncating coded_departure_routes table...\n";
sqlsrv_query($conn, "DELETE FROM dbo.coded_departure_routes");

// ============================================================================
// Import CDRs
// ============================================================================
echo "\nImporting CDRs from cdrs.csv...\n";

$handle = fopen($cdrsFile, 'r');
if (!$handle) {
    die("ERROR: Cannot open file: $cdrsFile\n");
}

$totalCdrs = 0;
$errors = 0;

$insertSql = "
    INSERT INTO dbo.coded_departure_routes 
    (cdr_code, full_route, origin_icao, dest_icao, is_active, source)
    VALUES (?, ?, ?, ?, 1, 'cdrs.csv')
";

while (($line = fgets($handle)) !== false) {
    $line = trim($line);
    if (empty($line)) continue;
    
    $parts = str_getcsv($line);
    if (count($parts) < 2) continue;
    
    $cdrCode = strtoupper(trim($parts[0]));
    $fullRoute = trim($parts[1]);
    
    if (empty($cdrCode) || empty($fullRoute)) continue;
    
    // Skip _OLD versions
    if (strpos($cdrCode, '_OLD') !== false) continue;
    
    // Extract origin and destination from route
    $routeParts = preg_split('/\s+/', $fullRoute);
    $originIcao = null;
    $destIcao = null;
    
    // First element might be origin airport
    if (count($routeParts) > 0) {
        $first = $routeParts[0];
        if (preg_match('/^K[A-Z]{3}$/', $first) || preg_match('/^[CP][A-Z]{3}$/', $first)) {
            $originIcao = $first;
        }
    }
    
    // Last element might be destination airport
    if (count($routeParts) > 0) {
        $last = $routeParts[count($routeParts) - 1];
        if (preg_match('/^K[A-Z]{3}$/', $last) || preg_match('/^[CP][A-Z]{3}$/', $last)) {
            $destIcao = $last;
        }
    }
    
    // Try to extract from CDR code pattern (e.g., ABQATLNB = ABQ->ATL variant NB)
    if (!$originIcao || !$destIcao) {
        // Common pattern: first 3 chars = origin, next 3 = destination
        if (strlen($cdrCode) >= 6) {
            $potentialOrig = 'K' . substr($cdrCode, 0, 3);
            $potentialDest = 'K' . substr($cdrCode, 3, 3);
            
            // Only use if looks like airport codes
            if (!$originIcao && preg_match('/^K[A-Z]{3}$/', $potentialOrig)) {
                $originIcao = $potentialOrig;
            }
            if (!$destIcao && preg_match('/^K[A-Z]{3}$/', $potentialDest)) {
                $destIcao = $potentialDest;
            }
        }
    }
    
    $params = [$cdrCode, $fullRoute, $originIcao, $destIcao];
    $stmt = sqlsrv_query($conn, $insertSql, $params);
    
    if ($stmt === false) {
        $errors++;
        if ($errors <= 5) {
            echo "ERROR inserting CDR $cdrCode: " . print_r(sqlsrv_errors(), true) . "\n";
        }
    } else {
        $totalCdrs++;
        sqlsrv_free_stmt($stmt);
    }
    
    // Progress indicator
    if ($totalCdrs % 500 == 0) {
        echo "  Processed $totalCdrs CDRs...\n";
    }
}

fclose($handle);
echo "  Imported $totalCdrs CDRs ($errors errors)\n";

// ============================================================================
// Summary
// ============================================================================
$countSql = "SELECT COUNT(*) AS cnt FROM dbo.coded_departure_routes";
$countStmt = sqlsrv_query($conn, $countSql);
$row = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
$totalCount = $row['cnt'];
sqlsrv_free_stmt($countStmt);

echo "\n=== Import Complete ===\n";
echo "Total CDRs in table: $totalCount\n";
echo "Finished at: " . gmdate('Y-m-d H:i:s') . " UTC\n";

sqlsrv_close($conn);
