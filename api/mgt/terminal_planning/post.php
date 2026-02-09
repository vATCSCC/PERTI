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

$p_id = post_int('p_id');

// Safely get POST values with null checks
$facility_raw = isset($_POST['facility_name']) ? $_POST['facility_name'] : '';
$facility_name = strip_tags(html_entity_decode(str_replace("`", "&#039;", $facility_raw)));

$comments_raw = isset($_POST['comments']) ? $_POST['comments'] : '';
$init_c = strip_tags(str_replace("`", "&#039;", $comments_raw), "<br><strong><b><i><em><ul><ol><li><img><table><td><tr><th><a><u>");
$comments = preg_replace("#<br\s*/?>#i", "<br>", str_replace('"', "&quot;", $init_c));


// Insert Data into Database using prepared statement
try {
    // Begin Transaction
    $conn_pdo->beginTransaction();

    // Use prepared statement to prevent SQL injection
    $sql = "INSERT INTO p_terminal_planning (facility_name, comments, p_id) VALUES (?, ?, ?)";
    $stmt = $conn_pdo->prepare($sql);
    $stmt->execute([$facility_name, $comments, $p_id]);

    $conn_pdo->commit();
    http_response_code(200);
}
catch (PDOException $e) {
    $conn_pdo->rollback();
    http_response_code(500);
}

?>