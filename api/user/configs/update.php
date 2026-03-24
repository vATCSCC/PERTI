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

perti_require_auth(false);

$domain = strip_tags(SITE_DOMAIN);

$id = post_input('id');
$weather = post_input('weather');
$arrive = post_input('arrive');
$depart = post_input('depart');
$aar = post_input('aar');
$adr = post_input('adr');
$comments = strip_tags(html_entity_decode(str_replace("`", "&#039;", $_POST['comments'] ?? '')));

// Update Data in Database (prepared statement)
$stmt = $conn_sqli->prepare("UPDATE p_configs SET weather=?, arrive=?, depart=?, aar=?, adr=?, comments=? WHERE id=?");
$stmt->bind_param("ssssssi", $weather, $arrive, $depart, $aar, $adr, $comments, $id);

if ($stmt->execute()) {
    http_response_code(200);
} else {
    error_log("user/configs/update error: " . $stmt->error);
    http_response_code(500);
}
$stmt->close();

?>
