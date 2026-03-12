<?php
/**
 * Playbook List API
 * Returns paginated list of plays with optional filters.
 *
 * GET ?category=EAST_GATE     - Filter by category
 * GET ?status=active           - Filter by status (active/draft/archived)
 * GET ?source=FAA              - Filter by source (FAA/DCC)
 * GET ?search=KENPA            - Search play name or description
 * GET ?artcc=ZNY               - Filter by ARTCC in facilities_involved
 * GET ?page=1&per_page=50      - Pagination
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

include("../../../load/config.php");
include("../../../load/input.php");
define('PERTI_MYSQL_ONLY', true);
include("../../../load/connect.php");
include("../../../load/playbook_visibility.php");

$category    = get_input('category');
$status      = get_input('status');
$source      = get_input('source');
$search      = get_input('search');
$artcc       = get_upper('artcc');
$hide_legacy = get_int('hide_legacy', 0);
$page        = max(1, get_int('page', 1));
$per_page    = min(10000, max(1, get_int('per_page', 200)));
$offset      = ($page - 1) * $per_page;

$where = [];
$params = [];
$types  = '';

// Visibility filtering
$vis_cid = isset($_SESSION['VATSIM_CID']) ? (int)$_SESSION['VATSIM_CID'] : null;
$vis_admin = $vis_cid !== null ? is_playbook_admin($conn_sqli) : false;
$vis = build_visibility_where($vis_cid, $vis_admin);
if ($vis['sql'] !== '') {
    $where[] = preg_replace('/^\s*AND\s+/', '', $vis['sql']);
    $params = array_merge($params, $vis['params']);
    $types .= $vis['types'];
}

if ($status !== '') {
    $where[] = "p.status = ?";
    $params[] = $status;
    $types .= 's';
} else {
    $where[] = "p.status != 'archived'";
}

if ($category !== '') {
    $where[] = "p.category = ?";
    $params[] = $category;
    $types .= 's';
}

if ($source !== '') {
    $where[] = "p.source = ?";
    $params[] = $source;
    $types .= 's';
}

if ($search !== '') {
    $where[] = "(p.play_name LIKE ? OR p.display_name LIKE ? OR p.description LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

if ($artcc !== '') {
    // Normalize FAA/alias codes to ICAO canonical (e.g. CZU → CZUL, CZE → CZEG)
    $artcc_aliases = [
        'CZE' => 'CZEG', 'CZU' => 'CZUL', 'CZV' => 'CZVR',
        'CZW' => 'CZWG', 'CZY' => 'CZYZ', 'CZM' => 'CZQM',
        'CZQ' => 'CZQX', 'CZO' => 'CZQO',
        'ZEG' => 'CZEG', 'ZUL' => 'CZUL', 'ZVR' => 'CZVR',
        'ZWG' => 'CZWG', 'ZYZ' => 'CZYZ', 'ZQM' => 'CZQM',
        'ZQX' => 'CZQX', 'ZQO' => 'CZQO', 'CZX' => 'CZQX',
        'KZAK' => 'ZAK', 'KZWY' => 'ZWY', 'PGZU' => 'ZUA',
        'PAZA' => 'ZAN', 'PAZN' => 'ZAP', 'PHZH' => 'ZHN',
        'ZMX' => 'MMMX', 'ZMT' => 'MMTY', 'ZMZ' => 'MMZT',
        'ZMR' => 'MMMD', 'ZMC' => 'MMUN', 'ZSU' => 'TJZS',
    ];
    $artcc = $artcc_aliases[$artcc] ?? $artcc;
    $where[] = "FIND_IN_SET(?, p.facilities_involved) > 0";
    $params[] = $artcc;
    $types .= 's';
}

if ($hide_legacy) {
    $where[] = "(p.play_name NOT LIKE '%\\_old\\_%' AND p.source != 'FAA_HISTORICAL')";
}

$where_sql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

// Count total
$count_sql = "SELECT COUNT(*) AS total FROM playbook_plays p $where_sql";
$count_stmt = $conn_sqli->prepare($count_sql);
if ($types !== '') {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total = $count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();

// Phase 1: Get play_ids for the current page (fast — no route aggregation)
$ids_sql = "SELECT p.play_id FROM playbook_plays p $where_sql
            ORDER BY p.source ASC, p.play_name ASC LIMIT ? OFFSET ?";
$ids_stmt = $conn_sqli->prepare($ids_sql);
$ids_types = $types . 'ii';
$ids_params = array_merge($params, [$per_page, $offset]);
$ids_stmt->bind_param($ids_types, ...$ids_params);
$ids_stmt->execute();
$ids_result = $ids_stmt->get_result();
$play_ids = [];
while ($r = $ids_result->fetch_assoc()) {
    $play_ids[] = (int)$r['play_id'];
}
$ids_stmt->close();

$rows = [];
if (!empty($play_ids)) {
    // Phase 2: Fetch full play data + route aggregation only for these play_ids
    $id_list = implode(',', $play_ids); // safe — integers from our own query
    $play_count = count($play_ids);

    // For large result sets (>500 plays, e.g. FAA_HISTORICAL source with 3000+),
    // skip route aggregation entirely to avoid PHP OOM. Play-level data
    // (play_name, description, facilities_involved) is still searchable;
    // detailed route data is loaded separately via get.php on play click.
    if ($play_count > 500) {
        $data_sql = "SELECT play_id, play_name, play_name_norm, display_name,
                            description, category, impacted_area, facilities_involved,
                            scenario_type, route_format, source, status,
                            airac_cycle, route_count, org_code, visibility,
                            created_by, updated_by, updated_at, created_at,
                            NULL AS agg_origin_airports, NULL AS agg_origin_tracons,
                            NULL AS agg_origin_artccs, NULL AS agg_dest_airports,
                            NULL AS agg_dest_tracons, NULL AS agg_dest_artccs,
                            NULL AS agg_traversed_artccs, NULL AS agg_traversed_tracons,
                            NULL AS agg_traversed_sectors_low, NULL AS agg_traversed_sectors_high,
                            NULL AS agg_traversed_sectors_superhigh, NULL AS agg_route_strings
                     FROM playbook_plays
                     WHERE play_id IN ($id_list)
                     ORDER BY source ASC, play_name ASC";
    } else {
        $conn_sqli->query("SET SESSION group_concat_max_len = 65536");

        // Skip agg_route_strings for medium result sets (prevents PHP OOM on B1ms tier)
        $route_strings_col = $play_count <= 200
            ? "GROUP_CONCAT(route_string SEPARATOR ' ') AS agg_route_strings"
            : "NULL AS agg_route_strings";

        $data_sql = "SELECT p.play_id, p.play_name, p.play_name_norm, p.display_name,
                            p.description, p.category, p.impacted_area, p.facilities_involved,
                            p.scenario_type, p.route_format, p.source, p.status,
                            p.airac_cycle, p.route_count, p.org_code, p.visibility,
                            p.created_by, p.updated_by, p.updated_at, p.created_at,
                            ra.agg_origin_airports, ra.agg_origin_tracons, ra.agg_origin_artccs,
                            ra.agg_dest_airports, ra.agg_dest_tracons, ra.agg_dest_artccs,
                            ra.agg_traversed_artccs, ra.agg_traversed_tracons,
                            ra.agg_traversed_sectors_low, ra.agg_traversed_sectors_high,
                            ra.agg_traversed_sectors_superhigh, ra.agg_route_strings
                     FROM playbook_plays p
                     LEFT JOIN (
                         SELECT play_id,
                             GROUP_CONCAT(DISTINCT NULLIF(origin_airports,'') SEPARATOR ',') AS agg_origin_airports,
                             GROUP_CONCAT(DISTINCT NULLIF(origin_tracons,'') SEPARATOR ',') AS agg_origin_tracons,
                             GROUP_CONCAT(DISTINCT NULLIF(origin_artccs,'') SEPARATOR ',') AS agg_origin_artccs,
                             GROUP_CONCAT(DISTINCT NULLIF(dest_airports,'') SEPARATOR ',') AS agg_dest_airports,
                             GROUP_CONCAT(DISTINCT NULLIF(dest_tracons,'') SEPARATOR ',') AS agg_dest_tracons,
                             GROUP_CONCAT(DISTINCT NULLIF(dest_artccs,'') SEPARATOR ',') AS agg_dest_artccs,
                             GROUP_CONCAT(DISTINCT NULLIF(traversed_artccs,'') SEPARATOR ',') AS agg_traversed_artccs,
                             GROUP_CONCAT(DISTINCT NULLIF(traversed_tracons,'') SEPARATOR ',') AS agg_traversed_tracons,
                             GROUP_CONCAT(DISTINCT NULLIF(traversed_sectors_low,'') SEPARATOR ',') AS agg_traversed_sectors_low,
                             GROUP_CONCAT(DISTINCT NULLIF(traversed_sectors_high,'') SEPARATOR ',') AS agg_traversed_sectors_high,
                             GROUP_CONCAT(DISTINCT NULLIF(traversed_sectors_superhigh,'') SEPARATOR ',') AS agg_traversed_sectors_superhigh,
                             $route_strings_col
                         FROM playbook_routes WHERE play_id IN ($id_list) GROUP BY play_id
                     ) ra ON ra.play_id = p.play_id
                     WHERE p.play_id IN ($id_list)
                     ORDER BY p.source ASC, p.play_name ASC";
    }

    $data_result = $conn_sqli->query($data_sql);
    while ($row = $data_result->fetch_assoc()) {
        $row['play_id'] = (int)$row['play_id'];
        $row['route_count'] = (int)$row['route_count'];
        $rows[] = $row;
    }
}

// Post-filter visibility and annotate can_edit
$filtered_rows = [];
foreach ($rows as $row) {
    // private_org rows may pass the SQL filter but fail the org-membership check
    if (($row['visibility'] ?? 'public') === 'private_org' && !$vis_admin) {
        if (!can_view_play($row, $conn_sqli)) continue;
    }
    $row['can_edit'] = can_edit_play($row, $conn_sqli);
    $filtered_rows[] = $row;
}
$rows = $filtered_rows;

// Adjust total if rows were removed by post-filter
$filtered_count = count($rows);
if ($filtered_count < count($play_ids ?? [])) {
    $total = max(0, $total - (count($play_ids) - $filtered_count));
}

echo json_encode([
    'success' => true,
    'total' => (int)$total,
    'page' => $page,
    'per_page' => $per_page,
    'pages' => max(1, ceil($total / $per_page)),
    'data' => $rows
]);
