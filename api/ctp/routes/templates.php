<?php
/**
 * CTP Route Templates CRUD API
 *
 * GET    /api/ctp/routes/templates.php?session_id=N   List templates
 * POST   /api/ctp/routes/templates.php                Create template
 * PUT    /api/ctp/routes/templates.php                Update template
 * DELETE /api/ctp/routes/templates.php                Delete template
 *
 * Manages ctp_route_templates (NAT tracks, custom oceanic routings).
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('CTP_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$conn_tmi = ctp_get_conn_tmi();

switch ($method) {
    case 'GET':
        handle_list($conn_tmi);
        break;
    case 'POST':
        handle_create($conn_tmi);
        break;
    case 'PUT':
        handle_update($conn_tmi);
        break;
    case 'DELETE':
        handle_delete($conn_tmi);
        break;
    default:
        respond_json(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

function handle_list($conn) {
    $session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : null;
    $segment = isset($_GET['segment']) ? strtoupper(trim($_GET['segment'])) : null;

    $sql = "SELECT * FROM dbo.ctp_route_templates WHERE is_active = 1";
    $params = [];

    if ($session_id) {
        $sql .= " AND (session_id IS NULL OR session_id = ?)";
        $params[] = $session_id;
    }
    if ($segment && in_array($segment, ['NA', 'OCEANIC', 'EU'])) {
        $sql .= " AND segment = ?";
        $params[] = $segment;
    }

    $sql .= " ORDER BY segment, priority ASC, template_name";

    $result = ctp_fetch_all($conn, $sql, $params);
    if (!$result['success']) {
        respond_json(500, ['status' => 'error', 'message' => 'Failed to fetch templates.']);
    }

    // Convert datetime objects
    $templates = [];
    foreach ($result['data'] as $row) {
        foreach ($row as $k => $v) {
            if ($v instanceof DateTimeInterface) {
                $row[$k] = $v->format('Y-m-d\TH:i:s\Z');
            }
        }
        $templates[] = $row;
    }

    respond_json(200, ['status' => 'ok', 'data' => ['templates' => $templates]]);
}

function handle_create($conn) {
    $cid = ctp_require_auth();
    $payload = read_request_payload();

    $required = ['template_name', 'segment', 'route_string'];
    foreach ($required as $field) {
        if (empty($payload[$field])) {
            respond_json(400, ['status' => 'error', 'message' => $field . ' is required.']);
        }
    }

    $segment = strtoupper(trim($payload['segment']));
    if (!in_array($segment, ['NA', 'OCEANIC', 'EU'])) {
        respond_json(400, ['status' => 'error', 'message' => 'segment must be NA, OCEANIC, or EU.']);
    }

    $sql = "
        INSERT INTO dbo.ctp_route_templates (
            session_id, segment, template_name, route_string,
            origin_filter, dest_filter, altitude_range,
            for_event_flights, priority, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?);
        SELECT SCOPE_IDENTITY() AS template_id;
    ";

    $params = [
        !empty($payload['session_id']) ? (int)$payload['session_id'] : null,
        $segment,
        trim($payload['template_name']),
        trim($payload['route_string']),
        !empty($payload['origin_filter']) ? json_encode($payload['origin_filter']) : null,
        !empty($payload['dest_filter']) ? json_encode($payload['dest_filter']) : null,
        !empty($payload['altitude_range']) ? trim($payload['altitude_range']) : null,
        isset($payload['for_event_flights']) ? ($payload['for_event_flights'] ? 1 : 0) : null,
        isset($payload['priority']) ? (int)$payload['priority'] : 50,
        $cid
    ];

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        respond_json(500, ['status' => 'error', 'message' => 'Failed to create template.']);
    }

    sqlsrv_next_result($stmt);
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $template_id = $row ? (int)$row['template_id'] : null;
    sqlsrv_free_stmt($stmt);

    respond_json(201, [
        'status' => 'ok',
        'data' => ['template_id' => $template_id]
    ]);
}

function handle_update($conn) {
    $cid = ctp_require_auth();
    $payload = read_request_payload();

    $template_id = isset($payload['template_id']) ? (int)$payload['template_id'] : 0;
    if ($template_id <= 0) {
        respond_json(400, ['status' => 'error', 'message' => 'template_id is required.']);
    }

    $fields = [];
    $params = [];

    $allowed = [
        'template_name' => 'NVARCHAR',
        'route_string' => 'NVARCHAR',
        'segment' => 'NVARCHAR',
        'altitude_range' => 'NVARCHAR',
        'priority' => 'INT',
        'is_active' => 'BIT'
    ];

    foreach ($allowed as $field => $type) {
        if (isset($payload[$field])) {
            $fields[] = "{$field} = ?";
            if ($type === 'INT') $params[] = (int)$payload[$field];
            elseif ($type === 'BIT') $params[] = $payload[$field] ? 1 : 0;
            else $params[] = trim($payload[$field]);
        }
    }

    // JSON fields
    if (isset($payload['origin_filter'])) {
        $fields[] = "origin_filter = ?";
        $params[] = is_array($payload['origin_filter']) ? json_encode($payload['origin_filter']) : $payload['origin_filter'];
    }
    if (isset($payload['dest_filter'])) {
        $fields[] = "dest_filter = ?";
        $params[] = is_array($payload['dest_filter']) ? json_encode($payload['dest_filter']) : $payload['dest_filter'];
    }
    if (array_key_exists('for_event_flights', $payload)) {
        $fields[] = "for_event_flights = ?";
        $params[] = $payload['for_event_flights'] === null ? null : ($payload['for_event_flights'] ? 1 : 0);
    }

    if (empty($fields)) {
        respond_json(400, ['status' => 'error', 'message' => 'No fields to update.']);
    }

    $params[] = $template_id;
    $sql = "UPDATE dbo.ctp_route_templates SET " . implode(', ', $fields) . " WHERE template_id = ?";

    $result = ctp_execute($conn, $sql, $params);
    if (!$result['success']) {
        respond_json(500, ['status' => 'error', 'message' => 'Failed to update template.']);
    }

    respond_json(200, ['status' => 'ok', 'data' => ['template_id' => $template_id]]);
}

function handle_delete($conn) {
    $cid = ctp_require_auth();
    $payload = read_request_payload();

    $template_id = isset($payload['template_id']) ? (int)$payload['template_id'] : 0;
    if ($template_id <= 0) {
        respond_json(400, ['status' => 'error', 'message' => 'template_id is required.']);
    }

    // Soft delete
    $result = ctp_execute($conn,
        "UPDATE dbo.ctp_route_templates SET is_active = 0 WHERE template_id = ?",
        [$template_id]
    );
    if (!$result['success']) {
        respond_json(500, ['status' => 'error', 'message' => 'Failed to delete template.']);
    }

    respond_json(200, ['status' => 'ok', 'data' => ['template_id' => $template_id, 'deleted' => true]]);
}
