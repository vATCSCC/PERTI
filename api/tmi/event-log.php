<?php
/**
 * TMI Event Log API
 *
 * GET /api/tmi/event-log.php
 *
 * Returns paginated, filterable TMI unified log entries.
 *
 * Query parameters:
 *   hours (int, default 4): lookback window
 *   start/end (datetime): explicit time range
 *   category (string): filter by action_category
 *   type (string): filter by action_type
 *   program_type (string): filter by program_type
 *   facility (string): filter by issuing_facility
 *   org (string): filter by issuing_org
 *   severity (string): filter by severity
 *   page (int, default 1): page number
 *   per_page (int, default 100): results per page
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

include(__DIR__ . '/../../load/config.php');
include(__DIR__ . '/../../load/input.php');
include(__DIR__ . '/../../load/connect.php');

$conn_tmi = get_conn_tmi();
if (!$conn_tmi) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'TMI database unavailable']);
    exit;
}

// Parse filters
$hours = isset($_GET['hours']) ? (int)$_GET['hours'] : 4;
$start = $_GET['start'] ?? null;
$end = $_GET['end'] ?? null;
$category = $_GET['category'] ?? null;
$type = $_GET['type'] ?? null;
$program_type = $_GET['program_type'] ?? null;
$facility = $_GET['facility'] ?? null;
$org = $_GET['org'] ?? null;
$severity = $_GET['severity'] ?? null;
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = min(500, max(1, (int)($_GET['per_page'] ?? 100)));

// Build WHERE clause
$where = [];
$params = [];

if ($start && $end) {
    $where[] = 'c.event_utc >= ? AND c.event_utc <= ?';
    $params[] = $start;
    $params[] = $end;
} else {
    $where[] = 'c.event_utc >= DATEADD(HOUR, -?, SYSUTCDATETIME())';
    $params[] = $hours;
}

if ($category) { $where[] = 'c.action_category = ?'; $params[] = $category; }
if ($type) { $where[] = 'c.action_type = ?'; $params[] = $type; }
if ($program_type) { $where[] = 'c.program_type = ?'; $params[] = $program_type; }
if ($facility) { $where[] = 'c.issuing_facility = ?'; $params[] = $facility; }
if ($org) { $where[] = 'c.issuing_org = ?'; $params[] = $org; }
if ($severity) { $where[] = 'c.severity = ?'; $params[] = $severity; }

$where_sql = implode(' AND ', $where);

// Count total
$count_sql = "SELECT COUNT(*) AS cnt FROM dbo.tmi_log_core c WHERE {$where_sql}";
$count_stmt = sqlsrv_query($conn_tmi, $count_sql, $params);
$total = 0;
if ($count_stmt) {
    $row = sqlsrv_fetch_array($count_stmt, SQLSRV_FETCH_ASSOC);
    $total = $row['cnt'] ?? 0;
    sqlsrv_free_stmt($count_stmt);
}

// Fetch page with satellite data
$offset = ($page - 1) * $per_page;
$data_sql = "
    SELECT
        c.*,
        s.ctl_element, s.element_type, s.facility, s.traffic_flow,
        p.effective_start_utc, p.effective_end_utc, p.rate_value, p.rate_unit,
        p.cause_category AS param_cause_category, p.cause_detail,
        p.ntml_formatted, p.cancellation_reason,
        i.total_flights, i.controlled_flights, i.avg_delay_min, i.max_delay_min, i.total_delay_min,
        r.program_id, r.entry_id, r.advisory_id, r.advisory_number,
        r.discord_message_id, r.discord_channel_id
    FROM dbo.tmi_log_core c
    LEFT JOIN dbo.tmi_log_scope s ON c.log_id = s.log_id
    LEFT JOIN dbo.tmi_log_parameters p ON c.log_id = p.log_id
    LEFT JOIN dbo.tmi_log_impact i ON c.log_id = i.log_id
    LEFT JOIN dbo.tmi_log_references r ON c.log_id = r.log_id
    WHERE {$where_sql}
    ORDER BY c.log_seq DESC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";
$data_params = array_merge($params, [$offset, $per_page]);
$data_stmt = sqlsrv_query($conn_tmi, $data_sql, $data_params);

$entries = [];
if ($data_stmt) {
    while ($row = sqlsrv_fetch_array($data_stmt, SQLSRV_FETCH_ASSOC)) {
        // Convert DateTime objects to strings
        foreach ($row as $k => $v) {
            if ($v instanceof DateTime) {
                $row[$k] = $v->format('Y-m-d H:i:s');
            }
        }
        $entries[] = $row;
    }
    sqlsrv_free_stmt($data_stmt);
}

echo json_encode([
    'success' => true,
    'data' => $entries,
    'pagination' => [
        'page' => $page,
        'per_page' => $per_page,
        'total' => $total,
        'pages' => ceil($total / $per_page),
    ],
    'filters' => [
        'hours' => $hours,
        'category' => $category,
        'type' => $type,
        'program_type' => $program_type,
        'facility' => $facility,
        'org' => $org,
        'severity' => $severity,
    ],
], JSON_PRETTY_PRINT);
