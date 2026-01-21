<?php
/**
 * GS Activate API
 *
 * POST /api/tmi/gs/activate.php
 *
 * Activates a proposed Ground Stop (issues EDCTs).
 * Calls sp_GS_IssueEDCTs stored procedure.
 *
 * Returns complete activation results including:
 * - Program details
 * - Full flight list with EDCTs
 * - Power run statistics (total/max/avg delay)
 * - Formatted advisory text
 *
 * Also publishes the GS to VATSWIM API if enabled.
 *
 * Request body:
 * {
 *   "program_id": 1,              // Required: program to activate
 *   "activated_by": "username",   // Optional: user activating the program
 *   "publish_swim": true,         // Optional: publish to VATSWIM (default: true)
 *   "publish_discord": true       // Optional: post to Discord (default: true)
 * }
 *
 * Response:
 * {
 *   "status": "ok",
 *   "message": "Ground Stop activated",
 *   "data": {
 *     "program": { ... updated program ... },
 *     "flights": { flights: [...], total: N, controlled: N, exempt: N, airborne: N },
 *     "power_run": { total_delay: N, max_delay: N, avg_delay: N },
 *     "advisory": { text: "...", discord: "```...```" },
 *     "swim_published": true
 *   }
 * }
 *
 * @version 2.0.0 - Added full results, advisory text, VATSWIM publishing
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('GS_API_INCLUDED', true);
require_once(__DIR__ . '/common.php');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond_json(405, [
        'status' => 'error',
        'message' => 'Method not allowed. Use POST.'
    ]);
}

$payload = read_request_payload();
$conn = get_adl_conn();

// Validate required fields
$program_id = isset($payload['program_id']) ? (int)$payload['program_id'] : 0;
$activated_by = isset($payload['activated_by']) ? trim($payload['activated_by']) : null;

if ($program_id <= 0) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'program_id is required and must be a positive integer.'
    ]);
}

// Verify program exists and is in PROPOSED state
$check_result = fetch_one($conn, 
    "SELECT program_id, status, program_type FROM dbo.ntml WHERE program_id = ?", 
    [$program_id]
);

if (!$check_result['success'] || !$check_result['data']) {
    respond_json(404, [
        'status' => 'error',
        'message' => 'Program not found.'
    ]);
}

$current = $check_result['data'];

if ($current['program_type'] !== 'GS') {
    respond_json(400, [
        'status' => 'error',
        'message' => "This endpoint is for Ground Stops only. Program type is: {$current['program_type']}"
    ]);
}

if ($current['status'] !== 'PROPOSED') {
    respond_json(400, [
        'status' => 'error',
        'message' => "Program must be in PROPOSED state to activate. Current status: {$current['status']}"
    ]);
}

// Call the stored procedure
$sql = "EXEC dbo.sp_GS_IssueEDCTs @program_id = ?, @activated_by = ?";
$params = [$program_id, $activated_by];

$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt === false) {
    $errors = sqlsrv_errors();
    $error_msg = 'Failed to activate Ground Stop';
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

// Fetch updated program with full details
$program_result = fetch_one($conn, "
    SELECT n.*, a.ARPT_NAME as airport_name, a.RESP_ARTCC_ID as artcc
    FROM dbo.ntml n
    LEFT JOIN dbo.apts a ON n.ctl_element = a.ICAO_ID
    WHERE n.program_id = ?
", [$program_id]);

$program = $program_result['success'] ? $program_result['data'] : null;

// Get options from payload
$publish_swim = isset($payload['publish_swim']) ? (bool)$payload['publish_swim'] : true;
$publish_discord = isset($payload['publish_discord']) ? (bool)$payload['publish_discord'] : true;

// ============================================================================
// FETCH FULL FLIGHT LIST
// ============================================================================
$flights_sql = "EXEC dbo.sp_GS_GetFlights @program_id = ?, @include_exempt = 1, @include_airborne = 1";
$flights_result = fetch_all($conn, $flights_sql, [$program_id]);
$flights = $flights_result['success'] ? $flights_result['data'] : [];

// Calculate flight statistics
$controlled = 0;
$exempt = 0;
$airborne = 0;
$total_delay = 0;
$max_delay = 0;
$delay_count = 0;

foreach ($flights as $f) {
    if (!empty($f['gs_held']) || !empty($f['ctl_held'])) {
        $controlled++;
    }
    if (!empty($f['ctl_exempt'])) {
        $exempt++;
    }
    if (!empty($f['is_airborne'])) {
        $airborne++;
    }

    // Calculate delay from program_delay_min or absolute_delay_min
    $delay_min = (int)($f['program_delay_min'] ?? $f['absolute_delay_min'] ?? 0);
    if ($delay_min > 0) {
        $total_delay += $delay_min;
        $max_delay = max($max_delay, $delay_min);
        $delay_count++;
    }
}

$avg_delay = $delay_count > 0 ? round($total_delay / $delay_count) : 0;

// ============================================================================
// POWER RUN STATISTICS
// ============================================================================
$power_run = [
    'total_delay' => $total_delay,
    'max_delay' => $max_delay,
    'avg_delay' => $avg_delay,
    'controlled_flights' => $controlled,
    'exempt_flights' => $exempt,
    'airborne_flights' => $airborne,
    'total_flights' => count($flights)
];

// ============================================================================
// GENERATE ADVISORY TEXT
// ============================================================================
$advisory_data = [
    'advisory_number' => $program['adv_number'] ?? '001',
    'ctl_element' => $program['ctl_element'],
    'artcc' => $program['artcc'] ?? 'ZXX',
    'issue_date' => date('Y-m-d H:i:s'),
    'adl_time' => date('Y-m-d H:i:s'),
    'start_utc' => $program['start_utc'],
    'end_utc' => $program['end_utc'],
    'cumulative_start' => $program['cumulative_start'],
    'cumulative_end' => $program['cumulative_end'],
    'flt_incl_carrier' => $program['flt_incl_carrier'],
    'flt_incl_type' => $program['flt_incl_type'],
    'dep_facilities' => parseJsonField($program, 'scope_json', 'dep_facilities'),
    'dep_scope' => parseJsonField($program, 'scope_json', 'scope_tier'),
    'curr_total_delay' => 0,
    'curr_max_delay' => 0,
    'curr_avg_delay' => 0,
    'prev_total_delay' => 0,
    'prev_max_delay' => 0,
    'prev_avg_delay' => 0,
    'new_total_delay' => $total_delay,
    'new_max_delay' => $max_delay,
    'new_avg_delay' => $avg_delay,
    'prob_extension' => $program['prob_extension'] ?? 'MEDIUM',
    'impacting_condition' => $program['impacting_condition'] ?? 'WEATHER',
    'condition_text' => $program['cause_text'],
    'comments' => $program['comments']
];

// Generate plain text advisory
$advisory_text = null;
$advisory_discord = null;
$discord_posted = false;

try {
    require_once(__DIR__ . '/../../../load/discord/TMIDiscord.php');

    // Create TMIDiscord instance with null channels for formatting only
    $tmiDiscord = new TMIDiscord([]);

    // Use reflection to call the private formatting method
    $reflection = new ReflectionClass($tmiDiscord);
    $method = $reflection->getMethod('formatGroundStopAdvisory');
    $method->setAccessible(true);
    $advisory_text = $method->invoke($tmiDiscord, $advisory_data);
    $advisory_discord = "```\n{$advisory_text}\n```";

    // Post to Discord if enabled
    if ($publish_discord && !empty($advisory_text)) {
        try {
            // Create new instance with actual channel config
            global $discord_channels;
            if (!empty($discord_channels)) {
                $tmiDiscordPost = new TMIDiscord($discord_channels);
                $result = $tmiDiscordPost->postGroundStopAdvisory($advisory_data, 'advzy_staging');
                $discord_posted = ($result !== null);
            }
        } catch (Exception $discordEx) {
            // Log but don't fail - Discord posting is optional
            error_log("GS Activate: Discord post failed - " . $discordEx->getMessage());
        }
    }
} catch (Exception $advEx) {
    // Log advisory generation error but don't fail activation
    error_log("GS Activate: Advisory generation failed - " . $advEx->getMessage());
}

// ============================================================================
// PUBLISH TO VATSWIM
// ============================================================================
$swim_published = false;

if ($publish_swim) {
    try {
        // Publish to VATSWIM via internal API call or direct database update
        $swim_data = [
            'type' => 'ground_stop',
            'program_id' => $program_id,
            'airport' => $program['ctl_element'],
            'airport_name' => $program['airport_name'],
            'artcc' => $program['artcc'],
            'name' => $program['program_name'] ?? 'CDM GROUND STOP',
            'reason' => $program['impacting_condition'],
            'probability_of_extension' => $program['prob_extension'],
            'times' => [
                'start' => formatDateTimeForSwim($program['start_utc']),
                'end' => formatDateTimeForSwim($program['end_utc'])
            ],
            'delays' => [
                'total_minutes' => $total_delay,
                'average_minutes' => $avg_delay,
                'maximum_minutes' => $max_delay
            ],
            'flights' => [
                'total' => count($flights),
                'controlled' => $controlled,
                'exempt' => $exempt,
                'airborne' => $airborne
            ],
            'advisory' => [
                'number' => $program['adv_number'],
                'text' => $advisory_text
            ],
            'status' => 'ACTIVE',
            'is_active' => true,
            'activated_utc' => date('c')
        ];

        // Store in ntml for SWIM API to query
        // The /tmi/programs endpoint will read from dbo.ntml
        $swim_published = true;

        // Log SWIM publishing event
        $event_sql = "
            INSERT INTO dbo.ntml_info (program_id, event_type, event_details_json, performed_utc, performed_by)
            VALUES (?, 'SWIM_PUBLISHED', ?, SYSUTCDATETIME(), ?)
        ";
        sqlsrv_query($conn, $event_sql, [
            $program_id,
            json_encode($swim_data),
            $activated_by ?? 'SYSTEM'
        ]);

    } catch (Exception $swimEx) {
        error_log("GS Activate: SWIM publish failed - " . $swimEx->getMessage());
    }
}

// ============================================================================
// BUILD RESPONSE
// ============================================================================
respond_json(200, [
    'status' => 'ok',
    'message' => 'Ground Stop activated',
    'data' => [
        'program' => $program,
        'flights' => [
            'flights' => $flights,
            'total' => count($flights),
            'controlled' => $controlled,
            'exempt' => $exempt,
            'airborne' => $airborne,
            'max_delay' => $max_delay,
            'avg_delay' => $avg_delay,
            'total_delay' => $total_delay
        ],
        'power_run' => $power_run,
        'advisory' => [
            'text' => $advisory_text,
            'discord' => $advisory_discord
        ],
        'swim_published' => $swim_published,
        'discord_posted' => $discord_posted,
        'server_utc' => get_server_utc($conn)
    ]
]);

/**
 * Parse a JSON field from program data
 */
function parseJsonField($program, $jsonField, $key) {
    if (empty($program[$jsonField])) return null;

    $data = json_decode($program[$jsonField], true);
    if ($data === null) return null;

    return $data[$key] ?? null;
}

/**
 * Format DateTime for SWIM API
 */
function formatDateTimeForSwim($dt) {
    if ($dt === null) return null;
    if ($dt instanceof DateTimeInterface) {
        return $dt->format('c');
    }
    return $dt;
}
