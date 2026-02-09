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

$c_q = $conn_sqli->query("SELECT COUNT(*) AS 'total' FROM p_dcc_staffing WHERE p_id='$p_id' AND position_facility!='DCC' AND position_facility!='VATCAN' AND position_facility!='ECFMP'")->fetch_assoc();

if ($c_q['total'] > 0) {
    $query = $conn_sqli->query("SELECT * FROM p_dcc_staffing WHERE p_id='$p_id' AND position_facility!='DCC' AND position_facility!='VATCAN' AND position_facility!='ECFMP'");

    while ($data = mysqli_fetch_array($query)) {
        echo '<tr>';
            if (!isset($_GET['position_facility'])) {
                echo '<td class="text-center" style="width: 10%;">'.$data['position_facility'].'</td>';
            }

            echo '<td class="text-center" style="width: 10%;">'.$data['personnel_ois'].'</td>';
            echo '<td>'.$data['personnel_name'].'</td>';

            if (isset($_GET['position_facility'])) {
                echo '<td>'.$data['position_name'].'</td>';
            }
    
            echo '<td class="w-25"><center>';
                echo '<a href="javascript:void(0)" data-toggle="tooltip" title="Edit Personnel"><span class="badge badge-warning" data-toggle="modal" data-target="#edit_dccstaffingModal" data-id="'.$data['id'].'" data-personnel_name="'.$data['personnel_name'].'" data-personnel_ois="'.$data['personnel_ois'].'" data-position_name="'.$data['position_name'].'" data-position_facility="'.$data['position_facility'].'">
                    <i class="fas fa-pencil-alt"></i> Edit</span></a>';
            echo '</center></td>';
    
        echo '</tr>';
    }
} else {
    echo '<tr><td class="text-center" colspan="4">No Personnel Rostered</td></tr>';
}

?>