<?php
/**
 * TMI Advisories API
 * 
 * CRUD operations for formal advisories (GS, GDP, AFP, Reroute, etc.)
 * 
 * Endpoints:
 *   GET    /api/tmi/advisories.php           - List advisories (with filters)
 *   GET    /api/tmi/advisories.php?id=123    - Get single advisory
 *   POST   /api/tmi/advisories.php           - Create new advisory
 *   PUT    /api/tmi/advisories.php?id=123    - Update advisory
 *   DELETE /api/tmi/advisories.php?id=123    - Cancel advisory
 * 
 * Query Parameters (GET list):
 *   status         - Filter by status (DRAFT, PROPOSED, ACTIVE, CANCELLED, EXPIRED)
 *   advisory_type  - Filter by type (GS, GDP, AFP, CTOP, REROUTE, OPS_PLAN, etc.)
 *   ctl_element    - Filter by airport/ARTCC/FCA
 *   active_only    - Set to 1 to show only currently active advisories
 *   page           - Page number (default: 1)
 *   per_page       - Items per page (default: 50, max: 200)
 * 
 * @package PERTI
 * @subpackage TMI
 */

require_once __DIR__ . '/helpers.php';

$method = tmi_method();
$id = tmi_param('id');

switch ($method) {
    case 'GET':
        if ($id) {
            getAdvisory($id);
        } else {
            listAdvisories();
        }
        break;
    
    case 'POST':
        createAdvisory();
        break;
    
    case 'PUT':
    case 'PATCH':
        if (!$id) TmiResponse::error('Advisory ID required', 400);
        updateAdvisory($id);
        break;
    
    case 'DELETE':
        if (!$id) TmiResponse::error('Advisory ID required', 400);
        deleteAdvisory($id);
        break;
    
    default:
        TmiResponse::error('Method not allowed', 405);
}

/**
 * List advisories with filters
 */
function listAdvisories() {
    global $conn_tmi;
    
    tmi_init(false);
    
    $where = [];
    $params = [];
    
    // Status filter
    $status = tmi_param('status');
    if ($status) {
        $where[] = "status = ?";
        $params[] = strtoupper($status);
    }
    
    // Advisory type filter
    $advisory_type = tmi_param('advisory_type');
    if ($advisory_type) {
        $where[] = "advisory_type = ?";
        $params[] = strtoupper($advisory_type);
    }
    
    // Control element filter
    $ctl_element = tmi_param('ctl_element');
    if ($ctl_element) {
        $where[] = "ctl_element = ?";
        $params[] = strtoupper($ctl_element);
    }
    
    // Active only filter
    if (tmi_param('active_only') === '1') {
        $where[] = "status = 'ACTIVE'";
        $where[] = "(effective_until IS NULL OR effective_until > SYSUTCDATETIME())";
    }
    
    // Pagination
    $page = tmi_int_param('page', 1, 1);
    $per_page = tmi_int_param('per_page', 50, 1, 200);
    $offset = ($page - 1) * $per_page;
    
    $where_sql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Get total count
    $total = tmi_count('tmi_advisories', implode(' AND ', $where), $params);
    
    // Get advisories
    $sql = "SELECT 
                advisory_id, advisory_guid, advisory_number, advisory_type,
                ctl_element, element_type, scope_facilities,
                program_id, program_rate, delay_cap,
                effective_from, effective_until,
                subject, body_text, reason_code, reason_detail,
                reroute_id, reroute_name, reroute_area, reroute_string,
                mit_miles, mit_type, mit_fix,
                status, is_proposed, source_type, source_id,
                discord_message_id, discord_posted_at,
                created_by, created_by_name, created_at, updated_at,
                approved_by, approved_at, cancelled_by, cancelled_at, cancel_reason,
                revision_number, supersedes_advisory_id
            FROM dbo.tmi_advisories
            $where_sql
            ORDER BY created_at DESC
            OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";
    
    $params[] = $offset;
    $params[] = $per_page;
    
    $result = sqlsrv_query($conn_tmi, $sql, $params);
    $advisories = tmi_fetch_all($result);
    
    TmiResponse::paginated($advisories, $total, $page, $per_page);
}

/**
 * Get single advisory by ID
 */
