<?php
/**
 * GDT Programs - Power Run (Multi-Scenario Modeling) API
 *
 * POST /api/gdt/programs/power_run.php
 *
 * Iterates the simulate endpoint over a range of parameter values to produce
 * a comparison table. Supports sweeping over distance, data time, or rate.
 *
 * All scenarios run as dry_run=true (no persistent changes).
 *
 * Request body (JSON):
 * {
 *   "program_id": 1,
 *   "sweep_param": "distance|rate|end_time",
 *   "sweep_start": 100,         // Start value (nm for distance, flights/hr for rate, minutes offset for end_time)
 *   "sweep_end": 500,           // End value
 *   "sweep_step": 50,           // Step size
 * }
 *
 * Response:
 * {
 *   "status": "ok",
 *   "data": {
 *     "program_id": 1,
 *     "sweep_param": "distance",
 *     "scenarios": [
 *       { "param_value": 100, "summary": { ... } },
 *       { "param_value": 150, "summary": { ... } },
 *       ...
 *     ]
 *   }
 * }
 *
 * @version 1.0.0
 * @date 2026-03-29
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('GDT_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');
$auth_cid = gdt_optional_auth();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond_json(405, ['status' => 'error', 'message' => 'Method not allowed. Use POST.']);
}

$payload = read_request_payload();
$conn_tmi = gdt_get_conn_tmi();

// ============================================================================
// Validate Input
// ============================================================================

$program_id = isset($payload['program_id']) ? (int)$payload['program_id'] : 0;
$sweep_param = strtolower(trim($payload['sweep_param'] ?? ''));
$sweep_start = isset($payload['sweep_start']) ? (int)$payload['sweep_start'] : 0;
$sweep_end = isset($payload['sweep_end']) ? (int)$payload['sweep_end'] : 0;
$sweep_step = isset($payload['sweep_step']) ? (int)$payload['sweep_step'] : 0;

if ($program_id <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'program_id is required']);
}

if (!in_array($sweep_param, ['distance', 'rate', 'end_time'])) {
    respond_json(400, ['status' => 'error', 'message' => 'sweep_param must be distance, rate, or end_time']);
}

if ($sweep_step <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'sweep_step must be > 0']);
}

if ($sweep_start > $sweep_end) {
    respond_json(400, ['status' => 'error', 'message' => 'sweep_start must be <= sweep_end']);
}

// Cap iterations to prevent abuse
$iterations = ($sweep_end - $sweep_start) / $sweep_step + 1;
if ($iterations > 20) {
    respond_json(400, ['status' => 'error', 'message' => 'Too many iterations (max 20). Increase sweep_step or narrow range.']);
}

// Validate program exists
$program = get_program($conn_tmi, $program_id);
if (!$program) {
    respond_json(404, ['status' => 'error', 'message' => 'Program not found']);
}

$program_type = $program['program_type'] ?? '';
if ($program_type === 'GS') {
    respond_json(400, ['status' => 'error', 'message' => 'Power Run not applicable to Ground Stop programs']);
}

// ============================================================================
// Run Scenarios
// ============================================================================

$scenarios = [];
$base_url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' .
    ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/api/gdt/programs/simulate.php';

for ($val = $sweep_start; $val <= $sweep_end; $val += $sweep_step) {
    // Build the simulate payload for this scenario
    $sim_payload = [
        'program_id' => $program_id,
        'dry_run' => true,
    ];

    switch ($sweep_param) {
        case 'distance':
            $sim_payload['scope'] = ['distance_nm' => $val];
            break;
        case 'rate':
            $sim_payload['what_if_rate'] = $val;
            break;
        case 'end_time':
            // val = minutes to add to current program end_utc
            $end = $program['end_utc'];
            $endStr = ($end instanceof DateTimeInterface) ? $end->format('Y-m-d H:i:s') : (string)$end;
            $newEnd = date('Y-m-d\TH:i:s\Z', strtotime($endStr) + ($val * 60));
            $sim_payload['what_if_end_utc'] = $newEnd;
            break;
    }

    // Internal call to simulate logic
    // Use cURL to call our own simulate endpoint (keeps code DRY)
    $ch = curl_init($base_url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($sim_payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Cookie: ' . ($_SERVER['HTTP_COOKIE'] ?? ''),
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($response, true);

    if ($httpCode === 200 && isset($result['data']['summary'])) {
        $scenarios[] = [
            'param_value' => $val,
            'param_label' => formatParamLabel($sweep_param, $val),
            'summary' => $result['data']['summary'],
            'slot_count' => $result['data']['slot_count'] ?? 0,
            'assigned_count' => $result['data']['assigned_count'] ?? 0,
            'exempt_count' => $result['data']['exempt_count'] ?? 0,
        ];
    } else {
        $scenarios[] = [
            'param_value' => $val,
            'param_label' => formatParamLabel($sweep_param, $val),
            'error' => $result['message'] ?? 'Simulation failed',
        ];
    }
}

// ============================================================================
// Response
// ============================================================================

respond_json(200, [
    'status' => 'ok',
    'message' => 'Power Run complete: ' . count($scenarios) . ' scenarios evaluated',
    'data' => [
        'program_id' => $program_id,
        'sweep_param' => $sweep_param,
        'sweep_start' => $sweep_start,
        'sweep_end' => $sweep_end,
        'sweep_step' => $sweep_step,
        'scenarios' => $scenarios,
    ]
]);

function formatParamLabel($param, $val) {
    switch ($param) {
        case 'distance': return $val . ' nm';
        case 'rate': return $val . '/hr';
        case 'end_time': return '+' . $val . ' min';
        default: return (string)$val;
    }
}
