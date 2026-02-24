<?php
/**
 * TMI API Helpers
 * 
 * Common functions and classes for TMI API endpoints.
 * Handles authentication, response formatting, and database operations.
 * 
 * @package PERTI
 * @subpackage TMI
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('PERTI_LOADED')) {
    define('PERTI_LOADED', true);
}

// Load core dependencies
require_once __DIR__ . '/../../load/config.php';
require_once __DIR__ . '/../../load/connect.php';

// Load org context if available
$orgContextPath = __DIR__ . '/../../load/org_context.php';
if (file_exists($orgContextPath)) {
    require_once $orgContextPath;
}

/**
 * Get active org code for TMI operations.
 * Uses org_context helper if available, falls back to session, then 'vatcscc'.
 */
function tmi_get_org_code(): string {
    if (function_exists('get_org_code')) {
        return get_org_code();
    }
    return $_SESSION['ORG_CODE'] ?? 'vatcscc';
}

/**
 * Check if the current user has global TMI access (sees all orgs).
 */
function tmi_is_global(): bool {
    return function_exists('is_org_global') && is_org_global();
}

/**
 * Get org code for TMI write operations (INSERT).
 * Returns the user's primary non-global org, never 'global'.
 */
function tmi_get_write_org_code(): string {
    $code = tmi_get_org_code();
    if ($code === 'global') {
        $all = $_SESSION['ORG_ALL'] ?? ['vatcscc'];
        foreach ($all as $o) {
            if ($o !== 'global') return $o;
        }
        return 'vatcscc';
    }
    return $code;
}

/**
 * Get SQL fragment for org_code filtering.
 * Returns '1=1' for global users (no filter), 'org_code = ?' otherwise.
 */
function tmi_org_scope_sql(): string {
    return tmi_is_global() ? '1=1' : 'org_code = ?';
}

/**
 * Get parameters for org_code filtering.
 * Returns [] for global users, [org_code] otherwise.
 */
function tmi_org_scope_params(): array {
    return tmi_is_global() ? [] : [tmi_get_org_code()];
}

/**
 * Add org_code filter to WHERE clause arrays (for list queries).
 * Skips the filter entirely for global users.
 */
function tmi_add_org_filter(array &$where, array &$params): void {
    if (!tmi_is_global()) {
        $where[] = 'org_code = ?';
        $params[] = tmi_get_org_code();
    }
}

/**
 * TMI Response Helper
 */
class TmiResponse {
    
    /**
     * Send JSON response
     */
    public static function json($data, $status = 200) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-TMI-Version: 1.0');
        self::setCorsHeaders();
        
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    /**
     * Send error response
     */
    public static function error($message, $status = 400, $code = null) {
        $response = [
            'success' => false,
            'error' => true,
            'message' => $message,
            'status' => $status
        ];
        if ($code) {
            $response['code'] = $code;
        }
        self::json($response, $status);
    }
    
    /**
     * Send success response
     */
    public static function success($data, $meta = []) {
        $response = [
            'success' => true,
            'data' => $data,
            'timestamp' => gmdate('c')
        ];
        if (!empty($meta)) {
            $response['meta'] = $meta;
        }
        self::json($response, 200);
    }
    
    /**
     * Send paginated response
     */
    public static function paginated($data, $total, $page, $per_page) {
        $total_pages = $per_page > 0 ? ceil($total / $per_page) : 1;
        self::json([
            'success' => true,
            'data' => $data,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => $total_pages,
                'has_more' => $page < $total_pages
            ],
            'timestamp' => gmdate('c')
        ], 200);
    }
    
    /**
     * Send created response
     */
    public static function created($data, $location = null) {
        if ($location) {
            header("Location: $location");
        }
        self::json([
            'success' => true,
            'data' => $data,
            'timestamp' => gmdate('c')
        ], 201);
    }
    
    /**
     * Send no content response
     */
    public static function noContent() {
        http_response_code(204);
        exit;
    }
    
    /**
     * Set CORS headers
     */
    private static function setCorsHeaders() {
        $allowed_origins = [
            'https://perti.vatcscc.org',
            'https://vatcscc.azurewebsites.net',
            'http://localhost',
            'http://localhost:8080'
        ];
        
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (in_array($origin, $allowed_origins)) {
            header("Access-Control-Allow-Origin: $origin");
        } elseif (strpos($origin, 'localhost') !== false) {
            header("Access-Control-Allow-Origin: $origin");
        }
        
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With, X-API-Key');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');
    }
    
    /**
     * Handle OPTIONS preflight request
     */
    public static function handlePreflight() {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            self::setCorsHeaders();
            http_response_code(204);
            exit;
        }
    }
}

