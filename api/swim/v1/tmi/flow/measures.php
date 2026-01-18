<?php
/**
 * VATSWIM API v1 - Flow Measures Endpoint
 *
 * Returns flow measures (MIT, MINIT, MDI, GS, etc.) from external providers.
 * TFMS/FIXM-aligned structure for global interoperability.
 *
 * GET /api/swim/v1/tmi/flow/measures
 * GET /api/swim/v1/tmi/flow/measures?provider=ECFMP
 * GET /api/swim/v1/tmi/flow/measures?type=MIT,MDI
 * GET /api/swim/v1/tmi/flow/measures?event_id=123
 * GET /api/swim/v1/tmi/flow/measures?airport=EGLL
 *
 * @version 1.0.0
 */

require_once __DIR__ . '/../../auth.php';

global $conn_tmi;

if (!$conn_tmi) {
    SwimResponse::error('TMI database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

$auth = swim_init_auth(false, false);

// Get filter parameters
$provider = swim_get_param('provider');
$measure_type = swim_get_param('type');
$measure_id = swim_get_param('id');
$event_id = swim_get_param('event_id');
$ident = swim_get_param('ident');
$airport = swim_get_param('airport');           // Filter by ctl_element
$status = swim_get_param('status');
$active_only = swim_get_param('active_only', 'true') === 'true';
$include_history = swim_get_param('include_history', 'false') === 'true';

$page = swim_get_int_param('page', 1, 1, 1000);
$per_page = swim_get_int_param('per_page', SWIM_DEFAULT_PAGE_SIZE, 1, SWIM_MAX_PAGE_SIZE);
$offset = ($page - 1) * $per_page;

// Build query
$where_clauses = [];
$params = [];

if ($active_only && !$include_history) {
    $where_clauses[] = "m.status IN ('NOTIFIED', 'ACTIVE')";
    $where_clauses[] = "m.end_utc > SYSUTCDATETIME()";
}

if ($provider) {
    $provider_list = array_map('trim', explode(',', strtoupper($provider)));
    $placeholders = implode(',', array_fill(0, count($provider_list), '?'));
    $where_clauses[] = "p.provider_code IN ($placeholders)";
    $params = array_merge($params, $provider_list);
}

if ($measure_type) {
    $type_list = array_map('trim', explode(',', strtoupper($measure_type)));
    $placeholders = implode(',', array_fill(0, count($type_list), '?'));
    $where_clauses[] = "m.measure_type IN ($placeholders)";
    $params = array_merge($params, $type_list);
}

if ($measure_id) {
    $where_clauses[] = "m.measure_id = ?";
    $params[] = intval($measure_id);
}

if ($event_id) {
    $where_clauses[] = "m.event_id = ?";
    $params[] = intval($event_id);
}

if ($ident) {
    $where_clauses[] = "m.ident LIKE ?";
    $params[] = '%' . $ident . '%';
}

if ($airport) {
    $airport_list = array_map('trim', explode(',', strtoupper($airport)));
    $placeholders = implode(',', array_fill(0, count($airport_list), '?'));
    $where_clauses[] = "m.ctl_element IN ($placeholders)";
    $params = array_merge($params, $airport_list);
}

if ($status) {
    $status_list = array_map('trim', explode(',', strtoupper($status)));
    $placeholders = implode(',', array_fill(0, count($status_list), '?'));
    $where_clauses[] = "m.status IN ($placeholders)";
    $params = array_merge($params, $status_list);
}

$where_clauses[] = "p.is_active = 1";

$where_sql = 'WHERE ' . implode(' AND ', $where_clauses);

// Count total
$count_sql = "
    SELECT COUNT(*) as total
    FROM dbo.tmi_flow_measures m
    JOIN dbo.tmi_flow_providers p ON m.provider_id = p.provider_id
    $where_sql
";
$count_stmt = sqlsrv_query($conn_tmi, $count_sql, $params);
if ($count_stmt === false) {
    $errors = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}
$total = sqlsrv_fetch_array($count_stmt, SQLSRV_FETCH_ASSOC)['total'];
sqlsrv_free_stmt($count_stmt);

// Main query
$sql = "
    SELECT
        m.measure_id,
        m.measure_guid,
        p.provider_code,
        p.provider_name,
        m.external_id,
        m.ident,
        m.revision,
        m.event_id,
        e.event_code,
        e.event_name,
        m.ctl_element,
        m.element_type,
        m.measure_type,
        m.measure_value,
        m.measure_unit,
        m.reason,
        m.filters_json,
        m.exemptions_json,
        m.mandatory_route_json,
        m.start_utc,
        m.end_utc,
        m.status,
        m.withdrawn_at,
        m.synced_at,
        m.created_at
    FROM dbo.tmi_flow_measures m
    JOIN dbo.tmi_flow_providers p ON m.provider_id = p.provider_id
    LEFT JOIN dbo.tmi_flow_events e ON m.event_id = e.event_id
    $where_sql
    ORDER BY m.start_utc DESC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";

$params[] = $offset;
$params[] = $per_page;

$stmt = sqlsrv_query($conn_tmi, $sql, $params);
if ($stmt === false) {
    $errors = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}

$measures = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    // Parse JSON fields
    $filters = json_decode($row['filters_json'] ?? '{}', true) ?: [];
    $exemptions = json_decode($row['exemptions_json'] ?? '{}', true) ?: [];
    $mandatory_route = json_decode($row['mandatory_route_json'] ?? '[]', true) ?: [];

    $measure = [
        'id' => $row['measure_id'],
        'guid' => $row['measure_guid'],

        // Provider
        'provider' => [
            'code' => $row['provider_code'],
            'name' => $row['provider_name']
        ],

        // Measure identification (TFMS-aligned)
        'ident' => $row['ident'],
        'revision' => $row['revision'],

        // Event linkage (if applicable)
        'event' => $row['event_id'] ? [
            'id' => $row['event_id'],
            'code' => $row['event_code'],
            'name' => $row['event_name']
        ] : null,

        // Control element (align with tmi_programs)
        'controlElement' => $row['ctl_element'],
        'elementType' => $row['element_type'],

        // Measure type and value (TFMS/FIXM)
        'type' => $row['measure_type'],
        'value' => $row['measure_value'],
        'unit' => $row['measure_unit'],

        // Reason (FIXM: /atfm/reason)
        'reason' => $row['reason'],

        // Scope filters (FIXM: /atfm/flowElement)
        'filters' => [
            'departureAerodrome' => $filters['adep'] ?? null,
            'arrivalAerodrome' => $filters['ades'] ?? null,
            'departureFir' => $filters['adep_fir'] ?? null,
            'arrivalFir' => $filters['ades_fir'] ?? null,
            'waypoints' => $filters['waypoints'] ?? null,
            'airways' => $filters['airways'] ?? null,
            'flightLevel' => $filters['levels'] ?? null,
            'aircraftType' => $filters['aircraft_type'] ?? null,
            'memberEvent' => $filters['member_event'] ?? null,
            'memberNotEvent' => $filters['member_not_event'] ?? null
        ],

        // Exemptions
        'exemptions' => [
            'eventFlights' => $exemptions['event_flights'] ?? false,
            'carriers' => $exemptions['carriers'] ?? null,
            'aircraftTypes' => $exemptions['aircraft_types'] ?? null,
            'specialHandling' => $exemptions['special_handling'] ?? null
        ],

        // Mandatory route (FIXM: /flight/routeConstraint)
        'mandatoryRoute' => !empty($mandatory_route) ? $mandatory_route : null,

        // Time (FIXM: /base/timeRange)
        'timeRange' => [
            'start' => formatDT($row['start_utc']),
            'end' => formatDT($row['end_utc'])
        ],

        'status' => $row['status'],
        'withdrawnAt' => formatDT($row['withdrawn_at']),

        '_synced_at' => formatDT($row['synced_at']),
        '_created_at' => formatDT($row['created_at'])
    ];

    // Clean up null filter values
    $measure['filters'] = array_filter($measure['filters'], fn($v) => $v !== null);
    $measure['exemptions'] = array_filter($measure['exemptions'], fn($v) => $v !== null && $v !== false);

    $measures[] = $measure;
}
sqlsrv_free_stmt($stmt);

// Statistics
$stats = [
    'by_provider' => [],
    'by_type' => [],
    'by_status' => []
];
foreach ($measures as $measure) {
    $provider = $measure['provider']['code'];
    $stats['by_provider'][$provider] = ($stats['by_provider'][$provider] ?? 0) + 1;

    $type = $measure['type'];
    $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;

    $status = $measure['status'];
    $stats['by_status'][$status] = ($stats['by_status'][$status] ?? 0) + 1;
}

$response = [
    'measures' => $measures,
    'statistics' => $stats,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => ceil($total / $per_page),
        'has_more' => $page < ceil($total / $per_page)
    ],
    'filters' => [
        'provider' => $provider,
        'type' => $measure_type,
        'event_id' => $event_id,
        'airport' => $airport,
        'status' => $status,
        'active_only' => $active_only
    ]
];

SwimResponse::success($response, [
    'source' => 'vatsim_tmi',
    'table' => 'tmi_flow_measures'
]);

function formatDT($dt) {
    if ($dt === null) return null;
    return ($dt instanceof DateTime) ? $dt->format('c') : $dt;
}
