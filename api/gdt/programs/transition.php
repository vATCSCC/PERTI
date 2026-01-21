<?php
/**
 * GDT Programs - Transition API
 * 
 * POST /api/gdt/programs/transition.php
 * 
 * Transitions a Ground Stop to a GDP program. The GS is marked COMPLETED
 * and a new GDP is created with slots starting at the GS end time.
 * 
 * Request body (JSON):
 * {
 *   "gs_program_id": 1,                 // Required: GS program to transition from
 *   "gdp_type": "GDP-DAS",              // Optional: GDP-DAS (default), GDP-GAAP, GDP-UDP
 *   "gdp_end_utc": "2026-01-21T20:00",  // Required: GDP end time
 *   "program_rate": 30,                 // Required: arrivals per hour
 *   "reserve_rate": 5,                  // Optional: reserved slots per hour (GAAP/UDP)
 *   "delay_limit_min": 180,             // Optional: max delay cap
 *   "transitioned_by": "username"       // Optional: user performing transition
 * }
 * 
 * Response:
 * {
 *   "status": "ok",
 *   "message": "Transition complete",
 *   "data": {
 *     "gs_program_id": 1,
 *     "gdp_program_id": 2,
 *     "gs_program": { ... completed GS ... },
 *     "gdp_program": { ... new GDP ... }
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

$gs_program_id = isset($payload['gs_program_id']) ? (int)$payload['gs_program_id'] : 0;
$gdp_type = isset($payload['gdp_type']) ? strtoupper(trim($payload['gdp_type'])) : 'GDP-DAS';
$gdp_end_utc = isset($payload['gdp_end_utc']) ? parse_utc_datetime($payload['gdp_end_utc']) : null;
$program_rate = isset($payload['program_rate']) ? (int)$payload['program_rate'] : 0;
$reserve_rate = isset($payload['reserve_rate']) ? (int)$payload['reserve_rate'] : null;
$delay_limit_min = isset($payload['delay_limit_min']) ? (int)$payload['delay_limit_min'] : 180;
$transitioned_by = isset($payload['transitioned_by']) ? trim($payload['transitioned_by']) : null;

if ($gs_program_id <= 0) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'gs_program_id is required.'
    ]);
}

if ($gdp_end_utc === null) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'gdp_end_utc is required.'
    ]);
}

if ($program_rate <= 0) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'program_rate is required and must be positive.'
    ]);
}

// Validate GDP type
if (!in_array($gdp_type, ['GDP-DAS', 'GDP-GAAP', 'GDP-UDP'])) {
    respond_json(400, [
        'status' => 'error',
        'message' => "Invalid gdp_type: {$gdp_type}. Must be GDP-DAS, GDP-GAAP, or GDP-UDP."
    ]);
}

// Check GS program exists and is a GS
$gs_program = get_program($conn_tmi, $gs_program_id);

if ($gs_program === null) {
    respond_json(404, [
        'status' => 'error',
        'message' => "GS program not found: {$gs_program_id}"
    ]);
}

if (($gs_program['program_type'] ?? '') !== 'GS') {
    respond_json(400, [
        'status' => 'error',
        'message' => "Program {$gs_program_id} is not a Ground Stop."
    ]);
}

// ============================================================================
// Call Stored Procedure
// ============================================================================

$sql = "
    DECLARE @gdp_program_id INT;
    EXEC dbo.sp_TMI_TransitionGStoGDP 
        @gs_program_id = ?,
        @gdp_type = ?,
        @gdp_end_utc = ?,
        @program_rate = ?,
        @reserve_rate = ?,
        @delay_limit_min = ?,
        @transitioned_by = ?,
        @gdp_program_id = @gdp_program_id OUTPUT;
    SELECT @gdp_program_id AS gdp_program_id;
";

$stmt = sqlsrv_query($conn_tmi, $sql, [
    $gs_program_id,
    $gdp_type,
    $gdp_end_utc,
    $program_rate,
    $reserve_rate,
    $delay_limit_min,
    $transitioned_by
]);

if ($stmt === false) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to transition GS to GDP',
        'errors' => sqlsrv_errors()
    ]);
}

$result_row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$gdp_program_id = ($result_row && isset($result_row['gdp_program_id'])) ? (int)$result_row['gdp_program_id'] : 0;
sqlsrv_free_stmt($stmt);

if ($gdp_program_id <= 0) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Transition executed but did not return GDP program ID'
    ]);
}

// ============================================================================
// Fetch Both Programs
// ============================================================================

$gs_program = get_program($conn_tmi, $gs_program_id);
$gdp_program = get_program($conn_tmi, $gdp_program_id);

respond_json(200, [
    'status' => 'ok',
    'message' => 'Transition complete',
    'data' => [
        'gs_program_id' => $gs_program_id,
        'gdp_program_id' => $gdp_program_id,
        'gs_program' => $gs_program,
        'gdp_program' => $gdp_program
    ]
]);
