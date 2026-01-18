<?php
/**
 * VATSWIM API v1 - TMI Reroutes Endpoint
 * 
 * Returns active reroute definitions and their flight assignments.
 * Data from VATSIM_TMI database (tmi_reroutes, tmi_reroute_flights tables).
 * 
 * GET /api/swim/v1/tmi/reroutes
 * GET /api/swim/v1/tmi/reroutes?origin=ZBW
 * GET /api/swim/v1/tmi/reroutes?dest=KJFK
 * GET /api/swim/v1/tmi/reroutes?id=123&flights=1
 * 
 * @version 1.0.0
 */

require_once __DIR__ . '/../auth.php';

// TMI database connection
global $conn_tmi;

if (!$conn_tmi) {
    SwimResponse::error('TMI database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

$auth = swim_init_auth(true, false);

// Get filter parameters
$id = swim_get_int_param('id');
$origin = swim_get_param('origin');           // Origin center filter
$dest = swim_get_param('dest');               // Destination airport filter
$status = swim_get_param('status');           // Filter by status
$active_only = swim_get_param('active_only', 'true') === 'true';
$include_flights = swim_get_param('flights', 'false') === 'true';
$include_compliance = swim_get_param('compliance', 'false') === 'true';

$page = swim_get_int_param('page', 1, 1, 1000);
$per_page = swim_get_int_param('per_page', SWIM_DEFAULT_PAGE_SIZE, 1, SWIM_MAX_PAGE_SIZE);
$offset = ($page - 1) * $per_page;

// Single reroute by ID
if ($id) {
    $sql = "
        SELECT 
            r.*
        FROM dbo.tmi_reroutes r
        WHERE r.reroute_id = ?
    ";
    
    $stmt = sqlsrv_query($conn_tmi, $sql, [$id]);
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        SwimResponse::error('Database error: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
    }
    
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    
    if (!$row) {
        SwimResponse::error('Reroute not found', 404, 'NOT_FOUND');
    }
    
    $reroute = formatReroute($row);
    
    // Include assigned flights if requested
    if ($include_flights) {
        $reroute['flights'] = getRerouteFlights($conn_tmi, $id);
    }
    
    // Include compliance summary if requested
    if ($include_compliance) {
        $reroute['compliance'] = getRerouteCompliance($conn_tmi, $id);
    }
    
    SwimResponse::success(['reroute' => $reroute], ['source' => 'vatsim_tmi']);
    exit;
}

// Build query for list
$where_clauses = [];
$params = [];

if ($active_only) {
    $where_clauses[] = "r.status IN (2, 3)";  // ACTIVE or MONITORING
    $where_clauses[] = "(r.end_utc IS NULL OR r.end_utc > GETUTCDATE())";
}

if ($origin) {
    $origin_list = array_map('trim', explode(',', strtoupper($origin)));
    $placeholders = implode(',', array_fill(0, count($origin_list), '?'));
    $where_clauses[] = "r.origin_centers LIKE '%' + ? + '%'";
    $params = array_merge($params, $origin_list);
}

if ($dest) {
    $dest_list = array_map('trim', explode(',', strtoupper($dest)));
    $placeholders = implode(',', array_fill(0, count($dest_list), '?'));
    $where_clauses[] = "r.dest_airports LIKE '%' + ? + '%'";
    $params = array_merge($params, $dest_list);
}

if ($status) {
    $status_list = array_map('intval', explode(',', $status));
    $placeholders = implode(',', array_fill(0, count($status_list), '?'));
    $where_clauses[] = "r.status IN ($placeholders)";
    $params = array_merge($params, $status_list);
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Count total
$count_sql = "SELECT COUNT(*) as total FROM dbo.tmi_reroutes r $where_sql";
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
        r.*
    FROM dbo.tmi_reroutes r
    $where_sql
    ORDER BY r.created_at DESC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";

$params[] = $offset;
$params[] = $per_page;

$stmt = sqlsrv_query($conn_tmi, $sql, $params);
if ($stmt === false) {
    $errors = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}

$reroutes = [];
$stats = [
    'by_status' => [],
    'by_origin' => [],
    'by_dest' => []
];

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $reroute = formatReroute($row);
    $reroutes[] = $reroute;
    
    // Update stats
    $status = getStatusName($row['status']);
    $stats['by_status'][$status] = ($stats['by_status'][$status] ?? 0) + 1;
}
sqlsrv_free_stmt($stmt);

$response = [
    'success' => true,
    'data' => $reroutes,
    'statistics' => $stats,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => ceil($total / $per_page),
        'has_more' => $page < ceil($total / $per_page)
    ],
    'filters' => [
        'origin' => $origin,
        'dest' => $dest,
        'active_only' => $active_only
    ],
    'timestamp' => gmdate('c'),
    'meta' => [
        'source' => 'vatsim_tmi',
        'table' => 'tmi_reroutes'
    ]
];

SwimResponse::json($response);


function formatReroute($row) {
    // Parse JSON fields
    $protectedFixes = !empty($row['protected_fixes']) ? json_decode($row['protected_fixes'], true) : [];
    $avoidFixes = !empty($row['avoid_fixes']) ? json_decode($row['avoid_fixes'], true) : [];
    $originCenters = !empty($row['origin_centers']) ? json_decode($row['origin_centers'], true) : [];
    $destAirports = !empty($row['dest_airports']) ? json_decode($row['dest_airports'], true) : [];
    $scope = !empty($row['scope_json']) ? json_decode($row['scope_json'], true) : null;
    
    return [
        'reroute_id' => $row['reroute_id'],
        'reroute_guid' => $row['reroute_guid'] ?? null,
        'name' => $row['name'],
        'status' => $row['status'],
        'status_name' => getStatusName($row['status']),
        
        'times' => [
            'start' => formatDT($row['start_utc']),
            'end' => formatDT($row['end_utc'])
        ],
        
        'route' => [
            'protected_segment' => $row['protected_segment'],
            'protected_fixes' => $protectedFixes,
            'avoid_fixes' => $avoidFixes
        ],
        
        'scope' => [
            'origin_centers' => $originCenters,
            'dest_airports' => $destAirports,
            'custom' => $scope
        ],
        
        'filters' => [
            'aircraft_type' => $row['flt_incl_type'] ?? null,
            'carrier' => $row['flt_incl_carrier'] ?? null,
            'weight_class' => $row['flt_incl_weight'] ?? null
        ],
        
        'reason' => [
            'code' => $row['reason_code'] ?? null,
            'detail' => $row['reason_detail'] ?? null
        ],
        
        'probability_extension' => $row['probability_extension'] ?? null,
        
        'metrics' => [
            'total_flights' => $row['total_flights'] ?? 0,
            'compliant_flights' => $row['compliant_flights'] ?? 0,
            'noncompliant_flights' => $row['noncompliant_flights'] ?? 0,
            'compliance_rate' => $row['compliance_rate'] ?? null
        ],
        
        'source' => [
            'type' => $row['source_type'] ?? null,
            'id' => $row['source_id'] ?? null
        ],
        
        'advisory_id' => $row['advisory_id'] ?? null,
        
        '_created_at' => formatDT($row['created_at']),
        '_created_by' => $row['created_by'] ?? null
    ];
}

function getRerouteFlights($conn, $rerouteId) {
    $sql = "
        SELECT 
            f.flight_uid,
            f.callsign,
            f.dept_icao,
            f.dest_icao,
            f.current_route,
            f.filed_route,
            f.is_compliant,
            f.compliance_status,
            f.assigned_at,
            f.last_checked_at
        FROM dbo.tmi_reroute_flights f
        WHERE f.reroute_id = ?
        ORDER BY f.assigned_at DESC
    ";
    
    $stmt = sqlsrv_query($conn, $sql, [$rerouteId]);
    if ($stmt === false) return [];
    
    $flights = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $flights[] = [
            'flight_uid' => $row['flight_uid'],
            'callsign' => $row['callsign'],
            'departure' => $row['dept_icao'],
            'destination' => $row['dest_icao'],
            'current_route' => $row['current_route'],
            'filed_route' => $row['filed_route'],
            'is_compliant' => (bool)$row['is_compliant'],
            'compliance_status' => $row['compliance_status'],
            'assigned_at' => formatDT($row['assigned_at']),
            'last_checked_at' => formatDT($row['last_checked_at'])
        ];
    }
    sqlsrv_free_stmt($stmt);
    
    return $flights;
}

