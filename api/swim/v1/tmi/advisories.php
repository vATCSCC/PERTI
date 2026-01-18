<?php
/**
 * VATSWIM API v1 - TMI Advisories Endpoint
 * 
 * Returns formal TMI advisories (Ground Stop, GDP, Reroute, etc.).
 * Data from VATSIM_TMI database (tmi_advisories table).
 * 
 * GET /api/swim/v1/tmi/advisories
 * GET /api/swim/v1/tmi/advisories?type=GS,GDP
 * GET /api/swim/v1/tmi/advisories?airport=KJFK
 * GET /api/swim/v1/tmi/advisories?active_only=1
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
$type = swim_get_param('type');              // Advisory type filter (GS, GDP, REROUTE, etc.)
$airport = swim_get_param('airport');         // Filter by control element
$facility = swim_get_param('facility');       // Filter by issuing facility
$status = swim_get_param('status');           // Filter by status
$active_only = swim_get_param('active_only', 'true') === 'true';
$include_text = swim_get_param('include_text', 'true') === 'true';

$page = swim_get_int_param('page', 1, 1, 1000);
$per_page = swim_get_int_param('per_page', SWIM_DEFAULT_PAGE_SIZE, 1, SWIM_MAX_PAGE_SIZE);
$offset = ($page - 1) * $per_page;

// Build query
$where_clauses = [];
$params = [];

if ($active_only) {
    $where_clauses[] = "a.status = 'ACTIVE'";
    $where_clauses[] = "(a.valid_until IS NULL OR a.valid_until > GETUTCDATE())";
}

if ($type) {
    $type_list = array_map('trim', explode(',', strtoupper($type)));
    $placeholders = implode(',', array_fill(0, count($type_list), '?'));
    $where_clauses[] = "a.advisory_type IN ($placeholders)";
    $params = array_merge($params, $type_list);
}

if ($airport) {
    $airport_list = array_map('trim', explode(',', strtoupper($airport)));
    $placeholders = implode(',', array_fill(0, count($airport_list), '?'));
    $where_clauses[] = "a.ctl_element IN ($placeholders)";
    $params = array_merge($params, $airport_list);
}

if ($facility) {
    $facility_list = array_map('trim', explode(',', strtoupper($facility)));
    $placeholders = implode(',', array_fill(0, count($facility_list), '?'));
    $where_clauses[] = "a.issuing_facility IN ($placeholders)";
    $params = array_merge($params, $facility_list);
}

if ($status) {
    $status_list = array_map('trim', explode(',', strtoupper($status)));
    $placeholders = implode(',', array_fill(0, count($status_list), '?'));
    $where_clauses[] = "a.status IN ($placeholders)";
    $params = array_merge($params, $status_list);
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Count total
$count_sql = "SELECT COUNT(*) as total FROM dbo.tmi_advisories a $where_sql";
$count_stmt = sqlsrv_query($conn_tmi, $count_sql, $params);
if ($count_stmt === false) {
    $errors = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}
$total = sqlsrv_fetch_array($count_stmt, SQLSRV_FETCH_ASSOC)['total'];
sqlsrv_free_stmt($count_stmt);

// Main query - select columns based on include_text flag
$text_columns = $include_text ? ", a.advisory_text, a.comments" : "";

$sql = "
    SELECT 
        a.advisory_id,
        a.advisory_guid,
        a.advisory_number,
        a.advisory_type,
        a.ctl_element,
        a.element_type,
        a.issuing_facility,
        a.artcc,
        a.is_proposed,
        a.revision_number,
        a.supersedes_advisory_id,
        a.reason_code,
        a.reason_detail,
        a.probability_extension,
        a.valid_from,
        a.valid_until,
        a.program_start,
        a.program_end,
        a.cumulative_start,
        a.cumulative_end,
        a.program_rate,
        a.delay_limit,
        a.max_delay,
        a.avg_delay,
        a.scope_json,
        a.status,
        a.source_type,
        a.source_id,
        a.created_at,
        a.created_by
        $text_columns
    FROM dbo.tmi_advisories a
    $where_sql
    ORDER BY a.created_at DESC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";

$params[] = $offset;
$params[] = $per_page;

$stmt = sqlsrv_query($conn_tmi, $sql, $params);
if ($stmt === false) {
    $errors = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}

$advisories = [];
$stats = [
    'by_type' => [],
    'by_airport' => [],
    'by_facility' => []
];

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $advisory = formatAdvisory($row, $include_text);
    $advisories[] = $advisory;
    
    // Update stats
    $type = $row['advisory_type'];
    $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;
    
    $airport = $row['ctl_element'];
    if ($airport) {
        $stats['by_airport'][$airport] = ($stats['by_airport'][$airport] ?? 0) + 1;
    }
    
    $facility = $row['issuing_facility'];
    if ($facility) {
        $stats['by_facility'][$facility] = ($stats['by_facility'][$facility] ?? 0) + 1;
    }
}
sqlsrv_free_stmt($stmt);

// Sort stats
arsort($stats['by_type']);
arsort($stats['by_airport']);
arsort($stats['by_facility']);

$response = [
    'success' => true,
    'data' => $advisories,
    'statistics' => $stats,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => ceil($total / $per_page),
        'has_more' => $page < ceil($total / $per_page)
    ],
    'filters' => [
        'type' => $type,
        'airport' => $airport,
        'facility' => $facility,
        'active_only' => $active_only
    ],
    'timestamp' => gmdate('c'),
    'meta' => [
        'source' => 'vatsim_tmi',
        'table' => 'tmi_advisories'
    ]
];

SwimResponse::json($response);


function formatAdvisory($row, $include_text = true) {
    // Parse scope JSON
    $scope = null;
    if (!empty($row['scope_json'])) {
        $scope = json_decode($row['scope_json'], true);
    }
    
    $advisory = [
        'advisory_id' => $row['advisory_id'],
        'advisory_guid' => $row['advisory_guid'],
        'advisory_number' => $row['advisory_number'],
        'type' => $row['advisory_type'],
        
        'control_element' => $row['ctl_element'],
        'element_type' => $row['element_type'],
        'issuing_facility' => $row['issuing_facility'],
        'artcc' => $row['artcc'],
        
        'is_proposed' => (bool)$row['is_proposed'],
        'revision' => $row['revision_number'],
        'supersedes' => $row['supersedes_advisory_id'],
        
        'reason' => [
            'code' => $row['reason_code'],
            'detail' => $row['reason_detail']
        ],
        
        'probability_extension' => $row['probability_extension'],
        
        'validity' => [
            'from' => formatDT($row['valid_from']),
            'until' => formatDT($row['valid_until'])
        ],
        
        'program_period' => [
            'start' => formatDT($row['program_start']),
            'end' => formatDT($row['program_end'])
        ],
        
        'cumulative_period' => [
            'start' => formatDT($row['cumulative_start']),
            'end' => formatDT($row['cumulative_end'])
        ],
        
        'rates' => [
            'program_rate' => $row['program_rate'],
            'delay_limit' => $row['delay_limit'],
            'max_delay' => $row['max_delay'],
            'avg_delay' => $row['avg_delay']
        ],
        
        'scope' => $scope,
        'status' => $row['status'],
        
        'source' => [
            'type' => $row['source_type'],
            'id' => $row['source_id']
        ],
        
        '_created_at' => formatDT($row['created_at']),
        '_created_by' => $row['created_by']
    ];
    
    // Add text fields if requested
    if ($include_text) {
        $advisory['advisory_text'] = $row['advisory_text'] ?? null;
        $advisory['comments'] = $row['comments'] ?? null;
    }
    
    return $advisory;
}

function formatDT($dt) {
    if ($dt === null) return null;
    return ($dt instanceof DateTime) ? $dt->format('c') : $dt;
}
