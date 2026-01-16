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

$c_q = $conn_sqli->query("SELECT COUNT(*) AS 'total' FROM p_group_flights WHERE p_id='$p_id'")->fetch_assoc();


if ($c_q['total'] > 0) {
    $query = $conn_sqli->query("SELECT * FROM p_group_flights WHERE p_id='$p_id'");

    while ($data = mysqli_fetch_array($query)) {
        echo '<tr>';

            echo '<td class="text-center" style="width: 15%;">'.$data['entity'].'</td>';
            echo '<td class="text-center" style="width: 5%;">'.$data['dep'].'</td>';
            echo '<td class="text-center" style="width: 5%;">'.$data['arr'].'</td>';
            echo '<td class="text-center" style="width: 5%;">'.$data['etd'].'Z</td>';
            echo '<td class="text-center" style="width: 5%;">'.$data['eta'].'Z</td>';

            if ($data['pilot_quantity'] < 5) {
                echo '<td class="text-center" style="width: 10%; background-color: #5CFF5C;">'.$data['pilot_quantity'].'</td>';
            } elseif ($data['pilot_quantity'] < 10) {
                echo '<td class="text-center" style="width: 10%; background-color: #318931;">'.$data['pilot_quantity'].'</td>';
            } elseif ($data['pilot_quantity'] < 15) {
                echo '<td class="text-center" style="width: 10%; background-color: #FFFF5C;">'.$data['pilot_quantity'].'</td>';
            } elseif ($data['pilot_quantity'] < 20) {
                echo '<td class="text-center" style="width: 10%; background-color: #ff8811;">'.$data['pilot_quantity'].'</td>';
            } else {
                echo '<td class="text-center" style="width: 10%; background-color: #ff2b2b;">'.$data['pilot_quantity'].'</td>';
            }

            echo '<td class="text-center">'.$data['route'].'</td>';
    
            if ($perm == true) {
                echo '<td style="width: 10%;"><center>';
                    echo '<a href="javascript:void(0)" data-toggle="tooltip" title="Edit Group Flight"><span class="badge badge-warning" data-toggle="modal" data-target="#editgroupflightModal" data-id="'.$data['id'].'" data-entity="'.$data['entity'].'" data-dep="'.$data['dep'].'" data-arr="'.$data['arr'].'" data-etd="'.$data['etd'].'" data-eta="'.$data['eta'].'" data-pilot_quantity="'.$data['pilot_quantity'].'" data-route="'.$data['route'].'">
                        <i class="fas fa-pencil-alt"></i> Edit</span></a>';
                    echo ' ';
                    echo '<a href="javascript:void(0)" onclick="deleteGroupFlight('.$data['id'].')" data-toggle="tooltip" title="Delete Group Flight"><span class="badge badge-danger"><i class="fas fa-times"></i> Delete</span></a>';
                echo '</center></td>';
            }
    
        echo '</tr>';
    }
} else {
    echo '<tr><td class="text-center" colspan="8">No Group Flights Filed</td></tr>';
}


?>