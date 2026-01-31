<?php
/**
 * GDT Programs - Publish API
 *
 * POST /api/gdt/programs/publish.php
 *
 * Publishes an approved GS/GDP program after coordination.
 * Regenerates flight list, activates program, and posts ACTUAL advisory.
 *
 * Request body (JSON):
 * {
 *   "program_id": 123,              // Required: program to publish
 *   "proposal_id": 456,             // Required: approved proposal ID
 *   "regenerate_flight_list": true, // Optional: regenerate flight list (default true)
 *   "user_cid": "1234567",          // Required: publisher CID
 *   "user_name": "John Doe"         // Required: publisher name
 * }
 *
 * Response:
 * {
 *   "status": "ok",
 *   "message": "Program published",
 *   "data": {
 *     "program_id": 123,
 *     "advisory_number": "ADVZY 002",
 *     "flight_list_count": 145,
 *     "program": { ... }
 *   }
 * }
 *
 * @version 1.0.0
 * @date 2026-01-30
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('GDT_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');
require_once(__DIR__ . '/../../tmi/AdvisoryNumber.php');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond_json(405, [
        'status' => 'error',
        'message' => 'Method not allowed. Use POST.'
    ]);
}

$payload = read_request_payload();
$conn_tmi = gdt_get_conn_tmi();

// ============================================================================
// Validate Required Fields
// ============================================================================

$program_id = isset($payload['program_id']) ? (int)$payload['program_id'] : 0;
$proposal_id = isset($payload['proposal_id']) ? (int)$payload['proposal_id'] : 0;
$dcc_override = isset($payload['dcc_override']) ? (bool)$payload['dcc_override'] : false;
$regenerate_flight_list = isset($payload['regenerate_flight_list']) ? (bool)$payload['regenerate_flight_list'] : true;
$user_cid = isset($payload['user_cid']) ? trim($payload['user_cid']) : null;
$user_name = isset($payload['user_name']) ? trim($payload['user_name']) : 'Unknown';

// DCC override additional fields (for direct publish without proposal)
$reason = isset($payload['reason']) ? strtoupper(trim($payload['reason'])) : 'WEATHER';
$remarks = isset($payload['remarks']) ? trim($payload['remarks']) : null;
$flights = isset($payload['flights']) ? (array)$payload['flights'] : [];

if ($program_id <= 0) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'program_id is required.'
    ]);
}

if (empty($user_cid)) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'user_cid is required.'
    ]);
}

// Check program exists
$program = get_program($conn_tmi, $program_id);

if ($program === null) {
    respond_json(404, [
        'status' => 'error',
        'message' => "Program not found: {$program_id}"
    ]);
}

// DCC Override mode - skip proposal validation
$proposal = null;
if ($dcc_override) {
    // DCC can publish directly without coordination approval
    // Program just needs to be in PROPOSED or MODELING status
    $valid_statuses = ['PROPOSED', 'MODELING', 'PENDING_COORD'];
    if (!in_array($program['status'], $valid_statuses) && !$program['is_active']) {
        respond_json(400, [
            'status' => 'error',
            'message' => "Program cannot be published directly. Status: {$program['status']}."
        ]);
    }
} else {
    // Standard flow - require approved proposal
    if ($proposal_id <= 0) {
        respond_json(400, [
            'status' => 'error',
            'message' => 'proposal_id is required (or use dcc_override: true).'
        ]);
    }

    // Verify program is linked to this proposal
    if ((int)($program['proposal_id'] ?? 0) !== $proposal_id) {
        respond_json(400, [
            'status' => 'error',
            'message' => "Program {$program_id} is not linked to proposal {$proposal_id}"
        ]);
    }

    // Check proposal status
    $proposal = get_proposal($conn_tmi, $proposal_id);

    if ($proposal === null) {
        respond_json(404, [
            'status' => 'error',
            'message' => "Proposal not found: {$proposal_id}"
        ]);
    }

    // Verify proposal is approved
    $valid_statuses = ['APPROVED'];
    if (!in_array($proposal['status'], $valid_statuses)) {
        respond_json(400, [
            'status' => 'error',
            'message' => "Proposal cannot be published. Status: {$proposal['status']}. Must be APPROVED."
        ]);
    }
}

// ============================================================================
// Get Next Advisory Number for ACTUAL advisory
// ============================================================================

$advNumHelper = new AdvisoryNumber($conn_tmi, 'sqlsrv');
$advisory_number = $advNumHelper->reserve();

// ============================================================================
// Regenerate Flight List (if requested)
// ============================================================================

$flight_list_count = 0;

if ($regenerate_flight_list) {
    // Clear existing flight list for this program
    $clear_sql = "DELETE FROM dbo.tmi_flight_list WHERE program_id = ?";
    execute_query($conn_tmi, $clear_sql, [$program_id]);

    // Get flights from tmi_flight_control (populated by model/simulate)
    $flights_result = fetch_all($conn_tmi,
        "SELECT * FROM dbo.tmi_flight_control WHERE program_id = ?",
        [$program_id]
    );

    if ($flights_result['success'] && !empty($flights_result['data'])) {
        foreach ($flights_result['data'] as $flight) {
            // Generate GUFI (Global Unique Flight Identifier)
            $gufi = generate_gufi($flight);

            $insert_sql = "INSERT INTO dbo.tmi_flight_list (
                               program_id, flight_gufi, callsign, flight_uid,
                               dep_airport, arr_airport, aircraft_type,
                               original_etd_utc, original_eta_utc,
                               edct_utc, cta_utc, delay_minutes,
                               slot_id, slot_time_utc,
                               is_exempt, exemption_code,
                               compliance_status, flight_status,
                               added_by
                           ) VALUES (
                               ?, ?, ?, ?,
                               ?, ?, ?,
                               ?, ?,
                               ?, ?, ?,
                               ?, ?,
                               ?, ?,
                               'PENDING', 'SCHEDULED',
                               ?
                           )";

            $insert_params = [
                $program_id,
                $gufi,
                $flight['callsign'] ?? '',
                $flight['flight_uid'] ?? null,
                $flight['dep_icao'] ?? $flight['dep_airport'] ?? '',
                $flight['arr_icao'] ?? $flight['arr_airport'] ?? $program['ctl_element'],
                $flight['ac_type'] ?? $flight['aircraft_type'] ?? null,
                datetime_to_sql($flight['orig_etd_utc'] ?? null),
                datetime_to_sql($flight['orig_eta_utc'] ?? null),
                datetime_to_sql($flight['ctd_utc'] ?? null), // EDCT
                datetime_to_sql($flight['cta_utc'] ?? null),
                $flight['program_delay_min'] ?? null,
                $flight['slot_id'] ?? null,
                datetime_to_sql($flight['cta_utc'] ?? null), // Slot time = CTA for GDP
                $flight['ctl_exempt'] ?? 0,
                $flight['ctl_exempt'] ? ($flight['ctl_exempt_reason'] ?? 'EXEMPT') : null,
                $user_cid
            ];

            execute_query($conn_tmi, $insert_sql, $insert_params);
            $flight_list_count++;
        }
    }

    // Update flight_list_generated_at
    $update_sql = "UPDATE dbo.tmi_programs SET flight_list_generated_at = SYSUTCDATETIME() WHERE program_id = ?";
    execute_query($conn_tmi, $update_sql, [$program_id]);
}

// ============================================================================
// Activate Program
// ============================================================================

$activate_sql = "EXEC dbo.sp_TMI_ActivateProgram @program_id = ?, @activated_by = ?";
$activate_stmt = sqlsrv_query($conn_tmi, $activate_sql, [$program_id, $user_cid]);

if ($activate_stmt === false) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to activate program',
        'errors' => sqlsrv_errors()
    ]);
}
sqlsrv_free_stmt($activate_stmt);

// ============================================================================
// Update Program and Proposal Status
// ============================================================================

// Update program with ACTUAL advisory number
$update_program_sql = "UPDATE dbo.tmi_programs SET
                           adv_number = ?,
                           proposal_status = 'ACTIVATED',
                           impacting_condition = COALESCE(impacting_condition, ?),
                           updated_at = SYSUTCDATETIME()
                       WHERE program_id = ?";
execute_query($conn_tmi, $update_program_sql, [$advisory_number, $reason, $program_id]);

// Update proposal status (if not DCC override)
if ($proposal_id > 0) {
    $update_proposal_sql = "UPDATE dbo.tmi_proposals SET
                                status = 'ACTIVATED',
                                activated_at = SYSUTCDATETIME(),
                                updated_at = SYSUTCDATETIME()
                            WHERE proposal_id = ?";
    execute_query($conn_tmi, $update_proposal_sql, [$proposal_id]);
}

// ============================================================================
// Generate ACTUAL Advisory Text
// ============================================================================

$program = get_program($conn_tmi, $program_id);
$advisory_text = generate_actual_advisory($program, $advisory_number);

// Log the action
$action_type = $dcc_override ? 'DCC_OVERRIDE_PUBLISH' : 'PROGRAM_ACTIVATED';
log_coordination_action($conn_tmi, $program_id, $proposal_id ?: null, $action_type, [
    'advisory_number' => $advisory_number,
    'flight_list_count' => $flight_list_count,
    'dcc_override' => $dcc_override,
    'reason' => $reason,
    'user_cid' => $user_cid,
    'user_name' => $user_name
], $advisory_number, 'ACTUAL');

respond_json(200, [
    'status' => 'ok',
    'message' => $dcc_override ? 'Program published directly (DCC override)' : 'Program published and activated',
    'data' => [
        'program_id' => $program_id,
        'proposal_id' => $proposal_id ?: null,
        'advisory_number' => $advisory_number,
        'advisory_text' => $advisory_text,
        'flight_list_count' => $flight_list_count,
        'dcc_override' => $dcc_override,
        'program' => $program
    ]
]);

// ============================================================================
// Helper Functions
// ============================================================================

/**
 * Get proposal by ID
 */
