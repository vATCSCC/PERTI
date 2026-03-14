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

// SWIM-only: query swim_playbook_route_throughput in SWIM_API
$conn_swim_api = get_conn_swim();
if (!$conn_swim_api) {
    SwimResponse::error('SWIM database connection not available', 503, 'SERVICE_UNAVAILABLE');
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
    global $conn_swim_api;

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
            FROM dbo.swim_playbook_route_throughput t
            JOIN dbo.swim_playbook_routes r ON t.route_id = r.route_id";

    $where = [];
    $params = [];

    if ($play_id > 0) {
        $where[] = "t.play_id = ?";
        $params[] = $play_id;
    }

    if ($route_id > 0) {
        $where[] = "t.route_id = ?";
        $params[] = $route_id;
    }

    $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY r.sort_order ASC';

    $stmt = sqlsrv_query($conn_swim_api, $sql, $params);
    if ($stmt === false) {
        $err = sqlsrv_errors();
        SwimResponse::error('Database error: ' . ($err[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
    }

    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Format DateTime objects from sqlsrv
        foreach ($row as $key => $val) {
            if ($val instanceof \DateTime) {
                $row[$key] = $val->format('Y-m-d H:i:s');
            }
        }
        if ($row['metadata_json']) {
            $row['metadata'] = json_decode($row['metadata_json'], true);
        } else {
            $row['metadata'] = null;
        }
        unset($row['metadata_json']);
        $rows[] = $row;
    }
    sqlsrv_free_stmt($stmt);

    SwimResponse::success([
        'count'      => count($rows),
        'throughput' => $rows,
    ]);
}

function handlePostThroughput(): void {
    global $conn_swim_api;

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
    $check = sqlsrv_query($conn_swim_api,
        "SELECT route_id FROM dbo.swim_playbook_routes WHERE route_id = ? AND play_id = ?",
        [$route_id, $play_id]
    );
    if ($check === false || !sqlsrv_fetch($check)) {
        if ($check !== false) sqlsrv_free_stmt($check);
        SwimResponse::error('Route not found or does not belong to specified play', 404, 'NOT_FOUND');
    }
    sqlsrv_free_stmt($check);

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

    // MERGE upsert (SQL Server equivalent of INSERT ON DUPLICATE KEY UPDATE)
    $sql = "MERGE dbo.swim_playbook_route_throughput AS t
            USING (SELECT ? AS route_id, ? AS play_id) AS s
            ON t.route_id = s.route_id AND t.play_id = s.play_id
            WHEN MATCHED THEN UPDATE SET
                source        = ?,
                planned_count = ?,
                slot_count    = ?,
                peak_rate_hr  = ?,
                avg_rate_hr   = ?,
                period_start  = ?,
                period_end    = ?,
                metadata_json = ?,
                updated_by    = ?,
                updated_at    = SYSUTCDATETIME()
            WHEN NOT MATCHED THEN INSERT
                (route_id, play_id, source, planned_count, slot_count, peak_rate_hr, avg_rate_hr,
                 period_start, period_end, metadata_json, updated_by, created_at, updated_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, SYSUTCDATETIME(), SYSUTCDATETIME());";

    $params = [
        // USING clause
        $route_id, $play_id,
        // WHEN MATCHED SET values
        $source, $planned_count, $slot_count, $peak_rate_hr, $avg_rate_hr,
        $period_start, $period_end, $metadata, $updated_by,
        // WHEN NOT MATCHED INSERT values
        $route_id, $play_id, $source, $planned_count, $slot_count, $peak_rate_hr, $avg_rate_hr,
        $period_start, $period_end, $metadata, $updated_by
    ];

    $stmt = sqlsrv_query($conn_swim_api, $sql, $params);
    if ($stmt === false) {
        $err = sqlsrv_errors();
        SwimResponse::error('Failed to store throughput data: ' . ($err[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
    }
    sqlsrv_free_stmt($stmt);

    SwimResponse::success([
        'message'  => 'Throughput data stored',
        'route_id' => $route_id,
        'play_id'  => $play_id,
    ], 201);
}
