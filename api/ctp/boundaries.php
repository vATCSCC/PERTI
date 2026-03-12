<?php
/**
 * CTP Boundaries API
 *
 * GET /api/ctp/boundaries.php
 *
 * Returns oceanic FIR boundary polygons as GeoJSON FeatureCollection
 * for MapLibre map rendering. Queries adl_boundary where is_oceanic = 1.
 *
 * Optional parameters:
 *   firs=CZQX,BIRD,EGGX   (filter to specific FIR codes)
 *
 * Response: GeoJSON FeatureCollection
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('CTP_API_INCLUDED', true);
require_once(__DIR__ . '/common.php');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond_json(405, ['status' => 'error', 'message' => 'Method not allowed. Use GET.']);
}

$conn_adl = ctp_get_conn_adl();

// Optional FIR filter
$fir_filter = isset($_GET['firs']) ? split_codes($_GET['firs']) : [];

$sql = "
    SELECT
        boundary_id,
        boundary_code,
        boundary_name,
        parent_artcc,
        parent_fir,
        icao_code,
        is_oceanic,
        floor_altitude,
        ceiling_altitude,
        label_lat,
        label_lon,
        boundary_geography.STAsText() AS geometry_wkt
    FROM dbo.adl_boundary
    WHERE is_active = 1
      AND is_oceanic = 1
";

$params = [];

if (!empty($fir_filter)) {
    $placeholders = implode(',', array_fill(0, count($fir_filter), '?'));
    $sql .= " AND boundary_code IN ({$placeholders})";
    $params = $fir_filter;
}

$sql .= " ORDER BY boundary_code";

$stmt = sqlsrv_query($conn_adl, $sql, $params);
if ($stmt === false) {
    respond_json(500, ['status' => 'error', 'message' => 'Failed to query boundaries.']);
}

$features = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $geometry = ctp_wkt_to_geojson($row['geometry_wkt']);
    if (!$geometry) continue;

    $features[] = [
        'type' => 'Feature',
        'id' => (int)$row['boundary_id'],
        'properties' => [
            'boundary_id' => (int)$row['boundary_id'],
            'boundary_code' => $row['boundary_code'],
            'boundary_name' => $row['boundary_name'],
            'parent_artcc' => $row['parent_artcc'],
            'parent_fir' => $row['parent_fir'],
            'icao_code' => $row['icao_code'],
            'is_oceanic' => (bool)$row['is_oceanic'],
            'floor_altitude' => $row['floor_altitude'] ? (int)$row['floor_altitude'] : null,
            'ceiling_altitude' => $row['ceiling_altitude'] ? (int)$row['ceiling_altitude'] : null,
            'label_lat' => $row['label_lat'] ? (float)$row['label_lat'] : null,
            'label_lon' => $row['label_lon'] ? (float)$row['label_lon'] : null
        ],
        'geometry' => $geometry
    ];
}
sqlsrv_free_stmt($stmt);

echo json_encode([
    'type' => 'FeatureCollection',
    'features' => $features
]);

// ============================================================================
// WKT to GeoJSON converters (same pattern as api/adl/boundaries.php)
// ============================================================================

function ctp_wkt_to_geojson($wkt) {
    if (!$wkt) return null;

    if (preg_match('/^POLYGON\s*\((.+)\)$/is', $wkt, $matches)) {
        return [
            'type' => 'Polygon',
            'coordinates' => ctp_parse_polygon_coords($matches[1])
        ];
    } elseif (preg_match('/^MULTIPOLYGON\s*\((.+)\)$/is', $wkt, $matches)) {
        return [
            'type' => 'MultiPolygon',
            'coordinates' => ctp_parse_multipolygon_coords($matches[1])
        ];
    }

    return null;
}

function ctp_parse_polygon_coords($str) {
    $rings = [];
    preg_match_all('/\(([^()]+)\)/', $str, $ringMatches);

    foreach ($ringMatches[1] as $ringStr) {
        $ring = [];
        $points = explode(',', $ringStr);
        foreach ($points as $point) {
            $coords = preg_split('/\s+/', trim($point));
            if (count($coords) >= 2) {
                $ring[] = [(float)$coords[0], (float)$coords[1]];
            }
        }
        $rings[] = $ring;
    }

    return $rings;
}

function ctp_parse_multipolygon_coords($str) {
    $polygons = [];
    preg_match_all('/\(\(([^)]+(?:\),[^)]+)*)\)\)/', $str, $polyMatches);

    foreach ($polyMatches[0] as $polyStr) {
        $polyStr = trim($polyStr, '()');
        $polygons[] = ctp_parse_polygon_coords($polyStr);
    }

    if (empty($polygons)) {
        $polygons[] = ctp_parse_polygon_coords($str);
    }

    return $polygons;
}
