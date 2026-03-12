<?php
/**
 * CTP Flights - Exclude/Include API
 *
 * POST /api/ctp/flights/exclude.php
 *
 * Toggle exclusion status for a flight or batch of flights.
 *
 * Request body:
 * {
 *   "ctp_control_ids": [123, 124, 125],
 *   "exclude": true               (true to exclude, false to include)
 * }
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('CTP_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond_json(405, ['status' => 'error', 'message' => 'Method not allowed. Use POST.']);
}

$cid = ctp_require_auth();
$conn_tmi = ctp_get_conn_tmi();
$payload = read_request_payload();

$ids = isset($payload['ctp_control_ids']) && is_array($payload['ctp_control_ids'])
    ? array_map('intval', $payload['ctp_control_ids'])
    : [];

// Support single ID too
if (empty($ids) && isset($payload['ctp_control_id'])) {
    $ids = [(int)$payload['ctp_control_id']];
}

if (empty($ids)) {
    respond_json(400, ['status' => 'error', 'message' => 'ctp_control_ids required.']);
}

$exclude = isset($payload['exclude']) ? (bool)$payload['exclude'] : true;
$exclude_val = $exclude ? 1 : 0;

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$params = array_merge([$exclude_val], $ids);

$sql = "UPDATE dbo.ctp_flight_control SET is_excluded = ? WHERE ctp_control_id IN ($placeholders)";
$result = ctp_execute($conn_tmi, $sql, $params);

if (!$result['success']) {
    respond_json(500, ['status' => 'error', 'message' => 'Failed to update exclusion status.']);
}

// Get session_id for audit
$session_result = ctp_fetch_one($conn_tmi,
    "SELECT session_id FROM dbo.ctp_flight_control WHERE ctp_control_id = ?",
    [$ids[0]]
);
$session_id = $session_result['data'] ? (int)$session_result['data']['session_id'] : null;

// Audit
if ($session_id) {
    foreach ($ids as $id) {
        ctp_audit_log($conn_tmi, $session_id, $id,
            $exclude ? 'FLIGHT_EXCLUDE' : 'FLIGHT_INCLUDE',
            ['exclude' => $exclude],
            $cid
        );
    }
}

respond_json(200, [
    'status' => 'ok',
    'data' => [
        'updated' => $result['rows_affected'],
        'excluded' => $exclude
    ]
]);
