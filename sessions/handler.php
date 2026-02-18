<?php
/**
 * Session Handler - Lightweight Version
 *
 * Starts PHP session and makes session variables available.
 * Authentication is handled by login/callback.php via VATSIM OAuth.
 * Authorization is checked by nav.php (users table query).
 *
 * Previous version used cURL to validate sessions against DB on every
 * page load. This was removed because:
 * - Single-instance deployment doesn't need centralized session store
 * - PHP native sessions are secure (cryptographic IDs, httponly cookies)
 * - Eliminated 1-2 HTTP requests + 2 DB queries per page load
 *
 * @package PERTI
 * @subpackage Sessions
 */

// Prevent multiple inclusions
if (defined('SESSION_HANDLER_LOADED')) {
    return;
}
define('SESSION_HANDLER_LOADED', true);

// Start session if not already started
if (session_status() == PHP_SESSION_NONE && !headers_sent()) {
    session_start();
    ob_start();
}

// Include dependencies
include_once(dirname(__DIR__, 1) . '/load/config.php');
require_once(dirname(__DIR__, 1) . '/load/input.php');

// DEV mode: Set dummy session values for local development
if (defined('DEV') && DEV === true) {
    $_SESSION['VATSIM_CID'] = 0;
    $_SESSION['VATSIM_FIRST_NAME'] = 'Dev';
    $_SESSION['VATSIM_LAST_NAME'] = 'User';
    $_SESSION['ORG_CODE'] = 'vatcscc';
    $_SESSION['ORG_PRIVILEGED'] = true;
    $_SESSION['ORG_GLOBAL'] = true;
    $_SESSION['ORG_ALL'] = ['vatcscc', 'vatcan', 'ecfmp'];
}

// Session data is now simply read from PHP's native session storage.
// No cURL validation needed - the session was established securely
// via VATSIM OAuth in login/callback.php

?>
