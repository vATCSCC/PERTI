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

$id = post_int('id');

$p_cid = post_int('p_cid');
$e_cid = post_int('e_cid');
$r_cid = post_int('r_cid');
$t_cid = post_int('t_cid');
$i_cid = post_int('i_cid');

// Update Data using prepared statement to prevent SQL injection
$stmt = $conn_sqli->prepare("UPDATE assigned SET p_cid=?, e_cid=?, r_cid=?, t_cid=?, i_cid=? WHERE id=?");
$stmt->bind_param("iiiiii", $p_cid, $e_cid, $r_cid, $t_cid, $i_cid, $id);
$query = $stmt->execute();

if ($query) {
    http_response_code('200');
} else {
    http_response_code('500');
}

?>