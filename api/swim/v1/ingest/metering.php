<?php
/**
 * VATSWIM API v1 - Metering Data Ingest Endpoint
 *
 * Receives TBFM-style metering data from authoritative sources (SimTraffic, vATCSCC).
 * Updates FIXM-aligned metering fields in swim_flights table.
 *
 * @version 2.0.0
 * @since 2026-01-16
 *
 * FIXM Field Mapping:
 *   sequence_number          -> sequenceNumber (SEQ)
 *   scheduled_time_of_arrival -> scheduledTimeOfArrival (STA)
 *   metering_point           -> meteringPoint (MF)
 *   metering_time            -> meteringTime (MF_TIME)
 *   metering_delay           -> delayValue (DLA_ASGN)
 *   metering_frozen          -> frozenIndicator (FROZEN)
 *   arrival_stream           -> arrivalStream (GATE)
 *   metering_status          -> vATCSCC:meteringStatus
 *
 * Expected payload:
 * {
 *   "airport": "KJFK",                              // Required - airport receiving traffic
 *   "metering_point": "CAMRN",                      // Optional - default meter fix for this batch
 *   "metering": [
 *     {
 *       "callsign": "UAL123",                       // Required - aircraft identifier
 *       "gufi": "VAT-20260116-UAL123-KORD-KJFK",   // Optional - direct GUFI lookup
 *       "sequence_number": 5,                       // Optional - arrival sequence (1=next to land)
 *       "scheduled_time_of_arrival": "2026-01-16T18:30:00Z",  // STA at runway threshold
 *       "metering_time": "2026-01-16T18:15:00Z",   // STA at meter fix
 *       "metering_point": "CAMRN",                  // Override per-flight meter fix
 *       "metering_delay": 5,                        // Minutes of delay from unimpeded
 *       "metering_frozen": true,                    // Sequence frozen
 *       "arrival_stream": "NORTH",                  // Corner post/stream assignment
 *       "arrival_runway": "31L",                    // Assigned arrival runway
 *       "metering_status": "METERED",               // UNMETERED|METERED|FROZEN|SUSPENDED|EXEMPT
 *       "undelayed_eta": "2026-01-16T18:25:00Z",   // Baseline ETA without delay
 *       "eta_vertex": "2026-01-16T18:10:00Z",      // ETA at vertex/corner post
 *       "sta_vertex": "2026-01-16T18:12:00Z",      // Assigned time at vertex
 *       "vertex_point": "ROBER"                     // Vertex fix name
 *     }
 *   ]
 * }
 */

require_once __DIR__ . '/../auth.php';

// Require authentication with write access
$auth = swim_init_auth(true, true);

// Validate source can write metering data
if (!$auth->canWriteField('metering')) {
    SwimResponse::error(
        'Source "' . $auth->getSourceId() . '" is not authorized to write metering data. ' .
        'Metering requires System or Partner tier with metering authority.',
        403,
        'NOT_AUTHORITATIVE'
    );
}

// Get request body
$body = swim_get_json_body();
if (!$body) {
    SwimResponse::error('Request body is required', 400, 'MISSING_BODY');
}

if (!isset($body['metering']) || !is_array($body['metering'])) {
    SwimResponse::error('Request must contain a "metering" array', 400, 'MISSING_METERING');
}

// Extract context fields
$airport = strtoupper(trim($body['airport'] ?? ''));
$default_metering_point = strtoupper(trim($body['metering_point'] ?? ''));

if (empty($airport)) {
    SwimResponse::error('Airport (airport) is required', 400, 'MISSING_AIRPORT');
}

$metering = $body['metering'];
$max_batch = 500;

if (count($metering) > $max_batch) {
    SwimResponse::error(
        "Batch size exceeded. Maximum {$max_batch} metering records per request.",
        400,
        'BATCH_TOO_LARGE'
    );
}

$source = $auth->getSourceId();
$processed = 0;
$updated = 0;
$not_found = 0;
$errors = [];

