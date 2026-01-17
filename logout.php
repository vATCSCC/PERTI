<?php
/**
 * Logout Handler
 *
 * Destroys the user's session and clears cookies.
 *
 * @package PERTI
 * @subpackage Sessions
 */

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Clear session variables
unset($_SESSION['VATSIM_CID']);
unset($_SESSION['VATSIM_FIRST_NAME']);
unset($_SESSION['VATSIM_LAST_NAME']);

// Destroy the session
session_destroy();

// Clear cookies
setcookie("PHPSESSID", "", time() - 3600, "/");
setcookie("SELF", "", time() - 3600, "/");

// Redirect to home
header("Location: index.php");
exit;

?>
