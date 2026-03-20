<?php
/**
 * Playbook Route Analysis API
 *
 * Computes ordered facility traversal, distances, and time segments
 * for a playbook route string using PostGIS spatial analysis.
 *
 * GET  /api/data/playbook/analysis.php?route_id=123
 * GET  /api/data/playbook/analysis.php?route_string=...&origin=KJFK&dest=EGLL
 * POST /api/data/playbook/analysis.php  (JSON body with route_waypoints)
 *
 * POST body (route_waypoints mode — uses client-resolved geometry directly):
 *   { "route_waypoints": [{fix,lat,lon},...], "route_string": "...", ... }
 *
 * Optional params (GET query or POST body):
 *   climb_kts=280        - Climb speed (kts TAS)
 *   cruise_kts=460       - Cruise speed (kts TAS)
 *   descent_kts=250      - Descent speed (kts TAS)
 *   wind_component_kts=0 - Wind component (positive=headwind, negative=tailwind)
 *   facility_types=ARTCC,FIR,TRACON
 *
 * @version 2.0.0
 */

include("../../../load/config.php");
define('PERTI_MYSQL_ONLY', true);
include("../../../load/connect.php");

require_once __DIR__ . '/../../../lib/ArtccNormalizer.php';
use PERTI\Lib\ArtccNormalizer;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'])) {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// Parse parameters from GET or POST JSON body
$route_waypoints = null; // Client-resolved waypoints [{fix, lat, lon}, ...]

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON body']);
        exit;
    }
    $route_waypoints = $body['route_waypoints'] ?? null;
    $route_id     = isset($body['route_id']) ? (int)$body['route_id'] : null;
    $route_string = $body['route_string'] ?? null;
    $origin       = strtoupper(trim($body['origin'] ?? ''));
    $dest         = strtoupper(trim($body['dest'] ?? ''));
    $climb_kts    = isset($body['climb_kts']) ? (float)$body['climb_kts'] : 280.0;
    $cruise_kts   = isset($body['cruise_kts']) ? (float)$body['cruise_kts'] : 460.0;
    $descent_kts  = isset($body['descent_kts']) ? (float)$body['descent_kts'] : 250.0;
    $wind_kts     = isset($body['wind_component_kts']) ? (float)$body['wind_component_kts'] : 0.0;
    $facility_types_str = $body['facility_types'] ?? 'ARTCC,FIR,TRACON,SECTOR_HIGH,SECTOR_LOW,SECTOR_SUPERHIGH';
} else {
    $route_id     = isset($_GET['route_id']) ? (int)$_GET['route_id'] : null;
    $route_string = $_GET['route_string'] ?? null;
    $origin       = strtoupper(trim($_GET['origin'] ?? ''));
    $dest         = strtoupper(trim($_GET['dest'] ?? ''));
    $climb_kts    = isset($_GET['climb_kts']) ? (float)$_GET['climb_kts'] : 280.0;
    $cruise_kts   = isset($_GET['cruise_kts']) ? (float)$_GET['cruise_kts'] : 460.0;
    $descent_kts  = isset($_GET['descent_kts']) ? (float)$_GET['descent_kts'] : 250.0;
    $wind_kts     = isset($_GET['wind_component_kts']) ? (float)$_GET['wind_component_kts'] : 0.0;
    $facility_types_str = $_GET['facility_types'] ?? 'ARTCC,FIR,TRACON,SECTOR_HIGH,SECTOR_LOW,SECTOR_SUPERHIGH';
}

$facility_types = array_map('trim', array_map('strtoupper', explode(',', $facility_types_str)));

// If route_waypoints provided, skip route_id/route_string resolution entirely
if (!empty($route_waypoints) && is_array($route_waypoints) && count($route_waypoints) >= 2) {
    // Validate waypoints structure
    foreach ($route_waypoints as &$wp) {
        if (!isset($wp['lat']) || !isset($wp['lon'])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Each waypoint must have lat and lon']);
            exit;
        }
        $wp['lat'] = (float)$wp['lat'];
        $wp['lon'] = (float)$wp['lon'];
        $wp['fix'] = $wp['fix'] ?? 'UNK';
    }
    unset($wp);

    if (empty($route_string)) {
        $route_string = implode(' ', array_column($route_waypoints, 'fix'));
    }
} else {
    $route_waypoints = null;

    // If route_id provided, look up the route from MySQL
    if ($route_id !== null) {
        global $conn_sqli;
        if (!$conn_sqli) {
            http_response_code(503);
            echo json_encode(['status' => 'error', 'message' => 'MySQL connection unavailable']);
            exit;
        }

        $stmt = $conn_sqli->prepare(
            "SELECT r.route_string, r.origin, r.dest, p.play_name
             FROM playbook_routes r
             JOIN playbook_plays p ON r.play_id = p.play_id
             WHERE r.route_id = ?"
        );
        $stmt->bind_param('i', $route_id);
        $stmt->execute();
        $route = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$route) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Route not found']);
            exit;
        }

        $route_string = $route['route_string'];
        if (empty($origin)) $origin = strtoupper(trim($route['origin'] ?? ''));
        if (empty($dest))   $dest   = strtoupper(trim($route['dest'] ?? ''));
    }

    if (empty($route_string)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Either route_waypoints, route_id, or route_string is required']);
        exit;
    }
}

