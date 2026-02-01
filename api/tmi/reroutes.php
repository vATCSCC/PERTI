<?php
/**
 * TMI Reroutes API
 * 
 * CRUD operations for reroute definitions and flight assignments.
 * 
 * Endpoints:
 *   GET    /api/tmi/reroutes.php                - List reroutes (with filters)
 *   GET    /api/tmi/reroutes.php?id=123         - Get single reroute
 *   GET    /api/tmi/reroutes.php?id=123&flights=1 - Get reroute with assigned flights
 *   POST   /api/tmi/reroutes.php                - Create new reroute
 *   PUT    /api/tmi/reroutes.php?id=123         - Update reroute
 *   DELETE /api/tmi/reroutes.php?id=123         - Cancel reroute
 * 
 * Query Parameters (GET list):
 *   status       - Filter by status (0-5: draft/proposed/active/monitoring/expired/cancelled)
 *   active_only  - Set to 1 to show only active reroutes (status=2)
 *   name         - Search by name (partial match)
 *   adv_number   - Filter by advisory number
 *   origin       - Filter by origin (airports/tracons/centers)
 *   dest         - Filter by destination
 *   include_expired - Set to 1 to include expired reroutes
 *   page         - Page number (default: 1)
 *   per_page     - Items per page (default: 50, max: 200)
 * 
 * Sub-resources:
 *   GET /api/tmi/reroutes.php?id=123&flights=1     - Include flight assignments
 *   GET /api/tmi/reroutes.php?id=123&compliance=1  - Include compliance stats
 * 
 * @package PERTI
 * @subpackage TMI
 */

require_once __DIR__ . '/helpers.php';

// Status constants (matches database tinyint)
define('REROUTE_STATUS_DRAFT', 0);
define('REROUTE_STATUS_PROPOSED', 1);
define('REROUTE_STATUS_ACTIVE', 2);
define('REROUTE_STATUS_MONITORING', 3);
define('REROUTE_STATUS_EXPIRED', 4);
define('REROUTE_STATUS_CANCELLED', 5);

$method = tmi_method();
$id = tmi_param('id');

// Route based on method
switch ($method) {
    case 'GET':
        if ($id) {
            getReroute($id);
        } else {
            listReroutes();
        }
        break;
    
    case 'POST':
        createReroute();
        break;
    
    case 'PUT':
    case 'PATCH':
        if (!$id) TmiResponse::error('Reroute ID required', 400);
        updateReroute($id);
        break;
    
    case 'DELETE':
        if (!$id) TmiResponse::error('Reroute ID required', 400);
        deleteReroute($id);
        break;
    
    default:
        TmiResponse::error('Method not allowed', 405);
}

/**
 * List reroutes with filters
 */
