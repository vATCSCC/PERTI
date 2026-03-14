<?php
/**
 * VATSWIM API v1 - CDM Milestone Ingest Endpoint
 *
 * Receives A-CDM milestone data from authoritative sources (vACDM, CDM Plugin, vATCSCC).
 * Updates FIXM-aligned CDM timing fields in swim_flights table and pilot readiness in VATSIM_TMI.
 *
 * @version 1.0.0
 * @since 2026-03-05
 *
 * A-CDM Milestone Mapping:
 *   tobt  -> target_off_block_time (TOBT - Target Off-Block Time)
 *   tsat  -> target_startup_approval_time (TSAT - Target Startup Approval Time)
 *   ttot  -> target_takeoff_time (TTOT - Target Takeoff Time)
 *   asat  -> actual_startup_approval_time (ASAT - Actual Startup Approval Time)
 *   exot  -> expected_taxi_out_time (EXOT - Expected Taxi Out Time, minutes)
 *
 * Expected payload:
 * {
 *   "updates": [
 *     {
 *       "callsign": "BAW123",
 *       "gufi": "VAT-20260305-BAW123-EGLL-KJFK",
 *       "airport": "EGLL",
 *       "tobt": "2026-03-05T14:30:00Z",
 *       "tsat": "2026-03-05T14:35:00Z",
 *       "ttot": "2026-03-05T14:40:00Z",
 *       "asat": "2026-03-05T14:36:00Z",
 *       "exot": 5,
 *       "readiness_state": "READY",
 *       "source": "VACDM"
 *     }
 *   ]
 * }
 */

require_once __DIR__ . '/../auth.php';

// Require authentication with write access
$auth = swim_init_auth(true, true);

// Validate source can write CDM data
if (!$auth->canWriteField('cdm')) {
    SwimResponse::error(
        'Source "' . $auth->getSourceId() . '" is not authorized to write CDM data. ' .
        'CDM requires System or Partner tier with cdm authority.',
        403,
        'NOT_AUTHORITATIVE'
    );
}

// Get request body
$body = swim_get_json_body();
if (!$body) {
    SwimResponse::error('Request body is required', 400, 'MISSING_BODY');
}

if (!isset($body['updates']) || !is_array($body['updates'])) {
    SwimResponse::error('Request must contain an "updates" array', 400, 'MISSING_UPDATES');
}

$updates = $body['updates'];
$max_batch = 500;

if (count($updates) > $max_batch) {
    SwimResponse::error(
        "Batch size exceeded. Maximum {$max_batch} CDM records per request.",
        400,
        'BATCH_TOO_LARGE'
    );
}

$source = $auth->getSourceId();
$processed = 0;
$updated = 0;
$not_found = 0;
$readiness_updated = 0;
$errors = [];

// SWIM database connection (primary — all milestone writes)
global $conn_swim;
if (!$conn_swim) {
    SwimResponse::error('SWIM database connection not available', 503, 'SERVICE_UNAVAILABLE');
}
// TMI connection lazy-loaded only if readiness_state updates are present
$conn_tmi = null;

// Valid CDM readiness states
$valid_readiness_states = ['PLANNING', 'BOARDING', 'READY', 'TAXIING', 'CANCELLED'];

foreach ($updates as $index => $record) {
    try {
        $result = processCdmUpdate($conn_swim, $conn_tmi, $record, $source, $valid_readiness_states);
        if ($result['status'] === 'updated') {
            $updated++;
        } elseif ($result['status'] === 'not_found') {
            $not_found++;
        }
        if (!empty($result['readiness_updated'])) {
            $readiness_updated++;
        }
        $processed++;
    } catch (Exception $e) {
        $errors[] = [
            'index' => $index,
            'callsign' => $record['callsign'] ?? 'unknown',
            'error' => $e->getMessage()
        ];
    }
}

SwimResponse::success([
    'processed' => $processed,
    'updated' => $updated,
    'not_found' => $not_found,
    'readiness_updated' => $readiness_updated,
    'errors' => count($errors),
    'error_details' => array_slice($errors, 0, 10)
], [
    'source' => $source,
    'batch_size' => count($updates)
]);

/**
 * Process a single CDM milestone update
 *
 * @param resource $conn_swim SWIM database connection
 * @param resource|null $conn_tmi TMI database connection (for readiness SP)
 * @param array $record CDM update record
 * @param string $source Source identifier
 * @param array $valid_readiness_states Valid readiness state values
 * @return array Result with status and details
 */
