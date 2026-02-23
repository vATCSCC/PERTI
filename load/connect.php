<?php

// load/connect.php
// Establishes connections to the primary MySQL database
// and (optionally) the ADL Azure SQL database.

// Prevent multiple inclusions
if (defined('CONNECT_PHP_LOADED')) {
    return;
}
define('CONNECT_PHP_LOADED', true);

include_once(__DIR__ . "/config.php");

// Include safe input handling functions for PHP 8.2+
require_once(__DIR__ . '/input.php');

// Include PHP i18n module (lazy-loaded, same JSON files as JS PERTII18n)
require_once(__DIR__ . '/i18n.php');

// -------------------------------------------------------------------------
// Helper for ADL SQL Server error messages (used by Azure SQL connection)
// -------------------------------------------------------------------------
if (!function_exists('adl_sql_error_message')) {
    function adl_sql_error_message()
    {
        if (!function_exists('sqlsrv_errors')) {
            return "";
        }
        $errs = sqlsrv_errors(SQLSRV_ERR_ERRORS);
        if (!$errs) {
            return "";
        }
        $msgs = [];
        foreach ($errs as $e) {
            $msgs[] = (isset($e['SQLSTATE']) ? $e['SQLSTATE'] : '') . " " .
                      (isset($e['code']) ? $e['code'] : '') . " " .
                      (isset($e['message']) ? trim($e['message']) : '');
        }
        return implode(" | ", $msgs);
    }
}

// -------------------------------------------------------------------------
// Primary Website Database (MySQL)
// -------------------------------------------------------------------------

// Credentials
$sql_user   = SQL_USERNAME;
$sql_passwd = SQL_PASSWORD;
$sql_host   = SQL_HOST;
$sql_dbname = SQL_DATABASE;

// Establish Connection (PDO)
$conn_pdo = null;
try {
    $conn_pdo = new PDO("mysql:host={$sql_host};dbname={$sql_dbname};charset=utf8mb4", $sql_user, $sql_passwd);
    $conn_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $ex) {
    error_log('PDO connection failed: ' . $ex->getMessage());
    $conn_pdo = null;
}

// Establish Connection (MySQLi)
$conn_sqli = mysqli_connect($sql_host, $sql_user, $sql_passwd, $sql_dbname);

if (!$conn_sqli) {
    error_log('MySQLi connection failed: ' . mysqli_connect_error());
    $conn_sqli = null;
} else {
    mysqli_set_charset($conn_sqli, 'utf8mb4');
}

// If both primary database connections failed, return 503 Service Unavailable
if ($conn_pdo === null && $conn_sqli === null) {
    http_response_code(503);
    error_log('CRITICAL: All primary database connections failed');
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: text/html; charset=utf-8');
        header('Retry-After: 30');
        echo '<!DOCTYPE html><html><head><title>Service Temporarily Unavailable</title></head>';
        echo '<body><h1>Service Temporarily Unavailable</h1>';
        echo '<p>We are experiencing technical difficulties. Please try again in a few moments.</p>';
        echo '</body></html>';
        exit;
    }
}

// -------------------------------------------------------------------------
// Azure SQL Databases - LAZY LOADING
// -------------------------------------------------------------------------
// Connections are established ON DEMAND when first accessed via getter functions.
// This reduces page load time for pages that don't need all databases.
//
// Usage:
//   $conn = get_conn_adl();  // Connects only when called
//   if ($conn) { ... }
//
// For backward compatibility, global $conn_adl etc. still work but connect eagerly
// on first access. Prefer using getter functions for new code.
// -------------------------------------------------------------------------

// Connection cache (null = not attempted, false = failed, resource = connected)
$_conn_cache = [
    'adl' => null,
    'swim' => null,
    'tmi' => null,
    'ref' => null,
    'gis' => null
];

if (!function_exists('get_conn_adl')) {
    /**
     * Get ADL database connection (lazy loaded)
     * Uses sqlsrv extension for Azure SQL - compatible with existing codebase
     * @return resource|false|null Connection resource, false on failure, null if not configured
     */
    function get_conn_adl() {
        global $_conn_cache;

        // Initialize cache if not set (CLI/daemon context)
        if (!is_array($_conn_cache)) {
            $_conn_cache = ['adl' => null, 'swim' => null, 'tmi' => null, 'ref' => null, 'gis' => null];
        }

        // Return cached connection if already attempted
        if (isset($_conn_cache['adl']) && $_conn_cache['adl'] !== null) {
            return $_conn_cache['adl'] ?: null;
        }

        if (!defined('ADL_SQL_HOST') || !defined('ADL_SQL_DATABASE') ||
            !defined('ADL_SQL_USERNAME') || !defined('ADL_SQL_PASSWORD')) {
            $_conn_cache['adl'] = false;
            return null;
        }

        if (!function_exists('sqlsrv_connect')) {
            error_log("ADL SQL connection skipped: sqlsrv extension is not loaded.");
            $_conn_cache['adl'] = false;
            return null;
        }

        $connectionInfo = [
            "Database" => ADL_SQL_DATABASE,
            "UID"      => ADL_SQL_USERNAME,
            "PWD"      => ADL_SQL_PASSWORD,
            "ConnectionPooling" => 1,
            "LoginTimeout" => 5
        ];

        $_conn_cache['adl'] = sqlsrv_connect(ADL_SQL_HOST, $connectionInfo);

        if ($_conn_cache['adl'] === false) {
            error_log("ADL SQL connection failed: " . adl_sql_error_message());
            return null;
        }

        return $_conn_cache['adl'];
    }
}

