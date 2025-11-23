<?php

if (!defined("SQL_USERNAME")) {

    // Database Information
    define("SQL_USERNAME", "");
    define("SQL_PASSWORD", "");
    define("SQL_HOST", "");
    define("SQL_DATABASE", "");

    // Site Information
    define("SITE_DOMAIN", "localhost");

    // Tech Configuration
    define("CONNECT_CLIENT_ID", 0);
    define("CONNECT_SECRET", '');
    define("CONNECT_SCOPES", 'full_name vatsim_details');
    define("CONNECT_REDIRECT_URI", '.../login/callback');
    define("CONNECT_URL_BASE", 'https://auth.vatsim.net');

    define("DEV", true);
}

?>