/**
 * TMI Authentication
 * 
 * For internal PERTI use - checks session-based auth or API key
 */
class TmiAuth {
    
    private $user = null;
    private $is_authenticated = false;
    private $error = null;
    
    /**
     * Check if user is authenticated
     * 
     * @param bool $require_auth Require authentication (default: true)
     * @return bool
     */
    public function authenticate($require_auth = true) {
        // Handle preflight
        TmiResponse::handlePreflight();
        
        // Check for session authentication (PERTI web)
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        
        if (isset($_SESSION['cid']) && !empty($_SESSION['cid'])) {
            $this->user = [
                'cid' => $_SESSION['cid'],
                'name' => $_SESSION['name'] ?? 'Unknown',
                'source' => 'session'
            ];
            $this->is_authenticated = true;
            return true;
        }
        
        // Check for API key (internal services)
        $api_key = $this->getApiKey();
        if ($api_key) {
            // Accept keys starting with 'tmi_' as internal
            if (preg_match('/^tmi_(sys|svc|bot)_/', $api_key)) {
                $this->user = [
                    'cid' => 'system',
                    'name' => 'System',
                    'source' => 'api_key',
                    'key_prefix' => substr($api_key, 0, 12)
                ];
                $this->is_authenticated = true;
                return true;
            }
        }
        
        // Check for Discord bot authentication
        $discord_sig = $_SERVER['HTTP_X_DISCORD_SIGNATURE'] ?? null;
        if ($discord_sig && defined('DISCORD_PUBLIC_KEY')) {
            $this->user = [
                'cid' => 'discord_bot',
                'name' => 'Discord Bot',
                'source' => 'discord'
            ];
            $this->is_authenticated = true;
            return true;
        }
        
        if (!$require_auth) {
            return true;
        }
        
        $this->error = 'Authentication required';
        return false;
    }
    
    /**
     * Get API key from request
     */
    private function getApiKey() {
        // Bearer token
        $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? 
                       $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if ($auth_header && preg_match('/^Bearer\s+(.+)$/i', $auth_header, $matches)) {
            return trim($matches[1]);
        }
        
        // X-API-Key header
        if (isset($_SERVER['HTTP_X_API_KEY'])) {
            return trim($_SERVER['HTTP_X_API_KEY']);
        }
        
        // Query parameter (for internal debugging only)
        if (isset($_GET['api_key']) && defined('DEV') && DEV === true) {
            return trim($_GET['api_key']);
        }
        
        return null;
    }
    
    public function getUser() { return $this->user; }
    public function getUserId() { return $this->user['cid'] ?? null; }
    public function getUserName() { return $this->user['name'] ?? 'Unknown'; }
    public function getSource() { return $this->user['source'] ?? 'unknown'; }
    public function getError() { return $this->error; }
    public function isAuthenticated() { return $this->is_authenticated; }
}

/**
 * Initialize TMI API request
 * 
 * @param bool $require_auth Require authentication
 * @return TmiAuth|null Auth object or null if auth not required
 */
function tmi_init($require_auth = true) {
    global $conn_tmi;
    
    TmiResponse::handlePreflight();
    
    // Check database connection
    if (!$conn_tmi) {
        TmiResponse::error('TMI database connection not available', 503, 'SERVICE_UNAVAILABLE');
    }
    
    if (!$require_auth) {
        return null;
    }
    
    $auth = new TmiAuth();
    if (!$auth->authenticate($require_auth)) {
        TmiResponse::error($auth->getError(), 401, 'UNAUTHORIZED');
    }
    
    return $auth;
}

/**
 * Get JSON body from request
 */
function tmi_get_json_body() {
    $body = file_get_contents('php://input');
    if (empty($body)) {
        return null;
    }
    
    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        TmiResponse::error('Invalid JSON: ' . json_last_error_msg(), 400, 'INVALID_JSON');
    }
    
    return $data;
}

/**
 * Get query parameter
 */
function tmi_param($name, $default = null) {
    return $_GET[$name] ?? $default;
}

/**
 * Get integer query parameter with bounds
 */
function tmi_int_param($name, $default = 0, $min = null, $max = null) {
    $value = intval(tmi_param($name, $default));
    if ($min !== null && $value < $min) $value = $min;
    if ($max !== null && $value > $max) $value = $max;
    return $value;
}

