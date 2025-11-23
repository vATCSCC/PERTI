<?php

include_once(dirname(__DIR__, 1) . '/sessions/handler.php');
// Session Start (S)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}
// Session Start (E)

include_once(dirname(__DIR__, 1) . '/load/config.php');
include_once(dirname(__DIR__, 1) . '/load/connect.php');

function randString($total) {
    // Setting Values
    $int = 0;
    $a_z_n = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
    $stringArray = array();

    while ($int < $total) {
        $stringArray[] = $a_z_n[rand(0, 61)];

        $int++;
    }

    return $stringArray;
}

// Setting Values from Config:
$client_id = CONNECT_CLIENT_ID;
$client_secret = CONNECT_SECRET;
$url_base = CONNECT_URL_BASE;
$scopes = CONNECT_SCOPES;
$redirect_uri = CONNECT_REDIRECT_URI;

$initLink = sprintf("%s/oauth/authorize?client_id=%s&redirect_uri=%s&response_type=code&scope=%s&state=%s", $url_base, $client_id, $redirect_uri, $scopes, randString(40));

if (!isset($_GET['token'])) {

    header("Location: $initLink");
    
}

?>