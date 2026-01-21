<?php
/**
 * GDT Programs - Get API
 * 
 * GET /api/gdt/programs/get.php?program_id=1
 * 
 * Retrieves a single program by ID with related slots and flight counts.
 * 
 * Query parameters:
 *   program_id     - Required: Program ID
 *   include_slots  - If "1", include slot allocation (default: 0)
 *   include_counts - If "1", include flight counts by status (default: 1)
 * 
 * Response:
 * {
 *   "status": "ok",
 *   "data": {
 *     "program": { ... },
 *     "slots": [ ... ],    // if include_slots=1
 *     "counts": { ... }    // if include_counts=1
 *   }
 * }
 * 
 * @version 1.0.0
 * @date 2026-01-21
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('GDT_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond_json(405, [
        'status' => 'error',
        'message' => 'Method not allowed. Use GET.'
    ]);
}

$conn_tmi = get_conn_tmi();

// ============================================================================
// Parse Parameters
// ============================================================================

$program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$include_slots = isset($_GET['include_slots']) && $_GET['include_slots'] === '1';
$include_counts = !isset($_GET['include_counts']) || $_GET['include_counts'] !== '0';

if ($program_id <= 0) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'program_id is required and must be a positive integer.'
    ]);
}

// ============================================================================
// Fetch Program
// ============================================================================

$program = get_program($conn_tmi, $program_id);

if ($program === null) {
    respond_json(404, [
        'status' => 'error',
        'message' => "Program not found: {$program_id}"
    ]);
}

$response_data = [
    'program' => $program
];

// ============================================================================
// Include Slots (optional)
// ============================================================================

if ($include_slots) {
    $slots_result = fetch_all($conn_tmi, "
        SELECT 
            slot_id,
            slot_name,
            slot_index,
            slot_time_utc,
            slot_type,
            slot_status,
            assigned_flight_uid,
            assigned_callsign,
            assigned_origin,
            assigned_dest,
            original_eta_utc,
            slot_delay_min,
            bridge_reason,
            is_popup_slot,
            bin_date,
            bin_hour,
            bin_quarter,
            created_at,
            assigned_at,
            updated_at
        FROM dbo.tmi_slots
        WHERE program_id = ?
        ORDER BY slot_index ASC
    ", [$program_id]);
    
    $response_data['slots'] = $slots_result['success'] ? $slots_result['data'] : [];
}

// ============================================================================
// Include Counts (optional)
// ============================================================================

if ($include_counts) {
    // Slot counts
    $slot_counts_result = fetch_one($conn_tmi, "
        SELECT 
            COUNT(*) AS total_slots,
            SUM(CASE WHEN slot_status = 'OPEN' THEN 1 ELSE 0 END) AS open_slots,
            SUM(CASE WHEN slot_status = 'ASSIGNED' THEN 1 ELSE 0 END) AS assigned_slots,
            SUM(CASE WHEN slot_status = 'BRIDGED' THEN 1 ELSE 0 END) AS bridged_slots,
            SUM(CASE WHEN slot_type = 'RESERVED' THEN 1 ELSE 0 END) AS reserved_slots,
            SUM(CASE WHEN slot_type = 'RESERVED' AND slot_status = 'OPEN' THEN 1 ELSE 0 END) AS reserved_open
        FROM dbo.tmi_slots
        WHERE program_id = ?
    ", [$program_id]);
    
    // Flight control counts
    $flight_counts_result = fetch_one($conn_tmi, "
        SELECT 
            COUNT(*) AS total_flights,
            SUM(CASE WHEN is_exempt = 1 THEN 1 ELSE 0 END) AS exempt_flights,
            SUM(CASE WHEN is_gs_hold = 1 THEN 1 ELSE 0 END) AS gs_held_flights,
            SUM(CASE WHEN is_popup = 1 THEN 1 ELSE 0 END) AS popup_flights,
            SUM(CASE WHEN is_ecr = 1 THEN 1 ELSE 0 END) AS ecr_flights,
            AVG(CAST(program_delay_min AS FLOAT)) AS avg_delay_min,
            MAX(program_delay_min) AS max_delay_min,
            SUM(CAST(program_delay_min AS BIGINT)) AS total_delay_min
        FROM dbo.tmi_flight_control
        WHERE program_id = ?
    ", [$program_id]);
    
    $response_data['counts'] = [
        'slots' => $slot_counts_result['success'] ? $slot_counts_result['data'] : null,
        'flights' => $flight_counts_result['success'] ? $flight_counts_result['data'] : null
    ];
}

// ============================================================================
// Response
// ============================================================================

respond_json(200, [
    'status' => 'ok',
    'data' => $response_data
]);
