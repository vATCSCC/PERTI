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

$id = strip_tags($_REQUEST['id']);

// Hardcoded protected CID - always allowed, cannot be deleted
$protected_cid = '1234727';

// Check if the user being deleted has the protected CID
$check_protected = $conn_sqli->query("SELECT cid FROM users WHERE id=$id");
if ($check_protected && $check_protected->num_rows > 0) {
    $user_row = $check_protected->fetch_assoc();
    if ($user_row['cid'] == $protected_cid) {
        http_response_code(403);
        echo json_encode(['error' => 'This system personnel cannot be deleted.']);
        exit();
    }
}

// Insert Data into Database
$query = $conn_sqli->multi_query("DELETE FROM users WHERE id=$id");

if ($query) {
    http_response_code('200');
} else {
    http_response_code('500');
}

?>