if (!function_exists('get_conn_swim')) {
    /**
     * Get SWIM API database connection (lazy loaded)
     * @return resource|false|null Connection resource, false on failure, null if not configured
     */
    function get_conn_swim() {
        global $_conn_cache;

        // Initialize cache if not set (CLI/daemon context)
        if (!is_array($_conn_cache)) {
            $_conn_cache = ['adl' => null, 'swim' => null, 'tmi' => null, 'ref' => null, 'gis' => null];
        }

        if (isset($_conn_cache['swim']) && $_conn_cache['swim'] !== null) {
            return $_conn_cache['swim'] ?: null;
        }

        if (!defined('SWIM_SQL_HOST') || !defined('SWIM_SQL_DATABASE') ||
            !defined('SWIM_SQL_USERNAME') || !defined('SWIM_SQL_PASSWORD')) {
            $_conn_cache['swim'] = false;
            return null;
        }

        if (!function_exists('sqlsrv_connect')) {
            $_conn_cache['swim'] = false;
            return null;
        }

        $connectionInfo = [
            "Database" => SWIM_SQL_DATABASE,
            "UID"      => SWIM_SQL_USERNAME,
            "PWD"      => SWIM_SQL_PASSWORD,
            "ConnectionPooling" => 1,
            "LoginTimeout" => 5
        ];

        $_conn_cache['swim'] = sqlsrv_connect(SWIM_SQL_HOST, $connectionInfo);

        if ($_conn_cache['swim'] === false) {
            error_log("SWIM API SQL connection failed: " . adl_sql_error_message());
            return null;
        }

        return $_conn_cache['swim'];
    }
}

if (!function_exists('get_conn_tmi')) {
    /**
     * Get TMI database connection (lazy loaded)
     * @return resource|false|null Connection resource, false on failure, null if not configured
     */
    function get_conn_tmi() {
        global $_conn_cache;

        // Initialize cache if not set (CLI/daemon context)
        if (!is_array($_conn_cache)) {
            $_conn_cache = ['adl' => null, 'swim' => null, 'tmi' => null, 'ref' => null, 'gis' => null];
        }

        if (isset($_conn_cache['tmi']) && $_conn_cache['tmi'] !== null) {
            return $_conn_cache['tmi'] ?: null;
        }

        if (!defined('TMI_SQL_HOST') || !defined('TMI_SQL_DATABASE') ||
            !defined('TMI_SQL_USERNAME') || !defined('TMI_SQL_PASSWORD')) {
            $_conn_cache['tmi'] = false;
            return null;
        }

        if (!function_exists('sqlsrv_connect')) {
            $_conn_cache['tmi'] = false;
            return null;
        }

        $connectionInfo = [
            "Database" => TMI_SQL_DATABASE,
            "UID"      => TMI_SQL_USERNAME,
            "PWD"      => TMI_SQL_PASSWORD,
            "ConnectionPooling" => 1,
            "LoginTimeout" => 5
        ];

        $_conn_cache['tmi'] = sqlsrv_connect(TMI_SQL_HOST, $connectionInfo);

        if ($_conn_cache['tmi'] === false) {
            error_log("TMI SQL connection failed: " . adl_sql_error_message());
            return null;
        }

        return $_conn_cache['tmi'];
    }
}

if (!function_exists('get_conn_ref')) {
    /**
     * Get REF database connection (lazy loaded)
     * @return resource|false|null Connection resource, false on failure, null if not configured
     */
    function get_conn_ref() {
        global $_conn_cache;

        // Initialize cache if not set (CLI/daemon context)
        if (!is_array($_conn_cache)) {
            $_conn_cache = ['adl' => null, 'swim' => null, 'tmi' => null, 'ref' => null, 'gis' => null];
        }

        if (isset($_conn_cache['ref']) && $_conn_cache['ref'] !== null) {
            return $_conn_cache['ref'] ?: null;
        }

        if (!defined('REF_SQL_HOST') || !defined('REF_SQL_DATABASE') ||
            !defined('REF_SQL_USERNAME') || !defined('REF_SQL_PASSWORD')) {
            $_conn_cache['ref'] = false;
            return null;
        }

        if (!function_exists('sqlsrv_connect')) {
            $_conn_cache['ref'] = false;
            return null;
        }

        $connectionInfo = [
            "Database" => REF_SQL_DATABASE,
            "UID"      => REF_SQL_USERNAME,
            "PWD"      => REF_SQL_PASSWORD,
            "ConnectionPooling" => 1,
            "LoginTimeout" => 5
        ];

        $_conn_cache['ref'] = sqlsrv_connect(REF_SQL_HOST, $connectionInfo);

        if ($_conn_cache['ref'] === false) {
            error_log("REF SQL connection failed: " . adl_sql_error_message());
            return null;
        }

        return $_conn_cache['ref'];
    }
}

