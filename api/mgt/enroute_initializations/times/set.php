<?php

// Session Start (S)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
  }
// Session Start (E)

include("../../../../load/config.php");
define('PERTI_MYSQL_ONLY', true);
include("../../../../load/connect.php");

$domain = strip_tags(SITE_DOMAIN);

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

    // Update Data in Database (prepared statement)
    $stmt = $conn_sqli->prepare("UPDATE p_enroute_init_times SET probability=? WHERE id=?");
    $stmt->bind_param("ii", $probability, $id);

    if ($stmt->execute()) {
        http_response_code(200);
    } else {
        error_log("enroute_initializations/times/set error: " . $stmt->error);
        http_response_code(500);
    }
    $stmt->close();

} else {
    http_response_code(403);
}

?>