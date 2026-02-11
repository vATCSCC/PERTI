<?php
/**
 * GDT Programs - Cancel API
 *
 * POST /api/gdt/programs/cancel.php
 *
 * Cancels an active GS/GDP program. Skips coordination and publishes
 * cancellation advisory immediately with EDCT purge instructions.
 *
 * Request body (JSON):
 * {
 *   "program_id": 123,                        // Required: program to cancel
 *   "cancel_reason": "WEATHER_IMPROVEMENT",   // Required: reason code
 *   "cancel_notes": "Thunderstorms moved...", // Optional: additional notes
 *   "edct_action": "DISREGARD",               // DISREGARD, DISREGARD_AFTER, AFP_ACTIVE
 *   "edct_action_time": null,                 // Required if DISREGARD_AFTER
 *   "user_cid": "1234567",                    // Required: canceller CID
 *   "user_name": "John Doe"                   // Required: canceller name
 * }
 *
 * EDCT Actions:
 *   DISREGARD       - "DISREGARD EDCTS FOR DEST [airport]" (default)
 *   DISREGARD_AFTER - "DISREGARD EDCTS FOR DEST [airport] AFTER DD/HHMMZ"
 *   AFP_ACTIVE      - "FLIGHTS MAY RECEIVE NEW EDCTS DUE TO AN ACTIVE AFP"
 *
 * Response:
 * {
 *   "status": "ok",
 *   "message": "Program cancelled",
 *   "data": {
 *     "program_id": 123,
 *     "advisory_number": "ADVZY 003",
 *     "flights_purged": 145,
 *     "program": { ... }
 *   }
 * }
 *
 * @version 1.0.0
 * @date 2026-01-30
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('GDT_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');
$auth_cid = gdt_optional_auth();
require_once(__DIR__ . '/../../tmi/AdvisoryNumber.php');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond_json(405, [
        'status' => 'error',
        'message' => 'Method not allowed. Use POST.'
    ]);
}

$payload = read_request_payload();
$conn_tmi = gdt_get_conn_tmi();

// ============================================================================
// Validate Required Fields
// ============================================================================

$program_id = isset($payload['program_id']) ? (int)$payload['program_id'] : 0;
$cancel_reason = isset($payload['cancel_reason']) ? strtoupper(trim($payload['cancel_reason'])) : null;
$cancel_notes = isset($payload['cancel_notes']) ? trim($payload['cancel_notes']) : null;
$edct_action = isset($payload['edct_action']) ? strtoupper(trim($payload['edct_action'])) : 'DISREGARD';
$edct_action_time = isset($payload['edct_action_time']) ? trim($payload['edct_action_time']) : null;
$user_cid = isset($payload['user_cid']) ? trim($payload['user_cid']) : null;
$user_name = isset($payload['user_name']) ? trim($payload['user_name']) : 'Unknown';

if ($program_id <= 0) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'program_id is required.'
    ]);
}

if (empty($cancel_reason)) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'cancel_reason is required.'
    ]);
}

if (empty($user_cid)) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'user_cid is required.'
    ]);
}

// Validate EDCT action
$valid_edct_actions = ['DISREGARD', 'DISREGARD_AFTER', 'AFP_ACTIVE'];
if (!in_array($edct_action, $valid_edct_actions)) {
    respond_json(400, [
        'status' => 'error',
        'message' => "Invalid edct_action: {$edct_action}. Valid actions: " . implode(', ', $valid_edct_actions)
    ]);
}

// Validate DISREGARD_AFTER requires a time
if ($edct_action === 'DISREGARD_AFTER' && empty($edct_action_time)) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'edct_action_time is required when edct_action is DISREGARD_AFTER.'
    ]);
}

// Parse EDCT action time if provided
$edct_time_dt = null;
if ($edct_action_time) {
    try {
        $edct_time_dt = new DateTime($edct_action_time, new DateTimeZone('UTC'));
    } catch (Exception $e) {
        respond_json(400, [
            'status' => 'error',
            'message' => 'Invalid edct_action_time format.'
        ]);
    }
}

// Check program exists
$program = get_program($conn_tmi, $program_id);

if ($program === null) {
    respond_json(404, [
        'status' => 'error',
        'message' => "Program not found: {$program_id}"
    ]);
}

// Verify program is active (can be cancelled)
if (!$program['is_active']) {
    respond_json(400, [
        'status' => 'error',
        'message' => "Program {$program_id} is not active. Current status: {$program['status']}"
    ]);
}

// ============================================================================
// Get Next Advisory Number for Cancellation
// ============================================================================

$advNumHelper = new AdvisoryNumber($conn_tmi, 'sqlsrv');
$advisory_number = $advNumHelper->reserve();

// ============================================================================
// Generate Cancellation Advisory (vATCSCC format)
// ============================================================================

$ctl_element = $program['ctl_element'] ?? 'UNKN';
$element_type = $program['element_type'] ?? 'APT';
$artcc = $program['artcc'] ?? $program['arr_center'] ?? 'ZZZ';
$program_type = $program['program_type'] ?? 'GS';
$is_gdp = strpos($program_type, 'GDP') !== false;
$type_name = $is_gdp ? 'GROUND DELAY PROGRAM' : 'GROUND STOP';

$now = new DateTime('now', new DateTimeZone('UTC'));
$adl_time = $now->format('Hi') . 'Z';
$header_date = $now->format('m/d/Y');
$cancel_time_str = $now->format('d/Hi') . 'Z';
$footer_timestamp = $now->format('y/m/d H:i');

// Extract advisory number for header
$adv_num = preg_replace('/[^0-9]/', '', $advisory_number) ?: '001';
$adv_num = str_pad($adv_num, 3, '0', STR_PAD_LEFT);

// Build EDCT purge line
$edct_line = '';
switch ($edct_action) {
    case 'DISREGARD':
        $edct_line = "DISREGARD EDCTS FOR DEST {$ctl_element}";
        break;
    case 'DISREGARD_AFTER':
        $after_time_str = $edct_time_dt->format('d/Hi') . 'Z';
        $edct_line = "DISREGARD EDCTS FOR DEST {$ctl_element} AFTER {$after_time_str}";
        break;
    case 'AFP_ACTIVE':
        $edct_line = "FLIGHTS MAY RECEIVE NEW EDCTS DUE TO AN ACTIVE AFP";
        break;
}

$advisory_text = "vATCSCC ADVZY {$adv_num} {$ctl_element}/{$artcc} {$header_date} CDM {$type_name} CNX
CTL ELEMENT: {$ctl_element}
ELEMENT TYPE: {$element_type}
ADL TIME: {$adl_time}
CANCEL TIME: {$cancel_time_str}
CANCEL REASON: " . str_replace('_', ' ', $cancel_reason) . "
{$edct_line}
" . ($cancel_notes ? "COMMENTS: {$cancel_notes}" : "COMMENTS: NONE") . "

{$footer_timestamp}";

// ============================================================================
// Purge Flight List for This Program
// ============================================================================

// Count flights before purge
$count_result = fetch_one($conn_tmi,
    "SELECT COUNT(*) AS cnt FROM dbo.tmi_flight_list WHERE program_id = ?",
    [$program_id]
);
$flights_purged = $count_result['data']['cnt'] ?? 0;

// Delete flight list entries for this program only
$purge_sql = "DELETE FROM dbo.tmi_flight_list WHERE program_id = ?";
execute_query($conn_tmi, $purge_sql, [$program_id]);

// ============================================================================
// Update Program Status
// ============================================================================

$update_sql = "UPDATE dbo.tmi_programs SET
                   status = 'CANCELLED',
                   is_active = 0,
                   cancel_advisory_num = ?,
                   cancellation_reason = ?,
                   cancellation_edct_action = ?,
                   cancellation_edct_time = ?,
                   cancellation_notes = ?,
                   cancelled_by = ?,
                   cancelled_at = SYSUTCDATETIME(),
                   updated_at = SYSUTCDATETIME()
               WHERE program_id = ?";

$update_params = [
    $advisory_number,
    $cancel_reason,
    $edct_action,
    $edct_time_dt ? $edct_time_dt->format('Y-m-d H:i:s') : null,
    $cancel_notes,
    $user_cid,
    $program_id
];

execute_query($conn_tmi, $update_sql, $update_params);

// ============================================================================
// Log the Action
// ============================================================================

log_coordination_action($conn_tmi, $program_id, $program['proposal_id'] ?? null, 'PROGRAM_CANCELLED', [
    'advisory_number' => $advisory_number,
    'cancel_reason' => $cancel_reason,
    'edct_action' => $edct_action,
    'flights_purged' => $flights_purged,
    'user_cid' => $user_cid,
    'user_name' => $user_name
], $advisory_number, 'CANCEL');

// Get updated program
$program = get_program($conn_tmi, $program_id);

respond_json(200, [
    'status' => 'ok',
    'message' => 'Program cancelled',
    'data' => [
        'program_id' => $program_id,
        'advisory_number' => $advisory_number,
        'advisory_text' => $advisory_text,
        'cancel_reason' => $cancel_reason,
        'edct_action' => $edct_action,
        'flights_purged' => $flights_purged,
        'program' => $program
    ]
]);

// ============================================================================
// Helper Functions
// ============================================================================

/**
 * Log coordination action
 */
function log_coordination_action($conn, $program_id, $proposal_id, $action_type, $data = [], $advisory_number = null, $advisory_type = null) {
    $sql = "INSERT INTO dbo.tmi_program_coordination_log (
                program_id, proposal_id, action_type, action_data_json,
                advisory_number, advisory_type,
                performed_by, performed_by_name
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

    $params = [
        $program_id,
        $proposal_id,
        $action_type,
        json_encode($data),
        $advisory_number,
        $advisory_type,
        $data['user_cid'] ?? null,
        $data['user_name'] ?? null
    ];

    execute_query($conn, $sql, $params);
}
