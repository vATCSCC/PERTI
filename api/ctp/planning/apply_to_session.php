<?php
/**
 * CTP Planning Simulator — Apply Scenario to Session
 *
 * POST /api/ctp/planning/apply_to_session.php
 * Body: { scenario_id }
 *
 * Promotes a planning scenario's track assignments into live throughput
 * configs on the linked session. Creates ctp_track_throughput_config rows
 * for each block/track combination, updates the scenario status to ACTIVE,
 * and writes an audit log entry.
 *
 * Prerequisites:
 *   - Scenario must have session_id set (cannot apply standalone scenarios)
 *   - Caller must own the scenario (created_by = authenticated CID)
 *
 * @version 1.0.0
 */
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

// ============================================================================
// Validate input
// ============================================================================

$scenario_id = isset($payload['scenario_id']) ? (int)$payload['scenario_id'] : 0;
if ($scenario_id <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'scenario_id is required']);
}

// ============================================================================
// Load and validate scenario
// ============================================================================

$scenario_result = ctp_fetch_one($conn,
    "SELECT scenario_id, session_id, scenario_name,
            departure_window_start, departure_window_end,
            status, created_by
     FROM dbo.ctp_planning_scenarios
     WHERE scenario_id = ?",
    [$scenario_id]);

if (!$scenario_result['success'] || !$scenario_result['data']) {
    respond_json(404, ['status' => 'error', 'message' => 'Scenario not found']);
}

$scenario = $scenario_result['data'];

// Must have session_id
if (empty($scenario['session_id'])) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'Scenario is not linked to a session. Set session_id before applying.'
    ]);
}

$session_id = (int)$scenario['session_id'];

// Verify ownership
if ($scenario['created_by'] !== $cid) {
    respond_json(403, [
        'status' => 'error',
        'message' => 'You do not have permission to apply this scenario'
    ]);
}

// Verify session exists
$session = ctp_get_session($conn, $session_id);
if (!$session) {
    respond_json(404, [
        'status' => 'error',
        'message' => 'Linked session not found'
    ]);
}

// ============================================================================
// Load traffic blocks + track assignments for this scenario
// ============================================================================

$blocks_result = ctp_fetch_all($conn,
    "SELECT block_id, block_label, origins_json, destinations_json,
            flight_count, dep_distribution
     FROM dbo.ctp_planning_traffic_blocks
     WHERE scenario_id = ?
     ORDER BY block_id",
    [$scenario_id]);

if (!$blocks_result['success']) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to load traffic blocks',
        'error' => $blocks_result['error']
    ]);
}

$blocks = $blocks_result['data'];
if (empty($blocks)) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'Scenario has no traffic blocks. Add blocks and assignments before applying.'
    ]);
}

$block_ids = array_map(function($b) { return (int)$b['block_id']; }, $blocks);
$placeholders = implode(',', array_fill(0, count($block_ids), '?'));
$assignments_result = ctp_fetch_all($conn,
    "SELECT assignment_id, block_id, track_name, route_string, flight_count, altitude_range
     FROM dbo.ctp_planning_track_assignments
     WHERE block_id IN ($placeholders)
     ORDER BY block_id, assignment_id",
    $block_ids);

if (!$assignments_result['success']) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to load track assignments',
        'error' => $assignments_result['error']
    ]);
}

// Group assignments by block_id
$assignments_by_block = [];
foreach ($assignments_result['data'] as $a) {
    $bid = (int)$a['block_id'];
    if (!isset($assignments_by_block[$bid])) {
        $assignments_by_block[$bid] = [];
    }
    $assignments_by_block[$bid][] = $a;
}

// ============================================================================
// Calculate departure window duration for rate computation
// ============================================================================

$dep_start = new DateTime($scenario['departure_window_start']);
$dep_end = new DateTime($scenario['departure_window_end']);
$dep_start->setTimezone(new DateTimeZone('UTC'));
$dep_end->setTimezone(new DateTimeZone('UTC'));
$window_hours = ($dep_end->getTimestamp() - $dep_start->getTimestamp()) / 3600.0;
if ($window_hours <= 0) $window_hours = 1; // Safety floor

