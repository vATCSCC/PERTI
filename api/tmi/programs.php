<?php
/**
 * TMI Programs API
 * 
 * CRUD operations for GDT programs (Ground Stop, Ground Delay Programs)
 * 
 * Endpoints:
 *   GET    /api/tmi/programs.php           - List programs (with filters)
 *   GET    /api/tmi/programs.php?id=123    - Get single program
 *   POST   /api/tmi/programs.php           - Create new program
 *   PUT    /api/tmi/programs.php?id=123    - Update program
 *   DELETE /api/tmi/programs.php?id=123    - Purge/cancel program
 * 
 * Query Parameters (GET list):
 *   status       - Filter by status (PROPOSED, ACTIVE, COMPLETED, PURGED)
 *   program_type - Filter by type (GS, GDP-DAS, GDP-GAAP, GDP-UDP, AFP-DAS, etc.)
 *   ctl_element  - Filter by airport/FCA
 *   active_only  - Set to 1 to show only currently active programs
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

switch ($method) {
    case 'GET':
        if ($id) {
            getProgram($id);
        } else {
            listPrograms();
        }
        break;
    
    case 'POST':
        createProgram();
        break;
    
    case 'PUT':
    case 'PATCH':
        if (!$id) TmiResponse::error('Program ID required', 400);
        updateProgram($id);
        break;
    
    case 'DELETE':
        if (!$id) TmiResponse::error('Program ID required', 400);
        deleteProgram($id);
        break;
    
    default:
        TmiResponse::error('Method not allowed', 405);
}

/**
 * List programs with filters
 */
function listPrograms() {
    global $conn_tmi;
    
    tmi_init(false);

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
    
    // Program type filter
    $program_type = tmi_param('program_type');
    if ($program_type) {
        $where[] = "program_type = ?";
        $params[] = strtoupper($program_type);
    }
    
    // Control element filter
    $ctl_element = tmi_param('ctl_element');
    if ($ctl_element) {
        $where[] = "ctl_element = ?";
        $params[] = strtoupper($ctl_element);
    }
    
    // Active only filter
    if (tmi_param('active_only') === '1') {
        $where[] = "is_active = 1";
        $where[] = "end_utc > SYSUTCDATETIME()";
    }
    
    // Pagination
    $page = tmi_int_param('page', 1, 1);
    $per_page = tmi_int_param('per_page', 50, 1, 200);
    $offset = ($page - 1) * $per_page;
    
    $where_sql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Get total count
    $total = tmi_count('tmi_programs', implode(' AND ', $where), $params);
    
    // Get programs
    $sql = "SELECT 
                program_id, program_guid, ctl_element, element_type, program_type,
                program_name, adv_number, start_utc, end_utc,
                cumulative_start, cumulative_end, status, is_proposed, is_active,
                program_rate, reserve_rate, delay_limit_min, target_delay_mult,
                arrival_fix_filter, aircraft_type_filter, impacting_condition,
                cause_text, comments, revision_number,
                total_flights, controlled_flights, exempt_flights,
                avg_delay_min, max_delay_min, total_delay_min,
                source_type, discord_message_id,
                created_by, created_at, updated_at,
                activated_by, activated_at, purged_by, purged_at
            FROM dbo.tmi_programs
            $where_sql
            ORDER BY created_at DESC
            OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";
    
    $params[] = $offset;
    $params[] = $per_page;
    
    $result = sqlsrv_query($conn_tmi, $sql, $params);
    $programs = tmi_fetch_all($result);
    
    TmiResponse::paginated($programs, $total, $page, $per_page);
}

/**
 * Get single program by ID
 */
function getProgram($id) {
    global $conn_tmi;
    
    tmi_init(false);
    
    $program = tmi_query_one(
        "SELECT * FROM dbo.tmi_programs WHERE program_id = ? AND org_code = ?",
        [$id, tmi_get_org_code()]
    );

    if (!$program) {
        TmiResponse::error('Program not found', 404);
    }

    // Include slot summary if requested
    if (tmi_param('include_slots') === '1') {
        $slots = tmi_query(
            "SELECT slot_id, slot_name, slot_time_utc, slot_type, slot_status,
                    assigned_callsign, assigned_origin, ctd_utc, cta_utc
             FROM dbo.tmi_slots 
             WHERE program_id = ? 
             ORDER BY slot_time_utc",
            [$id]
        );
        $program['slots'] = $slots ?: [];
    }
    
    TmiResponse::success($program);
}

/**
 * Create new program
 */