/**
 * Get request method
 */
function tmi_method() {
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

/**
 * Log TMI event to database
 */
function tmi_log_event($entity_type, $entity_id, $event_type, $options = []) {
    global $conn_tmi;
    
    if (!$conn_tmi) return false;
    
    $sql = "EXEC sp_LogTmiEvent 
        @entity_type = ?,
        @entity_id = ?,
        @event_type = ?,
        @event_detail = ?,
        @source_type = ?,
        @source_id = ?,
        @actor_id = ?,
        @actor_name = ?,
        @actor_ip = ?";
    
    $params = [
        $entity_type,
        $entity_id,
        $event_type,
        $options['detail'] ?? null,
        $options['source_type'] ?? 'API',
        $options['source_id'] ?? null,
        $options['actor_id'] ?? null,
        $options['actor_name'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null
    ];
    
    return sqlsrv_query($conn_tmi, $sql, $params) !== false;
}

/**
 * Generate next advisory number (RESERVES - increments the counter)
 * @return string|null Advisory number like "ADVZY 001" or null on failure
 */
function tmi_next_advisory_number() {
    global $conn_tmi;

    if (!$conn_tmi) return null;

    require_once __DIR__ . '/AdvisoryNumber.php';
    $advNum = new AdvisoryNumber($conn_tmi, 'sqlsrv');
    return $advNum->reserve();
}

/**
 * Peek at next advisory number (does NOT increment)
 * @return string Advisory number like "ADVZY 001"
 */
function tmi_peek_advisory_number() {
    global $conn_tmi;

    if (!$conn_tmi) return 'ADVZY 001';

    require_once __DIR__ . '/AdvisoryNumber.php';
    $advNum = new AdvisoryNumber($conn_tmi, 'sqlsrv');
    return $advNum->peek();
}

/**
 * Get current (last used) advisory number for today
 * @return int Sequence number (0 if none used today)
 */
function tmi_current_advisory_number() {
    global $conn_tmi;

    if (!$conn_tmi) return 0;

    require_once __DIR__ . '/AdvisoryNumber.php';
    $advNum = new AdvisoryNumber($conn_tmi, 'sqlsrv');
    return $advNum->current();
}

/**
 * Get all advisory number info (previous, current, next)
 * @param bool $reserve If true, reserves the next number
 * @return array ['previous' => int, 'current' => int, 'next' => string, ...]
 */
function tmi_advisory_number_info($reserve = false) {
    global $conn_tmi;

    if (!$conn_tmi) {
        return [
            'previous' => 0,
            'previous_formatted' => null,
            'current' => 0,
            'current_formatted' => null,
            'next' => 'ADVZY 001',
            'next_raw' => 1,
            'date' => gmdate('Y-m-d'),
            'reserved' => false
        ];
    }

    require_once __DIR__ . '/AdvisoryNumber.php';
    $advNum = new AdvisoryNumber($conn_tmi, 'sqlsrv');
    return $advNum->getAll($reserve);
}

/**
 * Format datetime for response (handles SQL Server DateTime objects)
 */
function tmi_format_datetime($datetime) {
    if ($datetime === null) return null;
    if ($datetime instanceof DateTime) {
        return $datetime->format('c');
    }
    if (is_string($datetime)) {
        return date('c', strtotime($datetime));
    }
    return null;
}

/**
 * Parse datetime from request
 */
function tmi_parse_datetime($value) {
    if (empty($value)) return null;
    
    // Handle ISO 8601 format
    $dt = DateTime::createFromFormat(DateTime::ATOM, $value);
    if ($dt) return $dt->format('Y-m-d H:i:s');
    
    // Handle various formats
    $formats = [
        'Y-m-d\TH:i:s',
        'Y-m-d\TH:i:sP',
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'Y-m-d'
    ];
    
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $value);
        if ($dt) return $dt->format('Y-m-d H:i:s');
    }
    
    // Last resort - let PHP figure it out
    $ts = strtotime($value);
    if ($ts !== false) {
        return date('Y-m-d H:i:s', $ts);
    }
    
    return null;
}

/**
 * Convert SQL Server result row to clean array (handles DateTime objects)
 */
function tmi_clean_row($row) {
    if (!is_array($row)) return $row;
    
    $clean = [];
    foreach ($row as $key => $value) {
        if ($value instanceof DateTime) {
            $clean[$key] = $value->format('c');
        } else {
            $clean[$key] = $value;
        }
    }
    return $clean;
}

