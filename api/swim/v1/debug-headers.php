<?php
/**
 * SWIM API Debug - Headers inspection
 * Temporary file to diagnose auth header issues
 * DELETE THIS FILE after debugging
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-SWIM-Source, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Collect all possible auth headers
$auth_info = [
    'timestamp' => gmdate('c'),
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    'found_auth_headers' => [],
    'all_server_vars_with_auth' => [],
    'getallheaders_result' => null,
    'recommendation' => null
];

// Check standard locations
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $auth_info['found_auth_headers']['HTTP_AUTHORIZATION'] = $_SERVER['HTTP_AUTHORIZATION'];
}
if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $auth_info['found_auth_headers']['REDIRECT_HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
}
if (isset($_SERVER['HTTP_X_API_KEY'])) {
    $auth_info['found_auth_headers']['HTTP_X_API_KEY'] = $_SERVER['HTTP_X_API_KEY'];
}

// Check getallheaders
if (function_exists('getallheaders')) {
    $headers = getallheaders();
    if ($headers !== false) {
        $auth_info['getallheaders_result'] = [];
        foreach ($headers as $key => $value) {
            if (stripos($key, 'auth') !== false || stripos($key, 'api') !== false || stripos($key, 'key') !== false) {
                $auth_info['getallheaders_result'][$key] = $value;
            }
        }
        if (empty($auth_info['getallheaders_result'])) {
            $auth_info['getallheaders_result'] = 'No auth-related headers found in getallheaders()';
        }
    } else {
        $auth_info['getallheaders_result'] = 'getallheaders() returned false';
    }
} else {
    $auth_info['getallheaders_result'] = 'getallheaders() not available';
}

// Scan all $_SERVER for anything auth-related
foreach ($_SERVER as $key => $value) {
    if (stripos($key, 'AUTH') !== false || stripos($key, 'API') !== false) {
        $auth_info['all_server_vars_with_auth'][$key] = $value;
    }
}

// Provide recommendation
if (empty($auth_info['found_auth_headers'])) {
    $auth_info['recommendation'] = 'No auth headers received. In Swagger UI: 1) Click Authorize button, 2) Enter your API key (e.g., swim_sys_vatcscc_internal_001), 3) Click Authorize, 4) Then try the endpoint again.';
    $auth_info['status'] = 'NO_AUTH_HEADERS';
} else {
    $auth_info['recommendation'] = 'Auth headers found! Authentication should work.';
    $auth_info['status'] = 'AUTH_HEADERS_PRESENT';
}

echo json_encode($auth_info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