function getRerouteCompliance($conn, $rerouteId) {
    $sql = "
        SELECT 
            c.check_time,
            c.total_flights,
            c.compliant_flights,
            c.noncompliant_flights,
            c.compliance_rate
        FROM dbo.tmi_reroute_compliance_log c
        WHERE c.reroute_id = ?
        ORDER BY c.check_time DESC
        OFFSET 0 ROWS FETCH NEXT 24 ROWS ONLY
    ";
    
    $stmt = sqlsrv_query($conn, $sql, [$rerouteId]);
    if ($stmt === false) return [];
    
    $history = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $history[] = [
            'check_time' => formatDT($row['check_time']),
            'total_flights' => $row['total_flights'],
            'compliant' => $row['compliant_flights'],
            'noncompliant' => $row['noncompliant_flights'],
            'rate' => floatval($row['compliance_rate'])
        ];
    }
    sqlsrv_free_stmt($stmt);
    
    return $history;
}

function getStatusName($status) {
    $names = [
        0 => 'DRAFT',
        1 => 'PROPOSED',
        2 => 'ACTIVE',
        3 => 'MONITORING',
        4 => 'EXPIRED',
        5 => 'CANCELLED'
    ];
    return $names[$status] ?? 'UNKNOWN';
}

function formatDT($dt) {
    if ($dt === null) return null;
    return ($dt instanceof DateTime) ? $dt->format('c') : $dt;
}
