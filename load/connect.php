<?php

// load/connect.php
// Establishes connections to the primary MySQL database
// and (optionally) the ADL Azure SQL database.

include("config.php");

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

?>
