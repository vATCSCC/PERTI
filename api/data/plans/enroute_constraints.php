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

$p_id = intval(get_input('p_id'));

require_once(dirname(__DIR__, 3) . '/load/org_context.php');
if (!validate_plan_org((int)$p_id, $conn_sqli)) {
    http_response_code(403);
    exit();
}

$c_q = $conn_sqli->query("SELECT COUNT(*) AS 'total' FROM p_enroute_constraints WHERE p_id='$p_id'")->fetch_assoc();


if ($c_q['total'] > 0) {
    $query = $conn_sqli->query("SELECT * FROM p_enroute_constraints WHERE p_id='$p_id'");

    while ($data = mysqli_fetch_array($query)) {
        echo '<tr>';

            echo '<td class="text-center" style="width: 40%;">'.htmlspecialchars($data['location'], ENT_QUOTES, 'UTF-8').' - '.htmlspecialchars($data['context'], ENT_QUOTES, 'UTF-8').'</td>';
            echo '<td class="text-center" style="width: 20%;">Through '.htmlspecialchars($data['date'], ENT_QUOTES, 'UTF-8').'</td>';
            echo '<td class="text-center">'.htmlspecialchars($data['impact'], ENT_QUOTES, 'UTF-8').'</td>';

            if ($perm == true) {
                echo '<td style="width: 10%;"><center>';
                    echo '<a href="javascript:void(0)" data-toggle="tooltip" title="Edit Constraint"><span class="badge badge-warning" data-toggle="modal" data-target="#editenrouteconstraintModal" data-id="'.intval($data['id']).'" data-location="'.htmlspecialchars($data['location'], ENT_QUOTES, 'UTF-8').'" data-context="'.htmlspecialchars($data['context'], ENT_QUOTES, 'UTF-8').'" data-date="'.htmlspecialchars($data['date'], ENT_QUOTES, 'UTF-8').'" data-impact="'.htmlspecialchars($data['impact'], ENT_QUOTES, 'UTF-8').'">
                    <i class="fas fa-pencil-alt"></i> Edit</span></a>';
                    echo ' ';
                    echo '<a href="javascript:void(0)" onclick="deleteEnrouteConstraint('.intval($data['id']).')" data-toggle="tooltip" title="Delete Constraint"><span class="badge badge-danger"><i class="fas fa-times"></i> Delete</span></a>';
                echo '</center></td>';
            }
    
        echo '</tr>';
    }
} else {
    echo '<tr><td class="text-center" colspan="4">No Constraints Filed</td></tr>';
}


?>


