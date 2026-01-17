<?php

if (file_exists(__DIR__ . '/input.php')) {
    require_once(__DIR__ . '/input.php');
}

// Helper function to get environment variable from multiple sources
function env($key, $default = '') {
    // Try getenv first
    $value = getenv($key);
    if ($value !== false && $value !== '') return $value;
    
    // Try $_ENV
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') return $_ENV[$key];
    
    // Try $_SERVER (Azure puts app settings here)
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];
    
    // Try APPSETTING_ prefix (Azure convention)
    $appsettingKey = 'APPSETTING_' . $key;
    if (isset($_SERVER[$appsettingKey]) && $_SERVER[$appsettingKey] !== '') return $_SERVER[$appsettingKey];
    
    return $default;
}

if (!defined("SQL_USERNAME")) {

    // Database Information
    define("SQL_USERNAME", env('SQL_USERNAME', 'jpeterson'));
    define("SQL_PASSWORD", env('SQL_PASSWORD', 'Jhp21012'));
    define("SQL_HOST", env('SQL_HOST', 'vatcscc-perti.mysql.database.azure.com'));
    define("SQL_DATABASE", env('SQL_DATABASE', 'perti_site'));

    define("ADL_SQL_HOST", env('ADL_SQL_HOST', 'vatsim.database.windows.net'));
    define("ADL_SQL_DATABASE", env('ADL_SQL_DATABASE', 'VATSIM_ADL'));
    define("ADL_SQL_USERNAME", env('ADL_SQL_USERNAME', 'adl_api_user'));
    define("ADL_SQL_PASSWORD", env('ADL_SQL_PASSWORD', 'CAMRN@11000'));

    define(
        "ADL_SQL_DSN",
        "sqlsrv:server=tcp:" . ADL_SQL_HOST . ",1433;Database=" . ADL_SQL_DATABASE
    );

    define("SWIM_SQL_HOST", env('SWIM_SQL_HOST', 'vatsim.database.windows.net'));
    define("SWIM_SQL_DATABASE", env('SWIM_SQL_DATABASE', 'SWIM_API'));
    define("SWIM_SQL_USERNAME", env('SWIM_SQL_USERNAME', 'adl_api_user'));
    define("SWIM_SQL_PASSWORD", env('SWIM_SQL_PASSWORD', 'CAMRN@11000'));

    define("ADL_QUERY_SOURCE", "normalized");

    // Dynamic domain detection for multi-site support
    $currentHost = $_SERVER['HTTP_HOST'] ?? 'perti.vatcscc.org';
    define("SITE_DOMAIN", $currentHost);

    define("CONNECT_CLIENT_ID", 975);
    define("CONNECT_SECRET", env('CONNECT_SECRET', 'uL1c3v2rAIn7G9bTKW79KPHmQoXQdAax5V1LDqNL'));
    define("CONNECT_SCOPES", 'full_name vatsim_details');
    define("CONNECT_REDIRECT_URI", 'https://' . $currentHost . '/login/callback');
    define("CONNECT_URL_BASE", 'https://auth.vatsim.net');

    define("ENV", 'prod');

    // Discord
    define('DISCORD_BOT_TOKEN', env('DISCORD_BOT_TOKEN', ''));
    define('DISCORD_APPLICATION_ID', env('DISCORD_APPLICATION_ID', '1447711207703183370'));
    define('DISCORD_PUBLIC_KEY', env('DISCORD_PUBLIC_KEY', ''));
    define('DISCORD_GUILD_ID', '1039586513689780224');
    define('DISCORD_CHANNELS', json_encode([
        'tmi' => '1350319537526014062',
        'advisories' => '1447715453425418251',
        'operations' => '1457805565802840206',
        'alerts' => '1457805582252642510',
        'general' => '1039586515115839619'
    ]));
    define('DISCORD_API_VERSION', '10');
    define('DISCORD_API_BASE', 'https://discord.com/api/v' . DISCORD_API_VERSION);

    define("PROTECTED_CID", "1234727");
}
?>
