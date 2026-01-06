<?php
/**
 * ADL Reference Data: Generate SQL Insert Scripts (v4)
 * Clean version - no subqueries, fresh output folder
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 1);
set_time_limit(0);

$startTime = microtime(true);

echo "════════════════════════════════════════════════════════════════════════════════\n";
echo "              ADL REFERENCE DATA - SQL IMPORT GENERATOR v4                      \n";
echo "════════════════════════════════════════════════════════════════════════════════\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

// Paths
$dataDir = __DIR__ . '/../../assets/data/';
$outputDir = __DIR__ . '/sql_output/';  // Fresh folder name

// Create output directory
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}
echo "Output directory: $outputDir\n\n";

// Helper functions
function sqlEscape($str) {
    if ($str === null || $str === '') return 'NULL';
    $str = str_replace("'", "''", $str);
    return "N'" . $str . "'";
}

function parseCsv($line) {
    return str_getcsv($line, ',', '"', '');
}

$batchSize = 500;

// ============================================================================
// 1. nav_fixes.sql
// ============================================================================
echo "1. Generating nav_fixes.sql...\n";

$outputFile = $outputDir . '01_nav_fixes.sql';
$handle = fopen($outputFile, 'w');

fwrite($handle, "-- Nav Fixes Import - Generated " . date('Y-m-d H:i:s') . "\n");
fwrite($handle, "SET NOCOUNT ON;\nPRINT 'Importing nav fixes...';\n");
fwrite($handle, "DELETE FROM dbo.nav_fixes;\n\n");

$count = 0;
$batch = [];

// points.csv
$input = fopen($dataDir . 'points.csv', 'r');
while (($line = fgets($input)) !== false) {
    $line = trim($line);
    if (empty($line)) continue;
    
    $parts = parseCsv($line);
    if (count($parts) < 3) continue;
    
    $name = strtoupper(trim($parts[0]));
    $lat = floatval($parts[1]);
    $lon = floatval($parts[2]);
    
    if (($lat == 0 && $lon == 0) || abs($lat) > 90 || abs($lon) > 180) continue;
    
    $fixType = 'WAYPOINT';
    if (strlen($name) == 4 && preg_match('/^[A-Z]{4}$/', $name)) $fixType = 'AIRPORT';
    elseif (strlen($name) <= 3 && preg_match('/^[A-Z]{2,3}$/', $name)) $fixType = 'NAVAID';
    
    $batch[] = "(" . sqlEscape($name) . "," . sqlEscape($fixType) . ",$lat,$lon,N'points.csv')";
    $count++;
    
    if (count($batch) >= $batchSize) {
        fwrite($handle, "INSERT INTO dbo.nav_fixes(fix_name,fix_type,lat,lon,source)VALUES\n" . implode(",\n", $batch) . ";\n");
        $batch = [];
    }
}
fclose($input);
if (!empty($batch)) {
    fwrite($handle, "INSERT INTO dbo.nav_fixes(fix_name,fix_type,lat,lon,source)VALUES\n" . implode(",\n", $batch) . ";\n");
    $batch = [];
}
echo "   points.csv: $count rows\n";

// navaids.csv
$navCount = 0;
$input = fopen($dataDir . 'navaids.csv', 'r');
while (($line = fgets($input)) !== false) {
    $line = trim($line);
    if (empty($line)) continue;
    
    $parts = parseCsv($line);
    if (count($parts) < 3) continue;
    
    $name = strtoupper(trim($parts[0]));
    if (strpos($name, '_OLD') !== false) continue;
    
    $lat = floatval($parts[1]);
    $lon = floatval($parts[2]);
    
    if (($lat == 0 && $lon == 0) || abs($lat) > 90 || abs($lon) > 180) continue;
    
    $batch[] = "(" . sqlEscape($name) . ",N'VOR',$lat,$lon,N'navaids.csv')";
    $navCount++;
    
    if (count($batch) >= $batchSize) {
        fwrite($handle, "INSERT INTO dbo.nav_fixes(fix_name,fix_type,lat,lon,source)VALUES\n" . implode(",\n", $batch) . ";\n");
        $batch = [];
    }
}
fclose($input);
if (!empty($batch)) {
    fwrite($handle, "INSERT INTO dbo.nav_fixes(fix_name,fix_type,lat,lon,source)VALUES\n" . implode(",\n", $batch) . ";\n");
}
echo "   navaids.csv: $navCount rows\n";

fwrite($handle, "\nUPDATE dbo.nav_fixes SET position_geo=geography::Point(lat,lon,4326) WHERE position_geo IS NULL AND lat IS NOT NULL;\n");
fwrite($handle, "DECLARE @cnt INT; SELECT @cnt=COUNT(*) FROM dbo.nav_fixes; PRINT 'Done: '+CAST(@cnt AS VARCHAR)+' nav fixes';\n");
fclose($handle);

// ============================================================================
// 2. airways.sql
// ============================================================================
echo "2. Generating airways.sql...\n";

$outputFile = $outputDir . '02_airways.sql';
$handle = fopen($outputFile, 'w');

fwrite($handle, "-- Airways Import - Generated " . date('Y-m-d H:i:s') . "\n");
fwrite($handle, "SET NOCOUNT ON;\nPRINT 'Importing airways...';\n");
fwrite($handle, "DELETE FROM dbo.airway_segments;\nDELETE FROM dbo.airways;\n\n");

$count = 0;
$batch = [];
$input = fopen($dataDir . 'awys.csv', 'r');
while (($line = fgets($input)) !== false) {
    $line = trim($line);
    if (empty($line)) continue;
    
    $parts = parseCsv($line);
    if (count($parts) < 2) continue;
    
    $name = strtoupper(trim($parts[0]));
    $seq = trim($parts[1]);
    if (empty($name) || empty($seq)) continue;
    
    $type = 'OTHER';
    if (preg_match('/^J\d+$/', $name)) $type = 'JET';
    elseif (preg_match('/^V\d+$/', $name)) $type = 'VICTOR';
    elseif (preg_match('/^Q\d+$/', $name)) $type = 'RNAV_HIGH';
    elseif (preg_match('/^T\d+$/', $name)) $type = 'RNAV_LOW';
    elseif (preg_match('/^A\d+$/', $name)) $type = 'OCEANIC';
    
    $fixes = preg_split('/\s+/', $seq);
    $fixCount = count($fixes);
    
    $batch[] = "(" . sqlEscape($name) . "," . sqlEscape($type) . "," . sqlEscape($seq) . ",$fixCount," . 
               sqlEscape($fixes[0]) . "," . sqlEscape($fixes[$fixCount-1]) . ",N'awys.csv')";
    $count++;
    
    if (count($batch) >= $batchSize) {
        fwrite($handle, "INSERT INTO dbo.airways(airway_name,airway_type,fix_sequence,fix_count,start_fix,end_fix,source)VALUES\n" . implode(",\n", $batch) . ";\n");
        $batch = [];
    }
}
fclose($input);
if (!empty($batch)) {
    fwrite($handle, "INSERT INTO dbo.airways(airway_name,airway_type,fix_sequence,fix_count,start_fix,end_fix,source)VALUES\n" . implode(",\n", $batch) . ";\n");
}

fwrite($handle, "DECLARE @cnt INT; SELECT @cnt=COUNT(*) FROM dbo.airways; PRINT 'Done: '+CAST(@cnt AS VARCHAR)+' airways';\n");
fclose($handle);
echo "   $count airways\n";

// ============================================================================
// 3. cdrs.sql
// ============================================================================
echo "3. Generating cdrs.sql...\n";

$outputFile = $outputDir . '03_cdrs.sql';
$handle = fopen($outputFile, 'w');

fwrite($handle, "-- CDRs Import - Generated " . date('Y-m-d H:i:s') . "\n");
fwrite($handle, "SET NOCOUNT ON;\nPRINT 'Importing CDRs...';\n");
fwrite($handle, "DELETE FROM dbo.coded_departure_routes;\n\n");

$count = 0;
$batch = [];
$input = fopen($dataDir . 'cdrs.csv', 'r');
while (($line = fgets($input)) !== false) {
    $line = trim($line);
    if (empty($line)) continue;
    
    $parts = parseCsv($line);
    if (count($parts) < 2) continue;
    
    $code = strtoupper(trim($parts[0]));
    $route = trim($parts[1]);
    if (empty($code) || empty($route) || strpos($code, '_OLD') !== false) continue;
    
    $rp = preg_split('/\s+/', $route);
    $orig = (count($rp) > 0 && preg_match('/^[KCP][A-Z]{3}$/', $rp[0])) ? $rp[0] : null;
    $dest = (count($rp) > 0 && preg_match('/^[KCP][A-Z]{3}$/', $rp[count($rp)-1])) ? $rp[count($rp)-1] : null;
    
    $batch[] = "(" . sqlEscape($code) . "," . sqlEscape($route) . "," . sqlEscape($orig) . "," . sqlEscape($dest) . ",1,N'cdrs.csv')";
    $count++;
    
    if (count($batch) >= $batchSize) {
        fwrite($handle, "INSERT INTO dbo.coded_departure_routes(cdr_code,full_route,origin_icao,dest_icao,is_active,source)VALUES\n" . implode(",\n", $batch) . ";\n");
        $batch = [];
    }
}
fclose($input);
if (!empty($batch)) {
    fwrite($handle, "INSERT INTO dbo.coded_departure_routes(cdr_code,full_route,origin_icao,dest_icao,is_active,source)VALUES\n" . implode(",\n", $batch) . ";\n");
}

fwrite($handle, "DECLARE @cnt INT; SELECT @cnt=COUNT(*) FROM dbo.coded_departure_routes; PRINT 'Done: '+CAST(@cnt AS VARCHAR)+' CDRs';\n");
fclose($handle);
echo "   $count CDRs\n";

// ============================================================================
// 4. playbook.sql
// ============================================================================
echo "4. Generating playbook.sql...\n";

$outputFile = $outputDir . '04_playbook.sql';
$handle = fopen($outputFile, 'w');

fwrite($handle, "-- Playbook Import - Generated " . date('Y-m-d H:i:s') . "\n");
fwrite($handle, "SET NOCOUNT ON;\nPRINT 'Importing playbook routes...';\n");
fwrite($handle, "DELETE FROM dbo.playbook_routes;\n\n");

$count = 0;
$lineNum = 0;
$batch = [];
$input = fopen($dataDir . 'playbook_routes.csv', 'r');
while (($line = fgets($input)) !== false) {
    $lineNum++;
    $line = trim($line);
    if (empty($line)) continue;
    if ($lineNum == 1 && strpos($line, 'Play,') === 0) continue;
    
    $parts = parseCsv($line);
    if (count($parts) < 2) continue;
    
    $play = trim($parts[0] ?? '');
    $route = trim($parts[1] ?? '');
    if (empty($play) || empty($route)) continue;
    
    $o = trim($parts[2] ?? '') ?: null;
    $ot = trim($parts[3] ?? '') ?: null;
    $oa = trim($parts[4] ?? '') ?: null;
    $d = trim($parts[5] ?? '') ?: null;
    $dt = trim($parts[6] ?? '') ?: null;
    $da = trim($parts[7] ?? '') ?: null;
    
    $batch[] = "(" . sqlEscape($play) . "," . sqlEscape($route) . "," . 
               sqlEscape($o) . "," . sqlEscape($ot) . "," . sqlEscape($oa) . "," .
               sqlEscape($d) . "," . sqlEscape($dt) . "," . sqlEscape($da) . ",1,N'playbook_routes.csv')";
    $count++;
    
    if (count($batch) >= $batchSize) {
        fwrite($handle, "INSERT INTO dbo.playbook_routes(play_name,full_route,origin_airports,origin_tracons,origin_artccs,dest_airports,dest_tracons,dest_artccs,is_active,source)VALUES\n" . implode(",\n", $batch) . ";\n");
        $batch = [];
    }
}
fclose($input);
if (!empty($batch)) {
    fwrite($handle, "INSERT INTO dbo.playbook_routes(play_name,full_route,origin_airports,origin_tracons,origin_artccs,dest_airports,dest_tracons,dest_artccs,is_active,source)VALUES\n" . implode(",\n", $batch) . ";\n");
}

fwrite($handle, "DECLARE @cnt INT; SELECT @cnt=COUNT(*) FROM dbo.playbook_routes; PRINT 'Done: '+CAST(@cnt AS VARCHAR)+' playbook routes';\n");
fclose($handle);
echo "   $count playbook routes\n";

// ============================================================================
// 5. procedures.sql
// ============================================================================
echo "5. Generating procedures.sql...\n";

$outputFile = $outputDir . '05_procedures.sql';
$handle = fopen($outputFile, 'w');

fwrite($handle, "-- Procedures Import - Generated " . date('Y-m-d H:i:s') . "\n");
fwrite($handle, "SET NOCOUNT ON;\nPRINT 'Importing procedures...';\n");
fwrite($handle, "DELETE FROM dbo.nav_procedures;\n\n");

function extractAirport($group) {
    if (empty($group)) return null;
    if (preg_match('/^([A-Z0-9]{3,4})\//', $group, $m)) {
        $apt = $m[1];
        return (strlen($apt) == 3 && preg_match('/^[A-Z]{3}$/', $apt)) ? 'K'.$apt : $apt;
    }
    return null;
}

// DPs
$dpCount = 0;
$batch = [];
$lineNum = 0;
$input = fopen($dataDir . 'dp_full_routes.csv', 'r');
while (($line = fgets($input)) !== false) {
    $lineNum++;
    $line = trim($line);
    if (empty($line)) continue;
    if ($lineNum == 1 && strpos($line, 'EFF_DATE,') === 0) continue;
    
    $parts = parseCsv($line);
    if (count($parts) < 9) continue;
    
    $name = trim($parts[1] ?? '');
    $code = trim($parts[2] ?? '');
    $orig = trim($parts[4] ?? '');
    $trans = trim($parts[7] ?? '') ?: null;
    $route = trim($parts[8] ?? '');
    
    if (empty($code) || empty($route)) continue;
    $apt = extractAirport($orig);
    if (!$apt) continue;
    
    $batch[] = "(N'DP'," . sqlEscape($apt) . "," . sqlEscape($name) . "," . 
               sqlEscape($code) . "," . sqlEscape($trans) . "," . sqlEscape($route) . ",1,N'dp_full_routes.csv')";
    $dpCount++;
    
    if (count($batch) >= $batchSize) {
        fwrite($handle, "INSERT INTO dbo.nav_procedures(procedure_type,airport_icao,procedure_name,computer_code,transition_name,full_route,is_active,source)VALUES\n" . implode(",\n", $batch) . ";\n");
        $batch = [];
    }
}
fclose($input);
if (!empty($batch)) {
    fwrite($handle, "INSERT INTO dbo.nav_procedures(procedure_type,airport_icao,procedure_name,computer_code,transition_name,full_route,is_active,source)VALUES\n" . implode(",\n", $batch) . ";\n");
    $batch = [];
}
echo "   $dpCount DPs\n";

// STARs
$starCount = 0;
$lineNum = 0;
$input = fopen($dataDir . 'star_full_routes.csv', 'r');
while (($line = fgets($input)) !== false) {
    $lineNum++;
    $line = trim($line);
    if (empty($line)) continue;
    if ($lineNum == 1 && strpos($line, 'EFF_DATE,') === 0) continue;
    
    $parts = parseCsv($line);
    if (count($parts) < 9) continue;
    
    $name = trim($parts[1] ?? '');
    $code = trim($parts[2] ?? '');
    $dest = trim($parts[4] ?? '');
    $trans = trim($parts[7] ?? '') ?: null;
    $route = trim($parts[8] ?? '');
    
    if (empty($code) || empty($route)) continue;
    $apt = extractAirport($dest);
    if (!$apt) continue;
    
    $batch[] = "(N'STAR'," . sqlEscape($apt) . "," . sqlEscape($name) . "," . 
               sqlEscape($code) . "," . sqlEscape($trans) . "," . sqlEscape($route) . ",1,N'star_full_routes.csv')";
    $starCount++;
    
    if (count($batch) >= $batchSize) {
        fwrite($handle, "INSERT INTO dbo.nav_procedures(procedure_type,airport_icao,procedure_name,computer_code,transition_name,full_route,is_active,source)VALUES\n" . implode(",\n", $batch) . ";\n");
        $batch = [];
    }
}
fclose($input);
if (!empty($batch)) {
    fwrite($handle, "INSERT INTO dbo.nav_procedures(procedure_type,airport_icao,procedure_name,computer_code,transition_name,full_route,is_active,source)VALUES\n" . implode(",\n", $batch) . ";\n");
}

fwrite($handle, "DECLARE @cnt INT; SELECT @cnt=COUNT(*) FROM dbo.nav_procedures; PRINT 'Done: '+CAST(@cnt AS VARCHAR)+' procedures';\n");
fclose($handle);
echo "   $starCount STARs\n";

// ============================================================================
// Done
// ============================================================================
$elapsed = microtime(true) - $startTime;

echo "\n════════════════════════════════════════════════════════════════════════════════\n";
echo "COMPLETE! Time: " . round($elapsed, 2) . "s\n";
echo "Files in: $outputDir\n";
echo "Run SQL files 01-05 in Azure Data Studio/SSMS\n";
