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
$personnel_ois = strip_tags($_POST['personnel_ois']);
$personnel_name = strip_tags(html_entity_decode(str_replace("`", "&#039;", $_POST['personnel_name'])));

// Insert Data into Database
$query = $conn_sqli->query("UPDATE p_dcc_staffing SET personnel_ois='$personnel_ois', personnel_name='$personnel_name' WHERE id=$id");

if ($query) {
    http_response_code('200');
} else {
    http_response_code('500');
}

?>