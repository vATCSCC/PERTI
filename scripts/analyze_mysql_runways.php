<?php

/**
 * analyze_mysql_runways.php
 *
 * Analyzes runway data patterns in MySQL config_data table.
 * Visit: https://perti.vatcscc.org/scripts/analyze_mysql_runways.php
 */

include("../load/config.php");
include("../load/connect.php");

header('Content-Type: text/plain; charset=utf-8');

echo "===========================================\n";
echo "MySQL Runway Data Analysis\n";
echo "===========================================\n\n";

// Get all runway data
$query = mysqli_query($conn_sqli, "SELECT airport, arr, dep FROM config_data ORDER BY airport");

$patterns = [];
$unusual = [];
$all_runways = [];

while ($row = mysqli_fetch_assoc($query)) {
    $airport = $row['airport'];

    // Parse arrival runways
    $arr = trim($row['arr']);
    if (!empty($arr)) {
        $runways = preg_split('/[,\/\s]+/', $arr);
        foreach ($runways as $rwy) {
            $rwy = strtoupper(trim($rwy));
            if (empty($rwy)) continue;

            $all_runways[] = [
                'airport' => $airport,
                'runway' => $rwy,
                'use' => 'ARR',
                'len' => strlen($rwy)
            ];

            // Categorize
            if (preg_match('/^[0-9]{1,2}[LRC]?$/', $rwy)) {
                $patterns['Standard'][] = $rwy;
            } elseif (strpos($rwy, '_') !== false) {
                $patterns['Has underscore'][] = $rwy;
                $unusual[] = ['airport' => $airport, 'runway' => $rwy, 'use' => 'ARR'];
            } elseif (strlen($rwy) > 4) {
                $patterns['Too long (>4)'][] = $rwy;
                $unusual[] = ['airport' => $airport, 'runway' => $rwy, 'use' => 'ARR'];
            } elseif (preg_match('/^[A-Z]+$/', $rwy)) {
                $patterns['Text only'][] = $rwy;
                $unusual[] = ['airport' => $airport, 'runway' => $rwy, 'use' => 'ARR'];
            } else {
                $patterns['Other'][] = $rwy;
                $unusual[] = ['airport' => $airport, 'runway' => $rwy, 'use' => 'ARR'];
            }
        }
    }

    // Parse departure runways
    $dep = trim($row['dep']);
    if (!empty($dep)) {
        $runways = preg_split('/[,\/\s]+/', $dep);
        foreach ($runways as $rwy) {
            $rwy = strtoupper(trim($rwy));
            if (empty($rwy)) continue;

            $all_runways[] = [
                'airport' => $airport,
                'runway' => $rwy,
                'use' => 'DEP',
                'len' => strlen($rwy)
            ];

            // Categorize
            if (preg_match('/^[0-9]{1,2}[LRC]?$/', $rwy)) {
                $patterns['Standard'][] = $rwy;
            } elseif (strpos($rwy, '_') !== false) {
                $patterns['Has underscore'][] = $rwy;
                $unusual[] = ['airport' => $airport, 'runway' => $rwy, 'use' => 'DEP'];
            } elseif (strlen($rwy) > 4) {
                $patterns['Too long (>4)'][] = $rwy;
                $unusual[] = ['airport' => $airport, 'runway' => $rwy, 'use' => 'DEP'];
            } elseif (preg_match('/^[A-Z]+$/', $rwy)) {
                $patterns['Text only'][] = $rwy;
                $unusual[] = ['airport' => $airport, 'runway' => $rwy, 'use' => 'DEP'];
            } else {
                $patterns['Other'][] = $rwy;
                $unusual[] = ['airport' => $airport, 'runway' => $rwy, 'use' => 'DEP'];
            }
        }
    }
}

// Summary
echo "=== PATTERN COUNTS ===\n\n";
foreach ($patterns as $type => $rwys) {
    echo sprintf("%-20s %d\n", $type . ":", count($rwys));
}

echo "\n\n=== UNUSUAL RUNWAY VALUES ===\n\n";
echo sprintf("%-10s %-20s %-6s\n", "Airport", "Runway Value", "Use");
echo str_repeat("-", 40) . "\n";

// Deduplicate and show unusual
$seen = [];
foreach ($unusual as $u) {
    $key = $u['airport'] . '|' . $u['runway'] . '|' . $u['use'];
    if (!isset($seen[$key])) {
        echo sprintf("%-10s %-20s %-6s\n", $u['airport'], $u['runway'], $u['use']);
        $seen[$key] = true;
    }
}

echo "\n\n=== UNIQUE UNUSUAL VALUES ===\n\n";
$unique_unusual = [];
foreach ($unusual as $u) {
    $unique_unusual[$u['runway']] = true;
}
ksort($unique_unusual);
foreach (array_keys($unique_unusual) as $rwy) {
    echo "  '$rwy'\n";
}

echo "\n\n=== RUNWAYS > 4 CHARS (TRUNCATION RISK) ===\n\n";
echo sprintf("%-10s %-30s %-6s %-5s\n", "Airport", "Runway Value", "Use", "Len");
echo str_repeat("-", 55) . "\n";

$seen = [];
foreach ($all_runways as $r) {
    if ($r['len'] > 4) {
        $key = $r['airport'] . '|' . $r['runway'];
        if (!isset($seen[$key])) {
            echo sprintf("%-10s %-30s %-6s %-5d\n", $r['airport'], $r['runway'], $r['use'], $r['len']);
            $seen[$key] = true;
        }
    }
}

echo "\n\nAnalysis complete.\n";

?>