function listReroutes() {
    global $conn_tmi;
    
    // Public read access - no auth required for list
    tmi_init(false);
    
    // Build query
    $where = [];
    $params = [];
    
    // Status filter (numeric)
    $status = tmi_param('status');
    if ($status !== null && $status !== '') {
        $where[] = "status = ?";
        $params[] = (int)$status;
    }
    
    // Active only shortcut
    if (tmi_param('active_only') === '1') {
        $where[] = "status = ?";
        $params[] = REROUTE_STATUS_ACTIVE;
        $where[] = "(end_utc IS NULL OR end_utc > SYSUTCDATETIME())";
    }
    
    // Name search (partial match)
    $name = tmi_param('name');
    if ($name) {
        $where[] = "name LIKE ?";
        $params[] = '%' . $name . '%';
    }
    
    // Advisory number filter
    $adv_number = tmi_param('adv_number');
    if ($adv_number) {
        $where[] = "adv_number = ?";
        $params[] = strtoupper($adv_number);
    }
    
    // Origin filter (check multiple JSON fields)
    $origin = tmi_param('origin');
    if ($origin) {
        $origin_upper = strtoupper($origin);
        $where[] = "(
            origin_airports LIKE ? OR 
            origin_tracons LIKE ? OR 
            origin_centers LIKE ?
        )";
        $params[] = '%' . $origin_upper . '%';
        $params[] = '%' . $origin_upper . '%';
        $params[] = '%' . $origin_upper . '%';
    }
    
    // Destination filter
    $dest = tmi_param('dest');
    if ($dest) {
        $dest_upper = strtoupper($dest);
        $where[] = "(
            dest_airports LIKE ? OR 
            dest_tracons LIKE ? OR 
            dest_centers LIKE ?
        )";
        $params[] = '%' . $dest_upper . '%';
        $params[] = '%' . $dest_upper . '%';
        $params[] = '%' . $dest_upper . '%';
    }
    
    // Exclude expired unless requested
    if (tmi_param('include_expired') !== '1') {
        $where[] = "status NOT IN (?, ?)";
        $params[] = REROUTE_STATUS_EXPIRED;
        $params[] = REROUTE_STATUS_CANCELLED;
    }
    
    // Date range filters
    $start_after = tmi_param('start_after');
    if ($start_after) {
        $where[] = "start_utc >= ?";
        $params[] = tmi_parse_datetime($start_after);
    }
    
    $start_before = tmi_param('start_before');
    if ($start_before) {
        $where[] = "start_utc <= ?";
        $params[] = tmi_parse_datetime($start_before);
    }
    
    // Pagination
    $page = tmi_int_param('page', 1, 1);
    $per_page = tmi_int_param('per_page', 50, 1, 200);
    $offset = ($page - 1) * $per_page;
    
    // Build WHERE clause
    $where_sql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Get total count
    $count_sql = "SELECT COUNT(*) as cnt FROM dbo.tmi_reroutes $where_sql";
    $count_result = sqlsrv_query($conn_tmi, $count_sql, $params);
    $total = 0;
    if ($count_result && ($row = sqlsrv_fetch_array($count_result, SQLSRV_FETCH_ASSOC))) {
        $total = (int)$row['cnt'];
    }
    
    // Get reroutes
    $sql = "SELECT 
                reroute_id, reroute_guid, status, name, adv_number,
                start_utc, end_utc, time_basis,
                protected_segment, protected_fixes, avoid_fixes, route_type,
                origin_airports, origin_tracons, origin_centers,
                dest_airports, dest_tracons, dest_centers,
                departure_fix, arrival_fix, thru_centers, thru_fixes,
                include_ac_cat, include_ac_types, include_carriers, weight_class,
                altitude_min, altitude_max, rvsm_filter,
                exempt_airports, exempt_carriers, exempt_flights,
                airborne_filter,
                comments, impacting_condition, advisory_text,
                color, line_weight, line_style,
                total_assigned, compliant_count, non_compliant_count, compliance_rate,
                source_type, source_id, discord_message_id, discord_channel_id,
                created_by, created_at, updated_at, activated_at
            FROM dbo.tmi_reroutes
            $where_sql
            ORDER BY 
                CASE WHEN status = 2 THEN 0 ELSE 1 END,  -- Active first
                start_utc DESC
            OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";
    
    $params[] = $offset;
    $params[] = $per_page;
    
    $result = sqlsrv_query($conn_tmi, $sql, $params);
    $reroutes = tmi_fetch_all($result);
    
    // Decode JSON fields
    $reroutes = array_map('decodeRerouteJsonFields', $reroutes);
    
    TmiResponse::paginated($reroutes, $total, $page, $per_page);
}

/**
 * Get single reroute by ID
 */
function getReroute($id) {
    global $conn_tmi;
    
    tmi_init(false);
    
    $sql = "SELECT * FROM dbo.tmi_reroutes WHERE reroute_id = ?";
    $reroute = tmi_query_one($sql, [$id]);
    
    if (!$reroute) {
        TmiResponse::error('Reroute not found', 404);
    }
    
    // Decode JSON fields
    $reroute = decodeRerouteJsonFields($reroute);
    
    // Include flight assignments if requested
    if (tmi_param('flights') === '1') {
        $flights_sql = "SELECT 
                id, reroute_id, flight_key, callsign, flight_uid,
                dep_icao, dest_icao, ac_type, filed_altitude,
                route_at_assign, assigned_route, current_route, final_route,
                last_lat, last_lon, last_altitude, last_position_utc,
                compliance_status, protected_fixes_crossed, avoid_fixes_crossed,
                compliance_pct, compliance_notes,
                assigned_at, departed_utc, arrived_utc,
                route_distance_orig_nm, route_distance_new_nm, route_delta_nm,
                ete_original_min, ete_assigned_min, ete_delta_min,
                manual_status, override_by, override_utc, override_reason
            FROM dbo.tmi_reroute_flights
            WHERE reroute_id = ?
            ORDER BY assigned_at DESC";
        
        $flights = tmi_query($flights_sql, [$id]);
        $reroute['flights'] = $flights ?: [];
        $reroute['flight_count'] = count($reroute['flights']);
    }
    
    // Include compliance summary if requested
    if (tmi_param('compliance') === '1') {
        $stats_sql = "SELECT 
                compliance_status,
                COUNT(*) as count
            FROM dbo.tmi_reroute_flights
            WHERE reroute_id = ?
            GROUP BY compliance_status";
        
        $stats = tmi_query($stats_sql, [$id]);
        $reroute['compliance_summary'] = $stats ?: [];
    }
    
    TmiResponse::success($reroute);
}