// Cache key based on spatial query params (speed/wind are just math, not cached)
$cache_source = $route_waypoints
    ? json_encode(array_map(function($w) { return $w['lat'].','.$w['lon']; }, $route_waypoints))
    : ($route_string . '|' . $origin . '|' . $dest);
$cache_key = md5($cache_source . '|' . $facility_types_str);
$cache_dir = sys_get_temp_dir() . '/route_analysis_cache';
$cache_file = $cache_dir . '/' . $cache_key . '.json';
$cache_ttl = 86400; // 24 hours
$cached = false;

if (is_file($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
    $cache_data = json_decode(file_get_contents($cache_file), true);
    if ($cache_data && isset($cache_data['route_wkt'])) {
        $route_wkt      = $cache_data['route_wkt'];
        $total_dist_nm  = $cache_data['total_dist_nm'];
        $waypoints_raw  = $cache_data['waypoints_raw'];
        $traversal_raw  = $cache_data['traversal_raw'];
        $cached = true;
    }
}

if (!$cached) {
    // Get PostGIS connection
    $conn_gis = get_conn_gis();
    if (!$conn_gis) {
        http_response_code(503);
        echo json_encode(['status' => 'error', 'message' => 'GIS connection unavailable']);
        exit;
    }

    try {

    if ($route_waypoints) {
        // =====================================================================
        // MODE 1: Client-resolved waypoints — build LINESTRING from coordinates
        // This uses the exact same geometry the map displays, handling oceanic
        // coordinate waypoints, SID/STAR expansion, and fix disambiguation
        // identically to the client-side route renderer.
        // =====================================================================

        // Build WKT LINESTRING from waypoint coordinates
        $wkt_points = [];
        foreach ($route_waypoints as $wp) {
            $wkt_points[] = sprintf('%.6f %.6f', $wp['lon'], $wp['lat']);
        }
        $route_wkt_raw = 'LINESTRING(' . implode(',', $wkt_points) . ')';

        // Query PostGIS for total distance and per-waypoint fractions
        $params_json = json_encode(array_map(function($wp) {
            return ['lon' => $wp['lon'], 'lat' => $wp['lat'], 'fix' => $wp['fix']];
        }, $route_waypoints));

        $dist_sql = "WITH route AS (
                         SELECT ST_GeomFromText(:wkt, 4326) AS geom
                     ),
                     wp AS (
                         SELECT
                             (elem->>'fix')::text AS fix_name,
                             (elem->>'lat')::float AS lat,
                             (elem->>'lon')::float AS lon,
                             ordinality AS seq
                         FROM jsonb_array_elements(:wps::jsonb) WITH ORDINALITY AS t(elem, ordinality)
                     )
                     SELECT
                         ST_AsText(r.geom) AS route_wkt,
                         ST_Length(r.geom::geography) / 1852.0 AS total_dist_nm,
                         jsonb_agg(
                             jsonb_build_object(
                                 'fix_name', wp.fix_name,
                                 'lat', wp.lat,
                                 'lon', wp.lon,
                                 'fraction', ST_LineLocatePoint(r.geom, ST_SetSRID(ST_MakePoint(wp.lon, wp.lat), 4326))
                             ) ORDER BY wp.seq
                         ) AS waypoints_json
                     FROM route r, wp
                     GROUP BY r.geom";

        $dist_stmt = $conn_gis->prepare($dist_sql);
        $dist_stmt->execute([':wkt' => $route_wkt_raw, ':wps' => $params_json]);
        $dist_row = $dist_stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$dist_row || empty($dist_row['route_wkt'])) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'Could not build route geometry from waypoints']);
            exit;
        }

        $route_wkt     = $dist_row['route_wkt'];
        $total_dist_nm = round((float)$dist_row['total_dist_nm'], 1);
        $waypoints_raw = json_decode($dist_row['waypoints_json'], true) ?: [];

    } else {
        // =====================================================================
        // MODE 2: Server-side route resolution via PostGIS expand_route()
        // Fallback when client geometry is not available (e.g. picker mode)
        // =====================================================================

        // Build full route string with origin/dest bookends for expand_route()
        $full_route = $route_string;
        if ($origin) $full_route = $origin . ' ' . $full_route;
        if ($dest)   $full_route = $full_route . ' ' . $dest;

        $er_sql = "WITH expanded AS (
                       SELECT waypoint_seq, waypoint_id, lat, lon, waypoint_type
                       FROM expand_route(:route)
                   ),
                   route_line AS (
                       SELECT ST_MakeLine(
                           ARRAY(
                               SELECT ST_SetSRID(ST_MakePoint(e.lon::float, e.lat::float), 4326)
                               FROM expanded e ORDER BY e.waypoint_seq
                           )
                       ) AS geom
                   )
                   SELECT
                       ST_AsText(rl.geom) AS route_wkt,
                       ST_Length(rl.geom::geography) / 1852.0 AS total_dist_nm,
                       jsonb_agg(
                           jsonb_build_object(
                               'fix_name', e.waypoint_id,
                               'lat', e.lat,
                               'lon', e.lon,
                               'fraction', ST_LineLocatePoint(rl.geom, ST_SetSRID(ST_MakePoint(e.lon::float, e.lat::float), 4326))
                           ) ORDER BY e.waypoint_seq
                       ) AS waypoints_json
                   FROM expanded e, route_line rl
                   GROUP BY rl.geom";

        $er_stmt = $conn_gis->prepare($er_sql);
        $er_stmt->execute([':route' => $full_route]);
        $er_row = $er_stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$er_row || empty($er_row['route_wkt'])) {
            http_response_code(422);
            echo json_encode([
                'status'  => 'error',
                'message' => 'Could not resolve route string to geometry. Ensure fixes are valid.'
            ]);
            exit;
        }

        $route_wkt     = $er_row['route_wkt'];
        $total_dist_nm = round((float)$er_row['total_dist_nm'], 1);
        $waypoints_raw = json_decode($er_row['waypoints_json'], true) ?: [];
    }

    // Facility traversal analysis — same for both modes
    $ft_types_pg = '{' . implode(',', $facility_types) . '}';
    $ft_sql = "SELECT * FROM analyze_route_traversal(
                   ST_GeomFromText(:wkt, 4326),
                   :types::text[]
               )";
    $ft_stmt = $conn_gis->prepare($ft_sql);
    $ft_stmt->execute([':wkt' => $route_wkt, ':types' => $ft_types_pg]);
    $traversal_raw = $ft_stmt->fetchAll(\PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Route analysis GIS error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'GIS query failed: ' . $e->getMessage()]);
        exit;
    }

    // Write cache
    if (!is_dir($cache_dir)) {
        @mkdir($cache_dir, 0755, true);
    }
    @file_put_contents($cache_file, json_encode([
        'route_wkt'     => $route_wkt,
        'total_dist_nm' => $total_dist_nm,
        'waypoints_raw' => $waypoints_raw,
        'traversal_raw' => $traversal_raw,
    ]));
}

