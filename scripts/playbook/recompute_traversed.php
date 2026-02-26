<?php
/**
 * Recompute traversed facilities (ARTCCs, TRACONs, sectors) for all playbook routes.
 * Uses PostGIS spatial joins to determine which boundaries each route's fixes traverse.
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

$exclude = ['DCT', 'STAR', 'SID', 'RNAV', 'GPS', 'ILS', 'VOR', 'DME', 'NDB', 'RADAR', 'DIRECT'];
$processed = 0;
$updated = 0;
$errors = 0;

while ($row = $result->fetch_assoc()) {
    $processed++;
    $route_id = (int)$row['route_id'];
    $rs = strtoupper(trim($row['route_string']));
    $oar = $row['origin_artccs'] ?? '';
    $dar = $row['dest_artccs'] ?? '';

    // Extract fix tokens
    $tokens = preg_split('/\s+/', $rs);
    $fixNames = [];
    foreach ($tokens as $t) {
        if (preg_match('/^[A-Z]{2,5}$/', $t) && !in_array($t, $exclude)) {
            $fixNames[] = $t;
        }
    }

    $artccs = [];
    $tracons = [];
    $sectors_low = [];
    $sectors_high = [];
    $sectors_superhigh = [];

    if (!empty($fixNames)) {
        $params = array_values(array_unique($fixNames));
        $placeholders = implode(',', array_fill(0, count($params), '?'));

        $sql = "SELECT 'artcc' AS btype, b.artcc_code AS code
                FROM nav_fixes f
                JOIN artcc_boundaries b ON ST_Contains(b.geom, f.geom)
                WHERE f.fix_name IN ($placeholders)
                UNION
                SELECT 'tracon', t.tracon_code
                FROM nav_fixes f
                JOIN tracon_boundaries t ON ST_Contains(t.geom, f.geom)
                WHERE f.fix_name IN ($placeholders)
                UNION
                SELECT CONCAT('sector_', LOWER(s.sector_type)), s.sector_code
                FROM nav_fixes f
                JOIN sector_boundaries s ON ST_Contains(s.geom, f.geom)
                WHERE f.fix_name IN ($placeholders)";

        $allParams = array_merge($params, $params, $params);

        try {
            $stmt = $conn_gis->prepare($sql);
            $stmt->execute($allParams);

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
        } catch (Exception $e) {
            $errors++;
            if ($processed <= 5 || $errors <= 3) {
                echo "  ERROR route $route_id: " . $e->getMessage() . "\n";
            }
        }
    }

    // Merge origin/dest ARTCCs
    foreach (explode(',', $oar) as $a) {
        $a = trim($a);
        if ($a !== '') $artccs[] = $a;
    }
    foreach (explode(',', $dar) as $a) {
        $a = trim($a);
        if ($a !== '') $artccs[] = $a;
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
