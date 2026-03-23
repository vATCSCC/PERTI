<?php
/**
 * VATSWIM API v1 - CTP Sessions Endpoint
 *
 * Read-only endpoint for CTP API consumers to retrieve session details,
 * throughput configurations, and flight statistics. Authenticated via
 * SWIM API key (read access sufficient, no write permission needed).
 *
 * GET /api/swim/v1/ctp/sessions.php
 *   - No params: list all ACTIVE/DRAFT/MONITORING sessions
 *   - ?session_id=1: get single session with full details
 *   - ?status=ACTIVE: filter by status
 *
 * GET /api/swim/v1/ctp/sessions.php?session_id=1
 *   Returns session + throughput configs + flight stats
 *
 * @version 1.0.0
 * @since 2026-03-22
 * @see docs/superpowers/specs/2026-03-22-ctp-api-vatswim-integration.md
 */

require_once __DIR__ . '/../auth.php';

// Read-only access — no write permission needed
$auth = swim_init_auth(true, false);

$session_id = swim_get_int_param('session_id', 0);
$status_filter = swim_get_param('status');

// Need TMI connection for ctp_sessions
$conn_tmi = get_conn_tmi();
if (!$conn_tmi) {
    SwimResponse::error('TMI database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

// ============================================================================
// Single session detail
// ============================================================================

if ($session_id > 0) {
    $stmt = sqlsrv_query($conn_tmi,
        "SELECT session_id, flow_event_id, program_id, session_name, direction,
                constrained_firs, constraint_window_start, constraint_window_end,
                slot_interval_min, max_slots_per_hour,
                validation_rules_json, managing_orgs, perspective_orgs_json,
                status, total_flights, slotted_flights, modified_flights, excluded_flights,
                created_by, created_at, updated_at
         FROM dbo.ctp_sessions WHERE session_id = ?",
        [$session_id]
    );

    if ($stmt === false) {
        SwimResponse::error('Database error', 500, 'DB_ERROR');
    }

    $session = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$session) {
        SwimResponse::error("Session {$session_id} not found", 404, 'NOT_FOUND');
    }

    // Fetch throughput configs for this session
    $tc_stmt = sqlsrv_query($conn_tmi,
        "SELECT config_id, config_label, tracks_json, origins_json, destinations_json,
                max_acph, priority, is_active, notes, created_at, updated_at
         FROM dbo.ctp_track_throughput_config
         WHERE session_id = ? AND is_active = 1
         ORDER BY priority ASC",
        [$session_id]
    );

    $throughput_configs = [];
    if ($tc_stmt !== false) {
        while ($tc = sqlsrv_fetch_array($tc_stmt, SQLSRV_FETCH_ASSOC)) {
            $throughput_configs[] = [
                'config_id'    => (int)$tc['config_id'],
                'label'        => $tc['config_label'],
                'tracks'       => $tc['tracks_json'] ? json_decode($tc['tracks_json'], true) : null,
                'origins'      => $tc['origins_json'] ? json_decode($tc['origins_json'], true) : null,
                'destinations' => $tc['destinations_json'] ? json_decode($tc['destinations_json'], true) : null,
                'max_acph'     => (int)$tc['max_acph'],
                'priority'     => (int)$tc['priority'],
                'notes'        => $tc['notes'],
            ];
        }
        sqlsrv_free_stmt($tc_stmt);
    }

    // Fetch active NAT track templates for this session
    $tracks_stmt = sqlsrv_query($conn_tmi,
        "SELECT template_id, template_name, segment, route_string,
                altitude_range, is_active
         FROM dbo.ctp_route_templates
         WHERE session_id = ? AND segment = 'OCEANIC' AND is_active = 1
         ORDER BY template_name",
        [$session_id]
    );

    $nat_tracks = [];
    if ($tracks_stmt !== false) {
        while ($tr = sqlsrv_fetch_array($tracks_stmt, SQLSRV_FETCH_ASSOC)) {
            $nat_tracks[] = [
                'template_id'    => (int)$tr['template_id'],
                'name'           => $tr['template_name'],
                'route_string'   => $tr['route_string'],
                'altitude_range' => $tr['altitude_range'],
            ];
        }
        sqlsrv_free_stmt($tracks_stmt);
    }

    $result = formatSession($session);
    $result['throughput_configs'] = $throughput_configs;
    $result['nat_tracks'] = $nat_tracks;

    SwimResponse::success($result);
}

// ============================================================================
// List sessions
// ============================================================================

$where_clauses = [];
$params = [];

if ($status_filter) {
    $valid_statuses = ['DRAFT', 'ACTIVE', 'MONITORING', 'COMPLETED', 'CANCELLED'];
    $status_upper = strtoupper(trim($status_filter));
    if (in_array($status_upper, $valid_statuses)) {
        $where_clauses[] = "status = ?";
        $params[] = $status_upper;
    } else {
        SwimResponse::error('Invalid status. Must be one of: ' . implode(', ', $valid_statuses), 400, 'INVALID_PARAMETER');
    }
} else {
    // Default: show active sessions (not completed/cancelled)
    $where_clauses[] = "status IN ('DRAFT', 'ACTIVE', 'MONITORING')";
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$stmt = sqlsrv_query($conn_tmi,
    "SELECT session_id, flow_event_id, program_id, session_name, direction,
            constrained_firs, constraint_window_start, constraint_window_end,
            slot_interval_min, max_slots_per_hour,
            managing_orgs, status,
            total_flights, slotted_flights, modified_flights, excluded_flights,
            created_by, created_at, updated_at
     FROM dbo.ctp_sessions
     {$where_sql}
     ORDER BY created_at DESC",
    $params
);

if ($stmt === false) {
    SwimResponse::error('Database error', 500, 'DB_ERROR');
}

$sessions = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $sessions[] = formatSession($row);
}
sqlsrv_free_stmt($stmt);

SwimResponse::success([
    'sessions' => $sessions,
    'count'    => count($sessions),
]);


// ============================================================================
// Helper Functions
// ============================================================================

function formatSession($row) {
    return [
        'session_id'              => (int)$row['session_id'],
        'flow_event_id'           => $row['flow_event_id'] !== null ? (int)$row['flow_event_id'] : null,
        'program_id'              => $row['program_id'] !== null ? (int)$row['program_id'] : null,
        'session_name'            => $row['session_name'],
        'direction'               => $row['direction'],
        'constrained_firs'        => $row['constrained_firs'] ? json_decode($row['constrained_firs'], true) : [],
        'constraint_window_start' => formatDT($row['constraint_window_start']),
        'constraint_window_end'   => formatDT($row['constraint_window_end']),
        'slot_interval_min'       => (int)$row['slot_interval_min'],
        'max_slots_per_hour'      => $row['max_slots_per_hour'] !== null ? (int)$row['max_slots_per_hour'] : null,
        'managing_orgs'           => isset($row['managing_orgs']) ? json_decode($row['managing_orgs'], true) : null,
        'status'                  => $row['status'],
        'stats' => [
            'total_flights'    => (int)$row['total_flights'],
            'slotted_flights'  => (int)$row['slotted_flights'],
            'modified_flights' => (int)$row['modified_flights'],
            'excluded_flights' => (int)$row['excluded_flights'],
        ],
        'created_by' => $row['created_by'] ?? null,
        'created_at' => formatDT($row['created_at']),
        'updated_at' => formatDT($row['updated_at'] ?? null),
    ];
}

function formatDT($dt) {
    if ($dt === null) return null;
    return ($dt instanceof DateTime) ? $dt->format('c') : $dt;
}
