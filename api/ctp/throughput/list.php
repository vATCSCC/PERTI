<?php
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}
define('CTP_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');

// Method check
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_json(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

$cid = ctp_require_auth();
$conn_tmi = ctp_get_conn_tmi();

// Validate session_id parameter
$session_id = (int)($_GET['session_id'] ?? 0);
if ($session_id <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'session_id required.']);
}

// Verify session exists
$session = ctp_get_session($conn_tmi, $session_id);
if (!$session) {
    respond_json(404, ['status' => 'error', 'message' => 'Session not found.']);
}

// Fetch throughput configs
$result = ctp_fetch_all($conn_tmi,
    "SELECT config_id, session_id, config_label, tracks_json, origins_json, destinations_json,
            max_acph, priority, is_active, notes, created_by, created_at, updated_at
     FROM dbo.ctp_track_throughput_config
     WHERE session_id = ? AND is_active = 1
     ORDER BY priority ASC, config_label ASC",
    [$session_id]);

if (!$result['success']) {
    respond_json(500, ['status' => 'error', 'message' => 'Query failed.', 'error' => $result['error']]);
}

// Parse JSON fields and format datetime
$configs = array_map(function($row) {
    $row['tracks_json'] = $row['tracks_json'] ? json_decode($row['tracks_json'], true) : null;
    $row['origins_json'] = $row['origins_json'] ? json_decode($row['origins_json'], true) : null;
    $row['destinations_json'] = $row['destinations_json'] ? json_decode($row['destinations_json'], true) : null;

    // Convert any DateTime objects to ISO format
    foreach (['created_at', 'updated_at'] as $col) {
        if (isset($row[$col]) && $row[$col] instanceof \DateTimeInterface) {
            $row[$col] = datetime_to_iso($row[$col]);
        }
    }

    return $row;
}, $result['data']);

respond_json(200, ['status' => 'ok', 'data' => $configs]);
