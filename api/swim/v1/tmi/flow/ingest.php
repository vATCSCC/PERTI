<?php
/**
 * VATSWIM API v1 - Flow Measure Ingest Endpoint
 *
 * Receives flow measure data from external providers (ECFMP, NavCanada, VATPAC, etc.)
 * via authenticated PUSH. Writes to tmi_flow_measures in VATSIM_TMI with UPSERT
 * semantics (external_id + provider_id unique constraint).
 *
 * POST /api/swim/v1/tmi/flow/ingest
 *
 * Expected payload:
 * {
 *   "measures": [
 *     {
 *       "external_id": "12345",
 *       "ident": "EGLL_MDI_01",
 *       "event_id": null,
 *       "ctl_element": "EGTT",
 *       "element_type": "FIR",
 *       "measure_type": "MDI",
 *       "measure_value": "120",
 *       "measure_unit": "SEC",
 *       "reason": "Weather at EGLL",
 *       "filters_json": "{\"ades\":[\"EGLL\"]}",
 *       "mandatory_route_json": null,
 *       "start_utc": "2026-03-30T14:00:00Z",
 *       "end_utc": "2026-03-30T18:00:00Z",
 *       "status": "ACTIVE",
 *       "withdrawn_at": null
 *     }
 *   ]
 * }
 *
 * @version 1.0.0
 * @since 2026-03-30
 */

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../../../../load/tmi_log.php';

// Require authentication with write access
$auth = swim_init_auth(true, true);

// Validate source can write flow measure data
if (!$auth->canWriteField('flow_measure')) {
    SwimResponse::error(
        'Source "' . $auth->getSourceId() . '" is not authorized to write flow measure data.',
        403,
        'NOT_AUTHORITATIVE'
    );
}

// Only accept POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    SwimResponse::error('Method not allowed. Use POST.', 405, 'METHOD_NOT_ALLOWED');
}

// Get request body
$body = swim_get_json_body();
if (!$body) {
    SwimResponse::error('Request body is required', 400, 'MISSING_BODY');
}

if (!isset($body['measures']) || !is_array($body['measures'])) {
    SwimResponse::error('Request must contain a "measures" array', 400, 'MISSING_MEASURES');
}

$measures = $body['measures'];
$max_batch = 200;

if (count($measures) === 0) {
    SwimResponse::error('Measures array cannot be empty', 400, 'EMPTY_MEASURES');
}

if (count($measures) > $max_batch) {
    SwimResponse::error(
        "Batch size exceeded. Maximum {$max_batch} flow measures per request.",
        400,
        'BATCH_TOO_LARGE'
    );
}

