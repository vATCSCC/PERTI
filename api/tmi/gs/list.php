<?php
/**
 * GS List API
 * 
 * GET /api/tmi/gs/list.php
 * 
 * Lists Ground Stop programs with optional filters.
 * 
 * Query parameters:
 * - ctl_element: Optional - filter by airport (e.g., KJFK)
 * - status: Optional - filter by status (PROPOSED, ACTIVE, COMPLETED, PURGED)
 * - active_only: Optional - only show currently active programs (default: 0)
 * - today_only: Optional - only show programs from today (default: 0)
 * - limit: Optional - max number of results (default: 50)
 * 
 * Response:
 * {
 *   "status": "ok",
 *   "message": "Programs retrieved",
 *   "data": {
 *     "programs": [ ... ],
 *     "total": 5
 *   }
 * }
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('GS_API_INCLUDED', true);
require_once(__DIR__ . '/common.php');

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'])) {
    respond_json(405, [
        'status' => 'error',
        'message' => 'Method not allowed. Use GET or POST.'
    ]);
}

$payload = read_request_payload();
$conn = get_adl_conn();

// Get parameters
$ctl_element = isset($payload['ctl_element']) ? strtoupper(trim($payload['ctl_element'])) : null;
$status = isset($payload['status']) ? strtoupper(trim($payload['status'])) : null;
$active_only = isset($payload['active_only']) ? (bool)$payload['active_only'] : false;
$today_only = isset($payload['today_only']) ? (bool)$payload['today_only'] : false;
$limit = isset($payload['limit']) ? min((int)$payload['limit'], 200) : 50;

// Build query
$where = ["program_type = 'GS'"];
$params = [];

if ($ctl_element) {
    $where[] = "ctl_element = ?";
    $params[] = $ctl_element;
}

if ($status) {
    $where[] = "status = ?";
    $params[] = $status;
}

if ($active_only) {
    $where[] = "is_active = 1";
    $where[] = "end_utc > SYSUTCDATETIME()";
}

if ($today_only) {
    $where[] = "(CAST(created_utc AS DATE) = CAST(SYSUTCDATETIME() AS DATE) OR (is_active = 1 AND end_utc > SYSUTCDATETIME()))";
}

$sql = "SELECT TOP {$limit} * FROM dbo.ntml";
if (count($where) > 0) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY created_utc DESC";

$result = fetch_all($conn, $sql, $params);

if (!$result['success']) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to retrieve programs',
        'errors' => $result['error']
    ]);
}

respond_json(200, [
    'status' => 'ok',
    'message' => 'Programs retrieved',
    'data' => [
        'programs' => $result['data'],
        'total' => count($result['data']),
        'filters' => [
            'ctl_element' => $ctl_element,
            'status' => $status,
            'active_only' => $active_only,
            'today_only' => $today_only
        ],
        'server_utc' => get_server_utc($conn)
    ]
]);
