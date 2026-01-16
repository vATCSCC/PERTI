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

$id = post_input('id');

$init_s = strip_tags(str_replace("`", "&#039;", $_POST['staffing']), "<br><strong><b><i><em><ul><ol><li><img><table><td><tr><th><a><u>");
$staffing = preg_replace("#<br\s*/?>#i", "<br>", str_replace('"', "&quot;", $init_s));

$init_t = strip_tags(str_replace("`", "&#039;", $_POST['tactical']), "<br><strong><b><i><em><ul><ol><li><img><table><td><tr><th><a><u>");
$tactical = preg_replace("#<br\s*/?>#i", "<br>", str_replace('"', "&quot;", $init_t));

$init_o = strip_tags(str_replace("`", "&#039;", $_POST['other']), "<br><strong><b><i><em><ul><ol><li><img><table><td><tr><th><a><u>");
$other = preg_replace("#<br\s*/?>#i", "<br>", str_replace('"', "&quot;", $init_o));

$init_p = strip_tags(str_replace("`", "&#039;", $_POST['perti']), "<br><strong><b><i><em><ul><ol><li><img><table><td><tr><th><a><u>");
$perti = preg_replace("#<br\s*/?>#i", "<br>", str_replace('"', "&quot;", $init_p));

$init_n = strip_tags(str_replace("`", "&#039;", $_POST['ntml']), "<br><strong><b><i><em><ul><ol><li><img><table><td><tr><th><a><u>");
$ntml = preg_replace("#<br\s*/?>#i", "<br>", str_replace('"', "&quot;", $init_n));

$init_m = strip_tags(str_replace("`", "&#039;", $_POST['tmi']), "<br><strong><b><i><em><ul><ol><li><img><table><td><tr><th><a><u>");
$tmi = preg_replace("#<br\s*/?>#i", "<br>", str_replace('"', "&quot;", $init_m));

$init_a = strip_tags(str_replace("`", "&#039;", $_POST['perti']), "<br><strong><b><i><em><ul><ol><li><img><table><td><tr><th><a><u>");
$ace = preg_replace("#<br\s*/?>#i", "<br>", str_replace('"', "&quot;", $init_a));

// Insert Data into Database
$query = $conn_sqli->query("UPDATE r_comments SET staffing='$staffing', tactical='$tactical', other='$other', ntml='$ntml', tmi='$tmi', ace='$ace' WHERE id=$id");

if ($query) {
    http_response_code('200');
} else {
    http_response_code('500');
}

?>