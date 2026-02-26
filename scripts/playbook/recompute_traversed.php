<?php
/**
 * Recompute traversed facilities (ARTCCs, TRACONs, sectors) for all playbook routes.
 * Uses PostGIS LINESTRING + ST_Intersects to determine which boundaries each route crosses.
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

// Fetch routes
$where = $playFilter ? "WHERE play_id = $playFilter" : "";
$result = $conn_sqli->query("SELECT route_id, play_id, route_string, origin_artccs, dest_artccs FROM playbook_routes $where ORDER BY play_id, sort_order");

$total = $result->num_rows;
echo "Processing $total routes" . ($dryRun ? " (DRY RUN)" : "") . "...\n";

$update_stmt = $conn_sqli->prepare("UPDATE playbook_routes SET
    traversed_artccs = ?, traversed_tracons = ?,
    traversed_sectors_low = ?, traversed_sectors_high = ?, traversed_sectors_superhigh = ?
    WHERE route_id = ?");

$exclude = ['DCT', 'STAR', 'SID', 'RNAV', 'GPS', 'ILS', 'VOR', 'DME', 'NDB', 'RADAR', 'DIRECT', 'UNKN'];
$processed = 0;
$updated = 0;
$errors = 0;

while ($row = $result->fetch_assoc()) {
    $processed++;
    $route_id = (int)$row['route_id'];
    $rs = strtoupper(trim($row['route_string']));
    $oar = $row['origin_artccs'] ?? '';
    $dar = $row['dest_artccs'] ?? '';

    // Extract fix tokens (preserving route order)
    $tokens = preg_split('/\s+/', $rs);
    $orderedFixNames = [];
    foreach ($tokens as $t) {
        if (preg_match('/^[A-Z]{2,5}$/', $t) && !in_array($t, $exclude)) {
            $orderedFixNames[] = $t;
        }
    }

    $artccs = [];
    $tracons = [];
    $sectors_low = [];
    $sectors_high = [];
    $sectors_superhigh = [];

    if (!empty($orderedFixNames)) {
        try {
            // Step A: Look up fix coordinates
            $uniqueFixes = array_values(array_unique($orderedFixNames));
            $placeholders = implode(',', array_fill(0, count($uniqueFixes), '?'));
            $coordSql = "SELECT fix_name, ST_X(geom) AS lon, ST_Y(geom) AS lat
                         FROM nav_fixes WHERE fix_name IN ($placeholders)";
            $coordStmt = $conn_gis->prepare($coordSql);
            $coordStmt->execute($uniqueFixes);

            $fixCoords = [];
            while ($r = $coordStmt->fetch(PDO::FETCH_ASSOC)) {
                $fixCoords[$r['fix_name']] = [$r['lon'], $r['lat']];
            }

            // Build ordered coordinate array
            $orderedCoords = [];
            foreach ($orderedFixNames as $fn) {
                if (isset($fixCoords[$fn])) {
                    $orderedCoords[] = $fixCoords[$fn];
                }
            }

            if (count($orderedCoords) >= 2) {
                // Step B: Build LINESTRING and intersect with boundaries
                $pointsSql = [];
                $lineParams = [];
                foreach ($orderedCoords as $coord) {
                    $pointsSql[] = 'ST_SetSRID(ST_MakePoint(?,?),4326)';
                    $lineParams[] = $coord[0]; // lon
                    $lineParams[] = $coord[1]; // lat
                }
                $lineExpr = 'ST_MakeLine(ARRAY[' . implode(',', $pointsSql) . '])';

                $sql = "WITH route_line AS (SELECT $lineExpr AS geom)
                        SELECT 'artcc' AS btype, b.artcc_code AS code
                        FROM route_line rl JOIN artcc_boundaries b ON ST_Intersects(rl.geom, b.geom)
                        UNION ALL
                        SELECT 'tracon', t.tracon_code
                        FROM route_line rl JOIN tracon_boundaries t ON ST_Intersects(rl.geom, t.geom)
                        UNION ALL
                        SELECT CONCAT('sector_', LOWER(s.sector_type)), s.sector_code
                        FROM route_line rl JOIN sector_boundaries s ON ST_Intersects(rl.geom, s.geom)";

                $stmt = $conn_gis->prepare($sql);
                $stmt->execute($lineParams);

                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
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
            } elseif (count($orderedCoords) === 1) {
                // Fallback: single fix — point-based ST_Contains
                $lon = $orderedCoords[0][0];
                $lat = $orderedCoords[0][1];
                $ptExpr = 'ST_SetSRID(ST_MakePoint(?,?),4326)';

                $sql = "SELECT 'artcc' AS btype, b.artcc_code AS code
                        FROM artcc_boundaries b WHERE ST_Contains(b.geom, $ptExpr)
                        UNION ALL
                        SELECT 'tracon', t.tracon_code
                        FROM tracon_boundaries t WHERE ST_Contains(t.geom, $ptExpr)
                        UNION ALL
                        SELECT CONCAT('sector_', LOWER(s.sector_type)), s.sector_code
                        FROM sector_boundaries s WHERE ST_Contains(s.geom, $ptExpr)";

                $stmt = $conn_gis->prepare($sql);
                $stmt->execute([$lon, $lat, $lon, $lat, $lon, $lat]);

                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
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
            }
        } catch (Exception $e) {
            $errors++;
            if ($processed <= 5 || $errors <= 3) {
                echo "  ERROR route $route_id: " . $e->getMessage() . "\n";
            }
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
