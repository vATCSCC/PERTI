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

// Insert Data into Database
try {

    // Begin Transaction
    $conn_pdo->beginTransaction();

    // SQL Query
    $sql = "INSERT INTO users (cid, first_name, last_name)
    VALUES ($n_cid, '$first_name', '$last_name')";

    $conn_pdo->exec($sql);

    // Create org memberships
    $assigner_cid = intval($_SESSION['VATSIM_CID'] ?? 0);
    $is_first = true;
    foreach ($orgs as $org) {
        $org = preg_replace('/[^a-z]/', '', $org);
        $is_primary = $is_first ? 1 : 0;
        $stmt = mysqli_prepare($conn_sqli, "INSERT IGNORE INTO user_orgs (cid, org_code, is_privileged, is_primary, assigned_by) VALUES (?, ?, 0, ?, ?)");
        mysqli_stmt_bind_param($stmt, "isii", $n_cid, $org, $is_primary, $assigner_cid);
        mysqli_stmt_execute($stmt);
        $is_first = false;
    }

    $conn_pdo->commit();
    http_response_code(200);
}

catch (PDOException $e) {
    $conn_pdo->rollback();
    http_response_code(500);
}

?>