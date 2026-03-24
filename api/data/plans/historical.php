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

$p_id = intval(get_input('p_id'));

require_once(dirname(__DIR__, 3) . '/load/org_context.php');
if (!validate_plan_org((int)$p_id, $conn_sqli)) {
    http_response_code(403);
    exit();
}

$c_q = $conn_sqli->query("SELECT COUNT(*) AS 'total' FROM p_historical WHERE p_id='$p_id'")->fetch_assoc();

if ($c_q['total'] > 0) {
    $query = $conn_sqli->query("SELECT * FROM p_historical WHERE p_id='$p_id'");

    while ($data = mysqli_fetch_array($query)) {
        echo '<div class="col-md-6 col-xl-4 mb-4">';
            echo '<div class="card">';
                echo '<img class="card-img-top" src="'.htmlspecialchars($data['image_url'], ENT_QUOTES, 'UTF-8').'">';

                echo '<div class="card-body">';
                    echo '<h5 class="card-title"><b>'.htmlspecialchars($data['title'], ENT_QUOTES, 'UTF-8').'</b> ('.htmlspecialchars($data['date'], ENT_QUOTES, 'UTF-8').')</h5>';
                    echo '<p class="text-wrap">'.htmlspecialchars($data['summary'], ENT_QUOTES, 'UTF-8').'</p>';

                    echo '<hr>';

                    if ($data['source_url'] !== '') {
                        echo '<b>Source:</b> <a href="' . htmlspecialchars($data['source_url'], ENT_QUOTES, 'UTF-8') . '" target="_blank">' . htmlspecialchars($data['source_url'], ENT_QUOTES, 'UTF-8') . '</a>';
                    }

                    if ($perm == true) {
                        echo '<hr>';

                        echo '<a href="javascript:void(0)" data-toggle="tooltip" title="Edit Historical Data"><span class="badge badge-warning" data-toggle="modal" data-target="#edithistoricalModal" data-id="'.intval($data['id']).'" data-title="'.htmlspecialchars($data['title'], ENT_QUOTES, 'UTF-8').'" data-date="'.htmlspecialchars($data['date'], ENT_QUOTES, 'UTF-8').'" data-summary="'.htmlspecialchars($data['summary'], ENT_QUOTES, 'UTF-8').'" data-image_url="'.htmlspecialchars($data['image_url'], ENT_QUOTES, 'UTF-8').'" data-source_url="'.htmlspecialchars($data['source_url'], ENT_QUOTES, 'UTF-8').'">
                        <i class="fas fa-pencil-alt"></i> Edit</span></a>';
                        echo ' ';
                        echo '<a href="javascript:void(0)" onclick="deleteHistorical('.intval($data['id']).')" data-toggle="tooltip" title="Delete Historical Data"><span class="badge badge-danger"><i class="fas fa-times"></i> Delete</span></a>';
                    }
                echo '</div>';
            echo '</div>';
        echo '</div>';
    }
} else {
    echo '<h5 class="text-center w-100">No Historical Data</h5>';
}


?>