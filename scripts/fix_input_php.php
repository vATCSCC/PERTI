<?php
/**
 * Bulk fix: Add input.php include to files that have config.php but not input.php
 */

$baseDir = __DIR__ . '/../api';

$filesToFix = [
    'demand/airport.php',
    'demand/atis.php',
    'demand/configs.php',
    'demand/override.php',
    'demand/rates.php',
    'demand/summary.php',
    'stats/airport.php',
    'stats/artcc.php',
    'stats/citypair.php',
    'stats/tmi.php',
    'weather/alerts.php',
    'weather/impact.php',
    'data/airspace_elements/get.php',
    'data/airspace_elements/list.php',
    'data/airspace_elements/lookup.php',
    'data/crossings/forecast.php',
    'adl/snapshot_history.php',
    'adl/atis-debug.php',
];

$fixed = 0;
$skipped = 0;

foreach ($filesToFix as $relPath) {
    $path = $baseDir . '/' . $relPath;
    if (!file_exists($path)) {
        echo "NOT FOUND: $relPath\n";
        continue;
    }

    $content = file_get_contents($path);

    // Skip if already has input.php
    if (strpos($content, 'input.php') !== false) {
        echo "SKIP (already has input.php): $relPath\n";
        $skipped++;
        continue;
    }

    // Find config.php include and add input.php after it
    $patterns = [
        'require_once("../../load/config.php");' => 'require_once("../../load/config.php");' . "\n" . 'require_once("../../load/input.php");',
        "require_once(__DIR__ . '/../../../load/config.php');" => "require_once(__DIR__ . '/../../../load/config.php');\nrequire_once(__DIR__ . '/../../../load/input.php');",
        'require_once(__DIR__ . "/../../load/config.php");' => 'require_once(__DIR__ . "/../../load/config.php");' . "\n" . 'require_once(__DIR__ . "/../../load/input.php");',
    ];

    $replaced = false;
    foreach ($patterns as $search => $replace) {
        if (strpos($content, $search) !== false) {
            $content = str_replace($search, $replace, $content);
            $replaced = true;
            break;
        }
    }

    if ($replaced) {
        file_put_contents($path, $content);
        echo "FIXED: $relPath\n";
        $fixed++;
    } else {
        echo "NO MATCH: $relPath\n";
    }
}

echo "\n=== DONE: $fixed fixed, $skipped skipped ===\n";
