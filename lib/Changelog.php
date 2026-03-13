<?php
/**
 * Changelog Helper
 *
 * Reusable changelog/audit trail helper for recording detailed changes
 * across the playbook (MySQL) and CTP (Azure SQL) systems.
 *
 * Automatically captures author CID, display name, IP, and timestamps.
 *
 * @version 1.0.0
 * @date 2026-03-12
 */

namespace PERTI\Lib;

class Changelog
{
    // ========================================================================
    // Playbook Changelog (MySQL)
    // ========================================================================

    /**
     * Log a playbook change with full detail.
     *
     * @param \mysqli  $conn    MySQL connection
     * @param int      $play_id Play ID
     * @param int|null $route_id Route ID (null for play-level changes)
     * @param string   $action  Action enum value
     * @param string|null $field Field name that changed
     * @param mixed    $old     Previous value
     * @param mixed    $new     New value
     * @param array    $context Additional context (ctp_session_id, tool, etc.)
     * @return bool Success
     */
    public static function logPlaybookChange($conn, $play_id, $route_id, $action, $field = null, $old = null, $new = null, $context = [])
    {
        $cid  = self::getSessionCid();
        $name = self::getSessionName();
        $ip   = self::getClientIp();

        $old_str = self::valueToString($old);
        $new_str = self::valueToString($new);
        $context_json = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : null;

        $stmt = $conn->prepare(
            "INSERT INTO playbook_changelog
                (play_id, route_id, action, field_name, old_value, new_value, changed_by, changed_by_name, ip_address, session_context)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            'iissssssss',
            $play_id, $route_id, $action, $field,
            $old_str, $new_str, $cid, $name, $ip, $context_json
        );
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    /**
     * Log multiple field changes for a single play/route update.
     *
     * Compares old and new associative arrays, logging each changed field.
     *
     * @param \mysqli  $conn    MySQL connection
     * @param int      $play_id Play ID
     * @param int|null $route_id Route ID
     * @param string   $action  Action enum value
     * @param array    $old     Old field values
     * @param array    $new     New field values
     * @param array    $fields  Fields to compare (if empty, compares all keys in $new)
     * @param array    $context Additional context
     * @return int Number of changes logged
     */
    public static function logPlaybookDiff($conn, $play_id, $route_id, $action, $old, $new, $fields = [], $context = [])
    {
        $count = 0;
        $check_fields = !empty($fields) ? $fields : array_keys($new);

        foreach ($check_fields as $field) {
            $old_val = $old[$field] ?? null;
            $new_val = $new[$field] ?? null;

            if (self::valuesChanged($old_val, $new_val)) {
                self::logPlaybookChange($conn, $play_id, $route_id, $action, $field, $old_val, $new_val, $context);
                $count++;
            }
        }
        return $count;
    }

    /**
     * Get playbook changelog for a play.
     *
     * @param \mysqli $conn MySQL connection
     * @param int $play_id Play ID
     * @param int $limit Max rows
     * @param int $offset Offset
     * @param string|null $since ISO datetime filter
     * @return array
     */
    public static function getPlaybookHistory($conn, $play_id, $limit = 50, $offset = 0, $since = null)
    {
        $sql = "SELECT changelog_id, play_id, route_id, action, field_name, old_value, new_value,
                       changed_by, changed_by_name, ip_address, session_context, changed_at
                FROM playbook_changelog
                WHERE play_id = ?";
        $params = [$play_id];
        $types  = 'i';

        if ($since !== null) {
            $sql .= " AND changed_at >= ?";
            $params[] = $since;
            $types .= 's';
        }

        $sql .= " ORDER BY changed_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            if ($row['session_context']) {
                $row['session_context'] = json_decode($row['session_context'], true);
            }
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    /**
     * Get changelog count for a play.
     */
    public static function getPlaybookHistoryCount($conn, $play_id, $since = null)
    {
        $sql = "SELECT COUNT(*) as cnt FROM playbook_changelog WHERE play_id = ?";
        $params = [$play_id];
        $types  = 'i';

        if ($since !== null) {
            $sql .= " AND changed_at >= ?";
            $params[] = $since;
            $types .= 's';
        }

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['cnt'] ?? 0);
    }

