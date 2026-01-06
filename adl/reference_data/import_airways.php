<?php
/**
 * ADL Reference Data Import: Airways
 * 
 * Imports airways from awys.csv into airways and airway_segments tables.
 * Run from command line: php import_airways.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load config
require_once(__DIR__ . '/../../load/config.php');

if (!defined("ADL_SQL_HOST") || !defined("ADL_SQL_DATABASE")) {
    die("ERROR: ADL_SQL_* constants not defined in config.php\n");
}

echo "=== ADL Reference Data Import: Airways ===\n";
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
$awysFile = $dataDir . 'awys.csv';

if (!file_exists($awysFile)) {
    die("ERROR: File not found: $awysFile\n");
}

// ============================================================================
// Load fix coordinates into memory for segment geometry
// ============================================================================
echo "Loading fix coordinates from nav_fixes...\n";
$fixCoords = [];
$fixSql = "SELECT fix_name, lat, lon FROM dbo.nav_fixes";
$fixStmt = sqlsrv_query($conn, $fixSql);
if ($fixStmt === false) {
    echo "WARNING: Could not load fix coordinates. Segments will not have geometry.\n";
} else {
    while ($row = sqlsrv_fetch_array($fixStmt, SQLSRV_FETCH_ASSOC)) {
        $fixCoords[strtoupper($row['fix_name'])] = [
            'lat' => $row['lat'],
            'lon' => $row['lon']
        ];
    }
    sqlsrv_free_stmt($fixStmt);
    echo "  Loaded " . count($fixCoords) . " fix coordinates.\n";
}

// ============================================================================
// Truncate existing data
// ============================================================================
echo "\nTruncating airways and airway_segments tables...\n";
sqlsrv_query($conn, "DELETE FROM dbo.airway_segments");
sqlsrv_query($conn, "DELETE FROM dbo.airways");

// ============================================================================
// Import airways
// ============================================================================
echo "\nImporting airways from awys.csv...\n";

$handle = fopen($awysFile, 'r');
if (!$handle) {
    die("ERROR: Cannot open file: $awysFile\n");
}

$totalAirways = 0;
$totalSegments = 0;
$errors = 0;

while (($line = fgets($handle)) !== false) {
    $line = trim($line);
    if (empty($line)) continue;
    
    $parts = str_getcsv($line);
    if (count($parts) < 2) continue;
    
    $airwayName = strtoupper(trim($parts[0]));
    $fixSequence = trim($parts[1]);
    
    if (empty($airwayName) || empty($fixSequence)) continue;
    
    // Determine airway type from name
    $airwayType = 'OTHER';
    if (preg_match('/^J\d+$/', $airwayName)) {
        $airwayType = 'JET';
    } elseif (preg_match('/^V\d+$/', $airwayName)) {
        $airwayType = 'VICTOR';
    } elseif (preg_match('/^Q\d+$/', $airwayName)) {
        $airwayType = 'RNAV_HIGH';
    } elseif (preg_match('/^T\d+$/', $airwayName)) {
        $airwayType = 'RNAV_LOW';
    } elseif (preg_match('/^A\d+$/', $airwayName)) {
        $airwayType = 'OCEANIC';
    } elseif (preg_match('/^[LMN]\d+$/', $airwayName)) {
        $airwayType = 'EUROPEAN';
    }
    
    $fixes = preg_split('/\s+/', $fixSequence);
    $fixCount = count($fixes);
    
    $startFix = $fixes[0] ?? null;
    $endFix = $fixes[$fixCount - 1] ?? null;
    
    // Insert airway
    $insertAirwaySql = "
        INSERT INTO dbo.airways (airway_name, airway_type, fix_sequence, fix_count, start_fix, end_fix, source)
        OUTPUT INSERTED.airway_id
        VALUES (?, ?, ?, ?, ?, ?, 'awys.csv')
    ";
    $params = [$airwayName, $airwayType, $fixSequence, $fixCount, $startFix, $endFix];
    $stmt = sqlsrv_query($conn, $insertAirwaySql, $params);
    
    if ($stmt === false) {
        $errors++;
        if ($errors <= 5) {
            echo "ERROR inserting airway $airwayName: " . print_r(sqlsrv_errors(), true) . "\n";
        }
        continue;
    }
    
    // Get the inserted airway_id
    sqlsrv_fetch($stmt);
    $airwayId = sqlsrv_get_field($stmt, 0);
    sqlsrv_free_stmt($stmt);
    
    $totalAirways++;
    
    // Insert segments
    for ($i = 0; $i < $fixCount - 1; $i++) {
        $fromFix = strtoupper($fixes[$i]);
        $toFix = strtoupper($fixes[$i + 1]);
        
        $fromCoord = $fixCoords[$fromFix] ?? null;
        $toCoord = $fixCoords[$toFix] ?? null;
        
        if (!$fromCoord || !$toCoord) continue;
        
        $fromLat = $fromCoord['lat'];
        $fromLon = $fromCoord['lon'];
        $toLat = $toCoord['lat'];
        $toLon = $toCoord['lon'];
        
        // Calculate approximate distance (nm)
        $dLat = deg2rad($toLat - $fromLat);
        $dLon = deg2rad($toLon - $fromLon);
        $avgLat = deg2rad(($fromLat + $toLat) / 2);
        $distNm = sqrt(pow($dLat * 60 * 180 / M_PI, 2) + pow($dLon * 60 * 180 / M_PI * cos($avgLat), 2));
        $distNm = round($distNm, 2);
        
        // Calculate course (approximate)
        $course = rad2deg(atan2(sin(deg2rad($toLon - $fromLon)) * cos(deg2rad($toLat)),
            cos(deg2rad($fromLat)) * sin(deg2rad($toLat)) - sin(deg2rad($fromLat)) * cos(deg2rad($toLat)) * cos(deg2rad($toLon - $fromLon))));
        $course = (int)(($course + 360) % 360);
        
        $insertSegmentSql = "
            INSERT INTO dbo.airway_segments 
            (airway_id, airway_name, sequence_num, from_fix, to_fix, from_lat, from_lon, to_lat, to_lon, distance_nm, course_deg)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        $segParams = [$airwayId, $airwayName, $i + 1, $fromFix, $toFix, $fromLat, $fromLon, $toLat, $toLon, $distNm, $course];
        $segStmt = sqlsrv_query($conn, $insertSegmentSql, $segParams);
        
        if ($segStmt !== false) {
            $totalSegments++;
            sqlsrv_free_stmt($segStmt);
        }
    }
    
    // Progress indicator
    if ($totalAirways % 100 == 0) {
        echo "  Processed $totalAirways airways...\n";
    }
}

fclose($handle);
echo "  Imported $totalAirways airways with $totalSegments segments ($errors errors)\n";

// ============================================================================
// Update segment_geo column
// ============================================================================
echo "\nUpdating segment_geo column (spatial geography)...\n";
$updateGeoSql = "
    UPDATE dbo.airway_segments 
    SET segment_geo = geography::STGeomFromText(
        'LINESTRING(' + CAST(from_lon AS VARCHAR) + ' ' + CAST(from_lat AS VARCHAR) + ', ' +
        CAST(to_lon AS VARCHAR) + ' ' + CAST(to_lat AS VARCHAR) + ')', 4326)
    WHERE segment_geo IS NULL
      AND from_lat IS NOT NULL 
      AND from_lon IS NOT NULL
      AND to_lat IS NOT NULL 
      AND to_lon IS NOT NULL
";
$result = sqlsrv_query($conn, $updateGeoSql);
if ($result === false) {
    echo "WARNING: Failed to update segment_geo: " . print_r(sqlsrv_errors(), true) . "\n";
} else {
    $rowsAffected = sqlsrv_rows_affected($result);
    echo "  Updated $rowsAffected segments with geometry\n";
    sqlsrv_free_stmt($result);
}

// ============================================================================
// Summary
// ============================================================================
echo "\n=== Import Complete ===\n";
echo "Airways imported: $totalAirways\n";
echo "Segments created: $totalSegments\n";
echo "Finished at: " . gmdate('Y-m-d H:i:s') . " UTC\n";

sqlsrv_close($conn);
