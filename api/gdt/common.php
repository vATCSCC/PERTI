<?php
/**
 * GDT API Common Utilities
 * 
 * Shared functions for Ground Delay Tools API endpoints.
 * Uses VATSIM_TMI database for program/slot management and
 * VATSIM_ADL database for flight data.
 * 
 * @version 1.0.0
 * @date 2026-01-21
 */

// Prevent direct access
if (!defined('GDT_API_INCLUDED')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Direct access not allowed']);
    exit;
}

// ============================================================================
// Response Helpers
// ============================================================================

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

// ============================================================================
// Session & Database Connections
// ============================================================================

// Start session BEFORE loading config/connect, because connect.php's closing
// ?> tag outputs a trailing newline that sends headers, preventing later
// session_start() calls from succeeding (headers_sent() returns true).
require_once(__DIR__ . '/../../sessions/handler.php');

// Load core dependencies (same pattern as /api/tmi/helpers.php)
if (!defined('PERTI_LOADED')) {
    define('PERTI_LOADED', true);
}
require_once(__DIR__ . '/../../load/config.php');
require_once(__DIR__ . '/../../load/connect.php');

// ============================================================================
// Authentication
// ============================================================================

/**
 * Require authenticated session for write operations.
 * Sends 401 and exits if not authenticated.
 * Session is already started by the module-level include above.
 * @return string The authenticated user's VATSIM CID
 */
function gdt_require_auth() {
    if (!isset($_SESSION['VATSIM_CID']) || empty($_SESSION['VATSIM_CID'])) {
        respond_json(401, [
            'status' => 'error',
            'message' => 'Authentication required. Please log in.'
        ]);
    }

    return $_SESSION['VATSIM_CID'];
}

/**
 * Get TMI database connection (VATSIM_TMI - program/slot management)
 * Uses the global connection established in connect.php
 * @return resource|null SQLSRV connection resource
 */
