<?php

// Include session handler (validates SELF cookie and populates session)
include_once(dirname(__DIR__, 3) . '/sessions/handler.php');

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

$event_name = strip_tags(html_entity_decode(str_replace("`", "&#039;", $_POST['event_name'])));
$event_date = post_input('event_date');
$event_start = post_input('event_start');
$event_end_date = post_input('event_end_date');
$event_end_time = post_input('event_end_time');
$event_banner = post_input('event_banner');
$oplevel = post_input('oplevel');
$hotline = post_input('hotline');

// Insert Data into Database
$query = $conn_sqli->query("UPDATE p_plans SET event_name='$event_name', event_date='$event_date', event_start='$event_start', event_end_date='$event_end_date', event_end_time='$event_end_time', event_banner='$event_banner', oplevel='$oplevel', hotline='$hotline' WHERE id=$id");

if ($query) {
    http_response_code('200');
} else {
    http_response_code('500');
}

?>
