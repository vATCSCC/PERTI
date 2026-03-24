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
$personnel_ois = post_input('personnel_ois');
$personnel_name = strip_tags(html_entity_decode(str_replace("`", "&#039;", $_POST['personnel_name'] ?? '')));

// Update Data in Database (prepared statement)
$stmt = $conn_sqli->prepare("UPDATE p_dcc_staffing SET personnel_ois=?, personnel_name=? WHERE id=?");
$stmt->bind_param("ssi", $personnel_ois, $personnel_name, $id);

if ($stmt->execute()) {
    http_response_code(200);
} else {
    error_log("user/dcc/update error: " . $stmt->error);
    http_response_code(500);
}
$stmt->close();
