<?php
/**
 * VATSWIM API v1 - TMI Facility Stats Endpoint
 *
 * Returns aggregated TMI statistics per facility, available in hourly or daily granularity.
 * Data from SWIM_API database (swim_tmi_facility_stats_hourly / swim_tmi_facility_stats_daily).
 *
 * GET /api/swim/v1/tmi/facility-stats
 * GET /api/swim/v1/tmi/facility-stats?airport=KJFK&period=hourly
 * GET /api/swim/v1/tmi/facility-stats?airport=KJFK&period=daily&days=30
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
$period = strtolower(swim_get_param('period', 'hourly'));  // hourly or daily
$airport = swim_get_param('airport');                       // airport_icao filter
$facility = swim_get_param('facility');                     // Facility name filter
$facility_type = swim_get_param('facility_type');           // Facility type filter (ARTCC, TRACON, etc.)
$hours = swim_get_int_param('hours', 24, 1, 720);          // Hours lookback for hourly (max 30 days)
$days = swim_get_int_param('days', 7, 1, 90);              // Days lookback for daily (max 90)

$page = swim_get_int_param('page', 1, 1, 1000);
$per_page = swim_get_int_param('per_page', SWIM_DEFAULT_PAGE_SIZE, 1, SWIM_MAX_PAGE_SIZE);
$offset = ($page - 1) * $per_page;

// Validate period parameter
if (!in_array($period, ['hourly', 'daily'])) {
    SwimResponse::error('Invalid period parameter. Use "hourly" or "daily".', 400, 'INVALID_PARAMETER');
}

$is_hourly = ($period === 'hourly');
$table = $is_hourly ? 'dbo.swim_tmi_facility_stats_hourly' : 'dbo.swim_tmi_facility_stats_daily';
$time_col = $is_hourly ? 'hour_utc' : 'date_utc';

// Build WHERE clauses
$where_clauses = [];
$params = [];

// Time window filter
if ($is_hourly) {
    $where_clauses[] = "f.$time_col >= DATEADD(HOUR, -?, GETUTCDATE())";
    $params[] = $hours;
} else {
    $where_clauses[] = "f.$time_col >= DATEADD(DAY, -?, CAST(GETUTCDATE() AS DATE))";
    $params[] = $days;
}

if ($airport) {
    $apt_list = array_map('trim', explode(',', strtoupper($airport)));
    $placeholders = implode(',', array_fill(0, count($apt_list), '?'));
    $where_clauses[] = "f.airport_icao IN ($placeholders)";
    $params = array_merge($params, $apt_list);
}

if ($facility) {
    $fac_list = array_map('trim', explode(',', strtoupper($facility)));
    $placeholders = implode(',', array_fill(0, count($fac_list), '?'));
    $where_clauses[] = "f.facility IN ($placeholders)";
    $params = array_merge($params, $fac_list);
}

if ($facility_type) {
    $ft_list = array_map('trim', explode(',', strtoupper($facility_type)));
    $placeholders = implode(',', array_fill(0, count($ft_list), '?'));
    $where_clauses[] = "f.facility_type IN ($placeholders)";
    $params = array_merge($params, $ft_list);
}

$where_sql = 'WHERE ' . implode(' AND ', $where_clauses);

// Count total
$count_sql = "SELECT COUNT(*) as total FROM $table f $where_sql";
$count_stmt = sqlsrv_query($conn_swim, $count_sql, $params);
if ($count_stmt === false) {
    $errors = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}
$total = sqlsrv_fetch_array($count_stmt, SQLSRV_FETCH_ASSOC)['total'];
sqlsrv_free_stmt($count_stmt);

// Build column list based on period
if ($is_hourly) {
    $columns = "
        f.stat_id,
        f.facility,
        f.facility_type,
        f.airport_icao,
        f.hour_utc,
        f.total_operations,
        f.total_arrivals,
        f.total_departures,
        f.ontime_arrivals,
        f.delayed_arrivals,
        f.avg_arr_delay_min,
        f.max_arr_delay_min,
        f.delay_min_total,
        f.computed_utc
    ";
    $order_col = 'f.hour_utc';
} else {
    $columns = "
        f.stat_id,
        f.facility,
        f.facility_type,
        f.airport_icao,
        f.date_utc,
        f.total_operations,
        f.total_arrivals,
        f.total_departures,
        f.ontime_arr_pct,
        f.avg_arr_delay_min,
        f.delay_min_total,
        f.programs_issued,
        f.computed_utc
    ";
    $order_col = 'f.date_utc';
}

// Main query
$sql = "
    SELECT $columns
    FROM $table f
    $where_sql
    ORDER BY $order_col DESC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";

$params[] = $offset;
$params[] = $per_page;

$stmt = sqlsrv_query($conn_swim, $sql, $params);
if ($stmt === false) {
    $errors = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}

$stats_data = [];
$summary = [
    'by_airport' => [],
    'by_facility' => []
];

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $entry = $is_hourly ? formatHourlyStat($row) : formatDailyStat($row);
    $stats_data[] = $entry;

    $apt = $row['airport_icao'];
    if ($apt) {
        $summary['by_airport'][$apt] = ($summary['by_airport'][$apt] ?? 0) + 1;
    }
    $fac = $row['facility'];
    if ($fac) {
        $summary['by_facility'][$fac] = ($summary['by_facility'][$fac] ?? 0) + 1;
    }
}
sqlsrv_free_stmt($stmt);

arsort($summary['by_airport']);
arsort($summary['by_facility']);

$response = [
    'success' => true,
    'data' => $stats_data,
    'summary' => $summary,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => ceil($total / $per_page),
        'has_more' => $page < ceil($total / $per_page)
    ],
    'filters' => [
        'period' => $period,
        'airport' => $airport,
        'facility' => $facility,
        'facility_type' => $facility_type,
        'hours' => $is_hourly ? $hours : null,
        'days' => !$is_hourly ? $days : null
    ],
    'timestamp' => gmdate('c'),
    'meta' => [
        'source' => 'swim_api',
        'table' => $is_hourly ? 'swim_tmi_facility_stats_hourly' : 'swim_tmi_facility_stats_daily',
        'period' => $period
    ]
];

SwimResponse::json($response);


function formatHourlyStat($row) {
    return [
        'stat_id' => $row['stat_id'],
        'facility' => $row['facility'],
        'facility_type' => $row['facility_type'],
        'airport_icao' => $row['airport_icao'],
        'hour_utc' => formatDT($row['hour_utc']),
        'total_operations' => $row['total_operations'],
        'total_arrivals' => $row['total_arrivals'],
        'total_departures' => $row['total_departures'],
        'ontime_arrivals' => $row['ontime_arrivals'],
        'delayed_arrivals' => $row['delayed_arrivals'],
        'avg_arr_delay_min' => $row['avg_arr_delay_min'] !== null ? (float)$row['avg_arr_delay_min'] : null,
        'max_arr_delay_min' => $row['max_arr_delay_min'] !== null ? (float)$row['max_arr_delay_min'] : null,
        'delay_min_total' => (float)$row['delay_min_total'],
        'computed_utc' => formatDT($row['computed_utc'])
    ];
}

function formatDailyStat($row) {
    return [
        'stat_id' => $row['stat_id'],
        'facility' => $row['facility'],
        'facility_type' => $row['facility_type'],
        'airport_icao' => $row['airport_icao'],
        'date_utc' => formatDT($row['date_utc']),
        'total_operations' => $row['total_operations'],
        'total_arrivals' => $row['total_arrivals'],
        'total_departures' => $row['total_departures'],
        'ontime_arr_pct' => $row['ontime_arr_pct'] !== null ? (float)$row['ontime_arr_pct'] : null,
        'avg_arr_delay_min' => $row['avg_arr_delay_min'] !== null ? (float)$row['avg_arr_delay_min'] : null,
        'delay_min_total' => (float)$row['delay_min_total'],
        'programs_issued' => $row['programs_issued'],
        'computed_utc' => formatDT($row['computed_utc'])
    ];
}

function formatDT($dt) {
    if ($dt === null) return null;
    return ($dt instanceof DateTime) ? $dt->format('c') : $dt;
}
