<?php
/**
 * GDT Programs - Transition API (GS -> GDP)
 *
 * POST /api/gdt/programs/transition.php
 *
 * Two-phase GS-to-GDP transition following FAA advisory chain pattern:
 *
 *   phase="propose" — Creates a PROPOSED GDP linked to the parent GS.
 *     GS remains ACTIVE. Issues "CDM PROPOSED GROUND DELAY PROGRAM" advisory.
 *
 *   phase="activate" — Transitions GS to TRANSITIONED, activates the GDP.
 *     Issues "CDM GROUND DELAY PROGRAM" advisory with "GROUND STOP CANCELLED."
 *     in comments and CUMULATIVE PROGRAM PERIOD.
 *
 * Request body (JSON):
 * {
 *   "gs_program_id": 123,                   // Required: parent GS
 *   "phase": "propose",                     // Required: "propose" or "activate"
 *   "gdp_program_id": null,                 // Required for "activate" phase
 *   "gdp_type": "GDP-DAS",                  // GDP-DAS (default), GDP-GAAP, GDP-UDP
 *   "gdp_end_utc": "2026-02-11T20:00Z",    // Required for "propose"
 *   "program_rate": 36,                     // Required for "propose"
 *   "reserve_rate": 5,                      // Optional (GAAP/UDP only)
 *   "delay_limit_min": 300,                 // Optional: delay cap
 *   "impacting_condition": "WEATHER",       // Optional
 *   "comments": "ARR 4R, DEP 4R."          // Optional
 * }
 *
 * @version 2.0.0
 * @date 2026-02-11
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('GDT_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');
require_once __DIR__ . '/../../../load/perti_constants.php';
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
// Common Validation
// ============================================================================

$gs_program_id = isset($payload['gs_program_id']) ? (int)$payload['gs_program_id'] : 0;
$phase = isset($payload['phase']) ? strtolower(trim($payload['phase'])) : 'activate';

if ($gs_program_id <= 0) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'gs_program_id is required.'
    ]);
}

if (!in_array($phase, ['propose', 'activate'])) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'phase must be "propose" or "activate".'
    ]);
}

// Fetch and validate parent GS
$gs_program = get_program($conn_tmi, $gs_program_id);

if ($gs_program === null) {
    respond_json(404, [
        'status' => 'error',
        'message' => "GS program not found: {$gs_program_id}"
    ]);
}

if (($gs_program['program_type'] ?? '') !== 'GS') {
    respond_json(400, [
        'status' => 'error',
        'message' => "Program {$gs_program_id} is not a Ground Stop."
    ]);
}

// ============================================================================
// Phase: PROPOSE — Create PROPOSED GDP linked to parent GS
// ============================================================================

if ($phase === 'propose') {
    $gdp_type = isset($payload['gdp_type']) ? strtoupper(trim($payload['gdp_type'])) : 'GDP-DAS';
    $gdp_end_utc = isset($payload['gdp_end_utc']) ? parse_utc_datetime($payload['gdp_end_utc']) : null;
    $program_rate = isset($payload['program_rate']) ? (int)$payload['program_rate'] : 0;
    $reserve_rate = isset($payload['reserve_rate']) ? (int)$payload['reserve_rate'] : null;
    $delay_limit_min = isset($payload['delay_limit_min']) ? (int)$payload['delay_limit_min'] : 180;
    $impacting_condition = isset($payload['impacting_condition']) ? trim($payload['impacting_condition']) : ($gs_program['impacting_condition'] ?? 'WEATHER');
    $comments = isset($payload['comments']) ? trim($payload['comments']) : '';

    if ($gdp_end_utc === null) {
        respond_json(400, ['status' => 'error', 'message' => 'gdp_end_utc is required for propose phase.']);
    }
    if ($program_rate <= 0) {
        respond_json(400, ['status' => 'error', 'message' => 'program_rate is required and must be positive.']);
    }
    if (!in_array($gdp_type, PERTI_GDP_TYPES)) {
        respond_json(400, ['status' => 'error', 'message' => "Invalid gdp_type: {$gdp_type}."]);
    }

    // Compute cumulative start from parent GS
    $gs_start = $gs_program['start_utc'];
    $cumulative_start = ($gs_start instanceof DateTimeInterface)
        ? $gs_start->format('Y-m-d H:i:s')
        : parse_utc_datetime((string)$gs_start);

    // Inherit chain ID from parent
    $chain_id = $gs_program['advisory_chain_id'] ?? $gs_program_id;

    // Inherit scope from parent if not provided
    $scope_json = isset($payload['scope_json'])
        ? (is_string($payload['scope_json']) ? $payload['scope_json'] : json_encode($payload['scope_json']))
        : ($gs_program['scope_json'] ?? null);

    $exemptions_json = isset($payload['exemptions_json'])
        ? (is_string($payload['exemptions_json']) ? $payload['exemptions_json'] : json_encode($payload['exemptions_json']))
        : ($gs_program['exemptions_json'] ?? null);

    // INSERT new PROPOSED GDP
    $insert_sql = "
        INSERT INTO dbo.tmi_programs (
            ctl_element, element_type, program_type, program_name,
            status, is_proposed, is_active,
            start_utc, end_utc, cumulative_start, cumulative_end,
            program_rate, reserve_rate, delay_limit_min,
            impacting_condition, cause_text, comments,
            scope_json, exemptions_json,
            parent_program_id, advisory_chain_id, transition_type,
            created_by, created_at, updated_at
        ) VALUES (
            ?, ?, ?, ?,
            'PROPOSED', 1, 0,
            ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            ?, ?,
            ?, ?, 'GS_TO_GDP',
            ?, SYSUTCDATETIME(), SYSUTCDATETIME()
        );
        SELECT SCOPE_IDENTITY() AS gdp_program_id;
    ";

    $ctl_element = $gs_program['ctl_element'] ?? '';
    $element_type = $gs_program['element_type'] ?? 'APT';
    $program_name = $gdp_type . ' ' . $ctl_element . ' (from GS #' . $gs_program_id . ')';

    // GDP starts when the GS was supposed to end (or now, whichever is later)
    $gs_end = $gs_program['end_utc'];
    $gdp_start = ($gs_end instanceof DateTimeInterface)
        ? $gs_end->format('Y-m-d H:i:s')
        : parse_utc_datetime((string)$gs_end);
    if ($gdp_start === null) {
        $gdp_start = gmdate('Y-m-d H:i:s');
    }

    $stmt = sqlsrv_query($conn_tmi, $insert_sql, [
        $ctl_element, $element_type, $gdp_type, $program_name,
        $gdp_start, $gdp_end_utc, $cumulative_start, $gdp_end_utc,
        $program_rate, $reserve_rate, $delay_limit_min,
        $impacting_condition, $comments, $comments,
        $scope_json, $exemptions_json,
        $gs_program_id, $chain_id,
        $auth_cid
    ]);

    if ($stmt === false) {
        respond_json(500, [
            'status' => 'error',
            'message' => 'Failed to create proposed GDP',
            'errors' => sqlsrv_errors()
        ]);
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $gdp_program_id = ($row && isset($row['gdp_program_id'])) ? (int)$row['gdp_program_id'] : 0;
    sqlsrv_free_stmt($stmt);

    if ($gdp_program_id <= 0) {
        respond_json(500, [
            'status' => 'error',
            'message' => 'Insert succeeded but did not return GDP program ID'
        ]);
    }

    // Log event
    execute_query($conn_tmi,
        "INSERT INTO dbo.tmi_events (entity_type, entity_id, program_id, event_type, event_detail, source_type, actor_id, event_utc)
         VALUES ('PROGRAM', ?, ?, 'GS_TO_GDP_PROPOSED', ?, 'GDT', ?, SYSUTCDATETIME())",
        [$gdp_program_id, $gs_program_id, "Proposed GDP #{$gdp_program_id} from GS #{$gs_program_id}", $auth_cid]
    );

    $gdp_program = get_program($conn_tmi, $gdp_program_id);

    respond_json(200, [
        'status' => 'ok',
        'message' => 'GDP proposed',
        'data' => [
            'phase' => 'propose',
            'gs_program_id' => $gs_program_id,
            'gdp_program_id' => $gdp_program_id,
            'gs_program' => $gs_program,
            'gdp_program' => $gdp_program,
            'cumulative_start' => $cumulative_start,
            'cumulative_end' => $gdp_end_utc
        ]
    ]);
}

// ============================================================================
// Phase: ACTIVATE — Transition GS to TRANSITIONED, activate GDP
// ============================================================================

if ($phase === 'activate') {
    $gdp_program_id = isset($payload['gdp_program_id']) ? (int)$payload['gdp_program_id'] : 0;

    if ($gdp_program_id <= 0) {
        respond_json(400, [
            'status' => 'error',
            'message' => 'gdp_program_id is required for activate phase.'
        ]);
    }

    $gdp_program = get_program($conn_tmi, $gdp_program_id);
    if ($gdp_program === null) {
        respond_json(404, [
            'status' => 'error',
            'message' => "GDP program not found: {$gdp_program_id}"
        ]);
    }

    // Verify the GDP is linked to this GS
    if ((int)($gdp_program['parent_program_id'] ?? 0) !== $gs_program_id) {
        respond_json(400, [
            'status' => 'error',
            'message' => "GDP #{$gdp_program_id} is not linked to GS #{$gs_program_id}."
        ]);
    }

    // Transition GS to TRANSITIONED
    $result1 = execute_query($conn_tmi,
        "UPDATE dbo.tmi_programs SET status = 'TRANSITIONED', is_active = 0, updated_at = SYSUTCDATETIME() WHERE program_id = ?",
        [$gs_program_id]
    );

    if (!$result1['success']) {
        respond_json(500, [
            'status' => 'error',
            'message' => 'Failed to transition GS',
            'errors' => $result1['error']
        ]);
    }

    // Activate GDP
    $result2 = execute_query($conn_tmi,
        "UPDATE dbo.tmi_programs SET status = 'ACTIVE', is_proposed = 0, is_active = 1, activated_at = SYSUTCDATETIME(), updated_at = SYSUTCDATETIME() WHERE program_id = ?",
        [$gdp_program_id]
    );

    if (!$result2['success']) {
        respond_json(500, [
            'status' => 'error',
            'message' => 'Failed to activate GDP',
            'errors' => $result2['error']
        ]);
    }

    // Log events
    execute_query($conn_tmi,
        "INSERT INTO dbo.tmi_events (entity_type, entity_id, program_id, event_type, event_detail, source_type, actor_id, event_utc)
         VALUES ('PROGRAM', ?, ?, 'GS_TRANSITIONED', ?, 'GDT', ?, SYSUTCDATETIME())",
        [$gs_program_id, $gs_program_id, "GS #{$gs_program_id} transitioned to GDP #{$gdp_program_id}", $auth_cid]
    );

    execute_query($conn_tmi,
        "INSERT INTO dbo.tmi_events (entity_type, entity_id, program_id, event_type, event_detail, source_type, actor_id, event_utc)
         VALUES ('PROGRAM', ?, ?, 'GDP_ACTIVATED_FROM_GS', ?, 'GDT', ?, SYSUTCDATETIME())",
        [$gdp_program_id, $gs_program_id, "GDP #{$gdp_program_id} activated (GS #{$gs_program_id} transitioned)", $auth_cid]
    );

    // Fetch updated records
    $gs_program = get_program($conn_tmi, $gs_program_id);
    $gdp_program = get_program($conn_tmi, $gdp_program_id);

    respond_json(200, [
        'status' => 'ok',
        'message' => 'Transition complete',
        'data' => [
            'phase' => 'activate',
            'gs_program_id' => $gs_program_id,
            'gdp_program_id' => $gdp_program_id,
            'gs_program' => $gs_program,
            'gdp_program' => $gdp_program
        ]
    ]);
}
