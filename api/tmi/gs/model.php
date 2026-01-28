<?php
/**
 * GS Model API
 * 
 * POST /api/tmi/gs/model.php
 * 
 * Models a Ground Stop by identifying affected flights.
 * 
 * UPDATED: 2026-01-26 - Now uses VATSIM_TMI.tmi_programs, queries flights from ADL
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
$conn_tmi = get_tmi_conn();  // For program data
$conn_adl = get_adl_conn();  // For flight data

// Validate required fields
$program_id = isset($payload['program_id']) ? (int)$payload['program_id'] : 0;
$dep_facilities = isset($payload['dep_facilities']) ? trim($payload['dep_facilities']) : '';
$performed_by = isset($payload['performed_by']) ? trim($payload['performed_by']) : null;

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
$program_result = fetch_one($conn_tmi, "SELECT * FROM dbo.tmi_programs WHERE program_id = ?", [$program_id]);

if (!$program_result['success'] || !$program_result['data']) {
    respond_json(404, [
        'status' => 'error',
        'message' => 'Program not found.'
    ]);
}

$program = $program_result['data'];

// Parse dep_facilities into array
$facilities = split_codes($dep_facilities);
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
        etd_runway_utc,
        eta_runway_utc,
        phase,
        gs_flag,
        -- Include epoch values for JavaScript compatibility
        DATEDIFF(SECOND, '1970-01-01', etd_runway_utc) AS etd_epoch,
        DATEDIFF(SECOND, '1970-01-01', eta_runway_utc) AS eta_epoch,
        CASE
            WHEN phase IN ('departed', 'enroute', 'descending') THEN 1
            ELSE 0
        END AS is_airborne
    FROM dbo.vw_adl_flights
    WHERE fp_dest_icao = ?
    AND fp_dept_artcc IN ({$placeholders})
    AND (
        (etd_runway_utc >= ? AND etd_runway_utc <= ?)
        OR (eta_runway_utc >= ? AND eta_runway_utc <= ?)
    )
    ORDER BY eta_runway_utc ASC
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
$program_result = fetch_one($conn_tmi, "SELECT * FROM dbo.tmi_programs WHERE program_id = ?", [$program_id]);
$program = $program_result['success'] ? $program_result['data'] : $program;

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
