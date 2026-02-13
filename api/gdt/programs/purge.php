<?php
/**
 * GDT Programs - Purge/Delete API
 *
 * POST /api/gdt/programs/purge.php
 *
 * Purges a program — cancels it and removes its flight list.
 * Works for any non-final status (ACTIVE, PROPOSED, MODELING, PENDING_COORD).
 * For PROPOSED/MODELING programs this is a lightweight delete (no advisory).
 *
 * Request body (JSON):
 * {
 *   "program_id": 1,                // Required: program to purge
 *   "purge_reason": "WX improved"   // Optional: reason for purge
 * }
 *
 * Response:
 * {
 *   "status": "ok",
 *   "message": "Program purged",
 *   "data": {
 *     "program_id": 1,
 *     "flights_removed": 42,
 *     "program": { ... updated program record ... }
 *   }
 * }
 *
 * @version 2.0.0
 * @date 2026-02-11
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('GDT_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');
$auth_cid = gdt_require_auth();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond_json(405, [
        'status' => 'error',
        'message' => 'Method not allowed. Use POST.'
    ]);
}

$payload = read_request_payload();
$conn_tmi = gdt_get_conn_tmi();

// ============================================================================
// Validate
// ============================================================================

$program_id = isset($payload['program_id']) ? (int)$payload['program_id'] : 0;
$purge_reason = isset($payload['purge_reason']) ? trim($payload['purge_reason']) : null;

if ($program_id <= 0) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'program_id is required.'
    ]);
}

$program = get_program($conn_tmi, $program_id);

if ($program === null) {
    respond_json(404, [
        'status' => 'error',
        'message' => "Program not found: {$program_id}"
    ]);
}

$status = strtoupper($program['status'] ?? '');
if (in_array($status, ['PURGED', 'COMPLETED', 'CANCELLED', 'TRANSITIONED'])) {
    respond_json(400, [
        'status' => 'error',
        'message' => "Program is already in final status: {$status}"
    ]);
}

// ============================================================================
// Purge: remove flight list + mark CANCELLED
// ============================================================================

sqlsrv_begin_transaction($conn_tmi);

// Count and remove flight list entries
$count_result = fetch_one($conn_tmi,
    "SELECT COUNT(*) AS cnt FROM dbo.tmi_flight_list WHERE program_id = ?",
    [$program_id]
);
$flights_removed = $count_result['data']['cnt'] ?? 0;

if ($flights_removed > 0) {
    $del = execute_query($conn_tmi,
        "DELETE FROM dbo.tmi_flight_list WHERE program_id = ?",
        [$program_id]
    );
    if (!$del['success']) {
        sqlsrv_rollback($conn_tmi);
        respond_json(500, [
            'status' => 'error',
            'message' => 'Failed to remove flight list',
            'errors' => $del['error']
        ]);
    }
}

// Release any held slots
execute_query($conn_tmi,
    "UPDATE dbo.tmi_slots SET slot_status = 'RELEASED', assigned_flight_uid = NULL, assigned_callsign = NULL, updated_at = SYSUTCDATETIME() WHERE program_id = ? AND slot_status IN ('ASSIGNED', 'HELD')",
    [$program_id]
);

// Mark program cancelled
$upd = execute_query($conn_tmi,
    "UPDATE dbo.tmi_programs SET status = 'CANCELLED', is_active = 0, is_proposed = 0, cancellation_reason = ?, cancelled_by = ?, cancelled_at = SYSUTCDATETIME(), updated_at = SYSUTCDATETIME() WHERE program_id = ?",
    [$purge_reason, $auth_cid, $program_id]
);

if (!$upd['success']) {
    sqlsrv_rollback($conn_tmi);
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to update program status',
        'errors' => $upd['error']
    ]);
}

sqlsrv_commit($conn_tmi);

// Log event
execute_query($conn_tmi,
    "INSERT INTO dbo.tmi_events (entity_type, entity_id, program_id, event_type, event_detail, source_type, actor_id, event_utc)
     VALUES ('PROGRAM', ?, ?, 'PURGED', ?, 'GDT', ?, SYSUTCDATETIME())",
    [$program_id, $program_id, "Program #{$program_id} purged" . ($purge_reason ? ": {$purge_reason}" : ''), $auth_cid]
);

// Fetch updated record
$program = get_program($conn_tmi, $program_id);

respond_json(200, [
    'status' => 'ok',
    'message' => 'Program purged',
    'data' => [
        'program_id' => $program_id,
        'flights_removed' => $flights_removed,
        'program' => $program
    ]
]);
