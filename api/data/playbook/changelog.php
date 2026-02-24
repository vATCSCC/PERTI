<?php
/**
 * Playbook Changelog API
 * Returns changelog entries for audit/AIRAC cycle review.
 *
 * GET ?play_id=123           - Filter by play
 * GET ?airac=2601            - Filter by AIRAC cycle
 * GET ?action=route_updated  - Filter by action type
 * GET ?page=1&per_page=50    - Pagination
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

// Auth required for changelog
$perm = false;
if (!defined('DEV')) {
    if (isset($_SESSION['VATSIM_CID'])) {
        $cid = session_get('VATSIM_CID', '');
        $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");
        if ($p_check && $p_check->num_rows > 0) {
            $perm = true;
        }
    }
} else {
    $perm = true;
}

if (!$perm) {
    http_response_code(403);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$play_id  = get_int('play_id');
$airac    = get_input('airac');
$action   = get_input('action');
$page     = max(1, get_int('page', 1));
$per_page = min(200, max(1, get_int('per_page', 50)));
$offset   = ($page - 1) * $per_page;

$where = [];
$params = [];
$types  = '';

if ($play_id > 0) {
    $where[] = "c.play_id = ?";
    $params[] = $play_id;
    $types .= 'i';
}

if ($airac !== '') {
    $where[] = "c.airac_cycle = ?";
    $params[] = $airac;
    $types .= 's';
}

if ($action !== '') {
    $where[] = "c.action = ?";
    $params[] = $action;
    $types .= 's';
}

$where_sql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

// Count
$count_sql = "SELECT COUNT(*) AS total FROM playbook_changelog c $where_sql";
$count_stmt = $conn_sqli->prepare($count_sql);
if ($types !== '') {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total = $count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();

// Fetch
$data_sql = "SELECT c.*, p.play_name
             FROM playbook_changelog c
             LEFT JOIN playbook_plays p ON p.play_id = c.play_id
             $where_sql
             ORDER BY c.changed_at DESC
             LIMIT ? OFFSET ?";

$data_stmt = $conn_sqli->prepare($data_sql);
$data_types = $types . 'ii';
$data_params = array_merge($params, [$per_page, $offset]);
$data_stmt->bind_param($data_types, ...$data_params);
$data_stmt->execute();
$result = $data_stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    $row['changelog_id'] = (int)$row['changelog_id'];
    $row['play_id'] = (int)$row['play_id'];
    if ($row['route_id'] !== null) $row['route_id'] = (int)$row['route_id'];
    $rows[] = $row;
}
$data_stmt->close();

echo json_encode([
    'success' => true,
    'total' => (int)$total,
    'page' => $page,
    'per_page' => $per_page,
    'data' => $rows
]);
