<?php
/**
 * CTP Flights - Get API
 *
 * GET /api/ctp/flights/get.php?ctp_control_id=N
 *
 * Returns full flight detail including all times, route segments,
 * compliance info, and audit history.
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

$conn_tmi = ctp_get_conn_tmi();
$conn_adl = ctp_get_conn_adl();

$ctp_control_id = isset($_GET['ctp_control_id']) ? (int)$_GET['ctp_control_id'] : 0;
if ($ctp_control_id <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'ctp_control_id is required.']);
}

// Get CTP flight control record (full)
$flight_result = ctp_fetch_one($conn_tmi, "SELECT * FROM dbo.ctp_flight_control WHERE ctp_control_id = ?", [$ctp_control_id]);
if (!$flight_result['success'] || !$flight_result['data']) {
    respond_json(404, ['status' => 'error', 'message' => 'Flight not found.']);
}
$flight = $flight_result['data'];

// Get session info for perspective check
$session = ctp_get_session($conn_tmi, (int)$flight['session_id']);
$perspectives = $session ? ctp_get_user_perspectives($session) : [];

// Get live flight times from ADL
$times = null;
$position = null;
$flight_uid = (int)$flight['flight_uid'];

$times_result = ctp_fetch_one($conn_adl, "
    SELECT
        t.std_utc, t.etd_utc, t.atd_utc,
        t.out_utc, t.off_utc, t.on_utc, t.in_utc,
        t.eta_dest_utc, t.ata_dest_utc,
        t.edct_utc AS adl_edct_utc,
        c.flight_phase, c.is_active
    FROM dbo.adl_flight_times t
    JOIN dbo.adl_flight_core c ON c.flight_uid = t.flight_uid
    WHERE t.flight_uid = ?
", [$flight_uid]);
if ($times_result['success']) {
    $times = $times_result['data'];
}

// Get current position
$pos_result = ctp_fetch_one($conn_adl, "
    SELECT lat, lon, altitude_ft, groundspeed_kts, heading_deg, vertical_rate_fpm, updated_at
    FROM dbo.adl_flight_position
    WHERE flight_uid = ?
", [$flight_uid]);
if ($pos_result['success']) {
    $position = $pos_result['data'];
}

// Get recent audit log entries for this flight
$audit_result = ctp_fetch_all($conn_tmi, "
    SELECT TOP 20
        log_id, action_type, segment, action_detail_json, performed_by, performed_at
    FROM dbo.ctp_audit_log
    WHERE ctp_control_id = ?
    ORDER BY performed_at DESC
", [$ctp_control_id]);
$audit = $audit_result['success'] ? $audit_result['data'] : [];

respond_json(200, [
    'status' => 'ok',
    'data' => [
        'flight' => $flight,
        'times' => $times,
        'position' => $position,
        'audit' => $audit,
        'user_perspectives' => $perspectives
    ]
]);
