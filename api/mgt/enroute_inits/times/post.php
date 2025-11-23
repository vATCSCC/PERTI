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

// Check Perms (S)
if ($perm == true) {
    // Do Nothing
} else {
    http_response_code(403);
    exit();
}
// (E)

$init_id = strip_tags($_POST['init_id']);
$time = strip_tags($_POST['time']);

// Insert Data into Database
try {

    // Begin Transaction
    $conn_pdo->beginTransaction();

    // SQL Query
    $sql = "INSERT INTO p_enroute_init_times (time, probability, init_id)
    VALUES ('$time', '0', '$init_id')";

    $conn_pdo->exec($sql);

    $conn_pdo->commit();
    http_response_code(200);
}

catch (PDOException $e) {
    $conn_pdo->rollback();
    http_response_code(500);
}

?>