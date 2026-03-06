<?php
/**
 * REF Reference Data Import: Airways
 *
 * Imports airways from awys.csv into VATSIM_REF.airways and airway_segments tables.
 * After import, run sync_ref_to_adl.sql to refresh ADL cache.
 * Run from command line: php import_airways.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load config
require_once(__DIR__ . '/../../load/config.php');

if (!defined("REF_SQL_HOST") || !defined("REF_SQL_DATABASE")) {
    die("ERROR: REF_SQL_* constants not defined in config.php\n");
}

echo "=== REF Reference Data Import: Airways ===\n";
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

// Path to data file
$dataDir = __DIR__ . '/../../assets/data/';
$awysFile = $dataDir . 'awys.csv';

if (!file_exists($awysFile)) {
    die("ERROR: File not found: $awysFile\n");
}

// ============================================================================
// Load fix coordinates into memory for segment geometry
// Stores ALL coordinates per fix name to support proximity disambiguation
// (matches the approach used by route-maplibre.js getPointByName)
// ============================================================================
echo "Loading fix coordinates from nav_fixes...\n";
$fixCoords = [];  // name => [[lat,lon], [lat,lon], ...]
$fixSql = "SELECT fix_name, lat, lon FROM dbo.nav_fixes";
$fixStmt = sqlsrv_query($conn, $fixSql);
if ($fixStmt === false) {
    echo "WARNING: Could not load fix coordinates. Segments will not have geometry.\n";
} else {
    $totalRows = 0;
    while ($row = sqlsrv_fetch_array($fixStmt, SQLSRV_FETCH_ASSOC)) {
        $name = strtoupper(trim($row['fix_name']));
        if (!isset($fixCoords[$name])) $fixCoords[$name] = [];
        $fixCoords[$name][] = [(float)$row['lat'], (float)$row['lon']];
        $totalRows++;
    }
    sqlsrv_free_stmt($fixStmt);
    $dupes = 0;
    foreach ($fixCoords as $locs) { if (count($locs) > 1) $dupes++; }
    echo "  Loaded $totalRows rows, " . count($fixCoords) . " unique names ($dupes with duplicates).\n";
}

// Load airports as fallback (3-letter codes like IPL, JLI)
$airportCoords = [];
$aptStmt = sqlsrv_query($conn, "SELECT ICAO_ID, ARPT_ID, LAT_DECIMAL, LONG_DECIMAL FROM dbo.apts WHERE LAT_DECIMAL IS NOT NULL");
if ($aptStmt) {
    while ($row = sqlsrv_fetch_array($aptStmt, SQLSRV_FETCH_ASSOC)) {
        $lat = (float)$row['LAT_DECIMAL'];
        $lon = (float)$row['LONG_DECIMAL'];
        if ($row['ICAO_ID']) $airportCoords[strtoupper(trim($row['ICAO_ID']))] = [$lat, $lon];
        if ($row['ARPT_ID']) $airportCoords[strtoupper(trim($row['ARPT_ID']))] = [$lat, $lon];
    }
    sqlsrv_free_stmt($aptStmt);
    echo "  Loaded " . count($airportCoords) . " airport coordinates.\n";
}

// Load area centers as fallback (ZLA, ZDC, etc.)
$centerCoords = [];
$ctrStmt = sqlsrv_query($conn, "SELECT center_code, lat, lon FROM dbo.area_centers WHERE lat IS NOT NULL");
if ($ctrStmt) {
    while ($row = sqlsrv_fetch_array($ctrStmt, SQLSRV_FETCH_ASSOC)) {
        $centerCoords[strtoupper(trim($row['center_code']))] = [(float)$row['lat'], (float)$row['lon']];
    }
    sqlsrv_free_stmt($ctrStmt);
    echo "  Loaded " . count($centerCoords) . " area center coordinates.\n";
}

/**
 * Resolve a fix name to coordinates with proximity disambiguation.
 * When multiple locations exist for the same name, picks the closest
 * to the previous waypoint's position (same approach as route-maplibre.js).
 */
function resolveFix($name, $prevLat, $prevLon) {
    global $fixCoords, $airportCoords, $centerCoords;
    $name = strtoupper(trim($name));

    if (isset($fixCoords[$name])) {
        $locs = $fixCoords[$name];
        if (count($locs) === 1) return $locs[0];
        if ($prevLat !== null) {
            $best = $locs[0];
            $bestDist = PHP_FLOAT_MAX;
            $cosLat = cos(deg2rad($prevLat));
            foreach ($locs as $loc) {
                $d = ($loc[0] - $prevLat) ** 2 + (($loc[1] - $prevLon) * $cosLat) ** 2;
                if ($d < $bestDist) { $bestDist = $d; $best = $loc; }
            }
            return $best;
        }
        return $locs[0];
    }
    if (isset($airportCoords[$name])) return $airportCoords[$name];
    if (strlen($name) === 3 && ctype_alpha($name) && isset($airportCoords['K' . $name]))
        return $airportCoords['K' . $name];
    if (isset($centerCoords[$name])) return $centerCoords[$name];
    return null;
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
    
    // Resolve all fix coordinates with proximity chaining
    $resolved = [];
    $prevLat = null;
    $prevLon = null;
    for ($i = 0; $i < $fixCount; $i++) {
        $coord = resolveFix($fixes[$i], $prevLat, $prevLon);
        if ($coord) {
            $resolved[$i] = $coord;
            $prevLat = $coord[0];
            $prevLon = $coord[1];
        }
    }

    // Post-validate first fix: if it's >10° from the second fix, re-resolve
    // using the second fix as proximity context. This prevents the first fix
    // on an airway from resolving to the wrong hemisphere when it has no
    // prior context (e.g., CUN on UT11 → Alaska instead of Cancun).
    if (isset($resolved[0]) && isset($resolved[1])) {
        $d = abs($resolved[0][0] - $resolved[1][0]) + abs($resolved[0][1] - $resolved[1][1]);
        if ($d > 10) {
            $reResolved = resolveFix($fixes[0], $resolved[1][0], $resolved[1][1]);
            if ($reResolved) {
                $resolved[0] = $reResolved;
            }
        }
    }

    // Insert segments between consecutive resolved fixes
    $seqNum = 0;
    for ($i = 0; $i < $fixCount - 1; $i++) {
        if (!isset($resolved[$i]) || !isset($resolved[$i + 1])) continue;

        $fromFix = strtoupper($fixes[$i]);
        $toFix = strtoupper($fixes[$i + 1]);
        $fromLat = $resolved[$i][0];
        $fromLon = $resolved[$i][1];
        $toLat = $resolved[$i + 1][0];
        $toLon = $resolved[$i + 1][1];

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

        $seqNum++;
        $insertSegmentSql = "
            INSERT INTO dbo.airway_segments
            (airway_id, airway_name, sequence_num, from_fix, to_fix, from_lat, from_lon, to_lat, to_lon, distance_nm, course_deg)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        $segParams = [$airwayId, $airwayName, $seqNum, $fromFix, $toFix, $fromLat, $fromLon, $toLat, $toLon, $distNm, $course];
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
echo "\nNOTE: Run sync_ref_to_adl.sql to refresh VATSIM_ADL cache.\n";

sqlsrv_close($conn);
