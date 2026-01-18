<?php
/**
 * VATSWIM Authentication Middleware
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
    
    private $conn_swim;
    private $api_key = null;
    private $key_info = null;
    private $error = null;
    
    public function __construct($conn_swim) {
        $this->conn_swim = $conn_swim;
    }
    
    public function authenticate() {
        $auth_header = $this->getAuthorizationHeader();

        // Fallback: X-API-Key header (IIS doesn't block custom headers)
        $api_key_header = $_SERVER['HTTP_X_API_KEY'] ?? null;

        // DEBUG: Add response headers showing what server received
        header('X-SWIM-Debug-AuthHeader: ' . ($auth_header ? 'present' : 'missing'));
        header('X-SWIM-Debug-XApiKey: ' . ($api_key_header ? 'present' : 'missing'));
        header('X-SWIM-Debug-Method: ' . ($_SERVER['REQUEST_METHOD'] ?? 'unknown'));

        if (!$auth_header && !$api_key_header) {
            $this->error = 'Missing Authorization header. Use "Authorization: Bearer {api_key}" or "X-API-Key: {api_key}"';
            return false;
        }

        // If X-API-Key is provided, use it directly
        if ($api_key_header) {
            $this->api_key = trim($api_key_header);
        } elseif (!preg_match('/^Bearer\s+(.+)$/i', $auth_header, $matches)) {
            $this->error = 'Invalid Authorization header format. Expected: Bearer {api_key}';
            return false;
        } else {
            $this->api_key = trim($matches[1]);
        }
        
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
        // Standard Apache/nginx
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            return $_SERVER['HTTP_AUTHORIZATION'];
        }

        // IIS with URL Rewrite
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        // IIS with FastCGI - getallheaders() often works
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if ($headers !== false) {
                foreach ($headers as $key => $value) {
                    if (strtolower($key) === 'authorization') {
                        return $value;
                    }
                }
            }
        }

        // Apache fallback
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $key => $value) {
                if (strtolower($key) === 'authorization') {
                    return $value;
                }
            }
        }

        // IIS - check all $_SERVER keys for any authorization variant
        foreach ($_SERVER as $key => $value) {
            if (stripos($key, 'AUTHORIZATION') !== false && !empty($value)) {
                return $value;
            }
        }

        return null;
    }
    
    private function lookupApiKey($api_key) {
        $sql = "SELECT id, api_key, tier, owner_name, owner_email, source_id, can_write,
                       allowed_sources, ip_whitelist, expires_at, created_at, last_used_at, is_active
                FROM dbo.swim_api_keys WHERE api_key = ? AND is_active = 1";
        
        $stmt = sqlsrv_query($this->conn_swim, $sql, [$api_key]);
        
        if ($stmt === false) {
            // Log the database error but don't expose details
            error_log('SWIM Auth: Database query failed - ' . print_r(sqlsrv_errors(), true));
            return null;
        }
        
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        
        // No fallback - key must exist in database
        return $row ?: null;
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
        if (!$this->key_info || !$this->conn_swim) return;
        @sqlsrv_query($this->conn_swim, 
            "UPDATE dbo.swim_api_keys SET last_used_at = GETUTCDATE() WHERE id = ?",
            [$this->key_info['id']]);
        @sqlsrv_query($this->conn_swim,
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

    /** @var string|null Current tier for cache TTL calculation */
    private static $currentTier = 'public';

    /**
     * Set the current API tier (called after auth)
     */
    public static function setTier($tier) {
        self::$currentTier = $tier ?: 'public';
    }

    /**
     * Get the current API tier
     */
    public static function getTier() {
        return self::$currentTier;
    }

    public static function json($data, $status = 200) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-SWIM-Version: ' . SWIM_API_VERSION);
        self::setCorsHeaders();

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // CDN-friendly caching + ETag support
        if ($status === 200) {
            // Default 15s cache for SWIM responses (matches data refresh rate)
            $cache_ttl = swim_get_cache_ttl('flights_list', self::$currentTier);
            header("Cache-Control: public, max-age={$cache_ttl}, s-maxage={$cache_ttl}");
            header("CDN-Cache-Control: public, max-age={$cache_ttl}");
            header("Vary: Accept-Encoding, Authorization, X-API-Key");

            // ETag support - check if client has current version
            if (defined('SWIM_ENABLE_ETAG') && SWIM_ENABLE_ETAG) {
                $etag = '"' . md5($json) . '"';
                header("ETag: $etag");

                $clientEtag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
                if ($clientEtag === $etag) {
                    http_response_code(304);
                    exit;
                }
            }
        } else {
            // Don't cache errors
            header("Cache-Control: no-cache, no-store, must-revalidate");
        }

        // Gzip compression for responses > threshold
        if (defined('SWIM_ENABLE_GZIP') && SWIM_ENABLE_GZIP) {
            $minSize = defined('SWIM_GZIP_MIN_SIZE') ? SWIM_GZIP_MIN_SIZE : 1024;
            $acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';

            if (strlen($json) > $minSize && strpos($acceptEncoding, 'gzip') !== false) {
                $compressed = gzencode($json, 6);
                if ($compressed !== false) {
                    header('Content-Encoding: gzip');
                    header('Content-Length: ' . strlen($compressed));
                    header('Vary: Accept-Encoding');
                    echo $compressed;
                    exit;
                }
            }
        }

        echo $json;
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

    /**
     * Send cached response if available, otherwise return false
     *
     * @param string $endpoint Endpoint key for TTL lookup
     * @param array $params Request parameters for cache key
     * @return bool True if cache hit (response sent), false if cache miss
     */
    public static function tryCached($endpoint, $params = []) {
        $cache_key = swim_cache_key($endpoint, $params);
        $cached = swim_cache_get($cache_key);

        if ($cached !== null) {
            header('X-SWIM-Cache: HIT');
            self::json($cached, 200);
            return true;
        }

        header('X-SWIM-Cache: MISS');
        return false;
    }

    /**
     * Send success response and cache it
     *
     * @param mixed $data Response data
     * @param string $endpoint Endpoint key for TTL lookup
     * @param array $params Request parameters for cache key
     * @param array $meta Optional metadata
     */
    public static function successCached($data, $endpoint, $params = [], $meta = []) {
        $response = ['success' => true, 'data' => $data, 'timestamp' => gmdate('c')];
        if (!empty($meta)) $response['meta'] = $meta;

        // Cache the response
        $cache_key = swim_cache_key($endpoint, $params);
        $ttl = swim_get_cache_ttl($endpoint, self::$currentTier);
        swim_cache_set($cache_key, $response, $ttl);

        self::json($response, 200);
    }

    /**
     * Send paginated response and cache it
     *
     * @param array $data Response data array
     * @param int $total Total count
     * @param int $page Current page
     * @param int $per_page Items per page
     * @param string $endpoint Endpoint key for TTL lookup
     * @param array $params Request parameters for cache key
     */
    public static function paginatedCached($data, $total, $page, $per_page, $endpoint, $params = []) {
        $total_pages = ceil($total / $per_page);
        $response = [
            'success' => true,
            'data' => $data,
            'pagination' => [
                'total' => $total, 'page' => $page, 'per_page' => $per_page,
                'total_pages' => $total_pages, 'has_more' => $page < $total_pages
            ],
            'timestamp' => gmdate('c')
        ];

        // Cache the response
        $cache_key = swim_cache_key($endpoint, $params);
        $ttl = swim_get_cache_ttl($endpoint, self::$currentTier);
        swim_cache_set($cache_key, $response, $ttl);

        self::json($response, 200);
    }

    /**
     * Send response in specified format with caching
     *
     * @param array $data Response data
     * @param string $format Output format (json, xml, geojson, csv, kml, ndjson)
     * @param string $endpoint Endpoint key for TTL lookup
     * @param array $params Request parameters for cache key
     * @param array $options Format-specific options
     */
    public static function formatted($data, $format, $endpoint, $params = [], $options = []) {
        $format = strtolower($format);

        // For JSON/FIXM, use standard JSON response
        if ($format === 'json' || $format === 'fixm') {
            $response = ['success' => true, 'data' => $data, 'timestamp' => gmdate('c')];

            // Cache the response
            $cache_key = swim_cache_key($endpoint, $params);
            $ttl = swim_get_cache_ttl($endpoint, self::$currentTier);
            swim_cache_set($cache_key, $response, $ttl);

            self::json($response, 200);
            return;
        }

        // For other formats, use SwimFormat
        $response = ['success' => true, 'data' => $data, 'timestamp' => gmdate('c')];

        // Cache the JSON representation (for consistency)
        $cache_key = swim_cache_key($endpoint, $params);
        $ttl = swim_get_cache_ttl($endpoint, self::$currentTier);
        swim_cache_set($cache_key, $response, $ttl);

        // Send in requested format
        SwimFormat::send($response, $format, 200, $options);
    }

    /**
     * Send paginated response in specified format with caching
     *
     * @param array $data Response data array
     * @param int $total Total count
     * @param int $page Current page
     * @param int $per_page Items per page
     * @param string $format Output format
     * @param string $endpoint Endpoint key for TTL lookup
     * @param array $params Request parameters for cache key
     * @param array $options Format-specific options
     */
    public static function paginatedFormatted($data, $total, $page, $per_page, $format, $endpoint, $params = [], $options = []) {
        $format = strtolower($format);
        $total_pages = ceil($total / $per_page);

        $response = [
            'success' => true,
            'data' => $data,
            'pagination' => [
                'total' => $total, 'page' => $page, 'per_page' => $per_page,
                'total_pages' => $total_pages, 'has_more' => $page < $total_pages
            ],
            'timestamp' => gmdate('c')
        ];

        // Cache the response
        $cache_key = swim_cache_key($endpoint, $params);
        $ttl = swim_get_cache_ttl($endpoint, self::$currentTier);
        swim_cache_set($cache_key, $response, $ttl);

        // For JSON/FIXM, use standard JSON response
        if ($format === 'json' || $format === 'fixm') {
            self::json($response, 200);
            return;
        }

        // For other formats, use SwimFormat
        SwimFormat::send($response, $format, 200, $options);
    }

    /**
     * Try to return cached response in specified format
     *
     * @param string $endpoint Endpoint key
     * @param array $params Request parameters for cache key
     * @param string $format Output format
     * @param array $options Format-specific options
     * @return bool True if cache hit (response sent), false if cache miss
     */
    public static function tryCachedFormatted($endpoint, $params = [], $format = 'json', $options = []) {
        $cache_key = swim_cache_key($endpoint, $params);
        $cached = swim_cache_get($cache_key);

        if ($cached !== null) {
            header('X-SWIM-Cache: HIT');

            $format = strtolower($format);
            if ($format === 'json' || $format === 'fixm') {
                self::json($cached, 200);
            } else {
                SwimFormat::send($cached, $format, 200, $options);
            }
            return true;
        }

        header('X-SWIM-Cache: MISS');
        return false;
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
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-SWIM-Source, X-API-Key');
        header('Access-Control-Max-Age: 86400');
    }
    
    public static function handlePreflight() {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            self::setCorsHeaders();
            http_response_code(204);
            exit;
        }
    }
}

