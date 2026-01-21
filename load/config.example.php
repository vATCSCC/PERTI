<?php
/**
 * PERTI Configuration Template
 * 
 * Copy this file to config.php and fill in the values for your environment.
 * 
 * @version 2.0.0
 * @date 2026-01-18
 */

if (!defined("SQL_USERNAME")) {

    // =====================================================================
    // PRIMARY MYSQL DATABASE (perti_site)
    // =====================================================================
    define("SQL_USERNAME", "");
    define("SQL_PASSWORD", "");
    define("SQL_HOST", "vatcscc-perti.mysql.database.azure.com");
    define("SQL_DATABASE", "perti_site");

    // =====================================================================
    // AZURE SQL DATABASES
    // =====================================================================
    
    // VATSIM_ADL - Flight Data (Normalized 8-table architecture)
    define("ADL_SQL_HOST", "your-server.database.windows.net");
    define("ADL_SQL_DATABASE", "VATSIM_ADL");
    define("ADL_SQL_USERNAME", "");
    define("ADL_SQL_PASSWORD", "");
    
    // SWIM_API - Public API Database
    define("SWIM_SQL_HOST", "your-server.database.windows.net");
    define("SWIM_SQL_DATABASE", "SWIM_API");
    define("SWIM_SQL_USERNAME", "");
    define("SWIM_SQL_PASSWORD", "");
    
    // VATSIM_TMI - Traffic Management Initiatives (GDT/GS/GDP)
    define("TMI_SQL_HOST", "your-server.database.windows.net");
    define("TMI_SQL_DATABASE", "VATSIM_TMI");
    define("TMI_SQL_USERNAME", "");
    define("TMI_SQL_PASSWORD", "");
    
    // VATSIM_REF - Reference Data (Airports, Airways, Boundaries)
    define("REF_SQL_HOST", "your-server.database.windows.net");
    define("REF_SQL_DATABASE", "VATSIM_REF");
    define("REF_SQL_USERNAME", "");
    define("REF_SQL_PASSWORD", "");

    // =====================================================================
    // SITE INFORMATION
    // =====================================================================
    define("SITE_DOMAIN", "localhost");
    define("ENV", 'dev');  // 'dev' or 'prod'

    // =====================================================================
    // VATSIM CONNECT OAUTH
    // =====================================================================
    define("CONNECT_CLIENT_ID", 0);
    define("CONNECT_SECRET", '');
    define("CONNECT_SCOPES", 'full_name vatsim_details');
    define("CONNECT_REDIRECT_URI", 'https://your-site.com/login/callback');
    define("CONNECT_URL_BASE", 'https://auth.vatsim.net');

    // =====================================================================
    // DISCORD CONFIGURATION
    // =====================================================================
    
    // Discord Bot Credentials
    define("DISCORD_BOT_TOKEN", '');
    define("DISCORD_APPLICATION_ID", '');
    
    // Channel IDs
    define("DISCORD_CHANNEL_NTML", '');
    define("DISCORD_CHANNEL_ADVISORIES", '');
    define("DISCORD_CHANNEL_NTML_STAGING", '');
    define("DISCORD_CHANNEL_ADVZY_STAGING", '');
    
    // Active channels (switch between production and staging)
    define("DISCORD_NTML_ACTIVE", DISCORD_CHANNEL_NTML_STAGING);
    define("DISCORD_ADVZY_ACTIVE", DISCORD_CHANNEL_ADVZY_STAGING);
    
    // Guild (Server) ID
    define('DISCORD_GUILD_ID', '');
    
    // Channel mapping
    define('DISCORD_CHANNELS', json_encode([
        'tmi' => DISCORD_CHANNEL_NTML,
        'advisories' => DISCORD_CHANNEL_ADVISORIES,
        'operations' => '',
        'alerts' => '',
        'general' => ''
    ]));
    
    // API Configuration
    define('DISCORD_API_VERSION', '10');
    define('DISCORD_API_BASE', 'https://discord.com/api/v' . DISCORD_API_VERSION);

    // =====================================================================
    // INTERNAL API KEYS (for client-side authenticated requests)
    // =====================================================================
    define("SWIM_PUBLIC_ROUTES_KEY", "");  // API key for public routes UI writes

    // =====================================================================
    // FEATURE FLAGS
    // =====================================================================
    define("PERTI_LOADED", true);
}

?>
