<?php
/**
 * GDT Programs - Revise API
 *
 * POST /api/gdt/programs/revise.php
 *
 * Revises an active program's parameters (rate, delay cap, end time, etc.)
 * and generates an updated advisory. Increments revision_number.
 *
 * Request body (JSON):
 * {
 *   "program_id": 1,                     // Required
 *   "program_rate": 36,                   // Optional: new rate
 *   "delay_limit_min": 300,               // Optional: new delay cap
 *   "end_utc": "2026-02-11T20:00Z",      // Optional: new end time
 *   "impacting_condition": "WEATHER",     // Optional
 *   "cause_text": "...",                  // Optional: updated comments
 *   "gs_probability": "HIGH",             // Optional
 *   "comments": "RATE REVISED FROM 30 TO 36" // Optional: revision note
 * }
 *
 * Response:
 * {
 *   "status": "ok",
 *   "message": "Program revised",
 *   "data": {
 *     "program_id": 1,
 *     "revision_number": 2,
 *     "changes": ["program_rate: 30 -> 36", "delay_limit_min: 240 -> 300"],
 *     "program": { ... updated record ... }
 *   }
 * }
 *
 * @version 1.0.0
 * @date 2026-02-11
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
// Validate
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

// Only allow revising active or modeling programs
$status = strtoupper($program['status'] ?? '');
if (!in_array($status, ['ACTIVE', 'MODELING', 'PROPOSED'])) {
    respond_json(400, [
        'status' => 'error',
        'message' => "Cannot revise program in status: {$status}. Must be ACTIVE, MODELING, or PROPOSED."
    ]);
}

// ============================================================================
// Build SET clauses for changed fields
// ============================================================================

$revisable_fields = [
    'program_rate'        => 'int',
    'delay_limit_min'     => 'int',
    'impacting_condition' => 'string',
    'cause_text'          => 'string',
    'gs_probability'      => 'string',
    'comments'            => 'string',
];

$sets = [];
$params = [];
$changes = [];

// Handle end_utc separately (needs datetime parsing)
if (isset($payload['end_utc']) && trim($payload['end_utc']) !== '') {
    $new_end = parse_utc_datetime($payload['end_utc']);
    if ($new_end !== null) {
        $old_end = $program['end_utc'];
        $old_end_str = ($old_end instanceof DateTimeInterface)
            ? $old_end->format('Y-m-d H:i')
            : (string)$old_end;
        $sets[] = "end_utc = ?";
        $params[] = $new_end;
        $changes[] = "end_utc: {$old_end_str} -> {$new_end}";
    }
}

foreach ($revisable_fields as $field => $type) {
    if (!isset($payload[$field])) continue;

    $new_val = $payload[$field];
    $old_val = $program[$field] ?? null;

    if ($type === 'int') {
        $new_val = (int)$new_val;
        $old_val = $old_val !== null ? (int)$old_val : null;
        if ($new_val === $old_val) continue;
    } else {
        $new_val = trim((string)$new_val);
        $old_val = $old_val !== null ? trim((string)$old_val) : '';
        if ($new_val === $old_val) continue;
    }

    $sets[] = "{$field} = ?";
    $params[] = $new_val;
    $changes[] = "{$field}: " . ($old_val ?? 'NULL') . " -> {$new_val}";
}

if (empty($sets) && empty($changes)) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'No changes detected. Provide at least one field to revise.'
    ]);
}

// Always increment revision_number and update timestamp
$current_rev = (int)($program['revision_number'] ?? 0);
$new_rev = $current_rev + 1;
$sets[] = "revision_number = ?";
$params[] = $new_rev;
$sets[] = "updated_at = SYSUTCDATETIME()";

// ============================================================================
// Execute UPDATE
// ============================================================================

$params[] = $program_id; // for WHERE clause
$sql = "UPDATE dbo.tmi_programs SET " . implode(', ', $sets) . " WHERE program_id = ?";

$result = execute_query($conn_tmi, $sql, $params);

if (!$result['success']) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to update program',
        'errors' => $result['error']
    ]);
}

// ============================================================================
// Log the revision event
// ============================================================================

$event_sql = "
    INSERT INTO dbo.tmi_events
        (entity_type, entity_id, program_id, event_type, event_detail, event_data_json, source_type, actor_id, event_utc)
    VALUES
        ('PROGRAM', ?, ?, 'REVISION', ?, ?, 'GDT', ?, SYSUTCDATETIME())
";

$event_detail = "Revision #{$new_rev}: " . implode('; ', $changes);
$event_data = json_encode([
    'revision_number' => $new_rev,
    'changes' => $changes,
    'previous_values' => array_intersect_key(
        $program,
        array_flip(array_merge(array_keys($revisable_fields), ['end_utc']))
    )
]);

execute_query($conn_tmi, $event_sql, [
    $program_id,
    $program_id,
    $event_detail,
    $event_data,
    $auth_cid
]);

// ============================================================================
// Fetch Updated Program
// ============================================================================

$updated = get_program($conn_tmi, $program_id);

respond_json(200, [
    'status' => 'ok',
    'message' => 'Program revised',
    'data' => [
        'program_id' => $program_id,
        'revision_number' => $new_rev,
        'changes' => $changes,
        'program' => $updated
    ]
]);
