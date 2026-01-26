<?php
/**
 * GDT Programs - Extend API
 * 
 * POST /api/gdt/programs/extend.php
 * 
 * Extends a program's end time and generates additional slots.
 * 
 * Request body (JSON):
 * {
 *   "program_id": 1,                    // Required: program to extend
 *   "new_end_utc": "2026-01-21T20:00",  // Required: new end time
 *   "extended_by": "username"           // Optional: user extending
 * }
 * 
 * Response:
 * {
 *   "status": "ok",
 *   "message": "Program extended",
 *   "data": {
 *     "program_id": 1,
 *     "new_slots_count": 30,
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
$conn_tmi = gdt_get_conn_tmi();

// ============================================================================
// Validate
// ============================================================================

$program_id = isset($payload['program_id']) ? (int)$payload['program_id'] : 0;
$new_end_utc = isset($payload['new_end_utc']) ? parse_utc_datetime($payload['new_end_utc']) : null;
$extended_by = isset($payload['extended_by']) ? trim($payload['extended_by']) : null;

if ($program_id <= 0) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'program_id is required.'
    ]);
}

if ($new_end_utc === null) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'new_end_utc is required and must be a valid datetime.'
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

// Validate new_end_utc is after current end_utc
$current_end = $program['end_utc'] ?? null;
if ($current_end) {
    $current_end_dt = new DateTime(datetime_to_iso($current_end));
    $new_end_dt = new DateTime($new_end_utc);
    if ($new_end_dt <= $current_end_dt) {
        respond_json(400, [
            'status' => 'error',
            'message' => 'new_end_utc must be after current end time.'
        ]);
    }
}

// ============================================================================
// Call Stored Procedure
// ============================================================================

$sql = "
    DECLARE @new_slots_count INT;
    EXEC dbo.sp_TMI_ExtendProgram 
        @program_id = ?, 
        @new_end_utc = ?, 
        @extended_by = ?,
        @new_slots_count = @new_slots_count OUTPUT;
    SELECT @new_slots_count AS new_slots_count;
";

$stmt = sqlsrv_query($conn_tmi, $sql, [$program_id, $new_end_utc, $extended_by]);

if ($stmt === false) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to extend program',
        'errors' => sqlsrv_errors()
    ]);
}

$result_row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$new_slots_count = ($result_row && isset($result_row['new_slots_count'])) ? (int)$result_row['new_slots_count'] : 0;
sqlsrv_free_stmt($stmt);

// ============================================================================
// Fetch Updated Program
// ============================================================================

$program = get_program($conn_tmi, $program_id);

respond_json(200, [
    'status' => 'ok',
    'message' => 'Program extended',
    'data' => [
        'program_id' => $program_id,
        'new_slots_count' => $new_slots_count,
        'program' => $program
    ]
]);
