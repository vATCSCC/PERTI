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

$p_id = get_input('p_id');

$c_q = $conn_sqli->query("SELECT COUNT(*) AS 'total' FROM p_op_goals WHERE p_id='$p_id'")->fetch_assoc();

if ($c_q['total'] > 0) {
    $query = $conn_sqli->query("SELECT * FROM p_op_goals WHERE p_id='$p_id'");

    while ($data = mysqli_fetch_array($query)) {
        echo '<tr>';
            echo '<td>'.$data['comments'].'</td>';
    
            if ($perm == true) {
                echo '<td class="w-25"><center>';
                    echo '<a href="javascript:void(0)" data-toggle="tooltip" title="Edit Goal"><span class="badge badge-warning" data-toggle="modal" data-target="#editgoalModal" data-id="'.$data['id'].'" data-comments="'.$data['comments'].'">
                        <i class="fas fa-pencil-alt"></i> Edit</span></a>';
                    echo ' ';
                    echo '<a href="javascript:void(0)" onclick="deleteGoal('.$data['id'].')" data-toggle="tooltip" title="Delete Goal"><span class="badge badge-danger"><i class="fas fa-times"></i> Delete</span></a>';
                echo '</center></td>';
            }
    
        echo '</tr>';
    }
} else {
    echo '<h5 class="text-center">No Operational Goals</h5>';
}


?>