/**
 * Create new reroute
 */
function createReroute() {
    global $conn_tmi;
    
    $auth = tmi_init(true);
    $body = tmi_get_json_body();
    
    if (!$body) {
        TmiResponse::error('Request body required', 400);
    }
    
    // Validate required fields
    $required = ['name'];
    foreach ($required as $field) {
        if (empty($body[$field])) {
            TmiResponse::error("Missing required field: $field", 400);
        }
    }
    
    // Validate status if provided
    if (isset($body['status'])) {
        $valid_statuses = [0, 1, 2, 3, 4, 5];
        if (!in_array((int)$body['status'], $valid_statuses)) {
            TmiResponse::error('Invalid status. Must be 0-5 (draft/proposed/active/monitoring/expired/cancelled)', 400);
        }
    }
    
    // Build insert data
    $data = [
        'name' => $body['name'],
        'status' => isset($body['status']) ? (int)$body['status'] : REROUTE_STATUS_DRAFT,
        'adv_number' => isset($body['adv_number']) ? strtoupper($body['adv_number']) : null,
        'start_utc' => tmi_parse_datetime($body['start_utc'] ?? null),
        'end_utc' => tmi_parse_datetime($body['end_utc'] ?? null),
        'time_basis' => strtoupper($body['time_basis'] ?? 'ETD'),
        
        // Protected route definition
        'protected_segment' => $body['protected_segment'] ?? null,
        'protected_fixes' => encodeJsonField($body['protected_fixes'] ?? null),
        'avoid_fixes' => encodeJsonField($body['avoid_fixes'] ?? null),
        'route_type' => strtoupper($body['route_type'] ?? 'FULL'),
        
        // Geographic scope
        'origin_airports' => encodeJsonField($body['origin_airports'] ?? null),
        'origin_tracons' => encodeJsonField($body['origin_tracons'] ?? null),
        'origin_centers' => encodeJsonField($body['origin_centers'] ?? null),
        'dest_airports' => encodeJsonField($body['dest_airports'] ?? null),
        'dest_tracons' => encodeJsonField($body['dest_tracons'] ?? null),
        'dest_centers' => encodeJsonField($body['dest_centers'] ?? null),
        
        // Route-based filtering
        'departure_fix' => isset($body['departure_fix']) ? strtoupper($body['departure_fix']) : null,
        'arrival_fix' => isset($body['arrival_fix']) ? strtoupper($body['arrival_fix']) : null,
        'thru_centers' => encodeJsonField($body['thru_centers'] ?? null),
        'thru_fixes' => encodeJsonField($body['thru_fixes'] ?? null),
        'use_airway' => encodeJsonField($body['use_airway'] ?? null),
        
        // Aircraft filtering
        'include_ac_cat' => strtoupper($body['include_ac_cat'] ?? 'ALL'),
        'include_ac_types' => encodeJsonField($body['include_ac_types'] ?? null),
        'include_carriers' => encodeJsonField($body['include_carriers'] ?? null),
        'weight_class' => strtoupper($body['weight_class'] ?? 'ALL'),
        'altitude_min' => isset($body['altitude_min']) ? (int)$body['altitude_min'] : null,
        'altitude_max' => isset($body['altitude_max']) ? (int)$body['altitude_max'] : null,
        'rvsm_filter' => strtoupper($body['rvsm_filter'] ?? 'ALL'),
        
        // Exemptions
        'exempt_airports' => encodeJsonField($body['exempt_airports'] ?? null),
        'exempt_carriers' => encodeJsonField($body['exempt_carriers'] ?? null),
        'exempt_flights' => encodeJsonField($body['exempt_flights'] ?? null),
        'exempt_active_only' => isset($body['exempt_active_only']) ? (int)$body['exempt_active_only'] : 0,
        
        // Airborne filter
        'airborne_filter' => strtoupper($body['airborne_filter'] ?? 'NOT_AIRBORNE'),
        
        // Metadata
        'comments' => $body['comments'] ?? null,
        'impacting_condition' => $body['impacting_condition'] ?? null,
        'advisory_text' => $body['advisory_text'] ?? null,
        
        // Display settings
        'color' => $body['color'] ?? '#e74c3c',
        'line_weight' => isset($body['line_weight']) ? (int)$body['line_weight'] : 3,
        'line_style' => $body['line_style'] ?? 'solid',
        'route_geojson' => $body['route_geojson'] ?? null,
        
        // Source tracking
        'source_type' => strtoupper($body['source_type'] ?? 'API'),
        'source_id' => $body['source_id'] ?? null,
        'discord_message_id' => $body['discord_message_id'] ?? null,
        'discord_channel_id' => $body['discord_channel_id'] ?? null,
        
        // Created by
        'created_by' => $auth->getUserId()
    ];
    
    // Set activated_at if status is active
    if ($data['status'] == REROUTE_STATUS_ACTIVE) {
        $data['activated_at'] = gmdate('Y-m-d H:i:s');
    }
    
    $id = tmi_insert('tmi_reroutes', $data);
    
    if ($id === false) {
        TmiResponse::error('Failed to create reroute: ' . tmi_sql_errors(), 500);
    }
    
    // Log event
    tmi_log_event('REROUTE', $id, 'CREATE', [
        'source_type' => $data['source_type'],
        'actor_id' => $auth->getUserId(),
        'actor_name' => $auth->getUserName()
    ]);
    
    // Fetch and return created reroute
    $reroute = tmi_query_one("SELECT * FROM dbo.tmi_reroutes WHERE reroute_id = ?", [$id]);
    $reroute = decodeRerouteJsonFields($reroute);
    
    TmiResponse::created($reroute);
}

