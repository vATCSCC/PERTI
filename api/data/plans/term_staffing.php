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

$c_q = $conn_sqli->query("SELECT COUNT(*) AS 'total' FROM p_terminal_staffing WHERE p_id='$p_id'")->fetch_assoc();


if ($c_q['total'] > 0) {
    $query = $conn_sqli->query("SELECT * FROM p_terminal_staffing WHERE p_id='$p_id'");

    while ($data = mysqli_fetch_array($query)) {
        echo '<tr>';

            echo '<td class="text-center" style="width: 40%;">'.$data['facility_name'].'</td>';

            if ($data['staffing_status'] == 0) {
                echo '<td class="text-center" style="width: 10%; background-color: #FFFF5C;">Unknown</td>';
            } elseif ($data['staffing_status'] == 1) {
                echo '<td class="text-center" style="width: 10%; background-color: #00D100;">Top Down</td>'; 
            } elseif ($data['staffing_status'] == 2) {
                echo '<td class="text-center" style="width: 10%; background-color: #5CFF5C;">Yes</td>'; 
            } elseif ($data['staffing_status'] == 3) {
                echo '<td class="text-center" style="width: 10%; background-color: #FFCD00;">Understaffed</td>'; 
            } else {
                echo '<td class="text-center bg-danger" style="width: 10%;">No</td>';  
            }

            echo '<td class="text-center" style="width: 10%;">'.$data['staffing_quantity'].'</td>';
            echo '<td class="text-center">'.$data['comments'].'</td>';
    
            if ($perm == true) {
                echo '<td style="width: 10%;"><center>';
                    echo '<a href="javascript:void(0)" data-toggle="tooltip" title="Edit Staffing"><span class="badge badge-warning" data-toggle="modal" data-target="#edittermstaffingModal" data-id="'.$data['id'].'" data-facility_name="'.$data['facility_name'].'" data-staffing_status="'.$data['staffing_status'].'" data-staffing_quantity="'.$data['staffing_quantity'].'" data-comments="'.$data['comments'].'">
                        <i class="fas fa-pencil-alt"></i> Edit</span></a>';
                    echo ' ';
                    echo '<a href="javascript:void(0)" onclick="deleteTermStaffing('.$data['id'].')" data-toggle="tooltip" title="Delete Staffing"><span class="badge badge-danger"><i class="fas fa-times"></i> Delete</span></a>';
                echo '</center></td>';
            }
    
        echo '</tr>';
    }
} else {
    echo '<tr><td class="text-center" colspan="5">No Staffing Filed</td></tr>';
}


?>