function get_proposal($conn, $proposal_id) {
    $result = fetch_one($conn, "SELECT * FROM dbo.tmi_proposals WHERE proposal_id = ?", [(int)$proposal_id]);
    return $result['success'] ? $result['data'] : null;
}

/**
 * Generate GUFI (Global Unique Flight Identifier)
 */
function generate_gufi($flight) {
    // GUFI format: CALLSIGN-DEP-ARR-YYYYMMDD-HHMM
    $callsign = $flight['callsign'] ?? 'UNKN';
    $dep = $flight['dep_icao'] ?? $flight['dep_airport'] ?? 'ZZZZ';
    $arr = $flight['arr_icao'] ?? $flight['arr_airport'] ?? 'ZZZZ';

    $etd = $flight['orig_etd_utc'] ?? $flight['etd_utc'] ?? null;
    if ($etd instanceof DateTime) {
        $date = $etd->format('Ymd');
        $time = $etd->format('Hi');
    } elseif ($etd) {
        $dt = new DateTime($etd);
        $date = $dt->format('Ymd');
        $time = $dt->format('Hi');
    } else {
        $date = date('Ymd');
        $time = '0000';
    }

    return strtoupper("{$callsign}-{$dep}-{$arr}-{$date}-{$time}");
}

/**
 * Convert datetime to SQL format
 */
