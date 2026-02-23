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

$p_id = get_input('p_id');

require_once(dirname(__DIR__, 3) . '/load/org_context.php');
if (!validate_plan_org((int)$p_id, $conn_sqli)) {
    http_response_code(403);
    exit();
}

header('Content-Type: application/json');

if (isset($_GET['position_facility'])) {
    $query = $conn_sqli->query("SELECT * FROM p_dcc_staffing WHERE p_id='$p_id' AND position_facility IN ('DCC','CANOC','ECFMP','CTP','WF')");
} else {
    $query = $conn_sqli->query("SELECT * FROM p_dcc_staffing WHERE p_id='$p_id' AND position_facility NOT IN ('DCC','CANOC','ECFMP','CTP','WF')");
}

$rows = [];
if ($query) {
    while ($data = mysqli_fetch_assoc($query)) {
        $rows[] = [
            'id' => (int)$data['id'],
            'position_facility' => $data['position_facility'],
            'position_name' => $data['position_name'],
            'personnel_name' => $data['personnel_name'],
            'personnel_ois' => $data['personnel_ois'],
        ];
    }
}

echo json_encode(['perm' => $perm, 'rows' => $rows]);
