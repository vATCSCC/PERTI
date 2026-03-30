<?php
/**
 * VATSWIM API v1 - TMI Facility Stats Endpoint
 *
 * Returns aggregated TMI statistics per facility, available in hourly or daily granularity.
 * Data from SWIM_API database (swim_tmi_facility_stats_hourly / swim_tmi_facility_stats_daily mirror tables).
 *
 * GET /api/swim/v1/tmi/facility-stats
 * GET /api/swim/v1/tmi/facility-stats?airport=KJFK&period=hourly
 * GET /api/swim/v1/tmi/facility-stats?airport=KJFK&period=daily
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
$airport = swim_get_param('airport');                       // Airport ICAO filter (airport_icao)
$facility = swim_get_param('facility');                     // Facility filter
$hours = swim_get_int_param('hours', 24, 1, 720);          // Hours lookback for hourly (default 24, max 30 days)
$days = swim_get_int_param('days', 7, 1, 90);              // Days lookback for daily (default 7, max 90)

$page = swim_get_int_param('page', 1, 1, 1000);
$per_page = swim_get_int_param('per_page', SWIM_DEFAULT_PAGE_SIZE, 1, SWIM_MAX_PAGE_SIZE);
$offset = ($page - 1) * $per_page;

// Validate period parameter
if (!in_array($period, ['hourly', 'daily'])) {
    SwimResponse::error('Invalid period parameter. Use "hourly" or "daily".', 400, 'INVALID_PARAMETER');
}

// Select table and time column based on period
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
        f.airport_icao,
        f.facility,
        f.hour_utc,
        f.total_programs,
        f.active_gdps,
        f.active_ground_stops,
        f.active_afps,
        f.controlled_flights,
        f.avg_delay_minutes,
        f.max_delay_minutes,
        f.total_delay_minutes,
        f.aar_actual,
        f.aar_planned,
        f.adr_actual,
        f.adr_planned,
        f.demand_count,
        f.capacity_utilization_pct,
        f.compliance_pct,
        f.created_utc
    ";
    $order_col = 'f.hour_utc';
} else {
    $columns = "
        f.stat_id,
        f.airport_icao,
        f.facility,
        f.date_utc,
        f.total_programs,
        f.total_gdps,
        f.total_ground_stops,
        f.total_afps,
        f.total_controlled_flights,
        f.avg_delay_minutes,
        f.max_delay_minutes,
        f.total_delay_minutes,
        f.peak_demand,
        f.avg_capacity_utilization_pct,
        f.avg_compliance_pct,
        f.total_advisories,
        f.total_entries,
        f.created_utc
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

    // Update summary
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

// Sort summary
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
        'airport_icao' => $row['airport_icao'],
        'facility' => $row['facility'],
        'hour_utc' => formatDT($row['hour_utc']),

        'programs' => [
            'total' => $row['total_programs'],
            'active_gdps' => $row['active_gdps'],
            'active_ground_stops' => $row['active_ground_stops'],
            'active_afps' => $row['active_afps']
        ],

        'delay' => [
            'controlled_flights' => $row['controlled_flights'],
            'avg_minutes' => $row['avg_delay_minutes'],
            'max_minutes' => $row['max_delay_minutes'],
            'total_minutes' => $row['total_delay_minutes']
        ],

        'capacity' => [
            'aar_actual' => $row['aar_actual'],
            'aar_planned' => $row['aar_planned'],
            'adr_actual' => $row['adr_actual'],
            'adr_planned' => $row['adr_planned'],
            'demand_count' => $row['demand_count'],
            'utilization_pct' => $row['capacity_utilization_pct']
        ],

        'compliance_pct' => $row['compliance_pct'],
        '_created_utc' => formatDT($row['created_utc'])
    ];
}

function formatDailyStat($row) {
    return [
        'stat_id' => $row['stat_id'],
        'airport_icao' => $row['airport_icao'],
        'facility' => $row['facility'],
        'date_utc' => formatDT($row['date_utc']),

        'programs' => [
            'total' => $row['total_programs'],
            'total_gdps' => $row['total_gdps'],
            'total_ground_stops' => $row['total_ground_stops'],
            'total_afps' => $row['total_afps']
        ],

        'delay' => [
            'total_controlled_flights' => $row['total_controlled_flights'],
            'avg_minutes' => $row['avg_delay_minutes'],
            'max_minutes' => $row['max_delay_minutes'],
            'total_minutes' => $row['total_delay_minutes']
        ],

        'demand' => [
            'peak_demand' => $row['peak_demand'],
            'avg_capacity_utilization_pct' => $row['avg_capacity_utilization_pct']
        ],

        'compliance' => [
            'avg_compliance_pct' => $row['avg_compliance_pct']
        ],

        'counts' => [
            'total_advisories' => $row['total_advisories'],
            'total_entries' => $row['total_entries']
        ],

        '_created_utc' => formatDT($row['created_utc'])
    ];
}

function formatDT($dt) {
    if ($dt === null) return null;
    return ($dt instanceof DateTime) ? $dt->format('c') : $dt;
}
