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

$domain = strip_tags(SITE_DOMAIN);

// Check Perms
$perm = false;
if (!defined('DEV')) {
    if (isset($_SESSION['VATSIM_CID'])) {

        // Getting CID Value
        $cid = session_get('VATSIM_CID', '');

        $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");

        if ($p_check) {
            $perm = true;
        }

    }
} else {
    $perm = true;
    $_SESSION['VATSIM_FIRST_NAME'] = $_SESSION['VATSIM_LAST_NAME'] = $_SESSION['VATSIM_CID'] = 0;
}

// Check Perms (S)
if ($perm == true) {
    // Do Nothing
} else {
    http_response_code(403);
    exit();
}
// (E)

$first_name = strip_tags(html_entity_decode(str_replace("`", "&#039;", $_POST['first_name'])));
$last_name = strip_tags(html_entity_decode(str_replace("`", "&#039;", $_POST['last_name'])));
$n_cid = post_input('cid');

// Org memberships from form checkboxes (defaults to vatcscc if none selected)
$orgs = $_POST['orgs'] ?? ['vatcscc'];
if (!is_array($orgs) || empty($orgs)) {
    $orgs = ['vatcscc'];
}

// Validate org codes exist in DB
$valid_orgs_q = $conn_sqli->query("SELECT org_code FROM organizations WHERE is_active = 1");
$valid_orgs = [];
while ($vr = $valid_orgs_q->fetch_assoc()) { $valid_orgs[] = $vr['org_code']; }
$orgs = array_filter($orgs, function($o) use ($valid_orgs) {
    return in_array(preg_replace('/[^a-z]/', '', $o), $valid_orgs);
});
if (empty($orgs)) {
    $orgs = ['vatcscc'];
}

// Insert Data into Database
try {

    // Begin Transaction
    $conn_pdo->beginTransaction();

    // SQL Query (prepared statement)
    $stmt_user = $conn_pdo->prepare("INSERT INTO users (cid, first_name, last_name, last_session_ip, last_selfcookie) VALUES (?, ?, ?, '', '')");
    $stmt_user->execute([$n_cid, $first_name, $last_name]);

    // Create org memberships
    $assigner_cid = intval($_SESSION['VATSIM_CID'] ?? 0);
    $is_first = true;
    foreach ($orgs as $org) {
        $org = preg_replace('/[^a-z]/', '', $org);
        $is_primary = $is_first ? 1 : 0;
        $stmt = mysqli_prepare($conn_sqli, "INSERT IGNORE INTO user_orgs (cid, org_code, is_privileged, is_primary, assigned_by) VALUES (?, ?, 1, ?, ?)");
        mysqli_stmt_bind_param($stmt, "isii", $n_cid, $org, $is_primary, $assigner_cid);
        mysqli_stmt_execute($stmt);
        $is_first = false;
    }

    $conn_pdo->commit();
    http_response_code(200);
}

catch (\Throwable $e) {
    if ($conn_pdo->inTransaction()) {
        $conn_pdo->rollback();
    }
    error_log("Personnel POST error: " . $e->getMessage());
    http_response_code(500);
}

?>