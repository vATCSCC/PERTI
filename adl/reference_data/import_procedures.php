<?php
/**
 * ADL Reference Data Import: Navigation Procedures (DPs and STARs)
 * 
 * Imports DPs from dp_full_routes.csv and STARs from star_full_routes.csv
 * into nav_procedures table.
 * Run from command line: php import_procedures.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load config
require_once(__DIR__ . '/../../load/config.php');

if (!defined("ADL_SQL_HOST") || !defined("ADL_SQL_DATABASE")) {
    die("ERROR: ADL_SQL_* constants not defined in config.php\n");
}

echo "=== ADL Reference Data Import: Navigation Procedures ===\n";
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

// Path to data files
$dataDir = __DIR__ . '/../../assets/data/';
$dpFile = $dataDir . 'dp_full_routes.csv';
$starFile = $dataDir . 'star_full_routes.csv';

// ============================================================================
// Truncate existing data
// ============================================================================
echo "Truncating nav_procedures table...\n";
sqlsrv_query($conn, "DELETE FROM dbo.nav_procedures");

// ============================================================================
// Helper function to extract airport code from runway group
// ============================================================================
function extractAirportFromGroup($group) {
    // Format: "APT/RWY|RWY" e.g., "JFK/04L|04R|13L|13R" or "DEN/25|26"
    if (empty($group)) return null;
    
    // Split by space to get multiple airport groups
    $groups = preg_split('/\s+/', $group);
    $airports = [];
    
    foreach ($groups as $g) {
        if (preg_match('/^([A-Z0-9]{3,4})\//', $g, $matches)) {
            $apt = $matches[1];
            // Add K prefix if needed for US airports
            if (strlen($apt) == 3 && preg_match('/^[A-Z]{3}$/', $apt)) {
                $apt = 'K' . $apt;
            }
            $airports[] = $apt;
        }
    }
    
    return !empty($airports) ? implode(',', array_unique($airports)) : null;
}

// ============================================================================
// Import Departure Procedures (DPs)
// ============================================================================
echo "\nImporting DPs from dp_full_routes.csv...\n";

if (!file_exists($dpFile)) {
    echo "WARNING: File not found: $dpFile - skipping DPs\n";
} else {
    $handle = fopen($dpFile, 'r');
    if (!$handle) {
        echo "WARNING: Cannot open file: $dpFile - skipping DPs\n";
    } else {
        $totalDps = 0;
        $errors = 0;
        $lineNum = 0;
        
        $insertSql = "
            INSERT INTO dbo.nav_procedures 
            (procedure_type, airport_icao, procedure_name, computer_code, transition_name, 
             full_route, runways, is_active, source, effective_date)
            VALUES ('DP', ?, ?, ?, ?, ?, ?, 1, 'dp_full_routes.csv', ?)
        ";
        
        while (($line = fgets($handle)) !== false) {
            $lineNum++;
            $line = trim($line);
            if (empty($line)) continue;
            
            // Skip header row
            if ($lineNum == 1 && strpos($line, 'EFF_DATE,') === 0) {
                echo "  Skipping header row\n";
                continue;
            }
            
            $parts = str_getcsv($line);
            if (count($parts) < 9) continue;
            
            // Columns: EFF_DATE, DP_NAME, DP_COMPUTER_CODE, ARTCC, ORIG_GROUP, 
            //          BODY_NAME, TRANSITION_COMPUTER_CODE, TRANSITION_NAME, ROUTE_POINTS, ROUTE_FROM_ORIG_GROUP
            $effDate = trim($parts[0] ?? '');
            $dpName = trim($parts[1] ?? '');
            $computerCode = trim($parts[2] ?? '');
            $artcc = trim($parts[3] ?? '');
            $origGroup = trim($parts[4] ?? '');
            $bodyName = trim($parts[5] ?? '');
            $transCode = trim($parts[6] ?? '');
            $transName = trim($parts[7] ?? '');
            $routePoints = trim($parts[8] ?? '');
            
            if (empty($computerCode) || empty($routePoints)) continue;
            
            // Extract airport from orig_group
            $airport = extractAirportFromGroup($origGroup);
            if (!$airport) continue;
            
            // Parse effective date
            $effDateObj = null;
            if (!empty($effDate)) {
                $effDateObj = DateTime::createFromFormat('m/d/Y', $effDate);
                if (!$effDateObj) {
                    $effDateObj = DateTime::createFromFormat('Y-m-d', $effDate);
                }
            }
            
            // Extract runways from orig_group
            $runways = null;
            if (preg_match_all('/\/([0-9LRC|]+)/', $origGroup, $matches)) {
                $runways = implode(',', array_unique($matches[1]));
            }
            
            $params = [
                $airport,
                $dpName,
                $computerCode,
                empty($transName) ? null : $transName,
                $routePoints,
                $runways,
                $effDateObj
            ];
            
            $stmt = sqlsrv_query($conn, $insertSql, $params);
            
            if ($stmt === false) {
                $errors++;
                if ($errors <= 5) {
                    echo "ERROR inserting DP $computerCode: " . print_r(sqlsrv_errors(), true) . "\n";
                }
            } else {
                $totalDps++;
                sqlsrv_free_stmt($stmt);
            }
            
            if ($totalDps % 1000 == 0) {
                echo "  Processed $totalDps DPs...\n";
            }
        }
        
        fclose($handle);
        echo "  Imported $totalDps DPs ($errors errors)\n";
    }
}

// ============================================================================
// Import STARs
// ============================================================================
echo "\nImporting STARs from star_full_routes.csv...\n";

if (!file_exists($starFile)) {
    echo "WARNING: File not found: $starFile - skipping STARs\n";
} else {
    $handle = fopen($starFile, 'r');
    if (!$handle) {
        echo "WARNING: Cannot open file: $starFile - skipping STARs\n";
    } else {
        $totalStars = 0;
        $errors = 0;
        $lineNum = 0;
        
        $insertSql = "
            INSERT INTO dbo.nav_procedures 
            (procedure_type, airport_icao, procedure_name, computer_code, transition_name, 
             full_route, runways, is_active, source, effective_date)
            VALUES ('STAR', ?, ?, ?, ?, ?, ?, 1, 'star_full_routes.csv', ?)
        ";
        
        while (($line = fgets($handle)) !== false) {
            $lineNum++;
            $line = trim($line);
            if (empty($line)) continue;
            
            // Skip header row
            if ($lineNum == 1 && strpos($line, 'EFF_DATE,') === 0) {
                echo "  Skipping header row\n";
                continue;
            }
            
            $parts = str_getcsv($line);
            if (count($parts) < 9) continue;
            
            // Columns: EFF_DATE, ARRIVAL_NAME, STAR_COMPUTER_CODE, ARTCC, DEST_GROUP,
            //          BODY_NAME, TRANSITION_COMPUTER_CODE, TRANSITION_NAME, ROUTE_POINTS, ROUTE_FROM_DEST_GROUP
            $effDate = trim($parts[0] ?? '');
            $starName = trim($parts[1] ?? '');
            $computerCode = trim($parts[2] ?? '');
            $artcc = trim($parts[3] ?? '');
            $destGroup = trim($parts[4] ?? '');
            $bodyName = trim($parts[5] ?? '');
            $transCode = trim($parts[6] ?? '');
            $transName = trim($parts[7] ?? '');
            $routePoints = trim($parts[8] ?? '');
            
            if (empty($computerCode) || empty($routePoints)) continue;
            
            // Extract airport from dest_group
            $airport = extractAirportFromGroup($destGroup);
            if (!$airport) {
                // Try to extract from route (last element)
                $routeParts = preg_split('/\s+/', $routePoints);
                $lastPart = end($routeParts);
                if (preg_match('/^K?[A-Z]{3}$/', $lastPart)) {
                    $airport = strlen($lastPart) == 3 ? 'K' . $lastPart : $lastPart;
                }
            }
            if (!$airport) continue;
            
            // Parse effective date
            $effDateObj = null;
            if (!empty($effDate)) {
                $effDateObj = DateTime::createFromFormat('m/d/Y', $effDate);
                if (!$effDateObj) {
                    $effDateObj = DateTime::createFromFormat('Y-m-d', $effDate);
                }
            }
            
            // Extract runways from dest_group
            $runways = null;
            if (preg_match_all('/\/([0-9LRC|]+)/', $destGroup, $matches)) {
                $runways = implode(',', array_unique($matches[1]));
            }
            
            $params = [
                $airport,
                $starName,
                $computerCode,
                empty($transName) ? null : $transName,
                $routePoints,
                $runways,
                $effDateObj
            ];
            
            $stmt = sqlsrv_query($conn, $insertSql, $params);
            
            if ($stmt === false) {
                $errors++;
                if ($errors <= 5) {
                    echo "ERROR inserting STAR $computerCode: " . print_r(sqlsrv_errors(), true) . "\n";
                }
            } else {
                $totalStars++;
                sqlsrv_free_stmt($stmt);
            }
            
            if ($totalStars % 1000 == 0) {
                echo "  Processed $totalStars STARs...\n";
            }
        }
        
        fclose($handle);
        echo "  Imported $totalStars STARs ($errors errors)\n";
    }
}

// ============================================================================
// Summary
// ============================================================================
$countSql = "
    SELECT procedure_type, COUNT(*) AS cnt 
    FROM dbo.nav_procedures 
    GROUP BY procedure_type
";
$countStmt = sqlsrv_query($conn, $countSql);
echo "\n=== Import Complete ===\n";
while ($row = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC)) {
    echo $row['procedure_type'] . " count: " . $row['cnt'] . "\n";
}
sqlsrv_free_stmt($countStmt);

echo "Finished at: " . gmdate('Y-m-d H:i:s') . " UTC\n";

sqlsrv_close($conn);
