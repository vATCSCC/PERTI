<?php
/**
 * GDT Programs - Submit for Coordination API
 *
 * POST /api/gdt/programs/submit_proposal.php
 *
 * Submits a GS/GDP program for coordination via TMI Publishing.
 * Creates a proposal linked to the program, posts PROPOSED advisory,
 * and initiates Discord coordination workflow.
 *
 * Request body (JSON):
 * {
 *   "program_id": 123,                    // Required: program to submit
 *   "coordination_mode": "STANDARD",      // STANDARD (45min), EXPEDITED (15min), IMMEDIATE
 *   "deadline_minutes": 45,               // Optional: custom deadline (default by mode)
 *   "facilities": ["ZDC", "ZNY"],         // Required: facilities for approval
 *   "advisory_text": "...",               // Optional: pre-formatted advisory text
 *   "user_cid": "1234567",                // Required: submitter CID
 *   "user_name": "John Doe"               // Required: submitter name
 * }
 *
 * Response:
 * {
 *   "status": "ok",
 *   "message": "Proposal submitted" | "Program activated immediately",
 *   "data": {
 *     "program_id": 123,
 *     "proposal_id": 456,
 *     "advisory_number": "ADVZY 001",
 *     "coordination_deadline_utc": "2026-01-21T15:00:00Z",
 *     "is_immediate": false
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
$auth_cid = gdt_require_auth();
require_once(__DIR__ . '/../../tmi/AdvisoryNumber.php');
require_once __DIR__ . '/../../../load/perti_constants.php';

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
$coordination_mode = isset($payload['coordination_mode']) ? strtoupper(trim($payload['coordination_mode'])) : 'STANDARD';
$deadline_minutes = isset($payload['deadline_minutes']) ? (int)$payload['deadline_minutes'] : null;
$facilities = isset($payload['facilities']) ? (array)$payload['facilities'] : [];
$advisory_text = isset($payload['advisory_text']) ? trim($payload['advisory_text']) : null;
$user_cid = isset($payload['user_cid']) ? trim($payload['user_cid']) : null;
$user_name = isset($payload['user_name']) ? trim($payload['user_name']) : 'Unknown';

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

// Validate coordination mode
if (!in_array($coordination_mode, PERTI_COORDINATION_MODES)) {
    respond_json(400, [
        'status' => 'error',
        'message' => "Invalid coordination_mode: {$coordination_mode}. Valid modes: " . implode(', ', PERTI_COORDINATION_MODES)
    ]);
}

// Check program exists and is in correct state
$program = get_program($conn_tmi, $program_id);

if ($program === null) {
    respond_json(404, [
        'status' => 'error',
        'message' => "Program not found: {$program_id}"
    ]);
}

// Validate program is in correct state for submission
if (!in_array($program['status'], PERTI_MODELING_STATUSES)) {
    respond_json(400, [
        'status' => 'error',
        'message' => "Program cannot be submitted. Current status: {$program['status']}. Must be " . implode(' or ', PERTI_MODELING_STATUSES) . "."
    ]);
}

// ============================================================================
// Determine Deadline
// ============================================================================

$default_deadlines = [
    'STANDARD' => 45,
    'EXPEDITED' => 15,
    'IMMEDIATE' => 0
];

if ($deadline_minutes === null) {
    $deadline_minutes = $default_deadlines[$coordination_mode];
}

$now = new DateTime('now', new DateTimeZone('UTC'));
$deadline = clone $now;
$deadline->add(new DateInterval('PT' . $deadline_minutes . 'M'));

// ============================================================================
// Get Next Advisory Number (peek for proposal, will reserve later if IMMEDIATE)
// ============================================================================

$advNumHelper = new AdvisoryNumber($conn_tmi, 'sqlsrv');
$advisory_number = $advNumHelper->peek();

// ============================================================================
// Handle IMMEDIATE Mode - Skip Coordination
// ============================================================================

if ($coordination_mode === 'IMMEDIATE') {
    // For GS: Default to immediate (skip coordination)
    // For GDP: Immediate is optional

    // Reserve ACTUAL advisory number using centralized class
    $actual_adv_number = $advNumHelper->reserve();

    // Activate program directly
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

    // Update program with advisory number (ACTUAL, no PROPOSED for immediate)
    $update_sql = "UPDATE dbo.tmi_programs SET
                       adv_number = ?,
                       proposal_status = 'ACTIVATED',
                       updated_at = SYSUTCDATETIME()
                   WHERE program_id = ?";
    execute_query($conn_tmi, $update_sql, [$actual_adv_number, $program_id]);

    // Log the action
    log_coordination_action($conn_tmi, $program_id, null, 'IMMEDIATE_ACTIVATION', [
        'advisory_number' => $actual_adv_number,
        'user_cid' => $user_cid,
        'user_name' => $user_name
    ]);

    // Get updated program
    $program = get_program($conn_tmi, $program_id);

    respond_json(200, [
        'status' => 'ok',
        'message' => 'Program activated immediately (no coordination)',
        'data' => [
            'program_id' => $program_id,
            'proposal_id' => null,
            'advisory_number' => $actual_adv_number,
            'is_immediate' => true,
            'program' => $program
        ]
    ]);
}

// ============================================================================
// Standard/Expedited Coordination Flow
// ============================================================================

// Validate facilities for coordination
if (empty($facilities)) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'At least one facility must be specified for coordination.'
    ]);
}

// Determine program type display
$program_type = $program['program_type'] ?? 'GS';
$is_gdp = strpos($program_type, 'GDP') !== false;
$entry_type = $is_gdp ? 'GDP' : 'GS';

// Build entry data for proposal
$entry_data = [
    'entryType' => $entry_type,
    'program_id' => $program_id,
    'program_type' => $program_type,
    'ctl_element' => $program['ctl_element'],
    'start_utc' => datetime_to_iso($program['start_utc']),
    'end_utc' => datetime_to_iso($program['end_utc']),
    'program_rate' => $program['program_rate'],
    'scope_json' => $program['scope_json'],
    'exemptions_json' => $program['exemptions_json'],
    'impacting_condition' => $program['impacting_condition'],
    'cause_text' => $program['cause_text'],
    'avg_delay_min' => $program['avg_delay_min'],
    'max_delay_min' => $program['max_delay_min'],
    'controlled_flights' => $program['controlled_flights']
];

// Generate advisory text if not provided
if (!$advisory_text) {
    $advisory_text = generate_proposed_advisory($program, $advisory_number, $deadline);
}

// Create proposal in tmi_proposals
$proposal_sql = "INSERT INTO dbo.tmi_proposals (
                     entry_type, program_id, requesting_facility, ctl_element,
                     entry_data_json, raw_text, program_snapshot_json,
                     approval_deadline_utc, valid_from, valid_until,
                     facilities_required,
                     created_by, created_by_name
                 ) OUTPUT INSERTED.proposal_id, INSERTED.proposal_guid
                 VALUES (
                     ?, ?, 'DCC', ?,
                     ?, ?, ?,
                     ?, ?, ?,
                     ?,
                     ?, ?
                 )";

$start_utc = $program['start_utc'] instanceof DateTime ? $program['start_utc']->format('Y-m-d H:i:s') : $program['start_utc'];
$end_utc = $program['end_utc'] instanceof DateTime ? $program['end_utc']->format('Y-m-d H:i:s') : $program['end_utc'];

$proposal_params = [
    $entry_type,
    $program_id,
    $program['ctl_element'],
    json_encode($entry_data),
    $advisory_text,
    json_encode($program), // Snapshot of program state
    $deadline->format('Y-m-d H:i:s'),
    $start_utc,
    $end_utc,
    count($facilities),
    $user_cid,
    $user_name
];

$proposal_stmt = sqlsrv_query($conn_tmi, $proposal_sql, $proposal_params);

if ($proposal_stmt === false) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to create proposal',
        'errors' => sqlsrv_errors()
    ]);
}

$proposal_row = sqlsrv_fetch_array($proposal_stmt, SQLSRV_FETCH_ASSOC);
$proposal_id = $proposal_row['proposal_id'] ?? null;
$proposal_guid = $proposal_row['proposal_guid'] ?? null;
sqlsrv_free_stmt($proposal_stmt);

if (!$proposal_id) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to get proposal_id after insert'
    ]);
}

// Insert facility approval records
$facility_emojis = get_facility_emojis();
foreach ($facilities as $facility) {
    $fac_code = is_array($facility) ? ($facility['code'] ?? $facility) : strtoupper(trim($facility));
    $fac_name = is_array($facility) ? ($facility['name'] ?? null) : null;
    $fac_emoji = $facility_emojis[$fac_code] ?? null;

    $fac_sql = "INSERT INTO dbo.tmi_proposal_facilities (
                    proposal_id, facility_code, facility_name, approval_emoji
                ) VALUES (?, ?, ?, ?)";
    execute_query($conn_tmi, $fac_sql, [$proposal_id, $fac_code, $fac_name, $fac_emoji]);
}

// Update program with coordination info
$update_program_sql = "UPDATE dbo.tmi_programs SET
                           proposal_id = ?,
                           proposal_status = 'PENDING_COORD',
                           coordination_deadline_utc = ?,
                           coordination_facilities_json = ?,
                           proposed_advisory_num = ?,
                           updated_at = SYSUTCDATETIME()
                       WHERE program_id = ?";

$update_params = [
    $proposal_id,
    $deadline->format('Y-m-d H:i:s'),
    json_encode($facilities),
    $advisory_number,
    $program_id
];

execute_query($conn_tmi, $update_program_sql, $update_params);

// Log the action
log_coordination_action($conn_tmi, $program_id, $proposal_id, 'PROPOSAL_SUBMITTED', [
    'coordination_mode' => $coordination_mode,
    'deadline_minutes' => $deadline_minutes,
    'facilities' => $facilities,
    'advisory_number' => $advisory_number,
    'user_cid' => $user_cid,
    'user_name' => $user_name
]);

// Get updated program
$program = get_program($conn_tmi, $program_id);

respond_json(200, [
    'status' => 'ok',
    'message' => 'Proposal submitted for coordination',
    'data' => [
        'program_id' => $program_id,
        'proposal_id' => $proposal_id,
        'proposal_guid' => $proposal_guid,
        'advisory_number' => $advisory_number,
        'coordination_deadline_utc' => $deadline->format('Y-m-d\TH:i:s') . 'Z',
        'advisory_text' => $advisory_text,
        'facilities' => $facilities,
        'is_immediate' => false,
        'program' => $program
    ]
]);

// ============================================================================
// Helper Functions
// ============================================================================

/**
 * Generate PROPOSED advisory text
 */
