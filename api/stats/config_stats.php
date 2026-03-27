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

if (!function_exists('env_stats_bool')) {
    function env_stats_bool($key, $default = false) {
        $raw = env_stats($key, $default ? '1' : '0');
        $value = strtolower(trim((string)$raw));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('env_stats_int')) {
    function env_stats_int($key, $default = 0) {
        $raw = env_stats($key, (string)$default);
        return is_numeric($raw) ? (int)$raw : (int)$default;
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
    define('STATS_SQL_PASSWORD', env_stats('STATS_SQL_PASSWORD', ''));
}

// Runtime tuning knobs for keeping VATSIM_STATS within the free monthly limit.
if (!defined('STATS_OPTIMIZE_FOR_FREE')) {
    define('STATS_OPTIMIZE_FOR_FREE', env_stats_bool('STATS_OPTIMIZE_FOR_FREE', true));
}

if (!defined('STATS_SNAPSHOT_MIN_INTERVAL_SEC')) {
    $defaultSnapshotInterval = STATS_OPTIMIZE_FOR_FREE ? 900 : 240; // 15m (free) vs 4m (legacy)
    define('STATS_SNAPSHOT_MIN_INTERVAL_SEC', max(60, env_stats_int('STATS_SNAPSHOT_MIN_INTERVAL_SEC', $defaultSnapshotInterval)));
}

if (!defined('STATS_ENABLE_CACHE_REGEN')) {
    define('STATS_ENABLE_CACHE_REGEN', env_stats_bool('STATS_ENABLE_CACHE_REGEN', true));
}

if (!defined('STATS_CACHE_REFRESH_INTERVAL_SEC')) {
    $defaultCacheRefresh = STATS_OPTIMIZE_FOR_FREE ? 21600 : 300; // 6h (free) vs 5m (legacy)
    define('STATS_CACHE_REFRESH_INTERVAL_SEC', max(300, env_stats_int('STATS_CACHE_REFRESH_INTERVAL_SEC', $defaultCacheRefresh)));
}

if (!defined('STATS_STATIC_CACHE_TTL_SEC')) {
    $defaultStaticTtl = STATS_OPTIMIZE_FOR_FREE ? 21600 : 300;
    define('STATS_STATIC_CACHE_TTL_SEC', max(300, env_stats_int('STATS_STATIC_CACHE_TTL_SEC', $defaultStaticTtl)));
}

if (!defined('STATS_DYNAMIC_CACHE_TTL_SEC')) {
    $defaultDynamicTtl = STATS_OPTIMIZE_FOR_FREE ? 3600 : 300;
    define('STATS_DYNAMIC_CACHE_TTL_SEC', max(300, env_stats_int('STATS_DYNAMIC_CACHE_TTL_SEC', $defaultDynamicTtl)));
}

if (!defined('STATS_SERVE_STALE_CACHE')) {
    define('STATS_SERVE_STALE_CACHE', env_stats_bool('STATS_SERVE_STALE_CACHE', true));
}
