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

$c_q = $conn_sqli->query("SELECT COUNT(*) AS 'total' FROM p_forecast WHERE p_id='$p_id'")->fetch_assoc();

if ($c_q['total'] > 0) {
    $query = $conn_sqli->query("SELECT * FROM p_forecast WHERE p_id='$p_id' ORDER BY date ASC");

    while ($data = mysqli_fetch_array($query)) {
        echo '<div class="col-md-6 col-xl-6 mb-4">';
            echo '<div class="card">';
                echo '<img class="card-img-top" src="'.$data['image_url'].'">';

                echo '<div class="card-body">';
                    echo '<h5 class="card-title">Forecast for <b>'.$data['date'].'Z</b></h5>';
                    echo '<p class="text-wrap">'.$data['summary'].'</p>';

                    if ($perm == true) {
                        echo '<hr>';

                        echo '<a href="javascript:void(0)" data-toggle="tooltip" title="Edit Forecast Entry"><span class="badge badge-warning" data-toggle="modal" data-target="#editforecastModal" data-id="'.$data['id'].'" data-date="'.$data['date'].'" data-summary="'.$data['summary'].'" data-image_url="'.$data['image_url'].'">
                        <i class="fas fa-pencil-alt"></i> Edit</span></a>';
                        echo ' ';
                        echo '<a href="javascript:void(0)" onclick="deleteForecast('.$data['id'].')" data-toggle="tooltip" title="Delete Forecast Entry"><span class="badge badge-danger"><i class="fas fa-times"></i> Delete</span></a>';
                    }
                echo '</div>';
            echo '</div>';
        echo '</div>';
    }
} else {
    echo '<h5 class="text-center w-100">No Forecast Data</h5>';
}


?>