<?php

// Session Start (S)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}
// Session Start (E)

include("../../../load/config.php");
define('PERTI_MYSQL_ONLY', true);
include("../../../load/connect.php");

// Check Perms
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
}

if (!$perm) {
    http_response_code(403);
    exit();
}

header('Content-Type: application/json');

$target_cid = intval($_POST['cid'] ?? 0);
$org_code = $_POST['org_code'] ?? '';
$action = $_POST['action'] ?? '';

if ($target_cid <= 0 || !in_array($action, ['add', 'remove']) || $org_code === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
    exit();
}

// Validate org_code exists
$stmt = mysqli_prepare($conn_sqli, "SELECT org_code FROM organizations WHERE org_code = ? AND is_active = 1");
mysqli_stmt_bind_param($stmt, "s", $org_code);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
if (mysqli_num_rows($result) === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid organization']);
    exit();
}

// Validate target user exists
$stmt = mysqli_prepare($conn_sqli, "SELECT cid FROM users WHERE cid = ?");
mysqli_stmt_bind_param($stmt, "i", $target_cid);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
if (mysqli_num_rows($result) === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'User not found']);
    exit();
}

$assigner_cid = intval($_SESSION['VATSIM_CID'] ?? 0);

if ($action === 'add') {
    $stmt = mysqli_prepare($conn_sqli, "INSERT IGNORE INTO user_orgs (cid, org_code, is_privileged, is_primary, assigned_by) VALUES (?, ?, 0, 0, ?)");
    mysqli_stmt_bind_param($stmt, "isi", $target_cid, $org_code, $assigner_cid);
    mysqli_stmt_execute($stmt);
    echo json_encode(['success' => true, 'action' => 'added', 'cid' => $target_cid, 'org_code' => $org_code]);
} else {
    // Don't allow removing the last org membership
    $stmt = mysqli_prepare($conn_sqli, "SELECT COUNT(*) as cnt FROM user_orgs WHERE cid = ?");
    mysqli_stmt_bind_param($stmt, "i", $target_cid);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $count = mysqli_fetch_assoc($result)['cnt'];

    if ($count <= 1) {
        http_response_code(400);
        echo json_encode(['error' => 'Cannot remove last organization membership']);
        exit();
    }

    $stmt = mysqli_prepare($conn_sqli, "DELETE FROM user_orgs WHERE cid = ? AND org_code = ?");
    mysqli_stmt_bind_param($stmt, "is", $target_cid, $org_code);
    mysqli_stmt_execute($stmt);
    echo json_encode(['success' => true, 'action' => 'removed', 'cid' => $target_cid, 'org_code' => $org_code]);
}
