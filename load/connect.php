<?php

// load/connect.php
// Establishes connections to the primary MySQL database
// and (optionally) the ADL Azure SQL database.

include_once("config.php");

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
try {
    $conn_pdo = new PDO("mysql:host={$sql_host};dbname={$sql_dbname}", $sql_user, $sql_passwd);
    $conn_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $ex) {
    die('PDO connection failed: ' . $ex->getMessage());
}

// Establish Connection (MySQLi)
$conn_sqli = mysqli_connect($sql_host, $sql_user, $sql_passwd, $sql_dbname);

if (!$conn_sqli) {
    die('Connection failed: ' . mysqli_connect_error());
}

// -------------------------------------------------------------------------
// ADL Database (Azure SQL / SQL Server)
// -------------------------------------------------------------------------
// Optional: only connect if ADL_SQL_* constants are defined and sqlsrv exists.
//
// Usage elsewhere in the codebase:
//   - Check if $conn_adl is not null before using it
//   - Do NOT die() on failure here; the rest of the site should still work
// -------------------------------------------------------------------------

$conn_adl = null;
$conn_swim = null;

if (defined('ADL_SQL_HOST') && defined('ADL_SQL_DATABASE') &&
    defined('ADL_SQL_USERNAME') && defined('ADL_SQL_PASSWORD')) {

    if (function_exists('sqlsrv_connect')) {
        $connectionInfo = [
            "Database" => ADL_SQL_DATABASE,
            "UID"      => ADL_SQL_USERNAME,
            "PWD"      => ADL_SQL_PASSWORD
        ];

        $conn_adl = sqlsrv_connect(ADL_SQL_HOST, $connectionInfo);

        if ($conn_adl === false) {
            // Log, but do not kill the request
            error_log("ADL SQL connection failed: " . adl_sql_error_message());
        }
    } else {
        // sqlsrv extension not available; log and continue
        error_log("ADL SQL connection skipped: sqlsrv extension is not loaded.");
    }
} else {
    // ADL constants not defined; this is fine if ADL is optional for this environment
    // error_log("ADL SQL constants not defined; ADL connection not established.");
}

// -------------------------------------------------------------------------
// SWIM API Database (Azure SQL Basic - dedicated for public API)
// -------------------------------------------------------------------------
// This is a fixed-cost ($5/mo) database to serve public SWIM API queries
// without incurring Serverless scaling costs on VATSIM_ADL.
// Data is synced from VATSIM_ADL every 15 seconds via sp_Swim_SyncFromAdl.
// -------------------------------------------------------------------------

if (defined('SWIM_SQL_HOST') && defined('SWIM_SQL_DATABASE') &&
    defined('SWIM_SQL_USERNAME') && defined('SWIM_SQL_PASSWORD')) {

    if (function_exists('sqlsrv_connect')) {
        $swimConnectionInfo = [
            "Database" => SWIM_SQL_DATABASE,
            "UID"      => SWIM_SQL_USERNAME,
            "PWD"      => SWIM_SQL_PASSWORD
        ];

        $conn_swim = sqlsrv_connect(SWIM_SQL_HOST, $swimConnectionInfo);

        if ($conn_swim === false) {
            // Log, but do not kill the request - fall back to $conn_adl if needed
            error_log("SWIM API SQL connection failed: " . adl_sql_error_message());
        }
    }
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

?>
