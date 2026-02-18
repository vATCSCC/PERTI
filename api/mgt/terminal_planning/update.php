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

$id = post_input('id');

$facility_name = strip_tags(html_entity_decode(str_replace("`", "&#039;", $_POST['facility_name'])));

$init_c = strip_tags(str_replace("`", "&#039;", $_POST['comments']), "<br><strong><b><i><em><ul><ol><li><img><table><td><tr><th><a><u>");
$comments = preg_replace("#<br\s*/?>#i", "<br>", str_replace('"', "&quot;", $init_c));

// Update Data in Database (prepared statement)
$stmt = $conn_sqli->prepare("UPDATE p_terminal_planning SET facility_name=?, comments=? WHERE id=?");
$stmt->bind_param("ssi", $facility_name, $comments, $id);

if ($stmt->execute()) {
    http_response_code(200);
} else {
    error_log("terminal_planning/update error: " . $stmt->error);
    http_response_code(500);
}
$stmt->close();

?>