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

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_URL => "https://api.vatusa.net/v2/public/events/21"
]);

$json = curl_exec($ch);
$obj =  json_decode($json, true);

foreach ($obj['data'] as &$data) {
    echo '<div class="col-md-6 col-xl-4 mb-4">';
        echo '<div class="card">';
            echo '<div class="card-body">';
                echo '<h5 class="card-title"><b>'.$data['title'].'</b></h5>';
                echo '<p class="text-wrap">On '.$data['start_date'].'</p>';
            echo '</div>';
        echo '</div>';
    echo '</div>';
}

?>