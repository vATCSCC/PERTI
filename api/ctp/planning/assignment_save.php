<?php
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}
define('CTP_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(405, ['status' => 'error', 'message' => 'Method not allowed']);
}

$cid = ctp_require_auth();
$payload = read_request_payload();
$conn = ctp_get_conn_tmi();

$block_id = isset($payload['block_id']) ? (int)$payload['block_id'] : null;
$assignment_id = isset($payload['assignment_id']) ? (int)$payload['assignment_id'] : null;

if (!$block_id && !$assignment_id) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'Either block_id (for create) or assignment_id (for update) is required'
    ]);
}

// If assignment_id provided, verify ownership via block → scenario
if ($assignment_id) {
    $check_sql = "SELECT a.assignment_id, a.block_id, s.created_by, s.session_id
                  FROM dbo.ctp_planning_track_assignments a
                  JOIN dbo.ctp_planning_traffic_blocks b ON a.block_id = b.block_id
                  JOIN dbo.ctp_planning_scenarios s ON b.scenario_id = s.scenario_id
                  WHERE a.assignment_id = ?";
    $check_result = ctp_fetch_one($conn, $check_sql, [$assignment_id]);
    if (!$check_result['success'] || !$check_result['data']) {
        respond_json(404, [
            'status' => 'error',
            'message' => 'Assignment not found'
        ]);
    }

    if ($check_result['data']['created_by'] !== $cid) {
        respond_json(403, [
            'status' => 'error',
            'message' => 'You do not have permission to update this assignment'
        ]);
    }

    $block_id = $check_result['data']['block_id'];
    $session_id = $check_result['data']['session_id'];
} else {
    // Verify block ownership for create
    $check_sql = "SELECT b.block_id, b.scenario_id, s.created_by, s.session_id
                  FROM dbo.ctp_planning_traffic_blocks b
                  JOIN dbo.ctp_planning_scenarios s ON b.scenario_id = s.scenario_id
                  WHERE b.block_id = ?";
    $check_result = ctp_fetch_one($conn, $check_sql, [$block_id]);
    if (!$check_result['success'] || !$check_result['data']) {
        respond_json(404, [
            'status' => 'error',
            'message' => 'Block not found'
        ]);
    }

    if ($check_result['data']['created_by'] !== $cid) {
        respond_json(403, [
            'status' => 'error',
            'message' => 'You do not have permission to modify this block'
        ]);
    }

    $session_id = $check_result['data']['session_id'];
}

if ($assignment_id) {
    // Update
    $updates = [];
    $params = [];

    if (isset($payload['track_name'])) {
        $updates[] = "track_name = ?";
        $params[] = trim($payload['track_name']);
    }
    if (isset($payload['route_string'])) {
        $updates[] = "route_string = ?";
        $params[] = trim($payload['route_string']);
    }
    if (isset($payload['flight_count'])) {
        $flight_count = (int)$payload['flight_count'];
        if ($flight_count <= 0) {
            respond_json(400, [
                'status' => 'error',
                'message' => 'flight_count must be greater than 0'
            ]);
        }
        $updates[] = "flight_count = ?";
        $params[] = $flight_count;
    }
    if (isset($payload['altitude_range'])) {
        $updates[] = "altitude_range = ?";
        $params[] = trim($payload['altitude_range']);
    }

    if (empty($updates)) {
        respond_json(400, [
            'status' => 'error',
            'message' => 'No fields to update'
        ]);
    }

    $updates[] = "updated_at = SYSUTCDATETIME()";
    $params[] = $assignment_id;

    $sql = "UPDATE dbo.ctp_planning_track_assignments SET " . implode(', ', $updates) . " WHERE assignment_id = ?";
    $result = ctp_execute($conn, $sql, $params);

    if (!$result['success']) {
        respond_json(500, [
            'status' => 'error',
            'message' => 'Failed to update assignment',
            'error' => $result['error']
        ]);
    }

    respond_json(200, [
        'status' => 'success',
        'message' => 'Assignment updated',
        'assignment_id' => $assignment_id
    ]);

} else {
    // Insert
    $track_name = isset($payload['track_name']) ? trim($payload['track_name']) : null;
    $route_string = isset($payload['route_string']) ? trim($payload['route_string']) : null;
    $flight_count = isset($payload['flight_count']) ? (int)$payload['flight_count'] : 0;
    $altitude_range = isset($payload['altitude_range']) ? trim($payload['altitude_range']) : null;

    if ($flight_count <= 0) {
        respond_json(400, [
            'status' => 'error',
            'message' => 'flight_count must be greater than 0'
        ]);
    }

    $sql = "INSERT INTO dbo.ctp_planning_track_assignments
            (block_id, track_name, route_string, flight_count, altitude_range)
            VALUES (?, ?, ?, ?, ?);
            SELECT SCOPE_IDENTITY() AS assignment_id";

    $stmt = sqlsrv_query($conn, $sql, [
        $block_id,
        $track_name,
        $route_string,
        $flight_count,
        $altitude_range
    ]);

    if ($stmt === false) {
        respond_json(500, [
            'status' => 'error',
            'message' => 'Failed to create assignment',
            'error' => sqlsrv_errors()
        ]);
    }

    sqlsrv_next_result($stmt);
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $new_assignment_id = (int)$row['assignment_id'];
    sqlsrv_free_stmt($stmt);

    respond_json(201, [
        'status' => 'success',
        'message' => 'Assignment created',
        'assignment_id' => $new_assignment_id
    ]);
}
