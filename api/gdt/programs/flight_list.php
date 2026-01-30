<?php
/**
 * GDT Programs - Flight List API
 *
 * GET /api/gdt/programs/flight_list.php?program_id=123
 *
 * Returns the dynamic flight list for a GS/GDP program.
 * Supports filtering by compliance status and includes statistics.
 *
 * Query Parameters:
 *   program_id      - Required: program to get flight list for
 *   status          - Optional: filter by compliance_status (PENDING, COMPLIANT, NON_COMPLIANT, EXEMPT)
 *   include_stats   - Optional: include summary statistics (default true)
 *   limit           - Optional: max flights to return (default 500)
 *   offset          - Optional: pagination offset (default 0)
 *
 * Response:
 * {
 *   "status": "ok",
 *   "data": {
 *     "program_id": 123,
 *     "flights": [...],
 *     "stats": {
 *       "total": 145,
 *       "controlled": 120,
 *       "exempt": 25,
 *       "pending": 90,
 *       "compliant": 25,
 *       "non_compliant": 5,
 *       "avg_delay_min": 47,
 *       "max_delay_min": 123
 *     },
 *     "generated_at": "2026-01-21T15:00:00Z"
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

// Allow GET only
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond_json(405, [
        'status' => 'error',
        'message' => 'Method not allowed. Use GET.'
    ]);
}

$conn_tmi = gdt_get_conn_tmi();

// ============================================================================
// Parse Query Parameters
// ============================================================================

$program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$status_filter = isset($_GET['status']) ? strtoupper(trim($_GET['status'])) : null;
$include_stats = !isset($_GET['include_stats']) || filter_var($_GET['include_stats'], FILTER_VALIDATE_BOOLEAN);
$limit = isset($_GET['limit']) ? min(1000, max(1, (int)$_GET['limit'])) : 500;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

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
// Build Query
// ============================================================================

$sql = "SELECT
            fl.list_id,
            fl.flight_gufi,
            fl.callsign,
            fl.flight_uid,
            fl.dep_airport,
            fl.arr_airport,
            fl.aircraft_type,
            fl.original_etd_utc,
            fl.original_eta_utc,
            fl.edct_utc,
            fl.cta_utc,
            fl.delay_minutes,
            fl.slot_id,
            fl.slot_time_utc,
            fl.is_exempt,
            fl.exemption_code,
            fl.exemption_reason,
            fl.compliance_status,
            fl.actual_departure_utc,
            fl.actual_arrival_utc,
            fl.compliance_delta_min,
            fl.flight_status,
            fl.added_at,
            fl.updated_at
        FROM dbo.tmi_flight_list fl
        WHERE fl.program_id = ?";

$params = [$program_id];

// Apply status filter
if ($status_filter) {
    $valid_statuses = ['PENDING', 'COMPLIANT', 'NON_COMPLIANT', 'EXEMPT', 'CANCELLED'];
    if (in_array($status_filter, $valid_statuses)) {
        $sql .= " AND fl.compliance_status = ?";
        $params[] = $status_filter;
    }
}

$sql .= " ORDER BY fl.edct_utc ASC, fl.original_etd_utc ASC";
$sql .= " OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";
$params[] = $offset;
$params[] = $limit;

// Execute query
$result = fetch_all($conn_tmi, $sql, $params);

if (!$result['success']) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to fetch flight list',
        'errors' => $result['error']
    ]);
}

$flights = $result['data'];

// ============================================================================
// Calculate Statistics
// ============================================================================

$stats = null;

if ($include_stats) {
    $stats_sql = "SELECT
                      COUNT(*) AS total,
                      SUM(CASE WHEN is_exempt = 0 THEN 1 ELSE 0 END) AS controlled,
                      SUM(CASE WHEN is_exempt = 1 THEN 1 ELSE 0 END) AS exempt,
                      SUM(CASE WHEN compliance_status = 'PENDING' THEN 1 ELSE 0 END) AS pending,
                      SUM(CASE WHEN compliance_status = 'COMPLIANT' THEN 1 ELSE 0 END) AS compliant,
                      SUM(CASE WHEN compliance_status = 'NON_COMPLIANT' THEN 1 ELSE 0 END) AS non_compliant,
                      SUM(CASE WHEN compliance_status = 'CANCELLED' THEN 1 ELSE 0 END) AS cancelled,
                      AVG(CASE WHEN delay_minutes > 0 THEN delay_minutes ELSE NULL END) AS avg_delay_min,
                      MAX(delay_minutes) AS max_delay_min,
                      SUM(CASE WHEN delay_minutes > 0 THEN delay_minutes ELSE 0 END) AS total_delay_min
                  FROM dbo.tmi_flight_list
                  WHERE program_id = ?";

    $stats_result = fetch_one($conn_tmi, $stats_sql, [$program_id]);

    if ($stats_result['success'] && $stats_result['data']) {
        $stats = [
            'total' => (int)($stats_result['data']['total'] ?? 0),
            'controlled' => (int)($stats_result['data']['controlled'] ?? 0),
            'exempt' => (int)($stats_result['data']['exempt'] ?? 0),
            'pending' => (int)($stats_result['data']['pending'] ?? 0),
            'compliant' => (int)($stats_result['data']['compliant'] ?? 0),
            'non_compliant' => (int)($stats_result['data']['non_compliant'] ?? 0),
            'cancelled' => (int)($stats_result['data']['cancelled'] ?? 0),
            'avg_delay_min' => round((float)($stats_result['data']['avg_delay_min'] ?? 0), 1),
            'max_delay_min' => (int)($stats_result['data']['max_delay_min'] ?? 0),
            'total_delay_min' => (int)($stats_result['data']['total_delay_min'] ?? 0)
        ];
    }
}

// ============================================================================
// Check for Multiple TMIs Controlling Same Flights
// ============================================================================

// Get flights in this program that are also controlled by other TMIs
$multi_tmi_sql = "SELECT fl.flight_gufi, COUNT(DISTINCT fl2.program_id) AS tmi_count
                  FROM dbo.tmi_flight_list fl
                  JOIN dbo.tmi_flight_list fl2 ON fl.flight_gufi = fl2.flight_gufi
                  JOIN dbo.tmi_programs p ON fl2.program_id = p.program_id AND p.is_active = 1
                  WHERE fl.program_id = ?
                  GROUP BY fl.flight_gufi
                  HAVING COUNT(DISTINCT fl2.program_id) > 1";

$multi_result = fetch_all($conn_tmi, $multi_tmi_sql, [$program_id]);
$multi_tmi_flights = [];
if ($multi_result['success'] && !empty($multi_result['data'])) {
    foreach ($multi_result['data'] as $row) {
        $multi_tmi_flights[$row['flight_gufi']] = (int)$row['tmi_count'];
    }
}

// Add multi_tmi_count to flights
foreach ($flights as &$flight) {
    $flight['multi_tmi_count'] = $multi_tmi_flights[$flight['flight_gufi']] ?? 1;
}
unset($flight);

// ============================================================================
// Response
// ============================================================================

$response_data = [
    'program_id' => $program_id,
    'program_type' => $program['program_type'],
    'ctl_element' => $program['ctl_element'],
    'flights' => $flights,
    'pagination' => [
        'offset' => $offset,
        'limit' => $limit,
        'returned' => count($flights)
    ]
];

if ($stats !== null) {
    $response_data['stats'] = $stats;
}

// Add generated_at timestamp
$generated_at = $program['flight_list_generated_at'] ?? null;
if ($generated_at instanceof DateTime) {
    $response_data['generated_at'] = datetime_to_iso($generated_at);
} elseif ($generated_at) {
    $response_data['generated_at'] = $generated_at;
}

respond_json(200, [
    'status' => 'ok',
    'data' => $response_data
]);
