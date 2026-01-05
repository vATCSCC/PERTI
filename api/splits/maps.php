<?php
/**
 * List Available Sector Maps API
 * 
 * Returns available sector-related videomaps for a facility
 * based on the ARTCC JSON metadata (without requiring full VideoMaps folder).
 * 
 * GET Parameters:
 *   - facility: ARTCC code
 * 
 * Response: JSON with available maps grouped by type
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

$facility = strtoupper($_GET['facility'] ?? '');

if (!$facility) {
    echo json_encode(['error' => 'Missing facility parameter']);
    exit;
}

// Try to find ARTCC JSON
$artccPaths = [
    dirname(__DIR__, 2) . "/data/ARTCCs/{$facility}.json",  // Local data folder
    "/mnt/data/CRC_extracted/ARTCCs/{$facility}.json",      // CRC extraction
    dirname(__DIR__, 2) . "/assets/data/ARTCCs/{$facility}.json", // Assets folder
];

$artccData = null;
$usedPath = null;

foreach ($artccPaths as $path) {
    if (file_exists($path)) {
        $artccData = json_decode(file_get_contents($path), true);
        $usedPath = $path;
        break;
    }
}

if (!$artccData) {
    echo json_encode([
        'error' => "ARTCC data not found for: $facility",
        'searchedPaths' => $artccPaths,
        'hint' => 'Place ARTCC JSON files in data/ARTCCs/ folder'
    ]);
    exit;
}

// Categorize videomaps
$sectorMaps = [];
$boundaryMaps = [];
$otherMaps = [];

foreach ($artccData['videoMaps'] ?? [] as $vm) {
    $name = $vm['name'] ?? '';
    $nameUpper = strtoupper($name);
    $tags = $vm['tags'] ?? [];
    $tagsStr = strtoupper(implode(' ', $tags));
    
    $mapInfo = [
        'id' => $vm['id'],
        'name' => $name,
        'shortName' => $vm['shortName'] ?? null,
        'tags' => $tags,
        'source' => $vm['sourceFileName'] ?? null,
        'lastUpdated' => $vm['lastUpdatedAt'] ?? null
    ];
    
    // Categorize
    if (in_array('SECTOR', $tags) || 
        strpos($nameUpper, 'SECTOR') !== false ||
        preg_match('/^' . $facility . '\d{2}/', $nameUpper)) {
        $sectorMaps[] = $mapInfo;
    } elseif (strpos($nameUpper, 'BOUNDARY') !== false || 
              strpos($nameUpper, 'BDRY') !== false) {
        $boundaryMaps[] = $mapInfo;
    } elseif (strpos($nameUpper, 'HIGH') !== false || 
              strpos($nameUpper, 'LOW') !== false ||
              strpos($nameUpper, 'SPLIT') !== false ||
              strpos($tagsStr, 'ERAM') !== false) {
        $otherMaps[] = $mapInfo;
    }
}

// Get visibility center
$center = null;
if (!empty($artccData['visibilityCenters'])) {
    $vc = $artccData['visibilityCenters'][0];
    $center = [$vc['lon'], $vc['lat']];
}

echo json_encode([
    'success' => true,
    'facility' => $facility,
    'lastUpdated' => $artccData['lastUpdatedAt'] ?? null,
    'center' => $center,
    'maps' => [
        'sectors' => $sectorMaps,
        'boundaries' => $boundaryMaps,
        'related' => array_slice($otherMaps, 0, 50) // Limit to prevent huge response
    ],
    'totals' => [
        'sectors' => count($sectorMaps),
        'boundaries' => count($boundaryMaps),
        'related' => count($otherMaps),
        'all' => count($artccData['videoMaps'] ?? [])
    ],
    'source' => basename($usedPath)
]);
