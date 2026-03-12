<?php
/**
 * CTP API Common Utilities
 *
 * Shared functions for CTP Oceanic Slot Management API endpoints.
 * Uses VATSIM_TMI database for session/slot management,
 * VATSIM_ADL database for flight data, and VATSIM_GIS for route expansion.
 *
 * Pattern follows api/gdt/common.php
 *
 * @version 1.0.0
 * @date 2026-03-12
 */

// Prevent direct access
if (!defined('CTP_API_INCLUDED')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Direct access not allowed']);
    exit;
}

// ============================================================================
// Response Helpers
// ============================================================================

/**
 * Send JSON response and exit
 */
function respond_json($code, $payload) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Read request payload from JSON body or form data
 */
function read_request_payload() {
    $raw = file_get_contents('php://input');
    if ($raw !== false && strlen(trim($raw)) > 0) {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
    }
    return array_merge($_GET ?? [], $_POST ?? []);
}

// ============================================================================
// Session & Database Connections
// ============================================================================

// Start session BEFORE loading config/connect, because connect.php's closing
// PHP tag outputs a trailing newline that sends headers.
require_once(__DIR__ . '/../../sessions/handler.php');

// Load core dependencies
if (!defined('PERTI_LOADED')) {
    define('PERTI_LOADED', true);
}
require_once(__DIR__ . '/../../load/config.php');
require_once(__DIR__ . '/../../load/connect.php');

// ============================================================================
// Authentication
// ============================================================================

/**
 * Require authenticated session for destructive operations.
 * @return string The authenticated user's VATSIM CID
 */
function ctp_require_auth() {
    if (!isset($_SESSION['VATSIM_CID']) || empty($_SESSION['VATSIM_CID'])) {
        respond_json(401, [
            'status' => 'error',
            'message' => 'Authentication required. Please log in.'
        ]);
    }
    return $_SESSION['VATSIM_CID'];
}

/**
 * Get authenticated CID if available, otherwise return 'anonymous'.
 * @return string VATSIM CID or 'anonymous'
 */
function ctp_optional_auth() {
    if (isset($_SESSION['VATSIM_CID']) && !empty($_SESSION['VATSIM_CID'])) {
        return $_SESSION['VATSIM_CID'];
    }
    return 'anonymous';
}

/**
 * Get TMI database connection (VATSIM_TMI)
 * @return resource SQLSRV connection resource
 */
function ctp_get_conn_tmi() {
    global $conn_tmi;
    if (!$conn_tmi) {
        $errors = ctp_filter_sqlsrv_errors();
        respond_json(500, [
            'status'  => 'error',
            'message' => 'TMI SQL connection not established.',
            'errors'  => $errors
        ]);
    }
    return $conn_tmi;
}

/**
 * Get ADL database connection (VATSIM_ADL)
 * @return resource SQLSRV connection resource
 */
function ctp_get_conn_adl() {
    global $conn_adl;
    if (!$conn_adl) {
        $errors = ctp_filter_sqlsrv_errors();
        respond_json(500, [
            'status'  => 'error',
            'message' => 'ADL SQL connection not established.',
            'errors'  => $errors
        ]);
    }
    return $conn_adl;
}

/**
 * Get GIS database connection (VATSIM_GIS via PostGIS)
 * @return PDO PostGIS PDO connection
 */
function ctp_get_conn_gis() {
    global $conn_gis;
    if (!$conn_gis) {
        respond_json(500, [
            'status'  => 'error',
            'message' => 'GIS connection not established.'
        ]);
    }
    return $conn_gis;
}

/**
 * Filter out informational SQL Server messages
 */
function ctp_filter_sqlsrv_errors() {
    if (!function_exists('sqlsrv_errors')) return null;
    $all_errors = sqlsrv_errors();
    if (!$all_errors) return null;
    $errors = array_filter($all_errors, function($e) {
        $code = isset($e['code']) ? $e['code'] : 0;
        return !in_array($code, [5701, 5703]);
    });
    return empty($errors) ? null : array_values($errors);
}

// ============================================================================
// Data Type Helpers
// ============================================================================

/**
 * Parse and validate UTC datetime string
 * @param string $s Input datetime string
 * @return string|null SQL Server compatible datetime or null
 */
