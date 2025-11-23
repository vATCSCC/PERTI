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

$p_cid = strip_tags($_POST['p_cid']);
$e_cid = strip_tags($_POST['e_cid']);
$r_cid = strip_tags($_POST['r_cid']);
$t_cid = strip_tags($_POST['t_cid']);
$i_cid = strip_tags($_POST['i_cid']);

// Insert Data into Database
$query = $conn_sqli->query("UPDATE assigned SET p_cid=$p_cid, e_cid=$e_cid, r_cid=$r_cid, t_cid=$t_cid, i_cid=$i_cid WHERE id=$id");

if ($query) {
    http_response_code('200');
} else {
    http_response_code('500');
}

?>