<?php

// Session Start (S)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
  }
// Session Start (E)

include("../../../load/config.php");
include("../../../load/connect.php");

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

$p_id = strip_tags($_GET['p_id']);

$c_q = $conn_sqli->query("SELECT COUNT(*) AS 'total' FROM p_terminal_constraints WHERE p_id='$p_id'")->fetch_assoc();


if ($c_q['total'] > 0) {
    $query = $conn_sqli->query("SELECT * FROM p_terminal_constraints WHERE p_id='$p_id'");

    while ($data = mysqli_fetch_array($query)) {
        echo '<tr>';

            echo '<td class="text-center" style="width: 40%;">'.$data['location'].' - '.$data['context'].'</td>';
            echo '<td class="text-center" style="width: 20%;">Through '.$data['date'].'</td>';
            echo '<td class="text-center">'.$data['impact'].'</td>';
    
            if ($perm == true) {
                echo '<td style="width: 10%;"><center>';
                    echo '<a href="javascript:void(0)" data-toggle="tooltip" title="Edit Constraint"><span class="badge badge-warning" data-toggle="modal" data-target="#edittermconstraintModal" data-id="'.$data['id'].'" data-location="'.$data['location'].'" data-context="'.$data['context'].'" data-date="'.$data['date'].'" data-impact="'.$data['impact'].'">
                    <i class="fas fa-pencil-alt"></i> Edit</span></a>';
                    echo ' ';
                    echo '<a href="javascript:void(0)" onclick="deleteTermConstraint('.$data['id'].')" data-toggle="tooltip" title="Delete Constraint"><span class="badge badge-danger"><i class="fas fa-times"></i> Delete</span></a>';
                echo '</center></td>';
            }
    
        echo '</tr>';
    }
} else {
    echo '<tr><td class="text-center" colspan="4">No Constraints Filed</td></tr>';
}


?>