function parse_utc_datetime($s) {
    if (!is_string($s) || trim($s) === '') return null;
    try {
        $dt = new DateTime(trim($s));
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Convert DateTime to ISO 8601 string for JSON
 */
function datetime_to_iso($val) {
    if ($val === null) return null;
    if ($val instanceof DateTimeInterface) {
        $utc = clone $val;
        if (method_exists($utc, 'setTimezone')) {
            $utc->setTimezone(new DateTimeZone('UTC'));
        }
        return $utc->format('Y-m-d\TH:i:s') . 'Z';
    }
    return $val;
}

/**
 * Split space/comma-delimited codes into array
 */
function split_codes($val) {
    if (is_array($val)) $val = implode(' ', $val);
    if (!is_string($val)) return [];
    $val = strtoupper(trim($val));
    if ($val === '') return [];
    $val = str_replace([",", ";", "\n", "\r", "\t"], " ", $val);
    $parts = preg_split('/\s+/', $val);
    $out = [];
    $seen = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '' && !isset($seen[$p])) {
            $seen[$p] = true;
            $out[] = $p;
        }
    }
    return $out;
}

// ============================================================================
// Query Helpers
// ============================================================================

/**
 * Fetch all rows from a query, converting DateTime objects
 */
function ctp_fetch_all($conn, $sql, $params = []) {
    $stmt = count($params) > 0
        ? sqlsrv_query($conn, $sql, $params)
        : sqlsrv_query($conn, $sql);
    if ($stmt === false) {
        return ['success' => false, 'data' => null, 'error' => sqlsrv_errors()];
    }
    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        foreach ($row as $k => $v) {
            if ($v instanceof DateTimeInterface) {
                $row[$k] = datetime_to_iso($v);
            }
        }
        $rows[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    return ['success' => true, 'data' => $rows, 'error' => null];
}

/**
 * Fetch single row from a query
 */
function ctp_fetch_one($conn, $sql, $params = []) {
    $result = ctp_fetch_all($conn, $sql, $params);
    if (!$result['success']) return $result;
    return [
        'success' => true,
        'data' => count($result['data']) > 0 ? $result['data'][0] : null,
        'error' => null
    ];
}

/**
 * Fetch a single scalar value
 */
function ctp_fetch_value($conn, $sql, $params = []) {
    $stmt = count($params) > 0
        ? sqlsrv_query($conn, $sql, $params)
        : sqlsrv_query($conn, $sql);
    if ($stmt === false) return [null, sqlsrv_errors()];
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC);
    sqlsrv_free_stmt($stmt);
    if (!$row) return [null, null];
    $val = $row[0];
    if ($val instanceof DateTimeInterface) $val = datetime_to_iso($val);
    return [$val, null];
}

/**
 * Execute a query that doesn't return results
 */
function ctp_execute($conn, $sql, $params = []) {
    $stmt = count($params) > 0
        ? sqlsrv_query($conn, $sql, $params)
        : sqlsrv_query($conn, $sql);
    if ($stmt === false) {
        return ['success' => false, 'rows_affected' => null, 'error' => sqlsrv_errors()];
    }
    $rows = sqlsrv_rows_affected($stmt);
    sqlsrv_free_stmt($stmt);
    return ['success' => true, 'rows_affected' => $rows, 'error' => null];
}

// ============================================================================
// Perspective / Authorization Helpers
// ============================================================================

/**
 * CTP session statuses
 */
define('CTP_SESSION_STATUSES', ['DRAFT', 'ACTIVE', 'MONITORING', 'COMPLETED', 'CANCELLED']);

/**
 * Route segments
 */
define('CTP_SEGMENTS', ['NA', 'OCEANIC', 'EU']);

/**
 * Check if current user's org can edit a given segment
 *
 * @param array $session Session row from ctp_sessions
 * @param string $segment NA, OCEANIC, or EU
 * @return bool True if current user has permission
 */
