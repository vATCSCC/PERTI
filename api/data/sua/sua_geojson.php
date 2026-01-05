<?php
/**
 * SUA GeoJSON API Endpoint
 *
 * Returns SUAs with full geometry for map display.
 * Uses the ATCSCC SUA.geojson file with LineString geometries.
 * Supports filtering and search.
 *
 * Parameters:
 * - search: Search by name
 * - type: Filter by type/colorName (comma-separated)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: max-age=300'); // Cache for 5 minutes

// Use the ATCSCC SUA.geojson file
$sua_file = __DIR__ . '/../../assets/geojson/SUA.geojson';

if (!file_exists($sua_file)) {
    echo json_encode([
        'type' => 'FeatureCollection',
        'features' => []
    ]);
    exit;
}

$data = json_decode(file_get_contents($sua_file), true);

if (!$data || !isset($data['features'])) {
    echo json_encode([
        'type' => 'FeatureCollection',
        'features' => []
    ]);
    exit;
}

$features = $data['features'];

// Map colorName to standard suaType for compatibility
$typeMap = [
    'PROHIBITED' => 'P',
    'RESTRICTED' => 'R',
    'WARNING' => 'W',
    'ALERT' => 'A',
    'MOA' => 'MOA',
    'NSA' => 'NSA',
    'ATCAA' => 'ATCAA',
    'TFR' => 'TFR',
    'AR' => 'AR',
    'ALTRV' => 'ALTRV',
    'OPAREA' => 'OPAREA',
    'AW' => 'AW',
    'USN' => 'USN',
    'DZ' => 'DZ',
    'ADIZ' => 'ADIZ',
    'OSARA' => 'OSARA',
    'SUA' => 'OTHER'
];

// Normalize features - add suaType based on colorName
foreach ($features as &$feature) {
    $colorName = $feature['properties']['colorName'] ?? 'OTHER';
    $feature['properties']['suaType'] = $typeMap[$colorName] ?? $colorName;
    // Also set designator from name if not present
    if (!isset($feature['properties']['designator'])) {
        $feature['properties']['designator'] = $feature['properties']['name'] ?? '';
    }
}
unset($feature);

// Apply filters
$search = isset($_GET['search']) ? strtoupper(trim($_GET['search'])) : null;
$type_filter = isset($_GET['type']) ? explode(',', strtoupper($_GET['type'])) : null;

if ($search || $type_filter) {
    $features = array_filter($features, function($f) use ($search, $type_filter, $typeMap) {
        $props = $f['properties'] ?? [];

        // Search filter
        if ($search) {
            $name = strtoupper($props['name'] ?? '');
            if (strpos($name, $search) === false) {
                return false;
            }
        }

        // Type filter - check both suaType and colorName
        if ($type_filter) {
            $suaType = $props['suaType'] ?? '';
            $colorName = strtoupper($props['colorName'] ?? '');

            $matched = false;
            foreach ($type_filter as $filterType) {
                if ($suaType === $filterType || $colorName === $filterType) {
                    $matched = true;
                    break;
                }
                // Also check if filter matches the mapped type
                if (isset($typeMap[$colorName]) && $typeMap[$colorName] === $filterType) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }

        return true;
    });

    $features = array_values($features);
}

// Return as GeoJSON FeatureCollection with geometry
echo json_encode([
    'type' => 'FeatureCollection',
    'features' => $features
]);
