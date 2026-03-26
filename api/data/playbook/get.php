<?php
/**
 * Playbook Get API
 * Returns a single play with all its routes.
 *
 * GET ?id=123  - Get play by ID
 */

header('Content-Type: application/json');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

include("../../../load/config.php");
include("../../../load/input.php");
include("../../../load/connect.php");
include("../../../load/playbook_visibility.php");
perti_set_cors();
require_once __DIR__ . '/../../../lib/ArtccNormalizer.php';
use PERTI\Lib\ArtccNormalizer;

$play_id = get_int('id');
if ($play_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid play id']);
    exit;
}

// Fetch play
$stmt = $conn_sqli->prepare("SELECT * FROM playbook_plays WHERE play_id = ?");
$stmt->bind_param('i', $play_id);
$stmt->execute();
$play = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$play) {
    http_response_code(404);
    echo json_encode(['error' => 'Play not found']);
    exit;
}

// Visibility check
if (!can_view_play($play, $conn_sqli)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied', 'error_code' => 'playbook.visibility.accessDenied']);
    exit;
}

$play['play_id'] = (int)$play['play_id'];
$play['route_count'] = (int)$play['route_count'];

// Filter ARTCC fields to L1 only (strip sub-sector suffixes like BIRD-E → BIRD)
if (!empty($play['impacted_area'])) {
    $play['impacted_area'] = ArtccNormalizer::toL1Csv($play['impacted_area'], '/');
}
if (!empty($play['facilities_involved'])) {
    $play['facilities_involved'] = ArtccNormalizer::toL1Csv($play['facilities_involved'], ',');
}

// Fetch routes
$stmt = $conn_sqli->prepare("SELECT * FROM playbook_routes WHERE play_id = ? ORDER BY sort_order ASC, route_id ASC");
$stmt->bind_param('i', $play_id);
$stmt->execute();
$result = $stmt->get_result();

$routes = [];
while ($row = $result->fetch_assoc()) {
    $row['route_id'] = (int)$row['route_id'];
    $row['play_id'] = (int)$row['play_id'];
    $row['sort_order'] = (int)$row['sort_order'];
    if (!empty($row['traversed_artccs'])) {
        $row['traversed_artccs'] = ArtccNormalizer::toL1Csv($row['traversed_artccs'], ',');
    }
    $routes[] = $row;
}
$stmt->close();

// ── Aggregate facility counts from route traversal columns ──────────
$facility_counts = ['ARTCC' => [], 'TRACON' => [], 'SECTOR_LOW' => [],
                    'SECTOR_HIGH' => [], 'SECTOR_SUPERHIGH' => []];
$routes_with_traversal = 0;
$fc_column_map = [
    'ARTCC'             => 'traversed_artccs',
    'TRACON'            => 'traversed_tracons',
    'SECTOR_LOW'        => 'traversed_sectors_low',
    'SECTOR_HIGH'       => 'traversed_sectors_high',
    'SECTOR_SUPERHIGH'  => 'traversed_sectors_superhigh',
];
$sector_type_lookup = [
    'traversed_sectors_low'       => 'LOW',
    'traversed_sectors_high'      => 'HIGH',
    'traversed_sectors_superhigh' => 'SUPERHIGH',
];
$all_sector_codes = []; // code => [type1, type2, ...] for PostGIS coverage lookup

foreach ($routes as $r) {
    $has_data = false;
    foreach ($fc_column_map as $type => $col) {
        $val = trim($r[$col] ?? '');
        if ($val === '') continue;
        $has_data = true;
        $codes = array_unique(array_filter(array_map('trim', explode(',', $val))));
        if ($type === 'ARTCC') {
            $codes = array_map(function($c) { return ArtccNormalizer::normalize($c); }, $codes);
            $codes = array_unique($codes);
        }
        foreach ($codes as $code) {
            if ($code === '') continue;
            $facility_counts[$type][$code] = ($facility_counts[$type][$code] ?? 0) + 1;
        }
        if (isset($sector_type_lookup[$col])) {
            $stype = $sector_type_lookup[$col];
            foreach ($codes as $code) {
                if ($code !== '') $all_sector_codes[$code][$stype] = true;
            }
        }
    }
    if ($has_data) $routes_with_traversal++;
}

