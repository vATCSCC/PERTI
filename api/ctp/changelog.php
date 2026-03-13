<?php
/**
 * CTP Audit Log / Changelog API
 *
 * Returns CTP action audit trail for a session. Provides comprehensive
 * change history with before/after values, author info, and timestamps.
 *
 * GET /api/ctp/changelog.php?session_id=X
 * GET /api/ctp/changelog.php?session_id=X&segment=NA
 * GET /api/ctp/changelog.php?session_id=X&action_type=ROUTE_MODIFY
 * GET /api/ctp/changelog.php?session_id=X&limit=50&offset=0
 *
 * @version 1.0.0
 */

define('CTP_API_INCLUDED', true);
require_once(__DIR__ . '/common.php');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_json(405, ['status' => 'error', 'message' => 'Method not allowed']);
}

$session_id  = isset($_GET['session_id']) ? (int)$_GET['session_id'] : null;
$segment     = isset($_GET['segment']) ? strtoupper(trim($_GET['segment'])) : null;
$action_type = isset($_GET['action_type']) ? strtoupper(trim($_GET['action_type'])) : null;
$performer   = isset($_GET['performed_by']) ? trim($_GET['performed_by']) : null;
$limit       = isset($_GET['limit']) ? min(200, max(1, (int)$_GET['limit'])) : 50;
$offset      = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

if ($session_id === null) {
    respond_json(400, ['status' => 'error', 'message' => 'session_id is required']);
}

$conn = ctp_get_conn_tmi();

// Verify session exists
$session = ctp_get_session($conn, $session_id);
if (!$session) {
    respond_json(404, ['status' => 'error', 'message' => 'CTP session not found']);
}

// Build query
$sql = "SELECT log_id, session_id, ctp_control_id, action_type, segment,
               action_detail_json, performed_by, performed_by_name, ip_address, performed_at
        FROM dbo.ctp_audit_log
        WHERE session_id = ?";
$params = [(int)$session_id];

if ($segment !== null && in_array($segment, ['NA', 'OCEANIC', 'EU'])) {
    $sql .= " AND segment = ?";
    $params[] = $segment;
}

if ($action_type !== null) {
    $sql .= " AND action_type = ?";
    $params[] = $action_type;
}

if ($performer !== null) {
    $sql .= " AND performed_by = ?";
    $params[] = $performer;
}

// Count total
$count_sql = str_replace(
    'SELECT log_id, session_id, ctp_control_id, action_type, segment, action_detail_json, performed_by, performed_by_name, ip_address, performed_at',
    'SELECT COUNT(*) AS total',
    $sql
);
list($total, $err) = ctp_fetch_value($conn, $count_sql, $params);
if ($err) {
    respond_json(500, ['status' => 'error', 'message' => 'Count query failed']);
}

// Fetch page
$sql .= " ORDER BY performed_at DESC
           OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";
$params[] = (int)$offset;
$params[] = (int)$limit;

$result = ctp_fetch_all($conn, $sql, $params);
if (!$result['success']) {
    respond_json(500, ['status' => 'error', 'message' => 'Query failed', 'errors' => $result['error']]);
}

// Parse action_detail_json
$entries = [];
foreach ($result['data'] as $row) {
    if (!empty($row['action_detail_json'])) {
        $row['action_detail'] = json_decode($row['action_detail_json'], true);
    } else {
        $row['action_detail'] = null;
    }
    unset($row['action_detail_json']);
    $entries[] = $row;
}

respond_json(200, [
    'status'     => 'ok',
    'session_id' => $session_id,
    'total'      => (int)$total,
    'limit'      => $limit,
    'offset'     => $offset,
    'data'       => $entries,
]);
