<?php
/**
 * PERTI Configuration Template
 * 
 * Copy this file to config.php and fill in the values for your environment.
 * 
 * @version 3.0.0
 * @date 2026-01-27
 * 
 * Changelog:
 * - v3.0.0: Added DISCORD_ORGANIZATIONS for multi-Discord support
 * - v2.0.0: Added multi-database Azure SQL support
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

    // VATSIM_GIS - PostGIS Spatial Database (Route/Boundary Intersection)
    define("GIS_SQL_HOST", "your-postgres-server");
    define("GIS_SQL_PORT", "5432");
    define("GIS_SQL_DATABASE", "VATSIM_GIS");
    define("GIS_SQL_USERNAME", "GIS_admin");
    define("GIS_SQL_PASSWORD", "");  // Default: <PASSWORD>

    // VATSIM_STATS - Statistics & Analytics Database
    // Uses same server as ADL, different database
    define("STATS_SQL_HOST", "your-server.database.windows.net");
    define("STATS_SQL_DATABASE", "VATSIM_STATS");
    define("STATS_SQL_USERNAME", "");
    define("STATS_SQL_PASSWORD", "");

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
    
    // Discord Bot Credentials (Single bot, multiple servers)
    define("DISCORD_BOT_TOKEN", '');
    define("DISCORD_APPLICATION_ID", '1447711207703183370');
    define("DISCORD_PUBLIC_KEY", '');  // For webhook signature verification
    
    // API Configuration
    define('DISCORD_API_VERSION', '10');
    define('DISCORD_API_BASE', 'https://discord.com/api/v' . DISCORD_API_VERSION);
    
    // =========================================================================
    // MULTI-DISCORD ORGANIZATION CONFIGURATION
    // =========================================================================
    // 
    // Each organization represents a Discord server that receives TMI postings.
    // The bot must be invited to each server with appropriate permissions.
    // 
    // Organization fields:
    //   - name: Display name for the organization
    //   - region: Geographic region (US, CA, EU) for cross-border detection
    //   - guild_id: Discord server ID
    //   - channels: Map of purpose -> channel ID
    //       - ntml: Production NTML channel
    //       - advisories: Production advisories channel  
    //       - ntml_staging: Staging NTML channel (optional)
    //       - advzy_staging: Staging advisories channel (optional)
    //   - enabled: Whether to include in posting options
    //   - default: Whether this is the default organization
    //   - testing_only: If true, only shown in dev/test environments
    //
    // Cross-border TMIs:
    //   TMIs affecting facilities in multiple regions (e.g., ZBW→CZYZ) will
    //   automatically suggest posting to all relevant orgs.
    // 
    // =========================================================================
    
    define('DISCORD_ORGANIZATIONS', json_encode([
        
        // vATCSCC - Virtual Air Traffic Control System Command Center (US)
        'vatcscc' => [
            'name' => 'vATCSCC',
            'region' => 'US',
            'guild_id' => '358294607974539265',
            'channels' => [
                'ntml' => '358295136398082048',
                'advisories' => '358300240236773376',
                'ntml_staging' => '912499730335010886',
                'advzy_staging' => '1008478301251194951'
            ],
            'enabled' => true,
            'default' => true
        ],
        
        // vATCSCC Backup - Testing/Backup server
        'vatcscc_backup' => [
            'name' => 'vATCSCC Backup',
            'region' => 'US',
            'guild_id' => '',  // Fill in backup guild ID
            'channels' => [
                'ntml' => '1350319537526014062',
                'advisories' => '1447715453425418251',
                'ntml_staging' => '1039586515115839621',
                'advzy_staging' => '1039586515115839622'
            ],
            'enabled' => true,
            'default' => false,
            'testing_only' => true  // Only available in dev/test
        ],
        
        // VATCAN - Virtual Air Traffic Control Association of Canada
        'vatcan' => [
            'name' => 'VATCAN',
            'region' => 'CA',
            'guild_id' => null,          // VATCAN to provide
            'channels' => [
                'ntml' => null,          // VATCAN to provide
                'advisories' => null,    // VATCAN to provide
                'ntml_staging' => null,  // VATCAN to provide (optional)
                'advzy_staging' => null  // VATCAN to provide (optional)
            ],
            'enabled' => false,          // Enable when credentials received
            'default' => false
        ],
        
        // ECFMP - European Collaborative Flow Management Program
        'ecfmp' => [
            'name' => 'ECFMP',
            'region' => 'EU',
            'guild_id' => null,          // Future integration
            'channels' => [
                'ntml' => null,
                'advisories' => null
            ],
            'enabled' => false,
            'default' => false
        ]
        
        // Add additional organizations as needed:
        // 'orgcode' => [
        //     'name' => 'Organization Name',
        //     'region' => 'XX',
        //     'guild_id' => 'DISCORD_GUILD_ID',
        //     'channels' => [
        //         'ntml' => 'CHANNEL_ID',
        //         'advisories' => 'CHANNEL_ID',
        //         'ntml_staging' => 'CHANNEL_ID',  // optional
        //         'advzy_staging' => 'CHANNEL_ID'  // optional
        //     ],
        //     'enabled' => true,
        //     'default' => false
        // ]
        
    ]));
    
    // =========================================================================
    // LEGACY DISCORD CONFIGURATION (Backwards Compatibility)
    // =========================================================================
    // These constants are maintained for backwards compatibility with existing
    // code that hasn't been updated to use MultiDiscordAPI yet.
    // New code should use DISCORD_ORGANIZATIONS via MultiDiscordAPI class.
    // =========================================================================
    
    // Primary vATCSCC Channel IDs (for legacy code)
    define("DISCORD_CHANNEL_NTML", '358295136398082048');
    define("DISCORD_CHANNEL_ADVISORIES", '358300240236773376');
    define("DISCORD_CHANNEL_NTML_STAGING", '912499730335010886');
    define("DISCORD_CHANNEL_ADVZY_STAGING", '1008478301251194951');
    
    // Active channels (switch between production and staging)
    define("DISCORD_NTML_ACTIVE", DISCORD_CHANNEL_NTML_STAGING);
    define("DISCORD_ADVZY_ACTIVE", DISCORD_CHANNEL_ADVZY_STAGING);
    
    // Guild (Server) ID
    define('DISCORD_GUILD_ID', '358294607974539265');
    
    // Legacy channel mapping (for DiscordAPI backwards compat)
    define('DISCORD_CHANNELS', json_encode([
        'tmi' => DISCORD_CHANNEL_NTML,
        'ntml' => DISCORD_CHANNEL_NTML,
        'advisories' => DISCORD_CHANNEL_ADVISORIES,
        'ntml_staging' => DISCORD_CHANNEL_NTML_STAGING,
        'advzy_staging' => DISCORD_CHANNEL_ADVZY_STAGING,
        'operations' => '',
        'alerts' => '',
        'general' => ''
    ]));

    // =====================================================================
    // INTERNAL API KEYS (for client-side authenticated requests)
    // =====================================================================
    define("SWIM_PUBLIC_ROUTES_KEY", "");  // API key for public routes UI writes

    // =====================================================================
    // TMI COMPLIANCE ANALYSIS
    // =====================================================================
    // TMI Compliance runs via local Python script: scripts/tmi_compliance/run.py
    // Ensure Python 3.x is installed with requirements: pip install -r scripts/tmi_compliance/requirements.txt
    // The script uses ADL and GIS database credentials defined above.

    // =====================================================================
    // FEATURE FLAGS
    // =====================================================================
    define("PERTI_LOADED", true);
    
    // Discord Multi-Org Feature Flag
    define("DISCORD_MULTI_ORG_ENABLED", true);  // Set to false to disable multi-org UI
    
    // TMI Publisher Feature Flags
    define("TMI_STAGING_REQUIRED", true);       // Require staging before production
    define("TMI_APPROVAL_REACTIONS", true);     // Enable reaction-based approvals
    define("TMI_CROSS_BORDER_AUTO_DETECT", true); // Auto-detect cross-border TMIs
}

?>
