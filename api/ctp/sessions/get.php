<?php
/**
 * CTP Sessions - Get API
 *
 * GET /api/ctp/sessions/get.php?session_id=N
 *
 * Returns session detail with stats and user perspectives.
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

$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
if ($session_id <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'session_id is required.']);
}

$session = ctp_get_session($conn, $session_id);
if (!$session) {
    respond_json(404, ['status' => 'error', 'message' => 'Session not found.']);
}

// Get live stats
$stats_result = ctp_fetch_one($conn, "
    SELECT
        COUNT(*) AS total_flights,
        SUM(CASE WHEN edct_status != 'NONE' THEN 1 ELSE 0 END) AS slotted_flights,
        SUM(CASE WHEN route_status != 'FILED' THEN 1 ELSE 0 END) AS modified_flights,
        SUM(CASE WHEN is_excluded = 1 THEN 1 ELSE 0 END) AS excluded_flights,
        SUM(CASE WHEN is_event_flight = 1 THEN 1 ELSE 0 END) AS event_flights,
        AVG(CAST(slot_delay_min AS FLOAT)) AS avg_delay_min,
        MAX(slot_delay_min) AS max_delay_min,
        SUM(CASE WHEN compliance_status = 'ON_TIME' THEN 1 ELSE 0 END) AS compliant_flights,
        SUM(CASE WHEN compliance_status = 'LATE' THEN 1 ELSE 0 END) AS late_flights,
        SUM(CASE WHEN compliance_status = 'EARLY' THEN 1 ELSE 0 END) AS early_flights,
        SUM(CASE WHEN compliance_status = 'NO_SHOW' THEN 1 ELSE 0 END) AS no_show_flights
    FROM dbo.ctp_flight_control
    WHERE session_id = ?
", [$session_id]);

$stats = $stats_result['success'] ? $stats_result['data'] : null;
if ($stats && isset($stats['avg_delay_min'])) {
    $stats['avg_delay_min'] = round((float)$stats['avg_delay_min'], 1);
}

// Get user's editable perspectives
$perspectives = ctp_get_user_perspectives($session);

// Get event info if linked
$event = null;
if (!empty($session['flow_event_id'])) {
    $event_result = ctp_fetch_one($conn, "
        SELECT event_id, event_code, event_name, event_type, start_utc, end_utc, status, participant_count
        FROM dbo.tmi_flow_events WHERE event_id = ?
    ", [(int)$session['flow_event_id']]);
    $event = $event_result['success'] ? $event_result['data'] : null;
}

respond_json(200, [
    'status' => 'ok',
    'data' => [
        'session' => $session,
        'stats' => $stats,
        'event' => $event,
        'user_perspectives' => $perspectives
    ]
]);
