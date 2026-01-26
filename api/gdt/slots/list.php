<?php
/**
 * GDT Slots - List API
 * 
 * GET /api/gdt/slots/list.php?program_id=1
 * 
 * Lists slots for a program with their allocation status.
 * Uses vw_tmi_slot_allocation view.
 * 
 * Query parameters:
 *   program_id  - Required: Program ID
 *   status      - Filter by status (OPEN, ASSIGNED, BRIDGED)
 *   type        - Filter by type (REGULAR, RESERVED)
 *   bin_hour    - Filter by hour (0-23)
 *   limit       - Max records (default: 500)
 *   offset      - Pagination offset
 * 
 * Response:
 * {
 *   "status": "ok",
 *   "data": {
 *     "slots": [ ... ],
 *     "total": 90,
 *     "summary": {
 *       "total": 90,
 *       "open": 45,
 *       "assigned": 40,
 *       "bridged": 5,
 *       "reserved_open": 10,
 *       "utilization_pct": 50.0
 *     }
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

$conn_tmi = gdt_get_conn_tmi();

// ============================================================================
// Parse Parameters
// ============================================================================

$program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$status = isset($_GET['status']) ? strtoupper(trim($_GET['status'])) : null;
$type = isset($_GET['type']) ? strtoupper(trim($_GET['type'])) : null;
$bin_hour = isset($_GET['bin_hour']) ? (int)$_GET['bin_hour'] : null;
$limit = isset($_GET['limit']) ? max(1, min(1000, (int)$_GET['limit'])) : 500;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

if ($program_id <= 0) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'program_id is required.'
    ]);
}

// ============================================================================
// Build Query
// ============================================================================

$where = ["program_id = ?"];
$params = [$program_id];

if ($status !== null && $status !== '') {
    $where[] = "slot_status = ?";
    $params[] = $status;
}

if ($type !== null && $type !== '') {
    $where[] = "slot_type = ?";
    $params[] = $type;
}

if ($bin_hour !== null && $bin_hour >= 0 && $bin_hour <= 23) {
    $where[] = "bin_hour = ?";
    $params[] = $bin_hour;
}

$where_sql = implode(" AND ", $where);

// ============================================================================
// Get Summary
// ============================================================================

$summary_sql = "
    SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN slot_status = 'OPEN' THEN 1 ELSE 0 END) AS open_slots,
        SUM(CASE WHEN slot_status = 'ASSIGNED' THEN 1 ELSE 0 END) AS assigned_slots,
        SUM(CASE WHEN slot_status = 'BRIDGED' THEN 1 ELSE 0 END) AS bridged_slots,
        SUM(CASE WHEN slot_type = 'RESERVED' THEN 1 ELSE 0 END) AS reserved_total,
        SUM(CASE WHEN slot_type = 'RESERVED' AND slot_status = 'OPEN' THEN 1 ELSE 0 END) AS reserved_open,
        SUM(CASE WHEN is_popup_slot = 1 THEN 1 ELSE 0 END) AS popup_slots
    FROM dbo.tmi_slots
    WHERE program_id = ?
";

$summary_result = fetch_one($conn_tmi, $summary_sql, [$program_id]);
$summary = $summary_result['success'] ? $summary_result['data'] : null;

// Calculate utilization
if ($summary && (int)$summary['total'] > 0) {
    $summary['utilization_pct'] = round(((int)$summary['assigned_slots'] / (int)$summary['total']) * 100, 1);
} elseif ($summary) {
    $summary['utilization_pct'] = 0;
}

// ============================================================================
// Fetch Slots
// ============================================================================

$params_paged = $params;
$params_paged[] = $offset;
$params_paged[] = $limit;

$slots_sql = "
    SELECT 
        slot_id,
        program_id,
        slot_name,
        slot_index,
        slot_time_utc,
        slot_type,
        slot_status,
        assigned_flight_uid,
        assigned_callsign,
        assigned_carrier,
        assigned_origin,
        assigned_dest,
        assigned_at,
        original_eta_utc,
        slot_delay_min,
        ctd_utc,
        cta_utc,
        bridge_reason,
        bridged_from_slot_id,
        is_popup_slot,
        popup_lead_time_min,
        bin_date,
        bin_hour,
        bin_quarter,
        created_at,
        updated_at
    FROM dbo.tmi_slots
    WHERE {$where_sql}
    ORDER BY slot_index ASC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";

$slots_result = fetch_all($conn_tmi, $slots_sql, $params_paged);

// ============================================================================
// Response
// ============================================================================

respond_json(200, [
    'status' => 'ok',
    'data' => [
        'slots' => $slots_result['success'] ? $slots_result['data'] : [],
        'total' => $summary ? (int)$summary['total'] : 0,
        'limit' => $limit,
        'offset' => $offset,
        'summary' => $summary
    ]
]);