function generate_proposed_advisory($program, $advisory_number, $deadline) {
    $program_type = $program['program_type'] ?? 'GS';
    $is_gdp = strpos($program_type, 'GDP') !== false;
    $ctl_element = $program['ctl_element'] ?? 'UNKN';

    // Format dates
    $start = $program['start_utc'] instanceof DateTime ? $program['start_utc'] : new DateTime($program['start_utc']);
    $end = $program['end_utc'] instanceof DateTime ? $program['end_utc'] : new DateTime($program['end_utc']);
    $start_str = $start->format('d/Hi') . 'Z';
    $end_str = $end->format('d/Hi') . 'Z';
    $deadline_str = $deadline->format('d/Hi') . 'Z';

    // Build advisory
    $lines = [];

    if ($is_gdp) {
        $lines[] = "CDM PROPOSED GROUND DELAY PROGRAM {$advisory_number}";
        $lines[] = "";
        $lines[] = "CTL ELEMENT.................. {$ctl_element}";
        $lines[] = "REASON FOR PROGRAM........... " . ($program['impacting_condition'] ?? 'VOLUME') . "/" . ($program['cause_text'] ?? 'DEMAND');
        $lines[] = "ANTICIPATED PROGRAM START.... {$start_str}";
        $lines[] = "ANTICIPATED END TIME......... {$end_str}";

        if (isset($program['avg_delay_min']) && $program['avg_delay_min'] > 0) {
            $lines[] = "AVERAGE DELAY................ " . round($program['avg_delay_min']) . " MINUTES";
        }
        if (isset($program['max_delay_min']) && $program['max_delay_min'] > 0) {
            $lines[] = "MAXIMUM DELAY................ " . $program['max_delay_min'] . " MINUTES";
        }

        $lines[] = "DELAY ASSIGNMENT MODE........ UDP";

        if (isset($program['program_rate']) && $program['program_rate'] > 0) {
            $lines[] = "PROGRAM RATE................. " . $program['program_rate'] . " PER HOUR";
        }
    } else {
        // Ground Stop
        $lines[] = "CDM PROPOSED GROUND STOP {$advisory_number}";
        $lines[] = "";
        $lines[] = "CTL ELEMENT.................. {$ctl_element}";
        $lines[] = "REASON FOR GROUND STOP....... " . ($program['impacting_condition'] ?? 'WEATHER') . "/" . ($program['cause_text'] ?? 'CONDITIONS');
        $lines[] = "ANTICIPATED GROUND STOP...... {$start_str}";
        $lines[] = "ANTICIPATED END TIME......... {$end_str}";
    }

    // Add scope if available
    if (!empty($program['scope_json'])) {
        $scope = is_string($program['scope_json']) ? json_decode($program['scope_json'], true) : $program['scope_json'];
        if ($scope) {
            // Add scope lines as appropriate
        }
    }

    $lines[] = "";
    $lines[] = "USER UPDATES MUST BE RECEIVED BY: {$deadline_str}";
    $lines[] = "";
    $lines[] = "JO/DCC";

    return implode("\n", $lines);
}