// ============================================================================
// Create throughput configs for each block/track combination
// ============================================================================

$configs_created = [];
$config_mapping = [];

foreach ($blocks as $block) {
    $bid = (int)$block['block_id'];
    $block_label = isset($block['block_label']) ? trim($block['block_label']) : "Block $bid";
    $origins_json = $block['origins_json']; // Already JSON string from DB
    $destinations_json = $block['destinations_json'];
    $block_assignments = isset($assignments_by_block[$bid]) ? $assignments_by_block[$bid] : [];

    foreach ($block_assignments as $asgn) {
        $track_name = isset($asgn['track_name']) ? trim($asgn['track_name']) : null;
        $asgn_flight_count = isset($asgn['flight_count']) ? (int)$asgn['flight_count'] : 0;

        if ($asgn_flight_count <= 0) continue;

        // config_label = block_label + " - " + track_name
        $config_label = $block_label;
        if ($track_name) {
            $config_label .= ' - ' . $track_name;
        }

        // max_acph = flight_count / window_hours (rounded up)
        $max_acph = (int)ceil($asgn_flight_count / $window_hours);
        if ($max_acph <= 0) $max_acph = 1;

        // tracks_json = JSON array with the track name
        $tracks_json = $track_name ? json_encode([$track_name]) : null;

        // Insert throughput config
        $insert_sql = "INSERT INTO dbo.ctp_track_throughput_config
            (session_id, config_label, tracks_json, origins_json, destinations_json,
             max_acph, priority, is_active, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?);
            SELECT SCOPE_IDENTITY() AS config_id";

        $notes = "Auto-generated from scenario \"" . $scenario['scenario_name'] . "\" (ID: $scenario_id)";

        $stmt = sqlsrv_query($conn, $insert_sql, [
            $session_id,
            $config_label,
            $tracks_json,
            $origins_json,
            $destinations_json,
            $max_acph,
            50, // priority = 50 for planning-generated configs
            $notes,
            $cid
        ]);

        if ($stmt === false) {
            respond_json(500, [
                'status' => 'error',
                'message' => "Failed to create throughput config for \"$config_label\"",
                'error' => sqlsrv_errors()
            ]);
        }

        sqlsrv_next_result($stmt);
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $config_id = $row ? (int)$row['config_id'] : null;
        sqlsrv_free_stmt($stmt);

        $configs_created[] = [
            'config_id' => $config_id,
            'config_label' => $config_label,
            'track_name' => $track_name,
            'max_acph' => $max_acph,
            'flight_count' => $asgn_flight_count,
        ];

        $config_mapping[] = [
            'block_id' => $bid,
            'block_label' => $block_label,
            'assignment_id' => (int)$asgn['assignment_id'],
            'track_name' => $track_name,
            'config_id' => $config_id,
            'max_acph' => $max_acph,
        ];
    }
}

if (empty($configs_created)) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'No track assignments with flight counts found. Nothing to apply.'
    ]);
}

// ============================================================================
// Update scenario status to ACTIVE
// ============================================================================

$update_result = ctp_execute($conn,
    "UPDATE dbo.ctp_planning_scenarios
     SET status = 'ACTIVE', updated_at = SYSUTCDATETIME()
     WHERE scenario_id = ?",
    [$scenario_id]);

if (!$update_result['success']) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Configs created but failed to update scenario status',
        'error' => $update_result['error']
    ]);
}

// ============================================================================
// Audit log
// ============================================================================

ctp_audit_log($conn, $session_id, null, 'SCENARIO_APPLY', [
    'scenario_id' => $scenario_id,
    'scenario_name' => $scenario['scenario_name'],
    'configs_created' => count($configs_created),
    'mapping' => $config_mapping,
], $cid);

// ============================================================================
// Respond
// ============================================================================

respond_json(200, [
    'status' => 'ok',
    'message' => 'Scenario applied to session. ' . count($configs_created) . ' throughput config(s) created.',
    'data' => [
        'scenario_id' => $scenario_id,
        'session_id' => $session_id,
        'status' => 'ACTIVE',
        'configs_created' => $configs_created,
    ],
]);
