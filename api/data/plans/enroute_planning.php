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

$c_q = $conn_sqli->query("SELECT COUNT(*) AS 'total' FROM p_enroute_planning WHERE p_id='$p_id'")->fetch_assoc();

if ($c_q['total'] > 0) {
    $query = $conn_sqli->query("SELECT * FROM p_enroute_planning WHERE p_id='$p_id'");

    while ($data = mysqli_fetch_array($query)) {
        echo '<div class="col-md-6 col-xl-4 mb-4">';
            echo '<div class="card">';
                echo '<div class="card-body">';
                    echo '<h5 class="card-title"><b>'.$data['facility_name'].'</b></h5>';
                    echo '<p class="text-wrap">'.$data['comments'].'</p>';

                    if ($perm == true) {
                        echo '<hr>';

                        echo '<a href="javascript:void(0)" data-toggle="tooltip" title="Edit Planning Data"><span class="badge badge-warning" data-toggle="modal" data-target="#editenrouteplanningModal" data-id="'.$data['id'].'" data-facility_name="'.$data['facility_name'].'" data-comments="'.$data['comments'].'">
                        <i class="fas fa-pencil-alt"></i> Edit</span></a>';
                        echo ' ';
                        echo '<a href="javascript:void(0)" onclick="deleteEnroutePlanning('.$data['id'].')" data-toggle="tooltip" title="Delete Planning Data"><span class="badge badge-danger"><i class="fas fa-times"></i> Delete</span></a>';
                    }
                echo '</div>';
            echo '</div>';
        echo '</div>';
    }
} else {
    echo '<h5 class="text-center w-100">No Planning Data</h5>';
}


?>