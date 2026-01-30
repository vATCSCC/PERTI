<?php
/**
 * Test Trajectory Crossing Functions
 *
 * Validates PostGIS trajectory crossing functions with a sample flight route.
 *
 * Test route: KDFW -> KMCO (Dallas to Orlando)
 * Expected crossings: ZFW, ZHU, ZJX (and potentially ZME, ZTL)
 *
 * Usage: php test_trajectory_crossings.php
 */

require_once __DIR__ . '/../load/connect.php';
require_once __DIR__ . '/../load/services/GISService.php';

echo "=== Trajectory Crossing Test ===\n\n";

// Initialize GIS
$gis = GISService::getInstance();
if (!$gis || !$gis->isConnected()) {
    echo "ERROR: Could not connect to PostGIS\n";
    exit(1);
}
echo "PostGIS: Connected\n\n";

// Sample route: KDFW -> KMCO (approximate waypoints)
$waypoints = [
    ['lat' => 32.8977, 'lon' => -97.0377, 'sequence_num' => 0],  // KDFW
    ['lat' => 32.6967, 'lon' => -96.3335, 'sequence_num' => 1],  // FORGE
    ['lat' => 32.2000, 'lon' => -94.5000, 'sequence_num' => 2],  // East Texas
    ['lat' => 31.7544, 'lon' => -91.1019, 'sequence_num' => 3],  // NATCHEZ area
    ['lat' => 30.8262, 'lon' => -86.6791, 'sequence_num' => 4],  // Pensacola area
    ['lat' => 29.5000, 'lon' => -83.5000, 'sequence_num' => 5],  // NW Florida
    ['lat' => 28.4294, 'lon' => -81.3090, 'sequence_num' => 6],  // KMCO
];

echo "Test Route: KDFW -> KMCO\n";
echo "Waypoints: " . count($waypoints) . "\n\n";

// Test 1: Get ARTCCs traversed
echo "--- Test 1: ARTCCs Traversed ---\n";
$artccs = $gis->getArtccsTraversed($waypoints);
echo "ARTCCs: " . ($artccs ? implode(', ', $artccs) : 'None') . "\n";
echo "Expected: ZFW, ZHU, ZJX (order may vary)\n\n";

// Test 2: Get ARTCC crossings with coordinates
echo "--- Test 2: ARTCC Crossings ---\n";
$artccCrossings = $gis->getTrajectoryArtccCrossings($waypoints);
if (empty($artccCrossings)) {
    echo "No ARTCC crossings found (check boundary data)\n";
} else {
    foreach ($artccCrossings as $c) {
        printf("  %s: %s at %.4f, %.4f (%.1f nm from origin, %s)\n",
            $c['artcc_code'],
            $c['crossing_type'],
            $c['crossing_lat'],
            $c['crossing_lon'],
            $c['distance_nm'],
            $c['is_oceanic'] ? 'oceanic' : 'domestic'
        );
    }
}
echo "\n";

// Test 3: Get sector crossings
echo "--- Test 3: Sector Crossings (HIGH only) ---\n";
$sectorCrossings = $gis->getTrajectorySectorCrossings($waypoints, 'HIGH');
if (empty($sectorCrossings)) {
    echo "No HIGH sector crossings found (check sector boundary data)\n";
} else {
    $count = min(10, count($sectorCrossings));  // Show first 10
    echo "Showing first {$count} of " . count($sectorCrossings) . " crossings:\n";
    for ($i = 0; $i < $count; $i++) {
        $c = $sectorCrossings[$i];
        printf("  %s (%s): %s at %.4f, %.4f (%.1f nm)\n",
            $c['sector_code'],
            $c['parent_artcc'],
            $c['crossing_type'],
            $c['crossing_lat'],
            $c['crossing_lon'],
            $c['distance_nm']
        );
    }
}
echo "\n";

// Test 4: All crossings combined
echo "--- Test 4: All Crossings ---\n";
$allCrossings = $gis->getTrajectoryAllCrossings($waypoints);
if (empty($allCrossings)) {
    echo "No crossings found\n";
} else {
    echo "Total crossings: " . count($allCrossings) . "\n";
    $byType = [];
    foreach ($allCrossings as $c) {
        $type = $c['boundary_type'];
        $byType[$type] = ($byType[$type] ?? 0) + 1;
    }
    foreach ($byType as $type => $count) {
        echo "  {$type}: {$count}\n";
    }
}
echo "\n";

// Test 5: Calculate ETAs (simulating mid-flight)
echo "--- Test 5: Crossing ETAs ---\n";
echo "Simulating flight at waypoint 2 with 450 kts groundspeed...\n";

$currentLat = 32.2;  // East Texas
$currentLon = -94.5;
$distFlown = 150.0;  // ~150nm from DFW
$groundspeed = 450;

$crossingEtas = $gis->calculateCrossingEtas(
    $waypoints,
    $currentLat,
    $currentLon,
    $distFlown,
    $groundspeed
);

if (empty($crossingEtas)) {
    echo "No future crossings found\n";
} else {
    echo "Future crossings:\n";
    $count = min(10, count($crossingEtas));
    for ($i = 0; $i < $count; $i++) {
        $c = $crossingEtas[$i];
        printf("  %s %s: %s in %.0f nm, ETA: %s\n",
            $c['boundary_type'],
            $c['boundary_code'],
            $c['crossing_type'],
            $c['distance_remaining_nm'],
            $c['eta_utc']
        );
    }
}
echo "\n";

// Test 6: Error handling
echo "--- Test 6: Error Handling ---\n";
$emptyResult = $gis->getTrajectoryArtccCrossings([]);
echo "Empty waypoints: " . (empty($emptyResult) ? "Handled correctly (empty array)" : "UNEXPECTED") . "\n";

$singlePoint = $gis->getTrajectoryArtccCrossings([['lat' => 32.0, 'lon' => -97.0, 'sequence_num' => 0]]);
echo "Single waypoint: " . (empty($singlePoint) ? "Handled correctly (need 2+ points)" : "UNEXPECTED") . "\n";

echo "\n=== Test Complete ===\n";

if ($gis->getLastError()) {
    echo "Last GIS error: " . $gis->getLastError() . "\n";
}
