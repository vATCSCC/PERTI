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
 *   "coordination_mode": "STANDARD",      // STANDARD (45min), EXPEDITED (15min)
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
 *   "message": "Proposal submitted",
 *   "data": {
 *     "program_id": 123,
 *     "proposal_id": 456,
 *     "advisory_number": "ADVZY 001",
 *     "coordination_deadline_utc": "2026-01-21T15:00:00Z",
 *     "is_immediate": false,
 *     "discord_posted": true
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

define('DISCORD_COORDINATION_CHANNEL', '1466013550450577491');
define('DENY_EMOJI', "\u{274C}");
define('DENY_EMOJI_ALT', "\u{1F6AB}");

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

// This endpoint is only for coordination submissions.
// Direct activation must use publish.php with dcc_override=true.
if ($coordination_mode === 'IMMEDIATE') {
    respond_json(400, [
        'status' => 'error',
        'message' => 'IMMEDIATE mode is not allowed on submit_proposal.php. Use publish.php with dcc_override=true.'
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
    'EXPEDITED' => 15
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

// Post proposal to Discord coordination thread and store Discord IDs.
$discord_posted = false;
$discord_error = null;
$discord_info = null;

try {
    $discord_result = post_program_coordination_to_discord(
        $proposal_id,
        $entry_type,
        $program,
        $advisory_text,
        $deadline,
        $facilities,
        $user_name
    );

    if ($discord_result && !empty($discord_result['id'])) {
        if (!empty($discord_result['thread_id']) && !empty($discord_result['thread_message_id'])) {
            $discord_channel_id = $discord_result['thread_id'];
            $discord_message_id = $discord_result['thread_message_id'];
        } else {
            $discord_channel_id = DISCORD_COORDINATION_CHANNEL;
            $discord_message_id = $discord_result['id'];
        }

        $update_discord_sql = "UPDATE dbo.tmi_proposals SET
                                   discord_channel_id = ?,
                                   discord_message_id = ?,
                                   discord_posted_at = SYSUTCDATETIME(),
                                   updated_at = SYSUTCDATETIME()
                               WHERE proposal_id = ?";
        execute_query($conn_tmi, $update_discord_sql, [$discord_channel_id, $discord_message_id, $proposal_id]);

        $discord_posted = true;
        $discord_info = [
            'channel_id' => $discord_channel_id,
            'message_id' => $discord_message_id,
            'thread_id' => $discord_result['thread_id'] ?? null,
        ];
    } else {
        $discord_error = $discord_result['error'] ?? 'Failed to post proposal to Discord coordination thread';
    }
} catch (Throwable $discord_ex) {
    $discord_error = $discord_ex->getMessage();
}

// Log the action
log_coordination_action($conn_tmi, $program_id, $proposal_id, 'PROPOSAL_SUBMITTED', [
    'coordination_mode' => $coordination_mode,
    'deadline_minutes' => $deadline_minutes,
    'facilities' => $facilities,
    'advisory_number' => $advisory_number,
    'discord_posted' => $discord_posted,
    'discord_error' => $discord_error,
    'user_cid' => $user_cid,
    'user_name' => $user_name
]);

// Get updated program
$program = get_program($conn_tmi, $program_id);

respond_json(200, [
    'status' => 'ok',
    'message' => $discord_posted
        ? 'Proposal submitted for coordination'
        : 'Proposal submitted for coordination (Discord post failed)',
    'data' => [
        'program_id' => $program_id,
        'proposal_id' => $proposal_id,
        'proposal_guid' => $proposal_guid,
        'advisory_number' => $advisory_number,
        'coordination_deadline_utc' => $deadline->format('Y-m-d\TH:i:s') . 'Z',
        'advisory_text' => $advisory_text,
        'facilities' => $facilities,
        'is_immediate' => false,
        'discord_posted' => $discord_posted,
        'discord_error' => $discord_error,
        'discord' => $discord_info,
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
        'ZAB' => "\u{1F1E6}", // A
        'ZAN' => "\u{1F1EC}", // G
        'ZAU' => "\u{1F1FA}", // U
        'ZBW' => "\u{1F1E7}", // B
        'ZDC' => "\u{1F1E9}", // D
        'ZDV' => "\u{1F1FB}", // V
        'ZFW' => "\u{1F1EB}", // F
        'ZHN' => "\u{1F1ED}", // H
        'ZHU' => "\u{1F1FC}", // W
        'ZID' => "\u{1F1EE}", // I
        'ZJX' => "\u{1F1EF}", // J
        'ZKC' => "\u{1F1F0}", // K
        'ZLA' => "\u{1F1F1}", // L
        'ZLC' => "\u{1F1E8}", // C
        'ZMA' => "\u{1F1F2}", // M
        'ZME' => "\u{1F1EA}", // E
        'ZMP' => "\u{1F1F5}", // P
        'ZNY' => "\u{1F1F3}", // N
        'ZOA' => "\u{1F1F4}", // O
        'ZOB' => "\u{1F1F7}", // R
        'ZSE' => "\u{1F1F8}", // S
        'ZTL' => "\u{1F1F9}", // T
        'CZEG' => "1\u{FE0F}\u{20E3}",
        'CZVR' => "2\u{FE0F}\u{20E3}",
        'CZWG' => "3\u{FE0F}\u{20E3}",
        'CZYZ' => "4\u{FE0F}\u{20E3}",
        'CZQM' => "5\u{FE0F}\u{20E3}",
        'CZQX' => "6\u{FE0F}\u{20E3}",
        'CZQO' => "7\u{FE0F}\u{20E3}",
        'CZUL' => "8\u{FE0F}\u{20E3}",
    ];
}

/**
 * Post a GS/GDP coordination proposal to Discord and create a thread.
 */
function post_program_coordination_to_discord($proposal_id, $entry_type, $program, $advisory_text, DateTime $deadline, array $facilities, $user_name) {
    $discord_api_path = __DIR__ . '/../../../load/discord/DiscordAPI.php';
    if (!file_exists($discord_api_path)) {
        return ['error' => 'Discord API not available'];
    }

    require_once $discord_api_path;
    $discord = new DiscordAPI();
    if (!$discord->isConfigured()) {
        return ['error' => 'Discord bot not configured'];
    }

    $thread_title = build_program_coordination_thread_title($proposal_id, $entry_type, $program, $deadline, $facilities);

    $starter = $discord->createMessage(DISCORD_COORDINATION_CHANNEL, [
        'content' => "**{$thread_title}**\n_Click thread to review and react_"
    ]);
    if (!$starter || empty($starter['id'])) {
        return ['error' => $discord->getLastError() ?: 'Failed to post starter message'];
    }

    $thread = $discord->createThreadFromMessage(
        DISCORD_COORDINATION_CHANNEL,
        $starter['id'],
        $thread_title,
        1440
    );
    if (!$thread || empty($thread['id'])) {
        $starter['error'] = $discord->getLastError() ?: 'Failed to create thread';
        return $starter;
    }

    $thread_id = $thread['id'];
    $starter['thread_id'] = $thread_id;

    $details = format_program_coordination_message($proposal_id, $entry_type, $program, $deadline, $facilities, $user_name);
    $thread_message = $discord->sendMessageToThread($thread_id, ['content' => $details]);
    if ($thread_message && !empty($thread_message['id'])) {
        $starter['thread_message_id'] = $thread_message['id'];
        add_program_reactions($discord, $thread_id, $thread_message['id'], $facilities);
    } else {
        $starter['error'] = $discord->getLastError() ?: 'Failed to post details in thread';
    }

    if (!empty($advisory_text)) {
        post_long_codeblock_to_thread($discord, $thread_id, 'Full Proposed Advisory', $advisory_text);
    }

    return $starter;
}

function build_program_coordination_thread_title($proposal_id, $entry_type, $program, DateTime $deadline, array $facilities) {
    $ctl = strtoupper(trim((string)($program['ctl_element'] ?? 'UNKN')));
    $due = $deadline->format('Hi') . 'Z';
    $fac_codes = [];
    foreach ($facilities as $fac) {
        $code = is_array($fac) ? ($fac['code'] ?? '') : $fac;
        $code = strtoupper(trim((string)$code));
        if ($code !== '') {
            $fac_codes[] = $code;
        }
    }
    $to = implode('/', array_slice($fac_codes, 0, 3));
    if (count($fac_codes) > 3) {
        $to .= '+';
    }

    $title = "COORD {$entry_type} {$ctl}>{$to} DUE {$due} #{$proposal_id}";
    if (strlen($title) > 100) {
        $title = "COORD {$entry_type} {$ctl} DUE {$due} #{$proposal_id}";
    }
    return substr($title, 0, 100);
}

function format_program_coordination_message($proposal_id, $entry_type, $program, DateTime $deadline, array $facilities, $user_name) {
    $entry_type = strtoupper((string)$entry_type);
    $ctl = strtoupper(trim((string)($program['ctl_element'] ?? 'UNKN')));

    $deadline_unix = $deadline->getTimestamp();
    $start_unix = !empty($program['start_utc']) ? strtotime((string)$program['start_utc']) : false;
    $end_unix = !empty($program['end_utc']) ? strtotime((string)$program['end_utc']) : false;

    $valid_str = 'TBD';
    if ($start_unix && $end_unix) {
        $valid_str = "<t:{$start_unix}:f> -> <t:{$end_unix}:f>";
    } elseif ($start_unix) {
        $valid_str = "<t:{$start_unix}:f> -> TBD";
    }

    $lines = [
        'ATCSCC COORDINATION TELEX',
        'STATUS: PROPOSED (NOT ACTIVE)',
        "ID: #{$proposal_id}",
        "TYPE: {$entry_type}",
        "CTL: {$ctl}",
        "PROPOSED BY: {$user_name}",
        "DUE UTC: <t:{$deadline_unix}:F> (<t:{$deadline_unix}:R>)",
        "VALID UTC: {$valid_str}",
    ];

    if ($entry_type === 'GDP') {
        $rate = $program['program_rate'] ?? null;
        $avg = $program['avg_delay_min'] ?? null;
        $max = $program['max_delay_min'] ?? null;
        if ($rate !== null && $rate !== '') {
            $lines[] = 'RATE: ' . $rate . '/HR';
        }
        if ($avg !== null && $avg !== '') {
            $lines[] = 'AVG DELAY: ' . round((float)$avg) . ' MIN';
        }
        if ($max !== null && $max !== '') {
            $lines[] = 'MAX DELAY: ' . round((float)$max) . ' MIN';
        }
    }

    $reason = strtoupper(trim((string)($program['impacting_condition'] ?? 'WEATHER')));
    $cause = strtoupper(trim((string)($program['cause_text'] ?? 'CONDITIONS')));
    $lines[] = "REASON: {$reason}/{$cause}";
    $lines[] = '';
    $lines[] = 'APPROVALS REQUIRED:';

    $emoji_map = get_facility_emojis();
    foreach ($facilities as $fac) {
        $code = is_array($fac) ? ($fac['code'] ?? '') : $fac;
        $code = strtoupper(trim((string)$code));
        if ($code === '') {
            continue;
        }
        $emoji = $emoji_map[$code] ?? '';
        if ($emoji !== '') {
            $lines[] = "{$code}: {$emoji}";
        } else {
            $lines[] = $code;
        }
    }

    $lines[] = '';
    $lines[] = 'ACTIONS:';
    $lines[] = 'APPROVE = FACILITY EMOJI';
    $lines[] = 'DENY = ' . DENY_EMOJI . ' OR ' . DENY_EMOJI_ALT;
    $lines[] = 'DCC OVERRIDE = :DCC:';

    return "```text\n" . implode("\n", $lines) . "\n```";
}

function add_program_reactions($discord, $thread_id, $message_id, array $facilities) {
    $emoji_map = get_facility_emojis();
    $used = [];

    foreach ($facilities as $fac) {
        $code = is_array($fac) ? ($fac['code'] ?? '') : $fac;
        $code = strtoupper(trim((string)$code));
        if ($code === '') {
            continue;
        }

        $emoji = $emoji_map[$code] ?? null;
        if ($emoji && !in_array($emoji, $used, true)) {
            $discord->createReaction($thread_id, $message_id, $emoji);
            $used[] = $emoji;
            usleep(120000);
        }
    }

    $discord->createReaction($thread_id, $message_id, DENY_EMOJI);
    usleep(120000);
    $discord->createReaction($thread_id, $message_id, DENY_EMOJI_ALT);
}

function split_message_for_discord($message, $max_len = 1860) {
    $message = str_replace("\r\n", "\n", (string)$message);
    if (strlen($message) <= $max_len) {
        return [$message];
    }

    $chunks = [];
    $lines = explode("\n", $message);
    $current = '';
    foreach ($lines as $line) {
        $candidate = $current . ($current === '' ? '' : "\n") . $line;
        if (strlen($candidate) <= $max_len) {
            $current = $candidate;
            continue;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        if (strlen($line) > $max_len) {
            $line_chunks = str_split($line, $max_len);
            $last_idx = count($line_chunks) - 1;
            foreach ($line_chunks as $idx => $line_chunk) {
                if ($idx < $last_idx) {
                    $chunks[] = $line_chunk;
                } else {
                    $current = $line_chunk;
                }
            }
        } else {
            $current = $line;
        }
    }

    if ($current !== '') {
        $chunks[] = $current;
    }

    return $chunks;
}

function post_long_codeblock_to_thread($discord, $thread_id, $label, $message) {
    $message = trim((string)$message);
    if ($message === '') {
        return;
    }

    $chunks = split_message_for_discord($message, 1860);
    $total = count($chunks);
    foreach ($chunks as $idx => $chunk) {
        $part = ($total > 1) ? " (Part " . ($idx + 1) . "/{$total})" : '';
        $content = "**{$label}{$part}**\n```\n{$chunk}\n```";
        $discord->sendMessageToThread($thread_id, ['content' => $content]);
        if ($idx < $total - 1) {
            usleep(120000);
        }
    }
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



