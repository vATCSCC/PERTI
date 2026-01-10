<?php
/**
 * GS Model API
 * 
 * POST /api/tmi/gs/model.php
 * 
 * Models a Ground Stop by identifying affected flights.
 * Calls sp_GS_Model stored procedure.
 * 
 * Request body:
 * {
 *   "program_id": 1,                     // Required: program to model
 *   "dep_facilities": "ZNY ZDC ZBW ZOB", // Required: space-delimited ARTCCs (from tier expansion)
 *   "performed_by": "username"           // Optional: user performing the action
 * }
 * 
 * Response:
 * {
 *   "status": "ok",
 *   "message": "Ground Stop modeled: X controlled, Y exempt",
 *   "data": {
 *     "program": { ... updated program with metrics ... },
 *     "flights": [ ... affected flights ... ],
 *     "summary": {
 *       "total_flights": 10,
 *       "controlled": 7,
 *       "exempt": 3,
 *       "airborne": 2
 *     }
 *   }
 * }
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('GS_API_INCLUDED', true);
require_once(__DIR__ . '/common.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(405, [
        'status' => 'error',
        'message' => 'Method not allowed. Use POST.'
    ]);
}

$payload = read_request_payload();
$conn = get_adl_conn();

// Validate required fields
$program_id = isset($payload['program_id']) ? (int)$payload['program_id'] : 0;
$dep_facilities = isset($payload['dep_facilities']) ? trim($payload['dep_facilities']) : '';
$performed_by = isset($payload['performed_by']) ? trim($payload['performed_by']) : null;

if ($program_id <= 0) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'program_id is required and must be a positive integer.'
    ]);
}

if ($dep_facilities === '') {
    respond_json(400, [
        'status' => 'error',
        'message' => 'dep_facilities is required (space-delimited ARTCC codes).'
    ]);
}

// Call the stored procedure
$sql = "EXEC dbo.sp_GS_Model @program_id = ?, @dep_facilities = ?, @performed_by = ?";
$params = [$program_id, $dep_facilities, $performed_by];

$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt === false) {
    $errors = sqlsrv_errors();
    // Check if it's a user error (program not found, etc.)
    $error_msg = 'Failed to model Ground Stop';
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

if (!$program_result['success'] || !$program_result['data']) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Program not found after modeling'
    ]);
}

$program = $program_result['data'];

// Fetch affected flights using the stored procedure
$flights_sql = "EXEC dbo.sp_GS_GetFlights @program_id = ?, @include_exempt = 1, @include_airborne = 1";
$flights_result = fetch_all($conn, $flights_sql, [$program_id]);

$flights = $flights_result['success'] ? $flights_result['data'] : [];

// Build summary
$summary = [
    'total_flights' => (int)($program['total_flights'] ?? 0),
    'controlled' => (int)($program['controlled_flights'] ?? 0),
    'exempt' => (int)($program['exempt_flights'] ?? 0),
    'airborne' => (int)($program['airborne_flights'] ?? 0)
];

respond_json(200, [
    'status' => 'ok',
    'message' => "Ground Stop modeled: {$summary['controlled']} controlled, {$summary['exempt']} exempt",
    'data' => [
        'program' => $program,
        'flights' => $flights,
        'summary' => $summary,
        'server_utc' => get_server_utc($conn)
    ]
]);
