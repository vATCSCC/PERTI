<?php
/**
 * VATSWIM API v1 - CTP Event Sync Ingest Endpoint
 *
 * Creates/updates a CTP session and throughput configuration in PERTI
 * based on event data pushed from the CTP API. This enables the CTP API
 * to be the event creation point when needed (Option B in spec Section 3.3.2).
 *
 * POST /api/swim/v1/ingest/ctp_event.php
 *
 * Requires system-tier SWIM API key with CTP write authority.
 *
 * Expected payload:
 * {
 *   "event_id": "CTP2026W",
 *   "title": "Cross the Pond Westbound 2026",
 *   "date": "2026-10-19",
 *   "direction": "WESTBOUND",
 *   "departure_window_start": "2026-10-19T10:00:00Z",
 *   "departure_window_end": "2026-10-19T18:00:00Z",
 *   "constrained_firs": ["CZQX", "BIRD", "EGGX", "LPPO"],
 *   "slot_interval_min": 5,
 *   "max_slots_per_hour": null,
 *   "airports": [
 *     {"identifier": "EGLL", "max_acph": 38, "votes": 2513},
 *     {"identifier": "KJFK", "max_acph": 44, "votes": 2504}
 *   ],
 *   "route_segments": [
 *     {"identifier": "NATA", "group": "NAT", "route_string": "MALOT 5320N...", "max_acph": 25}
 *   ]
 * }
 *
 * @version 1.0.0
 * @since 2026-03-22
 * @see docs/superpowers/specs/2026-03-22-ctp-api-vatswim-integration.md Section 3.3.2
 */

require_once __DIR__ . '/../auth.php';

// Require authentication with write access
$auth = swim_init_auth(true, true);

// Validate source can write CTP data
if (!$auth->canWriteField('ctp')) {
    SwimResponse::error(
        'Source "' . $auth->getSourceId() . '" is not authorized to write CTP event data.',
        403,
        'INSUFFICIENT_PERMISSION'
    );
}

// Only accept POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    SwimResponse::error('Method not allowed. Use POST.', 405, 'METHOD_NOT_ALLOWED');
}

// Parse body
$body = swim_get_json_body();
if (!$body) {
    SwimResponse::error('Request body is required', 400, 'MISSING_BODY');
}

// ============================================================================
// Validate required fields
// ============================================================================

$event_id = trim($body['event_id'] ?? '');
$title = trim($body['title'] ?? '');
$direction = strtoupper(trim($body['direction'] ?? ''));
$dep_window_start = trim($body['departure_window_start'] ?? '');
$dep_window_end = trim($body['departure_window_end'] ?? '');

if ($event_id === '') {
    SwimResponse::error('event_id is required', 400, 'MISSING_PARAM');
}
if ($direction === '' || !in_array($direction, ['WESTBOUND', 'EASTBOUND', 'BOTH'])) {
    SwimResponse::error('direction must be WESTBOUND, EASTBOUND, or BOTH', 400, 'MISSING_PARAM');
}
if ($dep_window_start === '' || $dep_window_end === '') {
    SwimResponse::error('departure_window_start and departure_window_end are required', 400, 'MISSING_PARAM');
}

$window_start_ts = strtotime($dep_window_start);
$window_end_ts = strtotime($dep_window_end);
if ($window_start_ts === false || $window_end_ts === false) {
    SwimResponse::error('departure_window_start/end must be valid ISO 8601 timestamps', 400, 'INVALID_PARAM');
}
if ($window_end_ts <= $window_start_ts) {
    SwimResponse::error('departure_window_end must be after departure_window_start', 400, 'INVALID_PARAM');
}

$window_start_sql = gmdate('Y-m-d H:i:s', $window_start_ts);
$window_end_sql = gmdate('Y-m-d H:i:s', $window_end_ts);

$constrained_firs = isset($body['constrained_firs']) && is_array($body['constrained_firs'])
    ? json_encode(array_map('strtoupper', $body['constrained_firs']))
    : null;

$slot_interval_min = isset($body['slot_interval_min']) ? intval($body['slot_interval_min']) : 5;
if ($slot_interval_min < 1) $slot_interval_min = 5;

$max_slots_per_hour = isset($body['max_slots_per_hour']) ? intval($body['max_slots_per_hour']) : null;

