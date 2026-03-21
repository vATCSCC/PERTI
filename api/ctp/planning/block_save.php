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

$scenario_id = isset($payload['scenario_id']) ? (int)$payload['scenario_id'] : null;
$block_id = isset($payload['block_id']) ? (int)$payload['block_id'] : null;

if (!$scenario_id && !$block_id) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'Either scenario_id (for create) or block_id (for update) is required'
    ]);
}

// If block_id provided, verify ownership via scenario
if ($block_id) {
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
            'message' => 'You do not have permission to update this block'
        ]);
    }

    $scenario_id = $check_result['data']['scenario_id'];
    $session_id = $check_result['data']['session_id'];
} else {
    // Verify scenario ownership for create
    $check_sql = "SELECT created_by, session_id FROM dbo.ctp_planning_scenarios WHERE scenario_id = ?";
    $check_result = ctp_fetch_one($conn, $check_sql, [$scenario_id]);
    if (!$check_result['success'] || !$check_result['data']) {
        respond_json(404, [
            'status' => 'error',
            'message' => 'Scenario not found'
        ]);
    }

    if ($check_result['data']['created_by'] !== $cid) {
        respond_json(403, [
            'status' => 'error',
            'message' => 'You do not have permission to modify this scenario'
        ]);
    }

    $session_id = $check_result['data']['session_id'];
}

// Validate dep_distribution
$dep_distribution = isset($payload['dep_distribution']) ? strtoupper(trim($payload['dep_distribution'])) : 'UNIFORM';
if (!in_array($dep_distribution, ['UNIFORM', 'FRONT_LOADED', 'BACK_LOADED', 'CUSTOM'])) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'Invalid dep_distribution'
    ]);
}

// Prepare JSON fields
$origins_json = isset($payload['origins_json']) ? json_encode($payload['origins_json']) : null;
$destinations_json = isset($payload['destinations_json']) ? json_encode($payload['destinations_json']) : null;
$dep_distribution_json = isset($payload['dep_distribution_json']) ? json_encode($payload['dep_distribution_json']) : null;
$aircraft_mix_json = isset($payload['aircraft_mix_json']) ? json_encode($payload['aircraft_mix_json']) : null;

if ($block_id) {
    // Update
    $updates = [];
    $params = [];

    if (isset($payload['block_label'])) {
        $updates[] = "block_label = ?";
        $params[] = trim($payload['block_label']);
    }
    if (isset($payload['origins_json'])) {
        $updates[] = "origins_json = ?";
        $params[] = $origins_json;
    }
    if (isset($payload['destinations_json'])) {
        $updates[] = "destinations_json = ?";
        $params[] = $destinations_json;
    }
    if (isset($payload['flight_count'])) {
        $updates[] = "flight_count = ?";
        $params[] = (int)$payload['flight_count'];
    }
    if (isset($payload['dep_distribution'])) {
        $updates[] = "dep_distribution = ?";
        $params[] = $dep_distribution;
    }
    if (isset($payload['dep_distribution_json'])) {
        $updates[] = "dep_distribution_json = ?";
        $params[] = $dep_distribution_json;
    }
    if (isset($payload['aircraft_mix_json'])) {
        $updates[] = "aircraft_mix_json = ?";
        $params[] = $aircraft_mix_json;
    }

    if (empty($updates)) {
        respond_json(400, [
            'status' => 'error',
            'message' => 'No fields to update'
        ]);
    }

    $updates[] = "updated_at = SYSUTCDATETIME()";
    $params[] = $block_id;

    $sql = "UPDATE dbo.ctp_planning_traffic_blocks SET " . implode(', ', $updates) . " WHERE block_id = ?";
    $result = ctp_execute($conn, $sql, $params);

    if (!$result['success']) {
        respond_json(500, [
            'status' => 'error',
            'message' => 'Failed to update block',
            'error' => $result['error']
        ]);
    }

    respond_json(200, [
        'status' => 'success',
        'message' => 'Block updated',
        'block_id' => $block_id
    ]);

} else {
    // Insert
    $block_label = isset($payload['block_label']) ? trim($payload['block_label']) : null;
    $flight_count = isset($payload['flight_count']) ? (int)$payload['flight_count'] : 0;

    if (!$origins_json || !$destinations_json) {
        respond_json(400, [
            'status' => 'error',
            'message' => 'origins_json and destinations_json are required'
        ]);
    }

    $sql = "INSERT INTO dbo.ctp_planning_traffic_blocks
            (scenario_id, block_label, origins_json, destinations_json, flight_count,
             dep_distribution, dep_distribution_json, aircraft_mix_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?);
            SELECT SCOPE_IDENTITY() AS block_id";

    $stmt = sqlsrv_query($conn, $sql, [
        $scenario_id,
        $block_label,
        $origins_json,
        $destinations_json,
        $flight_count,
        $dep_distribution,
        $dep_distribution_json,
        $aircraft_mix_json
    ]);

    if ($stmt === false) {
        respond_json(500, [
            'status' => 'error',
            'message' => 'Failed to create block',
            'error' => sqlsrv_errors()
        ]);
    }

    sqlsrv_next_result($stmt);
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $new_block_id = (int)$row['block_id'];
    sqlsrv_free_stmt($stmt);

    respond_json(201, [
        'status' => 'success',
        'message' => 'Block created',
        'block_id' => $new_block_id
    ]);
}
