<?php
/**
 * GDT Programs - Purge API
 * 
 * POST /api/gdt/programs/purge.php
 * 
 * Cancels/purges an active program and releases all held flights.
 * 
 * Request body (JSON):
 * {
 *   "program_id": 1,                // Required: program to purge
 *   "purge_reason": "WX improved",  // Optional: reason for purge
 *   "purged_by": "username"         // Optional: user purging
 * }
 * 
 * Response:
 * {
 *   "status": "ok",
 *   "message": "Program purged",
 *   "data": {
 *     "program_id": 1,
 *     "program": { ... updated program record ... }
 *   }
 * }
 * 
 * @version 1.0.0
 * @date 2026-01-21
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('GDT_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');
$auth_cid = gdt_optional_auth();

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
$purged_by = $auth_cid ?: (isset($payload['purged_by']) ? trim($payload['purged_by']) : 'anonymous');

if ($program_id <= 0) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'program_id is required.'
    ]);
}

// Check program exists
$program = get_program($conn_tmi, $program_id);

if ($program === null) {
    respond_json(404, [
        'status' => 'error',
        'message' => "Program not found: {$program_id}"
    ]);
}

// Can only purge active or proposed programs
$status = $program['status'] ?? '';
if (in_array($status, ['PURGED', 'COMPLETED', 'CANCELLED'])) {
    respond_json(400, [
        'status' => 'error',
        'message' => "Program is already in final status: {$status}"
    ]);
}

// ============================================================================
// Call Stored Procedure
// ============================================================================

$sql = "EXEC dbo.sp_TMI_PurgeProgram @program_id = ?, @purge_reason = ?, @purged_by = ?";
$stmt = sqlsrv_query($conn_tmi, $sql, [$program_id, $purge_reason, $purged_by]);

if ($stmt === false) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to purge program',
        'errors' => sqlsrv_errors()
    ]);
}

sqlsrv_free_stmt($stmt);

// ============================================================================
// Fetch Updated Program
// ============================================================================

$program = get_program($conn_tmi, $program_id);

respond_json(200, [
    'status' => 'ok',
    'message' => 'Program purged',
    'data' => [
        'program_id' => $program_id,
        'program' => $program
    ]
]);
