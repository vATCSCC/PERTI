<?php
/**
 * GS API Common Utilities
 * 
 * Shared functions for Ground Stop API endpoints using TMI database
 * 
 * UPDATED: 2026-01-26 - Now uses VATSIM_TMI.tmi_programs instead of VATSIM_ADL.ntml
 */

// Prevent direct access
if (!defined('GS_API_INCLUDED')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Direct access not allowed']);
    exit;
}

// Load core dependencies
if (!defined('PERTI_LOADED')) {
    define('PERTI_LOADED', true);
}
require_once(__DIR__ . '/../../../load/config.php');
require_once(__DIR__ . '/../../../load/connect.php');

/**
 * Send JSON response and exit
 */
function respond_json($code, $payload) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Read request payload from JSON body or form data
 */
function read_request_payload() {
    $raw = file_get_contents('php://input');
    if ($raw !== false && strlen(trim($raw)) > 0) {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
    }
    return array_merge($_GET ?? [], $_POST ?? []);
}

/**
 * Get TMI database connection (VATSIM_TMI - programs table)
 * This is the primary connection for GS/GDP program management
 */
function get_tmi_conn() {
    global $conn_tmi;
    
    if (!$conn_tmi) {
        $errors = function_exists('sqlsrv_errors') ? sqlsrv_errors() : null;
        respond_json(500, [
            'status'  => 'error',
            'message' => 'TMI SQL connection not established. Check TMI_SQL_* constants in config.php.',
            'errors'  => $errors
        ]);
    }
    
    return $conn_tmi;
}

/**
 * Get ADL database connection (VATSIM_ADL - flight data)
 * Used for querying live flight data for GS modeling
 */
function get_adl_conn() {
    global $conn_adl;
    
    if (!$conn_adl) {
        $errors = function_exists('sqlsrv_errors') ? sqlsrv_errors() : null;
        respond_json(500, [
            'status'  => 'error',
            'message' => 'ADL SQL connection not established. Check ADL_SQL_* constants in config.php.',
            'errors'  => $errors
        ]);
    }
    
    return $conn_adl;
}

/**
 * Parse and validate UTC datetime string
 */
function parse_utc_datetime($s) {
    if (!is_string($s) || trim($s) === '') return null;
    
    try {
        $dt = new DateTime(trim($s));
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Split space/comma-delimited codes into array
 */
function split_codes($val) {
    if (is_array($val)) {
        $val = implode(' ', $val);
    }
    if (!is_string($val)) return [];
    
    $val = strtoupper(trim($val));
    if ($val === '') return [];
    
    $val = str_replace([",", ";", "\n", "\r", "\t"], " ", $val);
    $parts = preg_split('/\s+/', $val);
    
    $out = [];
    $seen = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '' && !isset($seen[$p])) {
            $seen[$p] = true;
            $out[] = $p;
        }
    }
    return $out;
}

/**
 * Execute a stored procedure with parameters
 * Returns: ['success' => bool, 'data' => mixed, 'error' => string|null]
 */
function exec_stored_proc($conn, $proc_name, $params = [], $output_params = []) {
    // Build parameter string
    $param_parts = [];
    $param_values = [];
    
    foreach ($params as $name => $value) {
        if ($value === null) {
            $param_parts[] = "@{$name} = NULL";
        } else {
            $param_parts[] = "@{$name} = ?";
            $param_values[] = $value;
        }
    }
    
    // Handle output parameters
    foreach ($output_params as $name => $default) {
        $param_parts[] = "@{$name} = @{$name} OUTPUT";
    }
    
    // Declare output variables
    $declare_sql = "";
    foreach ($output_params as $name => $default) {
        $declare_sql .= "DECLARE @{$name} INT = " . (int)$default . "; ";
    }
    
    // Build EXEC statement
    $exec_sql = "EXEC {$proc_name} " . implode(", ", $param_parts);
    
    // Build SELECT for output params
    $select_sql = "";
    if (count($output_params) > 0) {
        $select_parts = [];
        foreach ($output_params as $name => $default) {
            $select_parts[] = "@{$name} AS {$name}";
        }
        $select_sql = "; SELECT " . implode(", ", $select_parts);
    }
    
    $full_sql = $declare_sql . $exec_sql . $select_sql;
    
    // Execute
    $stmt = count($param_values) > 0 
        ? sqlsrv_query($conn, $full_sql, $param_values)
        : sqlsrv_query($conn, $full_sql);
    
    if ($stmt === false) {
        return [
            'success' => false,
            'data' => null,
            'error' => sqlsrv_errors()
        ];
    }
    
    // Get output parameters if any
    $output_values = [];
    if (count($output_params) > 0) {
        // Skip to result set with output params
        while (sqlsrv_next_result($stmt)) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            if ($row) {
                $output_values = $row;
                break;
            }
        }
    }
    
    sqlsrv_free_stmt($stmt);
    
    return [
        'success' => true,
        'data' => $output_values,
        'error' => null
    ];
}

/**
 * Fetch all rows from a query
 */
function fetch_all($conn, $sql, $params = []) {
    $stmt = count($params) > 0 
        ? sqlsrv_query($conn, $sql, $params)
        : sqlsrv_query($conn, $sql);
    
    if ($stmt === false) {
        return [
            'success' => false,
            'data' => null,
            'error' => sqlsrv_errors()
        ];
    }
    
    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Convert DateTime objects to ISO strings
        foreach ($row as $k => $v) {
            if ($v instanceof DateTimeInterface) {
                $row[$k] = $v->format("Y-m-d\\TH:i:s\\Z");
            }
        }
        $rows[] = $row;
    }
    
    sqlsrv_free_stmt($stmt);
    
    return [
        'success' => true,
        'data' => $rows,
        'error' => null
    ];
}

/**
 * Fetch single row from a query
 */
function fetch_one($conn, $sql, $params = []) {
    $result = fetch_all($conn, $sql, $params);
    if (!$result['success']) {
        return $result;
    }
    return [
        'success' => true,
        'data' => count($result['data']) > 0 ? $result['data'][0] : null,
        'error' => null
    ];
}

/**
 * Get current UTC time from server
 */
function get_server_utc($conn) {
    $result = fetch_one($conn, "SELECT SYSUTCDATETIME() AS utc_now");
    if ($result['success'] && $result['data']) {
        return $result['data']['utc_now'];
    }
    return date('Y-m-d H:i:s');
}
