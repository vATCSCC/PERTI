<?php

// Include session handler (starts PHP session)
include_once(dirname(__DIR__, 3) . '/sessions/handler.php');

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

// Check org privilege
if (!is_org_privileged()) {
    http_response_code(403);
    exit();
}

$id = post_input('id');

require_once(dirname(__DIR__, 3) . '/load/org_context.php');
if (!validate_plan_org((int)$id, $conn_sqli)) {
    http_response_code(403);
    exit();
}
$org = get_org_code();

$event_name = strip_tags(html_entity_decode(str_replace("`", "&#039;", $_POST['event_name'])));
$event_date = post_input('event_date');
$event_start = post_input('event_start');
$event_end_date = post_input('event_end_date');
$event_end_time = post_input('event_end_time');
$event_banner = post_input('event_banner');
$oplevel = post_input('oplevel');
$hotline = post_input('hotline');
$org_code_raw = post_input('org_code');
$org_code = ($org_code_raw !== '' && $org_code_raw !== null) ? "'" . $conn_sqli->real_escape_string($org_code_raw) . "'" : 'NULL';

// Update Data in Database (validate_plan_org already checked access)
if (is_org_global()) {
    $query = $conn_sqli->query("UPDATE p_plans SET event_name='$event_name', event_date='$event_date', event_start='$event_start', event_end_date='$event_end_date', event_end_time='$event_end_time', event_banner='$event_banner', oplevel='$oplevel', hotline='$hotline', org_code=$org_code WHERE id=$id");
} else {
    $query = $conn_sqli->query("UPDATE p_plans SET event_name='$event_name', event_date='$event_date', event_start='$event_start', event_end_date='$event_end_date', event_end_time='$event_end_time', event_banner='$event_banner', oplevel='$oplevel', hotline='$hotline', org_code=$org_code WHERE id=$id AND (org_code='$org' OR org_code IS NULL)");
}

if ($query) {
    http_response_code('200');
} else {
    http_response_code('500');
}

?>
