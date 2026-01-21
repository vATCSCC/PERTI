<?php
/**
 * GDT Programs - Create API
 * 
 * POST /api/gdt/programs/create.php
 * 
 * Creates a new GS/GDP/AFP program in PROPOSED status.
 * Calls sp_TMI_CreateProgram stored procedure in VATSIM_TMI database.
 * 
 * Request body (JSON):
 * {
 *   "ctl_element": "KJFK",              // Required: destination airport/element
 *   "program_type": "GS",               // Required: GS, GDP-DAS, GDP-GAAP, GDP-UDP, AFP
 *   "start_utc": "2026-01-21T15:00:00", // Required: program start time
 *   "end_utc": "2026-01-21T18:00:00",   // Required: program end time
 *   "element_type": "APT",              // Optional: APT (default), CTR, FCA
 *   "program_rate": 30,                 // Optional: arrivals per hour (GDP/AFP only)
 *   "reserve_rate": 5,                  // Optional: reserved slots per hour (GAAP/UDP)
 *   "delay_limit_min": 180,             // Optional: max delay cap in minutes
 *   "scope_json": {...},                // Optional: scope/filter configuration
 *   "impacting_condition": "WEATHER",   // Optional: WEATHER, VOLUME, RUNWAY, EQUIPMENT
 *   "cause_text": "Thunderstorms",      // Optional: description of cause
 *   "created_by": "username"            // Optional: user creating the program
 * }
 * 
 * Response:
 * {
 *   "status": "ok",
 *   "message": "Program created",
 *   "data": {
 *     "program_id": 1,
 *     "program_guid": "...",
 *     "program": { ... full program record ... }
 *   }
 * }
 * 
 * @version 1.0.0
 * @date 2026-01-21
 */

header('Content-Type: application/json; charset=utf-8');

// Handle CORS preflight
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('GDT_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');

// Only allow POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond_json(405, [
        'status' => 'error',
        'message' => 'Method not allowed. Use POST.'
    ]);
}

// Get request payload and TMI connection
$payload = read_request_payload();
$conn_tmi = get_conn_tmi();

// ============================================================================
// Validate Required Fields
// ============================================================================

$ctl_element = isset($payload['ctl_element']) ? strtoupper(trim($payload['ctl_element'])) : '';
$program_type = isset($payload['program_type']) ? strtoupper(trim($payload['program_type'])) : '';
$start_utc = isset($payload['start_utc']) ? parse_utc_datetime($payload['start_utc']) : null;
$end_utc = isset($payload['end_utc']) ? parse_utc_datetime($payload['end_utc']) : null;

if ($ctl_element === '') {
    respond_json(400, [
        'status' => 'error',
        'message' => 'ctl_element (destination airport/element) is required.'
    ]);
}

if ($program_type === '') {
    respond_json(400, [
        'status' => 'error',
        'message' => 'program_type is required. Valid types: GS, GDP-DAS, GDP-GAAP, GDP-UDP, AFP'
    ]);
}

if (!is_valid_program_type($program_type)) {
    respond_json(400, [
        'status' => 'error',
        'message' => "Invalid program_type: {$program_type}. Valid types: GS, GDP-DAS, GDP-GAAP, GDP-UDP, AFP"
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

// Validate start < end
$start_dt = new DateTime($start_utc);
$end_dt = new DateTime($end_utc);
if ($start_dt >= $end_dt) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'start_utc must be before end_utc.'
    ]);
}

// ============================================================================
// Optional Fields
// ============================================================================

$element_type = isset($payload['element_type']) ? strtoupper(trim($payload['element_type'])) : 'APT';
$program_rate = isset($payload['program_rate']) ? (int)$payload['program_rate'] : null;
$reserve_rate = isset($payload['reserve_rate']) ? (int)$payload['reserve_rate'] : null;
$delay_limit_min = isset($payload['delay_limit_min']) ? (int)$payload['delay_limit_min'] : 180;
$scope_json = isset($payload['scope_json']) ? (is_string($payload['scope_json']) ? $payload['scope_json'] : json_encode($payload['scope_json'])) : null;
$impacting_condition = isset($payload['impacting_condition']) ? strtoupper(trim($payload['impacting_condition'])) : null;
$cause_text = isset($payload['cause_text']) ? trim($payload['cause_text']) : null;
$created_by = isset($payload['created_by']) ? trim($payload['created_by']) : null;

// Default rates for GDP types
$type_info = GDT_PROGRAM_TYPES[$program_type];
if ($type_info['has_rates'] && $program_rate === null) {
    $program_rate = 30; // Default 30 arrivals/hour
}
if (isset($type_info['has_reserve']) && $type_info['has_reserve'] && $reserve_rate === null) {
    $reserve_rate = 5; // Default 5 reserved slots/hour
}

// ============================================================================
// Call Stored Procedure: sp_TMI_CreateProgram
// ============================================================================

$sql = "
    DECLARE @program_id INT;
    DECLARE @program_guid UNIQUEIDENTIFIER;
    
    EXEC dbo.sp_TMI_CreateProgram
        @ctl_element = ?,
        @element_type = ?,
        @program_type = ?,
        @start_utc = ?,
        @end_utc = ?,
        @program_rate = ?,
        @reserve_rate = ?,
        @delay_limit_min = ?,
        @scope_json = ?,
        @impacting_condition = ?,
        @cause_text = ?,
        @created_by = ?,
        @program_id = @program_id OUTPUT,
        @program_guid = @program_guid OUTPUT;
    
    SELECT @program_id AS program_id, @program_guid AS program_guid;
";

$params = [
    $ctl_element,
    $element_type,
    $program_type,
    $start_utc,
    $end_utc,
    $program_rate,
    $reserve_rate,
    $delay_limit_min,
    $scope_json,
    $impacting_condition,
    $cause_text,
    $created_by
];

$stmt = sqlsrv_query($conn_tmi, $sql, $params);

if ($stmt === false) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to create program',
        'errors' => sqlsrv_errors()
    ]);
}

// Get output values from SELECT
$program_id = null;
$program_guid = null;

$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
if ($row) {
    $program_id = isset($row['program_id']) ? (int)$row['program_id'] : null;
    $program_guid = isset($row['program_guid']) ? $row['program_guid'] : null;
}

// Try next result set if needed
if ($program_id === null) {
    while (sqlsrv_next_result($stmt)) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row && isset($row['program_id'])) {
            $program_id = (int)$row['program_id'];
            $program_guid = isset($row['program_guid']) ? $row['program_guid'] : null;
            break;
        }
    }
}

sqlsrv_free_stmt($stmt);

if ($program_id === null || $program_id <= 0) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Stored procedure executed but did not return a program_id'
    ]);
}

// ============================================================================
// Fetch Created Program
// ============================================================================

$program = get_program($conn_tmi, $program_id);

respond_json(201, [
    'status' => 'ok',
    'message' => 'Program created',
    'data' => [
        'program_id' => $program_id,
        'program_guid' => $program_guid,
        'program' => $program
    ]
]);
