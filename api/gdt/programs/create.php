<?php
/**
 * GDT Programs - Create API
 * 
 * POST /api/gdt/programs/create.php
 * 
 * Creates a new GS/GDP/AFP program in PROPOSED status.
 * Uses direct SQL INSERT into VATSIM_TMI.dbo.tmi_programs.
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
 *   "scope_type": "TIER",               // Optional: TIER, DISTANCE, CENTER, CUSTOM
 *   "scope_tier": 2,                    // Optional: tier level (1-5)
 *   "scope_distance_nm": null,          // Optional: distance in NM
 *   "scope_json": {...},                // Optional: scope/filter configuration
 *   "exempt_airborne": 1,               // Optional: exempt airborne flights (default 1)
 *   "exempt_within_min": 30,            // Optional: exempt flights departing within N minutes
 *   "flt_incl_carrier": null,           // Optional: carrier filter
 *   "flt_incl_type": "ALL",             // Optional: aircraft type filter
 *   "flt_incl_fix": null,               // Optional: arrival fix filter
 *   "impacting_condition": "WEATHER",   // Optional: WEATHER, VOLUME, RUNWAY, EQUIPMENT
 *   "cause_text": "Thunderstorms",      // Optional: description of cause
 *   "prob_extension": "MEDIUM",         // Optional: LOW, MEDIUM, HIGH
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
 * @version 2.0.0
 * @date 2026-01-26
 */

header('Content-Type: application/json; charset=utf-8');

// Handle CORS preflight
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('GDT_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');
$auth_cid = gdt_require_auth();

// Only allow POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond_json(405, [
        'status' => 'error',
        'message' => 'Method not allowed. Use POST.'
    ]);
}

// Get request payload and TMI connection
$payload = read_request_payload();
$conn_tmi = gdt_get_conn_tmi();

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
$target_delay_mult = isset($payload['target_delay_mult']) ? (float)$payload['target_delay_mult'] : 1.0;

// Scope parameters (Migration 013 columns)
$scope_type = isset($payload['scope_type']) ? strtoupper(trim($payload['scope_type'])) : null;
$scope_tier = isset($payload['scope_tier']) ? (int)$payload['scope_tier'] : null;
$scope_distance_nm = isset($payload['scope_distance_nm']) ? (int)$payload['scope_distance_nm'] : null;
$scope_json = isset($payload['scope_json']) ? (is_string($payload['scope_json']) ? $payload['scope_json'] : json_encode($payload['scope_json'])) : null;

// Exemption parameters
$exempt_airborne = isset($payload['exempt_airborne']) ? (int)$payload['exempt_airborne'] : 1;
$exempt_within_min = isset($payload['exempt_within_min']) ? (int)$payload['exempt_within_min'] : null;
$exemptions_json = isset($payload['exemptions_json']) ? (is_string($payload['exemptions_json']) ? $payload['exemptions_json'] : json_encode($payload['exemptions_json'])) : null;

// Flight filter parameters
$flt_incl_carrier = isset($payload['flt_incl_carrier']) ? trim($payload['flt_incl_carrier']) : null;
$flt_incl_type = isset($payload['flt_incl_type']) ? strtoupper(trim($payload['flt_incl_type'])) : 'ALL';
$flt_incl_fix = isset($payload['flt_incl_fix']) ? strtoupper(trim($payload['flt_incl_fix'])) : null;

// Other parameters
$impacting_condition = isset($payload['impacting_condition']) ? strtoupper(trim($payload['impacting_condition'])) : null;
$cause_text = isset($payload['cause_text']) ? trim($payload['cause_text']) : null;
$comments = isset($payload['comments']) ? trim($payload['comments']) : null;
$prob_extension = isset($payload['prob_extension']) ? strtoupper(trim($payload['prob_extension'])) : null;
$created_by = $auth_cid;

// Rate parameters (JSON)
$rates_hourly_json = isset($payload['rates_hourly_json']) ? (is_string($payload['rates_hourly_json']) ? $payload['rates_hourly_json'] : json_encode($payload['rates_hourly_json'])) : null;
$reserve_hourly_json = isset($payload['reserve_hourly_json']) ? (is_string($payload['reserve_hourly_json']) ? $payload['reserve_hourly_json'] : json_encode($payload['reserve_hourly_json'])) : null;

// Default rates for GDP types
$type_info = GDT_PROGRAM_TYPES[$program_type];
if ($type_info['has_rates'] && $program_rate === null) {
    $program_rate = 30; // Default 30 arrivals/hour
}
if (isset($type_info['has_reserve']) && $type_info['has_reserve'] && $reserve_rate === null) {
    $reserve_rate = 5; // Default 5 reserved slots/hour
}

// Generate program GUID and name
$program_guid = strtoupper(sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0x0fff) | 0x4000,
    mt_rand(0, 0x3fff) | 0x8000,
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
));

// Generate program name: KJFK-GS-MMDDhhmm
$start_for_name = new DateTime($start_utc);
$program_name = $ctl_element . '-' . str_replace('-', '', $program_type) . '-' . $start_for_name->format('mdHi');

// ============================================================================
// Insert Program using Direct SQL
// ============================================================================

$sql = "
    INSERT INTO dbo.tmi_programs (
        program_guid, ctl_element, element_type, program_type, program_name,
        adv_number, start_utc, end_utc, cumulative_start, cumulative_end,
        status, is_proposed, is_active, program_rate, reserve_rate,
        delay_limit_min, target_delay_mult, rates_hourly_json, reserve_hourly_json,
        scope_type, scope_tier, scope_distance_nm, scope_json, exemptions_json,
        exempt_airborne, exempt_within_min, flt_incl_carrier, flt_incl_type, flt_incl_fix,
        impacting_condition, cause_text, comments, prob_extension, revision_number,
        total_flights, controlled_flights, exempt_flights, avg_delay_min, max_delay_min, total_delay_min,
        created_by, created_at, updated_at
    ) VALUES (
        ?, ?, ?, ?, ?,
        'ADVZY 001', ?, ?, ?, ?,
        'PROPOSED', 1, 0, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?, 0,
        0, 0, 0, 0, 0, 0,
        ?, GETUTCDATE(), GETUTCDATE()
    );
    SELECT SCOPE_IDENTITY() AS program_id;
";

$params = [
    $program_guid,
    $ctl_element,
    $element_type,
    $program_type,
    $program_name,
    $start_utc,
    $end_utc,
    $start_utc,  // cumulative_start
    $end_utc,    // cumulative_end
    $program_rate,
    $reserve_rate,
    $delay_limit_min,
    $target_delay_mult,
    $rates_hourly_json,
    $reserve_hourly_json,
    $scope_type,
    $scope_tier,
    $scope_distance_nm,
    $scope_json,
    $exemptions_json,
    $exempt_airborne,
    $exempt_within_min,
    $flt_incl_carrier,
    $flt_incl_type,
    $flt_incl_fix,
    $impacting_condition,
    $cause_text,
    $comments,
    $prob_extension,
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

// Get the new program_id from SCOPE_IDENTITY()
$program_id = null;

// Move to result set containing SCOPE_IDENTITY
sqlsrv_next_result($stmt);
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
