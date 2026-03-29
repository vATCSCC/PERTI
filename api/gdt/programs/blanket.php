<?php
/**
 * GDT Programs - Blanket Adjustment API
 *
 * POST /api/gdt/programs/blanket.php
 *
 * Applies a uniform delay offset (positive or negative) to all assigned
 * flights in an active GDP/AFP program. Per FAA FSM Ch.12, blanket
 * adjustments shift all CTDs by the same amount without changing the
 * slot structure.
 *
 * Request body (JSON):
 * {
 *   "program_id": 1,
 *   "adjustment_min": 15,       // Minutes to add (+) or subtract (-) from all CTDs
 *   "reason": "Weather improvement"  // Optional reason text
 * }
 *
 * Response:
 * {
 *   "status": "ok",
 *   "data": {
 *     "program_id": 1,
 *     "adjustment_min": 15,
 *     "flights_adjusted": 42,
 *     "new_avg_delay_min": 25.3,
 *     "new_max_delay_min": 45
 *   }
 * }
 *
 * @version 1.0.0
 * @date 2026-03-29
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('GDT_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');
$auth_cid = gdt_require_auth();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond_json(405, ['status' => 'error', 'message' => 'Method not allowed. Use POST.']);
}

$payload = read_request_payload();
$conn_tmi = gdt_get_conn_tmi();

// ============================================================================
// Validate Input
// ============================================================================

$program_id = isset($payload['program_id']) ? (int)$payload['program_id'] : 0;
$adjustment_min = isset($payload['adjustment_min']) ? (int)$payload['adjustment_min'] : 0;
$reason = isset($payload['reason']) ? trim($payload['reason']) : '';

if ($program_id <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'program_id is required']);
}

if ($adjustment_min === 0) {
    respond_json(400, ['status' => 'error', 'message' => 'adjustment_min must be non-zero']);
}

if (abs($adjustment_min) > 300) {
    respond_json(400, ['status' => 'error', 'message' => 'adjustment_min too large (max +-300 minutes)']);
}

// Validate program exists and is ACTIVE GDP/AFP
$program = get_program($conn_tmi, $program_id);
if (!$program) {
    respond_json(404, ['status' => 'error', 'message' => 'Program not found']);
}

$status = $program['status'] ?? '';
if ($status !== 'ACTIVE') {
    respond_json(400, ['status' => 'error', 'message' => "Blanket adjustments only apply to ACTIVE programs (current: {$status})"]);
}

$program_type = $program['program_type'] ?? '';
if ($program_type === 'GS') {
    respond_json(400, ['status' => 'error', 'message' => 'Blanket adjustments are not applicable to Ground Stop programs']);
}

// ============================================================================
// Apply Blanket Adjustment
// ============================================================================

// Update all assigned slots: shift slot_time_utc and CTDs by adjustment
$update_sql = "
    UPDATE s
    SET s.ctd_utc = DATEADD(MINUTE, ?, s.ctd_utc),
        s.cta_utc = DATEADD(MINUTE, ?, s.cta_utc),
        s.delay_minutes = CASE
            WHEN s.delay_minutes IS NOT NULL THEN s.delay_minutes + ?
            ELSE NULL
        END,
        s.updated_at = SYSUTCDATETIME()
    FROM dbo.tmi_slots s
    WHERE s.program_id = ?
      AND s.assigned_flight_uid IS NOT NULL
      AND s.slot_status = 'ASSIGNED'
";

$stmt = sqlsrv_query($conn_tmi, $update_sql, [
    $adjustment_min,
    $adjustment_min,
    $adjustment_min,
    $program_id
]);

if ($stmt === false) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to apply blanket adjustment',
        'errors' => sqlsrv_errors()
    ]);
}

$flights_adjusted = sqlsrv_rows_affected($stmt);
sqlsrv_free_stmt($stmt);

// Also update tmi_flight_control CTDs for consistency
$update_fc_sql = "
    UPDATE fc
    SET fc.ctd_utc = DATEADD(MINUTE, ?, fc.ctd_utc),
        fc.delay_minutes = CASE
            WHEN fc.delay_minutes IS NOT NULL THEN fc.delay_minutes + ?
            ELSE NULL
        END
    FROM dbo.tmi_flight_control fc
    WHERE fc.program_id = ?
      AND fc.is_exempt = 0
      AND fc.ctd_utc IS NOT NULL
";

$stmt2 = sqlsrv_query($conn_tmi, $update_fc_sql, [
    $adjustment_min,
    $adjustment_min,
    $program_id
]);
if ($stmt2) sqlsrv_free_stmt($stmt2);

// Recalculate program delay metrics
$metrics_sql = "
    SELECT
        AVG(CAST(s.delay_minutes AS FLOAT)) AS avg_delay_min,
        MAX(s.delay_minutes) AS max_delay_min,
        SUM(ISNULL(s.delay_minutes, 0)) AS total_delay_min
    FROM dbo.tmi_slots s
    WHERE s.program_id = ?
      AND s.assigned_flight_uid IS NOT NULL
";

$metrics_row = fetch_one($conn_tmi, $metrics_sql, [$program_id]);
$new_avg = 0;
$new_max = 0;
$new_total = 0;
if ($metrics_row) {
    $new_avg = round((float)($metrics_row['avg_delay_min'] ?? 0), 1);
    $new_max = (int)($metrics_row['max_delay_min'] ?? 0);
    $new_total = (int)($metrics_row['total_delay_min'] ?? 0);
}

// Update program metrics
execute_query($conn_tmi,
    "UPDATE dbo.tmi_programs SET avg_delay_min = ?, max_delay_min = ?, total_delay_min = ?, updated_at = SYSUTCDATETIME() WHERE program_id = ?",
    [$new_avg, $new_max, $new_total, $program_id]
);

// Log the blanket adjustment event
$event_details = json_encode([
    'adjustment_min' => $adjustment_min,
    'flights_adjusted' => $flights_adjusted,
    'reason' => $reason,
    'new_avg_delay' => $new_avg,
    'new_max_delay' => $new_max,
]);

execute_query($conn_tmi,
    "INSERT INTO dbo.tmi_events (program_id, event_type, event_action, details_json, performed_by, performed_utc)
     VALUES (?, 'BLANKET', 'ADJUSTMENT', ?, ?, SYSUTCDATETIME())",
    [$program_id, $event_details, $auth_cid]
);

// ============================================================================
// Response
// ============================================================================

respond_json(200, [
    'status' => 'ok',
    'message' => "Blanket adjustment of {$adjustment_min} min applied to {$flights_adjusted} flights",
    'data' => [
        'program_id' => $program_id,
        'adjustment_min' => $adjustment_min,
        'flights_adjusted' => $flights_adjusted,
        'new_avg_delay_min' => $new_avg,
        'new_max_delay_min' => $new_max,
        'new_total_delay_min' => $new_total,
    ]
]);
