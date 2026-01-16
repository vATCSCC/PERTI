<?php

// Session Start (S)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
  }
// Session Start (E)

include("../../../../load/config.php");
include("../../../../load/connect.php");

$domain = strip_tags(SITE_DOMAIN);

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

if ($perm == true) {

    if (!isset($_POST['id']) || !isset($_POST['probability'])) {
        http_response_code(400);
        exit;
    }

    $id          = post_input('id');
    $probability = post_input('probability');

    // Normalize probability range (0-4; 4 = Actual)
    $probability = (int)$probability;
    if ($probability < 0) {
        $probability = 0;
    } elseif ($probability > 4) {
        $probability = 4;
    }

    // Insert Data into Database
    $query = $conn_sqli->query("UPDATE p_terminal_init_times SET probability='$probability' WHERE id=$id");

    if ($query) {
        http_response_code(200);
    } else {
        http_response_code(500);
    }

} else {
    http_response_code(403);
}

?>