function gdt_get_conn_tmi() {
    global $conn_tmi;
    
    if (!$conn_tmi) {
        $errors = filter_sqlsrv_errors();
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
 * Uses the global connection established in connect.php
 * @return resource|null SQLSRV connection resource
 */
function gdt_get_conn_adl() {
    global $conn_adl;
    
    if (!$conn_adl) {
        $errors = filter_sqlsrv_errors();
        respond_json(500, [
            'status'  => 'error',
            'message' => 'ADL SQL connection not established. Check ADL_SQL_* constants in config.php.',
            'errors'  => $errors
        ]);
    }
    
    return $conn_adl;
}

/**
 * Filter out informational SQL Server messages
 */
function filter_sqlsrv_errors() {
    if (!function_exists('sqlsrv_errors')) {
        return null;
    }
    
    $all_errors = sqlsrv_errors();
    if (!$all_errors) {
        return null;
    }
    
    // Filter out info messages (5701=Changed database, 5703=Changed language)
    $errors = array_filter($all_errors, function($e) {
        $code = isset($e['code']) ? $e['code'] : 0;
        return !in_array($code, [5701, 5703]);
    });
    
    return empty($errors) ? null : array_values($errors);
}

// ============================================================================
// Data Type Helpers
// ============================================================================

/**
 * Parse and validate UTC datetime string
 * @param string $s Input datetime string
 * @return string|null SQL Server compatible datetime or null
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
 * Convert DateTime to ISO 8601 string for JSON
 * @param mixed $val DateTime object or value
 * @return string|mixed ISO string or original value
 */
function datetime_to_iso($val) {
    if ($val === null) return null;
    if ($val instanceof DateTimeInterface) {
        $utc = clone $val;
        if (method_exists($utc, 'setTimezone')) {
            $utc->setTimezone(new DateTimeZone('UTC'));
        }
        return $utc->format('Y-m-d\TH:i:s') . 'Z';
    }
    return $val;
}

/**
 * Split space/comma-delimited codes into array
 * @param mixed $val Input string or array
 * @return array Array of uppercase codes
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

// ============================================================================
// Query Helpers
// ============================================================================

/**
 * Fetch all rows from a query, converting DateTime objects
 * @param resource $conn SQLSRV connection
 * @param string $sql SQL query
 * @param array $params Query parameters
 * @return array ['success' => bool, 'data' => array|null, 'error' => mixed]
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
                $row[$k] = datetime_to_iso($v);
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
 * @param resource $conn SQLSRV connection
 * @param string $sql SQL query
 * @param array $params Query parameters
 * @return array ['success' => bool, 'data' => array|null, 'error' => mixed]
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
 * Fetch a single scalar value
 * @param resource $conn SQLSRV connection
 * @param string $sql SQL query
 * @param array $params Query parameters
 * @return array [value, error]
 */
function fetch_value($conn, $sql, $params = []) {
    $stmt = count($params) > 0 
        ? sqlsrv_query($conn, $sql, $params)
        : sqlsrv_query($conn, $sql);
    
    if ($stmt === false) {
        return [null, sqlsrv_errors()];
    }
    
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC);
    sqlsrv_free_stmt($stmt);
    
    if (!$row) {
        return [null, null];
    }
    
    $val = $row[0];
    if ($val instanceof DateTimeInterface) {
        $val = datetime_to_iso($val);
    }
    
    return [$val, null];
}

/**
 * Execute a query that doesn't return results
 * @param resource $conn SQLSRV connection
 * @param string $sql SQL query
 * @param array $params Query parameters
 * @return array ['success' => bool, 'rows_affected' => int|null, 'error' => mixed]
 */
function execute_query($conn, $sql, $params = []) {
    $stmt = count($params) > 0 
        ? sqlsrv_query($conn, $sql, $params)
        : sqlsrv_query($conn, $sql);
    
    if ($stmt === false) {
        return [
            'success' => false,
            'rows_affected' => null,
            'error' => sqlsrv_errors()
        ];
    }
    
    $rows = sqlsrv_rows_affected($stmt);
    sqlsrv_free_stmt($stmt);
    
    return [
        'success' => true,
        'rows_affected' => $rows,
        'error' => null
    ];
}

/**
 * Get current UTC time from SQL Server
 * @param resource $conn SQLSRV connection
 * @return string ISO formatted UTC time
 */
function get_server_utc($conn) {
    list($utc, $err) = fetch_value($conn, "SELECT SYSUTCDATETIME()");
    return $utc ?? date('Y-m-d\TH:i:s') . 'Z';
}

// ============================================================================
// TMI-Specific Helpers
// ============================================================================

/**
 * Program types supported by GDT
 */
define('GDT_PROGRAM_TYPES', [
    'GS' => [
        'name' => 'Ground Stop',
        'has_slots' => false,
        'has_rates' => false
    ],
    'GDP-DAS' => [
        'name' => 'GDP - Delay Assignment System',
        'has_slots' => true,
        'has_rates' => true
    ],
    'GDP-GAAP' => [
        'name' => 'GDP - General Aviation Airport Program',
        'has_slots' => true,
        'has_rates' => true,
        'has_reserve' => true
    ],
    'GDP-UDP' => [
        'name' => 'GDP - Unified Delay Program',
        'has_slots' => true,
        'has_rates' => true,
        'has_reserve' => true
    ],
    'AFP' => [
        'name' => 'Airspace Flow Program',
        'has_slots' => true,
        'has_rates' => true
    ]
]);

/**
 * Valid program statuses
 */
define('GDT_PROGRAM_STATUSES', [
    'PROPOSED',   // Created but not activated
    'MODELING',   // Slots generated, being modeled
    'ACTIVE',     // Live program
    'EXTENDED',   // Extended from original end time
    'SUPERSEDED', // Replaced by revision
    'COMPLETED',  // Finished normally
    'CANCELLED',  // Cancelled before completion
    'PURGED'      // Cancelled and flight controls cleared
]);

/**
 * Validate program type
 */
function is_valid_program_type($type) {
    return isset(GDT_PROGRAM_TYPES[$type]);
}

/**
 * Get program from TMI database
 * @param resource $conn TMI connection
 * @param int $program_id Program ID
 * @return array|null Program record or null
 */
function get_program($conn, $program_id) {
    $result = fetch_one($conn, "SELECT * FROM dbo.tmi_programs WHERE program_id = ?", [(int)$program_id]);
    return $result['success'] ? $result['data'] : null;
}

/**
 * Get active program for an element
 * @param resource $conn TMI connection
 * @param string $ctl_element Control element (airport code)
 * @return array|null Program record or null
 */
function get_active_program($conn, $ctl_element) {
    $result = fetch_one($conn, 
        "SELECT * FROM dbo.tmi_programs WHERE ctl_element = ? AND is_active = 1",
        [strtoupper(trim($ctl_element))]
    );
    return $result['success'] ? $result['data'] : null;
}
