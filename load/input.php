<?php
/**
 * Safe Input Handling Functions for PHP 8.2+
 *
 * These functions prevent "Undefined array key" warnings and TypeError exceptions
 * when accessing superglobal arrays ($_GET, $_POST, $_SESSION, $_COOKIE, $_SERVER).
 */

/**
 * Safely get and sanitize a value from $_GET
 * @param string $key The key to retrieve
 * @param mixed $default Default value if key doesn't exist
 * @return string|mixed Sanitized string or default value
 */
function get_input(string $key, $default = ''): mixed {
    return isset($_GET[$key]) ? strip_tags(trim($_GET[$key])) : $default;
}

/**
 * Safely get and sanitize a value from $_POST
 * @param string $key The key to retrieve
 * @param mixed $default Default value if key doesn't exist
 * @return string|mixed Sanitized string or default value
 */
function post_input(string $key, $default = ''): mixed {
    return isset($_POST[$key]) ? strip_tags(trim($_POST[$key])) : $default;
}

/**
 * Safely get a value from $_SESSION
 * @param string $key The key to retrieve
 * @param mixed $default Default value if key doesn't exist
 * @return mixed Session value or default
 */
function session_get(string $key, $default = null): mixed {
    return $_SESSION[$key] ?? $default;
}

/**
 * Safely get and sanitize a value from $_COOKIE
 * @param string $key The key to retrieve
 * @param mixed $default Default value if key doesn't exist
 * @return string|mixed Sanitized string or default value
 */
function cookie_get(string $key, $default = ''): mixed {
    return isset($_COOKIE[$key]) ? strip_tags($_COOKIE[$key]) : $default;
}

/**
 * Safely get a value from $_SERVER
 * @param string $key The key to retrieve
 * @param mixed $default Default value if key doesn't exist
 * @return string|mixed Server value or default
 */
function server_get(string $key, $default = ''): mixed {
    return isset($_SERVER[$key]) ? strip_tags($_SERVER[$key]) : $default;
}

/**
 * Safely get an integer from $_GET
 * @param string $key The key to retrieve
 * @param int $default Default value if key doesn't exist
 * @return int Integer value
 */
function get_int(string $key, int $default = 0): int {
    return isset($_GET[$key]) ? intval($_GET[$key]) : $default;
}

/**
 * Safely get an integer from $_POST
 * @param string $key The key to retrieve
 * @param int $default Default value if key doesn't exist
 * @return int Integer value
 */
function post_int(string $key, int $default = 0): int {
    return isset($_POST[$key]) ? intval($_POST[$key]) : $default;
}

/**
 * Safely get a float from $_GET
 * @param string $key The key to retrieve
 * @param float $default Default value if key doesn't exist
 * @return float Float value
 */
function get_float(string $key, float $default = 0.0): float {
    return isset($_GET[$key]) ? floatval($_GET[$key]) : $default;
}

/**
 * Safely get uppercase trimmed string from $_GET
 * @param string $key The key to retrieve
 * @param string $default Default value if key doesn't exist
 * @return string Uppercase trimmed string
 */
function get_upper(string $key, string $default = ''): string {
    return isset($_GET[$key]) ? strtoupper(trim($_GET[$key])) : $default;
}

/**
 * Safely get lowercase trimmed string from $_GET
 * @param string $key The key to retrieve
 * @param string $default Default value if key doesn't exist
 * @return string Lowercase trimmed string
 */
function get_lower(string $key, string $default = ''): string {
    return isset($_GET[$key]) ? strtolower(trim($_GET[$key])) : $default;
}

/**
 * Check if a $_GET key exists and is not empty
 * @param string $key The key to check
 * @return bool True if key exists and is not empty
 */
function has_get(string $key): bool {
    return isset($_GET[$key]) && $_GET[$key] !== '';
}

/**
 * Check if a $_POST key exists and is not empty
 * @param string $key The key to check
 * @return bool True if key exists and is not empty
 */
function has_post(string $key): bool {
    return isset($_POST[$key]) && $_POST[$key] !== '';
}

/**
 * Check if a $_SESSION key exists
 * @param string $key The key to check
 * @return bool True if key exists
 */
function has_session(string $key): bool {
    return isset($_SESSION[$key]);
}