// Session name: event_id is the unique identifier; title is display context
// Use event_id for session_name to ensure uniqueness across events
$session_name = $event_id;
if (strlen($session_name) > 64) {
    $session_name = substr($session_name, 0, 64);
}

$source = $auth->getSourceId() ?? 'ctp_api';

// ============================================================================
// Get TMI connection
// ============================================================================

$conn_tmi = get_conn_tmi();
if (!$conn_tmi) {
    SwimResponse::error('TMI database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

// ============================================================================
// Check if session already exists for this event_id
// ============================================================================

$existing_stmt = sqlsrv_query($conn_tmi,
    "SELECT session_id, status FROM dbo.ctp_sessions WHERE session_name = ?",
    [$session_name]
);

$existing = null;
if ($existing_stmt !== false) {
    $existing = sqlsrv_fetch_array($existing_stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($existing_stmt);
}

$session_id = null;
$action = 'created';

if ($existing) {
    // Update existing session
    $session_id = (int)$existing['session_id'];
    $action = 'updated';

    // Only update if still in DRAFT or ACTIVE state
    if (!in_array($existing['status'], ['DRAFT', 'ACTIVE'])) {
        SwimResponse::error(
            "Session '{$session_name}' is {$existing['status']} and cannot be updated",
            409,
            'SESSION_NOT_MODIFIABLE'
        );
    }

    $update_stmt = sqlsrv_query($conn_tmi,
        "UPDATE dbo.ctp_sessions SET
            direction = ?,
            constrained_firs = ?,
            constraint_window_start = ?,
            constraint_window_end = ?,
            slot_interval_min = ?,
            max_slots_per_hour = ?,
            updated_at = SYSUTCDATETIME()
         WHERE session_id = ?",
        [$direction, $constrained_firs, $window_start_sql, $window_end_sql,
         $slot_interval_min, $max_slots_per_hour, $session_id]
    );

    if ($update_stmt === false) {
        $errors = sqlsrv_errors();
        SwimResponse::error('Failed to update session: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
    }
    sqlsrv_free_stmt($update_stmt);
} else {
    // Create new session
    $insert_sql = "
        INSERT INTO dbo.ctp_sessions (
            session_name, direction, constrained_firs,
            constraint_window_start, constraint_window_end,
            slot_interval_min, max_slots_per_hour,
            status, created_by, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'DRAFT', ?, SYSUTCDATETIME(), SYSUTCDATETIME());
        SELECT SCOPE_IDENTITY() AS session_id;
    ";

    $insert_stmt = sqlsrv_query($conn_tmi, $insert_sql, [
        $session_name, $direction, $constrained_firs,
        $window_start_sql, $window_end_sql,
        $slot_interval_min, $max_slots_per_hour,
        $source
    ]);

    if ($insert_stmt === false) {
        $errors = sqlsrv_errors();
        SwimResponse::error('Failed to create session: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
    }

    sqlsrv_next_result($insert_stmt);
    $row = sqlsrv_fetch_array($insert_stmt, SQLSRV_FETCH_ASSOC);
    $session_id = $row ? (int)$row['session_id'] : null;
    sqlsrv_free_stmt($insert_stmt);

    if (!$session_id) {
        SwimResponse::error('Failed to retrieve new session ID', 500, 'DB_ERROR');
    }
}

// ============================================================================
// Sync throughput configs (airports + route_segments → ctp_track_throughput_config)
// ============================================================================

$throughput_created = 0;
$throughput_updated = 0;

$airports = isset($body['airports']) && is_array($body['airports']) ? $body['airports'] : [];
$route_segments = isset($body['route_segments']) && is_array($body['route_segments']) ? $body['route_segments'] : [];

// Process airports as throughput configs (origin/destination capacity constraints)
foreach ($airports as $apt) {
    $identifier = strtoupper(trim($apt['identifier'] ?? ''));
    $max_acph = isset($apt['max_acph']) ? intval($apt['max_acph']) : 0;
    if ($identifier === '' || $max_acph <= 0) continue;

    $label = "Airport: {$identifier}";
    $origins_json = json_encode([$identifier]);
    $destinations_json = json_encode([$identifier]);

    $tc_result = upsertThroughputConfig(
        $conn_tmi, $session_id, $label, null, $origins_json, $destinations_json, $max_acph, 50, $source
    );
    if ($tc_result === 'created') $throughput_created++;
    elseif ($tc_result === 'updated') $throughput_updated++;
}

// Process route segments as throughput configs (track/route capacity constraints)
foreach ($route_segments as $seg) {
    $identifier = strtoupper(trim($seg['identifier'] ?? ''));
    $group = strtoupper(trim($seg['group'] ?? ''));
    $max_acph = isset($seg['max_acph']) ? intval($seg['max_acph']) : 0;
    if ($identifier === '' || $max_acph <= 0) continue;

    $label = ($group ? "{$group}: " : '') . $identifier;
    $tracks_json = json_encode([$identifier]);

    $tc_result = upsertThroughputConfig(
        $conn_tmi, $session_id, $label, $tracks_json, null, null, $max_acph, 50, $source
    );
    if ($tc_result === 'created') $throughput_created++;
    elseif ($tc_result === 'updated') $throughput_updated++;
}

// ============================================================================
// Audit log
// ============================================================================

$audit_json = json_encode([
    'event_id'       => $event_id,
    'action'         => $action,
    'direction'      => $direction,
    'window'         => [$dep_window_start, $dep_window_end],
    'airports'       => count($airports),
    'route_segments' => count($route_segments),
    'throughput'     => ['created' => $throughput_created, 'updated' => $throughput_updated],
], JSON_UNESCAPED_UNICODE);

$audit_stmt = sqlsrv_query($conn_tmi,
    "INSERT INTO dbo.ctp_audit_log (session_id, ctp_control_id, action_type, segment, action_detail_json, performed_by)
     VALUES (?, NULL, 'EVENT_SYNC', 'GLOBAL', ?, ?)",
    [$session_id, $audit_json, $source]
);
if ($audit_stmt === false) {
    error_log("CTP event sync: Failed to write audit log for session {$session_id}");
} elseif ($audit_stmt) {
    sqlsrv_free_stmt($audit_stmt);
}

// ============================================================================
// WebSocket event push
// ============================================================================

$events = [[
    'type' => 'ctp.session.updated',
    'data' => [
        'session_id'   => $session_id,
        'session_name' => $session_name,
        'action'       => $action,
        'source'       => $source,
    ],
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
    @rename($tempFile, $eventFile);
}

// ============================================================================
// Response
// ============================================================================

SwimResponse::success([
    'session_id'         => $session_id,
    'session_name'       => $session_name,
    'action'             => $action,
    'status'             => $action === 'created' ? 'DRAFT' : $existing['status'],
    'throughput_configs'  => [
        'created' => $throughput_created,
        'updated' => $throughput_updated,
    ],
]);


// ============================================================================
// Helper Functions
// ============================================================================

/**
 * Upsert a throughput config by session_id + config_label
 *
 * @return string 'created', 'updated', or 'skipped'
 */
function upsertThroughputConfig($conn_tmi, $session_id, $label, $tracks_json, $origins_json, $destinations_json, $max_acph, $priority, $source) {
    // Check if exists
    $check_stmt = sqlsrv_query($conn_tmi,
        "SELECT config_id, max_acph FROM dbo.ctp_track_throughput_config
         WHERE session_id = ? AND config_label = ?",
        [$session_id, $label]
    );

    if ($check_stmt === false) return 'skipped';

    $existing = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($check_stmt);

    if ($existing) {
        // Update if max_acph changed
        if ((int)$existing['max_acph'] !== $max_acph) {
            $upd = sqlsrv_query($conn_tmi,
                "UPDATE dbo.ctp_track_throughput_config SET
                    tracks_json = ?, origins_json = ?, destinations_json = ?,
                    max_acph = ?, updated_at = SYSUTCDATETIME()
                 WHERE config_id = ?",
                [$tracks_json, $origins_json, $destinations_json, $max_acph, (int)$existing['config_id']]
            );
            if ($upd !== false) sqlsrv_free_stmt($upd);
            return 'updated';
        }
        return 'skipped';
    }

    // Insert new
    $ins = sqlsrv_query($conn_tmi,
        "INSERT INTO dbo.ctp_track_throughput_config (
            session_id, config_label, tracks_json, origins_json, destinations_json,
            max_acph, priority, is_active, created_by, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, SYSUTCDATETIME(), SYSUTCDATETIME())",
        [$session_id, $label, $tracks_json, $origins_json, $destinations_json,
         $max_acph, $priority, $source]
    );

    if ($ins !== false) {
        sqlsrv_free_stmt($ins);
        return 'created';
    }
    return 'skipped';
}
