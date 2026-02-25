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

$category    = get_input('category');
$status      = get_input('status');
$source      = get_input('source');
$search      = get_input('search');
$artcc       = get_upper('artcc');
$hide_legacy = get_int('hide_legacy', 0);
$page        = max(1, get_int('page', 1));
$per_page    = min(1000, max(1, get_int('per_page', 200)));
$offset      = ($page - 1) * $per_page;

$where = [];
$params = [];
$types  = '';

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
    $where[] = "FIND_IN_SET(?, p.facilities_involved) > 0";
    $params[] = $artcc;
    $types .= 's';
}

if ($hide_legacy) {
    $where[] = "p.play_name NOT LIKE '%\\_old\\_%'";
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

// Fetch page
$data_sql = "SELECT p.play_id, p.play_name, p.play_name_norm, p.display_name,
                    p.description, p.category, p.impacted_area, p.facilities_involved,
                    p.scenario_type, p.route_format, p.source, p.status,
                    p.airac_cycle, p.route_count, p.org_code,
                    p.created_by, p.updated_by, p.updated_at, p.created_at
             FROM playbook_plays p
             $where_sql
             ORDER BY p.source ASC, p.play_name ASC
             LIMIT ? OFFSET ?";

$data_stmt = $conn_sqli->prepare($data_sql);
$data_types = $types . 'ii';
$data_params = array_merge($params, [$per_page, $offset]);
$data_stmt->bind_param($data_types, ...$data_params);
$data_stmt->execute();
$result = $data_stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    $row['play_id'] = (int)$row['play_id'];
    $row['route_count'] = (int)$row['route_count'];
    $rows[] = $row;
}
$data_stmt->close();

echo json_encode([
    'success' => true,
    'total' => (int)$total,
    'page' => $page,
    'per_page' => $per_page,
    'pages' => ceil($total / $per_page),
    'data' => $rows
]);
