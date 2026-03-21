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
$new_name = isset($payload['new_name']) ? trim($payload['new_name']) : null;

if (!$scenario_id) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'scenario_id is required'
    ]);
}

if (!$new_name) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'new_name is required'
    ]);
}

// Fetch source scenario
$source_sql = "SELECT session_id, departure_window_start, departure_window_end, notes
               FROM dbo.ctp_planning_scenarios WHERE scenario_id = ?";
$source_result = ctp_fetch_one($conn, $source_sql, [$scenario_id]);
if (!$source_result['success'] || !$source_result['data']) {
    respond_json(404, [
        'status' => 'error',
        'message' => 'Source scenario not found'
    ]);
}

$source = $source_result['data'];

// Create new scenario
$insert_sql = "INSERT INTO dbo.ctp_planning_scenarios
               (session_id, scenario_name, departure_window_start, departure_window_end, notes, created_by)
               VALUES (?, ?, ?, ?, ?, ?);
               SELECT SCOPE_IDENTITY() AS new_scenario_id";

$stmt = sqlsrv_query($conn, $insert_sql, [
    $source['session_id'],
    $new_name,
    $source['departure_window_start'],
    $source['departure_window_end'],
    $source['notes'],
    $cid
]);

if ($stmt === false) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to clone scenario',
        'error' => sqlsrv_errors()
    ]);
}

sqlsrv_next_result($stmt);
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$new_scenario_id = (int)$row['new_scenario_id'];
sqlsrv_free_stmt($stmt);

// Copy blocks
$blocks_sql = "SELECT block_id, block_label, origins_json, destinations_json, flight_count,
                      dep_distribution, dep_distribution_json, aircraft_mix_json
               FROM dbo.ctp_planning_traffic_blocks WHERE scenario_id = ?";
$blocks_result = ctp_fetch_all($conn, $blocks_sql, [$scenario_id]);

if (!$blocks_result['success']) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to fetch source blocks',
        'error' => $blocks_result['error']
    ]);
}

// Map old block IDs to new block IDs
$block_id_map = [];

foreach ($blocks_result['data'] as $block) {
    $block_insert_sql = "INSERT INTO dbo.ctp_planning_traffic_blocks
                         (scenario_id, block_label, origins_json, destinations_json, flight_count,
                          dep_distribution, dep_distribution_json, aircraft_mix_json)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?);
                         SELECT SCOPE_IDENTITY() AS new_block_id";

    $stmt = sqlsrv_query($conn, $block_insert_sql, [
        $new_scenario_id,
        $block['block_label'],
        $block['origins_json'],
        $block['destinations_json'],
        $block['flight_count'],
        $block['dep_distribution'],
        $block['dep_distribution_json'],
        $block['aircraft_mix_json']
    ]);

    if ($stmt === false) {
        respond_json(500, [
            'status' => 'error',
            'message' => 'Failed to clone block',
            'error' => sqlsrv_errors()
        ]);
    }

    sqlsrv_next_result($stmt);
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $new_block_id = (int)$row['new_block_id'];
    sqlsrv_free_stmt($stmt);

    $block_id_map[$block['block_id']] = $new_block_id;
}

// Copy assignments
foreach ($block_id_map as $old_block_id => $new_block_id) {
    $assignments_sql = "SELECT track_name, route_string, flight_count, altitude_range
                        FROM dbo.ctp_planning_track_assignments WHERE block_id = ?";
    $assignments_result = ctp_fetch_all($conn, $assignments_sql, [$old_block_id]);

    if (!$assignments_result['success']) {
        continue;
    }

    foreach ($assignments_result['data'] as $assignment) {
        $assign_insert_sql = "INSERT INTO dbo.ctp_planning_track_assignments
                              (block_id, track_name, route_string, flight_count, altitude_range)
                              VALUES (?, ?, ?, ?, ?)";

        ctp_execute($conn, $assign_insert_sql, [
            $new_block_id,
            $assignment['track_name'],
            $assignment['route_string'],
            $assignment['flight_count'],
            $assignment['altitude_range']
        ]);
    }
}

ctp_audit_log($conn, $source['session_id'], null, 'SCENARIO_CLONE', [
    'source_scenario_id' => $scenario_id,
    'new_scenario_id' => $new_scenario_id,
    'new_name' => $new_name
], $cid);

respond_json(201, [
    'status' => 'success',
    'message' => 'Scenario cloned',
    'new_scenario_id' => $new_scenario_id
]);
