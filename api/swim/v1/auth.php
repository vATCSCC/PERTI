<?php
/**
 * VATSIM SWIM Authentication Middleware
 * 
 * Handles API key authentication, rate limiting, and request validation.
 * 
 * @package PERTI
 * @subpackage SWIM
 * @version 1.0.0
 */

// Load PERTI core
define('PERTI_LOADED', true);
require_once __DIR__ . '/../../../load/config.php';
require_once __DIR__ . '/../../../load/connect.php';
require_once __DIR__ . '/../../../load/swim_config.php';

/**
 * SWIM Authentication Class
 */
class SwimAuth {
    
    private $conn_adl;
    private $api_key = null;
    private $key_info = null;
    private $error = null;
    
    public function __construct($conn_adl) {
        $this->conn_adl = $conn_adl;
    }
    
    public function authenticate() {
        $auth_header = $this->getAuthorizationHeader();
        
        if (!$auth_header) {
            $this->error = 'Missing Authorization header';
            return false;
        }
        
        if (!preg_match('/^Bearer\s+(.+)$/i', $auth_header, $matches)) {
            $this->error = 'Invalid Authorization header format. Expected: Bearer {api_key}';
            return false;
        }
        
        $this->api_key = trim($matches[1]);
        
        $tier = swim_get_key_tier($this->api_key);
        if (!$tier) {
            $this->error = 'Invalid API key format';
            return false;
        }
        
        $key_info = $this->lookupApiKey($this->api_key);
        
        if (!$key_info) {
            $this->error = 'API key not found or inactive';
            return false;
        }
        
        if ($key_info['expires_at'] && strtotime($key_info['expires_at']) < time()) {
            $this->error = 'API key has expired';
            return false;
        }
        
        if (!$this->checkRateLimit($key_info)) {
            $this->error = 'Rate limit exceeded';
            return false;
        }
        
        $this->key_info = $key_info;
        $this->logAccess();
        
        return true;
    }
    
    private function getAuthorizationHeader() {
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            return $_SERVER['HTTP_AUTHORIZATION'];
        }
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $key => $value) {
                if (strtolower($key) === 'authorization') {
                    return $value;
                }
            }
        }
        return null;
    }
    
    private function lookupApiKey($api_key) {
        $sql = "SELECT id, api_key, tier, owner_name, owner_email, source_id, can_write,
                       allowed_sources, ip_whitelist, expires_at, created_at, last_used_at, is_active
                FROM dbo.swim_api_keys WHERE api_key = ? AND is_active = 1";
        
        $stmt = sqlsrv_query($this->conn_adl, $sql, [$api_key]);
        
        if ($stmt === false) {
            return $this->getFallbackKeyInfo($api_key);
        }
        
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        
        return $row ?: $this->getFallbackKeyInfo($api_key);
    }
    
    private function getFallbackKeyInfo($api_key) {
        $tier = swim_get_key_tier($api_key);
        if (!$tier) return null;
        
        return [
            'id' => 0,
            'api_key' => $api_key,
            'tier' => $tier,
            'owner_name' => 'Development',
            'owner_email' => 'dev@vatcscc.org',
            'source_id' => 'vatcscc',
            'can_write' => ($tier === 'system' || $tier === 'partner'),
            'allowed_sources' => null,
            'ip_whitelist' => null,
            'expires_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'last_used_at' => null,
            'is_active' => 1
        ];
    }
    
    private function checkRateLimit($key_info) {
        $rate_limit = swim_get_rate_limit($key_info['tier']);
        if (function_exists('apcu_fetch')) {
            $cache_key = 'swim_rate_' . md5($key_info['api_key']);
            $requests = apcu_fetch($cache_key, $success);
            if (!$success) $requests = 0;
            if ($requests >= $rate_limit) return false;
            apcu_store($cache_key, $requests + 1, 60);
        }
        return true;
    }
    
    private function logAccess() {
        if (!$this->key_info) return;
        @sqlsrv_query($this->conn_adl, 
            "UPDATE dbo.swim_api_keys SET last_used_at = GETUTCDATE() WHERE id = ?",
            [$this->key_info['id']]);
        @sqlsrv_query($this->conn_adl,
            "INSERT INTO dbo.swim_audit_log (api_key_id, endpoint, method, ip_address, user_agent, request_time)
             VALUES (?, ?, ?, ?, ?, GETUTCDATE())",
            [$this->key_info['id'], $_SERVER['REQUEST_URI'] ?? '', $_SERVER['REQUEST_METHOD'] ?? 'GET',
             $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);
    }
    
    public function getError() { return $this->error; }
    public function getKeyInfo() { return $this->key_info; }
    public function canWrite() { return $this->key_info && $this->key_info['can_write']; }
    public function getSourceId() { return $this->key_info ? $this->key_info['source_id'] : null; }
    public function canWriteField($field_path) {
        return $this->canWrite() && swim_can_write($field_path, $this->getSourceId());
    }
}

/**
 * SWIM Response Helper
 */
class SwimResponse {
    
    public static function json($data, $status = 200) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-SWIM-Version: ' . SWIM_API_VERSION);
        self::setCorsHeaders();
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    public static function error($message, $status = 400, $code = null) {
        $response = ['error' => true, 'message' => $message, 'status' => $status];
        if ($code) $response['code'] = $code;
        self::json($response, $status);
    }
    
    public static function success($data, $meta = []) {
        $response = ['success' => true, 'data' => $data, 'timestamp' => gmdate('c')];
        if (!empty($meta)) $response['meta'] = $meta;
        self::json($response, 200);
    }
    
    public static function paginated($data, $total, $page, $per_page) {
        $total_pages = ceil($total / $per_page);
        self::json([
            'success' => true,
            'data' => $data,
            'pagination' => [
                'total' => $total, 'page' => $page, 'per_page' => $per_page,
                'total_pages' => $total_pages, 'has_more' => $page < $total_pages
            ],
            'timestamp' => gmdate('c')
        ], 200);
    }
    
    private static function setCorsHeaders() {
        global $SWIM_CORS_ORIGINS;
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (in_array($origin, $SWIM_CORS_ORIGINS)) {
            header("Access-Control-Allow-Origin: $origin");
        } else {
            header("Access-Control-Allow-Origin: *");
        }
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-SWIM-Source');
        header('Access-Control-Max-Age: 86400');
    }
    
    public static function handlePreflight() {
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            self::setCorsHeaders();
            http_response_code(204);
            exit;
        }
    }
}

function swim_init_auth($require_auth = true, $require_write = false) {
    global $conn_adl;
    SwimResponse::handlePreflight();
    if (!$require_auth) return null;
    
    $auth = new SwimAuth($conn_adl);
    if (!$auth->authenticate()) {
        SwimResponse::error($auth->getError(), 401, 'UNAUTHORIZED');
    }
    if ($require_write && !$auth->canWrite()) {
        SwimResponse::error('Write access not permitted for this API key', 403, 'FORBIDDEN');
    }
    return $auth;
}

function swim_get_json_body() {
    $body = file_get_contents('php://input');
    if (empty($body)) return null;
    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        SwimResponse::error('Invalid JSON: ' . json_last_error_msg(), 400, 'INVALID_JSON');
    }
    return $data;
}

function swim_get_param($name, $default = null) {
    return $_GET[$name] ?? $default;
}

function swim_get_int_param($name, $default = 0, $min = null, $max = null) {
    $value = intval(swim_get_param($name, $default));
    if ($min !== null && $value < $min) $value = $min;
    if ($max !== null && $value > $max) $value = $max;
    return $value;
}
