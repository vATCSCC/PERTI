<?php
/**
 * VATSIM_STATS Database Configuration
 *
 * This file defines the STATS database constants if they aren't already defined.
 * The values can be overridden via environment variables (STATS_SQL_HOST, etc.)
 */

// Helper function to get environment variable from multiple sources
if (!function_exists('env_stats')) {
    function env_stats($key, $default = '') {
        // Try getenv first
        $value = getenv($key);
        if ($value !== false && $value !== '') return $value;

        // Try $_ENV
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') return $_ENV[$key];

        // Try $_SERVER (Azure puts app settings here)
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];

        // Try APPSETTING_ prefix (Azure convention for Windows)
        $appsettingKey = 'APPSETTING_' . $key;
        if (isset($_SERVER[$appsettingKey]) && $_SERVER[$appsettingKey] !== '') return $_SERVER[$appsettingKey];

        return $default;
    }
}

// STATS Database (Network statistics, pattern detection, analytics)
if (!defined('STATS_SQL_HOST')) {
    define('STATS_SQL_HOST', env_stats('STATS_SQL_HOST', 'vatsim.database.windows.net'));
}
if (!defined('STATS_SQL_DATABASE')) {
    define('STATS_SQL_DATABASE', env_stats('STATS_SQL_DATABASE', 'VATSIM_STATS'));
}
if (!defined('STATS_SQL_USERNAME')) {
    define('STATS_SQL_USERNAME', env_stats('STATS_SQL_USERNAME', 'adl_api_user'));
}
if (!defined('STATS_SQL_PASSWORD')) {
    define('STATS_SQL_PASSWORD', env_stats('STATS_SQL_PASSWORD', '***REMOVED***'));
}
