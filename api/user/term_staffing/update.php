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


$id = strip_tags($_POST['id']);

$staffing_status = strip_tags($_POST['staffing_status']);
$staffing_quantity = strip_tags($_POST['staffing_quantity']);
$comments = strip_tags(html_entity_decode(str_replace("`", "&#039;", $_POST['comments'])));

// Insert Data into Database
$query = $conn_sqli->query("UPDATE p_terminal_staffing SET staffing_status='$staffing_status', staffing_quantity='$staffing_quantity', comments='$comments' WHERE id=$id");

if ($query) {
    http_response_code('200');
} else {
    http_response_code('500');
}

?>