<?php
/** RAD API: Route Metrics — POST /api/rad/route-metrics.php */
define('RAD_API_INCLUDED', true);
require_once __DIR__ . '/common.php';

$cid = rad_require_auth();
$body = rad_read_payload();

$routes = $body['routes'] ?? [];
if (!is_array($routes) || count($routes) === 0) {
    rad_respond_json(400, ['status' => 'error', 'message' => 'routes array required']);
}
if (count($routes) > 10) {
    rad_respond_json(400, ['status' => 'error', 'message' => 'Maximum 10 routes per request']);
}

$cruise_speed = (int)($body['cruise_speed_kts'] ?? 450);
if ($cruise_speed < 100) $cruise_speed = 450;

$conn_gis = get_conn_gis();
if (!$conn_gis) {
    rad_respond_json(500, ['status' => 'error', 'message' => 'PostGIS unavailable']);
}

$metrics = [];
foreach ($routes as $route_string) {
    $route_string = trim($route_string);
    if (empty($route_string)) {
        $metrics[] = ['route' => $route_string, 'distance_nm' => null, 'time_minutes' => null, 'geojson' => null];
        continue;
    }

    try {
        $sql = "SELECT
                    ST_Length(
                        ST_MakeLine(ARRAY(
                            SELECT geom FROM expand_route(:route) ORDER BY seq
                        ))::geography
                    ) / 1852.0 AS distance_nm,
                    ST_AsGeoJSON(
                        ST_MakeLine(ARRAY(
                            SELECT geom FROM expand_route(:route2) ORDER BY seq
                        ))
                    ) AS geojson";

        $stmt = $conn_gis->prepare($sql);
        $stmt->execute([':route' => $route_string, ':route2' => $route_string]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $distance = $row['distance_nm'] ? round((float)$row['distance_nm'], 1) : null;
        $time_min = $distance ? (int)round($distance / $cruise_speed * 60) : null;
        $geojson = $row['geojson'] ? json_decode($row['geojson'], true) : null;

        $metrics[] = [
            'route' => $route_string,
            'distance_nm' => $distance,
            'time_minutes' => $time_min,
            'geojson' => $geojson,
        ];
    } catch (\Exception $e) {
        $metrics[] = [
            'route' => $route_string,
            'distance_nm' => null,
            'time_minutes' => null,
            'geojson' => null,
            'error' => $e->getMessage(),
        ];
    }
}

rad_respond_json(200, [
    'status' => 'ok',
    'data' => ['metrics' => $metrics, 'baseline_index' => 0],
]);
