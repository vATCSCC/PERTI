<?php

// Session Start (S)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
  }
// Session Start (E)

include("../../../load/config.php");
include("../../../load/connect.php");

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

$event_name = strip_tags(html_entity_decode(str_replace("`", "&#039;", $_POST['event_name'])));
$event_date = strip_tags($_POST['event_date']);
$event_start = strip_tags($_POST['event_start']);
$event_end_date = strip_tags($_POST['event_end_date']);
$event_end_time = strip_tags($_POST['event_end_time']);
$event_banner = strip_tags($_POST['event_banner']);
$oplevel = strip_tags($_POST['oplevel']);
$hotline = strip_tags($_POST['hotline']);

// Insert Data into Database
try {

    // Begin Transaction
    $conn_pdo->beginTransaction();

    // SQL Query
    $sql = "INSERT INTO p_plans (event_name, event_date, event_start, event_end_date, event_end_time, event_banner, oplevel, hotline)
    VALUES ('$event_name', '$event_date', '$event_start', '$event_end_date', '$event_end_time', '$event_banner', '$oplevel', '$hotline')";

    $conn_pdo->exec($sql);

    $conn_pdo->commit();
    http_response_code(200);
}

catch (PDOException $e) {
    $conn_pdo->rollback();
    http_response_code(500);
}

?>
