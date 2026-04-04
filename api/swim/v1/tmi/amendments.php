<?php
/**
 * VATSWIM API v1 - Route Amendments Endpoint
 *
 * Returns route amendment dialogue (RAD) data from SWIM mirror.
 * Data from SWIM_API database (swim_rad_amendments mirror table).
 *
 * GET /api/swim/v1/tmi/amendments                     - List amendments (active by default)
 * GET /api/swim/v1/tmi/amendments?all=true            - Include resolved/expired
 * GET /api/swim/v1/tmi/amendments?callsign=DAL301     - Filter by callsign
 * GET /api/swim/v1/tmi/amendments?origin=KATL         - Filter by origin
 * GET /api/swim/v1/tmi/amendments?dest=KLAX           - Filter by destination
 * GET /api/swim/v1/tmi/amendments?id=29               - Get single amendment
 * GET /api/swim/v1/tmi/amendments?reroute_id=2172     - Get amendments for a reroute
 *
 * @version 1.0.0
 */

require_once __DIR__ . '/../auth.php';

global $conn_swim;

if (!$conn_swim) {
    SwimResponse::error('SWIM database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

$auth = swim_init_auth(false, false);  // Public read access

// Parameters
$id = swim_get_int_param('id');
$callsign = swim_get_param('callsign');
$origin = swim_get_param('origin');
$dest = swim_get_param('dest');
$status = swim_get_param('status');
$reroute_id = swim_get_int_param('reroute_id');
$show_all = swim_get_param('all', 'false') === 'true';

$page = swim_get_int_param('page', 1, 1, 1000);
$per_page = swim_get_int_param('per_page', SWIM_DEFAULT_PAGE_SIZE, 1, SWIM_MAX_PAGE_SIZE);
$offset = ($page - 1) * $per_page;

// Single amendment by ID
if ($id) {
    $sql = "SELECT * FROM dbo.swim_rad_amendments WHERE id = ?";
    $stmt = sqlsrv_query($conn_swim, $sql, [$id]);
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        SwimResponse::error('Database error: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$row) {
        SwimResponse::error('Amendment not found', 404, 'NOT_FOUND');
    }

    SwimResponse::success(['amendment' => formatAmendment($row)], ['source' => 'swim_api']);
    exit;
}

// Build query for list
$where_clauses = [];
$params = [];

if (!$show_all) {
    $where_clauses[] = "status NOT IN ('ACPT','RJCT','EXPR')";
}

if ($callsign) {
    $where_clauses[] = "callsign = ?";
    $params[] = strtoupper($callsign);
}

if ($origin) {
    $where_clauses[] = "origin = ?";
    $params[] = strtoupper($origin);
}

if ($dest) {
    $where_clauses[] = "destination = ?";
    $params[] = strtoupper($dest);
}

if ($status) {
    $where_clauses[] = "status = ?";
    $params[] = strtoupper($status);
}

if ($reroute_id) {
    $where_clauses[] = "tmi_reroute_id = ?";
    $params[] = $reroute_id;
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Count total
$count_sql = "SELECT COUNT(*) as total FROM dbo.swim_rad_amendments $where_sql";
$count_stmt = sqlsrv_query($conn_swim, $count_sql, $params);
if ($count_stmt === false) {
    $errors = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}
$total = (int)sqlsrv_fetch_array($count_stmt, SQLSRV_FETCH_ASSOC)['total'];
sqlsrv_free_stmt($count_stmt);

// Main query
$sql = "
    SELECT *
    FROM dbo.swim_rad_amendments
    $where_sql
    ORDER BY created_utc DESC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";

$params[] = $offset;
$params[] = $per_page;

$stmt = sqlsrv_query($conn_swim, $sql, $params);
if ($stmt === false) {
    $errors = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}

$amendments = [];
$stats = ['by_status' => []];

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $amendment = formatAmendment($row);
    $amendments[] = $amendment;

    $s = $row['status'] ?? 'UNKNOWN';
    $stats['by_status'][$s] = ($stats['by_status'][$s] ?? 0) + 1;
}
sqlsrv_free_stmt($stmt);

$response = [
    'success' => true,
    'data' => $amendments,
    'statistics' => $stats,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => $total > 0 ? ceil($total / $per_page) : 0,
        'has_more' => $page < ceil($total / $per_page)
    ],
    'timestamp' => gmdate('c'),
    'meta' => [
        'source' => 'swim_api',
        'table' => 'swim_rad_amendments'
    ]
];

SwimResponse::json($response);


/**
 * Format an amendment row for API output
 */
function formatAmendment($row) {
    return [
        'id' => (int)$row['id'],
        'gufi' => $row['gufi'] ?? null,
        'callsign' => $row['callsign'],
        'origin' => trim($row['origin']),
        'destination' => trim($row['destination']),

        'routes' => [
            'original' => $row['original_route'] ?? null,
            'assigned' => $row['assigned_route'],
            'geojson' => parseJsonField($row['assigned_route_geojson'] ?? null),
        ],

        'status' => $row['status'],
        'rrstat' => $row['rrstat'] ?? null,
        'tmi_reroute_id' => $row['tmi_reroute_id'] ?? null,
        'tmi_id_label' => $row['tmi_id_label'] ?? null,

        'delivery' => [
            'channels' => $row['delivery_channels'] ?? null,
            'route_color' => $row['route_color'] ?? null,
        ],

        'timing' => [
            'created' => formatDT($row['created_utc'] ?? null),
            'sent' => formatDT($row['sent_utc'] ?? null),
            'delivered' => formatDT($row['delivered_utc'] ?? null),
            'resolved' => formatDT($row['resolved_utc'] ?? null),
            'expires' => formatDT($row['expires_utc'] ?? null),
        ],

        'created_by' => $row['created_by'] ?? null,
        'notes' => $row['notes'] ?? null,
    ];
}

function parseJsonField($value) {
    if ($value === null || $value === '') return null;
    if (is_array($value)) return $value;
    $decoded = json_decode($value, true);
    return $decoded !== null ? $decoded : $value;
}

function formatDT($dt) {
    if ($dt === null) return null;
    return ($dt instanceof DateTime) ? $dt->format('c') : $dt;
}
