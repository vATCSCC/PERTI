<?php
/**
 * Playbook Bulk Routes API
 * Returns multiple plays with their routes in a single request.
 * Designed to replace the N+1 pattern where the DCC loader fired 95+ individual
 * get.php calls — now a single request.
 *
 * GET ?ids=8692,8710,...            - Specific play IDs (comma-separated, max 500)
 * GET ?source_exclude=FAA&hide_legacy=1 - All non-FAA, non-legacy plays with routes
 *
 * Response is lean: only fields needed for PB directive resolution (play_name + route
 * origin/dest fields), not the full play object with facility counts/coverage.
 */

header('Content-Type: application/json');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

include("../../../load/config.php");
include("../../../load/input.php");
define('PERTI_MYSQL_ONLY', true);
include("../../../load/connect.php");
include("../../../load/playbook_visibility.php");
perti_set_cors();

$ids_param      = get_input('ids');
$source_exclude = get_input('source_exclude');
$hide_legacy    = get_int('hide_legacy', 0);

// Build play filter
$where = ["p.status != 'archived'"];
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

if ($ids_param !== '') {
    // Specific play IDs
    $ids = array_filter(array_map('intval', explode(',', $ids_param)));
    if (empty($ids) || count($ids) > 500) {
        http_response_code(400);
        echo json_encode(['error' => 'Provide 1-500 comma-separated play IDs']);
        exit;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $where[] = "p.play_id IN ($placeholders)";
    foreach ($ids as $id) {
        $params[] = $id;
        $types .= 'i';
    }
} else {
    // Source exclusion mode (e.g. source_exclude=FAA)
    if ($source_exclude !== '') {
        $where[] = "p.source != ?";
        $params[] = $source_exclude;
        $types .= 's';
    }
    if ($hide_legacy) {
        $where[] = "(p.play_name NOT LIKE '%\\_old\\_%' AND p.source != 'FAA_HISTORICAL')";
    }
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

// Phase 1: Get play IDs + names
$play_sql = "SELECT p.play_id, p.play_name, p.visibility, p.org_code, p.created_by
             FROM playbook_plays p $where_sql
             ORDER BY p.play_name ASC
             LIMIT 500";
$stmt = $conn_sqli->prepare($play_sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$plays = [];
$play_ids = [];
while ($row = $result->fetch_assoc()) {
    // Post-filter visibility for private_org plays
    if (($row['visibility'] ?? 'public') === 'private_org' && !$vis_admin) {
        if (!can_view_play($row, $conn_sqli)) continue;
    }
    $pid = (int)$row['play_id'];
    $play_ids[] = $pid;
    $plays[$pid] = [
        'play_id'   => $pid,
        'play_name' => $row['play_name'],
        'routes'    => [],
    ];
}
$stmt->close();

// Phase 2: Fetch routes for all plays in one query
if (!empty($play_ids)) {
    $id_list = implode(',', $play_ids); // safe — integers from our own query
    $route_sql = "SELECT play_id, route_id, route_string,
                         origin, dest,
                         origin_airports, origin_tracons, origin_artccs,
                         dest_airports, dest_tracons, dest_artccs
                  FROM playbook_routes
                  WHERE play_id IN ($id_list)
                  ORDER BY play_id, sort_order ASC, route_id ASC";
    $route_result = $conn_sqli->query($route_sql);

    while ($r = $route_result->fetch_assoc()) {
        $pid = (int)$r['play_id'];
        if (isset($plays[$pid])) {
            $plays[$pid]['routes'][] = [
                'route_id'        => (int)$r['route_id'],
                'route_string'    => $r['route_string'],
                'origin'          => $r['origin'],
                'dest'            => $r['dest'],
                'origin_airports' => $r['origin_airports'],
                'origin_tracons'  => $r['origin_tracons'],
                'origin_artccs'   => $r['origin_artccs'],
                'dest_airports'   => $r['dest_airports'],
                'dest_tracons'    => $r['dest_tracons'],
                'dest_artccs'     => $r['dest_artccs'],
            ];
        }
    }
}

echo json_encode([
    'success' => true,
    'count'   => count($plays),
    'plays'   => array_values($plays),
]);
