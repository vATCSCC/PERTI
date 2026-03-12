<?php
/**
 * CTP Sessions - Create API
 *
 * POST /api/ctp/sessions/create.php
 *
 * Creates a new CTP management session.
 *
 * Request body:
 * {
 *   "session_name": "CTP2026W-NON-EVENT",
 *   "direction": "WESTBOUND",
 *   "constrained_firs": ["CZQX","BIRD","EGGX","LPPO"],
 *   "constraint_window_start": "2026-10-25T12:00:00Z",
 *   "constraint_window_end": "2026-10-26T06:00:00Z",
 *   "flow_event_id": 1,
 *   "slot_interval_min": 5,
 *   "max_slots_per_hour": null,
 *   "validation_rules_json": {},
 *   "managing_orgs": ["VATCSCC","CANOC","ECFMP"],
 *   "perspective_orgs_json": {"NA":["DCC","CANOC"],"OCEANIC":["GANDER","SHANWICK"],"EU":["ECFMP"],"GLOBAL":["DCC","CANOC","ECFMP"]}
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
$conn = ctp_get_conn_tmi();
$payload = read_request_payload();

// Validate required fields
$session_name = isset($payload['session_name']) ? trim($payload['session_name']) : '';
$direction = isset($payload['direction']) ? strtoupper(trim($payload['direction'])) : '';
$window_start = isset($payload['constraint_window_start']) ? parse_utc_datetime($payload['constraint_window_start']) : null;
$window_end = isset($payload['constraint_window_end']) ? parse_utc_datetime($payload['constraint_window_end']) : null;

if ($session_name === '') {
    respond_json(400, ['status' => 'error', 'message' => 'session_name is required.']);
}
if (!in_array($direction, ['WESTBOUND', 'EASTBOUND', 'BOTH'])) {
    respond_json(400, ['status' => 'error', 'message' => 'direction must be WESTBOUND, EASTBOUND, or BOTH.']);
}
if (!$window_start || !$window_end) {
    respond_json(400, ['status' => 'error', 'message' => 'constraint_window_start and constraint_window_end are required.']);
}

// Optional fields
$flow_event_id = isset($payload['flow_event_id']) ? (int)$payload['flow_event_id'] : null;
$slot_interval = isset($payload['slot_interval_min']) ? max(1, (int)$payload['slot_interval_min']) : 5;
$max_slots = isset($payload['max_slots_per_hour']) && $payload['max_slots_per_hour'] !== null ? (int)$payload['max_slots_per_hour'] : null;

$constrained_firs = null;
if (isset($payload['constrained_firs'])) {
    $constrained_firs = is_array($payload['constrained_firs'])
        ? json_encode($payload['constrained_firs'])
        : $payload['constrained_firs'];
}

$validation_rules = null;
if (isset($payload['validation_rules_json'])) {
    $validation_rules = is_array($payload['validation_rules_json'])
        ? json_encode($payload['validation_rules_json'])
        : $payload['validation_rules_json'];
}

$managing_orgs = null;
if (isset($payload['managing_orgs'])) {
    $managing_orgs = is_array($payload['managing_orgs'])
        ? json_encode($payload['managing_orgs'])
        : $payload['managing_orgs'];
}

$perspective_orgs = null;
if (isset($payload['perspective_orgs_json'])) {
    $perspective_orgs = is_array($payload['perspective_orgs_json'])
        ? json_encode($payload['perspective_orgs_json'])
        : $payload['perspective_orgs_json'];
}

// Validate flow_event_id if provided
if ($flow_event_id !== null && $flow_event_id > 0) {
    $event_check = ctp_fetch_one($conn, "SELECT event_id FROM dbo.tmi_flow_events WHERE event_id = ?", [$flow_event_id]);
    if (!$event_check['success'] || !$event_check['data']) {
        respond_json(400, ['status' => 'error', 'message' => 'flow_event_id not found.']);
    }
} else {
    $flow_event_id = null;
}

// Insert session
$sql = "
    INSERT INTO dbo.ctp_sessions (
        flow_event_id, session_name, direction, constrained_firs,
        constraint_window_start, constraint_window_end,
        slot_interval_min, max_slots_per_hour,
        validation_rules_json, managing_orgs, perspective_orgs_json,
        status, created_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'DRAFT', ?);
    SELECT SCOPE_IDENTITY() AS session_id;
";

$params = [
    $flow_event_id,
    $session_name,
    $direction,
    $constrained_firs,
    $window_start,
    $window_end,
    $slot_interval,
    $max_slots,
    $validation_rules,
    $managing_orgs,
    $perspective_orgs,
    $cid
];

$stmt = sqlsrv_query($conn, $sql, $params);
if ($stmt === false) {
    respond_json(500, ['status' => 'error', 'message' => 'Failed to create session.', 'errors' => sqlsrv_errors()]);
}

// Move to next result set to get SCOPE_IDENTITY
sqlsrv_next_result($stmt);
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$new_id = $row ? (int)$row['session_id'] : null;
sqlsrv_free_stmt($stmt);

if (!$new_id) {
    respond_json(500, ['status' => 'error', 'message' => 'Session created but could not retrieve ID.']);
}

// Audit log
ctp_audit_log($conn, $new_id, null, 'SESSION_CREATE', [
    'session_name' => $session_name,
    'direction' => $direction,
    'constrained_firs' => $constrained_firs,
], $cid);

respond_json(201, [
    'status' => 'ok',
    'data' => [
        'session_id' => $new_id,
        'message' => 'Session created successfully.'
    ]
]);