// TMI database connection (all writes go to VATSIM_TMI)
$conn_tmi = get_conn_tmi();
if (!$conn_tmi) {
    SwimResponse::error('TMI database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

$source = $auth->getSourceId();

// Look up the provider_id for this source
$provider_id = lookupProviderId($conn_tmi, $source);
if (!$provider_id) {
    SwimResponse::error(
        'No registered flow provider found for source "' . $source . '". ' .
        'Register the provider in tmi_flow_providers first.',
        404,
        'PROVIDER_NOT_FOUND'
    );
}

// Validation constants
$valid_measure_types = ['MDI', 'MIT', 'RATE', 'GS', 'REROUTE', 'OTHER'];
$valid_statuses = ['NOTIFIED', 'ACTIVE', 'EXPIRED', 'WITHDRAWN'];

$processed = 0;
$created = 0;
$updated = 0;
$errors = [];

foreach ($measures as $index => $record) {
    // Validate required fields
    $validation_error = validateMeasureRecord($record, $valid_measure_types, $valid_statuses);
    if ($validation_error) {
        $errors[] = [
            'index' => $index,
            'external_id' => $record['external_id'] ?? 'unknown',
            'error' => $validation_error
        ];
        continue;
    }

    // Normalize fields
    $record = normalizeMeasureRecord($record);

    // Upsert the measure
    $result = upsertFlowMeasure($conn_tmi, $provider_id, $record, $source);
    if ($result['status'] === 'error') {
        $errors[] = [
            'index' => $index,
            'external_id' => $record['external_id'],
            'error' => $result['message']
        ];
        continue;
    }

    // Log the TMI action
    $is_new = ($result['status'] === 'created');
    $measure_id = $result['measure_id'];

    log_tmi_action($conn_tmi, [
        'action_category' => 'FLOW_MEASURE',
        'action_type' => $is_new ? 'ISSUE' : ($record['status'] === 'WITHDRAWN' ? 'WITHDRAW' : 'UPDATE'),
        'program_type' => $record['measure_type'],
        'summary' => "Flow measure {$record['ident']}: {$record['measure_type']} {$record['measure_value']}{$record['measure_unit']} at {$record['ctl_element']}",
        'source_system' => 'VATSWIM_INGEST',
        'issuing_facility' => $record['ctl_element'],
        'issuing_org' => strtoupper($source),
    ], [
        // scope
        'ctl_element' => $record['ctl_element'],
        'element_type' => $record['element_type'] ?? 'FIR',
        'facility' => $record['ctl_element'],
        'filters_json' => $record['filters_json'] ?? null,
    ], [
        // parameters
        'effective_start_utc' => $record['start_utc'],
        'effective_end_utc' => $record['end_utc'],
        'rate_value' => is_numeric($record['measure_value'] ?? '') ? intval($record['measure_value']) : null,
        'rate_unit' => $record['measure_unit'],
        'cancellation_reason' => ($record['status'] === 'WITHDRAWN') ? ($record['reason'] ?? 'Withdrawn') : null,
    ], null, [
        // references
        'flow_measure_id' => $measure_id,
        'source_type' => 'FLOW_PROVIDER',
        'source_id' => $source,
    ]);

    if ($is_new) {
        $created++;
    } else {
        $updated++;
    }
    $processed++;
}

SwimResponse::success([
    'processed' => $processed,
    'created' => $created,
    'updated' => $updated,
    'errors' => count($errors),
    'error_details' => array_slice($errors, 0, 10)
], [
    'source' => $source,
    'batch_size' => count($measures)
]);

// ---------------------------------------------------------------------------
// Functions
// ---------------------------------------------------------------------------

/**
 * Look up the provider_id for a given SWIM API source_id.
 *
 * Matches case-insensitively against tmi_flow_providers.provider_code.
 *
 * @param resource $conn_tmi TMI database connection
 * @param string $source SWIM API source identifier
 * @return int|null provider_id or null if not found
 */
function lookupProviderId($conn_tmi, $source) {
    $sql = "SELECT provider_id FROM dbo.tmi_flow_providers
            WHERE UPPER(provider_code) = UPPER(?) AND is_active = 1";
    $stmt = sqlsrv_query($conn_tmi, $sql, [$source]);
    if ($stmt === false) {
        return null;
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    return $row ? (int)$row['provider_id'] : null;
}

/**
 * Validate a single measure record.
 *
 * @param array $record Measure record from request body
 * @param array $valid_measure_types Allowed measure_type values
 * @param array $valid_statuses Allowed status values
 * @return string|null Error message or null if valid
 */
function validateMeasureRecord($record, $valid_measure_types, $valid_statuses) {
    if (empty($record['external_id'])) {
        return 'Missing required field: external_id';
    }
    if (empty($record['ctl_element'])) {
        return 'Missing required field: ctl_element';
    }
    if (empty($record['measure_type'])) {
        return 'Missing required field: measure_type';
    }
    if (empty($record['start_utc'])) {
        return 'Missing required field: start_utc';
    }
    if (empty($record['end_utc'])) {
        return 'Missing required field: end_utc';
    }

    $measure_type = strtoupper(trim($record['measure_type']));
    if (!in_array($measure_type, $valid_measure_types)) {
        return "Invalid measure_type: {$record['measure_type']}. Must be one of: " . implode(', ', $valid_measure_types);
    }

    if (!empty($record['status'])) {
        $status = strtoupper(trim($record['status']));
        if (!in_array($status, $valid_statuses)) {
            return "Invalid status: {$record['status']}. Must be one of: " . implode(', ', $valid_statuses);
        }
    }

    // Validate filters_json is valid JSON if provided
    if (!empty($record['filters_json']) && is_string($record['filters_json'])) {
        json_decode($record['filters_json']);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return 'filters_json is not valid JSON: ' . json_last_error_msg();
        }
    }

    // Validate mandatory_route_json is valid JSON if provided
    if (!empty($record['mandatory_route_json']) && is_string($record['mandatory_route_json'])) {
        json_decode($record['mandatory_route_json']);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return 'mandatory_route_json is not valid JSON: ' . json_last_error_msg();
        }
    }

    // Validate timestamps parse correctly
    if (strtotime($record['start_utc']) === false) {
        return 'Invalid start_utc timestamp format';
    }
    if (strtotime($record['end_utc']) === false) {
        return 'Invalid end_utc timestamp format';
    }

    return null;
}

/**
 * Normalize a measure record before database write.
 *
 * Trims whitespace, uppercases enum fields, and coerces JSON fields.
 *
 * @param array $record Raw measure record
 * @return array Normalized record
 */
function normalizeMeasureRecord($record) {
    $record['external_id'] = trim($record['external_id']);
    $record['ident'] = trim($record['ident'] ?? $record['external_id']);
    $record['ctl_element'] = strtoupper(trim($record['ctl_element']));
    $record['element_type'] = strtoupper(trim($record['element_type'] ?? 'FIR'));
    $record['measure_type'] = strtoupper(trim($record['measure_type']));
    $record['measure_value'] = isset($record['measure_value']) ? trim((string)$record['measure_value']) : null;
    $record['measure_unit'] = isset($record['measure_unit']) ? strtoupper(trim($record['measure_unit'])) : null;
    $record['reason'] = trim($record['reason'] ?? '');
    $record['status'] = strtoupper(trim($record['status'] ?? 'ACTIVE'));

    // Ensure JSON fields are strings (allow objects/arrays passed directly)
    if (isset($record['filters_json']) && !is_string($record['filters_json'])) {
        $record['filters_json'] = json_encode($record['filters_json']);
    }
    if (isset($record['mandatory_route_json']) && !is_string($record['mandatory_route_json'])) {
        $record['mandatory_route_json'] = json_encode($record['mandatory_route_json']);
    }

    return $record;
}

/**
 * Upsert a flow measure record.
 *
 * Uses the UNIQUE constraint on (provider_id, external_id) to determine
 * whether to INSERT or UPDATE. Checks for existing row first, then acts.
 *
 * @param resource $conn_tmi TMI database connection
 * @param int $provider_id Provider ID
 * @param array $record Normalized measure record
 * @param string $source Source identifier (for raw_data_json)
 * @return array Result with 'status' (created|updated|error) and 'measure_id'
 */
function upsertFlowMeasure($conn_tmi, $provider_id, $record, $source) {
    // Check if this external_id already exists for this provider
    $lookup_sql = "SELECT measure_id FROM dbo.tmi_flow_measures
                   WHERE provider_id = ? AND external_id = ?";
    $lookup_stmt = sqlsrv_query($conn_tmi, $lookup_sql, [$provider_id, $record['external_id']]);
    if ($lookup_stmt === false) {
        $err = sqlsrv_errors();
        return ['status' => 'error', 'message' => 'Lookup failed: ' . ($err[0]['message'] ?? 'Unknown error')];
    }

    $existing = sqlsrv_fetch_array($lookup_stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($lookup_stmt);

    // Build raw_data_json for audit trail
    $raw_data_json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Withdrawn_at handling
    $withdrawn_at = null;
    if ($record['status'] === 'WITHDRAWN' && !empty($record['withdrawn_at'])) {
        $withdrawn_at = $record['withdrawn_at'];
    } elseif ($record['status'] === 'WITHDRAWN') {
        // Auto-set withdrawn_at to now if status is WITHDRAWN but no timestamp given
        $withdrawn_at = gmdate('Y-m-d\TH:i:s');
    }

    if ($existing) {
        // UPDATE existing measure
        $measure_id = (int)$existing['measure_id'];

        $update_sql = "UPDATE dbo.tmi_flow_measures SET
            ident = ?,
            event_id = ?,
            ctl_element = ?,
            element_type = ?,
            measure_type = ?,
            measure_value = ?,
            measure_unit = ?,
            reason = ?,
            filters_json = ?,
            mandatory_route_json = ?,
            start_utc = TRY_CONVERT(datetime2, ?),
            end_utc = TRY_CONVERT(datetime2, ?),
            status = ?,
            withdrawn_at = TRY_CONVERT(datetime2, ?),
            raw_data_json = ?,
            synced_at = SYSUTCDATETIME(),
            updated_at = SYSUTCDATETIME(),
            revision = revision + 1
            WHERE measure_id = ?";

        $update_params = [
            $record['ident'],
            isset($record['event_id']) ? intval($record['event_id']) : null,
            $record['ctl_element'],
            $record['element_type'],
            $record['measure_type'],
            $record['measure_value'],
            $record['measure_unit'],
            $record['reason'] ?: null,
            $record['filters_json'] ?? null,
            $record['mandatory_route_json'] ?? null,
            $record['start_utc'],
            $record['end_utc'],
            $record['status'],
            $withdrawn_at,
            $raw_data_json,
            $measure_id
        ];

        $stmt = sqlsrv_query($conn_tmi, $update_sql, $update_params);
        if ($stmt === false) {
            $err = sqlsrv_errors();
            return ['status' => 'error', 'message' => 'Update failed: ' . ($err[0]['message'] ?? 'Unknown error')];
        }
        sqlsrv_free_stmt($stmt);

        return ['status' => 'updated', 'measure_id' => $measure_id];
    }

    // INSERT new measure
    $insert_sql = "INSERT INTO dbo.tmi_flow_measures
        (provider_id, external_id, ident, event_id, ctl_element, element_type,
         measure_type, measure_value, measure_unit, reason,
         filters_json, mandatory_route_json,
         start_utc, end_utc, status, withdrawn_at,
         raw_data_json, synced_at, created_at, updated_at)
        OUTPUT INSERTED.measure_id
        VALUES
        (?, ?, ?, ?, ?, ?,
         ?, ?, ?, ?,
         ?, ?,
         TRY_CONVERT(datetime2, ?), TRY_CONVERT(datetime2, ?), ?, TRY_CONVERT(datetime2, ?),
         ?, SYSUTCDATETIME(), SYSUTCDATETIME(), SYSUTCDATETIME())";

    $insert_params = [
        $provider_id,
        $record['external_id'],
        $record['ident'],
        isset($record['event_id']) ? intval($record['event_id']) : null,
        $record['ctl_element'],
        $record['element_type'],
        $record['measure_type'],
        $record['measure_value'],
        $record['measure_unit'],
        $record['reason'] ?: null,
        $record['filters_json'] ?? null,
        $record['mandatory_route_json'] ?? null,
        $record['start_utc'],
        $record['end_utc'],
        $record['status'],
        $withdrawn_at,
        $raw_data_json
    ];

    $stmt = sqlsrv_query($conn_tmi, $insert_sql, $insert_params);
    if ($stmt === false) {
        $err = sqlsrv_errors();
        return ['status' => 'error', 'message' => 'Insert failed: ' . ($err[0]['message'] ?? 'Unknown error')];
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    $measure_id = $row ? (int)$row['measure_id'] : 0;

    return ['status' => 'created', 'measure_id' => $measure_id];
}