// Step 4: Apply speed model to compute times
// Simple 3-phase model: climb (first 50nm), cruise (middle), descent (last 40nm)
$climb_dist = min(50.0, $total_dist_nm * 0.15);
$descent_dist = min(40.0, $total_dist_nm * 0.10);
$cruise_dist = max(0, $total_dist_nm - $climb_dist - $descent_dist);

$eff_climb   = max(100, $climb_kts - $wind_kts);
$eff_cruise  = max(100, $cruise_kts - $wind_kts);
$eff_descent = max(100, $descent_kts - $wind_kts);

$climb_time   = ($climb_dist / $eff_climb) * 60;
$cruise_time  = ($cruise_dist / $eff_cruise) * 60;
$descent_time = ($descent_dist / $eff_descent) * 60;
$total_time   = round($climb_time + $cruise_time + $descent_time, 1);

// Helper to compute time at a given distance from origin
$getTimeAtDist = function($dist_nm) use ($climb_dist, $descent_dist, $total_dist_nm, $eff_climb, $eff_cruise, $eff_descent) {
    if ($dist_nm <= $climb_dist) {
        return ($dist_nm / $eff_climb) * 60;
    }
    $climb_time = ($climb_dist / $eff_climb) * 60;
    $descent_start = $total_dist_nm - $descent_dist;

    if ($dist_nm <= $descent_start) {
        $cruise_seg = $dist_nm - $climb_dist;
        return $climb_time + ($cruise_seg / $eff_cruise) * 60;
    }

    $cruise_full = ($descent_start - $climb_dist) / $eff_cruise * 60;
    $desc_seg = $dist_nm - $descent_start;
    return $climb_time + $cruise_full + ($desc_seg / $eff_descent) * 60;
};

