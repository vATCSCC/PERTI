<?php
/**
 * PERTI API Response Utilities
 *
 * Standardized API response format across all endpoints.
 * Enforces consistent structure, status codes, and CORS handling.
 *
 * @package PERTI\Lib
 * @version 1.0.0
 */

namespace PERTI\Lib;

class Response {

    /** @var array Allowed CORS origins */
    private static array $allowedOrigins = [
        'https://perti.vatcscc.org',
        'https://vatcscc.org',
        'https://vatcscc.azurewebsites.net',
        'https://swim.vatcscc.org',
        'http://localhost',
        'http://localhost:3000',
        'http://localhost:8080',
    ];

    /**
     * Send a success response
     *
     * @param mixed $data Response data
     * @param int $code HTTP status code (default 200)
     * @param array $meta Optional metadata
     */
    public static function success($data = null, int $code = 200, array $meta = []): never {
        self::setCors();
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');

        $response = [
            'success' => true,
            'data' => $data,
            'timestamp' => DateTime::formatIso(),
        ];

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Send an error response
     *
     * @param string $message Error message
     * @param int $code HTTP status code (default 400)
     * @param string|null $errorCode Machine-readable error code
     * @param array $details Additional error details
     */
    public static function error(
        string $message,
        int $code = 400,
        ?string $errorCode = null,
        array $details = []
    ): never {
        self::setCors();
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');

        $response = [
            'success' => false,
            'error' => true,
            'message' => $message,
            'status' => $code,
            'timestamp' => DateTime::formatIso(),
        ];

        if ($errorCode) {
            $response['code'] = $errorCode;
        }

        if (!empty($details)) {
            $response['details'] = $details;
        }

        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Send a validation error response (400)
     */
    public static function validationError(array $errors): never {
        self::error(
            'Validation failed',
            400,
            'VALIDATION_ERROR',
            ['errors' => $errors]
        );
    }

    /**
     * Send an unauthorized error (401)
     */
    public static function unauthorized(string $message = 'Authentication required'): never {
        self::error($message, 401, 'UNAUTHORIZED');
    }

    /**
     * Send a forbidden error (403)
     */
    public static function forbidden(string $message = 'Access denied'): never {
        self::error($message, 403, 'FORBIDDEN');
    }

    /**
     * Send a not found error (404)
     */
    public static function notFound(string $message = 'Resource not found'): never {
        self::error($message, 404, 'NOT_FOUND');
    }

    /**
     * Send a server error (500)
     */
    public static function serverError(string $message = 'Internal server error'): never {
        error_log("[PERTI Response] Server error: " . $message);
        self::error($message, 500, 'SERVER_ERROR');
    }

    /**
     * Handle OPTIONS preflight request
     */
    public static function handlePreflight(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            self::setCors();
            http_response_code(204);
            exit;
        }
    }

    /**
     * Set CORS headers based on origin whitelist
     * NO wildcard fallback - unknown origins are rejected
     */
    public static function setCors(): void {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        // Check exact match
        if (in_array($origin, self::$allowedOrigins, true)) {
            header("Access-Control-Allow-Origin: {$origin}");
        }
        // Check localhost variants
        elseif (preg_match('/^https?:\/\/localhost(:\d+)?$/', $origin)) {
            header("Access-Control-Allow-Origin: {$origin}");
        }
        // No wildcard fallback - request will fail CORS if not in whitelist

        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-SWIM-Source, X-API-Key');
        header('Access-Control-Max-Age: 86400');
        header('Vary: Origin');
    }

    /**
     * Add a custom allowed origin at runtime
     */
    public static function addAllowedOrigin(string $origin): void {
        if (!in_array($origin, self::$allowedOrigins, true)) {
            self::$allowedOrigins[] = $origin;
        }
    }

    /**
     * Send raw JSON (for GeoJSON, etc.)
     */
    public static function json($data, int $code = 200): never {
        self::setCors();
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Send GeoJSON FeatureCollection
     */
    public static function geoJson(array $features): never {
        self::json([
            'type' => 'FeatureCollection',
            'features' => $features,
        ]);
    }

    /**
     * Send cached response with ETag
     */
    public static function cached($data, string $etag, int $maxAge = 300): never {
        $clientEtag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';

        if ($clientEtag === $etag) {
            self::setCors();
            http_response_code(304);
            exit;
        }

        header("ETag: {$etag}");
        header("Cache-Control: public, max-age={$maxAge}");
        self::success($data);
    }
}
