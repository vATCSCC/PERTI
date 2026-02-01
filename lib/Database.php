<?php
/**
 * PERTI Database Utilities
 *
 * Centralized database operations with enforced prepared statements.
 * Prevents SQL injection by design - no raw query methods exposed.
 *
 * @package PERTI\Lib
 * @version 1.0.0
 */

namespace PERTI\Lib;

class Database {

    /**
     * Execute a parameterized SELECT query (MySQLi)
     *
     * @param \mysqli $conn Database connection
     * @param string $sql SQL with ? placeholders
     * @param array $params Parameters to bind
     * @param string $types Type string (i=int, s=string, d=double, b=blob)
     * @return array|false Array of results or false on error
     *
     * @example
     * $users = Database::select($conn,
     *     "SELECT * FROM users WHERE cid = ? AND active = ?",
     *     [$cid, 1],
     *     "si"
     * );
     */
    public static function select(\mysqli $conn, string $sql, array $params = [], string $types = ''): array|false {
        if (empty($types) && !empty($params)) {
            $types = self::inferTypes($params);
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("[PERTI DB] Prepare failed: " . $conn->error . " | SQL: " . substr($sql, 0, 200));
            return false;
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            error_log("[PERTI DB] Execute failed: " . $stmt->error);
            $stmt->close();
            return false;
        }

        $result = $stmt->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    /**
     * Execute a parameterized SELECT query returning single row
     *
     * @param \mysqli $conn Database connection
     * @param string $sql SQL with ? placeholders
     * @param array $params Parameters to bind
     * @param string $types Type string
     * @return array|null Single row as assoc array, or null if not found/error
     */
    public static function selectOne(\mysqli $conn, string $sql, array $params = [], string $types = ''): array|null {
        $rows = self::select($conn, $sql, $params, $types);
        if ($rows === false || empty($rows)) {
            return null;
        }
        return $rows[0];
    }

    /**
     * Execute a parameterized INSERT/UPDATE/DELETE query (MySQLi)
     *
     * @param \mysqli $conn Database connection
     * @param string $sql SQL with ? placeholders
     * @param array $params Parameters to bind
     * @param string $types Type string
     * @return int|false Affected rows or false on error
     *
     * @example
     * $affected = Database::execute($conn,
     *     "UPDATE p_terminal_staffing SET status = ?, comments = ? WHERE id = ?",
     *     [$status, $comments, $id],
     *     "ssi"
     * );
     */
    public static function execute(\mysqli $conn, string $sql, array $params = [], string $types = ''): int|false {
        if (empty($types) && !empty($params)) {
            $types = self::inferTypes($params);
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("[PERTI DB] Prepare failed: " . $conn->error . " | SQL: " . substr($sql, 0, 200));
            return false;
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            error_log("[PERTI DB] Execute failed: " . $stmt->error);
            $stmt->close();
            return false;
        }

        $affected = $stmt->affected_rows;
        $stmt->close();

        return $affected;
    }

    /**
     * Execute INSERT and return last insert ID
     */
    public static function insert(\mysqli $conn, string $sql, array $params = [], string $types = ''): int|false {
        if (empty($types) && !empty($params)) {
            $types = self::inferTypes($params);
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("[PERTI DB] Prepare failed: " . $conn->error);
            return false;
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            error_log("[PERTI DB] Execute failed: " . $stmt->error);
            $stmt->close();
            return false;
        }

        $id = $stmt->insert_id;
        $stmt->close();

        return $id;
    }

    /**
     * Execute a parameterized query on SQL Server (sqlsrv)
     *
     * @param resource $conn SQL Server connection
     * @param string $sql SQL with ? placeholders
     * @param array $params Parameters to bind
     * @return array|false Array of results or false on error
     */
    public static function selectSqlsrv($conn, string $sql, array $params = []): array|false {
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            error_log("[PERTI DB] SQLSRV query failed: " . json_encode($errors));
            return false;
        }

        $rows = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $rows[] = $row;
        }
        sqlsrv_free_stmt($stmt);

        return $rows;
    }

    /**
     * Execute a parameterized SELECT query returning single row (SQL Server)
     */
    public static function selectOneSqlsrv($conn, string $sql, array $params = []): array|null {
        $rows = self::selectSqlsrv($conn, $sql, $params);
        if ($rows === false || empty($rows)) {
            return null;
        }
        return $rows[0];
    }

    /**
     * Execute INSERT/UPDATE/DELETE on SQL Server
     */
    public static function executeSqlsrv($conn, string $sql, array $params = []): int|false {
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            error_log("[PERTI DB] SQLSRV execute failed: " . json_encode($errors));
            return false;
        }

        $affected = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        return $affected;
    }

    /**
     * Escape LIKE pattern wildcards
     * Use this BEFORE passing to prepared statement for LIKE clauses
     *
     * @example
     * $search = Database::escapeLike($userInput);
     * Database::select($conn, "SELECT * FROM t WHERE col LIKE ?", ["%{$search}%"]);
     */
    public static function escapeLike(string $value): string {
        return str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $value);
    }

    /**
     * Auto-infer parameter types for MySQLi binding
     */
    private static function inferTypes(array $params): string {
        $types = '';
        foreach ($params as $param) {
            if (is_int($param)) {
                $types .= 'i';
            } elseif (is_float($param)) {
                $types .= 'd';
            } elseif (is_null($param)) {
                $types .= 's'; // NULL handled as string
            } else {
                $types .= 's';
            }
        }
        return $types;
    }

    /**
     * Validate and cast ID parameter
     * Returns null if invalid (non-numeric or <= 0)
     */
    public static function validateId($value): ?int {
        if (!is_numeric($value)) {
            return null;
        }
        $id = (int)$value;
        return $id > 0 ? $id : null;
    }

    /**
     * Build a safe IN clause with placeholders
     *
     * @example
     * [$placeholders, $types] = Database::buildInClause([1, 2, 3], 'i');
     * // Returns: ["?,?,?", "iii"]
     */
    public static function buildInClause(array $values, string $type = 's'): array {
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $types = str_repeat($type, count($values));
        return [$placeholders, $types];
    }
}
