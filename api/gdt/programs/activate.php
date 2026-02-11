<?php
/**
 * GDT Programs - Activate API
 * 
 * POST /api/gdt/programs/activate.php
 * 
 * Activates a PROPOSED or MODELING program, making it live.
 * Supersedes any other active programs for the same element.
 * 
 * Request body (JSON):
 * {
 *   "program_id": 1,           // Required: program to activate
 *   "activated_by": "username" // Optional: user activating
 * }
 * 
 * Response:
 * {
 *   "status": "ok",
 *   "message": "Program activated",
 *   "data": {
 *     "program_id": 1,
 *     "program": { ... updated program record ... }
 *   }
 * }
 * 
 * @version 1.0.0
 * @date 2026-01-21
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
    respond_json(405, [
        'status' => 'error',
        'message' => 'Method not allowed. Use POST.'
    ]);
}

$payload = read_request_payload();
$conn_tmi = gdt_get_conn_tmi();

// ============================================================================
// Validate
// ============================================================================

$program_id = isset($payload['program_id']) ? (int)$payload['program_id'] : 0;
$activated_by = $auth_cid ?: (isset($payload['activated_by']) ? trim($payload['activated_by']) : 'anonymous');

if ($program_id <= 0) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'program_id is required.'
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

// ============================================================================
// Call Stored Procedure
// ============================================================================

$sql = "EXEC dbo.sp_TMI_ActivateProgram @program_id = ?, @activated_by = ?";
$stmt = sqlsrv_query($conn_tmi, $sql, [$program_id, $activated_by]);

if ($stmt === false) {
    $errors = sqlsrv_errors();
    
    // Check for specific error messages from the SP
    $error_msg = 'Failed to activate program';
    if ($errors) {
        foreach ($errors as $e) {
            if (isset($e['message']) && strpos($e['message'], 'cannot be activated') !== false) {
                $error_msg = $e['message'];
                break;
            }
        }
    }
    
    respond_json(400, [
        'status' => 'error',
        'message' => $error_msg,
        'errors' => $errors
    ]);
}

sqlsrv_free_stmt($stmt);

// ============================================================================
// Fetch Updated Program
// ============================================================================

$program = get_program($conn_tmi, $program_id);

// ============================================================================
// Fetch Flight Control Records and Calculate Power Run Metrics
// ============================================================================

// Get flight control records for this program
$flight_sql = "
    SELECT *,
        DATEDIFF(SECOND, '1970-01-01', ctd_utc) AS ctd_epoch,
        DATEDIFF(SECOND, '1970-01-01', cta_utc) AS cta_epoch,
        DATEDIFF(SECOND, '1970-01-01', orig_etd_utc) AS orig_etd_epoch,
        DATEDIFF(SECOND, '1970-01-01', orig_eta_utc) AS orig_eta_epoch
    FROM dbo.tmi_flight_control
    WHERE program_id = ?
    ORDER BY cta_utc ASC, orig_eta_utc ASC
";
$flight_result = fetch_all($conn_tmi, $flight_sql, [$program_id]);
$flights = $flight_result['success'] ? $flight_result['data'] : [];

// Calculate power run metrics from flight control records
$controlled_count = 0;
$exempt_count = 0;
$airborne_count = 0;
$total_delay = 0;
$max_delay = 0;
$delay_count = 0;

foreach ($flights as $f) {
    if (isset($f['ctl_exempt']) && $f['ctl_exempt']) {
        $exempt_count++;
    } else {
        $controlled_count++;
    }

    // Check for airborne flights (GS held = 0 means they weren't held because they were airborne)
    if (isset($f['flight_status_at_ctl']) &&
        in_array(strtoupper($f['flight_status_at_ctl']), ['AIRBORNE', 'DEPARTED', 'ENROUTE'])) {
        $airborne_count++;
    }

    $delay = isset($f['program_delay_min']) ? (int)$f['program_delay_min'] : 0;
    if ($delay > 0) {
        $total_delay += $delay;
        if ($delay > $max_delay) {
            $max_delay = $delay;
        }
        $delay_count++;
    }
}

$avg_delay = $delay_count > 0 ? round($total_delay / $delay_count, 1) : 0;

// Build flights data response
$flights_data = [
    'flights' => $flights,
    'total' => count($flights),
    'controlled' => $controlled_count,
    'exempt' => $exempt_count,
    'airborne' => $airborne_count
];

// Build power run response
$power_run = [
    'total_delay' => $total_delay,
    'max_delay' => $max_delay,
    'avg_delay' => $avg_delay,
    'controlled' => $controlled_count,
    'exempt' => $exempt_count
];

respond_json(200, [
    'status' => 'ok',
    'message' => 'Program activated',
    'data' => [
        'program_id' => $program_id,
        'program' => $program,
        'flights' => $flights_data,
        'power_run' => $power_run
    ]
]);
