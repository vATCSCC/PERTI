<?php
/**
 * GS Get API
 * 
 * GET /api/tmi/gs/get.php?program_id=1
 * 
 * Gets a single Ground Stop program by ID.
 * 
 * UPDATED: 2026-01-26 - Now uses VATSIM_TMI.tmi_programs
 * 
 * Query parameters:
 * - program_id: Required - program to retrieve
 * - include_flights: Optional - include affected flights (default: 0)
 * - include_events: Optional - include event log (default: 0)
 * 
 * Response:
 * {
 *   "status": "ok",
 *   "message": "Program retrieved",
 *   "data": {
 *     "program": { ... },
 *     "flights": [ ... ],  // if include_flights=1
 *     "events": [ ... ]    // if include_events=1
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

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'])) {
    respond_json(405, [
        'status' => 'error',
        'message' => 'Method not allowed. Use GET or POST.'
    ]);
}

$payload = read_request_payload();
$conn_tmi = get_tmi_conn();  // Use TMI for program data

// Get parameters
$program_id = isset($payload['program_id']) ? (int)$payload['program_id'] : 0;
$include_flights = isset($payload['include_flights']) ? (bool)$payload['include_flights'] : false;
$include_events = isset($payload['include_events']) ? (bool)$payload['include_events'] : false;

if ($program_id <= 0) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'program_id is required and must be a positive integer.'
    ]);
}

// Fetch program from TMI
$program_result = fetch_one($conn_tmi, "SELECT * FROM dbo.tmi_programs WHERE program_id = ?", [$program_id]);

if (!$program_result['success'] || !$program_result['data']) {
    respond_json(404, [
        'status' => 'error',
        'message' => 'Program not found.'
    ]);
}

$program = $program_result['data'];

$response_data = [
    'program' => $program
];

// Optionally include flights from flight control table
if ($include_flights) {
    $flights_sql = "
        SELECT * FROM dbo.tmi_flight_control 
        WHERE program_id = ? 
        ORDER BY cta_utc ASC, callsign ASC
    ";
    $flights_result = fetch_all($conn_tmi, $flights_sql, [$program_id]);
    $response_data['flights'] = $flights_result['success'] ? $flights_result['data'] : [];
}

// Optionally include events from TMI events table
if ($include_events) {
    $events_sql = "SELECT * FROM dbo.tmi_events WHERE program_id = ? ORDER BY performed_utc DESC";
    $events_result = fetch_all($conn_tmi, $events_sql, [$program_id]);
    $response_data['events'] = $events_result['success'] ? $events_result['data'] : [];
}

$response_data['server_utc'] = get_server_utc($conn_tmi);

respond_json(200, [
    'status' => 'ok',
    'message' => 'Program retrieved',
    'data' => $response_data
]);