// Sort each type descending by count
$formatted_counts = [];
foreach ($facility_counts as $type => $counts) {
    arsort($counts);
    $arr = [];
    foreach ($counts as $code => $count) {
        $arr[] = ['code' => $code, 'route_count' => $count];
    }
    $formatted_counts[$type] = $arr;
}
$formatted_counts['total_routes'] = count($routes);
$formatted_counts['routes_with_traversal'] = $routes_with_traversal;

// ── Sector coverage: query PostGIS for sector totals per ARTCC ──────
if (!empty($all_sector_codes)) {
    $conn_gis = get_conn_gis();
    if ($conn_gis) {
        try {
            $sector_codes_list = array_keys($all_sector_codes);
            $ph = implode(',', array_fill(0, count($sector_codes_list), '?'));

            // Get parent_artcc for each traversed sector
            $stmt_gis = $conn_gis->prepare(
                "SELECT sector_code, parent_artcc, sector_type FROM sector_boundaries WHERE sector_code IN ($ph)"
            );
            $stmt_gis->execute($sector_codes_list);
            $sector_info = $stmt_gis->fetchAll(PDO::FETCH_ASSOC);

            $sector_artcc_map = [];
            $relevant_artccs = [];
            foreach ($sector_info as $si) {
                $sector_artcc_map[$si['sector_code']] = $si['parent_artcc'];
                $relevant_artccs[$si['parent_artcc']] = true;
            }

            if (!empty($relevant_artccs)) {
                $artcc_list = array_keys($relevant_artccs);
                $ph2 = implode(',', array_fill(0, count($artcc_list), '?'));
                $stmt_totals = $conn_gis->prepare(
                    "SELECT parent_artcc, sector_type, COUNT(*) AS total
                     FROM sector_boundaries WHERE parent_artcc IN ($ph2)
                     GROUP BY parent_artcc, sector_type"
                );
                $stmt_totals->execute($artcc_list);

                $sector_totals = [];
                foreach ($stmt_totals->fetchAll(PDO::FETCH_ASSOC) as $t) {
                    $sector_totals[$t['parent_artcc']][$t['sector_type']] = (int)$t['total'];
                }

                // Play-level: unique sectors per ARTCC per type
                // A sector can appear in multiple strata (e.g., ZNY42 in both HIGH and SUPERHIGH)
                $play_sectors = [];
                foreach ($all_sector_codes as $code => $stypes) {
                    $artcc = $sector_artcc_map[$code] ?? null;
                    if ($artcc) {
                        foreach ($stypes as $stype => $_) {
                            $play_sectors[$artcc][$stype][$code] = true;
                        }
                    }
                }

                $coverage_data = [];
                foreach ($play_sectors as $artcc => $types) {
                    foreach ($types as $stype => $codes_set) {
                        $trav_count = count($codes_set);
                        $total = $sector_totals[$artcc][$stype] ?? 0;
                        $pct = $total > 0 ? round($trav_count / $total * 100, 1) : 0;
                        $coverage_data[$artcc][$stype] = [
                            'traversed' => $trav_count,
                            'total' => $total,
                            'pct' => $pct,
                        ];
                    }
                }

                $formatted_counts['coverage'] = [
                    'sector_totals' => $sector_totals,
                    'play_sectors'  => $coverage_data,
                    'sector_artcc_map' => $sector_artcc_map,
                ];
            }
        } catch (Exception $e) {
            // PostGIS unavailable — skip coverage, still return counts
        }
    }
}

// Annotate with permission flags
$session_cid = isset($_SESSION['VATSIM_CID']) ? (int)$_SESSION['VATSIM_CID'] : null;
$play['can_edit'] = can_edit_play($play, $conn_sqli);
$play['is_owner'] = $session_cid !== null && (string)($play['created_by'] ?? '') === (string)$session_cid;

echo json_encode([
    'success' => true,
    'play' => $play,
    'routes' => $routes,
    'facility_counts' => $formatted_counts,
]);
