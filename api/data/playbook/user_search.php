<?php
/**
 * Playbook User Search API
 * GET ?q=<search>&org=<org_code>
 *
 * Searches users by CID, first_name, or last_name.
 * Optionally filters by org membership.
 * Returns max 20 results.
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

// Auth required
if (!isset($_SESSION['VATSIM_CID'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$q   = trim(get_str('q'));
$org = trim(get_str('org'));

if (strlen($q) < 2 && !$org) {
    echo json_encode(['success' => true, 'users' => []]);
    exit;
}

// Build query based on filters
$params = [];
$types  = '';
$where  = [];

if ($org) {
    // Filter by org membership
    $where[] = "u.cid IN (SELECT cid FROM user_orgs WHERE org_code = ?)";
    $params[] = $org;
    $types .= 's';
}

if (strlen($q) >= 2) {
    if (ctype_digit($q)) {
        // Search by CID (prefix match)
        $where[] = "u.cid LIKE ?";
        $params[] = $q . '%';
        $types .= 's';
    } else {
        // Search by name (case-insensitive partial match)
        $where[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= 'sss';
    }
}

$sql = "SELECT u.cid, u.first_name, u.last_name FROM users u";
if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
}
$sql .= " ORDER BY u.last_name, u.first_name LIMIT 20";

$stmt = $conn_sqli->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$users = [];
while ($row = $result->fetch_assoc()) {
    $users[] = [
        'cid'        => (int)$row['cid'],
        'first_name' => $row['first_name'],
        'last_name'  => $row['last_name'],
        'name'       => $row['first_name'] . ' ' . $row['last_name']
    ];
}
$stmt->close();

// Enrich with org memberships
if ($users) {
    $cids = array_column($users, 'cid');
    $placeholders = implode(',', array_fill(0, count($cids), '?'));
    $org_sql = "SELECT uo.cid, uo.org_code, o.display_name
                FROM user_orgs uo
                JOIN organizations o ON o.org_code = uo.org_code AND o.is_active = 1
                WHERE uo.cid IN ($placeholders)";
    $org_stmt = $conn_sqli->prepare($org_sql);
    $org_types = str_repeat('i', count($cids));
    $org_stmt->bind_param($org_types, ...$cids);
    $org_stmt->execute();
    $org_result = $org_stmt->get_result();

    $org_map = [];
    while ($orow = $org_result->fetch_assoc()) {
        $org_map[(int)$orow['cid']][] = [
            'org_code'     => $orow['org_code'],
            'display_name' => $orow['display_name']
        ];
    }
    $org_stmt->close();

    foreach ($users as &$u) {
        $u['orgs'] = $org_map[$u['cid']] ?? [];
    }
    unset($u);
}

echo json_encode(['success' => true, 'users' => $users]);
