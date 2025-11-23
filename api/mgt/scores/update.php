<?php

// Session Start (S)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
  }
// Session Start (E)

include("../../../load/config.php");
include("../../../load/connect.php");

$domain = strip_tags(SITE_DOMAIN);

// Check Perms
$perm = false;
if (!defined('DEV')) {
    if (isset($_SESSION['VATSIM_CID'])) {

        // Getting CID Value
        $cid = strip_tags($_SESSION['VATSIM_CID']);

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

$id = strip_tags($_POST['id']);

$staffing = strip_tags($_POST['staffing']);
$tactical = strip_tags($_POST['tactical']);
$other = strip_tags($_POST['other']);
$perti = strip_tags($_POST['perti']);
$ntml = strip_tags($_POST['ntml']);
$tmi = strip_tags($_POST['tmi']);
$ace = strip_tags($_POST['ace']);

// Insert Data into Database
$query = $conn_sqli->query("UPDATE r_scores SET staffing='$staffing', tactical='$tactical', other='$other', perti='$perti', ntml='$ntml', tmi='$tmi', ace='$ace' WHERE id=$id");

if ($query) {
    http_response_code('200');
} else {
    http_response_code('500');
}

?>