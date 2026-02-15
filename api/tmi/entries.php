<?php
/**
 * TMI Entries API
 * 
 * CRUD operations for NTML log entries (MIT, MINIT, DELAY, CONFIG, APREQ, etc.)
 * 
 * Endpoints:
 *   GET    /api/tmi/entries.php           - List entries (with filters)
 *   GET    /api/tmi/entries.php?id=123    - Get single entry
 *   POST   /api/tmi/entries.php           - Create new entry
 *   PUT    /api/tmi/entries.php?id=123    - Update entry
 *   DELETE /api/tmi/entries.php?id=123    - Delete/cancel entry
 * 
 * Query Parameters (GET list):
 *   status       - Filter by status (ACTIVE, DRAFT, EXPIRED, etc.)
 *   entry_type   - Filter by type (MIT, MINIT, DELAY, CONFIG, APREQ)
 *   facility     - Filter by requesting or providing facility
 *   ctl_element  - Filter by control element (airport/fix)
 *   active_only  - Set to 1 to show only currently active entries
 *   page         - Page number (default: 1)
 *   per_page     - Items per page (default: 50, max: 200)
 * 
 * @package PERTI
 * @subpackage TMI
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../load/perti_constants.php';

$method = tmi_method();
$id = tmi_param('id');

// Route based on method
switch ($method) {
    case 'GET':
        if ($id) {
            getEntry($id);
        } else {
            listEntries();
        }
        break;
    
    case 'POST':
        createEntry();
        break;
    
    case 'PUT':
    case 'PATCH':
        if (!$id) TmiResponse::error('Entry ID required', 400);
        updateEntry($id);
        break;
    
    case 'DELETE':
        if (!$id) TmiResponse::error('Entry ID required', 400);
        deleteEntry($id);
        break;
    
    default:
        TmiResponse::error('Method not allowed', 405);
}

/**
 * List entries with filters
 */
function listEntries() {
    global $conn_tmi;
    
    // Public read access - no auth required for list
    tmi_init(false);

    // Build query
    $where = [];
    $params = [];

    // Scope to active org
    $where[] = "org_code = ?";
    $params[] = tmi_get_org_code();

    // Status filter
    $status = tmi_param('status');
    if ($status) {
        $where[] = "status = ?";
        $params[] = strtoupper($status);
    }
    
    // Entry type filter
    $entry_type = tmi_param('entry_type');
    if ($entry_type) {
        $where[] = "entry_type = ?";
        $params[] = strtoupper($entry_type);
    }
    
    // Facility filter (requesting or providing)
    $facility = tmi_param('facility');
    if ($facility) {
        $where[] = "(requesting_facility = ? OR providing_facility = ?)";
        $params[] = strtoupper($facility);
        $params[] = strtoupper($facility);
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
        $where[] = "(valid_until IS NULL OR valid_until > SYSUTCDATETIME())";
    }
    
    // Pagination
    $page = tmi_int_param('page', 1, 1);
    $per_page = tmi_int_param('per_page', 50, 1, 200);
    $offset = ($page - 1) * $per_page;
    
    // Build WHERE clause
    $where_sql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Get total count
    $count_sql = "SELECT COUNT(*) as cnt FROM dbo.tmi_entries $where_sql";
    $count_result = sqlsrv_query($conn_tmi, $count_sql, $params);
    $total = 0;
    if ($count_result && ($row = sqlsrv_fetch_array($count_result, SQLSRV_FETCH_ASSOC))) {
        $total = (int)$row['cnt'];
    }
    
    // Get entries
    $sql = "SELECT 
                entry_id, entry_guid, determinant_code, protocol_type, entry_type,
                ctl_element, element_type, requesting_facility, providing_facility,
                restriction_value, restriction_unit, condition_text, qualifiers,
                exclusions, reason_code, reason_detail,
                valid_from, valid_until, status,
                source_type, source_id, discord_message_id,
                created_by, created_by_name, created_at, updated_at,
                cancelled_by, cancelled_at, cancel_reason
            FROM dbo.tmi_entries
            $where_sql
            ORDER BY created_at DESC
            OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";
    
    $params[] = $offset;
    $params[] = $per_page;
    
    $result = sqlsrv_query($conn_tmi, $sql, $params);
    $entries = tmi_fetch_all($result);
    
    TmiResponse::paginated($entries, $total, $page, $per_page);
}

