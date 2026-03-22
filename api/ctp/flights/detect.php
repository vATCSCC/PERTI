<?php
/**
 * CTP Flights - Detect API
 *
 * POST /api/ctp/flights/detect.php
 *
 * Triggers oceanic flight detection for a CTP session.
 * Finds flights crossing constrained FIRs within the session's time window,
 * excludes event participants and already-tracked flights,
 * then inserts new detections into ctp_flight_control.
 *
 * Request body:
 * {
 *   "session_id": 1,
 *   "include_event_flights": false  (optional, default false)
 * }
 *
 * Response:
 * {
 *   "status": "ok",
 *   "data": {
 *     "detected": 142,
 *     "skipped_event": 35,
 *     "skipped_existing": 210,
 *     "total_candidates": 387
 *   }
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
$conn_adl = ctp_get_conn_adl();
$payload = read_request_payload();

$session_id = isset($payload['session_id']) ? (int)$payload['session_id'] : 0;
if ($session_id <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'session_id is required.']);
}

$include_event = !empty($payload['include_event_flights']);

$session = ctp_get_session($conn_tmi, $session_id);
if (!$session) {
    respond_json(404, ['status' => 'error', 'message' => 'Session not found.']);
}

if (!in_array($session['status'], ['DRAFT', 'ACTIVE', 'MONITORING'])) {
    respond_json(409, ['status' => 'error', 'message' => 'Session is not in a detectable state: ' . $session['status']]);
}

// Parse constrained FIRs
$constrained_firs = [];
if (!empty($session['constrained_firs'])) {
    $constrained_firs = json_decode($session['constrained_firs'], true);
    if (!is_array($constrained_firs)) $constrained_firs = [];
}

if (empty($constrained_firs)) {
    respond_json(400, ['status' => 'error', 'message' => 'Session has no constrained FIRs configured.']);
}

$window_start = $session['constraint_window_start'];
$window_end = $session['constraint_window_end'];

// ============================================================================
// Step 1: Find flights crossing constrained FIRs within window
// ============================================================================

// Build FIR placeholder list for IN clause
$fir_placeholders = implode(',', array_fill(0, count($constrained_firs), '?'));

// Query ADL for candidate flights
// Matches flights whose planned crossings include any constrained FIR
// within the session time window
$candidates_sql = "
    SELECT DISTINCT
        c.flight_uid,
        c.callsign,
        p.fp_dept_icao AS dep_airport,
        p.fp_dest_icao AS arr_airport,
        c.current_artcc_id AS dep_artcc,
        p.aircraft_equip AS aircraft_type,
        p.fp_route AS filed_route,
        p.fp_altitude_ft AS filed_altitude,
        t.std_utc,
        t.etd_utc,
        -- Oceanic crossing details (first constrained FIR entry)
        xing.boundary_code AS oceanic_entry_fir,
        xing.entry_fix_name AS oceanic_entry_fix,
        xing.planned_entry_utc AS oceanic_entry_utc,
        xing.planned_exit_utc AS oceanic_exit_utc,
        xing.exit_fix_name AS oceanic_exit_fix
    FROM dbo.adl_flight_planned_crossings xing
    JOIN dbo.adl_flight_core c ON c.flight_uid = xing.flight_uid
    JOIN dbo.adl_flight_plan p ON p.flight_uid = xing.flight_uid
    LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = xing.flight_uid
    WHERE xing.boundary_code IN ({$fir_placeholders})
      AND xing.crossing_type IN ('ENTRY', 'CROSS')
      AND xing.planned_entry_utc >= ?
      AND xing.planned_entry_utc <= ?
      AND c.is_active = 1
";

$adl_params = array_merge(
    $constrained_firs,
    [$window_start, $window_end]
);

$stmt = sqlsrv_query($conn_adl, $candidates_sql, $adl_params);
if ($stmt === false) {
    respond_json(500, ['status' => 'error', 'message' => 'Failed to query candidates.', 'errors' => sqlsrv_errors()]);
}

$candidates = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    // Convert DateTime objects
    foreach ($row as $k => $v) {
        if ($v instanceof DateTimeInterface) {
            $row[$k] = datetime_to_iso($v);
        }
    }
    $uid = (int)$row['flight_uid'];
    // Keep earliest entry per flight
    if (!isset($candidates[$uid]) || $row['oceanic_entry_utc'] < $candidates[$uid]['oceanic_entry_utc']) {
        $candidates[$uid] = $row;
    }
}
sqlsrv_free_stmt($stmt);

$total_candidates = count($candidates);
if ($total_candidates === 0) {
    respond_json(200, [
        'status' => 'ok',
        'data' => ['detected' => 0, 'skipped_event' => 0, 'skipped_existing' => 0, 'total_candidates' => 0]
    ]);
}

// ============================================================================
// Step 2: Exclude event participants (unless include_event_flights)
// ============================================================================

$skipped_event = 0;
if (!$include_event && !empty($session['flow_event_id'])) {
    $event_id = (int)$session['flow_event_id'];

    // Get event participants from TMI
    $participants_result = ctp_fetch_all($conn_tmi,
        "SELECT callsign, dep_aerodrome, arr_aerodrome FROM dbo.tmi_flow_event_participants WHERE event_id = ?",
        [$event_id]
    );

    if ($participants_result['success'] && !empty($participants_result['data'])) {
        $event_lookup = [];
        foreach ($participants_result['data'] as $ep) {
            $key = strtoupper(trim($ep['callsign'] ?? ''));
            if ($key !== '') $event_lookup[$key] = true;
        }

        foreach ($candidates as $uid => $c) {
            $cs = strtoupper(trim($c['callsign']));
            if (isset($event_lookup[$cs])) {
                unset($candidates[$uid]);
                $skipped_event++;
            }
        }
    }
}

// ============================================================================
// Step 3: Exclude already-tracked flights
// ============================================================================

$existing_uids = [];
$existing_result = ctp_fetch_all($conn_tmi,
    "SELECT flight_uid FROM dbo.ctp_flight_control WHERE session_id = ?",
    [$session_id]
);
if ($existing_result['success']) {
    foreach ($existing_result['data'] as $e) {
        $existing_uids[(int)$e['flight_uid']] = true;
    }
}

$skipped_existing = 0;
foreach ($candidates as $uid => $c) {
    if (isset($existing_uids[$uid])) {
        unset($candidates[$uid]);
        $skipped_existing++;
    }
}

// ============================================================================
// Step 4: Also find the last constrained FIR (exit) for each flight
// ============================================================================

// For flights that traverse multiple constrained FIRs, find the exit FIR
$flight_uids = array_keys($candidates);
$exit_info = [];
if (!empty($flight_uids)) {
    // Process in chunks to avoid huge IN clause
    foreach (array_chunk($flight_uids, 500) as $chunk) {
        $uid_ph = implode(',', array_fill(0, count($chunk), '?'));
        $exit_sql = "
            SELECT flight_uid, boundary_code, exit_fix_name, planned_exit_utc
            FROM dbo.adl_flight_planned_crossings
            WHERE flight_uid IN ({$uid_ph})
              AND boundary_code IN ({$fir_placeholders})
              AND crossing_type IN ('EXIT', 'CROSS')
              AND planned_exit_utc IS NOT NULL
            ORDER BY planned_exit_utc DESC
        ";
        $exit_params = array_merge($chunk, $constrained_firs);
        $exit_stmt = sqlsrv_query($conn_adl, $exit_sql, $exit_params);
        if ($exit_stmt) {
            while ($row = sqlsrv_fetch_array($exit_stmt, SQLSRV_FETCH_ASSOC)) {
                $uid = (int)$row['flight_uid'];
                if (!isset($exit_info[$uid])) {
                    foreach ($row as $k => $v) {
                        if ($v instanceof DateTimeInterface) $row[$k] = datetime_to_iso($v);
                    }
                    $exit_info[$uid] = $row;
                }
            }
            sqlsrv_free_stmt($exit_stmt);
        }
    }
}

// ============================================================================
// Step 5: Batch insert into ctp_flight_control
// ============================================================================

$detected = 0;
$insert_sql = "
    INSERT INTO dbo.ctp_flight_control (
        session_id, flight_uid, callsign,
        dep_airport, arr_airport, dep_artcc, aircraft_type,
        filed_route, filed_altitude,
        oceanic_entry_fir, oceanic_exit_fir,
        oceanic_entry_fix, oceanic_exit_fix,
        oceanic_entry_utc, oceanic_exit_utc,
        original_etd_utc, is_event_flight
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
";

foreach ($candidates as $uid => $c) {
    $exit = isset($exit_info[$uid]) ? $exit_info[$uid] : null;
    $oceanic_exit_fir = $exit ? $exit['boundary_code'] : $c['oceanic_entry_fir'];
    $oceanic_exit_fix = $exit ? $exit['exit_fix_name'] : $c['oceanic_exit_fix'];
    $oceanic_exit_utc = $exit ? parse_utc_datetime($exit['planned_exit_utc']) : parse_utc_datetime($c['oceanic_exit_utc']);

    $original_etd = null;
    if (!empty($c['etd_utc'])) $original_etd = parse_utc_datetime($c['etd_utc']);
    elseif (!empty($c['std_utc'])) $original_etd = parse_utc_datetime($c['std_utc']);

    $params = [
        $session_id,
        (int)$uid,
        $c['callsign'],
        $c['dep_airport'],
        $c['arr_airport'],
        $c['dep_artcc'],
        $c['aircraft_type'],
        $c['filed_route'],
        !empty($c['filed_altitude']) ? (int)$c['filed_altitude'] : null,
        $c['oceanic_entry_fir'],
        $oceanic_exit_fir,
        $c['oceanic_entry_fix'],
        $oceanic_exit_fix,
        parse_utc_datetime($c['oceanic_entry_utc']),
        $oceanic_exit_utc,
        $original_etd,
        $include_event ? 1 : 0
    ];

    $ins_stmt = sqlsrv_query($conn_tmi, $insert_sql, $params);
    if ($ins_stmt !== false) {
        sqlsrv_free_stmt($ins_stmt);
        $detected++;
    }
}

// Resolve NAT tracks for newly detected flights
$nat_resolved_count = 0;
if ($detected > 0) {
    $active_tracks = getActiveTracksForResolution($session_id);
    // Token matching uses filed_route (works immediately); sequence matching needs seg_oceanic_route.
    // Include flights with either field so token resolution works even before oceanic decomposition.
    $resolve_sql = "SELECT ctp_control_id, filed_route, seg_oceanic_route
                    FROM dbo.ctp_flight_control
                    WHERE session_id = ? AND resolved_nat_track IS NULL
                      AND (filed_route IS NOT NULL OR seg_oceanic_route IS NOT NULL)";
    $resolve_result = ctp_fetch_all($conn_tmi, $resolve_sql, [$session_id]);
    if ($resolve_result['success'] && !empty($resolve_result['data'])) {
        foreach ($resolve_result['data'] as $f) {
            $res = resolveAndPersistNATTrack(
                $conn_tmi,
                (int)$f['ctp_control_id'],
                $f['filed_route'] ?? '',
                $f['seg_oceanic_route'] ?? '',
                $active_tracks
            );
            if ($res !== null) $nat_resolved_count++;
        }
    }
}

// Update session stats
ctp_execute($conn_tmi,
    "UPDATE dbo.ctp_sessions SET total_flights = (SELECT COUNT(*) FROM dbo.ctp_flight_control WHERE session_id = ?) WHERE session_id = ?",
    [$session_id, $session_id]
);

// Audit log
ctp_audit_log($conn_tmi, $session_id, null, 'FLIGHTS_DETECT', [
    'detected' => $detected,
    'skipped_event' => $skipped_event,
    'skipped_existing' => $skipped_existing,
    'total_candidates' => $total_candidates
], $cid);

// SWIM push
if ($detected > 0) {
    ctp_push_swim_event('ctp.flights.detected', [
        'session_id' => $session_id,
        'count' => $detected
    ]);
}

respond_json(200, [
    'status' => 'ok',
    'data' => [
        'detected' => $detected,
        'skipped_event' => $skipped_event,
        'skipped_existing' => $skipped_existing,
        'total_candidates' => $total_candidates,
        'nat_resolved' => $nat_resolved_count
    ]
]);
