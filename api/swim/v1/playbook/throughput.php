<?php
/**
 * VATSWIM API v1 - Playbook Route Throughput Ingest
 *
 * CTP pushes per-route throughput data (planned counts, slot counts, peak rates)
 * to PERTI for display on Playbook and Route pages.
 *
 * POST /api/swim/v1/playbook/throughput
 *   Body: { "route_id": 123, "play_id": 456, "throughput": { "planned_count": 45, ... } }
 *
 * GET /api/swim/v1/playbook/throughput?play_id=456
 *   Returns throughput data for a play's routes.
 *
 * @version 1.0.0
 */

require_once __DIR__ . '/../auth.php';

$auth = swim_init_auth(true);
$key_info = $auth->getKeyInfo();
SwimResponse::setTier($key_info['tier'] ?? 'public');

global $conn_sqli;
if (!$conn_sqli) {
    SwimResponse::error('MySQL database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    handleGetThroughput();
} elseif ($method === 'POST') {
    handlePostThroughput();
} elseif ($method === 'OPTIONS') {
    SwimResponse::handlePreflight();
} else {
    SwimResponse::error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
}

function handleGetThroughput(): void {
    global $conn_sqli;

    $play_id  = swim_get_int_param('play_id', 0, 0, 999999);
    $route_id = swim_get_int_param('route_id', 0, 0, 999999);

    if ($play_id <= 0 && $route_id <= 0) {
        SwimResponse::error('play_id or route_id is required', 400, 'MISSING_PARAM');
    }

    $sql = "SELECT t.throughput_id, t.route_id, t.play_id, t.source,
                   t.planned_count, t.slot_count, t.peak_rate_hr, t.avg_rate_hr,
                   t.period_start, t.period_end, t.metadata_json,
                   t.updated_by, t.updated_at, t.created_at,
                   r.route_string, r.origin, r.dest
            FROM playbook_route_throughput t
            JOIN playbook_routes r ON t.route_id = r.route_id";

    $where = [];
    $params = [];
    $types = '';

    if ($play_id > 0) {
        $where[] = "t.play_id = ?";
        $params[] = $play_id;
        $types .= 'i';
    }

    if ($route_id > 0) {
        $where[] = "t.route_id = ?";
        $params[] = $route_id;
        $types .= 'i';
    }

    $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY r.sort_order ASC';

    $stmt = $conn_sqli->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        if ($row['metadata_json']) {
            $row['metadata'] = json_decode($row['metadata_json'], true);
        } else {
            $row['metadata'] = null;
        }
        unset($row['metadata_json']);
        $rows[] = $row;
    }
    $stmt->close();

    SwimResponse::success([
        'count'      => count($rows),
        'throughput' => $rows,
    ]);
}

function handlePostThroughput(): void {
    global $conn_sqli;

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!$body || !is_array($body)) {
        SwimResponse::error('Invalid JSON body', 400, 'INVALID_JSON');
    }

    $route_id = (int)($body['route_id'] ?? 0);
    $play_id  = (int)($body['play_id'] ?? 0);

    if ($route_id <= 0 || $play_id <= 0) {
        SwimResponse::error('route_id and play_id are required', 400, 'MISSING_PARAM');
    }

    // Verify route exists and belongs to play
    $check = $conn_sqli->prepare("SELECT route_id FROM playbook_routes WHERE route_id = ? AND play_id = ?");
    $check->bind_param('ii', $route_id, $play_id);
    $check->execute();
    if ($check->get_result()->num_rows === 0) {
        $check->close();
        SwimResponse::error('Route not found or does not belong to specified play', 404, 'NOT_FOUND');
    }
    $check->close();

    $tp = $body['throughput'] ?? $body;
    $source        = trim($body['source'] ?? 'CTP');
    $planned_count = isset($tp['planned_count']) ? (int)$tp['planned_count'] : null;
    $slot_count    = isset($tp['slot_count']) ? (int)$tp['slot_count'] : null;
    $peak_rate_hr  = isset($tp['peak_rate_hr']) ? (int)$tp['peak_rate_hr'] : null;
    $avg_rate_hr   = isset($tp['avg_rate_hr']) ? (float)$tp['avg_rate_hr'] : null;
    $period_start  = isset($tp['period_start']) ? $tp['period_start'] : null;
    $period_end    = isset($tp['period_end']) ? $tp['period_end'] : null;
    $metadata      = isset($tp['metadata']) ? json_encode($tp['metadata'], JSON_UNESCAPED_UNICODE) : null;
    $updated_by    = $body['updated_by'] ?? 'swim_api';

    // Upsert: INSERT ON DUPLICATE KEY UPDATE
    $sql = "INSERT INTO playbook_route_throughput
                (route_id, play_id, source, planned_count, slot_count, peak_rate_hr, avg_rate_hr,
                 period_start, period_end, metadata_json, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                planned_count = VALUES(planned_count),
                slot_count    = VALUES(slot_count),
                peak_rate_hr  = VALUES(peak_rate_hr),
                avg_rate_hr   = VALUES(avg_rate_hr),
                period_start  = VALUES(period_start),
                period_end    = VALUES(period_end),
                metadata_json = VALUES(metadata_json),
                updated_by    = VALUES(updated_by)";

    $stmt = $conn_sqli->prepare($sql);
    $stmt->bind_param(
        'iisiiidssss',
        $route_id, $play_id, $source,
        $planned_count, $slot_count, $peak_rate_hr, $avg_rate_hr,
        $period_start, $period_end, $metadata, $updated_by
    );
    $result = $stmt->execute();
    $stmt->close();

    if (!$result) {
        SwimResponse::error('Failed to store throughput data', 500, 'DB_ERROR');
    }

    SwimResponse::success([
        'message'  => 'Throughput data stored',
        'route_id' => $route_id,
        'play_id'  => $play_id,
    ], 201);
}