if (!function_exists('get_conn_gis')) {
    /**
     * Get GIS database connection (lazy loaded)
     * Uses PostgreSQL/PostGIS for spatial queries (route/boundary intersection)
     * @return PDO|false|null PDO connection, false on failure, null if not configured
     */
    function get_conn_gis() {
        global $_conn_cache;

        // Initialize cache if not set (CLI/daemon context)
        if (!is_array($_conn_cache)) {
            $_conn_cache = ['adl' => null, 'swim' => null, 'tmi' => null, 'ref' => null, 'gis' => null];
        }

        if (isset($_conn_cache['gis']) && $_conn_cache['gis'] !== null) {
            return $_conn_cache['gis'] ?: null;
        }

        if (!defined('GIS_SQL_HOST') || !defined('GIS_SQL_DATABASE') ||
            !defined('GIS_SQL_USERNAME') || !defined('GIS_SQL_PASSWORD')) {
            $_conn_cache['gis'] = false;
            return null;
        }

        // Check for PDO pgsql extension
        if (!extension_loaded('pdo_pgsql')) {
            error_log("GIS SQL connection skipped: pdo_pgsql extension is not loaded.");
            $_conn_cache['gis'] = false;
            return null;
        }

        try {
            $port = defined('GIS_SQL_PORT') ? GIS_SQL_PORT : '5432';
            $dsn = "pgsql:host=" . GIS_SQL_HOST . ";port=" . $port . ";dbname=" . GIS_SQL_DATABASE;

            $_conn_cache['gis'] = new PDO($dsn, GIS_SQL_USERNAME, GIS_SQL_PASSWORD, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false
            ]);
        } catch (PDOException $e) {
            error_log("GIS SQL connection failed: " . $e->getMessage());
            $_conn_cache['gis'] = false;
            return null;
        }

        return $_conn_cache['gis'];
    }
}

// -------------------------------------------------------------------------
// Backward Compatibility: Global connection variables
// -------------------------------------------------------------------------
// These are populated lazily when first accessed by code using them directly.
// New code should prefer the get_conn_*() functions.
// -------------------------------------------------------------------------

// Initialize to null - actual connection happens on first use via getter
$conn_adl = null;
$conn_swim = null;
$conn_tmi = null;
$conn_ref = null;
$conn_gis = null;

// For backward compatibility with code that checks $conn_adl directly,
// we connect eagerly here. Pages that only need MySQL can define
// PERTI_MYSQL_ONLY before including connect.php to skip Azure connections.
// Pages that only need MySQL + ADL can define PERTI_ADL_ONLY to skip
// SWIM/TMI/REF connections (~1.5s saved per request).
if (!defined('PERTI_MYSQL_ONLY')) {
    $conn_adl = get_conn_adl();
    if (!defined('PERTI_ADL_ONLY')) {
        $conn_swim = get_conn_swim();
        $conn_tmi = get_conn_tmi();
        $conn_ref = get_conn_ref();
    }
    // GIS is NOT eagerly loaded — call get_conn_gis() only where needed.
    // Eager GIS loading was exhausting the PostgreSQL connection pool
    // (max ~25-50 connections) since every PHP-FPM worker opened one.
}

// -------------------------------------------------------------------------
// Helper: Trigger SWIM sync after ADL refresh
// -------------------------------------------------------------------------
// Call this function at the end of your ADL daemon refresh cycle to sync
// data to the SWIM_API database. This is the cheapest sync method ($0).
// -------------------------------------------------------------------------

if (!function_exists('swim_trigger_sync')) {
    /**
     * Trigger SWIM API data sync from VATSIM_ADL
     * Call this after ADL daemon refresh completes
     * 
     * @return array ['success' => bool, 'message' => string, 'stats' => array]
     */
    function swim_trigger_sync() {
        global $conn_swim;
        
        if (!$conn_swim) {
            return ['success' => false, 'message' => 'SWIM connection not available', 'stats' => []];
        }
        
        // Load the sync script if not already loaded
        $sync_script = __DIR__ . '/../scripts/swim_sync.php';
        if (file_exists($sync_script) && !function_exists('swim_sync_from_adl')) {
            require_once $sync_script;
        }
        
        if (function_exists('swim_sync_from_adl')) {
            return swim_sync_from_adl();
        }
        
        return ['success' => false, 'message' => 'SWIM sync script not found', 'stats' => []];
    }
}

// Load organization context helpers
require_once __DIR__ . '/org_context.php';
