<?php

// Session Start (S)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
  }
// Session Start (E)

include("../../../load/config.php");
include("../../../load/connect.php");

perti_require_auth(false);

$id = post_input('id');

$staffing_status = post_input('staffing_status');
$staffing_quantity = post_input('staffing_quantity');
$comments = strip_tags(html_entity_decode(str_replace("`", "&#039;", $_POST['comments'] ?? '')));

// Update Data in Database (prepared statement)
$stmt = $conn_sqli->prepare("UPDATE p_terminal_staffing SET staffing_status=?, staffing_quantity=?, comments=? WHERE id=?");
$stmt->bind_param("sssi", $staffing_status, $staffing_quantity, $comments, $id);

if ($stmt->execute()) {
    http_response_code(200);
} else {
    error_log("user/term_staffing/update error: " . $stmt->error);
    http_response_code(500);
}
$stmt->close();
