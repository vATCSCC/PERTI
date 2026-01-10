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

// Fetch VATUSA events
$ch_usa = curl_init();
curl_setopt_array($ch_usa, [
    CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_URL => "https://api.vatusa.net/v2/public/events/21"
]);
$json_usa = curl_exec($ch_usa);
$obj_usa = json_decode($json_usa, true);
curl_close($ch_usa);

// Fetch VATCAN events
$ch_can = curl_init();
curl_setopt_array($ch_can, [
    CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_URL => "https://vatcan.ca/api/v2/division/events"
]);
$json_can = curl_exec($ch_can);
$obj_can = json_decode($json_can, true);
curl_close($ch_can);

// Render VATUSA events (blue border + USA badge)
if (isset($obj_usa['data']) && is_array($obj_usa['data'])) {
    foreach ($obj_usa['data'] as &$data) {
        echo '<div class="col-md-6 col-xl-4 mb-4">';
            echo '<div class="card border-left-primary" style="border-left: 4px solid #4e73df !important;">';
                echo '<div class="card-body">';
                    echo '<h5 class="card-title"><b>'.$data['title'].'</b> <span class="badge badge-primary">USA</span></h5>';
                    echo '<p class="text-wrap">On '.$data['start_date'].'</p>';
                echo '</div>';
            echo '</div>';
        echo '</div>';
    }
}

// Render VATCAN events (red border + CAN badge)
if (isset($obj_can['data']) && is_array($obj_can['data'])) {
    foreach ($obj_can['data'] as &$data) {
        echo '<div class="col-md-6 col-xl-4 mb-4">';
            echo '<div class="card border-left-danger" style="border-left: 4px solid #e74a3b !important;">';
                echo '<div class="card-body">';
                    echo '<h5 class="card-title"><b>'.$data['name'].'</b> <span class="badge badge-danger">CAN</span></h5>';
                    echo '<p class="text-wrap">On '.substr($data['start'], 0, 10).'</p>';
                echo '</div>';
            echo '</div>';
        echo '</div>';
    }
}

?>