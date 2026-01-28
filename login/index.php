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

    return implode('', $stringArray);
}

// Setting Values from Config:
$client_id = CONNECT_CLIENT_ID;
$client_secret = CONNECT_SECRET;
$url_base = CONNECT_URL_BASE;
$scopes = CONNECT_SCOPES;
$redirect_uri = CONNECT_REDIRECT_URI;

// Capture return URL for post-login redirect
$return_url = null;
if (isset($_GET['return']) && !empty($_GET['return'])) {
    $return_url = $_GET['return'];
} elseif (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
    $return_url = $_SERVER['HTTP_REFERER'];
}

// Validate return URL - only allow same-origin redirects
if ($return_url) {
    $parsed = parse_url($return_url);
    $current_host = $_SERVER['HTTP_HOST'] ?? '';
    // Only store if it's a relative URL or same host
    if (!isset($parsed['host']) || $parsed['host'] === $current_host) {
        $_SESSION['LOGIN_RETURN_URL'] = $return_url;
    }
}

$initLink = sprintf("%s/oauth/authorize?client_id=%s&redirect_uri=%s&response_type=code&scope=%s&state=%s", $url_base, $client_id, $redirect_uri, $scopes, randString(40));

if (!isset($_GET['token'])) {

    header("Location: $initLink");
    
}

?>