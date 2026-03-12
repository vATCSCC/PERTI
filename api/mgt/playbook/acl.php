<?php
/**
 * Playbook ACL Management API
 * POST — Manage access control list entries for private plays.
 *
 * Actions (JSON body):
 *   list      — Return all ACL entries for a play
 *   add       — Add a CID to the ACL
 *   update    — Update permissions for an existing ACL entry
 *   remove    — Remove a CID from the ACL
 *   bulk_add  — Add multiple CIDs at once
 */

include_once(dirname(__DIR__, 3) . '/sessions/handler.php');
// handler.php already includes config.php and input.php via include_once
define('PERTI_MYSQL_ONLY', true);
include_once(dirname(__DIR__, 3) . '/load/connect.php');
include_once(dirname(__DIR__, 3) . '/load/playbook_visibility.php');

header('Content-Type: application/json');

// Auth
$perm = false;
if (!defined('DEV')) {
    if (isset($_SESSION['VATSIM_CID'])) {
        $cid = session_get('VATSIM_CID', '');
        $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");
        if ($p_check) {
            $perm = true;
        }
    }
} else {
    $perm = true;
    $cid = '0';
}

if (!$perm) {
    http_response_code(403);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

$action  = $body['action'] ?? '';
$play_id = (int)($body['play_id'] ?? 0);

if ($play_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'play_id is required']);
    exit;
}

// Fetch the play
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

$changed_by = session_get('VATSIM_CID', '0');

// --- LIST ---
if ($action === 'list') {
    // Requires owner or can_manage_acl
    if (!can_manage_acl_play($play, $conn_sqli)) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not have permission to view the ACL for this play']);
        exit;
    }

    $acl_stmt = $conn_sqli->prepare("SELECT cid, can_view, can_manage, can_manage_acl, added_by, created_at, updated_at FROM playbook_play_acl WHERE play_id = ? ORDER BY created_at ASC");
    $acl_stmt->bind_param('i', $play_id);
    $acl_stmt->execute();
    $result = $acl_stmt->get_result();

    $entries = [];
    while ($row = $result->fetch_assoc()) {
        $row['cid'] = (int)$row['cid'];
        $row['can_view'] = (int)$row['can_view'];
        $row['can_manage'] = (int)$row['can_manage'];
        $row['can_manage_acl'] = (int)$row['can_manage_acl'];
        $entries[] = $row;
    }
    $acl_stmt->close();

    echo json_encode([
        'success' => true,
        'play_id' => (int)$play_id,
        'visibility' => $play['visibility'],
        'acl' => $entries,
        'acl_count' => count($entries)
    ]);
    exit;
}

// --- All remaining actions require private visibility ---
$visibility = $play['visibility'] ?? 'public';
if (!in_array($visibility, ['private_users', 'private_org'])) {
    http_response_code(400);
    echo json_encode(['error' => 'ACL management is only available for plays with private_users or private_org visibility']);
    exit;
}

// All remaining actions require can_manage_acl permission
if (!can_manage_acl_play($play, $conn_sqli)) {
    http_response_code(403);
    echo json_encode(['error' => 'You do not have permission to manage the ACL for this play']);
    exit;
}

