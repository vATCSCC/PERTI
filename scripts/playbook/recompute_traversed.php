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
    traversed_sectors_low = ?, traversed_sectors_high = ?, traversed_sectors_superhigh = ?
    WHERE route_id = ?");

// Prepare the PostGIS query once — reuse for every route
$gis_sql = "WITH route AS (
                SELECT artccs_traversed, route_geometry AS geom
                FROM expand_route_with_artccs(?)
            )
            SELECT 'artcc' AS btype, unnest(route.artccs_traversed) AS code
            FROM route WHERE route.geom IS NOT NULL
            UNION ALL
            SELECT 'tracon', t.tracon_code
            FROM route JOIN tracon_boundaries t ON ST_Intersects(route.geom, t.geom)
            WHERE route.geom IS NOT NULL
            UNION ALL
            SELECT CONCAT('sector_', LOWER(s.sector_type)), s.sector_code
            FROM route JOIN sector_boundaries s ON ST_Intersects(route.geom, s.geom)
            WHERE route.geom IS NOT NULL";
$gis_stmt = $conn_gis->prepare($gis_sql);

$processed = 0;
$updated = 0;
$errors = 0;

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
    $rs = strtoupper(trim($row['route_string']));
    $oar = $row['origin_artccs'] ?? '';
    $dar = $row['dest_artccs'] ?? '';

    // Build full route string with origin/dest endpoints
    $fullRoute = $rs;
    $origEndpoint = extractEndpoint($row['origin'] ?? '', $row['origin_airports'] ?? '', $oar);
    $destEndpoint = extractEndpoint($row['dest'] ?? '', $row['dest_airports'] ?? '', $dar);
    if ($origEndpoint) $fullRoute = $origEndpoint . ' ' . $fullRoute;
    if ($destEndpoint) $fullRoute = $fullRoute . ' ' . $destEndpoint;

    $artccs = [];
    $tracons = [];
    $sectors_low = [];
    $sectors_high = [];
    $sectors_superhigh = [];

    try {
        $gis_stmt->execute([$fullRoute]);

        while ($r = $gis_stmt->fetch(PDO::FETCH_ASSOC)) {
            $code = $r['code'];
            switch ($r['btype']) {
                case 'artcc':
                    if (strlen($code) === 4 && $code[0] === 'K') $code = substr($code, 1);
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

    // Merge origin/dest ARTCCs (skip UNKN)
    foreach (explode(',', $oar) as $a) {
        $a = trim($a);
        if ($a !== '' && strtoupper($a) !== 'UNKN') $artccs[] = $a;
    }
    foreach (explode(',', $dar) as $a) {
        $a = trim($a);
        if ($a !== '' && strtoupper($a) !== 'UNKN') $artccs[] = $a;
    }

    $trav_artccs = implode(',', array_unique(array_filter($artccs)));
    $trav_tracons = implode(',', array_unique(array_filter($tracons)));
    $trav_sec_low = implode(',', array_unique(array_filter($sectors_low)));
    $trav_sec_high = implode(',', array_unique(array_filter($sectors_high)));
    $trav_sec_superhigh = implode(',', array_unique(array_filter($sectors_superhigh)));

    if (!$dryRun) {
        $update_stmt->bind_param('sssssi',
            $trav_artccs, $trav_tracons,
            $trav_sec_low, $trav_sec_high, $trav_sec_superhigh,
            $route_id);
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
