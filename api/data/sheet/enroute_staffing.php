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

$p_id = get_input('p_id');

$c_q = $conn_sqli->query("SELECT COUNT(*) AS 'total' FROM p_enroute_staffing WHERE p_id='$p_id'")->fetch_assoc();


if ($c_q['total'] > 0) {
    $query = $conn_sqli->query("SELECT * FROM p_enroute_staffing WHERE p_id='$p_id'");

    while ($data = mysqli_fetch_array($query)) {
        echo '<tr>';

            echo '<td class="text-center" style="width: 40%;">'.$data['facility_name'].'</td>';

            if ($data['staffing_status'] == 0) {
                echo '<td class="text-center" style="width: 10%; background-color: #FFFF5C;">Unknown</td>';
            } elseif ($data['staffing_status'] == 1) {
                echo '<td class="text-center" style="width: 10%; background-color: #5CFF5C;">Yes</td>'; 
            } elseif ($data['staffing_status'] == 2) {
                echo '<td class="text-center" style="width: 10%; background-color: #FFCD00;">Understaffed</td>'; 
            } else {
                echo '<td class="text-center bg-danger" style="width: 10%;">No</td>';  
            }

            echo '<td class="text-center" style="width: 10%;">'.$data['staffing_quantity'].'</td>';
            echo '<td class="text-center">'.$data['comments'].'</td>';
    
            echo '<td style="width: 10%;"><center>';
                echo '<a href="javascript:void(0)" data-toggle="tooltip" title="Edit Staffing"><span class="badge badge-warning" data-toggle="modal" data-target="#editenroutestaffingModal" data-id="'.$data['id'].'" data-facility_name="'.$data['facility_name'].'" data-staffing_status="'.$data['staffing_status'].'" data-staffing_quantity="'.$data['staffing_quantity'].'" data-comments="'.$data['comments'].'">
                    <i class="fas fa-pencil-alt"></i> Edit</span></a>';
            echo '</center></td>';
    
        echo '</tr>';
    }
} else {
    echo '<tr><td class="text-center" colspan="5">No Staffing Filed</td></tr>';
}


?>