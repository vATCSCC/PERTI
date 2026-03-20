<?php
/**
 * Recompute traversed facilities (ARTCCs, TRACONs, sectors) for all playbook routes.
 * Uses PostGIS expand_route_with_artccs() — the same route expansion pipeline as
 * route.php and the ADL parse queue — to properly resolve airways, DPs/STARs,
 * airports, and FBD tokens into a LINESTRING, then intersects with boundaries.
 *
 * Usage: php scripts/playbook/recompute_traversed.php [--dry-run] [--play-id=123]
 */

// Parse CLI args
$dryRun = in_array('--dry-run', $argv ?? []);
$playFilter = null;
foreach ($argv ?? [] as $arg) {
    if (strpos($arg, '--play-id=') === 0) {
        $playFilter = (int)substr($arg, 10);
    }
}

include_once(__DIR__ . '/../../load/config.php');
include_once(__DIR__ . '/../../load/input.php');
include_once(__DIR__ . '/../../load/connect.php');
require_once __DIR__ . '/../../lib/ArtccNormalizer.php';
use PERTI\Lib\ArtccNormalizer;

// Get GIS connection
$conn_gis = get_conn_gis();
if (!$conn_gis) {
    echo "ERROR: Could not connect to PostGIS (VATSIM_GIS).\n";
    exit(1);
}

echo "PostGIS connection OK.\n";