// Get SWIM database connection
global $conn_swim;
if (!$conn_swim) {
    SwimResponse::error('SWIM database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

foreach ($metering as $index => $record) {
    try {
        $result = processMeteringUpdate($conn_swim, $record, $source, $airport, $default_metering_point);
        if ($result['status'] === 'updated') {
            $updated++;
        } elseif ($result['status'] === 'not_found') {
            $not_found++;
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
    'errors' => count($errors),
    'error_details' => array_slice($errors, 0, 10)
], [
    'source' => $source,
    'airport' => $airport,
    'metering_point' => $default_metering_point,
    'batch_size' => count($metering)
]);

/**
 * Process a single metering update
 *
 * @param resource $conn Database connection
 * @param array $record Metering record
 * @param string $source Source identifier
 * @param string $airport Destination airport
 * @param string $default_mf Default meter fix
 * @return array Result with status and details
 */
function processMeteringUpdate($conn, $record, $source, $airport, $default_mf) {
    // Validate required fields
    if (empty($record['callsign']) && empty($record['gufi'])) {
        throw new Exception('Missing required field: callsign or gufi');
    }

    $callsign = strtoupper(trim($record['callsign'] ?? ''));
    $gufi = trim($record['gufi'] ?? '');

    // Look up flight - prefer GUFI if provided
    if (!empty($gufi)) {
        $lookup_sql = "SELECT flight_uid, callsign, fp_dest_icao
                       FROM dbo.swim_flights
                       WHERE gufi = ? AND is_active = 1";
        $params = [$gufi];
    } else {
        // Look up by callsign and destination
        $lookup_sql = "SELECT TOP 1 flight_uid, callsign, fp_dest_icao
                       FROM dbo.swim_flights
                       WHERE callsign = ? AND fp_dest_icao = ? AND is_active = 1
                       ORDER BY last_sync_utc DESC";
        $params = [$callsign, $airport];
    }

    $lookup_stmt = sqlsrv_query($conn, $lookup_sql, $params);
    if ($lookup_stmt === false) {
        throw new Exception('Database error looking up flight');
    }

    $flight = sqlsrv_fetch_array($lookup_stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($lookup_stmt);

    if (!$flight) {
        return ['status' => 'not_found', 'callsign' => $callsign];
    }

    // Build UPDATE statement with FIXM-aligned fields
    $updates = [];
    $update_params = [];

    // Core TBFM fields (FIXM)
    if (isset($record['sequence_number'])) {
        $updates[] = 'sequence_number = ?';
        $update_params[] = intval($record['sequence_number']);
    }

    if (!empty($record['scheduled_time_of_arrival'])) {
        $updates[] = 'scheduled_time_of_arrival = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $record['scheduled_time_of_arrival'];
        // Also update eta_runway_utc for backward compatibility
        $updates[] = 'eta_runway_utc = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $record['scheduled_time_of_arrival'];
    }

    if (!empty($record['scheduled_time_of_departure'])) {
        $updates[] = 'scheduled_time_of_departure = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $record['scheduled_time_of_departure'];
    }

    // Metering point (use record-level or batch default)
    $metering_point = strtoupper(trim($record['metering_point'] ?? $default_mf));
    if (!empty($metering_point)) {
        $updates[] = 'metering_point = ?';
        $update_params[] = $metering_point;
    }

    if (!empty($record['metering_time'])) {
        $updates[] = 'metering_time = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $record['metering_time'];
    }

    if (isset($record['metering_delay'])) {
        $updates[] = 'metering_delay = ?';
        $update_params[] = intval($record['metering_delay']);
    }

    if (isset($record['metering_frozen'])) {
        $updates[] = 'metering_frozen = ?';
        $update_params[] = $record['metering_frozen'] ? 1 : 0;
    }

    if (!empty($record['arrival_stream'])) {
        $updates[] = 'arrival_stream = ?';
        $update_params[] = strtoupper(trim($record['arrival_stream']));
    }

    if (!empty($record['arrival_runway'])) {
        $updates[] = 'arr_runway = ?';
        $update_params[] = strtoupper(trim($record['arrival_runway']));
    }

    // Extended TBFM fields (vATCSCC)
    if (!empty($record['metering_status'])) {
        $status = strtoupper(trim($record['metering_status']));
        if (in_array($status, ['UNMETERED', 'METERED', 'FROZEN', 'SUSPENDED', 'EXEMPT'])) {
            $updates[] = 'metering_status = ?';
            $update_params[] = $status;
        }
    }

    if (!empty($record['undelayed_eta'])) {
        $updates[] = 'undelayed_eta = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $record['undelayed_eta'];
    }

    // Vertex times
    if (!empty($record['eta_vertex'])) {
        $updates[] = 'eta_vertex = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $record['eta_vertex'];
    }

    if (!empty($record['sta_vertex'])) {
        $updates[] = 'sta_vertex = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $record['sta_vertex'];
    }

    if (!empty($record['vertex_point'])) {
        $updates[] = 'vertex_point = ?';
        $update_params[] = strtoupper(trim($record['vertex_point']));
    }

    // Always update source tracking
    $updates[] = 'metering_source = ?';
    $update_params[] = $source;

    $updates[] = 'metering_updated_at = GETUTCDATE()';
    $updates[] = 'last_sync_utc = GETUTCDATE()';

    if (empty($updates)) {
        return ['status' => 'no_changes', 'callsign' => $callsign];
    }

    // Execute update
    $update_params[] = $flight['flight_uid'];

    $update_sql = "UPDATE dbo.swim_flights SET " . implode(', ', $updates) . " WHERE flight_uid = ?";

    $stmt = sqlsrv_query($conn, $update_sql, $update_params);
    if ($stmt === false) {
        $err = sqlsrv_errors();
        throw new Exception('Failed to update metering data: ' . ($err[0]['message'] ?? 'Unknown error'));
    }

    $rows = sqlsrv_rows_affected($stmt);
    sqlsrv_free_stmt($stmt);

    if ($rows > 0) {
        return [
            'status' => 'updated',
            'callsign' => $flight['callsign'],
            'flight_uid' => $flight['flight_uid']
        ];
    }

    return ['status' => 'no_changes', 'callsign' => $callsign];
}
