<?php

if (!defined("SQL_USERNAME")) {

    // =============================================
    // Primary Website Database (MySQL)
    // =============================================
    define("SQL_USERNAME", "");
    define("SQL_PASSWORD", "");
    define("SQL_HOST", "");
    define("SQL_DATABASE", "");

    // =============================================
    // ADL Database (Azure SQL - Flight Data)
    // Server: vatsim.database.windows.net
    // =============================================
    define("ADL_SQL_HOST", "vatsim.database.windows.net");
    define("ADL_SQL_DATABASE", "VATSIM_ADL");
    define("ADL_SQL_USERNAME", "");
    define("ADL_SQL_PASSWORD", "");

    // =============================================
    // SWIM API Database (Azure SQL - Public API)
    // Server: vatsim.database.windows.net
    // =============================================
    define("SWIM_SQL_HOST", "vatsim.database.windows.net");
    define("SWIM_SQL_DATABASE", "SWIM_API");
    define("SWIM_SQL_USERNAME", "");  // Same as ADL
    define("SWIM_SQL_PASSWORD", "");  // Same as ADL

    // =============================================
    // TMI Database (Azure SQL - Traffic Management)
    // Server: vatsim.database.windows.net
    // Contains: NTML, Advisories, GDT, Reroutes, Public Routes
    // =============================================
    define("TMI_SQL_HOST", "vatsim.database.windows.net");
    define("TMI_SQL_DATABASE", "VATSIM_TMI");
    define("TMI_SQL_USERNAME", "TMI_admin");
    define("TMI_SQL_PASSWORD", "");  // Contact admin for password

    // Site Information
    define("SITE_DOMAIN", "localhost");

    // Tech Configuration
    define("CONNECT_CLIENT_ID", 0);
    define("CONNECT_SECRET", '');
    define("CONNECT_SCOPES", 'full_name vatsim_details');
    define("CONNECT_REDIRECT_URI", '.../login/callback');
    define("CONNECT_URL_BASE", 'https://auth.vatsim.net');

    define("DEV", true);

    // =============================================
    // Discord Bot Integration
    // =============================================

    // Bot Application Credentials (from Discord Developer Portal)
    // 1. Go to https://discord.com/developers/applications
    // 2. Create or select your application
    // 3. Go to "Bot" section to get/create bot token
    // 4. Go to "General Information" for Application ID and Public Key
    define('DISCORD_BOT_TOKEN', '');              // Bot token (keep secret!)
    define('DISCORD_APPLICATION_ID', '');         // Application ID
    define('DISCORD_PUBLIC_KEY', '');             // Public key for webhook signature verification

    // Guild (Server) Configuration
    // Right-click your server in Discord (with Developer Mode enabled) to copy ID
    define('DISCORD_GUILD_ID', '');               // Primary Discord server ID

    // Channel IDs (map of purpose => channel_id)
    // Right-click channels in Discord (with Developer Mode enabled) to copy IDs
    define('DISCORD_CHANNELS', json_encode([
        'tmi' => '',           // TMI announcements channel
        'advisories' => '',    // Advisory postings
        'operations' => '',    // Operations log
        'alerts' => '',        // High-priority alerts
        'general' => ''        // General communications
    ]));

    // API Configuration (generally no need to change)
    define('DISCORD_API_VERSION', '10');
    define('DISCORD_API_BASE', 'https://discord.com/api/v' . DISCORD_API_VERSION);

    // Legacy: Discord Webhook for Advisory Posting (optional, bot is preferred)
    define("DISCORD_WEBHOOK_ADVISORIES", "");

    // Protected CID - this user cannot be deleted from personnel management
    define("PROTECTED_CID", "");
}

?>
