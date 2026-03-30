<?php
/**
 * VATSWIM API v1 - TMI Event Log Endpoint
 *
 * Returns TMI event log entries with scope, parameter, impact, and reference details.
 * Data from SWIM_API database (swim_tmi_log_* mirror tables).
 *
 * GET /api/swim/v1/tmi/event-log
 * GET /api/swim/v1/tmi/event-log?hours=4&category=PROGRAM&facility=KJFK
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
$hours = swim_get_int_param('hours', 4, 1, 168);       // Default 4h, max 7 days
$category = swim_get_param('category');                  // Event category filter (PROGRAM, ADVISORY, etc.)
$type = swim_get_param('type');                          // Event type filter
$program_type = swim_get_param('program_type');          // Program type filter (GDP, GS, AFP)
$facility = swim_get_param('facility');                  // Facility/airport filter
$org = swim_get_param('org');                            // Organization filter
$severity = swim_get_param('severity');                  // Severity filter

$page = swim_get_int_param('page', 1, 1, 1000);
$per_page = swim_get_int_param('per_page', SWIM_DEFAULT_PAGE_SIZE, 1, SWIM_MAX_PAGE_SIZE);
$offset = ($page - 1) * $per_page;

// Build WHERE clauses
$where_clauses = [];
$params = [];

// Time window filter
$where_clauses[] = "c.event_utc >= DATEADD(HOUR, -?, GETUTCDATE())";
$params[] = $hours;

if ($category) {
    $cat_list = array_map('trim', explode(',', strtoupper($category)));
    $placeholders = implode(',', array_fill(0, count($cat_list), '?'));
    $where_clauses[] = "c.category IN ($placeholders)";
    $params = array_merge($params, $cat_list);
}

if ($type) {
    $type_list = array_map('trim', explode(',', strtoupper($type)));
    $placeholders = implode(',', array_fill(0, count($type_list), '?'));
    $where_clauses[] = "c.event_type IN ($placeholders)";
    $params = array_merge($params, $type_list);
}

if ($program_type) {
    $pt_list = array_map('trim', explode(',', strtoupper($program_type)));
    $placeholders = implode(',', array_fill(0, count($pt_list), '?'));
    $where_clauses[] = "c.program_type IN ($placeholders)";
    $params = array_merge($params, $pt_list);
}

if ($facility) {
    $fac_list = array_map('trim', explode(',', strtoupper($facility)));
    $placeholders = implode(',', array_fill(0, count($fac_list), '?'));
    $where_clauses[] = "(c.facility IN ($placeholders) OR s.scope_value IN ($placeholders))";
    $params = array_merge($params, $fac_list, $fac_list);
}

if ($org) {
    $org_list = array_map('trim', explode(',', strtoupper($org)));
    $placeholders = implode(',', array_fill(0, count($org_list), '?'));
    $where_clauses[] = "c.org IN ($placeholders)";
    $params = array_merge($params, $org_list);
}

if ($severity) {
    $sev_list = array_map('trim', explode(',', strtoupper($severity)));
    $placeholders = implode(',', array_fill(0, count($sev_list), '?'));
    $where_clauses[] = "c.severity IN ($placeholders)";
    $params = array_merge($params, $sev_list);
}

$where_sql = 'WHERE ' . implode(' AND ', $where_clauses);

// Count total (use core table only for count to avoid inflation from JOINs)
$count_where_clauses = [];
$count_params = [];

$count_where_clauses[] = "c.event_utc >= DATEADD(HOUR, -?, GETUTCDATE())";
$count_params[] = $hours;

if ($category) {
    $cat_list = array_map('trim', explode(',', strtoupper($category)));
    $placeholders = implode(',', array_fill(0, count($cat_list), '?'));
    $count_where_clauses[] = "c.category IN ($placeholders)";
    $count_params = array_merge($count_params, $cat_list);
}

if ($type) {
    $type_list = array_map('trim', explode(',', strtoupper($type)));
    $placeholders = implode(',', array_fill(0, count($type_list), '?'));
    $count_where_clauses[] = "c.event_type IN ($placeholders)";
    $count_params = array_merge($count_params, $type_list);
}

if ($program_type) {
    $pt_list = array_map('trim', explode(',', strtoupper($program_type)));
    $placeholders = implode(',', array_fill(0, count($pt_list), '?'));
    $count_where_clauses[] = "c.program_type IN ($placeholders)";
    $count_params = array_merge($count_params, $pt_list);
}

if ($org) {
    $org_list = array_map('trim', explode(',', strtoupper($org)));
    $placeholders = implode(',', array_fill(0, count($org_list), '?'));
    $count_where_clauses[] = "c.org IN ($placeholders)";
    $count_params = array_merge($count_params, $org_list);
}

if ($severity) {
    $sev_list = array_map('trim', explode(',', strtoupper($severity)));
    $placeholders = implode(',', array_fill(0, count($sev_list), '?'));
    $count_where_clauses[] = "c.severity IN ($placeholders)";
    $count_params = array_merge($count_params, $sev_list);
}

$count_where_sql = 'WHERE ' . implode(' AND ', $count_where_clauses);

$count_sql = "SELECT COUNT(*) as total FROM dbo.swim_tmi_log_core c $count_where_sql";
$count_stmt = sqlsrv_query($conn_swim, $count_sql, $count_params);
if ($count_stmt === false) {
    $errors = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}
$total = sqlsrv_fetch_array($count_stmt, SQLSRV_FETCH_ASSOC)['total'];
sqlsrv_free_stmt($count_stmt);

// Main query with LEFT JOINs
$sql = "
    SELECT
        c.log_id,
        c.event_utc,
        c.category,
        c.event_type,
        c.program_type,
        c.program_id,
        c.facility,
        c.org,
        c.severity,
        c.summary,
        c.created_by,
        c.created_utc,
        s.scope_type,
        s.scope_value,
        s.scope_direction,
        p.cause_category AS param_cause_category,
        p.cause_detail AS param_cause_detail,
        p.rate_value AS param_rate_value,
        p.rate_unit AS param_rate_unit,
        p.delay_minutes AS param_delay_minutes,
        p.scope_filter AS param_scope_filter,
        i.affected_flights AS impact_affected_flights,
        i.avg_delay_minutes AS impact_avg_delay_minutes,
        i.max_delay_minutes AS impact_max_delay_minutes,
        i.total_delay_minutes AS impact_total_delay_minutes,
        i.compliance_pct AS impact_compliance_pct,
        r.ref_type,
        r.ref_id,
        r.ref_label
    FROM dbo.swim_tmi_log_core c
    LEFT JOIN dbo.swim_tmi_log_scope s ON c.log_id = s.log_id
    LEFT JOIN dbo.swim_tmi_log_parameters p ON c.log_id = p.log_id
    LEFT JOIN dbo.swim_tmi_log_impact i ON c.log_id = i.log_id
    LEFT JOIN dbo.swim_tmi_log_references r ON c.log_id = r.log_id
    $where_sql
    ORDER BY c.event_utc DESC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";

$params[] = $offset;
$params[] = $per_page;

$stmt = sqlsrv_query($conn_swim, $sql, $params);
if ($stmt === false) {
    $errors = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}

$events = [];
$stats = [
    'by_category' => [],
    'by_type' => [],
    'by_facility' => [],
    'by_severity' => []
];

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $entry = formatEventLog($row);
    $events[] = $entry;

    // Update stats
    $cat = $row['category'];
    if ($cat) {
        $stats['by_category'][$cat] = ($stats['by_category'][$cat] ?? 0) + 1;
    }

    $evt_type = $row['event_type'];
    if ($evt_type) {
        $stats['by_type'][$evt_type] = ($stats['by_type'][$evt_type] ?? 0) + 1;
    }

    $fac = $row['facility'];
    if ($fac) {
        $stats['by_facility'][$fac] = ($stats['by_facility'][$fac] ?? 0) + 1;
    }

    $sev = $row['severity'];
    if ($sev) {
        $stats['by_severity'][$sev] = ($stats['by_severity'][$sev] ?? 0) + 1;
    }
}
sqlsrv_free_stmt($stmt);

// Sort stats
arsort($stats['by_category']);
arsort($stats['by_type']);
arsort($stats['by_facility']);
arsort($stats['by_severity']);

$response = [
    'success' => true,
    'data' => $events,
    'statistics' => $stats,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => ceil($total / $per_page),
        'has_more' => $page < ceil($total / $per_page)
    ],
    'filters' => [
        'hours' => $hours,
        'category' => $category,
        'type' => $type,
        'program_type' => $program_type,
        'facility' => $facility,
        'org' => $org,
        'severity' => $severity
    ],
    'timestamp' => gmdate('c'),
    'meta' => [
        'source' => 'swim_api',
        'tables' => 'swim_tmi_log_core, swim_tmi_log_scope, swim_tmi_log_parameters, swim_tmi_log_impact, swim_tmi_log_references'
    ]
];

SwimResponse::json($response);


function formatEventLog($row) {
    return [
        'log_id' => $row['log_id'],
        'event_utc' => formatDT($row['event_utc']),
        'category' => $row['category'],
        'event_type' => $row['event_type'],
        'program_type' => $row['program_type'],
        'program_id' => $row['program_id'],
        'facility' => $row['facility'],
        'org' => $row['org'],
        'severity' => $row['severity'],
        'summary' => $row['summary'],

        'scope' => [
            'type' => $row['scope_type'],
            'value' => $row['scope_value'],
            'direction' => $row['scope_direction']
        ],

        'parameters' => [
            'cause_category' => $row['param_cause_category'],
            'cause_detail' => $row['param_cause_detail'],
            'rate_value' => $row['param_rate_value'],
            'rate_unit' => $row['param_rate_unit'],
            'delay_minutes' => $row['param_delay_minutes'],
            'scope_filter' => $row['param_scope_filter']
        ],

        'impact' => [
            'affected_flights' => $row['impact_affected_flights'],
            'avg_delay_minutes' => $row['impact_avg_delay_minutes'],
            'max_delay_minutes' => $row['impact_max_delay_minutes'],
            'total_delay_minutes' => $row['impact_total_delay_minutes'],
            'compliance_pct' => $row['impact_compliance_pct']
        ],

        'reference' => [
            'type' => $row['ref_type'],
            'id' => $row['ref_id'],
            'label' => $row['ref_label']
        ],

        '_created_by' => $row['created_by'],
        '_created_utc' => formatDT($row['created_utc'])
    ];
}

function formatDT($dt) {
    if ($dt === null) return null;
    return ($dt instanceof DateTime) ? $dt->format('c') : $dt;
}