function swim_init_auth($require_auth = true, $require_write = false) {
    global $conn_swim, $conn_adl;
    SwimResponse::handlePreflight();
    if (!$require_auth) return null;

    // Use SWIM_API database if available, fall back to VATSIM_ADL during migration
    $conn = $conn_swim ?: $conn_adl;

    if (!$conn) {
        SwimResponse::error('Database connection not available', 503, 'SERVICE_UNAVAILABLE');
    }

    $auth = new SwimAuth($conn);
    if (!$auth->authenticate()) {
        SwimResponse::error($auth->getError(), 401, 'UNAUTHORIZED');
    }
    if ($require_write && !$auth->canWrite()) {
        SwimResponse::error('Write access not permitted for this API key', 403, 'FORBIDDEN');
    }

    // Set tier for cache TTL calculation
    $key_info = $auth->getKeyInfo();
    SwimResponse::setTier($key_info['tier'] ?? 'public');

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

/**
 * SWIM Format Handler
 *
 * Converts response data to various output formats.
 * Supported: json, fixm, xml, geojson, csv, kml, ndjson
 */
class SwimFormat {

    /** @var array Format to Content-Type mapping */
    private static $contentTypes = [
        'json'    => 'application/json; charset=utf-8',
        'fixm'    => 'application/json; charset=utf-8',
        'xml'     => 'application/xml; charset=utf-8',
        'geojson' => 'application/geo+json; charset=utf-8',
        'csv'     => 'text/csv; charset=utf-8',
        'kml'     => 'application/vnd.google-earth.kml+xml; charset=utf-8',
        'ndjson'  => 'application/x-ndjson; charset=utf-8'
    ];

    /** @var array Format to file extension mapping */
    private static $extensions = [
        'json'    => 'json',
        'fixm'    => 'json',
        'xml'     => 'xml',
        'geojson' => 'geojson',
        'csv'     => 'csv',
        'kml'     => 'kml',
        'ndjson'  => 'ndjson'
    ];

    /**
     * Get supported formats for an endpoint type
     *
     * @param string $endpoint_type 'flights', 'metering', 'positions'
     * @return array List of supported format codes
     */
    public static function getSupportedFormats($endpoint_type = 'flights') {
        $base = ['json', 'fixm', 'xml', 'ndjson'];

        switch ($endpoint_type) {
            case 'flights':
            case 'positions':
                return array_merge($base, ['geojson', 'csv', 'kml']);
            case 'metering':
                return array_merge($base, ['csv']);
            default:
                return $base;
        }
    }

    /**
     * Validate format is supported for endpoint
     *
     * @param string $format Requested format
     * @param string $endpoint_type Endpoint type
     * @return bool
     */
    public static function isSupported($format, $endpoint_type = 'flights') {
        return in_array(strtolower($format), self::getSupportedFormats($endpoint_type));
    }

    /**
     * Get Content-Type header for format
     *
     * @param string $format
     * @return string
     */
    public static function getContentType($format) {
        return self::$contentTypes[strtolower($format)] ?? self::$contentTypes['json'];
    }

    /**
     * Get file extension for format
     *
     * @param string $format
     * @return string
     */
    public static function getExtension($format) {
        return self::$extensions[strtolower($format)] ?? 'json';
    }

    /**
     * Convert data array to specified format
     *
     * @param array $data Data to convert (flights array or full response)
     * @param string $format Target format
     * @param array $options Format-specific options
     * @return string Formatted output
     */
    public static function convert($data, $format, $options = []) {
        $format = strtolower($format);

        switch ($format) {
            case 'xml':
                return self::toXml($data, $options);
            case 'geojson':
                return self::toGeoJson($data, $options);
            case 'csv':
                return self::toCsv($data, $options);
            case 'kml':
                return self::toKml($data, $options);
            case 'ndjson':
                return self::toNdjson($data, $options);
            case 'json':
            case 'fixm':
            default:
                return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    /**
     * Convert to XML format
     */
    public static function toXml($data, $options = []) {
        $rootName = $options['root'] ?? 'swim_response';
        $itemName = $options['item'] ?? 'item';

        $xml = new SimpleXMLElement("<?xml version=\"1.0\" encoding=\"UTF-8\"?><{$rootName}/>");
        self::arrayToXml($data, $xml, $itemName);

        // Pretty print
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml->asXML());

        return $dom->saveXML();
    }

    /**
     * Recursively convert array to XML
     */
    private static function arrayToXml($data, &$xml, $itemName = 'item') {
        foreach ($data as $key => $value) {
            // Handle numeric keys (arrays)
            if (is_numeric($key)) {
                $key = $itemName;
            }

            // Clean key name for XML (no spaces, starts with letter)
            $key = preg_replace('/[^a-zA-Z0-9_]/', '_', $key);
            if (is_numeric($key[0] ?? '')) {
                $key = '_' . $key;
            }

            if (is_array($value)) {
                $child = $xml->addChild($key);
                self::arrayToXml($value, $child, $itemName);
            } elseif ($value === null) {
                $xml->addChild($key);
            } elseif (is_bool($value)) {
                $xml->addChild($key, $value ? 'true' : 'false');
            } else {
                $xml->addChild($key, htmlspecialchars((string)$value, ENT_XML1, 'UTF-8'));
            }
        }
    }

    /**
     * Convert to GeoJSON format (for flights with positions)
     */
    public static function toGeoJson($data, $options = []) {
        $features = [];
        $items = $data['data'] ?? $data;

        // Handle nested structure
        if (isset($items['flights'])) {
            $items = $items['flights'];
        }

        if (!is_array($items)) {
            $items = [];
        }

        $precision = defined('SWIM_GEOJSON_PRECISION') ? SWIM_GEOJSON_PRECISION : 5;

        foreach ($items as $item) {
            $lon = $item['longitude'] ?? $item['lon'] ?? $item['position']['longitude'] ?? null;
            $lat = $item['latitude'] ?? $item['lat'] ?? $item['position']['latitude'] ?? null;

            if ($lon === null || $lat === null) {
                continue; // Skip items without coordinates
            }

            // Round coordinates to precision
            $lon = round((float)$lon, $precision);
            $lat = round((float)$lat, $precision);

            // Build properties (exclude coordinate fields)
            $properties = [];
            foreach ($item as $key => $value) {
                if (!in_array($key, ['lat', 'lon', 'latitude', 'longitude', 'position'])) {
                    $properties[$key] = $value;
                }
            }

            $features[] = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [$lon, $lat]
                ],
                'properties' => $properties
            ];
        }

        $geojson = [
            'type' => 'FeatureCollection',
            'features' => $features,
            'metadata' => [
                'generated' => gmdate('c'),
                'count' => count($features),
                'source' => 'VATSWIM API'
            ]
        ];

        // Include pagination if present
        if (isset($data['pagination'])) {
            $geojson['metadata']['pagination'] = $data['pagination'];
        }

        return json_encode($geojson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Convert to CSV format
     */
    public static function toCsv($data, $options = []) {
        $items = $data['data'] ?? $data;

        // Handle nested structure
        if (isset($items['flights'])) {
            $items = $items['flights'];
        }

        if (!is_array($items) || empty($items)) {
            return '';
        }

        $output = fopen('php://temp', 'r+');

        // Get all unique keys from all items (in case of inconsistent fields)
        $headers = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                foreach (array_keys($item) as $key) {
                    if (!in_array($key, $headers)) {
                        $headers[] = $key;
                    }
                }
            }
        }

        // Flatten nested arrays in headers
        $flatHeaders = [];
        foreach ($headers as $header) {
            // Skip complex nested objects for CSV
            if (!is_array($items[0][$header] ?? null)) {
                $flatHeaders[] = $header;
            }
        }

        // Write header row
        fputcsv($output, $flatHeaders);

        // Write data rows
        foreach ($items as $item) {
            $row = [];
            foreach ($flatHeaders as $header) {
                $value = $item[$header] ?? '';
                // Convert arrays/objects to JSON string
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value);
                } elseif (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                }
                $row[] = $value;
            }
            fputcsv($output, $row);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Convert to KML format (Google Earth)
     */
    public static function toKml($data, $options = []) {
        $docName = $options['name'] ?? 'VATSWIM Flights';
        $items = $data['data'] ?? $data;

        // Handle nested structure
        if (isset($items['flights'])) {
            $items = $items['flights'];
        }

        if (!is_array($items)) {
            $items = [];
        }

        $kml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $kml .= '<kml xmlns="http://www.opengis.net/kml/2.2">' . "\n";
        $kml .= '<Document>' . "\n";
        $kml .= '  <name>' . htmlspecialchars($docName) . '</name>' . "\n";
        $kml .= '  <description>Generated by VATSWIM API at ' . gmdate('c') . '</description>' . "\n";

        // Define styles for different flight phases
        $kml .= '  <Style id="flight-enroute"><IconStyle><color>ff00ff00</color><scale>1.0</scale><Icon><href>http://maps.google.com/mapfiles/kml/shapes/airports.png</href></Icon></IconStyle></Style>' . "\n";
        $kml .= '  <Style id="flight-departure"><IconStyle><color>ff0000ff</color><scale>0.8</scale><Icon><href>http://maps.google.com/mapfiles/kml/shapes/airports.png</href></Icon></IconStyle></Style>' . "\n";
        $kml .= '  <Style id="flight-arrival"><IconStyle><color>ffff0000</color><scale>0.8</scale><Icon><href>http://maps.google.com/mapfiles/kml/shapes/airports.png</href></Icon></IconStyle></Style>' . "\n";
        $kml .= '  <Style id="flight-ground"><IconStyle><color>ff888888</color><scale>0.6</scale><Icon><href>http://maps.google.com/mapfiles/kml/shapes/airports.png</href></Icon></IconStyle></Style>' . "\n";

        foreach ($items as $item) {
            $lon = $item['longitude'] ?? $item['lon'] ?? $item['position']['longitude'] ?? null;
            $lat = $item['latitude'] ?? $item['lat'] ?? $item['position']['latitude'] ?? null;
            $alt = $item['altitude'] ?? $item['altitude_ft'] ?? $item['position']['altitude'] ?? 0;

            if ($lon === null || $lat === null) {
                continue;
            }

            $callsign = htmlspecialchars($item['callsign'] ?? $item['aircraftIdentification'] ?? 'Unknown');
            $phase = strtolower($item['phase'] ?? 'enroute');
            $styleId = 'flight-' . (in_array($phase, ['departure', 'arrival', 'ground', 'enroute']) ? $phase : 'enroute');

            // Build description
            $desc = [];
            if (isset($item['aircraft_type']) || isset($item['aircraftType'])) {
                $desc[] = 'Aircraft: ' . ($item['aircraft_type'] ?? $item['aircraftType']);
            }
            if (isset($item['fp_dept_icao']) || isset($item['departureAerodrome'])) {
                $desc[] = 'From: ' . ($item['fp_dept_icao'] ?? $item['departureAerodrome']);
            }
            if (isset($item['fp_dest_icao']) || isset($item['arrivalAerodrome'])) {
                $desc[] = 'To: ' . ($item['fp_dest_icao'] ?? $item['arrivalAerodrome']);
            }
            if (isset($item['groundspeed_kts']) || isset($item['groundSpeed'])) {
                $desc[] = 'Speed: ' . ($item['groundspeed_kts'] ?? $item['groundSpeed']) . ' kts';
            }
            if ($alt > 0) {
                $desc[] = 'Altitude: ' . number_format($alt) . ' ft';
            }

            $kml .= '  <Placemark>' . "\n";
            $kml .= '    <name>' . $callsign . '</name>' . "\n";
            $kml .= '    <description><![CDATA[' . implode('<br/>', $desc) . ']]></description>' . "\n";
            $kml .= '    <styleUrl>#' . $styleId . '</styleUrl>' . "\n";
            $kml .= '    <Point>' . "\n";
            $kml .= '      <altitudeMode>absolute</altitudeMode>' . "\n";
            $kml .= '      <coordinates>' . $lon . ',' . $lat . ',' . round($alt * 0.3048) . '</coordinates>' . "\n"; // Convert ft to meters
            $kml .= '    </Point>' . "\n";
            $kml .= '  </Placemark>' . "\n";
        }

        $kml .= '</Document>' . "\n";
        $kml .= '</kml>';

        return $kml;
    }

    /**
     * Convert to NDJSON (Newline Delimited JSON) format
     */
    public static function toNdjson($data, $options = []) {
        $items = $data['data'] ?? $data;

        // Handle nested structure
        if (isset($items['flights'])) {
            $items = $items['flights'];
        }

        if (!is_array($items)) {
            return '';
        }

        $lines = [];
        foreach ($items as $item) {
            $lines[] = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Send formatted response with appropriate headers
     *
     * @param mixed $data Data to send
     * @param string $format Output format
     * @param int $status HTTP status code
     * @param array $options Format options
     */
    public static function send($data, $format, $status = 200, $options = []) {
        http_response_code($status);
        header('Content-Type: ' . self::getContentType($format));
        header('X-SWIM-Version: ' . SWIM_API_VERSION);
        header('X-SWIM-Format: ' . $format);

        // Set CORS headers
        SwimResponse::handlePreflight();
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        global $SWIM_CORS_ORIGINS;
        if (isset($SWIM_CORS_ORIGINS) && in_array($origin, $SWIM_CORS_ORIGINS)) {
            header("Access-Control-Allow-Origin: $origin");
        } else {
            header("Access-Control-Allow-Origin: *");
        }

        // Content-Disposition for downloadable formats
        if (in_array($format, ['csv', 'kml'])) {
            $filename = ($options['filename'] ?? 'swim_export') . '.' . self::getExtension($format);
            header("Content-Disposition: attachment; filename=\"{$filename}\"");
        }

        $output = self::convert($data, $format, $options);

        // ETag support for all formats
        if (defined('SWIM_ENABLE_ETAG') && SWIM_ENABLE_ETAG && $status === 200) {
            $etag = '"' . md5($output) . '"';
            header("ETag: $etag");
            header('Cache-Control: private, must-revalidate');

            $clientEtag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
            if ($clientEtag === $etag) {
                http_response_code(304);
                exit;
            }
        }

        // Gzip compression
        if (defined('SWIM_ENABLE_GZIP') && SWIM_ENABLE_GZIP) {
            $minSize = defined('SWIM_GZIP_MIN_SIZE') ? SWIM_GZIP_MIN_SIZE : 1024;
            $acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';

            if (strlen($output) > $minSize && strpos($acceptEncoding, 'gzip') !== false) {
                $compressed = gzencode($output, 6);
                if ($compressed !== false) {
                    header('Content-Encoding: gzip');
                    header('Content-Length: ' . strlen($compressed));
                    header('Vary: Accept-Encoding');
                    echo $compressed;
                    exit;
                }
            }
        }

        echo $output;
        exit;
    }
}

/**
 * Validate and normalize format parameter
 *
 * @param string $format Requested format
 * @param string $endpoint_type Endpoint type for validation
 * @return string Validated format (defaults to 'json' if invalid)
 */
function swim_validate_format($format, $endpoint_type = 'flights') {
    $format = strtolower(trim($format ?? 'json'));

    if (!SwimFormat::isSupported($format, $endpoint_type)) {
        return 'json'; // Default fallback
    }

    return $format;
}