/**
 * Get single entry by ID
 */
function getEntry($id) {
    global $conn_tmi;
    
    tmi_init(false);
    
    $sql = "SELECT * FROM dbo.tmi_entries WHERE entry_id = ? AND org_code = ?";
    $entry = tmi_query_one($sql, [$id, tmi_get_org_code()]);
    
    if (!$entry) {
        TmiResponse::error('Entry not found', 404);
    }
    
    TmiResponse::success($entry);
}

/**
 * Create new entry
 */
function createEntry() {
    global $conn_tmi;
    
    $auth = tmi_init(true);
    $body = tmi_get_json_body();
    
    if (!$body) {
        TmiResponse::error('Request body required', 400);
    }
    
    // Validate required fields
    $required = ['determinant_code', 'protocol_type', 'entry_type', 'source_type'];
    foreach ($required as $field) {
        if (empty($body[$field])) {
            TmiResponse::error("Missing required field: $field", 400);
        }
    }
    
    // Validate entry type
    if (!in_array(strtoupper($body['entry_type']), PERTI_ENTRY_TYPES)) {
        TmiResponse::error('Invalid entry_type. Must be one of: ' . implode(', ', PERTI_ENTRY_TYPES), 400);
    }
    
    // Build insert data
    $data = [
        'determinant_code' => strtoupper($body['determinant_code']),
        'protocol_type' => (int)$body['protocol_type'],
        'entry_type' => strtoupper($body['entry_type']),
        'ctl_element' => isset($body['ctl_element']) ? strtoupper($body['ctl_element']) : null,
        'element_type' => isset($body['element_type']) ? strtoupper($body['element_type']) : null,
        'requesting_facility' => isset($body['requesting_facility']) ? strtoupper($body['requesting_facility']) : null,
        'providing_facility' => isset($body['providing_facility']) ? strtoupper($body['providing_facility']) : null,
        'restriction_value' => isset($body['restriction_value']) ? (int)$body['restriction_value'] : null,
        'restriction_unit' => isset($body['restriction_unit']) ? strtoupper($body['restriction_unit']) : null,
        'condition_text' => $body['condition_text'] ?? null,
        'qualifiers' => $body['qualifiers'] ?? null,
        'exclusions' => $body['exclusions'] ?? null,
        'reason_code' => isset($body['reason_code']) ? strtoupper($body['reason_code']) : null,
        'reason_detail' => $body['reason_detail'] ?? null,
        'valid_from' => tmi_parse_datetime($body['valid_from'] ?? null),
        'valid_until' => tmi_parse_datetime($body['valid_until'] ?? null),
        'status' => strtoupper($body['status'] ?? 'DRAFT'),
        'source_type' => strtoupper($body['source_type']),
        'source_id' => $body['source_id'] ?? null,
        'source_channel' => $body['source_channel'] ?? null,
        'raw_input' => $body['raw_input'] ?? null,
        'parsed_data' => isset($body['parsed_data']) ? json_encode($body['parsed_data']) : null,
        'created_by' => $auth->getUserId(),
        'created_by_name' => $auth->getUserName()
    ];
    
    // Generate content hash for deduplication
    $hash_content = implode('|', [
        $data['determinant_code'],
        $data['entry_type'],
        $data['ctl_element'] ?? '',
        $data['restriction_value'] ?? '',
        $data['valid_from'] ?? '',
        $data['valid_until'] ?? ''
    ]);
    $data['content_hash'] = hash('sha256', $hash_content);
    
    // Check for duplicate
    $existing = tmi_query_one(
        "SELECT entry_id FROM dbo.tmi_entries WHERE content_hash = ? AND status NOT IN ('CANCELLED', 'EXPIRED', 'SUPERSEDED')",
        [$data['content_hash']]
    );
    
    if ($existing) {
        TmiResponse::error('Duplicate entry detected', 409, 'DUPLICATE');
    }
    
    $id = tmi_insert('tmi_entries', $data);
    
    if ($id === false) {
        TmiResponse::error('Failed to create entry: ' . tmi_sql_errors(), 500);
    }
    
    // Log event
    tmi_log_event('ENTRY', $id, 'CREATE', [
        'source_type' => $data['source_type'],
        'actor_id' => $auth->getUserId(),
        'actor_name' => $auth->getUserName()
    ]);
    
    // Fetch and return created entry
    $entry = tmi_query_one("SELECT * FROM dbo.tmi_entries WHERE entry_id = ?", [$id]);
    
    TmiResponse::created($entry);
}

