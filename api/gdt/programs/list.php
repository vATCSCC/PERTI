<?php
/**
 * GDT Programs - List API
 * 
 * GET /api/gdt/programs/list.php
 * 
 * Lists TMI programs with optional filtering.
 * 
 * Query parameters:
 *   status      - Filter by status (PROPOSED, MODELING, ACTIVE, COMPLETED, etc.)
 *   ctl_element - Filter by destination airport
 *   program_type - Filter by type (GS, GDP-DAS, GDP-GAAP, GDP-UDP, AFP)
 *   active_only - If "1", show only active programs (is_active=1)
 *   include_completed - If "1", include completed programs (default: exclude)
 *   limit       - Max records to return (default: 50)
 *   offset      - Pagination offset (default: 0)
 * 
 * Response:
 * {
 *   "status": "ok",
 *   "data": {
 *     "programs": [ ... ],
 *     "total": 25,
 *     "limit": 50,
 *     "offset": 0
 *   }
 * }
 * 
 * @version 1.0.0
 * @date 2026-01-21
 */

header('Content-Type: application/json; charset=utf-8');

// Handle CORS preflight
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('GDT_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');

// Only allow GET
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond_json(405, [
        'status' => 'error',
        'message' => 'Method not allowed. Use GET.'
    ]);
}

// Get TMI connection
$conn_tmi = gdt_get_conn_tmi();

// ============================================================================
// Parse Query Parameters
// ============================================================================

$status = isset($_GET['status']) ? strtoupper(trim($_GET['status'])) : null;
$ctl_element = isset($_GET['ctl_element']) ? strtoupper(trim($_GET['ctl_element'])) : null;
$program_type = isset($_GET['program_type']) ? strtoupper(trim($_GET['program_type'])) : null;
$active_only = isset($_GET['active_only']) && $_GET['active_only'] === '1';
$include_completed = isset($_GET['include_completed']) && $_GET['include_completed'] === '1';
$limit = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 50;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

// ============================================================================
// Auto-complete expired programs (ACTIVE with end_utc in the past)
// ============================================================================
$ac_stmt = sqlsrv_query($conn_tmi,
    "UPDATE dbo.tmi_programs SET status = 'COMPLETED', is_active = 0, completed_at = SYSUTCDATETIME(), updated_at = SYSUTCDATETIME() WHERE status = 'ACTIVE' AND end_utc IS NOT NULL AND end_utc < SYSUTCDATETIME()"
);
if ($ac_stmt !== false) sqlsrv_free_stmt($ac_stmt);

// ============================================================================
// Build Query
// ============================================================================

$where = [];
$params = [];

if ($status !== null && $status !== '') {
    $where[] = "status = ?";
    $params[] = $status;
}

if ($ctl_element !== null && $ctl_element !== '') {
    $where[] = "ctl_element = ?";
    $params[] = $ctl_element;
}

if ($program_type !== null && $program_type !== '') {
    $where[] = "program_type = ?";
    $params[] = $program_type;
}

if ($active_only) {
    $where[] = "is_active = 1";
}

if (!$include_completed) {
    $where[] = "status NOT IN ('COMPLETED', 'CANCELLED', 'PURGED')";
}

$where_sql = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";

// ============================================================================
// Get Total Count
// ============================================================================

$count_sql = "SELECT COUNT(*) FROM dbo.tmi_programs {$where_sql}";
list($total, $count_err) = fetch_value($conn_tmi, $count_sql, $params);

if ($count_err) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to count programs',
        'errors' => $count_err
    ]);
}

$total = (int)($total ?? 0);

// ============================================================================
// Fetch Programs
// ============================================================================

$sql = "
    SELECT 
        program_id,
        program_guid,
        ctl_element,
        element_type,
        program_type,
        program_name,
        adv_number,
        status,
        is_proposed,
        is_active,
        start_utc,
        end_utc,
        cumulative_start,
        cumulative_end,
        program_rate,
        reserve_rate,
        delay_limit_min,
        impacting_condition,
        cause_text,
        gs_probability,
        compression_enabled,
        popup_flights,
        parent_program_id,
        superseded_by_id,
        transition_type,
        created_by,
        created_at,
        activated_by,
        activated_at,
        completed_at,
        updated_at
    FROM dbo.tmi_programs
    {$where_sql}
    ORDER BY created_at DESC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";

$params[] = $offset;
$params[] = $limit;

$result = fetch_all($conn_tmi, $sql, $params);

if (!$result['success']) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to fetch programs',
        'errors' => $result['error']
    ]);
}

// ============================================================================
// Response
// ============================================================================

respond_json(200, [
    'status' => 'ok',
    'data' => [
        'programs' => $result['data'],
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset
    ]
]);