function getAdvisory($id) {
    global $conn_tmi;
    
    tmi_init(false);
    
    $advisory = tmi_query_one("SELECT * FROM dbo.tmi_advisories WHERE advisory_id = ?", [$id]);
    
    if (!$advisory) {
        TmiResponse::error('Advisory not found', 404);
    }
    
    // Include linked program if exists
    if (!empty($advisory['program_id'])) {
        $program = tmi_query_one(
            "SELECT program_id, program_type, program_name, start_utc, end_utc, status, is_active
             FROM dbo.tmi_programs WHERE program_id = ?",
            [$advisory['program_id']]
        );
        $advisory['program'] = $program;
    }
    
    // Include linked reroute if exists
    if (!empty($advisory['reroute_id'])) {
        $reroute = tmi_query_one(
            "SELECT reroute_id, name, status, start_utc, end_utc, protected_segment
             FROM dbo.tmi_reroutes WHERE reroute_id = ?",
            [$advisory['reroute_id']]
        );
        $advisory['reroute'] = $reroute;
    }
    
    TmiResponse::success($advisory);
}

/**
 * Create new advisory
 */
function createAdvisory() {
    global $conn_tmi;
    
    $auth = tmi_init(true);
    $body = tmi_get_json_body();
    
    if (!$body) {
        TmiResponse::error('Request body required', 400);
    }
    
    // Validate required fields
    $required = ['advisory_type', 'subject', 'body_text', 'source_type'];
    foreach ($required as $field) {
        if (empty($body[$field])) {
            TmiResponse::error("Missing required field: $field", 400);
        }
    }
    
    // Validate advisory type
    $valid_types = ['GS', 'GDP', 'AFP', 'CTOP', 'REROUTE', 'OPS_PLAN', 'GENERAL', 'CDR', 'SWAP', 'FEA', 'FCA', 'ICR', 'TOS', 'MIT'];
    if (!in_array(strtoupper($body['advisory_type']), $valid_types)) {
        TmiResponse::error('Invalid advisory_type. Must be one of: ' . implode(', ', $valid_types), 400);
    }
    
    // Generate advisory number
    $adv_number = $body['advisory_number'] ?? tmi_next_advisory_number();
    
    // Build insert data
    $data = [
        'advisory_number' => $adv_number,
        'advisory_type' => strtoupper($body['advisory_type']),
        'ctl_element' => isset($body['ctl_element']) ? strtoupper($body['ctl_element']) : null,
        'element_type' => isset($body['element_type']) ? strtoupper($body['element_type']) : null,
        'scope_facilities' => isset($body['scope_facilities']) ? json_encode($body['scope_facilities']) : null,
        'program_id' => isset($body['program_id']) ? (int)$body['program_id'] : null,
        'program_rate' => isset($body['program_rate']) ? (int)$body['program_rate'] : null,
        'delay_cap' => isset($body['delay_cap']) ? (int)$body['delay_cap'] : null,
        'effective_from' => tmi_parse_datetime($body['effective_from'] ?? null),
        'effective_until' => tmi_parse_datetime($body['effective_until'] ?? null),
        'subject' => $body['subject'],
        'body_text' => $body['body_text'],
        'reason_code' => isset($body['reason_code']) ? strtoupper($body['reason_code']) : null,
        'reason_detail' => $body['reason_detail'] ?? null,
        'reroute_id' => isset($body['reroute_id']) ? (int)$body['reroute_id'] : null,
        'reroute_name' => $body['reroute_name'] ?? null,
        'reroute_area' => isset($body['reroute_area']) ? strtoupper($body['reroute_area']) : null,
        'reroute_string' => $body['reroute_string'] ?? null,
        'reroute_from' => $body['reroute_from'] ?? null,
        'reroute_to' => $body['reroute_to'] ?? null,
        'mit_miles' => isset($body['mit_miles']) ? (int)$body['mit_miles'] : null,
        'mit_type' => isset($body['mit_type']) ? strtoupper($body['mit_type']) : null,
        'mit_fix' => isset($body['mit_fix']) ? strtoupper($body['mit_fix']) : null,
        'status' => strtoupper($body['status'] ?? 'DRAFT'),
        'is_proposed' => ($body['status'] ?? 'DRAFT') === 'PROPOSED' ? 1 : 0,
        'source_type' => strtoupper($body['source_type']),
        'source_id' => $body['source_id'] ?? null,
        'created_by' => $auth->getUserId(),
        'created_by_name' => $auth->getUserName()
    ];
    
    $id = tmi_insert('tmi_advisories', $data);
    
    if ($id === false) {
        TmiResponse::error('Failed to create advisory: ' . tmi_sql_errors(), 500);
    }
    
    // Log event
    tmi_log_event('ADVISORY', $id, 'CREATE', [
        'detail' => "{$data['advisory_type']}: {$data['subject']}",
        'source_type' => $data['source_type'],
        'actor_id' => $auth->getUserId(),
        'actor_name' => $auth->getUserName()
    ]);
    
    // Fetch and return created advisory
    $advisory = tmi_query_one("SELECT * FROM dbo.tmi_advisories WHERE advisory_id = ?", [$id]);
    
    TmiResponse::created($advisory);
}

/**
 * Update existing advisory
 */