/**
 * Update existing entry
 */
function updateEntry($id) {
    global $conn_tmi;
    
    $auth = tmi_init(true);
    $body = tmi_get_json_body();
    
    if (!$body) {
        TmiResponse::error('Request body required', 400);
    }
    
    // Check entry exists (scoped to org)
    $existing = tmi_query_one(
        "SELECT * FROM dbo.tmi_entries WHERE entry_id = ? AND org_code = ?",
        [$id, tmi_get_org_code()]
    );
    if (!$existing) {
        TmiResponse::error('Entry not found', 404);
    }

    // Build update data (only update provided fields)
    $data = ['updated_at' => gmdate('Y-m-d H:i:s')];
    
    $allowed_fields = [
        'determinant_code', 'protocol_type', 'entry_type',
        'ctl_element', 'element_type', 'requesting_facility', 'providing_facility',
        'restriction_value', 'restriction_unit', 'condition_text', 'qualifiers',
        'exclusions', 'reason_code', 'reason_detail',
        'valid_from', 'valid_until', 'status'
    ];
    
    foreach ($allowed_fields as $field) {
        if (isset($body[$field])) {
            if (in_array($field, ['valid_from', 'valid_until'])) {
                $data[$field] = tmi_parse_datetime($body[$field]);
            } elseif (in_array($field, ['determinant_code', 'entry_type', 'ctl_element', 'element_type', 
                                        'requesting_facility', 'providing_facility', 'restriction_unit',
                                        'reason_code', 'status'])) {
                $data[$field] = strtoupper($body[$field]);
            } else {
                $data[$field] = $body[$field];
            }
        }
    }
    
    $rows = tmi_update('tmi_entries', $data, 'entry_id = ?', [$id]);
    
    if ($rows === false) {
        TmiResponse::error('Failed to update entry: ' . tmi_sql_errors(), 500);
    }
    
    // Log event
    tmi_log_event('ENTRY', $id, 'UPDATE', [
        'detail' => isset($body['status']) ? "Status changed to {$body['status']}" : 'Fields updated',
        'source_type' => 'API',
        'actor_id' => $auth->getUserId(),
        'actor_name' => $auth->getUserName()
    ]);
    
    // Fetch and return updated entry
    $entry = tmi_query_one("SELECT * FROM dbo.tmi_entries WHERE entry_id = ?", [$id]);
    
    TmiResponse::success($entry);
}

/**
 * Delete/cancel entry
 */
function deleteEntry($id) {
    global $conn_tmi;
    
    $auth = tmi_init(true);
    
    // Check entry exists (scoped to org)
    $existing = tmi_query_one(
        "SELECT * FROM dbo.tmi_entries WHERE entry_id = ? AND org_code = ?",
        [$id, tmi_get_org_code()]
    );
    if (!$existing) {
        TmiResponse::error('Entry not found', 404);
    }

    // Get cancel reason from query or body
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
    
    $rows = tmi_update('tmi_entries', $data, 'entry_id = ?', [$id]);
    
    if ($rows === false) {
        TmiResponse::error('Failed to cancel entry', 500);
    }
    
    // Log event
    tmi_log_event('ENTRY', $id, 'DELETE', [
        'detail' => $cancel_reason,
        'source_type' => 'API',
        'actor_id' => $auth->getUserId(),
        'actor_name' => $auth->getUserName()
    ]);
    
    TmiResponse::success(['message' => 'Entry cancelled', 'entry_id' => $id]);
}
