<?php
/**
 * CTP Audit Log API
 *
 * GET /api/ctp/audit_log.php?session_id=N
 *
 * Returns paginated audit trail for a CTP session.
 *
 * Optional params:
 *   &ctp_control_id=N   Filter to specific flight
 *   &action_type=EDCT_ASSIGN,ROUTE_MODIFY   Filter by action types
 *   &limit=50&offset=0  Pagination
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('CTP_API_INCLUDED', true);
require_once(__DIR__ . '/common.php');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond_json(405, ['status' => 'error', 'message' => 'Method not allowed. Use GET.']);
}

$conn_tmi = ctp_get_conn_tmi();

$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
if ($session_id <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'session_id is required.']);
}

$limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 200) : 50;
$offset = isset($_GET['offset']) ? max((int)$_GET['offset'], 0) : 0;
$ctp_control_id = isset($_GET['ctp_control_id']) ? (int)$_GET['ctp_control_id'] : null;
$action_types = isset($_GET['action_type']) ? array_map('trim', explode(',', strtoupper($_GET['action_type']))) : null;

// Build query
$where = "WHERE session_id = ?";
$params = [$session_id];

if ($ctp_control_id) {
    $where .= " AND ctp_control_id = ?";
    $params[] = $ctp_control_id;
}

if ($action_types && count($action_types) > 0) {
    $placeholders = implode(',', array_fill(0, count($action_types), '?'));
    $where .= " AND action_type IN ($placeholders)";
    foreach ($action_types as $at) $params[] = $at;
}

// Count
$count_sql = "SELECT COUNT(*) AS cnt FROM dbo.ctp_audit_log $where";
list($total, $err) = ctp_fetch_value($conn_tmi, $count_sql, $params);
$total = (int)($total ?? 0);

// Fetch page
$data_params = array_merge($params, [$offset, $limit]);
$data_sql = "
    SELECT log_id, session_id, ctp_control_id, action_type, segment, action_detail_json, performed_by, performed_at
    FROM dbo.ctp_audit_log
    $where
    ORDER BY performed_at DESC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";

$result = ctp_fetch_all($conn_tmi, $data_sql, $data_params);
if (!$result['success']) {
    respond_json(500, ['status' => 'error', 'message' => 'Failed to fetch audit log.']);
}

// Parse JSON details
$entries = [];
foreach ($result['data'] as $row) {
    if (!empty($row['action_detail_json']) && is_string($row['action_detail_json'])) {
        $row['action_detail'] = json_decode($row['action_detail_json'], true);
    } else {
        $row['action_detail'] = null;
    }
    unset($row['action_detail_json']);
    $entries[] = $row;
}

respond_json(200, [
    'status' => 'ok',
    'data' => [
        'entries' => $entries,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset
    ]
]);
