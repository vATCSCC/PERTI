<?php
/**
 * REF Reference Data Import: Navigation Fixes
 *
 * Imports waypoints from points.csv and navaids.csv into VATSIM_REF.nav_fixes table.
 * After import, run sync_ref_to_adl.sql to refresh ADL cache.
 * Run from command line: php import_nav_fixes.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load config
require_once(__DIR__ . '/../../load/config.php');

if (!defined("REF_SQL_HOST") || !defined("REF_SQL_DATABASE")) {
    die("ERROR: REF_SQL_* constants not defined in config.php\n");
}

echo "=== REF Reference Data Import: Navigation Fixes ===\n";
echo "Target: VATSIM_REF (authoritative source)\n";
echo "Started at: " . gmdate('Y-m-d H:i:s') . " UTC\n\n";

// Connect to REF database (authoritative source)
$connectionInfo = [
    "Database" => REF_SQL_DATABASE,
    "UID" => REF_SQL_USERNAME,
    "PWD" => REF_SQL_PASSWORD,
    "LoginTimeout" => 30,
    "Encrypt" => true,
    "TrustServerCertificate" => false
];

$conn = sqlsrv_connect(REF_SQL_HOST, $connectionInfo);
if ($conn === false) {
    die("ERROR: Connection failed - " . print_r(sqlsrv_errors(), true) . "\n");
}
echo "Connected to VATSIM_REF database.\n";

// Paths to data files
$dataDir = __DIR__ . '/../../assets/data/';
$pointsFile = $dataDir . 'points.csv';
$navaidsFile = $dataDir . 'navaids.csv';

// ============================================================================
// Truncate existing data (optional - comment out to append)
// ============================================================================
$truncate = true;
if ($truncate) {
    echo "Truncating nav_fixes table...\n";
    $result = sqlsrv_query($conn, "TRUNCATE TABLE dbo.nav_fixes");
    if ($result === false) {
        echo "WARNING: Could not truncate (table may not exist or have FK constraints)\n";
        // Try DELETE instead
        sqlsrv_query($conn, "DELETE FROM dbo.nav_fixes");
    }
}

// ============================================================================
// Import waypoints from points.csv
// ============================================================================
echo "\nImporting waypoints from points.csv...\n";

if (!file_exists($pointsFile)) {
    die("ERROR: File not found: $pointsFile\n");
}

$handle = fopen($pointsFile, 'r');
if (!$handle) {
    die("ERROR: Cannot open file: $pointsFile\n");
}

$batchSize = 500;
$batch = [];
$totalPoints = 0;
$errors = 0;

// Prepare insert statement
$insertSql = "INSERT INTO dbo.nav_fixes (fix_name, fix_type, lat, lon, source) VALUES (?, ?, ?, ?, ?)";

while (($line = fgets($handle)) !== false) {
    $line = trim($line);
    if (empty($line)) continue;
    
    $parts = str_getcsv($line);
    if (count($parts) < 3) continue;
    
    $name = strtoupper(trim($parts[0]));
    $lat = floatval($parts[1]);
    $lon = floatval($parts[2]);
    
    // Skip invalid coordinates
    if ($lat == 0 && $lon == 0) continue;
    if (abs($lat) > 90 || abs($lon) > 180) continue;
    
    // Determine fix type based on name pattern
    $fixType = 'WAYPOINT';
    if (strlen($name) == 4 && preg_match('/^[A-Z]{4}$/', $name)) {
        $fixType = 'AIRPORT';
    } elseif (strlen($name) <= 3 && preg_match('/^[A-Z]{2,3}$/', $name)) {
        $fixType = 'NAVAID';
    }
    
    $params = [$name, $fixType, $lat, $lon, 'points.csv'];
    $stmt = sqlsrv_query($conn, $insertSql, $params);
    
    if ($stmt === false) {
        $errors++;
        if ($errors <= 5) {
            echo "ERROR inserting $name: " . print_r(sqlsrv_errors(), true) . "\n";
        }
    } else {
        $totalPoints++;
        sqlsrv_free_stmt($stmt);
    }
    
    // Progress indicator
    if ($totalPoints % 10000 == 0) {
        echo "  Processed $totalPoints waypoints...\n";
    }
}

fclose($handle);
echo "  Imported $totalPoints waypoints from points.csv ($errors errors)\n";

// ============================================================================
// Import navaids from navaids.csv (VORs, NDBs, etc.)
// ============================================================================
echo "\nImporting navaids from navaids.csv...\n";

if (!file_exists($navaidsFile)) {
    echo "WARNING: File not found: $navaidsFile - skipping\n";
} else {
    $handle = fopen($navaidsFile, 'r');
    if (!$handle) {
        echo "WARNING: Cannot open file: $navaidsFile - skipping\n";
    } else {
        $totalNavaids = 0;
        $navErrors = 0;
        
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (empty($line)) continue;
            
            $parts = str_getcsv($line);
            if (count($parts) < 3) continue;
            
            $name = strtoupper(trim($parts[0]));
            $lat = floatval($parts[1]);
            $lon = floatval($parts[2]);
            
            // Skip if already exists (from points.csv)
            // Navaids file may have _OLD versions
            if (strpos($name, '_OLD') !== false) continue;
            
            // Skip invalid coordinates
            if ($lat == 0 && $lon == 0) continue;
            if (abs($lat) > 90 || abs($lon) > 180) continue;
            
            $fixType = 'VOR';  // Most navaids are VORs
            
            // Check if exists
            $checkSql = "SELECT 1 FROM dbo.nav_fixes WHERE fix_name = ?";
            $checkStmt = sqlsrv_query($conn, $checkSql, [$name]);
            if ($checkStmt && sqlsrv_fetch($checkStmt)) {
                // Update existing to mark as NAVAID
                sqlsrv_free_stmt($checkStmt);
                $updateSql = "UPDATE dbo.nav_fixes SET fix_type = 'VOR' WHERE fix_name = ? AND fix_type = 'NAVAID'";
                sqlsrv_query($conn, $updateSql, [$name]);
                continue;
            }
            if ($checkStmt) sqlsrv_free_stmt($checkStmt);
            
            $params = [$name, $fixType, $lat, $lon, 'navaids.csv'];
            $stmt = sqlsrv_query($conn, $insertSql, $params);
            
            if ($stmt === false) {
                $navErrors++;
            } else {
                $totalNavaids++;
                sqlsrv_free_stmt($stmt);
            }
        }
        
        fclose($handle);
        echo "  Imported $totalNavaids navaids from navaids.csv ($navErrors errors)\n";
    }
}

// ============================================================================
// Update position_geo column
// ============================================================================
echo "\nUpdating position_geo column (spatial geography)...\n";
$updateGeoSql = "
    UPDATE dbo.nav_fixes 
    SET position_geo = geography::Point(lat, lon, 4326)
    WHERE position_geo IS NULL
      AND lat IS NOT NULL 
      AND lon IS NOT NULL
      AND lat BETWEEN -90 AND 90
      AND lon BETWEEN -180 AND 180
";
$result = sqlsrv_query($conn, $updateGeoSql);
if ($result === false) {
    echo "WARNING: Failed to update position_geo: " . print_r(sqlsrv_errors(), true) . "\n";
} else {
    $rowsAffected = sqlsrv_rows_affected($result);
    echo "  Updated $rowsAffected rows with position_geo\n";
    sqlsrv_free_stmt($result);
}

// ============================================================================
// Summary
// ============================================================================
$countSql = "SELECT COUNT(*) AS cnt FROM dbo.nav_fixes";
$countStmt = sqlsrv_query($conn, $countSql);
$row = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
$totalCount = $row['cnt'];
sqlsrv_free_stmt($countStmt);

echo "\n=== Import Complete ===\n";
echo "Total records in nav_fixes: $totalCount\n";
echo "Finished at: " . gmdate('Y-m-d H:i:s') . " UTC\n";
echo "\nNOTE: Run sync_ref_to_adl.sql to refresh VATSIM_ADL cache.\n";

sqlsrv_close($conn);
