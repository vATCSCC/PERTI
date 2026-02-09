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

if (isset($_GET['position_facility'])) {
    $c_q = $conn_sqli->query("SELECT COUNT(*) AS 'total' FROM p_dcc_staffing WHERE p_id='$p_id' AND position_facility='DCC' OR position_facility='VATCAN' OR position_facility='ECFMP'")->fetch_assoc();
} else {
    $c_q = $conn_sqli->query("SELECT COUNT(*) AS 'total' FROM p_dcc_staffing WHERE p_id='$p_id' AND position_facility!='DCC' AND position_facility!='VATCAN' AND position_facility!='ECFMP'")->fetch_assoc();
}


if ($c_q['total'] > 0) {
    if (isset($_GET['position_facility'])) {
        $query = $conn_sqli->query("SELECT * FROM p_dcc_staffing WHERE p_id='$p_id' AND position_facility='DCC' OR position_facility='VATCAN' OR position_facility='ECFMP'");
    } else {
        $query = $conn_sqli->query("SELECT * FROM p_dcc_staffing WHERE p_id='$p_id' AND position_facility!='DCC' AND position_facility!='VATCAN' AND position_facility!='ECFMP'");
    }

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
    
            if ($perm == true) {
                echo '<td class="w-25"><center>';
                    echo '<a href="javascript:void(0)" data-toggle="tooltip" title="Edit Personnel"><span class="badge badge-warning" data-toggle="modal" data-target="#edit_dccstaffingModal" data-id="'.$data['id'].'" data-personnel_name="'.$data['personnel_name'].'" data-personnel_ois="'.$data['personnel_ois'].'" data-position_name="'.$data['position_name'].'" data-position_facility="'.$data['position_facility'].'">
                        <i class="fas fa-pencil-alt"></i> Edit</span></a>';
                    echo ' ';
                    echo '<a href="javascript:void(0)" onclick="deleteDCCStaffing('.$data['id'].')" data-toggle="tooltip" title="Delete Personnel"><span class="badge badge-danger"><i class="fas fa-times"></i> Delete</span></a>';
                echo '</center></td>';
            }
    
        echo '</tr>';
    }
} else {
    echo '<tr><td class="text-center" colspan="4">No Personnel Rostered</td></tr>';
}


?>