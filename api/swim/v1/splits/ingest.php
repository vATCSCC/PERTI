<?php
/**
 * VATSWIM Splits Ingest Endpoint
 *
 * POST /api/swim/v1/splits/ingest
 *
 * Accepts split configurations from external facility tools (CRC, EuroScope, vNAS, etc.)
 * and writes them to VATSIM_ADL. Requires a write-capable SWIM API key.
 *
 * Idempotent via source_id: if the same facility + source_id already exists, the config
 * is replaced rather than duplicated.
 *
 * @package PERTI\SWIM\Splits
 * @version 1.0.0
 */

require_once __DIR__ . '/../auth.php';

// This endpoint also needs ADL for writing splits
$conn_adl = get_conn_adl();

// --- Auth ---
$auth = swim_init_auth(true, true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    SwimResponse::error('Method not allowed. Use POST.', 405, 'METHOD_NOT_ALLOWED');
}

if (!$conn_adl) {
    SwimResponse::error('ADL database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

// --- Parse body ---
$body = swim_get_json_body();
if (!$body) {
    SwimResponse::error('Request body is required (JSON)', 400, 'MISSING_BODY');
}

// --- Validate required fields ---
$facility = strtoupper(trim($body['facility'] ?? ''));
if (empty($facility) || strlen($facility) > 4) {
    SwimResponse::error('facility is required (1-4 character ARTCC code)', 400, 'INVALID_FACILITY');
}

$config_name = trim($body['config_name'] ?? '');
if (empty($config_name)) {
    SwimResponse::error('config_name is required', 400, 'MISSING_CONFIG_NAME');
}

$positions = $body['positions'] ?? [];
if (!is_array($positions) || empty($positions)) {
    SwimResponse::error('positions array is required and must be non-empty', 400, 'MISSING_POSITIONS');
}

// Validate each position
foreach ($positions as $i => $pos) {
    if (empty(trim($pos['position_name'] ?? ''))) {
        SwimResponse::error("positions[$i].position_name is required", 400, 'INVALID_POSITION');
    }
    $sectors = $pos['sectors'] ?? [];
    if (!is_array($sectors) || empty($sectors)) {
        SwimResponse::error("positions[$i].sectors must be a non-empty array", 400, 'INVALID_SECTORS');
    }
}

// Optional fields
$sector_type = strtolower(trim($body['sector_type'] ?? 'high'));
if (!in_array($sector_type, ['high', 'low'])) {
    SwimResponse::error('sector_type must be "high" or "low"', 400, 'INVALID_SECTOR_TYPE');
}

$source_id = trim($body['source_id'] ?? '');
if (empty($source_id)) {
    $source_id = null;
}

// Parse datetimes
$start_time = null;
if (!empty($body['start_time_utc'])) {
    $start_time = str_replace('T', ' ', $body['start_time_utc']);
    $start_time = rtrim($start_time, 'Z');
    if (strlen($start_time) === 16) $start_time .= ':00';
}

$end_time = null;
if (!empty($body['end_time_utc'])) {
    $end_time = str_replace('T', ' ', $body['end_time_utc']);
    $end_time = rtrim($end_time, 'Z');
    if (strlen($end_time) === 16) $end_time .= ':00';
}

// --- Data authority check ---
// API-ingested configs use source='swim_api'. UI configs (source='perti') are NOT modifiable here.
$source = 'swim_api';

// --- Idempotent upsert: check if source_id already exists for this facility ---
$existing_id = null;
if ($source_id !== null) {
    $sql = "SELECT id, [source] FROM dbo.splits_configs WHERE artcc = ? AND [source] = ? AND source_id = ?";
    $stmt = sqlsrv_query($conn_adl, $sql, [$facility, $source, $source_id]);
    if ($stmt !== false) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row) {
            $existing_id = $row['id'];
        }
        sqlsrv_free_stmt($stmt);
    }
}

$config_id = null;

