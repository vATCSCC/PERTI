<?php
/**
 * CTP Planning — Track Constraints CRUD
 *
 * GET    ?session_id=N           — list constraints for session
 * POST   { session_id, track_name, ... } — create/update (upsert)
 * DELETE { constraint_id }       — remove constraint
 *
 * @version 1.0.0
 */
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}
define('CTP_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');

$method = $_SERVER['REQUEST_METHOD'];
$conn = ctp_get_conn_tmi();

// ============================================================================
// GET — List constraints for a session
// ============================================================================
if ($method === 'GET') {
    $session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
    if ($session_id <= 0) {
        respond_json(400, ['status' => 'error', 'message' => 'session_id is required']);
    }

    $result = ctp_fetch_all($conn,
        "SELECT constraint_id, session_id, track_name, max_acph,
                ocean_entry_start, ocean_entry_end,
                fl_min, fl_max, priority, notes,
                created_at, updated_at
         FROM dbo.ctp_planning_track_constraints
         WHERE session_id = ?
         ORDER BY track_name",
        [$session_id]);

    if (!$result['success']) {
        respond_json(500, ['status' => 'error', 'message' => 'Failed to fetch constraints', 'error' => $result['error']]);
    }

    respond_json(200, ['status' => 'ok', 'data' => $result['data']]);
}

// ============================================================================
// POST — Create or update constraint (upsert by session_id + track_name)
// ============================================================================
if ($method === 'POST') {
    $cid = ctp_require_auth();
    $payload = read_request_payload();

    $session_id = isset($payload['session_id']) ? (int)$payload['session_id'] : 0;
    $track_name = isset($payload['track_name']) ? strtoupper(trim($payload['track_name'])) : '';

    if ($session_id <= 0 || $track_name === '') {
        respond_json(400, ['status' => 'error', 'message' => 'session_id and track_name are required']);
    }

    $max_acph = isset($payload['max_acph']) && $payload['max_acph'] !== '' ? (int)$payload['max_acph'] : null;
    $ocean_entry_start = isset($payload['ocean_entry_start']) ? parse_utc_datetime($payload['ocean_entry_start']) : null;
    $ocean_entry_end = isset($payload['ocean_entry_end']) ? parse_utc_datetime($payload['ocean_entry_end']) : null;
    $fl_min = isset($payload['fl_min']) && $payload['fl_min'] !== '' ? (int)$payload['fl_min'] : null;
    $fl_max = isset($payload['fl_max']) && $payload['fl_max'] !== '' ? (int)$payload['fl_max'] : null;
    $priority = isset($payload['priority']) ? (int)$payload['priority'] : 50;
    $notes = isset($payload['notes']) ? trim($payload['notes']) : null;

    // Check if constraint already exists for this session+track
    $existing = ctp_fetch_one($conn,
        "SELECT constraint_id FROM dbo.ctp_planning_track_constraints
         WHERE session_id = ? AND track_name = ?",
        [$session_id, $track_name]);

    if ($existing['success'] && $existing['data']) {
        // UPDATE
        $constraint_id = (int)$existing['data']['constraint_id'];
        $result = ctp_execute($conn,
            "UPDATE dbo.ctp_planning_track_constraints
             SET max_acph = ?, ocean_entry_start = ?, ocean_entry_end = ?,
                 fl_min = ?, fl_max = ?, priority = ?, notes = ?,
                 updated_at = SYSUTCDATETIME()
             WHERE constraint_id = ?",
            [$max_acph, $ocean_entry_start, $ocean_entry_end,
             $fl_min, $fl_max, $priority, $notes, $constraint_id]);

        if (!$result['success']) {
            respond_json(500, ['status' => 'error', 'message' => 'Failed to update constraint', 'error' => $result['error']]);
        }

        respond_json(200, ['status' => 'ok', 'message' => 'Constraint updated', 'data' => ['constraint_id' => $constraint_id]]);
    } else {
        // INSERT
        $sql = "INSERT INTO dbo.ctp_planning_track_constraints
                (session_id, track_name, max_acph, ocean_entry_start, ocean_entry_end,
                 fl_min, fl_max, priority, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?);
                SELECT SCOPE_IDENTITY() AS constraint_id";

        $stmt = sqlsrv_query($conn, $sql, [
            $session_id, $track_name, $max_acph, $ocean_entry_start, $ocean_entry_end,
            $fl_min, $fl_max, $priority, $notes
        ]);

        if ($stmt === false) {
            respond_json(500, ['status' => 'error', 'message' => 'Failed to create constraint', 'error' => sqlsrv_errors()]);
        }

        sqlsrv_next_result($stmt);
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $constraint_id = $row ? (int)$row['constraint_id'] : null;
        sqlsrv_free_stmt($stmt);

        respond_json(201, ['status' => 'ok', 'message' => 'Constraint created', 'data' => ['constraint_id' => $constraint_id]]);
    }
}

// ============================================================================
// DELETE — Remove a constraint
// ============================================================================
if ($method === 'DELETE') {
    $cid = ctp_require_auth();
    $payload = read_request_payload();

    $constraint_id = isset($payload['constraint_id']) ? (int)$payload['constraint_id'] : 0;
    if ($constraint_id <= 0) {
        respond_json(400, ['status' => 'error', 'message' => 'constraint_id is required']);
    }

    $result = ctp_execute($conn,
        "DELETE FROM dbo.ctp_planning_track_constraints WHERE constraint_id = ?",
        [$constraint_id]);

    if (!$result['success']) {
        respond_json(500, ['status' => 'error', 'message' => 'Failed to delete constraint', 'error' => $result['error']]);
    }

    respond_json(200, ['status' => 'ok', 'message' => 'Constraint deleted']);
}

respond_json(405, ['status' => 'error', 'message' => 'Method not allowed']);
