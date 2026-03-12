<?php
/**
 * CTP Flights - Batch EDCT Assignment API
 *
 * POST /api/ctp/flights/assign_edct_batch.php
 *
 * Assigns EDCTs to multiple flights in a single request.
 * Supports explicit assignment or auto-assignment mode.
 *
 * Request body (explicit):
 * {
 *   "session_id": 1,
 *   "assignments": [
 *     { "ctp_control_id": 123, "edct_utc": "2026-04-15T14:30:00Z" },
 *     { "ctp_control_id": 124, "edct_utc": "2026-04-15T14:35:00Z" }
 *   ]
 * }
 *
 * Request body (auto-assign):
 * {
 *   "session_id": 1,
 *   "auto_assign": true,
 *   "base_time_utc": "2026-04-15T14:00:00Z",
 *   "interval_min": 5,
 *   "max_per_hour": 12,
 *   "flight_ids": [123, 124, 125]       (optional: subset; omit = all unassigned)
 * }
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('CTP_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond_json(405, ['status' => 'error', 'message' => 'Method not allowed. Use POST.']);
}

$cid = ctp_require_auth();
$conn_tmi = ctp_get_conn_tmi();
$payload = read_request_payload();

$session_id = isset($payload['session_id']) ? (int)$payload['session_id'] : 0;
if ($session_id <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'session_id is required.']);
}

// Get session
$session = ctp_get_session($conn_tmi, $session_id);
if (!$session) {
    respond_json(404, ['status' => 'error', 'message' => 'Session not found.']);
}
if (!in_array($session['status'], ['ACTIVE', 'MONITORING'])) {
    respond_json(409, ['status' => 'error', 'message' => 'Session must be ACTIVE or MONITORING.']);
}

$auto_assign = !empty($payload['auto_assign']);
$assignments = [];

if ($auto_assign) {
    // Auto-assign mode: sequential EDCTs by oceanic entry time
    $base_time = isset($payload['base_time_utc']) ? parse_utc_datetime($payload['base_time_utc']) : null;
    if (!$base_time) {
        respond_json(400, ['status' => 'error', 'message' => 'base_time_utc is required for auto-assign.']);
    }

    $interval = isset($payload['interval_min']) ? (int)$payload['interval_min'] : ((int)($session['slot_interval_min'] ?? 5));
    $max_per_hour = isset($payload['max_per_hour']) ? (int)$payload['max_per_hour'] : (isset($session['max_slots_per_hour']) ? (int)$session['max_slots_per_hour'] : null);

    // Get unassigned flights sorted by oceanic entry time
    $filter_ids = isset($payload['flight_ids']) && is_array($payload['flight_ids']) ? $payload['flight_ids'] : null;

    $sql = "SELECT ctp_control_id, original_etd_utc, oceanic_entry_utc
            FROM dbo.ctp_flight_control
            WHERE session_id = ? AND is_excluded = 0 AND edct_status IN ('NONE', 'ASSIGNED')";
    $params = [$session_id];

    if ($filter_ids && count($filter_ids) > 0) {
        $placeholders = implode(',', array_fill(0, count($filter_ids), '?'));
        $sql .= " AND ctp_control_id IN ($placeholders)";
        foreach ($filter_ids as $fid) {
            $params[] = (int)$fid;
        }
        // Only assign to unassigned flights when using filter
        $sql = str_replace("IN ('NONE', 'ASSIGNED')", "= 'NONE'", $sql);
    } else {
        $sql .= " AND edct_status = 'NONE'";
    }
    $sql .= " ORDER BY oceanic_entry_utc ASC, ctp_control_id ASC";

    $flights_result = ctp_fetch_all($conn_tmi, $sql, $params);
    if (!$flights_result['success']) {
        respond_json(500, ['status' => 'error', 'message' => 'Failed to fetch flights.']);
    }

    $base_ts = strtotime($base_time);
    $slot_index = 0;
    $hour_counts = []; // Track per-hour counts for rate cap

    foreach ($flights_result['data'] as $f) {
        $edct_ts = $base_ts + ($slot_index * $interval * 60);

        // Rate cap check
        if ($max_per_hour) {
            $hour_key = (int)floor(($edct_ts - $base_ts) / 3600);
            if (!isset($hour_counts[$hour_key])) $hour_counts[$hour_key] = 0;

            if ($hour_counts[$hour_key] >= $max_per_hour) {
                // Move to next hour
                $hour_key++;
                $edct_ts = $base_ts + ($hour_key * 3600);
                $hour_counts[$hour_key] = 0;
            }
            $hour_counts[$hour_key]++;
        }

        $assignments[] = [
            'ctp_control_id' => (int)$f['ctp_control_id'],
            'edct_utc' => gmdate('Y-m-d H:i:s', $edct_ts)
        ];
        $slot_index++;
    }
} else {
    // Explicit mode
    if (!isset($payload['assignments']) || !is_array($payload['assignments'])) {
        respond_json(400, ['status' => 'error', 'message' => 'assignments array or auto_assign required.']);
    }
    foreach ($payload['assignments'] as $a) {
        $id = isset($a['ctp_control_id']) ? (int)$a['ctp_control_id'] : 0;
        $edct = isset($a['edct_utc']) ? parse_utc_datetime($a['edct_utc']) : null;
        if ($id > 0 && $edct) {
            $assignments[] = ['ctp_control_id' => $id, 'edct_utc' => $edct];
        }
    }
}

if (empty($assignments)) {
    respond_json(400, ['status' => 'error', 'message' => 'No valid assignments to process.']);
}

// Process assignments
$now = gmdate('Y-m-d H:i:s');
$success_count = 0;
$errors = [];

foreach ($assignments as $a) {
    $ctrl_id = $a['ctp_control_id'];
    $edct = $a['edct_utc'];

    // Get flight for delay calc
    $fr = ctp_fetch_one($conn_tmi,
        "SELECT original_etd_utc, callsign FROM dbo.ctp_flight_control WHERE ctp_control_id = ? AND session_id = ?",
        [$ctrl_id, $session_id]
    );
    if (!$fr['success'] || !$fr['data']) {
        $errors[] = ['ctp_control_id' => $ctrl_id, 'error' => 'Flight not found'];
        continue;
    }

    $orig_etd = $fr['data']['original_etd_utc'];
    $delay = null;
    if ($orig_etd) {
        $orig_str = ($orig_etd instanceof DateTimeInterface) ? $orig_etd->format('Y-m-d H:i:s') : $orig_etd;
        $orig_ts = strtotime($orig_str);
        $edct_ts = strtotime($edct);
        if ($orig_ts && $edct_ts) $delay = (int)round(($edct_ts - $orig_ts) / 60);
    }

    $result = ctp_execute($conn_tmi,
        "UPDATE dbo.ctp_flight_control SET
            edct_utc = ?, edct_status = 'ASSIGNED', slot_delay_min = ?,
            edct_assigned_by = ?, edct_assigned_at = ?,
            swim_push_version = swim_push_version + 1
         WHERE ctp_control_id = ? AND session_id = ?",
        [$edct, $delay, $cid, $now, $ctrl_id, $session_id]
    );

    if ($result['success']) {
        $success_count++;

        ctp_audit_log($conn_tmi, $session_id, $ctrl_id, 'EDCT_ASSIGN', [
            'new_edct' => $edct, 'delay_min' => $delay, 'batch' => true
        ], $cid);
    } else {
        $errors[] = ['ctp_control_id' => $ctrl_id, 'error' => 'Update failed'];
    }
}

// SWIM push (batch notification)
ctp_push_swim_event('ctp.edct.batch_assigned', [
    'session_id' => $session_id,
    'count' => $success_count,
    'auto_assign' => $auto_assign
]);

respond_json(200, [
    'status' => 'ok',
    'data' => [
        'assigned' => $success_count,
        'total' => count($assignments),
        'errors' => $errors
    ]
]);
