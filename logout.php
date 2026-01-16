<?php

// Session Start (S)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}
// Session Start (E)

include('load/config.php');
include('load/connect.php');

// Generate Current IP Address
if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
    $ip = $_SERVER['HTTP_CLIENT_IP'];
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
} else {
    $ip = $_SERVER['REMOTE_ADDR'];
}

$selfcookie = cookie_get('SELF'); 

$query = "UPDATE users SET last_session_ip='', last_selfcookie='' WHERE last_session_ip='$ip' AND last_selfcookie='$selfcookie'";

if (mysqli_query($conn_sqli, $query)) {
    unset($_SESSION['VATSIM_CID']);
    unset($_SESSION['VATSIM_FIRST_NAME']);
    unset($_SESSION['VATSIM_LAST_NAME']);
    
    session_destroy();
    
    setCookie("PHPSESSID", "", time() - 3600, "/");
    setCookie("SELF", "", time() - 3600, "/");
    
    header("Location: index.php");
}

?>