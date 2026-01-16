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

$id = post_int('id');

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

// Update Data using prepared statement to prevent SQL injection
$stmt = $conn_sqli->prepare("UPDATE r_comments SET staffing=?, tactical=?, other=?, ntml=?, tmi=?, ace=? WHERE id=?");
$stmt->bind_param("ssssssi", $staffing, $tactical, $other, $ntml, $tmi, $ace, $id);
$query = $stmt->execute();

if ($query) {
    http_response_code('200');
} else {
    http_response_code('500');
}

?>