/**
 * Get facility emoji mappings
 */
function get_facility_emojis() {
    return [
        'ZAB' => '🇦', 'ZAN' => '🇬', 'ZAU' => '🇺', 'ZBW' => '🇧',
        'ZDC' => '🇩', 'ZDV' => '🇻', 'ZFW' => '🇫', 'ZHN' => '🇭',
        'ZHU' => '🇼', 'ZID' => '🇮', 'ZJX' => '🇯', 'ZKC' => '🇰',
        'ZLA' => '🇱', 'ZLC' => '🇨', 'ZMA' => '🇲', 'ZME' => '🇪',
        'ZMP' => '🇵', 'ZNY' => '🇳', 'ZOA' => '🇴', 'ZOB' => '🇷',
        'ZSE' => '🇸', 'ZTL' => '🇹'
    ];
}

/**
 * Log coordination action to tmi_program_coordination_log
 */
function log_coordination_action($conn, $program_id, $proposal_id, $action_type, $data = []) {
    $sql = "INSERT INTO dbo.tmi_program_coordination_log (
                program_id, proposal_id, action_type, action_data_json,
                performed_by, performed_by_name
            ) VALUES (?, ?, ?, ?, ?, ?)";

    $params = [
        $program_id,
        $proposal_id,
        $action_type,
        json_encode($data),
        $data['user_cid'] ?? null,
        $data['user_name'] ?? null
    ];

    execute_query($conn, $sql, $params);
}