if ($existing_id !== null) {
    // --- UPDATE existing config ---
    $sql = "UPDATE dbo.splits_configs
            SET config_name = ?, sector_type = ?, start_time_utc = ?, end_time_utc = ?,
                status = CASE WHEN start_time_utc IS NULL OR start_time_utc <= GETUTCDATE() THEN 'active' ELSE 'scheduled' END,
                updated_at = GETUTCDATE()
            WHERE id = ?";
    $stmt = sqlsrv_query($conn_adl, $sql, [$config_name, $sector_type, $start_time, $end_time, $existing_id]);
    if ($stmt === false) {
        SwimResponse::error('Failed to update config: ' . json_encode(sqlsrv_errors()), 500, 'DB_ERROR');
    }
    sqlsrv_free_stmt($stmt);

    // Delete old positions and re-insert
    $sql = "DELETE FROM dbo.splits_positions WHERE config_id = ?";
    sqlsrv_query($conn_adl, $sql, [$existing_id]);

    $config_id = $existing_id;
} else {
    // --- INSERT new config ---
    $status = ($start_time === null || strtotime($start_time) <= time()) ? 'active' : 'scheduled';

    $sql = "INSERT INTO dbo.splits_configs
                (artcc, config_name, sector_type, start_time_utc, end_time_utc, status,
                 [source], source_id, created_by, created_at, updated_at)
            OUTPUT INSERTED.id
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'swim_api', GETUTCDATE(), GETUTCDATE())";
    $stmt = sqlsrv_query($conn_adl, $sql, [
        $facility, $config_name, $sector_type, $start_time, $end_time,
        $status, $source, $source_id
    ]);
    if ($stmt === false) {
        SwimResponse::error('Failed to insert config: ' . json_encode(sqlsrv_errors()), 500, 'DB_ERROR');
    }
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $config_id = $row['id'] ?? null;
    sqlsrv_free_stmt($stmt);

    if (!$config_id) {
        SwimResponse::error('Failed to get config ID after insert', 500, 'DB_ERROR');
    }
}

// --- Insert positions ---
$positions_inserted = 0;
foreach ($positions as $index => $pos) {
    $pos_name = trim($pos['position_name']);
    $pos_sectors = json_encode($pos['sectors']);
    $pos_color = $pos['color'] ?? '#808080';
    $pos_order = $pos['sort_order'] ?? ($index + 1);
    $pos_frequency = isset($pos['frequency']) ? trim($pos['frequency']) : null;
    $pos_oi = isset($pos['controller_oi']) ? strtoupper(trim($pos['controller_oi'])) : null;
    $pos_strata = isset($pos['strata_filter'])
        ? (is_array($pos['strata_filter']) ? json_encode($pos['strata_filter']) : $pos['strata_filter'])
        : null;

    $sql = "INSERT INTO dbo.splits_positions
                (config_id, position_name, color, sectors, sort_order, frequency, controller_oi, strata_filter, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, GETUTCDATE())";
    $pos_stmt = sqlsrv_query($conn_adl, $sql, [
        $config_id, $pos_name, $pos_color, $pos_sectors, $pos_order,
        $pos_frequency, $pos_oi, $pos_strata
    ]);
    if ($pos_stmt !== false) {
        $positions_inserted++;
        sqlsrv_free_stmt($pos_stmt);
    }
}

// --- Trigger scheduler ---
$sql = "UPDATE dbo.scheduler_state SET next_run_at = GETUTCDATE() WHERE id = 1";
sqlsrv_query($conn_adl, $sql);

// --- Fire WebSocket event ---
$event_type = $existing_id !== null ? 'splits.updated' : 'splits.activated';
$ws_event = [
    'type' => $event_type,
    'data' => [
        'config_id' => $config_id,
        'facility' => $facility,
        'config_name' => $config_name,
        'sector_type' => $sector_type,
        'source' => $source,
        'start_time_utc' => $body['start_time_utc'] ?? null,
        'end_time_utc' => $body['end_time_utc'] ?? null,
        'positions' => array_map(function ($pos) {
            return [
                'position_name' => $pos['position_name'],
                'sectors' => $pos['sectors'],
                'frequency' => $pos['frequency'] ?? null,
                'controller_oi' => $pos['controller_oi'] ?? null,
            ];
        }, $positions),
    ],
];

// Write event to WebSocket queue (atomic write pattern)
$ws_events_file = sys_get_temp_dir() . '/swim_ws_events.json';
$existing_events = [];
if (file_exists($ws_events_file)) {
    $existing_events = json_decode(file_get_contents($ws_events_file), true) ?: [];
}
$existing_events[] = $ws_event;
// Keep queue bounded
if (count($existing_events) > 10000) {
    $existing_events = array_slice($existing_events, -5000);
}
$tmp_file = $ws_events_file . '.tmp.' . getmypid();
file_put_contents($tmp_file, json_encode($existing_events));
rename($tmp_file, $ws_events_file);

// --- Response ---
$response_status = $existing_id !== null ? 200 : 201;
http_response_code($response_status);
SwimResponse::success([
    'config_id' => $config_id,
    'status' => $existing_id !== null ? 'updated' : 'created',
    'source' => $source,
    'source_id' => $source_id,
    'positions_count' => $positions_inserted,
]);
