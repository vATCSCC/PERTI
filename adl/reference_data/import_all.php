<?php
/**
 * ADL Reference Data Import: Master Script
 * 
 * Runs all reference data imports in the correct order.
 * Run from command line: php import_all.php
 * 
 * Import order:
 * 1. Navigation Fixes (points.csv, navaids.csv) - must be first
 * 2. Airways (awys.csv) - depends on nav_fixes for geometry
 * 3. CDRs (cdrs.csv) - standalone
 * 4. Playbook Routes (playbook_routes.csv) - standalone
 * 5. Procedures (dp_full_routes.csv, star_full_routes.csv) - standalone
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);  // No timeout for long imports

$startTime = microtime(true);

echo "════════════════════════════════════════════════════════════════════════════════\n";
echo "                    ADL REFERENCE DATA IMPORT - MASTER SCRIPT                   \n";
echo "════════════════════════════════════════════════════════════════════════════════\n";
echo "Started at: " . gmdate('Y-m-d H:i:s') . " UTC\n\n";

$scriptDir = __DIR__;
$scripts = [
    'import_nav_fixes.php' => 'Navigation Fixes',
    'import_airways.php' => 'Airways',
    'import_cdrs.php' => 'Coded Departure Routes',
    'import_playbook.php' => 'Playbook Routes',
    'import_procedures.php' => 'Navigation Procedures'
];

$success = 0;
$failed = 0;

foreach ($scripts as $script => $description) {
    echo "\n────────────────────────────────────────────────────────────────────────────────\n";
    echo "IMPORTING: $description ($script)\n";
    echo "────────────────────────────────────────────────────────────────────────────────\n\n";
    
    $scriptPath = $scriptDir . '/' . $script;
    
    if (!file_exists($scriptPath)) {
        echo "ERROR: Script not found: $scriptPath\n";
        $failed++;
        continue;
    }
    
    // Include the script
    try {
        include($scriptPath);
        $success++;
    } catch (Exception $e) {
        echo "ERROR: Exception in $script: " . $e->getMessage() . "\n";
        $failed++;
    }
}

$elapsed = microtime(true) - $startTime;
$minutes = floor($elapsed / 60);
$seconds = $elapsed % 60;

echo "\n════════════════════════════════════════════════════════════════════════════════\n";
echo "                              IMPORT COMPLETE                                    \n";
echo "════════════════════════════════════════════════════════════════════════════════\n";
echo "Scripts succeeded: $success\n";
echo "Scripts failed: $failed\n";
echo "Total time: {$minutes}m " . round($seconds, 1) . "s\n";
echo "Finished at: " . gmdate('Y-m-d H:i:s') . " UTC\n";
