<?php
/**
 * GS Create API
 * 
 * POST /api/tmi/gs/create.php
 * 
 * Creates a new Ground Stop program in PROPOSED state.
 * 
 * UPDATED: 2026-01-26 - Now uses VATSIM_TMI.tmi_programs instead of VATSIM_ADL.ntml
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
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('GS_API_INCLUDED', true);
require_once(__DIR__ . '/common.php');

// Only allow POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond_json(405, [
        'status' => 'error',
        'message' => 'Method not allowed. Use POST.'
    ]);
}

// Get request payload
$payload = read_request_payload();
$conn = get_tmi_conn();  // Use TMI database

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
$impacting_condition = isset($payload['impacting_condition']) ? strtoupper(trim($payload['impacting_condition'])) : 'WEATHER';
$cause_text = isset($payload['cause_text']) ? trim($payload['cause_text']) : 'Ground Stop';
$comments = isset($payload['comments']) ? trim($payload['comments']) : null;
$prob_extension = isset($payload['prob_extension']) ? strtoupper(trim($payload['prob_extension'])) : 'MEDIUM';
$created_by = isset($payload['created_by']) ? trim($payload['created_by']) : 'TMU';

// Generate program name and advisory number
// Format: KJFK-GS-01261530 (element-type-MMddHHmm)
$start_dt = new DateTime($start_utc, new DateTimeZone('UTC'));
$program_name = $ctl_element . '-GS-' . $start_dt->format('mdHi');

// Get next advisory number for today
$today_date = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d');
$adv_result = fetch_one($conn, "
    SELECT COUNT(*) + 1 AS next_num 
    FROM dbo.tmi_programs 
    WHERE program_type = 'GS' 
    AND CAST(created_at AS DATE) = ?
", [$today_date]);
$adv_num = $adv_result['success'] && $adv_result['data'] ? (int)$adv_result['data']['next_num'] : 1;
$adv_number = 'ADVZY ' . str_pad($adv_num, 3, '0', STR_PAD_LEFT);

// Insert into tmi_programs
$sql = "
    INSERT INTO dbo.tmi_programs (
        program_guid,
        ctl_element,
        element_type,
        program_type,
        program_name,
        adv_number,
        start_utc,
        end_utc,
        cumulative_start,
        cumulative_end,
        status,
        is_proposed,
        is_active,
        delay_limit_min,
        scope_type,
        scope_tier,
        scope_distance_nm,
        exempt_airborne,
        exempt_within_min,
        flt_incl_carrier,
        flt_incl_type,
        impacting_condition,
        cause_text,
        comments,
        prob_extension,
        created_by,
        created_at,
        updated_at
    ) VALUES (
        NEWID(),
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?,
        'PROPOSED', 1, 0,
        180,
        ?, ?, ?,
        ?, ?,
        ?, ?,
        ?, ?, ?, ?,
        ?,
        SYSUTCDATETIME(),
        SYSUTCDATETIME()
    );
    SELECT SCOPE_IDENTITY() AS program_id;
";

$params = [
    $ctl_element,           // ctl_element
    'APT',                  // element_type
    'GS',                   // program_type
    $program_name,          // program_name
    $adv_number,            // adv_number
    $start_utc,             // start_utc
    $end_utc,               // end_utc
    $start_utc,             // cumulative_start
    $end_utc,               // cumulative_end
    $scope_type,            // scope_type
    $scope_tier,            // scope_tier
    $scope_distance_nm,     // scope_distance_nm
    $exempt_airborne ? 1 : 0, // exempt_airborne
    $exempt_within_min,     // exempt_within_min
    $flt_incl_carrier,      // flt_incl_carrier
    $flt_incl_type,         // flt_incl_type
    $impacting_condition,   // impacting_condition
    $cause_text,            // cause_text
    $comments,              // comments
    $prob_extension,        // prob_extension
    $created_by             // created_by
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
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
if ($row && isset($row['program_id'])) {
    $program_id = (int)$row['program_id'];
}

sqlsrv_free_stmt($stmt);

if ($program_id === null || $program_id <= 0) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Insert executed but did not return a program_id'
    ]);
}

// Fetch the created program
$program_result = fetch_one($conn, "SELECT * FROM dbo.tmi_programs WHERE program_id = ?", [$program_id]);

respond_json(200, [
    'status' => 'ok',
    'message' => 'Ground Stop created',
    'data' => [
        'program_id' => $program_id,
        'program' => $program_result['success'] ? $program_result['data'] : null
    ]
]);
