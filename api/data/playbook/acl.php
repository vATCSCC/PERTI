<?php
/**
 * Playbook ACL Read API
 * Returns ACL information for a play.
 *
 * GET ?play_id=123 — Get ACL entries and current user's permissions
 *
 * If the user is the owner or has can_manage_acl: returns the full ACL list.
 * Otherwise: returns only the user's own ACL entry (if any).
 * Always returns: my_permissions object and is_owner boolean.
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

$play_id = get_int('play_id');
if ($play_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid play_id']);
    exit;
}

// Fetch play
$stmt = $conn_sqli->prepare("SELECT play_id, visibility, created_by, org_code FROM playbook_plays WHERE play_id = ?");
$stmt->bind_param('i', $play_id);
$stmt->execute();
$play = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$play) {
    http_response_code(404);
    echo json_encode(['error' => 'Play not found']);
    exit;
}

$session_cid = $_SESSION['VATSIM_CID'] ?? null;
$is_owner = $session_cid !== null && (string)$session_cid === (string)($play['created_by'] ?? '');
$is_admin = $session_cid !== null && is_playbook_admin($conn_sqli);
$can_manage_acl = can_manage_acl_play($play, $conn_sqli);

// Build my_permissions for the current user
$my_permissions = [
    'can_view' => false,
    'can_manage' => false,
    'can_manage_acl' => false
];

if ($is_owner || $is_admin) {
    // Owner and admin have full implicit permissions
    $my_permissions = [
        'can_view' => true,
        'can_manage' => true,
        'can_manage_acl' => true
    ];
} elseif ($session_cid !== null) {
    // Check the user's own ACL entry
    $my_stmt = $conn_sqli->prepare("SELECT can_view, can_manage, can_manage_acl FROM playbook_play_acl WHERE play_id = ? AND cid = ?");
    $my_cid = (int)$session_cid;
    $my_stmt->bind_param('ii', $play_id, $my_cid);
    $my_stmt->execute();
    $my_acl = $my_stmt->get_result()->fetch_assoc();
    $my_stmt->close();

    if ($my_acl) {
        $my_permissions = [
            'can_view' => (bool)$my_acl['can_view'],
            'can_manage' => (bool)$my_acl['can_manage'],
            'can_manage_acl' => (bool)$my_acl['can_manage_acl']
        ];
    } elseif ($play['visibility'] === 'public') {
        // Public plays: any authenticated user can view and edit
        $my_permissions['can_view'] = true;
        $my_permissions['can_manage'] = true;
    }
}

// Determine what ACL data to return
$acl = [];
if ($can_manage_acl) {
    // Owner / admin / ACL manager: return full list
    $acl_stmt = $conn_sqli->prepare("SELECT cid, can_view, can_manage, can_manage_acl, added_by, created_at, updated_at FROM playbook_play_acl WHERE play_id = ? ORDER BY created_at ASC");
    $acl_stmt->bind_param('i', $play_id);
    $acl_stmt->execute();
    $result = $acl_stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $row['cid'] = (int)$row['cid'];
        $row['can_view'] = (int)$row['can_view'];
        $row['can_manage'] = (int)$row['can_manage'];
        $row['can_manage_acl'] = (int)$row['can_manage_acl'];
        $acl[] = $row;
    }
    $acl_stmt->close();
} elseif ($session_cid !== null) {
    // Non-manager: return only their own entry (if any)
    $own_stmt = $conn_sqli->prepare("SELECT cid, can_view, can_manage, can_manage_acl, added_by, created_at, updated_at FROM playbook_play_acl WHERE play_id = ? AND cid = ?");
    $own_cid = (int)$session_cid;
    $own_stmt->bind_param('ii', $play_id, $own_cid);
    $own_stmt->execute();
    $own_row = $own_stmt->get_result()->fetch_assoc();
    $own_stmt->close();

    if ($own_row) {
        $own_row['cid'] = (int)$own_row['cid'];
        $own_row['can_view'] = (int)$own_row['can_view'];
        $own_row['can_manage'] = (int)$own_row['can_manage'];
        $own_row['can_manage_acl'] = (int)$own_row['can_manage_acl'];
        $acl[] = $own_row;
    }
}

echo json_encode([
    'success' => true,
    'play_id' => (int)$play_id,
    'visibility' => $play['visibility'],
    'is_owner' => $is_owner,
    'my_permissions' => $my_permissions,
    'acl' => $acl,
    'acl_count' => count($acl)
]);