/**
 * Update existing reroute
 */
function updateReroute($id) {
    global $conn_tmi;
    
    $auth = tmi_init(true);
    $body = tmi_get_json_body();
    
    if (!$body) {
        TmiResponse::error('Request body required', 400);
    }
    
    // Check reroute exists
    $existing = tmi_query_one("SELECT * FROM dbo.tmi_reroutes WHERE reroute_id = ?", [$id]);
    if (!$existing) {
        TmiResponse::error('Reroute not found', 404);
    }
    
    // Build update data (only update provided fields)
    $data = ['updated_at' => gmdate('Y-m-d H:i:s')];
    
    // Simple fields that can be directly updated
    $simple_fields = ['name', 'comments', 'impacting_condition', 'advisory_text', 
                      'color', 'route_geojson', 'source_id', 'discord_message_id', 'discord_channel_id'];
    foreach ($simple_fields as $field) {
        if (isset($body[$field])) {
            $data[$field] = $body[$field];
        }
    }
    
    // Uppercase fields
    $upper_fields = ['adv_number', 'time_basis', 'route_type', 'departure_fix', 'arrival_fix',
                     'include_ac_cat', 'weight_class', 'rvsm_filter', 'airborne_filter', 'source_type'];
    foreach ($upper_fields as $field) {
        if (isset($body[$field])) {
            $data[$field] = strtoupper($body[$field]);
        }
    }
    
    // Integer fields
    $int_fields = ['line_weight', 'altitude_min', 'altitude_max'];
    foreach ($int_fields as $field) {
        if (isset($body[$field])) {
            $data[$field] = (int)$body[$field];
        }
    }
    
    // Boolean/bit fields
    if (isset($body['exempt_active_only'])) {
        $data['exempt_active_only'] = (int)$body['exempt_active_only'];
    }
    
    // DateTime fields
    $datetime_fields = ['start_utc', 'end_utc'];
    foreach ($datetime_fields as $field) {
        if (isset($body[$field])) {
            $data[$field] = tmi_parse_datetime($body[$field]);
        }
    }
    
    // JSON fields
    $json_fields = ['protected_fixes', 'avoid_fixes', 'origin_airports', 'origin_tracons', 'origin_centers',
                    'dest_airports', 'dest_tracons', 'dest_centers', 'thru_centers', 'thru_fixes',
                    'use_airway', 'include_ac_types', 'include_carriers', 'exempt_airports', 
                    'exempt_carriers', 'exempt_flights'];
    foreach ($json_fields as $field) {
        if (isset($body[$field])) {
            $data[$field] = encodeJsonField($body[$field]);
        }
    }
    
    // Protected segment (raw string)
    if (isset($body['protected_segment'])) {
        $data['protected_segment'] = $body['protected_segment'];
    }
    
    // Handle status change
    if (isset($body['status'])) {
        $new_status = (int)$body['status'];
        $old_status = (int)$existing['status'];
        
        if ($new_status !== $old_status) {
            $data['status'] = $new_status;
            
            // Set activated_at when going active
            if ($new_status == REROUTE_STATUS_ACTIVE && $old_status != REROUTE_STATUS_ACTIVE) {
                $data['activated_at'] = gmdate('Y-m-d H:i:s');
            }
        }
    }
    
    // Update metrics if provided
    $metric_fields = ['total_assigned', 'compliant_count', 'non_compliant_count', 'compliance_rate'];
    foreach ($metric_fields as $field) {
        if (isset($body[$field])) {
            $data[$field] = is_numeric($body[$field]) ? $body[$field] : null;
        }
    }
    
    $rows = tmi_update('tmi_reroutes', $data, 'reroute_id = ?', [$id]);
    
    if ($rows === false) {
        TmiResponse::error('Failed to update reroute: ' . tmi_sql_errors(), 500);
    }
    
    // Log event
    $detail = isset($body['status']) ? "Status changed to {$body['status']}" : 'Fields updated';
    tmi_log_event('REROUTE', $id, 'UPDATE', [
        'detail' => $detail,
        'source_type' => 'API',
        'actor_id' => $auth->getUserId(),
        'actor_name' => $auth->getUserName()
    ]);
    
    // Fetch and return updated reroute
    $reroute = tmi_query_one("SELECT * FROM dbo.tmi_reroutes WHERE reroute_id = ?", [$id]);
    $reroute = decodeRerouteJsonFields($reroute);
    
    TmiResponse::success($reroute);
}

