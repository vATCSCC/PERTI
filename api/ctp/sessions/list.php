<?php
/**
 * CTP Sessions - List API
 *
 * GET /api/ctp/sessions/list.php
 * GET /api/ctp/sessions/list.php?status=ACTIVE
 *
 * Lists CTP sessions with optional status filter.
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('CTP_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond_json(405, ['status' => 'error', 'message' => 'Method not allowed. Use GET.']);
}

$conn = ctp_get_conn_tmi();

// Parse parameters
$status = isset($_GET['status']) ? strtoupper(trim($_GET['status'])) : null;
$limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 50;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

$where = [];
$params = [];

if ($status !== null && $status !== '') {
    $statuses = split_codes($status);
    if (count($statuses) === 1) {
        $where[] = "s.status = ?";
        $params[] = $statuses[0];
    } elseif (count($statuses) > 1) {
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $where[] = "s.status IN ({$placeholders})";
        $params = array_merge($params, $statuses);
    }
}

$where_sql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

// Get total count
list($total, $err) = ctp_fetch_value($conn, "SELECT COUNT(*) FROM dbo.ctp_sessions s {$where_sql}", $params);

// Fetch sessions
$params_paged = array_merge($params, [$offset, $limit]);
$result = ctp_fetch_all($conn, "
    SELECT
        s.session_id,
        s.flow_event_id,
        s.program_id,
        s.session_name,
        s.direction,
        s.constrained_firs,
        s.constraint_window_start,
        s.constraint_window_end,
        s.slot_interval_min,
        s.max_slots_per_hour,
        s.managing_orgs,
        s.perspective_orgs_json,
        s.status,
        s.total_flights,
        s.slotted_flights,
        s.modified_flights,
        s.excluded_flights,
        s.created_by,
        s.created_at,
        s.updated_at,
        e.event_name,
        e.event_code
    FROM dbo.ctp_sessions s
    LEFT JOIN dbo.tmi_flow_events e ON s.flow_event_id = e.event_id
    {$where_sql}
    ORDER BY
        CASE s.status
            WHEN 'ACTIVE' THEN 1
            WHEN 'MONITORING' THEN 2
            WHEN 'DRAFT' THEN 3
            WHEN 'COMPLETED' THEN 4
            WHEN 'CANCELLED' THEN 5
        END,
        s.constraint_window_start DESC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
", $params_paged);

respond_json(200, [
    'status' => 'ok',
    'data' => [
        'sessions' => $result['success'] ? $result['data'] : [],
        'total' => (int)($total ?? 0),
        'limit' => $limit,
        'offset' => $offset
    ]
]);
