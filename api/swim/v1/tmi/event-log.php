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
$category = swim_get_param('category');                  // action_category filter (PROGRAM, ADVISORY, etc.)
$type = swim_get_param('type');                          // action_type filter
$program_type = swim_get_param('program_type');          // Program type filter (GDP, GS, AFP)
$facility = swim_get_param('facility');                  // issuing_facility filter
$org = swim_get_param('org');                            // issuing_org filter
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
    $where_clauses[] = "c.action_category IN ($placeholders)";
    $params = array_merge($params, $cat_list);
}

if ($type) {
    $type_list = array_map('trim', explode(',', strtoupper($type)));
    $placeholders = implode(',', array_fill(0, count($type_list), '?'));
    $where_clauses[] = "c.action_type IN ($placeholders)";
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
    $where_clauses[] = "(c.issuing_facility IN ($placeholders) OR s.facility IN ($placeholders))";
    $params = array_merge($params, $fac_list, $fac_list);
}

if ($org) {
    $org_list = array_map('trim', explode(',', strtoupper($org)));
    $placeholders = implode(',', array_fill(0, count($org_list), '?'));
    $where_clauses[] = "c.issuing_org IN ($placeholders)";
    $params = array_merge($params, $org_list);
}

if ($severity) {
    $sev_list = array_map('trim', explode(',', strtoupper($severity)));
    $placeholders = implode(',', array_fill(0, count($sev_list), '?'));
    $where_clauses[] = "c.severity IN ($placeholders)";
    $params = array_merge($params, $sev_list);
}

$where_sql = 'WHERE ' . implode(' AND ', $where_clauses);

// Count total (core table only, no facility filter that needs scope JOIN)
$count_where_clauses = [];
$count_params = [];

$count_where_clauses[] = "c.event_utc >= DATEADD(HOUR, -?, GETUTCDATE())";
$count_params[] = $hours;

if ($category) {
    $cat_list = array_map('trim', explode(',', strtoupper($category)));
    $placeholders = implode(',', array_fill(0, count($cat_list), '?'));
    $count_where_clauses[] = "c.action_category IN ($placeholders)";
    $count_params = array_merge($count_params, $cat_list);
}

if ($type) {
    $type_list = array_map('trim', explode(',', strtoupper($type)));
    $placeholders = implode(',', array_fill(0, count($type_list), '?'));
    $count_where_clauses[] = "c.action_type IN ($placeholders)";
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
    $count_where_clauses[] = "c.issuing_org IN ($placeholders)";
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

// Main query with LEFT JOINs to satellite tables
$sql = "
    SELECT
        c.log_id,
        c.log_seq,
        c.action_category,
        c.action_type,
        c.program_type,
        c.severity,
        c.source_system,
        c.summary,
        c.event_utc,
        c.user_cid,
        c.user_name,
        c.issuing_facility,
        c.issuing_org,
        s.ctl_element,
        s.element_type,
        s.facility AS scope_facility,
        s.traffic_flow,
        s.via_fix,
        s.scope_airports,
        s.scope_tiers,
        p.effective_start_utc,
        p.effective_end_utc,
        p.rate_value,
        p.rate_unit,
        p.program_rate,
        p.cause_category,
        p.cause_detail,
        p.cancellation_reason,
        i.total_flights,
        i.controlled_flights,
        i.avg_delay_min,
        i.max_delay_min,
        i.total_delay_min,
        i.demand_rate,
        i.capacity_rate,
        i.compliance_rate,
        r.program_id AS ref_program_id,
        r.entry_id AS ref_entry_id,
        r.advisory_id AS ref_advisory_id,
        r.reroute_id AS ref_reroute_id,
        r.slot_id AS ref_slot_id,
        r.flight_uid AS ref_flight_uid,
        r.advisory_number AS ref_advisory_number
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

    $cat = $row['action_category'];
    if ($cat) {
        $stats['by_category'][$cat] = ($stats['by_category'][$cat] ?? 0) + 1;
    }
    $evt_type = $row['action_type'];
    if ($evt_type) {
        $stats['by_type'][$evt_type] = ($stats['by_type'][$evt_type] ?? 0) + 1;
    }
    $fac = $row['issuing_facility'];
    if ($fac) {
        $stats['by_facility'][$fac] = ($stats['by_facility'][$fac] ?? 0) + 1;
    }
    $sev = $row['severity'];
    if ($sev) {
        $stats['by_severity'][$sev] = ($stats['by_severity'][$sev] ?? 0) + 1;
    }
}
sqlsrv_free_stmt($stmt);

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
        'log_seq' => $row['log_seq'],
        'event_utc' => formatDT($row['event_utc']),
        'action_category' => $row['action_category'],
        'action_type' => $row['action_type'],
        'program_type' => $row['program_type'],
        'severity' => $row['severity'],
        'source_system' => $row['source_system'],
        'summary' => $row['summary'],
        'user_cid' => $row['user_cid'],
        'user_name' => $row['user_name'],
        'issuing_facility' => $row['issuing_facility'],
        'issuing_org' => $row['issuing_org'],

        'scope' => [
            'ctl_element' => $row['ctl_element'],
            'element_type' => $row['element_type'],
            'facility' => $row['scope_facility'],
            'traffic_flow' => $row['traffic_flow'],
            'via_fix' => $row['via_fix'],
            'airports' => $row['scope_airports'],
            'tiers' => $row['scope_tiers']
        ],

        'parameters' => [
            'effective_start_utc' => formatDT($row['effective_start_utc']),
            'effective_end_utc' => formatDT($row['effective_end_utc']),
            'rate_value' => $row['rate_value'],
            'rate_unit' => $row['rate_unit'],
            'program_rate' => $row['program_rate'],
            'cause_category' => $row['cause_category'],
            'cause_detail' => $row['cause_detail'],
            'cancellation_reason' => $row['cancellation_reason']
        ],

        'impact' => [
            'total_flights' => $row['total_flights'],
            'controlled_flights' => $row['controlled_flights'],
            'avg_delay_min' => $row['avg_delay_min'],
            'max_delay_min' => $row['max_delay_min'],
            'total_delay_min' => $row['total_delay_min'],
            'demand_rate' => $row['demand_rate'],
            'capacity_rate' => $row['capacity_rate'],
            'compliance_rate' => $row['compliance_rate']
        ],

        'references' => [
            'program_id' => $row['ref_program_id'],
            'entry_id' => $row['ref_entry_id'],
            'advisory_id' => $row['ref_advisory_id'],
            'reroute_id' => $row['ref_reroute_id'],
            'slot_id' => $row['ref_slot_id'],
            'flight_uid' => $row['ref_flight_uid'],
            'advisory_number' => $row['ref_advisory_number']
        ]
    ];
}

function formatDT($dt) {
    if ($dt === null) return null;
    return ($dt instanceof DateTime) ? $dt->format('c') : $dt;
}
