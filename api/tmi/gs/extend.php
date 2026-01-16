<?php
/**
 * GS Extend API
 * 
 * POST /api/tmi/gs/extend.php
 * 
 * Extends the end time of a Ground Stop.
 * Calls sp_GS_Extend stored procedure.
 * 
 * Request body:
 * {
 *   "program_id": 1,                     // Required: program to extend
 *   "new_end_utc": "2026-01-10T19:00",   // Required: new end time (must be after current end)
 *   "extended_by": "username",           // Optional: user extending the program
 *   "comments": "Extended due to..."     // Optional: reason for extension
 * }
 * 
 * Response:
 * {
 *   "status": "ok",
 *   "message": "Ground Stop extended to 101900Z",
 *   "data": {
 *     "program": { ... updated program ... },
 *     "previous_end_utc": "...",
 *     "new_end_utc": "..."
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
$new_end_utc = isset($payload['new_end_utc']) ? parse_utc_datetime($payload['new_end_utc']) : null;
$extended_by = isset($payload['extended_by']) ? trim($payload['extended_by']) : null;
$comments = isset($payload['comments']) ? trim($payload['comments']) : null;

if ($program_id <= 0) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'program_id is required and must be a positive integer.'
    ]);
}

if ($new_end_utc === null) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'new_end_utc is required and must be a valid datetime.'
    ]);
}

// Get current program to capture previous end time
$check_result = fetch_one($conn, 
    "SELECT program_id, status, program_type, end_utc FROM dbo.ntml WHERE program_id = ?", 
    [$program_id]
);

if (!$check_result['success'] || !$check_result['data']) {
    respond_json(404, [
        'status' => 'error',
        'message' => 'Program not found.'
    ]);
}

$current = $check_result['data'];
$previous_end = $current['end_utc'];

if ($current['program_type'] !== 'GS') {
    respond_json(400, [
        'status' => 'error',
        'message' => "This endpoint is for Ground Stops only. Program type is: {$current['program_type']}"
    ]);
}

if (!in_array($current['status'], ['PROPOSED', 'ACTIVE'])) {
    respond_json(400, [
        'status' => 'error',
        'message' => "Can only extend PROPOSED or ACTIVE programs. Current status: {$current['status']}"
    ]);
}

// Call the stored procedure
$sql = "EXEC dbo.sp_GS_Extend @program_id = ?, @new_end_utc = ?, @extended_by = ?, @comments = ?";
$params = [$program_id, $new_end_utc, $extended_by, $comments];

$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt === false) {
    $errors = sqlsrv_errors();
    $error_msg = 'Failed to extend Ground Stop';
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

// Format times for message
$new_end_dt = new DateTime($new_end_utc);
$new_end_fmt = $new_end_dt->format('dHi') . 'Z';

respond_json(200, [
    'status' => 'ok',
    'message' => "Ground Stop extended to {$new_end_fmt}",
    'data' => [
        'program' => $program,
        'previous_end_utc' => $previous_end instanceof DateTimeInterface 
            ? $previous_end->format("Y-m-d\\TH:i:s\\Z") 
            : $previous_end,
        'new_end_utc' => $new_end_utc,
        'server_utc' => get_server_utc($conn)
    ]
]);
