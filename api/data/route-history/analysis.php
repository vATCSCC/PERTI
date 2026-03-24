<?php
/**
 * Historical Routes - Route Geometry Analysis
 *
 * Resolves a route string to waypoint coordinates via PostGIS expand_route().
 * Used by the routes map module for route polyline visualization.
 *
 * GET /api/data/route-history/analysis.php?route_string=RBV+Q430+AIR&origin=KJFK&dest=KLAX
 */

include("../../../load/config.php");
define('PERTI_MYSQL_ONLY', true);
include("../../../load/connect.php");

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$route_string = trim($_GET['route_string'] ?? '');
$origin = strtoupper(trim($_GET['origin'] ?? ''));
$dest = strtoupper(trim($_GET['dest'] ?? ''));

if (empty($route_string)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'route_string is required']);
    exit;
}

// File-based cache (24h TTL)
$cache_key = md5($route_string . '|' . $origin . '|' . $dest);
$cache_dir = sys_get_temp_dir() . '/route_history_analysis_cache';
$cache_file = $cache_dir . '/' . $cache_key . '.json';
$cache_ttl = 86400;

if (is_file($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
    $cached = json_decode(file_get_contents($cache_file), true);
    if ($cached) {
        echo json_encode($cached);
        exit;
    }
}

// Get PostGIS connection (lazy-loaded, not blocked by PERTI_MYSQL_ONLY)
$conn_gis = get_conn_gis();
if (!$conn_gis) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'GIS connection unavailable']);
    exit;
}

try {
    // Build full route with origin/dest bookends
    $full_route = $route_string;
    if ($origin) {
        $first_token = strtoupper(explode(' ', trim($full_route))[0] ?? '');
        if ($first_token !== $origin) {
            $full_route = $origin . ' ' . $full_route;
        }
    }
    if ($dest) {
        $last_token = strtoupper(trim(substr($full_route, strrpos($full_route, ' ') + 1)));
        if ($last_token !== $dest) {
            $full_route = $full_route . ' ' . $dest;
        }
    }

    // Call PostGIS expand_route()
    $sql = "WITH expanded AS (
                SELECT waypoint_seq, waypoint_id, lat, lon, waypoint_type
                FROM expand_route(:route)
            ),
            route_line AS (
                SELECT ST_MakeLine(
                    ST_SetSRID(ST_MakePoint(lon, lat), 4326) ORDER BY waypoint_seq
                ) AS geom
                FROM expanded
            )
            SELECT
                ST_AsText(r.geom) AS route_wkt,
                ST_Length(r.geom::geography) / 1852.0 AS total_dist_nm,
                jsonb_agg(
                    jsonb_build_object(
                        'fix_name', e.waypoint_id,
                        'lat', e.lat,
                        'lon', e.lon,
                        'seq', e.waypoint_seq
                    ) ORDER BY e.waypoint_seq
                ) AS waypoints_json
            FROM route_line r, expanded e
            GROUP BY r.geom";

    $stmt = $conn_gis->prepare($sql);
    $stmt->execute([':route' => $full_route]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['route_wkt'])) {
        echo json_encode(['status' => 'error', 'message' => 'Could not resolve route geometry']);
        exit;
    }

    $waypoints = json_decode($row['waypoints_json'], true) ?: [];

    // Convert lat/lon to floats
    foreach ($waypoints as &$wp) {
        $wp['lat'] = (float)$wp['lat'];
        $wp['lon'] = (float)$wp['lon'];
        $wp['seq'] = (int)$wp['seq'];
    }
    unset($wp);

    $result = [
        'status' => 'ok',
        'route_string' => $route_string,
        'origin' => $origin,
        'dest' => $dest,
        'waypoints' => $waypoints,
        'total_dist_nm' => round((float)$row['total_dist_nm'], 1),
        'route_wkt' => $row['route_wkt']
    ];

    // Save to cache
    if (!is_dir($cache_dir)) @mkdir($cache_dir, 0755, true);
    @file_put_contents($cache_file, json_encode($result));

    echo json_encode($result);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'PostGIS error: ' . $e->getMessage()]);
}