// Fetch routes with origin/dest fields for LINESTRING bookending
$where = $playFilter ? "WHERE play_id = $playFilter" : "";
$result = $conn_sqli->query("SELECT route_id, play_id, route_string,
    origin, dest, origin_airports, dest_airports,
    origin_artccs, dest_artccs
    FROM playbook_routes $where ORDER BY play_id, sort_order");

$total = $result->num_rows;
echo "Processing $total routes" . ($dryRun ? " (DRY RUN)" : "") . "...\n";

$update_stmt = $conn_sqli->prepare("UPDATE playbook_routes SET
    traversed_artccs = ?, traversed_tracons = ?,
    traversed_sectors_low = ?, traversed_sectors_high = ?, traversed_sectors_superhigh = ?,
    route_geometry = ?
    WHERE route_id = ?");

// Single PostGIS query: traversal boundaries + geometry + waypoints + distance
$gis_sql = "WITH route AS (
                SELECT waypoints, artccs_traversed, route_geometry AS geom,
                       ST_AsGeoJSON(route_geometry) AS geojson,
                       ST_Length(route_geometry::geography) / 1852.0 AS distance_nm
                FROM expand_route_with_artccs(?)
            )
            SELECT
                route.geojson,
                route.distance_nm,
                route.waypoints::text AS waypoints_json,
                sub.btype, sub.code
            FROM route
            LEFT JOIN LATERAL (
                SELECT sub2.btype, sub2.code, sub2.trav_order FROM (
                    SELECT 'artcc' AS btype, u.code, u.ord AS trav_order
                    FROM unnest(route.artccs_traversed) WITH ORDINALITY AS u(code, ord)
                    WHERE route.geom IS NOT NULL
                    UNION ALL
                    SELECT 'tracon', t.tracon_code,
                        ST_LineLocatePoint(route.geom, ST_Centroid(ST_Intersection(route.geom, t.geom)))
                    FROM (SELECT tracon_code, geom FROM tracon_boundaries WHERE ST_IsValid(geom)) t WHERE ST_Intersects(route.geom, t.geom)
                        AND route.geom IS NOT NULL
                    UNION ALL
                    SELECT CONCAT('sector_', LOWER(s.sector_type)), s.sector_code,
                        ST_LineLocatePoint(route.geom, ST_Centroid(ST_Intersection(route.geom, s.geom)))
                    FROM (SELECT sector_code, sector_type, geom FROM sector_boundaries WHERE ST_IsValid(geom)) s WHERE ST_Intersects(route.geom, s.geom)
                        AND route.geom IS NOT NULL
                ) sub2
                ORDER BY
                    CASE WHEN sub2.btype = 'artcc' THEN 1
                         WHEN sub2.btype = 'tracon' THEN 2
                         ELSE 3 END,
                    sub2.trav_order
            ) sub ON true";
$gis_stmt = $conn_gis->prepare($gis_sql);

$processed = 0;
$updated = 0;
$errors = 0;

function normalizeRouteCanadian($rs) {
    static $codes = ['CZE','CZU','CZV','CZW','CZY','CZM','CZQ','CZO'];
    $parts = preg_split('/\s+/', trim($rs));
    $changed = false;
    foreach ($parts as &$p) {
        if (in_array(strtoupper($p), $codes)) {
            $old = $p;
            $p = ArtccNormalizer::normalize($p);
            if ($p !== $old) $changed = true;
        }
    }
    return $changed ? implode(' ', $parts) : $rs;
}

/**
 * Extract a route endpoint from origin/dest fields.
 * Airports > label > ARTCCs. PostGIS resolve_waypoint() handles all types.
 */
function extractEndpoint($label, $airportsCsv, $artccsCsv) {
    if ($airportsCsv !== '') {
        $first = strtoupper(trim(explode(',', $airportsCsv)[0]));
        if ($first !== '' && preg_match('/^[A-Z]{3,4}$/', $first)) return $first;
    }
    $label = strtoupper(trim($label));
    if ($label !== '' && preg_match('/^[A-Z][A-Z0-9]{1,4}$/', $label)) return $label;
    if ($artccsCsv !== '') {
        $first = strtoupper(trim(explode(',', $artccsCsv)[0]));
        if ($first !== '' && $first !== 'UNKN' && preg_match('/^[A-Z]{2,4}$/', $first)) return $first;
    }
    return '';
}

while ($row = $result->fetch_assoc()) {
    $processed++;
    $route_id = (int)$row['route_id'];
    $rs = normalizeRouteCanadian(strtoupper(trim($row['route_string'])));
    $oar = ArtccNormalizer::normalizeCsv($row['origin_artccs'] ?? '');
    $dar = ArtccNormalizer::normalizeCsv($row['dest_artccs'] ?? '');

    // Build full route string with origin/dest endpoints
    $fullRoute = $rs;
    $origEndpoint = extractEndpoint($row['origin'] ?? '', $row['origin_airports'] ?? '', $oar);
    $destEndpoint = extractEndpoint($row['dest'] ?? '', $row['dest_airports'] ?? '', $dar);
    // Don't prepend/append if route already starts/ends with the endpoint (avoids
    // duplicate tokens like "ZLA ZLA TRM..." that cause mid-route misresolution)
    $routeParts = preg_split('/\s+/', $fullRoute);
    $firstToken = strtoupper($routeParts[0] ?? '');
    $lastToken = strtoupper($routeParts[count($routeParts) - 1] ?? '');
    if ($origEndpoint && $origEndpoint !== $firstToken) {
        $fullRoute = $origEndpoint . ' ' . $fullRoute;
    }
    if ($destEndpoint && $destEndpoint !== $lastToken) {
        $fullRoute = $fullRoute . ' ' . $destEndpoint;
    }

    $artccs = [];
    $tracons = [];
    $sectors_low = [];
    $sectors_high = [];
    $sectors_superhigh = [];

    $geojson_raw = null;
    $distance_nm = null;
    $waypoints_raw = null;

    try {
        $gis_stmt->execute([$fullRoute]);

        while ($r = $gis_stmt->fetch(PDO::FETCH_ASSOC)) {
            // Capture geometry fields from first row (same on all rows)
            if ($geojson_raw === null) {
                $geojson_raw = $r['geojson'];
                $distance_nm = $r['distance_nm'];
                $waypoints_raw = $r['waypoints_json'];
            }

            // Boundary data (may be null from LEFT JOIN if no intersections)
            $code = $r['code'] ?? null;
            if ($code === null) continue;

            switch ($r['btype']) {
                case 'artcc':
                    $code = ArtccNormalizer::normalize($code);
                    $artccs[] = $code;
                    break;
                case 'tracon': $tracons[] = $code; break;
                case 'sector_low': $sectors_low[] = $code; break;
                case 'sector_high': $sectors_high[] = $code; break;
                case 'sector_superhigh': $sectors_superhigh[] = $code; break;
            }
        }
    } catch (Exception $e) {
        $errors++;
        if ($processed <= 5 || $errors <= 3) {
            echo "  ERROR route $route_id: " . $e->getMessage() . "\n";
        }
    }

    // Build rich geometry envelope
    $route_geom = null;
    if ($geojson_raw) {
        $envelope = [
            'geojson' => json_decode($geojson_raw, true),
            'waypoints' => $waypoints_raw ? json_decode($waypoints_raw, true) : [],
            'distance_nm' => $distance_nm !== null ? round((float)$distance_nm, 1) : null,
            'frozen_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $route_geom = json_encode($envelope, JSON_UNESCAPED_SLASHES);
    }

    // Merge origin ARTCCs BEFORE GIS results, dest ARTCCs AFTER.
    // array_unique() preserves first occurrence, so insertion order matters:
    // origin → GIS spatial → destination gives correct traversal ordering.
    $origin_list = [];
    foreach (explode(',', $oar) as $a) {
        $a = trim($a);
        if ($a !== '' && strtoupper($a) !== 'UNKN' && strtoupper($a) !== 'VARIOUS') $origin_list[] = $a;
    }
    $dest_list = [];
    foreach (explode(',', $dar) as $a) {
        $a = trim($a);
        if ($a !== '' && strtoupper($a) !== 'UNKN' && strtoupper($a) !== 'VARIOUS') $dest_list[] = $a;
    }
    $artccs = array_merge($origin_list, $artccs, $dest_list);

    $trav_artccs = implode(',', array_unique(array_filter($artccs)));
    $trav_tracons = implode(',', array_unique(array_filter($tracons)));
    $trav_sec_low = implode(',', array_unique(array_filter($sectors_low)));
    $trav_sec_high = implode(',', array_unique(array_filter($sectors_high)));
    $trav_sec_superhigh = implode(',', array_unique(array_filter($sectors_superhigh)));

    if (!$dryRun) {
        $update_stmt->bind_param('ssssssi',
            $trav_artccs, $trav_tracons,
            $trav_sec_low, $trav_sec_high, $trav_sec_superhigh,
            $route_geom, $route_id);
        $update_stmt->execute();
        $updated++;
    }

    if ($processed % 500 === 0 || $processed === $total) {
        echo "  $processed / $total processed" . ($dryRun ? '' : ", $updated updated") . ", $errors errors\n";
    }

    // Sample output for first few routes
    if ($processed <= 3) {
        echo "  Route $route_id: artccs=$trav_artccs | tracons=$trav_tracons | sec_high=$trav_sec_high\n";
    }
}

$update_stmt->close();
echo "\nDone. Processed: $processed, Updated: $updated, Errors: $errors\n";