// Build waypoint response with cumulative distance/time
$waypoints = [];
foreach ($waypoints_raw as $wp) {
    $cum_dist = round((float)$wp['fraction'] * $total_dist_nm, 1);
    $cum_time = round($getTimeAtDist($cum_dist), 1);
    $waypoints[] = [
        'fix'         => $wp['fix_name'],
        'lat'         => round((float)$wp['lat'], 6),
        'lon'         => round((float)$wp['lon'], 6),
        'cum_dist_nm' => $cum_dist,
        'cum_time_min'=> $cum_time,
    ];
}

// Helper to find nearest waypoint to a given fraction along the route
$findNearestFix = function($fraction) use ($waypoints_raw, $total_dist_nm) {
    if (empty($waypoints_raw)) return null;
    $target_dist = $fraction * $total_dist_nm;
    $best = null;
    $best_delta = PHP_FLOAT_MAX;
    foreach ($waypoints_raw as $wp) {
        $wp_dist = (float)$wp['fraction'] * $total_dist_nm;
        $delta = abs($wp_dist - $target_dist);
        if ($delta < $best_delta && $delta < 20.0) {
            $best = $wp['fix_name'];
            $best_delta = $delta;
        }
    }
    return $best;
};

// Build facility traversal response with times
$traversal = [];
foreach ($traversal_raw as $t) {
    $entry_dist = round((float)$t['entry_fraction'] * $total_dist_nm, 1);
    $exit_dist  = round((float)$t['exit_fraction'] * $total_dist_nm, 1);
    $entry_time = round($getTimeAtDist($entry_dist), 1);
    $exit_time  = round($getTimeAtDist($exit_dist), 1);

    $traversal[] = [
        'type'             => $t['facility_type'],
        'id'               => (in_array($t['facility_type'], ['ARTCC', 'FIR']))
                                    ? ArtccNormalizer::normalize($t['facility_id'])
                                    : $t['facility_id'],
        'name'             => $t['facility_name'],
        'entry_fix'        => $findNearestFix((float)$t['entry_fraction']),
        'exit_fix'         => $findNearestFix((float)$t['exit_fraction']),
        'entry_dist_nm'    => $entry_dist,
        'exit_dist_nm'     => $exit_dist,
        'distance_within_nm' => round((float)$t['distance_nm'], 1),
        'time_within_min'  => round($exit_time - $entry_time, 1),
        'entry_time_min'   => $entry_time,
        'exit_time_min'    => $exit_time,
        'entry_lat'        => (float)$t['entry_lat'],
        'entry_lon'        => (float)$t['entry_lon'],
        'exit_lat'         => (float)$t['exit_lat'],
        'exit_lon'         => (float)$t['exit_lon'],
        'order'            => (int)$t['traversal_order'],
        'floor_altitude'   => isset($t['floor_altitude']) ? (int)$t['floor_altitude'] : null,
        'ceiling_altitude' => isset($t['ceiling_altitude']) ? (int)$t['ceiling_altitude'] : null,
    ];
}

// Build fix analysis (distance/time from origin AND to destination)
$fix_analysis = [];
foreach ($waypoints as $wp) {
    $fix_analysis[] = [
        'fix'                 => $wp['fix'],
        'lat'                 => $wp['lat'],
        'lon'                 => $wp['lon'],
        'dist_from_origin_nm' => $wp['cum_dist_nm'],
        'dist_to_dest_nm'     => round($total_dist_nm - $wp['cum_dist_nm'], 1),
        'time_from_origin_min'=> $wp['cum_time_min'],
        'time_to_dest_min'    => round($total_time - $wp['cum_time_min'], 1),
        'facility'            => findFacilityForDist($wp['cum_dist_nm'], $traversal),
    ];
}

// Build expanded route string from resolved waypoints
$expanded_route_string = implode(' ', array_column($waypoints_raw, 'fix_name'));

// Output
echo json_encode([
    'status'       => 'success',
    'route_id'     => $route_id,
    'route_string' => $route_string,
    'expanded_route_string' => $expanded_route_string,
    'origin'       => $origin ?: null,
    'dest'         => $dest ?: null,
    'total_distance_nm' => $total_dist_nm,
    'total_time_min'    => $total_time,
    'speed_profile' => [
        'climb_kts'   => $climb_kts,
        'cruise_kts'  => $cruise_kts,
        'descent_kts' => $descent_kts,
    ],
    'wind_profile' => [
        'component_kts' => $wind_kts,
    ],
    'cached'             => $cached,
    'waypoints'          => $waypoints,
    'facility_traversal' => $traversal,
    'fix_analysis'       => $fix_analysis,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// ============================================================================
// Helpers
// ============================================================================

/**
 * Find which facility a distance falls within.
 */
function findFacilityForDist($dist_nm, $traversal) {
    foreach ($traversal as $t) {
        if ($dist_nm >= $t['entry_dist_nm'] && $dist_nm <= $t['exit_dist_nm']) {
            return $t['id'];
        }
    }
    return null;
}
