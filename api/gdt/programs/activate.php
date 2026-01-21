<?php
/**
 * GDT Programs - Activate API
 * 
 * POST /api/gdt/programs/activate.php
 * 
 * Activates a PROPOSED or MODELING program, making it live.
 * Supersedes any other active programs for the same element.
 * 
 * Request body (JSON):
 * {
 *   "program_id": 1,           // Required: program to activate
 *   "activated_by": "username" // Optional: user activating
 * }
 * 
 * Response:
 * {
 *   "status": "ok",
 *   "message": "Program activated",
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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond_json(405, [
        'status' => 'error',
        'message' => 'Method not allowed. Use POST.'
    ]);
}

$payload = read_request_payload();
$conn_tmi = get_conn_tmi();

// ============================================================================
// Validate
// ============================================================================

$program_id = isset($payload['program_id']) ? (int)$payload['program_id'] : 0;
$activated_by = isset($payload['activated_by']) ? trim($payload['activated_by']) : null;

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

// ============================================================================
// Call Stored Procedure
// ============================================================================

$sql = "EXEC dbo.sp_TMI_ActivateProgram @program_id = ?, @activated_by = ?";
$stmt = sqlsrv_query($conn_tmi, $sql, [$program_id, $activated_by]);

if ($stmt === false) {
    $errors = sqlsrv_errors();
    
    // Check for specific error messages from the SP
    $error_msg = 'Failed to activate program';
    if ($errors) {
        foreach ($errors as $e) {
            if (isset($e['message']) && strpos($e['message'], 'cannot be activated') !== false) {
                $error_msg = $e['message'];
                break;
            }
        }
    }
    
    respond_json(400, [
        'status' => 'error',
        'message' => $error_msg,
        'errors' => $errors
    ]);
}

sqlsrv_free_stmt($stmt);

// ============================================================================
// Fetch Updated Program
// ============================================================================

$program = get_program($conn_tmi, $program_id);

respond_json(200, [
    'status' => 'ok',
    'message' => 'Program activated',
    'data' => [
        'program_id' => $program_id,
        'program' => $program
    ]
]);
