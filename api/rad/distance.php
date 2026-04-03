<?php
/** RAD API: Route Distance — POST /api/rad/distance.php
 *
 * Computes geodesic route distance in nautical miles via PostGIS expand_routes_batch().
 * Accepts an array of full route strings (with origin/dest bookends).
 *
 * POST body: { "routes": ["KJFK DCT MERIT J6 BRIGS KLAX", ...] }
 * Response:  { "status": "ok", "data": { "KJFK DCT MERIT...": 2105.3, ... } }
 */
define('RAD_API_INCLUDED', true);
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rad_respond_json(405, ['status' => 'error', 'message' => 'POST only']);
}

rad_require_auth();

$body = rad_read_payload();
$routes = $body['routes'] ?? [];

if (!is_array($routes) || count($routes) === 0) {
    rad_respond_json(400, ['status' => 'error', 'message' => 'routes array required']);
}

if (count($routes) > 40) {
    rad_respond_json(400, ['status' => 'error', 'message' => 'Maximum 40 routes per request']);
}

$conn_gis = get_conn_gis();
if (!$conn_gis) {
    rad_respond_json(503, ['status' => 'error', 'message' => 'GIS service unavailable']);
}

// Deduplicate and trim
$unique = array_values(array_unique(array_map('trim', array_filter($routes))));

// Build Postgres text array
$pg_array = '{' . implode(',', array_map(function ($s) {
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
}, $unique)) . '}';

try {
    $sql = "SELECT route_index, route_input,
                   ST_Length(route_geometry::geography) / 1852.0 AS distance_nm,
                   error_message
            FROM expand_routes_batch(:routes)";
    $stmt = $conn_gis->prepare($sql);
    $stmt->execute([':routes' => $pg_array]);

    $distances = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $idx = (int)$row['route_index'] - 1;
        if ($idx >= 0 && $idx < count($unique)) {
            $key = $unique[$idx];
            if (empty($row['error_message']) && $row['distance_nm'] !== null) {
                $distances[$key] = round((float)$row['distance_nm'], 1);
            }
        }
    }

    rad_respond_json(200, ['status' => 'ok', 'data' => $distances]);
} catch (PDOException $e) {
    error_log('RAD distance error: ' . $e->getMessage());
    rad_respond_json(500, ['status' => 'error', 'message' => 'Distance calculation failed']);
}
