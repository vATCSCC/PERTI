<?php
/**
 * VATSIM SWIM API v1 - Flow Events Endpoint
 *
 * Returns special events (CTP, FNO, etc.) from external flow management providers.
 * FIXM-aligned: maps to /flight/specialHandling and /atfm/event
 *
 * GET /api/swim/v1/tmi/flow/events
 * GET /api/swim/v1/tmi/flow/events?provider=ECFMP
 * GET /api/swim/v1/tmi/flow/events?code=CTP2026
 * GET /api/swim/v1/tmi/flow/events?status=ACTIVE
 * GET /api/swim/v1/tmi/flow/events?include_participants=true
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
$event_code = swim_get_param('code');
$event_id = swim_get_param('id');
$status = swim_get_param('status');
$active_only = swim_get_param('active_only', 'true') === 'true';
$include_participants = swim_get_param('include_participants', 'false') === 'true';
$include_history = swim_get_param('include_history', 'false') === 'true';

$page = swim_get_int_param('page', 1, 1, 1000);
$per_page = swim_get_int_param('per_page', SWIM_DEFAULT_PAGE_SIZE, 1, SWIM_MAX_PAGE_SIZE);
$offset = ($page - 1) * $per_page;

// Build query
$where_clauses = [];
$params = [];

if ($active_only && !$include_history) {
    $where_clauses[] = "e.status IN ('SCHEDULED', 'ACTIVE')";
    $where_clauses[] = "e.end_utc > SYSUTCDATETIME()";
}

if ($provider) {
    $provider_list = array_map('trim', explode(',', strtoupper($provider)));
    $placeholders = implode(',', array_fill(0, count($provider_list), '?'));
    $where_clauses[] = "p.provider_code IN ($placeholders)";
    $params = array_merge($params, $provider_list);
}

if ($event_code) {
    $code_list = array_map('trim', explode(',', strtoupper($event_code)));
    $placeholders = implode(',', array_fill(0, count($code_list), '?'));
    $where_clauses[] = "e.event_code IN ($placeholders)";
    $params = array_merge($params, $code_list);
}

if ($event_id) {
    $where_clauses[] = "e.event_id = ?";
    $params[] = intval($event_id);
}

if ($status) {
    $status_list = array_map('trim', explode(',', strtoupper($status)));
    $placeholders = implode(',', array_fill(0, count($status_list), '?'));
    $where_clauses[] = "e.status IN ($placeholders)";
    $params = array_merge($params, $status_list);
}

$where_clauses[] = "p.is_active = 1";

$where_sql = 'WHERE ' . implode(' AND ', $where_clauses);

// Count total
$count_sql = "
    SELECT COUNT(*) as total
    FROM dbo.tmi_flow_events e
    JOIN dbo.tmi_flow_providers p ON e.provider_id = p.provider_id
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
        e.event_id,
        e.event_guid,
        p.provider_code,
        p.provider_name,
        e.external_id,
        e.event_code,
        e.event_name,
        e.event_type,
        e.fir_ids_json,
        e.start_utc,
        e.end_utc,
        e.gs_exempt,
        e.gdp_priority,
        e.status,
        e.participant_count,
        e.synced_at,
        e.created_at
    FROM dbo.tmi_flow_events e
    JOIN dbo.tmi_flow_providers p ON e.provider_id = p.provider_id
    $where_sql
    ORDER BY e.start_utc DESC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";

$params[] = $offset;
$params[] = $per_page;

$stmt = sqlsrv_query($conn_tmi, $sql, $params);
if ($stmt === false) {
    $errors = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}

$events = [];
$event_ids = [];

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $fir_ids = json_decode($row['fir_ids_json'] ?? '[]', true) ?: [];

    $event = [
        'id' => $row['event_id'],
        'guid' => $row['event_guid'],

        // Provider
        'provider' => [
            'code' => $row['provider_code'],
            'name' => $row['provider_name']
        ],

        // Event identification (FIXM: /flight/specialHandling)
        'code' => $row['event_code'],
        'name' => $row['event_name'],
        'type' => $row['event_type'],

        // Scope (FIXM: flightInformationRegion)
        'firs' => $fir_ids,

        // Time (FIXM: /base/timeRange)
        'timeRange' => [
            'start' => formatDT($row['start_utc']),
            'end' => formatDT($row['end_utc'])
        ],

        // TMI exemptions (TFMS-aligned)
        'exemptions' => [
            'groundStop' => (bool)$row['gs_exempt'],
            'gdpPriority' => (bool)$row['gdp_priority']
        ],

        'status' => $row['status'],
        'participantCount' => $row['participant_count'],

        '_synced_at' => formatDT($row['synced_at']),
        '_created_at' => formatDT($row['created_at'])
    ];

    $events[] = $event;
    $event_ids[] = $row['event_id'];
}
sqlsrv_free_stmt($stmt);

// Fetch participants if requested
if ($include_participants && !empty($event_ids)) {
    $placeholders = implode(',', array_fill(0, count($event_ids), '?'));
    $participants_sql = "
        SELECT
            event_id,
            pilot_cid,
            callsign,
            dep_aerodrome,
            arr_aerodrome,
            flight_uid,
            matched_at
        FROM dbo.tmi_flow_event_participants
        WHERE event_id IN ($placeholders)
        ORDER BY event_id, dep_aerodrome, arr_aerodrome
    ";

    $participants_stmt = sqlsrv_query($conn_tmi, $participants_sql, $event_ids);
    if ($participants_stmt !== false) {
        $participants_by_event = [];
        while ($row = sqlsrv_fetch_array($participants_stmt, SQLSRV_FETCH_ASSOC)) {
            $eid = $row['event_id'];
            if (!isset($participants_by_event[$eid])) {
                $participants_by_event[$eid] = [];
            }
            $participants_by_event[$eid][] = [
                'cid' => $row['pilot_cid'],
                'callsign' => $row['callsign'],
                'departure' => $row['dep_aerodrome'],
                'arrival' => $row['arr_aerodrome'],
                'flightMatched' => $row['flight_uid'] !== null,
                'matchedAt' => formatDT($row['matched_at'])
            ];
        }
        sqlsrv_free_stmt($participants_stmt);

        // Attach participants to events
        foreach ($events as &$event) {
            $event['participants'] = $participants_by_event[$event['id']] ?? [];
        }
    }
}

// Statistics
$stats = [
    'by_provider' => [],
    'by_status' => [],
    'by_type' => []
];
foreach ($events as $event) {
    $provider = $event['provider']['code'];
    $stats['by_provider'][$provider] = ($stats['by_provider'][$provider] ?? 0) + 1;

    $status = $event['status'];
    $stats['by_status'][$status] = ($stats['by_status'][$status] ?? 0) + 1;

    $type = $event['type'];
    $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;
}

$response = [
    'events' => $events,
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
        'code' => $event_code,
        'status' => $status,
        'active_only' => $active_only,
        'include_participants' => $include_participants
    ]
];

SwimResponse::success($response, [
    'source' => 'vatsim_tmi',
    'table' => 'tmi_flow_events'
]);

function formatDT($dt) {
    if ($dt === null) return null;
    return ($dt instanceof DateTime) ? $dt->format('c') : $dt;
}
