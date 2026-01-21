<?php
/**
 * GDT Flights - List API
 * 
 * GET /api/gdt/flights/list.php?program_id=1
 * 
 * Lists flights assigned to a program with their control information.
 * Uses vw_tmi_flight_list view.
 * 
 * Query parameters:
 *   program_id      - Required: Program ID
 *   include_exempt  - If "1", include exempt flights (default: 1)
 *   status          - Filter by control status (e.g., CONTROLLED, EXEMPT, GS_HELD)
 *   dep_airport     - Filter by departure airport
 *   dep_center      - Filter by departure ARTCC
 *   carrier         - Filter by carrier
 *   limit           - Max records (default: 500)
 *   offset          - Pagination offset
 * 
 * Response:
 * {
 *   "status": "ok",
 *   "data": {
 *     "flights": [ ... ],
 *     "total": 150,
 *     "summary": {
 *       "controlled": 120,
 *       "exempt": 30,
 *       "avg_delay_min": 45,
 *       "max_delay_min": 90,
 *       "total_delay_min": 5400
 *     }
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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond_json(405, [
        'status' => 'error',
        'message' => 'Method not allowed. Use GET.'
    ]);
}

$conn_tmi = get_conn_tmi();

// ============================================================================
// Parse Parameters
// ============================================================================

$program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$include_exempt = !isset($_GET['include_exempt']) || $_GET['include_exempt'] !== '0';
$status = isset($_GET['status']) ? strtoupper(trim($_GET['status'])) : null;
$dep_airport = isset($_GET['dep_airport']) ? strtoupper(trim($_GET['dep_airport'])) : null;
$dep_center = isset($_GET['dep_center']) ? strtoupper(trim($_GET['dep_center'])) : null;
$carrier = isset($_GET['carrier']) ? strtoupper(trim($_GET['carrier'])) : null;
$limit = isset($_GET['limit']) ? max(1, min(1000, (int)$_GET['limit'])) : 500;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

if ($program_id <= 0) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'program_id is required.'
    ]);
}

// ============================================================================
// Build Query
// ============================================================================

$where = ["program_id = ?"];
$params = [$program_id];

if (!$include_exempt) {
    $where[] = "(ctl_exempt = 0 OR ctl_exempt IS NULL)";
}

if ($status !== null && $status !== '') {
    if ($status === 'CONTROLLED') {
        $where[] = "(ctl_exempt = 0 OR ctl_exempt IS NULL)";
    } elseif ($status === 'EXEMPT') {
        $where[] = "ctl_exempt = 1";
    } elseif ($status === 'GS_HELD') {
        $where[] = "gs_held = 1";
    }
}

if ($dep_airport !== null && $dep_airport !== '') {
    $where[] = "dep_airport = ?";
    $params[] = $dep_airport;
}

if ($dep_center !== null && $dep_center !== '') {
    $where[] = "dep_center = ?";
    $params[] = $dep_center;
}

if ($carrier !== null && $carrier !== '') {
    $where[] = "(carrier = ? OR LEFT(callsign, 3) = ?)";
    $params[] = $carrier;
    $params[] = $carrier;
}

$where_sql = implode(" AND ", $where);

// ============================================================================
// Get Summary
// ============================================================================

$summary_sql = "
    SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN ctl_exempt = 1 THEN 1 ELSE 0 END) AS exempt,
        SUM(CASE WHEN ctl_exempt = 0 OR ctl_exempt IS NULL THEN 1 ELSE 0 END) AS controlled,
        SUM(CASE WHEN gs_held = 1 THEN 1 ELSE 0 END) AS gs_held,
        AVG(CAST(program_delay_min AS FLOAT)) AS avg_delay_min,
        MAX(program_delay_min) AS max_delay_min,
        SUM(CAST(program_delay_min AS BIGINT)) AS total_delay_min
    FROM dbo.tmi_flight_control
    WHERE {$where_sql}
";

$summary_result = fetch_one($conn_tmi, $summary_sql, $params);
$summary = $summary_result['success'] ? $summary_result['data'] : null;

// Round avg_delay_min
if ($summary && isset($summary['avg_delay_min'])) {
    $summary['avg_delay_min'] = round((float)$summary['avg_delay_min'], 1);
}

// ============================================================================
// Fetch Flights
// ============================================================================

// Add pagination params
$params_paged = $params;
$params_paged[] = $offset;
$params_paged[] = $limit;

$flights_sql = "
    SELECT 
        control_id,
        flight_uid,
        callsign,
        program_id,
        slot_id,
        aslot,
        ctl_elem,
        ctl_type,
        ctl_exempt,
        ctl_exempt_reason,
        gs_held,
        gs_release_utc,
        is_popup,
        is_ecr,
        is_sub,
        ctd_utc,
        cta_utc,
        octd_utc,
        octa_utc,
        orig_eta_utc,
        orig_etd_utc,
        orig_ete_min,
        program_delay_min,
        delay_capped,
        dep_airport,
        arr_airport,
        dep_center,
        arr_center,
        carrier,
        control_assigned_utc,
        control_released_utc,
        created_at,
        updated_at
    FROM dbo.tmi_flight_control
    WHERE {$where_sql}
    ORDER BY cta_utc ASC, orig_eta_utc ASC, callsign ASC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";

$flights_result = fetch_all($conn_tmi, $flights_sql, $params_paged);

// ============================================================================
// Response
// ============================================================================

respond_json(200, [
    'status' => 'ok',
    'data' => [
        'flights' => $flights_result['success'] ? $flights_result['data'] : [],
        'total' => $summary ? (int)$summary['total'] : 0,
        'limit' => $limit,
        'offset' => $offset,
        'summary' => $summary
    ]
]);