    // ========================================================================
    // CTP Audit Log (Azure SQL via sqlsrv)
    // ========================================================================

    /**
     * Log a CTP action with full detail.
     *
     * @param resource $conn       SQLSRV connection (VATSIM_TMI)
     * @param int      $session_id CTP session ID
     * @param int|null $control_id CTP flight control ID
     * @param string   $action     Action type
     * @param string|null $segment NA, OCEANIC, EU
     * @param array    $detail     Before/after detail
     * @param string|null $performer_cid Override performer CID
     * @return bool Success
     */
    public static function logCTPChange($conn, $session_id, $control_id, $action, $segment = null, $detail = [], $performer_cid = null)
    {
        $cid  = $performer_cid ?? self::getSessionCid() ?? 'system';
        $name = self::getSessionName();
        $ip   = self::getClientIp();

        $detail_json = !empty($detail) ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null;

        $sql = "INSERT INTO dbo.ctp_audit_log
                    (session_id, ctp_control_id, action_type, segment, action_detail_json, performed_by, performed_by_name, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

        $params = [
            (int)$session_id,
            $control_id !== null ? (int)$control_id : null,
            $action,
            $segment,
            $detail_json,
            $cid,
            $name,
            $ip
        ];

        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            return false;
        }
        sqlsrv_free_stmt($stmt);
        return true;
    }

    /**
     * Get CTP audit history for a session.
     */
    public static function getCTPHistory($conn, $session_id, $limit = 50, $offset = 0, $segment = null)
    {
        $sql = "SELECT log_id, session_id, ctp_control_id, action_type, segment,
                       action_detail_json, performed_by, performed_by_name, ip_address, performed_at
                FROM dbo.ctp_audit_log
                WHERE session_id = ?";
        $params = [(int)$session_id];

        if ($segment !== null) {
            $sql .= " AND segment = ?";
            $params[] = $segment;
        }

        $sql .= " ORDER BY performed_at DESC
                   OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";
        $params[] = (int)$offset;
        $params[] = (int)$limit;

        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            return [];
        }

        $rows = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            foreach ($row as $k => $v) {
                if ($v instanceof \DateTimeInterface) {
                    $utc = clone $v;
                    $utc->setTimezone(new \DateTimeZone('UTC'));
                    $row[$k] = $utc->format('Y-m-d\TH:i:s') . 'Z';
                }
            }
            if (!empty($row['action_detail_json'])) {
                $row['action_detail'] = json_decode($row['action_detail_json'], true);
            }
            $rows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
        return $rows;
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    /**
     * Get current session VATSIM CID.
     */
    private static function getSessionCid()
    {
        return $_SESSION['VATSIM_CID'] ?? null;
    }

    /**
     * Get current session display name.
     */
    private static function getSessionName()
    {
        return $_SESSION['VATSIM_NAME'] ?? $_SESSION['VATSIM_FNAME'] ?? null;
    }

    /**
     * Get client IP address.
     */
    private static function getClientIp()
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($parts[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }

    /**
     * Convert a value to string for changelog storage.
     */
    private static function valueToString($val)
    {
        if ($val === null) {
            return null;
        }
        if (is_array($val) || is_object($val)) {
            return json_encode($val, JSON_UNESCAPED_UNICODE);
        }
        if (is_bool($val)) {
            return $val ? '1' : '0';
        }
        return (string)$val;
    }

    /**
     * Check if two values are different (for diff logging).
     */
    private static function valuesChanged($old, $new)
    {
        if ($old === null && $new === null) return false;
        if ($old === null || $new === null) return true;

        // Normalize for comparison
        $old_s = self::valueToString($old);
        $new_s = self::valueToString($new);
        return $old_s !== $new_s;
    }
}
