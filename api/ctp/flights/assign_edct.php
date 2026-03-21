<?php
/**
 * CTP Flights - Assign EDCT API
 *
 * POST /api/ctp/flights/assign_edct.php
 *
 * Assigns an EDCT (Expect Departure Clearance Time) to a CTP-managed flight.
 * Also creates/updates the corresponding tmi_flight_control record for TMI->ADL sync.
 *
 * Request body:
 * {
 *   "ctp_control_id": 12345,
 *   "edct_utc": "2026-04-15T14:30:00Z"
 * }
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('CTP_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');
require_once(__DIR__ . '/../../../load/services/NATTrackResolver.php');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond_json(405, ['status' => 'error', 'message' => 'Method not allowed. Use POST.']);
}

$cid = ctp_require_auth();
$conn_tmi = ctp_get_conn_tmi();
$payload = read_request_payload();

$ctp_control_id = isset($payload['ctp_control_id']) ? (int)$payload['ctp_control_id'] : 0;
if ($ctp_control_id <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'ctp_control_id is required.']);
}

$edct_raw = isset($payload['edct_utc']) ? trim($payload['edct_utc']) : '';
if ($edct_raw === '') {
    respond_json(400, ['status' => 'error', 'message' => 'edct_utc is required.']);
}

$edct_utc = parse_utc_datetime($edct_raw);
if (!$edct_utc) {
    respond_json(400, ['status' => 'error', 'message' => 'edct_utc is not a valid datetime.']);
}

// Get flight record
$flight_result = ctp_fetch_one($conn_tmi,
    "SELECT ctp_control_id, session_id, flight_uid, callsign, dep_airport, arr_airport,
            dep_artcc, arr_artcc, original_etd_utc, edct_utc, edct_status, tmi_control_id
     FROM dbo.ctp_flight_control WHERE ctp_control_id = ?",
    [$ctp_control_id]
);
if (!$flight_result['success'] || !$flight_result['data']) {
    respond_json(404, ['status' => 'error', 'message' => 'Flight not found.']);
}
$flight = $flight_result['data'];
$session_id = (int)$flight['session_id'];

// Get session and validate status
$session = ctp_get_session($conn_tmi, $session_id);
if (!$session) {
    respond_json(404, ['status' => 'error', 'message' => 'Session not found.']);
}
if (!in_array($session['status'], ['ACTIVE', 'MONITORING'])) {
    respond_json(409, ['status' => 'error', 'message' => 'Session must be ACTIVE or MONITORING to assign EDCTs.']);
}

// Calculate delay
$orig_etd = $flight['original_etd_utc'];
$slot_delay_min = null;
if ($orig_etd) {
    $orig_etd_str = ($orig_etd instanceof DateTimeInterface) ? $orig_etd->format('Y-m-d H:i:s') : $orig_etd;
    $orig_ts = strtotime($orig_etd_str);
    $edct_ts = strtotime($edct_utc);
    if ($orig_ts && $edct_ts) {
        $slot_delay_min = (int)round(($edct_ts - $orig_ts) / 60);
    }
}

$now = gmdate('Y-m-d H:i:s');
$old_edct = $flight['edct_utc'];

// Update ctp_flight_control
$update_sql = "
    UPDATE dbo.ctp_flight_control SET
        edct_utc = ?,
        edct_status = 'ASSIGNED',
        slot_delay_min = ?,
        edct_assigned_by = ?,
        edct_assigned_at = ?,
        swim_push_version = swim_push_version + 1
    WHERE ctp_control_id = ?
";
$result = ctp_execute($conn_tmi, $update_sql, [
    $edct_utc,
    $slot_delay_min,
    $cid,
    $now,
    $ctp_control_id
]);
if (!$result['success']) {
    respond_json(500, ['status' => 'error', 'message' => 'Failed to assign EDCT.']);
}

// Create/update tmi_flight_control for TMI->ADL sync (if session has a program_id)
$program_id = isset($session['program_id']) ? (int)$session['program_id'] : null;
if ($program_id && $flight['flight_uid']) {
    $tmi_control_id = $flight['tmi_control_id'] ? (int)$flight['tmi_control_id'] : null;

    if ($tmi_control_id) {
        // Update existing
        ctp_execute($conn_tmi,
            "UPDATE dbo.tmi_flight_control SET
                ctd_utc = ?, program_delay_min = ?, modified_utc = SYSUTCDATETIME()
             WHERE control_id = ?",
            [$edct_utc, $slot_delay_min, $tmi_control_id]
        );
    } else {
        // Insert new
        $insert_sql = "
            INSERT INTO dbo.tmi_flight_control (
                flight_uid, callsign, program_id,
                ctl_type, ctl_elem,
                ctd_utc, octd_utc,
                orig_etd_utc,
                program_delay_min,
                dep_airport, arr_airport,
                control_assigned_utc
            ) VALUES (?, ?, ?, 'CTP', ?, ?, ?, ?, ?, ?, ?, SYSUTCDATETIME());
            SELECT SCOPE_IDENTITY() AS control_id;
        ";
        $ctl_elem = $session['session_name'] ?? 'CTP';
        $stmt = sqlsrv_query($conn_tmi, $insert_sql, [
            (int)$flight['flight_uid'],
            $flight['callsign'],
            $program_id,
            $ctl_elem,
            $edct_utc,
            $edct_utc, // octd_utc = first assigned EDCT
            $orig_etd ? (($orig_etd instanceof DateTimeInterface) ? $orig_etd->format('Y-m-d H:i:s') : $orig_etd) : null,
            $slot_delay_min,
            $flight['dep_airport'],
            $flight['arr_airport']
        ]);
        if ($stmt !== false) {
            sqlsrv_next_result($stmt);
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            $new_control_id = $row ? (int)$row['control_id'] : null;
            sqlsrv_free_stmt($stmt);

            // Link back to ctp_flight_control
            if ($new_control_id) {
                ctp_execute($conn_tmi,
                    "UPDATE dbo.ctp_flight_control SET tmi_control_id = ? WHERE ctp_control_id = ?",
                    [$new_control_id, $ctp_control_id]
                );
            }
        }
    }
}

// Audit log
ctp_audit_log($conn_tmi, $session_id, $ctp_control_id, 'EDCT_ASSIGN', [
    'old_edct' => $old_edct ? datetime_to_iso($old_edct) : null,
    'new_edct' => $edct_utc,
    'delay_min' => $slot_delay_min
], $cid);

// SWIM push
ctp_push_swim_event('ctp.edct.assigned', [
    'session_id' => $session_id,
    'ctp_control_id' => $ctp_control_id,
    'callsign' => $flight['callsign'],
    'edct_utc' => $edct_utc,
    'delay_min' => $slot_delay_min
]);

// SWIM immediate push for resolved_nat_track columns
$conn_swim = get_conn_swim();
if ($conn_swim && !empty($flight['flight_uid'])) {
    $nat_data = ctp_fetch_one($conn_tmi,
        "SELECT resolved_nat_track, nat_track_resolved_at, nat_track_source FROM dbo.ctp_flight_control WHERE ctp_control_id = ?",
        [$ctp_control_id]);
    if ($nat_data['success'] && $nat_data['data']) {
        $nd = $nat_data['data'];
        sqlsrv_query($conn_swim,
            "UPDATE dbo.swim_flights SET resolved_nat_track = ?, nat_track_resolved_at = ?, nat_track_source = ? WHERE flight_uid = ?",
            [$nd['resolved_nat_track'], $nd['nat_track_resolved_at'], $nd['nat_track_source'], $flight['flight_uid']]);
    }
}

respond_json(200, [
    'status' => 'ok',
    'data' => [
        'ctp_control_id' => $ctp_control_id,
        'edct_utc' => $edct_utc,
        'edct_status' => 'ASSIGNED',
        'slot_delay_min' => $slot_delay_min
    ]
]);
