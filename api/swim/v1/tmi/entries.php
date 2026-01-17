<?php
/**
 * VATSIM SWIM API v1 - TMI Entries Endpoint
 * 
 * Returns NTML log entries (MIT, MINIT, STOP, APREQ, CFR, TBM, DELAY, CONFIG).
 * Data from VATSIM_TMI database (tmi_entries table).
 * 
 * GET /api/swim/v1/tmi/entries
 * GET /api/swim/v1/tmi/entries?type=MIT,STOP
 * GET /api/swim/v1/tmi/entries?airport=KJFK
 * GET /api/swim/v1/tmi/entries?active_only=1
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
$type = swim_get_param('type');              // Entry type filter (MIT, MINIT, STOP, etc.)
$airport = swim_get_param('airport');         // Filter by control element
$artcc = swim_get_param('artcc');             // Filter by facility
$status = swim_get_param('status');           // Filter by status
$active_only = swim_get_param('active_only', 'true') === 'true';
$include_history = swim_get_param('include_history', 'false') === 'true';

$page = swim_get_int_param('page', 1, 1, 1000);
$per_page = swim_get_int_param('per_page', SWIM_DEFAULT_PAGE_SIZE, 1, SWIM_MAX_PAGE_SIZE);
$offset = ($page - 1) * $per_page;

// Build query
$where_clauses = [];
$params = [];

if ($active_only && !$include_history) {
    $where_clauses[] = "e.status = 'ACTIVE'";
    $where_clauses[] = "(e.valid_until IS NULL OR e.valid_until > GETUTCDATE())";
}

if ($type) {
    $type_list = array_map('trim', explode(',', strtoupper($type)));
    $placeholders = implode(',', array_fill(0, count($type_list), '?'));
    $where_clauses[] = "e.entry_type IN ($placeholders)";
    $params = array_merge($params, $type_list);
}

if ($airport) {
    $airport_list = array_map('trim', explode(',', strtoupper($airport)));
    $placeholders = implode(',', array_fill(0, count($airport_list), '?'));
    $where_clauses[] = "e.ctl_element IN ($placeholders)";
    $params = array_merge($params, $airport_list);
}

if ($artcc) {
    $artcc_list = array_map('trim', explode(',', strtoupper($artcc)));
    $placeholders = implode(',', array_fill(0, count($artcc_list), '?'));
    $where_clauses[] = "(e.requesting_facility IN ($placeholders) OR e.providing_facility IN ($placeholders))";
    $params = array_merge($params, $artcc_list, $artcc_list);
}

if ($status) {
    $status_list = array_map('trim', explode(',', strtoupper($status)));
    $placeholders = implode(',', array_fill(0, count($status_list), '?'));
    $where_clauses[] = "e.status IN ($placeholders)";
    $params = array_merge($params, $status_list);
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Count total
$count_sql = "SELECT COUNT(*) as total FROM dbo.tmi_entries e $where_sql";
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
        e.entry_id,
        e.entry_guid,
        e.determinant_code,
        e.protocol_type,
        e.entry_type,
        e.ctl_element,
        e.condition_text,
        e.restriction_value,
        e.restriction_unit,
        e.qualifiers,
        e.aircraft_type,
        e.altitude,
        e.alt_type,
        e.speed,
        e.speed_operator,
        e.reason_code,
        e.reason_detail,
        e.exclusions,
        e.flow_type,
        e.valid_from,
        e.valid_until,
        e.requesting_facility,
        e.providing_facility,
        e.status,
        e.source_type,
        e.source_id,
        e.raw_text,
        e.created_at,
        e.created_by
    FROM dbo.tmi_entries e
    $where_sql
    ORDER BY e.created_at DESC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";

$params[] = $offset;
$params[] = $per_page;

$stmt = sqlsrv_query($conn_tmi, $sql, $params);
if ($stmt === false) {
    $errors = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}

$entries = [];
$stats = [
    'by_type' => [],
    'by_airport' => [],
    'by_facility' => []
];

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $entry = formatEntry($row);
    $entries[] = $entry;
    
    // Update stats
    $type = $row['entry_type'];
    $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;
    
    $airport = $row['ctl_element'];
    if ($airport) {
        $stats['by_airport'][$airport] = ($stats['by_airport'][$airport] ?? 0) + 1;
    }
    
    $facility = $row['requesting_facility'];
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
    'data' => $entries,
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
        'artcc' => $artcc,
        'active_only' => $active_only
    ],
    'timestamp' => gmdate('c'),
    'meta' => [
        'source' => 'vatsim_tmi',
        'table' => 'tmi_entries'
    ]
];

SwimResponse::json($response);


function formatEntry($row) {
    // Parse qualifiers JSON if stored as string
    $qualifiers = $row['qualifiers'];
    if (is_string($qualifiers) && !empty($qualifiers)) {
        $decoded = json_decode($qualifiers, true);
        $qualifiers = $decoded ?: explode(',', $qualifiers);
    }
    
    return [
        'entry_id' => $row['entry_id'],
        'entry_guid' => $row['entry_guid'],
        'determinant' => $row['determinant_code'],
        'protocol' => $row['protocol_type'],
        'type' => $row['entry_type'],
        
        'control_element' => $row['ctl_element'],
        'condition' => $row['condition_text'],
        
        'restriction' => [
            'value' => $row['restriction_value'],
            'unit' => $row['restriction_unit'] ?? ($row['entry_type'] === 'MIT' ? 'NM' : 'MIN'),
            'type' => $row['entry_type']
        ],
        
        'qualifiers' => $qualifiers,
        
        'filters' => [
            'aircraft_type' => $row['aircraft_type'],
            'altitude' => $row['altitude'],
            'altitude_type' => $row['alt_type'],
            'speed' => $row['speed'],
            'speed_operator' => $row['speed_operator']
        ],
        
        'reason' => [
            'code' => $row['reason_code'],
            'detail' => $row['reason_detail']
        ],
        
        'exclusions' => $row['exclusions'],
        'flow_type' => $row['flow_type'],
        
        'validity' => [
            'from' => formatDT($row['valid_from']),
            'until' => formatDT($row['valid_until'])
        ],
        
        'coordination' => [
            'requesting' => $row['requesting_facility'],
            'providing' => $row['providing_facility']
        ],
        
        'status' => $row['status'],
        'source' => [
            'type' => $row['source_type'],
            'id' => $row['source_id']
        ],
        
        'raw_text' => $row['raw_text'],
        
        '_created_at' => formatDT($row['created_at']),
        '_created_by' => $row['created_by']
    ];
}

function formatDT($dt) {
    if ($dt === null) return null;
    return ($dt instanceof DateTime) ? $dt->format('c') : $dt;
}