/**
 * Fetch all rows from SQL Server result
 */
function tmi_fetch_all($result) {
    $rows = [];
    if ($result) {
        while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
            $rows[] = tmi_clean_row($row);
        }
    }
    return $rows;
}

/**
 * Get count from a table with optional WHERE clause
 */
function tmi_count($table, $where = '', $params = []) {
    global $conn_tmi;
    
    $sql = "SELECT COUNT(*) as cnt FROM dbo.$table";
    if ($where) {
        $sql .= " WHERE $where";
    }
    
    $result = sqlsrv_query($conn_tmi, $sql, $params);
    if ($result && ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC))) {
        return (int)$row['cnt'];
    }
    
    return 0;
}

/**
 * Execute a simple query and return all rows
 */
function tmi_query($sql, $params = []) {
    global $conn_tmi;
    
    $result = sqlsrv_query($conn_tmi, $sql, $params);
    if ($result === false) {
        $errors = sqlsrv_errors();
        error_log("TMI Query Error: " . print_r($errors, true));
        return false;
    }
    
    return tmi_fetch_all($result);
}

/**
 * Execute a query and return single row
 */
function tmi_query_one($sql, $params = []) {
    global $conn_tmi;
    
    $result = sqlsrv_query($conn_tmi, $sql, $params);
    if ($result === false) {
        return null;
    }
    
    $row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC);
    return $row ? tmi_clean_row($row) : null;
}

/**
 * Insert a row and return the new ID
 */
function tmi_insert($table, $data) {
    global $conn_tmi;

    // Auto-inject org_code if not already present (never store 'global')
    if (!isset($data['org_code'])) {
        $data['org_code'] = tmi_get_write_org_code();
    }

    $columns = array_keys($data);
    $placeholders = array_fill(0, count($columns), '?');
    $values = array_values($data);
    
    $sql = "INSERT INTO dbo.$table (" . implode(', ', $columns) . ") 
            VALUES (" . implode(', ', $placeholders) . ");
            SELECT SCOPE_IDENTITY() AS id;";
    
    $result = sqlsrv_query($conn_tmi, $sql, $values);
    if ($result === false) {
        $errors = sqlsrv_errors();
        error_log("TMI Insert Error: " . print_r($errors, true));
        return false;
    }
    
    // Move to the result set with the ID
    sqlsrv_next_result($result);
    $row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC);
    
    return $row ? (int)$row['id'] : false;
}

/**
 * Update rows in a table
 */
function tmi_update($table, $data, $where, $where_params = []) {
    global $conn_tmi;

    $sets = [];
    $values = [];
    foreach ($data as $col => $val) {
        $sets[] = "$col = ?";
        $values[] = $val;
    }

    // Scope updates to the active org (global users can update any org's records)
    if (!tmi_is_global()) {
        $where .= ' AND org_code = ?';
        $where_params[] = tmi_get_org_code();
    }

    $sql = "UPDATE dbo.$table SET " . implode(', ', $sets) . " WHERE $where";
    $params = array_merge($values, $where_params);
    
    $result = sqlsrv_query($conn_tmi, $sql, $params);
    if ($result === false) {
        $errors = sqlsrv_errors();
        error_log("TMI Update Error: " . print_r($errors, true));
        return false;
    }
    
    return sqlsrv_rows_affected($result);
}

/**
 * Delete rows from a table
 */
function tmi_delete($table, $where, $params = []) {
    global $conn_tmi;

    // Scope deletes to the active org (global users can delete any org's records)
    if (!tmi_is_global()) {
        $where .= ' AND org_code = ?';
        $params[] = tmi_get_org_code();
    }

    $sql = "DELETE FROM dbo.$table WHERE $where";

    $result = sqlsrv_query($conn_tmi, $sql, $params);
    if ($result === false) {
        return false;
    }
    
    return sqlsrv_rows_affected($result);
}

/**
 * Get SQL Server errors as string
 */
function tmi_sql_errors() {
    $errors = sqlsrv_errors();
    if (!$errors) return '';
    
    $messages = [];
    foreach ($errors as $e) {
        $messages[] = ($e['SQLSTATE'] ?? '') . ' ' . ($e['code'] ?? '') . ': ' . ($e['message'] ?? '');
    }
    return implode(' | ', $messages);
}