function datetime_to_sql($val) {
    if ($val === null) return null;
    if ($val instanceof DateTime) {
        return $val->format('Y-m-d H:i:s');
    }
    if (is_string($val) && trim($val) !== '') {
        try {
            $dt = new DateTime($val);
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return null;
        }
    }
    return null;
}

/**
 * Generate ACTUAL advisory text (vATCSCC format)
 */
function generate_actual_advisory($program, $advisory_number) {
    $program_type = $program['program_type'] ?? 'GS';
    $is_gdp = strpos($program_type, 'GDP') !== false;
    $ctl_element = $program['ctl_element'] ?? 'UNKN';
    $element_type = $program['element_type'] ?? 'APT';
    $artcc = $program['artcc'] ?? $program['arr_center'] ?? 'ZZZ';

    // Current time for ADL TIME
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $adl_time = $now->format('Hi') . 'Z';
    $header_date = $now->format('m/d/Y');

    // Format program period dates
    $start = $program['start_utc'] instanceof DateTime ? $program['start_utc'] : new DateTime($program['start_utc']);
    $end = $program['end_utc'] instanceof DateTime ? $program['end_utc'] : new DateTime($program['end_utc']);
    $start_period = $start->format('d/Hi') . 'Z';
    $end_period = $end->format('d/Hi') . 'Z';

    // Footer time codes
    $start_code = $start->format('dHi');
    $end_code = $end->format('dHi');
    $footer_timestamp = $now->format('y/m/d H:i');

    // Get delay summary
    $total_delay = round($program['total_delay_min'] ?? 0);
    $max_delay = round($program['max_delay_min'] ?? $program['delay_limit_min'] ?? 0);
    $avg_delay = round($program['avg_delay_min'] ?? 0);

    // Flight inclusion and facilities
    $flt_incl = $program['flight_filter'] ?? 'ALL';
    $facilities = $program['scope_facilities'] ?? $artcc;
    $prob_extension = $program['prob_extension'] ?? 'MODERATE';
    $reason = $program['impacting_condition'] ?? 'VOLUME';
    $comments = $program['comments'] ?? 'NONE';

    // Extract advisory number (e.g., "ADVZY 001" -> "001")
    $adv_num = preg_replace('/[^0-9]/', '', $advisory_number) ?: '001';
    $adv_num = str_pad($adv_num, 3, '0', STR_PAD_LEFT);

    $lines = [];

    if ($is_gdp) {
        // GDP format
        $program_rate = $program['program_rate'] ?? 0;
        $controlled_flights = $program['controlled_flights'] ?? $program['flight_count'] ?? 0;

        $lines[] = "vATCSCC ADVZY {$adv_num} {$ctl_element}/{$artcc} {$header_date} CDM GROUND DELAY PROGRAM";
        $lines[] = "CTL ELEMENT: {$ctl_element}";
        $lines[] = "ELEMENT TYPE: {$element_type}";
        $lines[] = "ADL TIME: {$adl_time}";
        $lines[] = "GDP PERIOD: {$start_period} - {$end_period}";
        $lines[] = "FLT INCL: {$flt_incl}";
        $lines[] = "DEP FACILITIES INCLUDED: (Tier1) {$facilities}";
        $lines[] = "PROGRAM RATE: {$program_rate}/HR";
        $lines[] = "DELAY ASSIGNMENT MODE: UDP";
        $lines[] = "NEW TOTAL, MAXIMUM, AVERAGE DELAYS: {$total_delay} / {$max_delay} / {$avg_delay}";
        $lines[] = "CONTROLLED FLIGHTS: {$controlled_flights}";
        $lines[] = "PROBABILITY OF EXTENSION: {$prob_extension}";
        $lines[] = "IMPACTING CONDITION: {$reason}";
        $lines[] = "COMMENTS: {$comments}";
    } else {
        // Ground Stop format
        $lines[] = "vATCSCC ADVZY {$adv_num} {$ctl_element}/{$artcc} {$header_date} CDM GROUND STOP";
        $lines[] = "CTL ELEMENT: {$ctl_element}";
        $lines[] = "ELEMENT TYPE: {$element_type}";
        $lines[] = "ADL TIME: {$adl_time}";
        $lines[] = "GROUND STOP PERIOD: {$start_period} - {$end_period}";
        $lines[] = "FLT INCL: {$flt_incl}";
        $lines[] = "DEP FACILITIES INCLUDED: (Tier1) {$facilities}";
        $lines[] = "NEW TOTAL, MAXIMUM, AVERAGE DELAYS: {$total_delay} / {$max_delay} / {$avg_delay}";
        $lines[] = "PROBABILITY OF EXTENSION: {$prob_extension}";
        $lines[] = "IMPACTING CONDITION: {$reason}";
        $lines[] = "COMMENTS: {$comments}";
    }

    $lines[] = "";
    $lines[] = "{$start_code}-{$end_code}";
    $lines[] = $footer_timestamp;

    return implode("\n", $lines);
}

/**
 * Log coordination action
 */
function log_coordination_action($conn, $program_id, $proposal_id, $action_type, $data = [], $advisory_number = null, $advisory_type = null) {
    $sql = "INSERT INTO dbo.tmi_program_coordination_log (
                program_id, proposal_id, action_type, action_data_json,
                advisory_number, advisory_type,
                performed_by, performed_by_name
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

    $params = [
        $program_id,
        $proposal_id,
        $action_type,
        json_encode($data),
        $advisory_number,
        $advisory_type,
        $data['user_cid'] ?? null,
        $data['user_name'] ?? null
    ];

    execute_query($conn, $sql, $params);
}
