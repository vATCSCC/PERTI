<?php
/**
 * ADL-only Database Connection
 * 
 * Connects to Azure SQL without requiring MySQL.
 * For use by API endpoints that only need the ADL database.
 */

// Load config and input helpers
require_once __DIR__ . '/../../load/config.php';
require_once __DIR__ . '/../../load/input.php';

// Helper for ADL SQL Server error messages
if (!function_exists('adl_sql_error_message')) {
    function adl_sql_error_message()
    {
        if (!function_exists('sqlsrv_errors')) {
            return "sqlsrv extension not loaded";
        }
        $errs = sqlsrv_errors(SQLSRV_ERR_ERRORS);
        if (!$errs) {
            return "Unknown error";
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

// ADL Database (Azure SQL / SQL Server)
$conn_adl = null;

if (defined('ADL_SQL_HOST') && defined('ADL_SQL_DATABASE') &&
    defined('ADL_SQL_USERNAME') && defined('ADL_SQL_PASSWORD')) {

    if (function_exists('sqlsrv_connect')) {
        $connectionInfo = [
            "Database" => ADL_SQL_DATABASE,
            "UID"      => ADL_SQL_USERNAME,
            "PWD"      => ADL_SQL_PASSWORD,
            "ConnectionPooling" => 1
        ];

        $conn_adl = sqlsrv_connect(ADL_SQL_HOST, $connectionInfo);

        if ($conn_adl === false) {
            error_log("ADL SQL connection failed: " . adl_sql_error_message());
        }
    } else {
        error_log("ADL SQL connection skipped: sqlsrv extension is not loaded.");
    }
}