// --- ADD ---
if ($action === 'add') {
    $target_cid = (int)($body['cid'] ?? 0);
    if ($target_cid <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'cid is required']);
        exit;
    }

    // Cannot add the owner (they have implicit full access)
    if ((string)$target_cid === (string)($play['created_by'] ?? '')) {
        http_response_code(400);
        echo json_encode(['error' => 'The play owner already has full access and does not need an ACL entry']);
        exit;
    }

    $can_view       = (int)($body['can_view'] ?? 1);
    $can_manage     = (int)($body['can_manage'] ?? 0);
    $can_manage_acl = (int)($body['can_manage_acl'] ?? 1);

    $ins_stmt = $conn_sqli->prepare("INSERT INTO playbook_play_acl (play_id, cid, can_view, can_manage, can_manage_acl, added_by) VALUES (?, ?, ?, ?, ?, ?)");
    $ins_stmt->bind_param('iiiiis', $play_id, $target_cid, $can_view, $can_manage, $can_manage_acl, $changed_by);

    if (!$ins_stmt->execute()) {
        // Duplicate key means CID already in the ACL
        if ($conn_sqli->errno === 1062) {
            $ins_stmt->close();
            http_response_code(409);
            echo json_encode(['error' => 'CID is already in the ACL for this play']);
            exit;
        }
        $ins_stmt->close();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to add ACL entry']);
        exit;
    }
    $ins_stmt->close();

    // Log to changelog
    $detail = json_encode(['cid' => $target_cid, 'can_view' => $can_view, 'can_manage' => $can_manage, 'can_manage_acl' => $can_manage_acl]);
    $cl_stmt = $conn_sqli->prepare("INSERT INTO playbook_changelog (play_id, action, field_name, new_value, changed_by) VALUES (?, 'acl_added', 'acl', ?, ?)");
    $cl_stmt->bind_param('iss', $play_id, $detail, $changed_by);
    $cl_stmt->execute();
    $cl_stmt->close();

    echo json_encode([
        'success' => true,
        'play_id' => (int)$play_id,
        'cid' => $target_cid,
        'action' => 'add'
    ]);
    exit;
}

// --- UPDATE ---
if ($action === 'update') {
    $target_cid = (int)($body['cid'] ?? 0);
    if ($target_cid <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'cid is required']);
        exit;
    }

    // Fetch existing ACL entry
    $check_stmt = $conn_sqli->prepare("SELECT can_view, can_manage, can_manage_acl FROM playbook_play_acl WHERE play_id = ? AND cid = ?");
    $check_stmt->bind_param('ii', $play_id, $target_cid);
    $check_stmt->execute();
    $existing = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();

    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'ACL entry not found for this CID']);
        exit;
    }

    $can_view       = (int)($body['can_view'] ?? $existing['can_view']);
    $can_manage     = (int)($body['can_manage'] ?? $existing['can_manage']);
    $can_manage_acl = (int)($body['can_manage_acl'] ?? $existing['can_manage_acl']);

    $upd_stmt = $conn_sqli->prepare("UPDATE playbook_play_acl SET can_view = ?, can_manage = ?, can_manage_acl = ? WHERE play_id = ? AND cid = ?");
    $upd_stmt->bind_param('iiiii', $can_view, $can_manage, $can_manage_acl, $play_id, $target_cid);
    $upd_stmt->execute();
    $upd_stmt->close();

    // Log to changelog
    $old_detail = json_encode(['cid' => $target_cid, 'can_view' => (int)$existing['can_view'], 'can_manage' => (int)$existing['can_manage'], 'can_manage_acl' => (int)$existing['can_manage_acl']]);
    $new_detail = json_encode(['cid' => $target_cid, 'can_view' => $can_view, 'can_manage' => $can_manage, 'can_manage_acl' => $can_manage_acl]);
    $cl_stmt = $conn_sqli->prepare("INSERT INTO playbook_changelog (play_id, action, field_name, old_value, new_value, changed_by) VALUES (?, 'acl_updated', 'acl', ?, ?, ?)");
    $cl_stmt->bind_param('isss', $play_id, $old_detail, $new_detail, $changed_by);
    $cl_stmt->execute();
    $cl_stmt->close();

    echo json_encode([
        'success' => true,
        'play_id' => (int)$play_id,
        'cid' => $target_cid,
        'action' => 'update'
    ]);
    exit;
}

