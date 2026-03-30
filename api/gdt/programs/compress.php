<?php
/**
 * GDT Programs - Compress API
 *
 * POST /api/gdt/programs/compress.php
 *
 * Runs slot compression on an active GDP/AFP program. Compression reclaims
 * slots where the assigned flight has already departed or is a no-show, and
 * moves later delayed flights into those earlier slots to reduce total delay.
 *
 * Request body (JSON):
 * {
 *   "program_id": 1    // Required: active program to compress
 * }
 *
 * Response:
 * {
 *   "status": "ok",
 *   "message": "Compression complete",
 *   "data": {
 *     "program_id": 1,
 *     "slots_compressed": 3,
 *     "delay_saved_min": 45,
 *     "updated_metrics": { ... }
 *   }
 * }
 *
 * @version 1.0.0
 * @date 2026-03-05
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
    respond_json(405, [
        'status' => 'error',
        'message' => 'Method not allowed. Use POST.'
    ]);
}

$payload = read_request_payload();
$conn_tmi = gdt_get_conn_tmi();

// ============================================================================
// Validate Program
// ============================================================================

$program_id = isset($payload['program_id']) ? (int)$payload['program_id'] : 0;

if ($program_id <= 0) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'program_id is required.'
    ]);
}

$program = get_program($conn_tmi, $program_id);

if ($program === null) {
    respond_json(404, [
        'status' => 'error',
        'message' => "Program not found: {$program_id}"
    ]);
}

$status = $program['status'] ?? '';
if ($status !== 'ACTIVE') {
    respond_json(400, [
        'status' => 'error',
        'message' => "Compression only available for ACTIVE programs. Current status: {$status}"
    ]);
}

$program_type = $program['program_type'] ?? '';
if ($program_type === 'GS') {
    respond_json(400, [
        'status' => 'error',
        'message' => 'Compression is not applicable to Ground Stop programs.'
    ]);
}

// ============================================================================
// Run Compression SP
// ============================================================================

$exec_sql = "
    DECLARE @slots_compressed INT, @delay_saved_min INT;
    EXEC dbo.sp_TMI_RunCompression
        @program_id = ?,
        @compression_by = ?,
        @slots_compressed = @slots_compressed OUTPUT,
        @delay_saved_min = @delay_saved_min OUTPUT;
    SELECT @slots_compressed AS slots_compressed, @delay_saved_min AS delay_saved_min;
";

$exec_stmt = sqlsrv_query($conn_tmi, $exec_sql, [$program_id, $auth_cid]);

if ($exec_stmt === false) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to execute compression',
        'errors' => filter_sqlsrv_errors()
    ]);
}

$result_row = sqlsrv_fetch_array($exec_stmt, SQLSRV_FETCH_ASSOC);
$slots_compressed = 0;
$delay_saved_min = 0;

if ($result_row) {
    $slots_compressed = (int)($result_row['slots_compressed'] ?? 0);
    $delay_saved_min = (int)($result_row['delay_saved_min'] ?? 0);
}
sqlsrv_free_stmt($exec_stmt);

// Refresh program metrics
$program = get_program($conn_tmi, $program_id);

// Log to TMI unified log
log_tmi_action($conn_tmi, [
    'action_category' => 'PROGRAM',
    'action_type'     => 'COMPRESS',
    'program_type'    => $program['program_type'] ?? null,
    'summary'         => 'GDP compressed: ' . ($program['ctl_element'] ?? ''),
    'user_cid'        => $auth_cid,
    'issuing_org'     => $program['org_code'] ?? null,
], [
    'ctl_element' => $program['ctl_element'] ?? null,
    'element_type' => 'AIRPORT',
], null, [
    'slots_compressed' => $slots_compressed,
    'delay_saved_min'  => $delay_saved_min,
], [
    'program_id' => $program_id,
]);

respond_json(200, [
    'status' => 'ok',
    'message' => $slots_compressed > 0
        ? "Compression complete: {$slots_compressed} slots compressed, {$delay_saved_min} minutes saved"
        : 'No compression opportunities found',
    'data' => [
        'program_id' => $program_id,
        'slots_compressed' => $slots_compressed,
        'delay_saved_min' => $delay_saved_min,
        'updated_metrics' => [
            'avg_delay_min' => $program['avg_delay_min'] ?? null,
            'max_delay_min' => $program['max_delay_min'] ?? null,
            'total_delay_min' => $program['total_delay_min'] ?? null,
            'last_compression_utc' => datetime_to_iso($program['last_compression_utc'] ?? null)
        ]
    ]
]);
