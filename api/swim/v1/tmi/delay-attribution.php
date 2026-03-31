<?php
/**
 * VATSWIM API v1 - TMI Delay Attribution Endpoint
 *
 * Returns delay attribution data linking delays to causes, programs, and phases.
 * Data from SWIM_API database (swim_tmi_delay_attribution mirror table).
 *
 * GET /api/swim/v1/tmi/delay-attribution
 * GET /api/swim/v1/tmi/delay-attribution?airport=KJFK&current_only=true
 * GET /api/swim/v1/tmi/delay-attribution?flight_uid=12345
 *
 * @version 1.0.0
 */

require_once __DIR__ . '/../auth.php';

// SWIM database connection (SWIM-isolated: uses SWIM_API mirror tables)
global $conn_swim;

if (!$conn_swim) {
    SwimResponse::error('SWIM database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

$auth = swim_init_auth(true, false);

// Get filter parameters
$airport = swim_get_param('airport');                    // Arrival airport filter (arr_icao)
$dep_airport = swim_get_param('dep_airport');            // Departure airport filter (dep_icao)
$flight_uid = swim_get_param('flight_uid');              // Specific flight UID
$cause_category = swim_get_param('cause_category');      // Cause category filter (WEATHER, VOLUME, etc.)
$delay_phase = swim_get_param('delay_phase');            // Delay phase filter (GROUND, AIRBORNE, etc.)
$program_id = swim_get_param('program_id');              // Attributed program ID filter
$current_only = swim_get_param('current_only', 'true') === 'true';

$page = swim_get_int_param('page', 1, 1, 1000);
$per_page = swim_get_int_param('per_page', SWIM_DEFAULT_PAGE_SIZE, 1, SWIM_MAX_PAGE_SIZE);
$offset = ($page - 1) * $per_page;

// Build WHERE clauses
$where_clauses = [];
$params = [];

if ($current_only) {
    $where_clauses[] = "d.is_current = 1";
}

if ($airport) {
    $apt_list = array_map('trim', explode(',', strtoupper($airport)));
    $placeholders = implode(',', array_fill(0, count($apt_list), '?'));
    $where_clauses[] = "d.arr_icao IN ($placeholders)";
    $params = array_merge($params, $apt_list);
}

if ($dep_airport) {
    $dep_list = array_map('trim', explode(',', strtoupper($dep_airport)));
    $placeholders = implode(',', array_fill(0, count($dep_list), '?'));
    $where_clauses[] = "d.dep_icao IN ($placeholders)";
    $params = array_merge($params, $dep_list);
}

if ($flight_uid) {
    $where_clauses[] = "d.flight_uid = ?";
    $params[] = intval($flight_uid);
}

if ($cause_category) {
    $cause_list = array_map('trim', explode(',', strtoupper($cause_category)));
    $placeholders = implode(',', array_fill(0, count($cause_list), '?'));
    $where_clauses[] = "d.cause_category IN ($placeholders)";
    $params = array_merge($params, $cause_list);
}

if ($delay_phase) {
    $phase_list = array_map('trim', explode(',', strtoupper($delay_phase)));
    $placeholders = implode(',', array_fill(0, count($phase_list), '?'));
    $where_clauses[] = "d.delay_phase IN ($placeholders)";
    $params = array_merge($params, $phase_list);
}

if ($program_id) {
    $where_clauses[] = "d.attributed_program_id = ?";
    $params[] = intval($program_id);
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Count total
$count_sql = "SELECT COUNT(*) as total FROM dbo.swim_tmi_delay_attribution d $where_sql";
$count_stmt = sqlsrv_query($conn_swim, $count_sql, $params);
if ($count_stmt === false) {
    $errors = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}
$total = sqlsrv_fetch_array($count_stmt, SQLSRV_FETCH_ASSOC)['total'];
sqlsrv_free_stmt($count_stmt);

// Main query
$sql = "
    SELECT
        d.attribution_id,
        d.flight_uid,
        d.callsign,
        d.dep_icao,
        d.arr_icao,
        d.delay_phase,
        d.delay_minutes,
        d.cause_category,
        d.cause_subcategory,
        d.attributed_program_id,
        d.attributed_facility,
        d.computation_method,
        d.computed_utc,
        d.is_current
    FROM dbo.swim_tmi_delay_attribution d
    $where_sql
    ORDER BY d.computed_utc DESC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";

$params[] = $offset;
$params[] = $per_page;

$stmt = sqlsrv_query($conn_swim, $sql, $params);
if ($stmt === false) {
    $errors = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}

$attributions = [];
$stats = [
    'by_cause' => [],
    'by_phase' => [],
    'by_airport' => []
];

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $attributions[] = [
        'attribution_id' => $row['attribution_id'],
        'flight_uid' => $row['flight_uid'],
        'callsign' => $row['callsign'],
        'dep_icao' => $row['dep_icao'],
        'arr_icao' => $row['arr_icao'],
        'delay_phase' => $row['delay_phase'],
        'delay_minutes' => (float)$row['delay_minutes'],
        'cause_category' => $row['cause_category'],
        'cause_subcategory' => $row['cause_subcategory'],
        'attributed_program_id' => $row['attributed_program_id'],
        'attributed_facility' => $row['attributed_facility'],
        'computation_method' => $row['computation_method'],
        'computed_utc' => formatDT($row['computed_utc']),
        'is_current' => (bool)$row['is_current']
    ];

    $cause = $row['cause_category'];
    if ($cause) {
        $stats['by_cause'][$cause] = ($stats['by_cause'][$cause] ?? 0) + 1;
    }
    $phase = $row['delay_phase'];
    if ($phase) {
        $stats['by_phase'][$phase] = ($stats['by_phase'][$phase] ?? 0) + 1;
    }
    $apt = $row['arr_icao'];
    if ($apt) {
        $stats['by_airport'][$apt] = ($stats['by_airport'][$apt] ?? 0) + 1;
    }
}
sqlsrv_free_stmt($stmt);

arsort($stats['by_cause']);
arsort($stats['by_phase']);
arsort($stats['by_airport']);

$response = [
    'success' => true,
    'data' => $attributions,
    'statistics' => $stats,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => ceil($total / $per_page),
        'has_more' => $page < ceil($total / $per_page)
    ],
    'filters' => [
        'airport' => $airport,
        'dep_airport' => $dep_airport,
        'flight_uid' => $flight_uid,
        'cause_category' => $cause_category,
        'delay_phase' => $delay_phase,
        'program_id' => $program_id,
        'current_only' => $current_only
    ],
    'timestamp' => gmdate('c'),
    'meta' => [
        'source' => 'swim_api',
        'table' => 'swim_tmi_delay_attribution'
    ]
];

SwimResponse::json($response);


function formatDT($dt) {
    if ($dt === null) return null;
    return ($dt instanceof DateTime) ? $dt->format('c') : $dt;
}