function updateAdvisory($id) {
    global $conn_tmi;
    
    $auth = tmi_init(true);
    $body = tmi_get_json_body();
    
    if (!$body) {
        TmiResponse::error('Request body required', 400);
    }
    
    // Check advisory exists
    $existing = tmi_query_one("SELECT * FROM dbo.tmi_advisories WHERE advisory_id = ?", [$id]);
    if (!$existing) {
        TmiResponse::error('Advisory not found', 404);
    }
    
    $data = ['updated_at' => gmdate('Y-m-d H:i:s')];
    
    // Handle status changes specially
    if (isset($body['status'])) {
        $new_status = strtoupper($body['status']);
        $data['status'] = $new_status;
        
        if ($new_status === 'ACTIVE') {
            $data['is_proposed'] = 0;
            $data['approved_by'] = $auth->getUserId();
            $data['approved_at'] = gmdate('Y-m-d H:i:s');
        } elseif ($new_status === 'CANCELLED') {
            $data['cancelled_by'] = $auth->getUserId();
            $data['cancelled_at'] = gmdate('Y-m-d H:i:s');
            $data['cancel_reason'] = $body['cancel_reason'] ?? 'Cancelled via API';
        }
    }
    
    // Update other fields
    $allowed_fields = [
        'ctl_element', 'element_type', 'program_id', 'program_rate', 'delay_cap',
        'effective_from', 'effective_until', 'subject', 'body_text',
        'reason_code', 'reason_detail', 'reroute_id', 'reroute_name',
        'reroute_area', 'reroute_string', 'mit_miles', 'mit_type', 'mit_fix',
        'discord_message_id', 'discord_posted_at'
    ];
    
    foreach ($allowed_fields as $field) {
        if (isset($body[$field])) {
            if (in_array($field, ['effective_from', 'effective_until', 'discord_posted_at'])) {
                $data[$field] = tmi_parse_datetime($body[$field]);
            } elseif (in_array($field, ['ctl_element', 'element_type', 'reason_code', 'reroute_area', 'mit_type', 'mit_fix'])) {
                $data[$field] = strtoupper($body[$field]);
            } elseif (in_array($field, ['program_id', 'program_rate', 'delay_cap', 'reroute_id', 'mit_miles'])) {
                $data[$field] = (int)$body[$field];
            } else {
                $data[$field] = $body[$field];
            }
        }
    }
    
    $rows = tmi_update('tmi_advisories', $data, 'advisory_id = ?', [$id]);
    
    if ($rows === false) {
        TmiResponse::error('Failed to update advisory: ' . tmi_sql_errors(), 500);
    }
    
    // Log event
    $event_type = isset($body['status']) ? 'STATUS_CHANGE' : 'UPDATE';
    $event_detail = isset($body['status']) ? "Status: {$existing['status']} → {$body['status']}" : 'Advisory updated';
    
    tmi_log_event('ADVISORY', $id, $event_type, [
        'detail' => $event_detail,
        'source_type' => 'API',
        'actor_id' => $auth->getUserId(),
        'actor_name' => $auth->getUserName()
    ]);
    
    $advisory = tmi_query_one("SELECT * FROM dbo.tmi_advisories WHERE advisory_id = ?", [$id]);
    
    TmiResponse::success($advisory);
}

/**
 * Delete/cancel advisory
 */
function deleteAdvisory($id) {
    global $conn_tmi;
    
    $auth = tmi_init(true);
    
    $existing = tmi_query_one("SELECT * FROM dbo.tmi_advisories WHERE advisory_id = ?", [$id]);
    if (!$existing) {
        TmiResponse::error('Advisory not found', 404);
    }
    
    $body = tmi_get_json_body() ?? [];
    $cancel_reason = $body['cancel_reason'] ?? tmi_param('reason') ?? 'Cancelled via API';
    
    // Soft delete - mark as cancelled
    $data = [
        'status' => 'CANCELLED',
        'cancelled_by' => $auth->getUserId(),
        'cancelled_at' => gmdate('Y-m-d H:i:s'),
        'cancel_reason' => $cancel_reason,
        'updated_at' => gmdate('Y-m-d H:i:s')
    ];
    
    $rows = tmi_update('tmi_advisories', $data, 'advisory_id = ?', [$id]);
    
    if ($rows === false) {
        TmiResponse::error('Failed to cancel advisory', 500);
    }
    
    // Log event
    tmi_log_event('ADVISORY', $id, 'DELETE', [
        'detail' => $cancel_reason,
        'source_type' => 'API',
        'actor_id' => $auth->getUserId(),
        'actor_name' => $auth->getUserName()
    ]);
    
    TmiResponse::success(['message' => 'Advisory cancelled', 'advisory_id' => $id]);
}
