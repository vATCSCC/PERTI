<?php
/**
 * SUA GeoJSON API Endpoint
 *
 * Returns SUAs with full geometry for map display.
 * Uses the transformed SUA GeoJSON file with proper Polygon/LineString geometries.
 * Supports filtering by search, type, group, and ARTCC.
 *
 * Parameters:
 * - search: Search by name or sua_id
 * - type: Filter by sua_type (comma-separated)
 * - group: Filter by sua_group (comma-separated)
 * - artcc: Filter by ARTCC
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: max-age=300'); // Cache for 5 minutes

// Use the transformed SUA GeoJSON file (falls back to original if not available)
$sua_file = __DIR__ . '/../../../assets/geojson/SUA_transformed.geojson';
if (!file_exists($sua_file)) {
    $sua_file = __DIR__ . '/../../../assets/geojson/SUA.geojson';
}

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
$type_filter = isset($_GET['type']) ? array_map('strtoupper', explode(',', $_GET['type'])) : null;
$group_filter = isset($_GET['group']) ? array_map('strtoupper', explode(',', $_GET['group'])) : null;
$artcc_filter = isset($_GET['artcc']) ? strtoupper(trim($_GET['artcc'])) : null;

if ($search || $type_filter || $group_filter || $artcc_filter) {
    $features = array_filter($features, function($f) use ($search, $type_filter, $group_filter, $artcc_filter) {
        $props = $f['properties'] ?? [];

        // Search filter - check name, sua_id, designator
        if ($search) {
            $name = strtoupper($props['name'] ?? '');
            $sua_id = strtoupper($props['sua_id'] ?? '');
            $designator = strtoupper($props['designator'] ?? '');
            $colorName = strtoupper($props['colorName'] ?? '');

            if (strpos($name, $search) === false &&
                strpos($sua_id, $search) === false &&
                strpos($designator, $search) === false &&
                strpos($colorName, $search) === false) {
                return false;
            }
        }

        // Type filter - check sua_type or colorName
        if ($type_filter) {
            $sua_type = strtoupper($props['sua_type'] ?? '');
            $colorName = strtoupper($props['colorName'] ?? '');

            $matched = false;
            foreach ($type_filter as $filterType) {
                if ($sua_type === $filterType || $colorName === $filterType) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }

        // Group filter - check sua_group
        if ($group_filter) {
            $sua_group = strtoupper($props['sua_group'] ?? 'OTHER');

            if (!in_array($sua_group, $group_filter)) {
                return false;
            }
        }

        // ARTCC filter
        if ($artcc_filter) {
            $artcc = strtoupper($props['artcc'] ?? '');
            if ($artcc !== $artcc_filter) {
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
