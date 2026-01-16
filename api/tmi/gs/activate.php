<?php
/**
 * GS Activate API
 * 
 * POST /api/tmi/gs/activate.php
 * 
 * Activates a proposed Ground Stop (issues EDCTs).
 * Calls sp_GS_IssueEDCTs stored procedure.
 * 
 * Request body:
 * {
 *   "program_id": 1,              // Required: program to activate
 *   "activated_by": "username"    // Optional: user activating the program
 * }
 * 
 * Response:
 * {
 *   "status": "ok",
 *   "message": "Ground Stop activated",
 *   "data": {
 *     "program": { ... updated program ... },
 *     "controlled_flights": 7
 *   }
 * }
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('GS_API_INCLUDED', true);
require_once(__DIR__ . '/common.php');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond_json(405, [
        'status' => 'error',
        'message' => 'Method not allowed. Use POST.'
    ]);
}

$payload = read_request_payload();
$conn = get_adl_conn();

// Validate required fields
$program_id = isset($payload['program_id']) ? (int)$payload['program_id'] : 0;
$activated_by = isset($payload['activated_by']) ? trim($payload['activated_by']) : null;

if ($program_id <= 0) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'program_id is required and must be a positive integer.'
    ]);
}

// Verify program exists and is in PROPOSED state
$check_result = fetch_one($conn, 
    "SELECT program_id, status, program_type FROM dbo.ntml WHERE program_id = ?", 
    [$program_id]
);

if (!$check_result['success'] || !$check_result['data']) {
    respond_json(404, [
        'status' => 'error',
        'message' => 'Program not found.'
    ]);
}

$current = $check_result['data'];

if ($current['program_type'] !== 'GS') {
    respond_json(400, [
        'status' => 'error',
        'message' => "This endpoint is for Ground Stops only. Program type is: {$current['program_type']}"
    ]);
}

if ($current['status'] !== 'PROPOSED') {
    respond_json(400, [
        'status' => 'error',
        'message' => "Program must be in PROPOSED state to activate. Current status: {$current['status']}"
    ]);
}

// Call the stored procedure
$sql = "EXEC dbo.sp_GS_IssueEDCTs @program_id = ?, @activated_by = ?";
$params = [$program_id, $activated_by];

$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt === false) {
    $errors = sqlsrv_errors();
    $error_msg = 'Failed to activate Ground Stop';
    if ($errors && isset($errors[0]['message'])) {
        $error_msg = $errors[0]['message'];
    }
    respond_json(500, [
        'status' => 'error',
        'message' => $error_msg,
        'errors' => $errors
    ]);
}
sqlsrv_free_stmt($stmt);

// Fetch updated program
$program_result = fetch_one($conn, "SELECT * FROM dbo.ntml WHERE program_id = ?", [$program_id]);

$program = $program_result['success'] ? $program_result['data'] : null;

respond_json(200, [
    'status' => 'ok',
    'message' => 'Ground Stop activated',
    'data' => [
        'program' => $program,
        'controlled_flights' => (int)($program['controlled_flights'] ?? 0),
        'server_utc' => get_server_utc($conn)
    ]
]);
