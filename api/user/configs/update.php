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


$id = post_input('id');
$weather = post_input('weather');
$arrive = post_input('arrive');
$depart = post_input('depart');
$comments = strip_tags(html_entity_decode(str_replace("`", "&#039;", $_POST['comments'] ?? '')));

// Insert Data into Database
$query = $conn_sqli->query("UPDATE p_configs SET weather='$weather', arrive='$arrive', depart='$depart', aar='', adr='', comments='$comments' WHERE id=$id");

if ($query) {
    http_response_code('200');
} else {
    http_response_code('500');
}

?>