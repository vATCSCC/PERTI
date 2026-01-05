<?php
/**
 * SUA List API Endpoint
 *
 * Returns all SUAs from the transformed GeoJSON for the SUA browser table.
 * Supports filtering by search, type, group, and ARTCC.
 *
 * Parameters:
 * - search: Search by name, sua_id, or designator
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
        'status' => 'error',
        'message' => 'SUA GeoJSON file not found',
        'data' => []
    ]);
    exit;
}

$data = json_decode(file_get_contents($sua_file), true);

if (!$data || !isset($data['features'])) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid SUA data format',
        'data' => []
    ]);
    exit;
}

$features = $data['features'];

// Apply filters
$search = isset($_GET['search']) ? strtoupper(trim($_GET['search'])) : null;
$type_filter = isset($_GET['type']) ? array_map('strtoupper', explode(',', $_GET['type'])) : null;
$group_filter = isset($_GET['group']) ? array_map('strtoupper', explode(',', $_GET['group'])) : null;
$artcc_filter = isset($_GET['artcc']) ? strtoupper($_GET['artcc']) : null;

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

// Extract properties for the table (no geometry needed)
// Map new schema fields to legacy field names for backwards compatibility
$result = array_map(function($f) {
    $props = $f['properties'] ?? [];

    return [
        'sua_id' => $props['sua_id'] ?? null,
        'sua_group' => $props['sua_group'] ?? 'OTHER',
        'sua_type' => $props['sua_type'] ?? $props['colorName'] ?? null,
        'suaType' => $props['sua_type'] ?? $props['colorName'] ?? null, // Legacy field
        'name' => $props['name'] ?? null,
        'designator' => $props['designator'] ?? $props['sua_id'] ?? null,
        'area_name' => $props['area_name'] ?? null,
        'artcc' => $props['artcc'] ?? null,
        'floor_alt' => $props['floor_alt'] ?? null,
        'ceiling_alt' => $props['ceiling_alt'] ?? null,
        'lowerLimit' => $props['floor_alt'] ?? $props['lowerLimit'] ?? null, // Legacy field
        'upperLimit' => $props['ceiling_alt'] ?? $props['upperLimit'] ?? null, // Legacy field
        'geometry_type' => $props['geometry_type'] ?? null,
        'schedule' => $props['schedule'] ?? null,
        'scheduleDesc' => $props['scheduleDesc'] ?? null,
        'colorName' => $props['colorName'] ?? null
    ];
}, $features);

// Get unique types for filter dropdown
$types = array_unique(array_filter(array_map(function($f) {
    return $f['properties']['sua_type'] ?? $f['properties']['colorName'] ?? null;
}, $data['features'])));
sort($types);

echo json_encode([
    'status' => 'ok',
    'count' => count($result),
    'types' => array_values($types),
    'data' => $result
]);