function createProgram() {
    global $conn_tmi;
    
    $auth = tmi_init(true);
    $body = tmi_get_json_body();
    
    if (!$body) {
        TmiResponse::error('Request body required', 400);
    }
    
    // Validate required fields
    $required = ['ctl_element', 'element_type', 'program_type', 'start_utc', 'end_utc'];
    foreach ($required as $field) {
        if (empty($body[$field])) {
            TmiResponse::error("Missing required field: $field", 400);
        }
    }
    
    // Validate program type
    if (!in_array(strtoupper($body['program_type']), PERTI_PROGRAM_TYPES)) {
        TmiResponse::error('Invalid program_type. Must be one of: ' . implode(', ', PERTI_PROGRAM_TYPES), 400);
    }
    
    // Parse times
    $start_utc = tmi_parse_datetime($body['start_utc']);
    $end_utc = tmi_parse_datetime($body['end_utc']);
    
    if (!$start_utc || !$end_utc) {
        TmiResponse::error('Invalid start_utc or end_utc format', 400);
    }
    
    // Build insert data
    $data = [
        'ctl_element' => strtoupper($body['ctl_element']),
        'element_type' => strtoupper($body['element_type']),
        'program_type' => strtoupper($body['program_type']),
        'program_name' => $body['program_name'] ?? null,
        'adv_number' => $body['adv_number'] ?? null,
        'start_utc' => $start_utc,
        'end_utc' => $end_utc,
        'status' => strtoupper($body['status'] ?? 'PROPOSED'),
        'is_proposed' => ($body['status'] ?? 'PROPOSED') === 'PROPOSED' ? 1 : 0,
        'is_active' => 0,
        'program_rate' => isset($body['program_rate']) ? (int)$body['program_rate'] : null,
        'reserve_rate' => isset($body['reserve_rate']) ? (int)$body['reserve_rate'] : null,
        'delay_limit_min' => isset($body['delay_limit_min']) ? (int)$body['delay_limit_min'] : 180,
        'target_delay_mult' => isset($body['target_delay_mult']) ? (float)$body['target_delay_mult'] : 1.0,
        'rates_hourly_json' => isset($body['rates_hourly']) ? json_encode($body['rates_hourly']) : null,
        'reserve_hourly_json' => isset($body['reserve_hourly']) ? json_encode($body['reserve_hourly']) : null,
        'scope_json' => isset($body['scope']) ? json_encode($body['scope']) : null,
        'exemptions_json' => isset($body['exemptions']) ? json_encode($body['exemptions']) : null,
        'arrival_fix_filter' => isset($body['arrival_fix_filter']) ? strtoupper($body['arrival_fix_filter']) : null,
        'aircraft_type_filter' => strtoupper($body['aircraft_type_filter'] ?? 'ALL'),
        'carrier_filter' => isset($body['carrier_filter']) ? json_encode($body['carrier_filter']) : null,
        'impacting_condition' => $body['impacting_condition'] ?? null,
        'cause_text' => $body['cause_text'] ?? null,
        'comments' => $body['comments'] ?? null,
        'subs_enabled' => isset($body['subs_enabled']) ? (int)$body['subs_enabled'] : 1,
        'adaptive_compression' => isset($body['adaptive_compression']) ? (int)$body['adaptive_compression'] : 0,
        'source_type' => strtoupper($body['source_type'] ?? 'API'),
        'source_id' => $body['source_id'] ?? null,
        'created_by' => $auth->getUserId()
    ];
    
    // Generate advisory number if not provided
    if (empty($data['adv_number'])) {
        $data['adv_number'] = tmi_next_advisory_number();
    }
    
    $id = tmi_insert('tmi_programs', $data);
    
    if ($id === false) {
        TmiResponse::error('Failed to create program: ' . tmi_sql_errors(), 500);
    }
    
    // Log event
    tmi_log_event('PROGRAM', $id, 'CREATE', [
        'detail' => "{$data['program_type']} for {$data['ctl_element']}",
        'source_type' => $data['source_type'],
        'actor_id' => $auth->getUserId(),
        'actor_name' => $auth->getUserName(),
        'program_id' => $id
    ]);
    
    // Fetch and return created program
    $program = tmi_query_one("SELECT * FROM dbo.tmi_programs WHERE program_id = ?", [$id]);
    
    TmiResponse::created($program);
}

/**
 * Update existing program
 */
