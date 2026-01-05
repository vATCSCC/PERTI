<?php
/**
 * SUA GeoJSON API Endpoint
 *
 * Returns SUAs with full geometry for map display.
 * Supports filtering and search.
 *
 * Parameters:
 * - search: Search by name or designator
 * - type: Filter by type (comma-separated)
 * - artcc: Filter by ARTCC
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: max-age=300'); // Cache for 5 minutes

$sua_file = __DIR__ . '/../sua_boundaries.json';

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

// Apply filters
$search = isset($_GET['search']) ? strtoupper(trim($_GET['search'])) : null;
$type_filter = isset($_GET['type']) ? explode(',', strtoupper($_GET['type'])) : null;
$artcc_filter = isset($_GET['artcc']) ? strtoupper($_GET['artcc']) : null;

if ($search || $type_filter || $artcc_filter) {
    $features = array_filter($features, function($f) use ($search, $type_filter, $artcc_filter) {
        $props = $f['properties'] ?? [];

        // Search filter
        if ($search) {
            $name = strtoupper($props['name'] ?? '');
            $designator = strtoupper($props['designator'] ?? '');
            if (strpos($name, $search) === false && strpos($designator, $search) === false) {
                return false;
            }
        }

        // Type filter
        if ($type_filter && !in_array($props['suaType'] ?? '', $type_filter)) {
            return false;
        }

        // ARTCC filter
        if ($artcc_filter && ($props['artcc'] ?? '') !== $artcc_filter) {
            return false;
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
