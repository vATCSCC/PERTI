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

// Check Perms (S)
if ($perm == true) {
    // Do Nothing
} else {
    http_response_code(403);
    exit();
}
// (E)

$p_id = post_input('p_id');
$facility_name = strip_tags(html_entity_decode(str_replace("`", "&#039;", $_POST['facility_name'])));
$staffing_status = post_input('staffing_status');
$staffing_quantity = post_input('staffing_quantity');
$comments = strip_tags(html_entity_decode(str_replace("`", "&#039;", $_POST['comments'])));


// Insert Data into Database
try {

    // Begin Transaction
    $conn_pdo->beginTransaction();

    // SQL Query (prepared statement)
    $sql = "INSERT INTO p_enroute_staffing (facility_name, staffing_status, staffing_quantity, comments, p_id) VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn_pdo->prepare($sql);
    $stmt->execute([$facility_name, $staffing_status, $staffing_quantity, $comments, $p_id]);

    $conn_pdo->commit();
    http_response_code(200);
}

catch (PDOException $e) {
    $conn_pdo->rollback();
    error_log("enroute_staffing/post error: " . $e->getMessage());
    http_response_code(500);
}

?>