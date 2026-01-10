<?php
/**
 * GS Create API
 * 
 * POST /api/tmi/gs/create.php
 * 
 * Creates a new Ground Stop program in PROPOSED state.
 * Calls sp_GS_Create stored procedure.
 * 
 * Request body:
 * {
 *   "ctl_element": "KJFK",           // Required: destination airport
 *   "start_utc": "2026-01-10T15:00", // Required: GS start time
 *   "end_utc": "2026-01-10T17:00",   // Required: GS end time
 *   "scope_type": "TIER",            // Optional: TIER, DISTANCE, MANUAL (default: TIER)
 *   "scope_tier": 1,                 // Optional: 1, 2, or 3 (default: 1)
 *   "scope_distance_nm": 400,        // Optional: for DISTANCE scope
 *   "exempt_airborne": true,         // Optional: exempt airborne flights (default: true)
 *   "exempt_within_min": 45,         // Optional: exempt flights departing within X min
 *   "flt_incl_carrier": "AAL UAL",   // Optional: carrier filter (space-delimited)
 *   "flt_incl_type": "ALL",          // Optional: ALL, JET, PROP
 *   "impacting_condition": "WEATHER",// Optional: WEATHER, VOLUME, RUNWAY, EQUIPMENT, OTHER
 *   "cause_text": "Low visibility",  // Optional: description of cause
 *   "comments": "...",               // Optional: additional comments
 *   "prob_extension": "MEDIUM",      // Optional: LOW, MEDIUM, HIGH
 *   "created_by": "username"         // Optional: user creating the program
 * }
 * 
 * Response:
 * {
 *   "status": "ok",
 *   "message": "Ground Stop created",
 *   "data": {
 *     "program_id": 1,
 *     "program": { ... full program record ... }
 *   }
 * }
 */

header('Content-Type: application/json; charset=utf-8');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('GS_API_INCLUDED', true);
require_once(__DIR__ . '/common.php');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(405, [
        'status' => 'error',
        'message' => 'Method not allowed. Use POST.'
    ]);
}

// Get request payload
$payload = read_request_payload();
$conn = get_adl_conn();

// Validate required fields
$ctl_element = isset($payload['ctl_element']) ? strtoupper(trim($payload['ctl_element'])) : '';
$start_utc = isset($payload['start_utc']) ? parse_utc_datetime($payload['start_utc']) : null;
$end_utc = isset($payload['end_utc']) ? parse_utc_datetime($payload['end_utc']) : null;

if ($ctl_element === '') {
    respond_json(400, [
        'status' => 'error',
        'message' => 'ctl_element (destination airport) is required.'
    ]);
}

if ($start_utc === null) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'start_utc is required and must be a valid datetime.'
    ]);
}

if ($end_utc === null) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'end_utc is required and must be a valid datetime.'
    ]);
}

// Optional fields with defaults
$scope_type = isset($payload['scope_type']) ? strtoupper(trim($payload['scope_type'])) : 'TIER';
$scope_tier = isset($payload['scope_tier']) ? (int)$payload['scope_tier'] : 1;
$scope_distance_nm = isset($payload['scope_distance_nm']) ? (int)$payload['scope_distance_nm'] : null;
$exempt_airborne = isset($payload['exempt_airborne']) ? (bool)$payload['exempt_airborne'] : true;
$exempt_within_min = isset($payload['exempt_within_min']) ? (int)$payload['exempt_within_min'] : 45;
$flt_incl_carrier = isset($payload['flt_incl_carrier']) ? trim($payload['flt_incl_carrier']) : null;
$flt_incl_type = isset($payload['flt_incl_type']) ? strtoupper(trim($payload['flt_incl_type'])) : 'ALL';
$impacting_condition = isset($payload['impacting_condition']) ? strtoupper(trim($payload['impacting_condition'])) : null;
$cause_text = isset($payload['cause_text']) ? trim($payload['cause_text']) : null;
$comments = isset($payload['comments']) ? trim($payload['comments']) : null;
$prob_extension = isset($payload['prob_extension']) ? strtoupper(trim($payload['prob_extension'])) : 'MEDIUM';
$created_by = isset($payload['created_by']) ? trim($payload['created_by']) : null;

// Build SQL to call stored procedure with OUTPUT parameter
$sql = "
    DECLARE @program_id INT;
    EXEC dbo.sp_GS_Create
        @ctl_element = ?,
        @start_utc = ?,
        @end_utc = ?,
        @scope_type = ?,
        @scope_tier = ?,
        @scope_distance_nm = ?,
        @exempt_airborne = ?,
        @exempt_within_min = ?,
        @flt_incl_carrier = ?,
        @flt_incl_type = ?,
        @impacting_condition = ?,
        @cause_text = ?,
        @comments = ?,
        @prob_extension = ?,
        @created_by = ?,
        @program_id = @program_id OUTPUT;
    SELECT @program_id AS program_id;
";

$params = [
    $ctl_element,
    $start_utc,
    $end_utc,
    $scope_type,
    $scope_tier,
    $scope_distance_nm,
    $exempt_airborne ? 1 : 0,
    $exempt_within_min,
    $flt_incl_carrier,
    $flt_incl_type,
    $impacting_condition,
    $cause_text,
    $comments,
    $prob_extension,
    $created_by
];

$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt === false) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to create Ground Stop',
        'errors' => sqlsrv_errors()
    ]);
}

// Get the output program_id
$program_id = null;
sqlsrv_next_result($stmt);
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
if ($row && isset($row['program_id'])) {
    $program_id = (int)$row['program_id'];
}
sqlsrv_free_stmt($stmt);

if ($program_id === null || $program_id <= 0) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Stored procedure executed but did not return a program_id'
    ]);
}

// Fetch the created program
$program_result = fetch_one($conn, "SELECT * FROM dbo.ntml WHERE program_id = ?", [$program_id]);

respond_json(200, [
    'status' => 'ok',
    'message' => 'Ground Stop created',
    'data' => [
        'program_id' => $program_id,
        'program' => $program_result['success'] ? $program_result['data'] : null
    ]
]);