function ctp_check_perspective($session, $segment) {
    $cid = ctp_optional_auth();
    if ($cid === 'anonymous') return false;

    $perspective_orgs = null;
    if (!empty($session['perspective_orgs_json'])) {
        $perspective_orgs = json_decode($session['perspective_orgs_json'], true);
    }

    // If no perspective config, all authenticated users can edit all segments
    if (!$perspective_orgs || !is_array($perspective_orgs)) return true;

    // Get user's org from session
    $user_org = isset($_SESSION['USER_ORG']) ? strtoupper($_SESSION['USER_ORG']) : null;
    $user_orgs = [];
    if ($user_org) $user_orgs[] = $user_org;

    // Also check additional orgs if stored
    if (isset($_SESSION['USER_ORGS']) && is_array($_SESSION['USER_ORGS'])) {
        foreach ($_SESSION['USER_ORGS'] as $o) {
            $user_orgs[] = strtoupper($o);
        }
    }

    // GLOBAL perspective can edit any segment
    if (isset($perspective_orgs['GLOBAL']) && is_array($perspective_orgs['GLOBAL'])) {
        foreach ($user_orgs as $uo) {
            if (in_array($uo, $perspective_orgs['GLOBAL'])) return true;
        }
    }

    // Check segment-specific permission
    $segment = strtoupper($segment);
    if (isset($perspective_orgs[$segment]) && is_array($perspective_orgs[$segment])) {
        foreach ($user_orgs as $uo) {
            if (in_array($uo, $perspective_orgs[$segment])) return true;
        }
    }

    return false;
}

/**
 * Get segments the current user can edit
 *
 * @param array $session Session row from ctp_sessions
 * @return array List of editable segments (e.g. ['NA', 'OCEANIC', 'EU'])
 */
function ctp_get_user_perspectives($session) {
    $editable = [];
    foreach (CTP_SEGMENTS as $seg) {
        if (ctp_check_perspective($session, $seg)) {
            $editable[] = $seg;
        }
    }
    return $editable;
}

// ============================================================================
// SWIM WebSocket Push Helper
// ============================================================================

/**
 * Push CTP event to WebSocket immediately.
 * Reuses swim_publishToWebSocket pattern from scripts/swim_ws_events.php.
 *
 * @param string $eventType e.g. 'ctp.edct.assigned', 'ctp.route.modified'
 * @param array $data Event data payload
 * @return bool Success
 */
function ctp_push_swim_event($eventType, $data) {
    $events = [[
        'type' => $eventType,
        'data' => $data,
    ]];

    $eventFile = sys_get_temp_dir() . '/swim_ws_events.json';
    $existingEvents = [];
    if (file_exists($eventFile)) {
        $content = @file_get_contents($eventFile);
        if ($content) {
            $existingEvents = json_decode($content, true) ?: [];
        }
    }

    foreach ($events as $event) {
        $existingEvents[] = array_merge($event, [
            '_received_at' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);
    }

    if (count($existingEvents) > 10000) {
        $existingEvents = array_slice($existingEvents, -5000);
    }

    $tempFile = $eventFile . '.tmp.' . getmypid();
    if (file_put_contents($tempFile, json_encode($existingEvents)) !== false) {
        return @rename($tempFile, $eventFile);
    }
    return false;
}

// ============================================================================
// CTP Session Helper
// ============================================================================

/**
 * Get CTP session by ID
 * @param resource $conn TMI connection
 * @param int $session_id Session ID
 * @return array|null Session record or null
 */
function ctp_get_session($conn, $session_id) {
    $result = ctp_fetch_one($conn, "SELECT * FROM dbo.ctp_sessions WHERE session_id = ?", [(int)$session_id]);
    return $result['success'] ? $result['data'] : null;
}

/**
 * Insert an audit log entry with enhanced detail (name, IP).
 */
function ctp_audit_log($conn, $session_id, $ctp_control_id, $action_type, $detail, $performed_by, $segment = null) {
    $detail_json = is_array($detail) ? json_encode($detail, JSON_UNESCAPED_UNICODE) : $detail;
    $performed_by_name = $_SESSION['VATSIM_NAME'] ?? $_SESSION['VATSIM_FNAME'] ?? null;
    $ip_address = null;
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip_address = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $ip_address = $_SERVER['REMOTE_ADDR'];
    }

    return ctp_execute($conn,
        "INSERT INTO dbo.ctp_audit_log (session_id, ctp_control_id, action_type, segment, action_detail_json, performed_by, performed_by_name, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        [
            (int)$session_id,
            $ctp_control_id !== null ? (int)$ctp_control_id : null,
            $action_type,
            $segment,
            $detail_json,
            $performed_by,
            $performed_by_name,
            $ip_address
        ]
    );
}
