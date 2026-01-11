<?php
/**
 * JATOC Server-Side Authentication & Authorization
 *
 * Checks VATSIM session and verifies user roles from database.
 * Provides permission-based access control for API endpoints.
 */

require_once __DIR__ . '/config.php';

class JatocAuth {
    private static $userRoles = null;
    private static $conn = null;

    /**
     * Set database connection for role lookups
     *
     * @param resource $conn SQL Server connection
     */
    public static function setConnection($conn) {
        self::$conn = $conn;
    }

    /**
     * Check if user is authenticated (has VATSIM session)
     *
     * @return bool True if user has valid VATSIM session
     */
    public static function isAuthenticated() {
        return isset($_SESSION['VATSIM_CID']) && !empty($_SESSION['VATSIM_CID']);
    }

    /**
     * Get current user's VATSIM CID
     *
     * @return string|null CID or null if not authenticated
     */
    public static function getCid() {
        return $_SESSION['VATSIM_CID'] ?? null;
    }

    /**
     * Get current user's name
     *
     * @return string User's full name or 'Unknown'
     */
    public static function getUserName() {
        $first = $_SESSION['VATSIM_FIRST_NAME'] ?? '';
        $last = $_SESSION['VATSIM_LAST_NAME'] ?? '';
        $name = trim("$first $last");
        return $name ?: 'Unknown';
    }

    /**
     * Get user roles from database or session cache
     *
     * @return array Array of role codes (e.g., ['DCC', 'FACILITY'])
     */
    public static function getUserRoles() {
        if (self::$userRoles !== null) {
            return self::$userRoles;
        }

        $cid = self::getCid();
        if (!$cid) {
            self::$userRoles = [];
            return self::$userRoles;
        }

        // Check if roles are cached in session (30 second cache for development)
        if (isset($_SESSION['JATOC_ROLES']) && isset($_SESSION['JATOC_ROLES_CACHED_AT'])) {
            if (time() - $_SESSION['JATOC_ROLES_CACHED_AT'] < 30) {
                self::$userRoles = $_SESSION['JATOC_ROLES'];
                return self::$userRoles;
            }
        }

        // Fetch from database
        self::$userRoles = self::fetchRolesFromDatabase($cid);

        // Cache in session
        $_SESSION['JATOC_ROLES'] = self::$userRoles;
        $_SESSION['JATOC_ROLES_CACHED_AT'] = time();

        return self::$userRoles;
    }

    /**
     * Fetch user roles from database
     *
     * @param string $cid VATSIM CID
     * @return array Array of role codes
     */
    private static function fetchRolesFromDatabase($cid) {
        if (!self::$conn) {
            return self::inferRolesFromSession();
        }

        // Check if user roles table exists
        $checkSql = "SELECT OBJECT_ID('dbo.jatoc_user_roles', 'U') as table_id";
        $checkStmt = @sqlsrv_query(self::$conn, $checkSql);
        if ($checkStmt) {
            $row = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($checkStmt);

            if (empty($row['table_id'])) {
                // Table doesn't exist - use fallback
                return self::inferRolesFromSession();
            }
        } else {
            return self::inferRolesFromSession();
        }

        // Query user roles
        $sql = "SELECT role_code FROM dbo.jatoc_user_roles
                WHERE cid = ? AND active = 1
                AND (expires_at IS NULL OR expires_at > SYSUTCDATETIME())";
        $stmt = sqlsrv_query(self::$conn, $sql, [$cid]);

        if ($stmt === false) {
            return self::inferRolesFromSession();
        }

        $roles = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $roles[] = $row['role_code'];
        }
        sqlsrv_free_stmt($stmt);

        // If no roles found, give basic access
        if (empty($roles)) {
            return self::inferRolesFromSession();
        }

        return $roles;
    }

    /**
     * Infer roles from session (fallback when roles table doesn't exist or empty)
     *
     * For backward compatibility and development, authenticated users get DCC role
     * which allows full permissions. In production, populate jatoc_user_roles table.
     *
     * @return array Array of role codes
     */
    private static function inferRolesFromSession() {
        if (!self::isAuthenticated()) {
            return ['READONLY'];
        }

        // TODO: In production, remove DCC fallback and require explicit role assignment
        // For now, give authenticated users DCC role for full access during development
        return ['DCC'];
    }

    /**
     * Check if user has a specific permission
     *
     * @param string $permission Permission to check (e.g., 'create', 'update')
     * @return bool True if user has permission
     */
    public static function hasPermission($permission) {
        $roles = self::getUserRoles();

        foreach ($roles as $role) {
            if (isset(JATOC_ROLES[$role])) {
                if (in_array($permission, JATOC_ROLES[$role])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if user has DCC role
     *
     * @return bool True if user is DCC
     */
    public static function isDCC() {
        return in_array('DCC', self::getUserRoles());
    }

    /**
     * Require authentication - exits with 401 if not authenticated
     */
    public static function requireAuth() {
        if (!self::isAuthenticated()) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'error' => 'Authentication required'
            ]);
            exit;
        }
    }

    /**
     * Require specific permission - exits with 403 if not authorized
     *
     * @param string $permission Required permission
     */
    public static function requirePermission($permission) {
        self::requireAuth();

        if (!self::hasPermission($permission)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'Insufficient permissions',
                'required_permission' => $permission
            ]);
            exit;
        }
    }

    /**
     * Require DCC role - exits with 403 if not DCC
     */
    public static function requireDCC() {
        self::requireAuth();

        if (!self::isDCC()) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'DCC role required for this action'
            ]);
            exit;
        }
    }

    /**
     * Get user identifier for logging (CID or 'anonymous')
     *
     * @return string User identifier
     */
    public static function getLogIdentifier() {
        $cid = self::getCid();
        return $cid ?: 'anonymous';
    }
}
