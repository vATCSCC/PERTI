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

$p_id = strip_tags($_POST['p_id']);
$position_name = strip_tags($_POST['position_name']);
$position_facility = strip_tags($_POST['position_facility']);
$personnel_name = strip_tags(html_entity_decode(str_replace("`", "&#039;", $_POST['personnel_name'])));
$personnel_ois = strip_tags($_POST['personnel_ois']);

// Insert Data into Database
try {

    // Begin Transaction
    $conn_pdo->beginTransaction();

    // SQL Query
    $sql = "INSERT INTO p_dcc_staffing (p_id, position_name, position_facility, personnel_name, personnel_ois) VALUES ('$p_id', '$position_name', '$position_facility', '$personnel_name', '$personnel_ois')";

    $conn_pdo->exec($sql);

    $conn_pdo->commit();
    http_response_code(200);
}

catch (PDOException $e) {
    $conn_pdo->rollback();
    http_response_code(500);
}

?>