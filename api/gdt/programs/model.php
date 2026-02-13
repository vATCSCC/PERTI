<?php
/**
 * GDT Programs - Model API
 * 
 * POST /api/gdt/programs/model.php
 * 
 * Models a Ground Stop by identifying affected flights.
 * Backward-compatible with api/tmi/gs/model.php interface.
 * 
 * Request body:
 * {
 *   "program_id": 1,                     // Required: program to model
 *   "dep_facilities": "ZNY ZDC ZBW ZOB", // Required: space-delimited ARTCCs (from tier expansion)
 *   "performed_by": "username"           // Optional: user performing the action
 * }
 * 
 * Response:
 * {
 *   "status": "ok",
 *   "message": "Ground Stop modeled: X controlled, Y exempt",
 *   "data": {
 *     "program": { ... updated program with metrics ... },
 *     "flights": [ ... affected flights ... ],
 *     "summary": {
 *       "total_flights": 10,
 *       "controlled": 7,
 *       "exempt": 3,
 *       "airborne": 2
 *     }
 *   }
 * }
 * 
 * @version 1.0.0
 * @date 2026-01-26
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
$conn_tmi = gdt_get_conn_tmi();  // For program data
$conn_adl = gdt_get_conn_adl();  // For flight data

// Validate required fields
$program_id = isset($payload['program_id']) ? (int)$payload['program_id'] : 0;
$dep_facilities = isset($payload['dep_facilities']) ? trim($payload['dep_facilities']) : '';
$performed_by = $auth_cid;

if ($program_id <= 0) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'program_id is required and must be a positive integer.'
    ]);
}

if ($dep_facilities === '') {
    respond_json(400, [
        'status' => 'error',
        'message' => 'dep_facilities is required (space-delimited ARTCC codes).'
    ]);
}

// Fetch program from TMI
$program = get_program($conn_tmi, $program_id);

if ($program === null) {
    respond_json(404, [
        'status' => 'error',
        'message' => 'Program not found.'
    ]);
}

// Parse dep_facilities into array
$facilities = preg_split('/[\s,]+/', strtoupper($dep_facilities), -1, PREG_SPLIT_NO_EMPTY);
if (empty($facilities)) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'No valid departure facilities provided.'
    ]);
}

// Build query for flights from ADL
$ctl_element = $program['ctl_element'];
$start_utc = $program['start_utc'];
$end_utc = $program['end_utc'];
$exempt_airborne = isset($program['exempt_airborne']) ? (bool)$program['exempt_airborne'] : true;
$exempt_within_min = isset($program['exempt_within_min']) ? (int)$program['exempt_within_min'] : 45;

// Build facility placeholders
$placeholders = implode(',', array_fill(0, count($facilities), '?'));

// Query flights destined for this airport, departing from scope facilities
// during the GS time window
$sql = "
    SELECT
        flight_uid,
        callsign,
        fp_dept_icao AS dep,
        fp_dest_icao AS arr,
        fp_dept_artcc AS dep_artcc,
        fp_dest_artcc AS arr_artcc,
        aircraft_icao,
        COALESCE(etd_runway_utc, etd_utc, std_utc) AS etd_runway_utc,
        COALESCE(eta_runway_utc, eta_utc) AS eta_runway_utc,
        DATEDIFF(SECOND, '1970-01-01', COALESCE(etd_runway_utc, etd_utc, std_utc)) AS etd_epoch,
        DATEDIFF(SECOND, '1970-01-01', COALESCE(eta_runway_utc, eta_utc)) AS eta_epoch,
        phase,
        gs_flag,
        CASE
            WHEN phase IN ('departed', 'enroute', 'descending') THEN 1
            ELSE 0
        END AS is_airborne
    FROM dbo.vw_adl_flights
    WHERE fp_dest_icao = ?
    AND fp_dept_artcc IN ({$placeholders})
    AND (
        (COALESCE(etd_runway_utc, etd_utc, std_utc) >= ? AND COALESCE(etd_runway_utc, etd_utc, std_utc) <= ?)
        OR (COALESCE(eta_runway_utc, eta_utc) >= ? AND COALESCE(eta_runway_utc, eta_utc) <= ?)
    )
    AND (phase IS NULL OR phase NOT IN ('arrived'))
    ORDER BY COALESCE(eta_runway_utc, eta_utc) ASC
";

$params = array_merge(
    [$ctl_element],
    $facilities,
    [$start_utc, $end_utc, $start_utc, $end_utc]
);

$flights_result = fetch_all($conn_adl, $sql, $params);

if (!$flights_result['success']) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to query flights',
        'errors' => $flights_result['error']
    ]);
}

$flights = $flights_result['data'];

// Apply exemption logic
$now_utc = new DateTime('now', new DateTimeZone('UTC'));
$controlled = [];
$exempt = [];
$airborne = [];

foreach ($flights as &$flight) {
    $flight['ctl_exempt'] = 0;
    $flight['exempt_reason'] = null;
    
    // Check if airborne
    $is_airborne = (bool)($flight['is_airborne'] ?? 0);
    if ($is_airborne) {
        if ($exempt_airborne) {
            $flight['ctl_exempt'] = 1;
            $flight['exempt_reason'] = 'AIRBORNE';
            $airborne[] = $flight;
            $exempt[] = $flight;
            continue;
        }
    }
    
    // Check departing-within exemption
    if ($exempt_within_min > 0 && !empty($flight['etd_runway_utc'])) {
        $etd = $flight['etd_runway_utc'];
        if ($etd instanceof DateTime) {
            $diff_min = ($etd->getTimestamp() - $now_utc->getTimestamp()) / 60;
            if ($diff_min <= $exempt_within_min && $diff_min >= 0) {
                $flight['ctl_exempt'] = 1;
                $flight['exempt_reason'] = 'DEPARTING_SOON';
                $exempt[] = $flight;
                continue;
            }
        }
    }
    
    // Not exempt - controlled
    $controlled[] = $flight;
}

// Build summary
$summary = [
    'total_flights' => count($flights),
    'controlled' => count($controlled),
    'exempt' => count($exempt),
    'airborne' => count($airborne)
];

// Update program with metrics
$update_sql = "
    UPDATE dbo.tmi_programs SET
        total_flights = ?,
        controlled_flights = ?,
        exempt_flights = ?,
        airborne_flights = ?,
        model_time_utc = SYSUTCDATETIME(),
        modified_utc = SYSUTCDATETIME(),
        modified_by = ?,
        updated_at = SYSUTCDATETIME()
    WHERE program_id = ?
";

$update_params = [
    $summary['total_flights'],
    $summary['controlled'],
    $summary['exempt'],
    $summary['airborne'],
    $performed_by,
    $program_id
];

$stmt = sqlsrv_query($conn_tmi, $update_sql, $update_params);
if ($stmt === false) {
    // Log but don't fail
    error_log("Failed to update program metrics: " . json_encode(sqlsrv_errors()));
} else {
    sqlsrv_free_stmt($stmt);
}

// Refresh program data
$program = get_program($conn_tmi, $program_id) ?? $program;

respond_json(200, [
    'status' => 'ok',
    'message' => "Ground Stop modeled: {$summary['controlled']} controlled, {$summary['exempt']} exempt",
    'data' => [
        'program' => $program,
        'flights' => $flights,
        'summary' => $summary,
        'server_utc' => $now_utc->format('Y-m-d\TH:i:s\Z')
    ]
]);
