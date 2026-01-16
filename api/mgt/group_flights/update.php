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

$id = post_input('id');

$entity = strip_tags(html_entity_decode(str_replace("`", "&#039;", $_POST['entity'])));
$dep = post_input('dep');
$arr = post_input('arr');
$etd = post_input('etd');
$eta = post_input('eta');
$pilot_quantity = post_input('pilot_quantity');
$route = strip_tags(html_entity_decode(str_replace("`", "&#039;", $_POST['route'])));

// Insert Data into Database
$query = $conn_sqli->query("UPDATE p_group_flights SET entity='$entity', dep='$dep', arr='$arr', etd='$etd', eta='$eta', pilot_quantity='$pilot_quantity', route='$route' WHERE id=$id");

if ($query) {
    http_response_code('200');
} else {
    http_response_code('500');
}

?>