// --- REMOVE ---
if ($action === 'remove') {
    $target_cid = (int)($body['cid'] ?? 0);
    if ($target_cid <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'cid is required']);
        exit;
    }

    // Cannot remove the owner
    if ((string)$target_cid === (string)($play['created_by'] ?? '')) {
        http_response_code(400);
        echo json_encode(['error' => 'Cannot remove the play owner from the ACL']);
        exit;
    }

    // Verify the entry exists before deleting (for accurate response)
    $check_stmt = $conn_sqli->prepare("SELECT can_view, can_manage, can_manage_acl FROM playbook_play_acl WHERE play_id = ? AND cid = ?");
    $check_stmt->bind_param('ii', $play_id, $target_cid);
    $check_stmt->execute();
    $existing = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();

    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'ACL entry not found for this CID']);
        exit;
    }

    $del_stmt = $conn_sqli->prepare("DELETE FROM playbook_play_acl WHERE play_id = ? AND cid = ?");
    $del_stmt->bind_param('ii', $play_id, $target_cid);
    $del_stmt->execute();
    $del_stmt->close();

    // Log to changelog
    $old_detail = json_encode(['cid' => $target_cid, 'can_view' => (int)$existing['can_view'], 'can_manage' => (int)$existing['can_manage'], 'can_manage_acl' => (int)$existing['can_manage_acl']]);
    $cl_stmt = $conn_sqli->prepare("INSERT INTO playbook_changelog (play_id, action, field_name, old_value, changed_by) VALUES (?, 'acl_removed', 'acl', ?, ?)");
    $cl_stmt->bind_param('iss', $play_id, $old_detail, $changed_by);
    $cl_stmt->execute();
    $cl_stmt->close();

    echo json_encode([
        'success' => true,
        'play_id' => (int)$play_id,
        'cid' => $target_cid,
        'action' => 'remove'
    ]);
    exit;
}

// --- BULK_ADD ---
if ($action === 'bulk_add') {
    $cids = $body['cids'] ?? [];
    if (!is_array($cids) || empty($cids)) {
        http_response_code(400);
        echo json_encode(['error' => 'cids array is required and must not be empty']);
        exit;
    }

    $can_view       = (int)($body['can_view'] ?? 1);
    $can_manage     = (int)($body['can_manage'] ?? 0);
    $can_manage_acl = (int)($body['can_manage_acl'] ?? 1);
    $owner_cid      = (string)($play['created_by'] ?? '');

    $ins_stmt = $conn_sqli->prepare("INSERT IGNORE INTO playbook_play_acl (play_id, cid, can_view, can_manage, can_manage_acl, added_by) VALUES (?, ?, ?, ?, ?, ?)");

    $added = [];
    $skipped = [];

    foreach ($cids as $target_cid) {
        $target_cid = (int)$target_cid;
        if ($target_cid <= 0) continue;

        // Skip the owner
        if ((string)$target_cid === $owner_cid) {
            $skipped[] = $target_cid;
            continue;
        }

        $ins_stmt->bind_param('iiiiis', $play_id, $target_cid, $can_view, $can_manage, $can_manage_acl, $changed_by);
        $ins_stmt->execute();

        if ($ins_stmt->affected_rows > 0) {
            $added[] = $target_cid;
        } else {
            $skipped[] = $target_cid;
        }
    }
    $ins_stmt->close();

    // Log to changelog (single entry for the bulk operation)
    if (!empty($added)) {
        $detail = json_encode(['cids' => $added, 'can_view' => $can_view, 'can_manage' => $can_manage, 'can_manage_acl' => $can_manage_acl]);
        $cl_stmt = $conn_sqli->prepare("INSERT INTO playbook_changelog (play_id, action, field_name, new_value, changed_by) VALUES (?, 'acl_added', 'acl_bulk', ?, ?)");
        $cl_stmt->bind_param('iss', $play_id, $detail, $changed_by);
        $cl_stmt->execute();
        $cl_stmt->close();
    }

    echo json_encode([
        'success' => true,
        'play_id' => (int)$play_id,
        'added' => $added,
        'skipped' => $skipped,
        'action' => 'bulk_add'
    ]);
    exit;
}

// Unknown action
http_response_code(400);
echo json_encode(['error' => 'Invalid action. Use: list, add, update, remove, bulk_add']);
