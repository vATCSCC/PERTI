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

$rows = [];
$query = $conn_sqli->query("SELECT * FROM p_configs WHERE p_id='$p_id'");

if ($query) {
    while ($data = mysqli_fetch_assoc($query)) {
        $row = [
            'id' => (int)$data['id'],
            'airport' => $data['airport'],
            'weather' => (int)$data['weather'],
            'arrive' => $data['arrive'],
            'depart' => $data['depart'],
            'aar' => $data['aar'],
            'adr' => $data['adr'],
            'comments' => $data['comments'],
            'has_autofill' => false,
            'autofill_aar' => 0,
            'autofill_adr' => 0,
        ];

        if ($perm) {
            $c_fc = $conn_sqli->query("SELECT COUNT(*) AS 'total', vmc_aar, lvmc_aar, imc_aar, limc_aar, vmc_adr, imc_adr FROM config_data WHERE airport='{$data['airport']}' AND arr='{$data['arrive']}' AND dep='{$data['depart']}'")->fetch_assoc();
            if ($c_fc['total'] > 0) {
                $row['has_autofill'] = true;
                if ($data['weather'] == 1) {
                    $row['autofill_aar'] = $c_fc['vmc_aar'];
                    $row['autofill_adr'] = $c_fc['vmc_adr'];
                } elseif ($data['weather'] == 2) {
                    $row['autofill_aar'] = $c_fc['lvmc_aar'];
                    $row['autofill_adr'] = $c_fc['vmc_adr'];
                } elseif ($data['weather'] == 3) {
                    $row['autofill_aar'] = $c_fc['imc_aar'];
                    $row['autofill_adr'] = $c_fc['imc_adr'];
                } elseif ($data['weather'] == 4) {
                    $row['autofill_aar'] = $c_fc['limc_aar'];
                    $row['autofill_adr'] = $c_fc['imc_adr'];
                }
            }
        }

        $rows[] = $row;
    }
}

echo json_encode(['perm' => $perm, 'rows' => $rows]);
