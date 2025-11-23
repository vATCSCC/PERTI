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
$title = strip_tags(html_entity_decode(str_replace("`", "&#039;", $_POST['title'])));
$date = strip_tags($_POST['date']);
$summary = strip_tags(html_entity_decode(str_replace("`", "&#039;", $_POST['summary'])));
$image_url = strip_tags($_POST['image_url']);
$source_url = strip_tags($_POST['source_url']);

// Insert Data into Database
try {

    // Begin Transaction
    $conn_pdo->beginTransaction();

    // SQL Query
    $sql = "INSERT INTO p_historical (p_id, title, date, summary, image_url, source_url) VALUES ('$p_id', '$title', '$date', '$summary', '$image_url', '$source_url')";

    $conn_pdo->exec($sql);

    $conn_pdo->commit();
    http_response_code(200);
}

catch (PDOException $e) {
    $conn_pdo->rollback();
    http_response_code(500);
}

?>