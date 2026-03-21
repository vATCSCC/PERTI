<?php
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}
define('CTP_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');

$method = $_SERVER['REQUEST_METHOD'];
$conn = ctp_get_conn_tmi();

if ($method === 'GET') {
    // List scenarios with optional filters
    $session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : null;
    $status = isset($_GET['status']) ? strtoupper(trim($_GET['status'])) : null;

    $sql = "SELECT scenario_id, session_id, scenario_name,
                   departure_window_start, departure_window_end,
                   status, notes, created_by, created_at, updated_at
            FROM dbo.ctp_planning_scenarios
            WHERE (session_id = ? OR ? IS NULL)
              AND (status = ? OR ? IS NULL)
            ORDER BY created_at DESC";

    $result = ctp_fetch_all($conn, $sql, [$session_id, $session_id, $status, $status]);
    if (!$result['success']) {
        respond_json(500, [
            'status' => 'error',
            'message' => 'Failed to fetch scenarios',
            'error' => $result['error']
        ]);
    }

    respond_json(200, [
        'status' => 'success',
        'data' => $result['data']
    ]);

} elseif ($method === 'POST') {
    // Create scenario
    $cid = ctp_require_auth();
    $payload = read_request_payload();

    $scenario_name = isset($payload['scenario_name']) ? trim($payload['scenario_name']) : null;
    $departure_window_start = isset($payload['departure_window_start'])
        ? parse_utc_datetime($payload['departure_window_start']) : null;
    $departure_window_end = isset($payload['departure_window_end'])
        ? parse_utc_datetime($payload['departure_window_end']) : null;
    $session_id = isset($payload['session_id']) ? (int)$payload['session_id'] : null;
    $notes = isset($payload['notes']) ? trim($payload['notes']) : null;

    if (!$scenario_name) {
        respond_json(400, [
            'status' => 'error',
            'message' => 'scenario_name is required'
        ]);
    }

    if (!$departure_window_start || !$departure_window_end) {
        respond_json(400, [
            'status' => 'error',
            'message' => 'departure_window_start and departure_window_end are required'
        ]);
    }

    $sql = "INSERT INTO dbo.ctp_planning_scenarios
            (session_id, scenario_name, departure_window_start, departure_window_end, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?);
            SELECT SCOPE_IDENTITY() AS scenario_id";

    $stmt = sqlsrv_query($conn, $sql, [
        $session_id,
        $scenario_name,
        $departure_window_start,
        $departure_window_end,
        $notes,
        $cid
    ]);

    if ($stmt === false) {
        respond_json(500, [
            'status' => 'error',
            'message' => 'Failed to create scenario',
            'error' => sqlsrv_errors()
        ]);
    }

    sqlsrv_next_result($stmt);
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $scenario_id = (int)$row['scenario_id'];
    sqlsrv_free_stmt($stmt);

    ctp_audit_log($conn, $session_id, null, 'SCENARIO_CREATE', [
        'scenario_id' => $scenario_id,
        'scenario_name' => $scenario_name
    ], $cid);

    respond_json(201, [
        'status' => 'success',
        'message' => 'Scenario created',
        'scenario_id' => $scenario_id
    ]);

} else {
    respond_json(405, ['status' => 'error', 'message' => 'Method not allowed']);
}
