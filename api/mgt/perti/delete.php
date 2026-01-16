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

$id = strip_tags($_REQUEST['id']);

// Insert Data into Database
$query = $conn_sqli->multi_query("DELETE FROM p_plans WHERE id=$id; 
                                DELETE FROM p_configs WHERE p_id=$id;
                                DELETE FROM p_dcc_staffing WHERE p_id=$id;
                                DELETE FROM p_enroute_constraints WHERE p_id=$id;
                                DELETE FROM p_enroute_init WHERE p_id=$id;
                                DELETE FROM p_enroute_planning WHERE p_id=$id;
                                DELETE FROM p_enroute_staffing WHERE p_id=$id;
                                DELETE FROM p_forecast WHERE p_id=$id;
                                DELETE FROM p_group_flights WHERE p_id=$id;
                                DELETE FROM p_historical WHERE p_id=$id;
                                DELETE FROM p_op_goals WHERE p_id=$id;
                                DELETE FROM p_terminal_constraints WHERE p_id=$id;
                                DELETE FROM p_terminal_init WHERE p_id=$id;
                                DELETE FROM p_terminal_planning WHERE p_id=$id;
                                DELETE FROM p_terminal_staffing WHERE p_id=$id");

if ($query) {
    http_response_code('200');
} else {
    http_response_code('500');
}

?>