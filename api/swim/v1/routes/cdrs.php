<?php
/**
 * VATSWIM API v1 - Coded Departure Routes (CDR) Endpoint
 *
 * Read-only access to coded departure routes from the VATSIM_REF database.
 * CDRs define pre-coordinated routes between airport pairs, used for
 * traffic management rerouting and playbook operations.
 *
 * GET /api/swim/v1/routes/cdrs
 *
 * Query Parameters:
 *   origin    - Filter by origin_icao (exact match, case-insensitive)
 *   dest      - Filter by dest_icao (exact match, case-insensitive)
 *   code      - Filter by cdr_code (prefix match)
 *   search    - Free-text search across cdr_code + full_route (LIKE %search%)
 *   artcc     - Filter by dep_artcc OR arr_artcc (aliases: fir, acc)
 *   dep_artcc - Filter by dep_artcc only (aliases: dep_fir, dep_acc)
 *   arr_artcc - Filter by arr_artcc only (aliases: arr_fir, arr_acc)
 *   page      - Page number (default 1, min 1, max 5000)
 *   per_page  - Results per page (default 50, max 200)
 *
 * @version 1.0.0
 * @since 2026-03-13
 */

require_once __DIR__ . '/../auth.php';

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

// Only GET is supported
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    SwimResponse::error('Method not allowed. Only GET is supported.', 405, 'METHOD_NOT_ALLOWED');
}

// Public endpoint -- auth is optional
swim_init_auth(false, false);

// Get REF database connection (lazy-loaded sqlsrv to VATSIM_REF)
$conn_ref = get_conn_ref();
if (!$conn_ref) {
    SwimResponse::error('Reference database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

// Parse query parameters
$origin    = swim_get_param('origin');
$dest      = swim_get_param('dest');
$code      = swim_get_param('code');
$search    = swim_get_param('search');
$artcc     = swim_get_param('artcc') ?? swim_get_param('fir') ?? swim_get_param('acc');
$dep_artcc = swim_get_param('dep_artcc') ?? swim_get_param('dep_fir') ?? swim_get_param('dep_acc');
$arr_artcc = swim_get_param('arr_artcc') ?? swim_get_param('arr_fir') ?? swim_get_param('arr_acc');
$page      = swim_get_int_param('page', 1, 1, 5000);
$per_page  = swim_get_int_param('per_page', 50, 1, 200);
$offset    = ($page - 1) * $per_page;

// Build WHERE clauses with parameterized values
$where_clauses = [];
$params = [];

if ($origin !== null && $origin !== '') {
    $where_clauses[] = "origin_icao = ?";
    $params[] = strtoupper(trim($origin));
}

if ($dest !== null && $dest !== '') {
    $where_clauses[] = "dest_icao = ?";
    $params[] = strtoupper(trim($dest));
}

if ($code !== null && $code !== '') {
    $where_clauses[] = "cdr_code LIKE ?";
    $params[] = strtoupper(trim($code)) . '%';
}

if ($search !== null && $search !== '') {
    $where_clauses[] = "(cdr_code LIKE '%' + ? + '%' OR full_route LIKE '%' + ? + '%')";
    $search_val = trim($search);
    $params[] = $search_val;
    $params[] = $search_val;
}

if ($artcc !== null && $artcc !== '') {
    $artcc_val = strtoupper(trim($artcc));
    $where_clauses[] = "(dep_artcc = ? OR arr_artcc = ?)";
    $params[] = $artcc_val;
    $params[] = $artcc_val;
}

if ($dep_artcc !== null && $dep_artcc !== '') {
    $where_clauses[] = "dep_artcc = ?";
    $params[] = strtoupper(trim($dep_artcc));
}

if ($arr_artcc !== null && $arr_artcc !== '') {
    $where_clauses[] = "arr_artcc = ?";
    $params[] = strtoupper(trim($arr_artcc));
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Count total matching rows
$count_sql = "SELECT COUNT(*) AS total FROM dbo.coded_departure_routes $where_sql";
$count_stmt = sqlsrv_query($conn_ref, $count_sql, $params);
if ($count_stmt === false) {
    $err = sqlsrv_errors();
    SwimResponse::error('Database error (count): ' . ($err[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}
$total = (int)sqlsrv_fetch_array($count_stmt, SQLSRV_FETCH_ASSOC)['total'];
sqlsrv_free_stmt($count_stmt);

// Fetch paginated data
$data_sql = "
    SELECT cdr_id, cdr_code, full_route, origin_icao, dest_icao,
           dep_artcc, arr_artcc, direction,
           altitude_min_ft, altitude_max_ft, is_active, source
    FROM dbo.coded_departure_routes
    $where_sql
    ORDER BY cdr_code ASC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";

$data_params = array_merge($params, [$offset, $per_page]);
$stmt = sqlsrv_query($conn_ref, $data_sql, $data_params);
if ($stmt === false) {
    $err = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($err[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}

$cdrs = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $cdrs[] = formatCdrRow($row);
}
sqlsrv_free_stmt($stmt);

// Build response with pagination and metadata
$total_pages = ($per_page > 0) ? (int)ceil($total / $per_page) : 0;

SwimResponse::json([
    'success'    => true,
    'data'       => $cdrs,
    'pagination' => [
        'page'        => $page,
        'per_page'    => $per_page,
        'total'       => $total,
        'total_pages' => $total_pages,
        'has_more'    => $page < $total_pages
    ],
    'metadata'   => [
        'generated' => gmdate('c'),
        'source'    => 'vatsim_ref.coded_departure_routes'
    ]
], 200);

// ============================================================================
// Formatting
// ============================================================================

/**
 * Format a coded_departure_routes row for the API response.
 */
function formatCdrRow(array $row): array {
    return [
        'cdr_id'          => (int)$row['cdr_id'],
        'cdr_code'        => $row['cdr_code'],
        'full_route'      => $row['full_route'],
        'origin_icao'     => $row['origin_icao'] ?: null,
        'dest_icao'       => $row['dest_icao'] ?: null,
        'dep_artcc'       => $row['dep_artcc'] ?: null,
        'arr_artcc'       => $row['arr_artcc'] ?: null,
        'direction'       => $row['direction'] ?: null,
        'altitude_min_ft' => $row['altitude_min_ft'] !== null ? (int)$row['altitude_min_ft'] : null,
        'altitude_max_ft' => $row['altitude_max_ft'] !== null ? (int)$row['altitude_max_ft'] : null,
        'is_active'       => (bool)$row['is_active'],
        'source'          => $row['source'] ?: null
    ];
}
