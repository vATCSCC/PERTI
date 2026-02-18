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

// Allowed HTML tags for rich text fields
$allowedTags = "<br><strong><b><i><em><ul><ol><li><img><table><td><tr><th><a><u>";

// Safe input sanitization function
function sanitizeRichText($key, $allowedTags) {
    $raw = isset($_POST[$key]) ? $_POST[$key] : '';
    $cleaned = strip_tags(str_replace("`", "&#039;", $raw), $allowedTags);
    return preg_replace("#<br\s*/?>#i", "<br>", str_replace('"', "&quot;", $cleaned));
}

$staffing = sanitizeRichText('staffing', $allowedTags);
$tactical = sanitizeRichText('tactical', $allowedTags);
$other = sanitizeRichText('other', $allowedTags);
$perti = sanitizeRichText('perti', $allowedTags);
$ntml = sanitizeRichText('ntml', $allowedTags);
$tmi = sanitizeRichText('tmi', $allowedTags);
$ace = sanitizeRichText('ace', $allowedTags);


// Insert Data into Database using prepared statement
try {
    // Begin Transaction
    $conn_pdo->beginTransaction();

    // Use prepared statement to prevent SQL injection
    $sql = "INSERT INTO r_comments (p_id, staffing, tactical, other, perti, ntml, tmi, ace)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn_pdo->prepare($sql);
    $stmt->execute([$p_id, $staffing, $tactical, $other, $perti, $ntml, $tmi, $ace]);

    $conn_pdo->commit();
    http_response_code(200);
}
catch (PDOException $e) {
    $conn_pdo->rollback();
    error_log("comments/post error: " . $e->getMessage());
    http_response_code(500);
}

?>