/**
 * Delete/cancel reroute
 */
function deleteReroute($id) {
    global $conn_tmi;
    
    $auth = tmi_init(true);
    
    // Check reroute exists
    $existing = tmi_query_one("SELECT * FROM dbo.tmi_reroutes WHERE reroute_id = ?", [$id]);
    if (!$existing) {
        TmiResponse::error('Reroute not found', 404);
    }
    
    // Soft delete - mark as cancelled
    $data = [
        'status' => REROUTE_STATUS_CANCELLED,
        'updated_at' => gmdate('Y-m-d H:i:s')
    ];
    
    $rows = tmi_update('tmi_reroutes', $data, 'reroute_id = ?', [$id]);
    
    if ($rows === false) {
        TmiResponse::error('Failed to cancel reroute', 500);
    }
    
    // Log event
    tmi_log_event('REROUTE', $id, 'DELETE', [
        'detail' => 'Cancelled via API',
        'source_type' => 'API',
        'actor_id' => $auth->getUserId(),
        'actor_name' => $auth->getUserName()
    ]);
    
    TmiResponse::success(['message' => 'Reroute cancelled', 'reroute_id' => $id]);
}

/**
 * Helper: Encode array/object to JSON string for storage
 */
function encodeJsonField($value) {
    if ($value === null) return null;
    if (is_string($value)) {
        // Already a string - check if it's valid JSON, if not wrap in array
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $value; // Already valid JSON
        }
        // Single value - wrap in array
        return json_encode([$value]);
    }
    if (is_array($value)) {
        return json_encode($value);
    }
    return null;
}

/**
 * Helper: Decode JSON fields in a reroute record
 */
function decodeRerouteJsonFields($reroute) {
    if (!$reroute) return $reroute;
    
    $json_fields = [
        'protected_fixes', 'avoid_fixes',
        'origin_airports', 'origin_tracons', 'origin_centers',
        'dest_airports', 'dest_tracons', 'dest_centers',
        'thru_centers', 'thru_fixes', 'use_airway',
        'include_ac_types', 'include_carriers',
        'exempt_airports', 'exempt_carriers', 'exempt_flights'
    ];
    
    foreach ($json_fields as $field) {
        if (isset($reroute[$field]) && is_string($reroute[$field])) {
            $decoded = json_decode($reroute[$field], true);
            $reroute[$field] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : null;
        }
    }
    
    // Map numeric status to string for readability
    $status_map = [
        0 => 'DRAFT',
        1 => 'PROPOSED',
        2 => 'ACTIVE',
        3 => 'MONITORING',
        4 => 'EXPIRED',
        5 => 'CANCELLED'
    ];
    if (isset($reroute['status'])) {
        $reroute['status_code'] = (int)$reroute['status'];
        $reroute['status_name'] = $status_map[(int)$reroute['status']] ?? 'UNKNOWN';
    }
    
    return $reroute;
}