function processCdmUpdate($conn_swim, $conn_tmi, $record, $source, $valid_readiness_states) {
    // Validate required fields
    if (empty($record['callsign']) && empty($record['gufi'])) {
        throw new Exception('Missing required field: callsign or gufi');
    }

    $callsign = strtoupper(trim($record['callsign'] ?? ''));
    $gufi = trim($record['gufi'] ?? '');
    $airport = strtoupper(trim($record['airport'] ?? ''));
    $cdm_source = strtoupper(trim($record['source'] ?? $source));

    // Look up flight - prefer GUFI if provided
    if (!empty($gufi)) {
        $lookup_sql = "SELECT flight_uid, callsign, fp_dept_icao, fp_dest_icao
                       FROM dbo.swim_flights
                       WHERE gufi = ? AND is_active = 1";
        $params = [$gufi];
    } elseif (!empty($airport)) {
        // Look up by callsign and departure airport (CDM is departure-focused)
        $lookup_sql = "SELECT TOP 1 flight_uid, callsign, fp_dept_icao, fp_dest_icao
                       FROM dbo.swim_flights
                       WHERE callsign = ? AND fp_dept_icao = ? AND is_active = 1
                       ORDER BY last_sync_utc DESC";
        $params = [$callsign, $airport];
    } else {
        // Fallback: callsign only (most recent active flight)
        $lookup_sql = "SELECT TOP 1 flight_uid, callsign, fp_dept_icao, fp_dest_icao
                       FROM dbo.swim_flights
                       WHERE callsign = ? AND is_active = 1
                       ORDER BY last_sync_utc DESC";
        $params = [$callsign];
    }

    $lookup_stmt = sqlsrv_query($conn_swim, $lookup_sql, $params);
    if ($lookup_stmt === false) {
        throw new Exception('Database error looking up flight');
    }

    $flight = sqlsrv_fetch_array($lookup_stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($lookup_stmt);

    if (!$flight) {
        return ['status' => 'not_found', 'callsign' => $callsign];
    }

    // Build UPDATE statement for CDM milestone fields
    $set_clauses = [];
    $update_params = [];

    // TOBT - Target Off-Block Time (can be updated multiple times)
    if (!empty($record['tobt'])) {
        $set_clauses[] = 'target_off_block_time = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $record['tobt'];
    }

    // TSAT - Target Startup Approval Time
    if (!empty($record['tsat'])) {
        $set_clauses[] = 'target_startup_approval_time = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $record['tsat'];
    }

    // TTOT - Target Takeoff Time
    if (!empty($record['ttot'])) {
        $set_clauses[] = 'target_takeoff_time = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $record['ttot'];
    }

    // TLDT - Target Landing Time (optional, arrival-side)
    if (!empty($record['tldt'])) {
        $set_clauses[] = 'target_landing_time = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $record['tldt'];
    }

    // ASAT - Actual Startup Approval Time (set once at pushback approval)
    if (!empty($record['asat'])) {
        $set_clauses[] = 'actual_startup_approval_time = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $record['asat'];
    }

    // EXOT - Expected Taxi Out Time (minutes)
    if (isset($record['exot']) && is_numeric($record['exot'])) {
        $exot = intval($record['exot']);
        if ($exot >= 0 && $exot <= 120) {
            $set_clauses[] = 'expected_taxi_out_time = ?';
            $update_params[] = $exot;
        }
    }

    // Always update source tracking when we have CDM fields
    if (!empty($set_clauses)) {
        $set_clauses[] = 'cdm_source = ?';
        $update_params[] = $cdm_source;

        $set_clauses[] = 'cdm_updated_at = GETUTCDATE()';
        $set_clauses[] = 'last_sync_utc = GETUTCDATE()';
    }

    $result = ['status' => 'no_changes', 'callsign' => $callsign, 'readiness_updated' => false];

    // Execute swim_flights update if we have fields to set
    if (!empty($set_clauses)) {
        $update_params[] = $flight['flight_uid'];
        $update_sql = "UPDATE dbo.swim_flights SET " . implode(', ', $set_clauses) . " WHERE flight_uid = ?";

        $stmt = sqlsrv_query($conn_swim, $update_sql, $update_params);
        if ($stmt === false) {
            $err = sqlsrv_errors();
            throw new Exception('Failed to update CDM data: ' . ($err[0]['message'] ?? 'Unknown error'));
        }

        $rows = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        if ($rows > 0) {
            $result['status'] = 'updated';
            $result['flight_uid'] = $flight['flight_uid'];
        }
    }

    // Update pilot readiness state in VATSIM_TMI if provided (lazy connect)
    if (!empty($record['readiness_state'])) {
        if ($conn_tmi === null) $conn_tmi = get_conn_tmi();
    }
    if (!empty($record['readiness_state']) && $conn_tmi) {
        $state = strtoupper(trim($record['readiness_state']));
        if (in_array($state, $valid_readiness_states)) {
            $reported_tobt = $record['tobt'] ?? null;
            $dep_airport = $airport ?: ($flight['fp_dept_icao'] ?? '');

            $sp_sql = "EXEC sp_CDM_UpdateReadiness ?, ?, ?, ?, ?, ?";
            $sp_params = [
                $flight['flight_uid'],
                $callsign ?: $flight['callsign'],
                $dep_airport,
                $state,
                $reported_tobt,
                $cdm_source
            ];

            $sp_stmt = sqlsrv_query($conn_tmi, $sp_sql, $sp_params);
            if ($sp_stmt !== false) {
                sqlsrv_free_stmt($sp_stmt);
                $result['readiness_updated'] = true;
            } else {
                // Log but don't fail the whole update for readiness errors
                error_log("CDM ingest: readiness SP failed for {$callsign}: " .
                    print_r(sqlsrv_errors(), true));
            }
        }
    }

    // If we updated swim_flights but readiness wasn't separately requested,
    // still mark result as updated
    if ($result['status'] === 'no_changes' && $result['readiness_updated']) {
        $result['status'] = 'updated';
    }

    return $result;
}
