<?php
/**
 * GS Flights API
 * 
 * GET /api/tmi/gs/flights.php?program_id=1
 * 
 * Gets flights affected by a Ground Stop.
 * Calls sp_GS_GetFlights stored procedure.
 * 
 * Query parameters:
 * - program_id: Required - program to get flights for
 * - include_exempt: Optional - include exempt flights (default: 1)
 * - include_airborne: Optional - include airborne flights (default: 1)
 * 
 * Response:
 * {
 *   "status": "ok",
 *   "message": "Flights retrieved",
 *   "data": {
 *     "program": { ... program info ... },
 *     "flights": [ ... flight list ... ],
 *     "summary": {
 *       "total": 10,
 *       "controlled": 7,
 *       "exempt": 3
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

// Allow GET or POST
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'])) {
    respond_json(405, [
        'status' => 'error',
        'message' => 'Method not allowed. Use GET or POST.'
    ]);
}

$payload = read_request_payload();
$conn = get_adl_conn();

// Get parameters
$program_id = isset($payload['program_id']) ? (int)$payload['program_id'] : 0;
$include_exempt = isset($payload['include_exempt']) ? (bool)$payload['include_exempt'] : true;
$include_airborne = isset($payload['include_airborne']) ? (bool)$payload['include_airborne'] : true;

if ($program_id <= 0) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'program_id is required and must be a positive integer.'
    ]);
}

// Fetch program info
$program_result = fetch_one($conn, "SELECT * FROM dbo.ntml WHERE program_id = ?", [$program_id]);

if (!$program_result['success'] || !$program_result['data']) {
    respond_json(404, [
        'status' => 'error',
        'message' => 'Program not found.'
    ]);
}

$program = $program_result['data'];

// Call the stored procedure to get flights
$sql = "EXEC dbo.sp_GS_GetFlights @program_id = ?, @include_exempt = ?, @include_airborne = ?";
$params = [$program_id, $include_exempt ? 1 : 0, $include_airborne ? 1 : 0];

$flights_result = fetch_all($conn, $sql, $params);

if (!$flights_result['success']) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to retrieve flights',
        'errors' => $flights_result['error']
    ]);
}

$flights = $flights_result['data'];

// Calculate summary
$controlled = 0;
$exempt = 0;
foreach ($flights as $f) {
    if (isset($f['gs_held']) && $f['gs_held']) {
        $controlled++;
    }
    if (isset($f['ctl_exempt']) && $f['ctl_exempt']) {
        $exempt++;
    }
}

respond_json(200, [
    'status' => 'ok',
    'message' => 'Flights retrieved',
    'data' => [
        'program' => $program,
        'flights' => $flights,
        'summary' => [
            'total' => count($flights),
            'controlled' => $controlled,
            'exempt' => $exempt
        ],
        'server_utc' => get_server_utc($conn)
    ]
]);
