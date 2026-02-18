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

$id = isset($_REQUEST['id']) ? intval($_REQUEST['id']) : 0;

require_once(dirname(__DIR__, 3) . '/load/org_context.php');
if (!validate_plan_org($id, $conn_sqli)) {
    http_response_code(403);
    exit();
}
$org = get_org_code();

// Delete plan and all child records (prepared statements, validate_plan_org already checked access)
// Note: $id is already intval'd, $org comes from get_org_code() (session-controlled)
// Child tables don't have org_code column, so only p_plans needs the org filter
$tables = [
    ['p_plans', 'id'],
    ['p_configs', 'p_id'],
    ['p_dcc_staffing', 'p_id'],
    ['p_enroute_constraints', 'p_id'],
    ['p_enroute_init', 'p_id'],
    ['p_enroute_planning', 'p_id'],
    ['p_enroute_staffing', 'p_id'],
    ['p_forecast', 'p_id'],
    ['p_group_flights', 'p_id'],
    ['p_historical', 'p_id'],
    ['p_op_goals', 'p_id'],
    ['p_terminal_constraints', 'p_id'],
    ['p_terminal_init', 'p_id'],
    ['p_terminal_planning', 'p_id'],
    ['p_terminal_staffing', 'p_id'],
];

$success = true;
foreach ($tables as $tbl) {
    $table = $tbl[0];
    $col = $tbl[1];
    if ($table === 'p_plans' && !is_org_global()) {
        $stmt = $conn_sqli->prepare("DELETE FROM p_plans WHERE id=? AND (org_code=? OR org_code IS NULL)");
        $stmt->bind_param("is", $id, $org);
    } else {
        $stmt = $conn_sqli->prepare("DELETE FROM `$table` WHERE `$col`=?");
        $stmt->bind_param("i", $id);
    }
    if (!$stmt->execute()) {
        error_log("perti/delete error on $table: " . $stmt->error);
        $success = false;
    }
    $stmt->close();
}

if ($success) {
    http_response_code(200);
} else {
    http_response_code(500);
}

?>