function updateProgram($id) {
    global $conn_tmi;
    
    $auth = tmi_init(true);
    $body = tmi_get_json_body();
    
    if (!$body) {
        TmiResponse::error('Request body required', 400);
    }
    
    // Check program exists (scoped to org)
    $existing = tmi_query_one(
        "SELECT * FROM dbo.tmi_programs WHERE program_id = ? AND org_code = ?",
        [$id, tmi_get_org_code()]
    );
    if (!$existing) {
        TmiResponse::error('Program not found', 404);
    }

    $data = ['updated_at' => gmdate('Y-m-d H:i:s')];
    
    // Handle status changes specially
    if (isset($body['status'])) {
        $new_status = strtoupper($body['status']);
        $data['status'] = $new_status;
        
        if ($new_status === 'ACTIVE') {
            $data['is_active'] = 1;
            $data['is_proposed'] = 0;
            $data['activated_by'] = $auth->getUserId();
            $data['activated_at'] = gmdate('Y-m-d H:i:s');
        } elseif ($new_status === 'PURGED') {
            $data['is_active'] = 0;
            $data['purged_by'] = $auth->getUserId();
            $data['purged_at'] = gmdate('Y-m-d H:i:s');
        } elseif ($new_status === 'COMPLETED') {
            $data['is_active'] = 0;
        }
    }
    
    // Update other fields
    $allowed_fields = [
        'program_name', 'start_utc', 'end_utc', 'program_rate', 'reserve_rate',
        'delay_limit_min', 'target_delay_mult', 'arrival_fix_filter', 'aircraft_type_filter',
        'impacting_condition', 'cause_text', 'comments', 'subs_enabled', 'adaptive_compression'
    ];
    
    foreach ($allowed_fields as $field) {
        if (isset($body[$field])) {
            if (in_array($field, ['start_utc', 'end_utc'])) {
                $data[$field] = tmi_parse_datetime($body[$field]);
            } elseif (in_array($field, ['arrival_fix_filter', 'aircraft_type_filter'])) {
                $data[$field] = strtoupper($body[$field]);
            } elseif (in_array($field, ['program_rate', 'reserve_rate', 'delay_limit_min', 'subs_enabled', 'adaptive_compression'])) {
                $data[$field] = (int)$body[$field];
            } elseif ($field === 'target_delay_mult') {
                $data[$field] = (float)$body[$field];
            } else {
                $data[$field] = $body[$field];
            }
        }
    }
    
    // Handle JSON fields
    if (isset($body['rates_hourly'])) {
        $data['rates_hourly_json'] = json_encode($body['rates_hourly']);
    }
    if (isset($body['scope'])) {
        $data['scope_json'] = json_encode($body['scope']);
    }
    if (isset($body['exemptions'])) {
        $data['exemptions_json'] = json_encode($body['exemptions']);
    }
    
    // Update metrics if provided
    $metric_fields = ['total_flights', 'controlled_flights', 'exempt_flights', 'avg_delay_min', 'max_delay_min', 'total_delay_min'];
    foreach ($metric_fields as $field) {
        if (isset($body[$field])) {
            $data[$field] = $body[$field];
        }
    }
    
    $rows = tmi_update('tmi_programs', $data, 'program_id = ?', [$id]);
    
    if ($rows === false) {
        TmiResponse::error('Failed to update program: ' . tmi_sql_errors(), 500);
    }
    
    // Determine event type
    $event_type = 'UPDATE';
    $event_detail = 'Program updated';
    if (isset($body['status'])) {
        $event_type = 'STATUS_CHANGE';
        $event_detail = "Status: {$existing['status']} → {$body['status']}";
        
        if ($body['status'] === 'ACTIVE') {
            $event_type = 'PROGRAM_ACTIVATED';
        } elseif ($body['status'] === 'PURGED') {
            $event_type = 'PROGRAM_PURGED';
        }
    }
    
    tmi_log_event('PROGRAM', $id, $event_type, [
        'detail' => $event_detail,
        'source_type' => 'API',
        'actor_id' => $auth->getUserId(),
        'actor_name' => $auth->getUserName(),
        'program_id' => $id
    ]);
    
    $program = tmi_query_one("SELECT * FROM dbo.tmi_programs WHERE program_id = ?", [$id]);
    
    TmiResponse::success($program);
}

/**
 * Delete/purge program
 */
function deleteProgram($id) {
    global $conn_tmi;
    
    $auth = tmi_init(true);
    
    $existing = tmi_query_one(
        "SELECT * FROM dbo.tmi_programs WHERE program_id = ? AND org_code = ?",
        [$id, tmi_get_org_code()]
    );
    if (!$existing) {
        TmiResponse::error('Program not found', 404);
    }

    // Soft delete - mark as purged
    $data = [
        'status' => 'PURGED',
        'is_active' => 0,
        'purged_by' => $auth->getUserId(),
        'purged_at' => gmdate('Y-m-d H:i:s'),
        'updated_at' => gmdate('Y-m-d H:i:s')
    ];
    
    $rows = tmi_update('tmi_programs', $data, 'program_id = ?', [$id]);
    
    if ($rows === false) {
        TmiResponse::error('Failed to purge program', 500);
    }
    
    tmi_log_event('PROGRAM', $id, 'PROGRAM_PURGED', [
        'detail' => 'Program purged via API',
        'source_type' => 'API',
        'actor_id' => $auth->getUserId(),
        'actor_name' => $auth->getUserName(),
        'program_id' => $id
    ]);
    
    TmiResponse::success(['message' => 'Program purged', 'program_id' => $id]);
}
