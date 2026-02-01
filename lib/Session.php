<?php
/**
 * PERTI Session Utilities
 *
 * Centralized session handling with consistent key names.
 * Prevents session key inconsistencies.
 *
 * @package PERTI\Lib
 * @version 1.0.0
 */

namespace PERTI\Lib;

class Session {

    // Canonical session key names - use these constants everywhere
    public const KEY_CID = 'VATSIM_CID';           // User's VATSIM CID
    public const KEY_NAME = 'VATSIM_NAME';         // User's name
    public const KEY_RATING = 'VATSIM_RATING';     // Controller rating
    public const KEY_FACILITY = 'VATSIM_FACILITY'; // Home facility
    public const KEY_PERMISSIONS = 'VATSIM_PERMISSIONS'; // Permission flags

    /**
     * Start session if not already started
     */
    public static function start(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Get the current user's CID
     *
     * @return string|null CID or null if not logged in
     */
    public static function getCid(): ?string {
        self::start();
        return $_SESSION[self::KEY_CID] ?? null;
    }

    /**
     * Get the current user's name
     */
    public static function getName(): ?string {
        self::start();
        return $_SESSION[self::KEY_NAME] ?? null;
    }

    /**
     * Get a session value with optional default
     *
     * @param string $key Session key (use Session::KEY_* constants)
     * @param mixed $default Default value if not set
     * @return mixed Session value or default
     */
    public static function get(string $key, $default = null) {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Set a session value
     */
    public static function set(string $key, $value): void {
        self::start();
        $_SESSION[$key] = $value;
    }

    /**
     * Check if user is authenticated
     */
    public static function isAuthenticated(): bool {
        return self::getCid() !== null;
    }

    /**
     * Require authentication or exit with 401
     */
    public static function requireAuth(): void {
        if (!self::isAuthenticated()) {
            Response::unauthorized('Session expired or not logged in');
        }
    }

    /**
     * Get user info array for API responses
     */
    public static function getUserInfo(): ?array {
        if (!self::isAuthenticated()) {
            return null;
        }

        return [
            'cid' => self::getCid(),
            'name' => self::getName(),
            'rating' => self::get(self::KEY_RATING),
            'facility' => self::get(self::KEY_FACILITY),
        ];
    }

    /**
     * Set all user session data on login
     */
    public static function login(
        string $cid,
        string $name,
        ?string $rating = null,
        ?string $facility = null,
        array $permissions = []
    ): void {
        self::start();
        $_SESSION[self::KEY_CID] = $cid;
        $_SESSION[self::KEY_NAME] = $name;
        $_SESSION[self::KEY_RATING] = $rating;
        $_SESSION[self::KEY_FACILITY] = $facility;
        $_SESSION[self::KEY_PERMISSIONS] = $permissions;

        // Regenerate session ID on login for security
        session_regenerate_id(true);
    }

    /**
     * Clear all session data on logout
     */
    public static function logout(): void {
        self::start();
        $_SESSION = [];

        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }

        session_destroy();
    }

    /**
     * Check if user has a specific permission
     */
    public static function hasPermission(string $permission): bool {
        $permissions = self::get(self::KEY_PERMISSIONS, []);
        return in_array($permission, $permissions, true);
    }

    /**
     * Require a specific permission or exit with 403
     */
    public static function requirePermission(string $permission): void {
        self::requireAuth();

        if (!self::hasPermission($permission)) {
            Response::forbidden("Permission required: {$permission}");
        }
    }

    /**
     * MIGRATION HELPER: Also check legacy 'cid' key
     * Remove after all code is migrated
     *
     * @deprecated Use getCid() after migration
     */
    public static function getCidLegacy(): ?string {
        self::start();
        // Check new key first, fall back to legacy
        return $_SESSION[self::KEY_CID]
            ?? $_SESSION['cid']
            ?? null;
    }
}
