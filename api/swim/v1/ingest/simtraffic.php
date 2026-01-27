<?php
/**
 * VATSWIM API v1 - SimTraffic Flight Times Ingest Endpoint
 *
 * Receives SimTraffic flight timing data and updates SWIM database.
 * Supports both PUSH mode (SimTraffic sends data) and PULL mode (fetch from ST API).
 *
 * @version 1.0.0
 * @since 2026-01-27
 *
 * SimTraffic API Field Mapping:
 *   departure.push_time      -> out_utc (T13 AOBT)
 *   departure.taxi_time      -> taxi_time_utc
 *   departure.sequence_time  -> sequence_time_utc
 *   departure.holdshort_time -> holdshort_time_utc
 *   departure.runway_time    -> runway_time_utc
 *   departure.takeoff_time   -> off_utc (T11 ATOT)
 *   departure.edct           -> edct_utc
 *   arrival.eta              -> eta_utc, eta_runway_utc
 *   arrival.eta_mf / mft     -> metering_time
 *   arrival.eta_vertex / vt  -> eta_vertex, sta_vertex
 *   arrival.on_time          -> on_utc (T12 ALDT)
 *   arrival.metering_fix     -> metering_point
 *   arrival.rwy_assigned     -> arr_runway
 *   status.departed          -> phase='enroute'
 *   status.arrived           -> phase='arrived'
 *   status.in_artcc          -> current_artcc
 *   status.delay_value       -> metering_delay
 *
 * Expected payload (PUSH mode):
 * {
 *   "mode": "push",                                    // Required - "push" or "pull"
 *   "flights": [
 *     {
 *       "callsign": "UAL123",                         // Required - aircraft identifier
 *       "gufi": "VAT-20260127-UAL123-KORD-KJFK",     // Optional - direct GUFI lookup
 *       "departure_afld": "KORD",                     // Optional - for flight lookup
 *       "arrival_afld": "KJFK",                       // Optional - for flight lookup
 *       "departure": {
 *         "push_time": "2026-01-27T14:30:00Z",       // Actual pushback
 *         "taxi_time": "2026-01-27T14:35:00Z",       // Taxi start
 *         "sequence_time": "2026-01-27T14:38:00Z",   // Departure sequence
 *         "holdshort_time": "2026-01-27T14:40:00Z",  // Hold short point
 *         "runway_time": "2026-01-27T14:42:00Z",     // Runway entry
 *         "takeoff_time": "2026-01-27T14:45:00Z",    // Actual takeoff
 *         "edct": "2026-01-27T14:40:00Z"             // EDCT
 *       },
 *       "arrival": {
 *         "eta": "2026-01-27T17:15:00Z",             // ETA at runway
 *         "eta_mf": "2026-01-27T17:00:00Z",          // ETA at meter fix
 *         "mft": "2026-01-27T17:00:00Z",             // Alias for eta_mf
 *         "eta_vertex": "2026-01-27T16:45:00Z",      // ETA at vertex
 *         "vt": "2026-01-27T16:45:00Z",              // Alias for eta_vertex
 *         "on_time": "2026-01-27T17:18:00Z",         // Actual landing
 *         "metering_fix": "CAMRN",                    // Meter fix
 *         "rwy_assigned": "31L",                      // Assigned runway
 *         "arrived": false                            // Arrived flag
 *       },
 *       "status": {
 *         "departed": true,                           // Departed flag
 *         "enroute": true,                            // Enroute flag
 *         "in_artcc": "ZDC",                          // Current ARTCC
 *         "delay_value": 5                            // TBFM delay minutes
 *       }
 *     }
 *   ]
 * }
 *
 * PULL mode payload:
 * {
 *   "mode": "pull",
 *   "callsigns": ["UAL123", "DAL456"]                // Callsigns to fetch from SimTraffic API
 * }
 */

require_once __DIR__ . '/../auth.php';

// Rate limit: 5 requests/second per API key (per SimTraffic API documentation)
define('SIMTRAFFIC_RATE_LIMIT_MS', 200);  // 200ms between API calls

// Require authentication with write access
$auth = swim_init_auth(true, true);

// Validate source can write times data
if (!$auth->canWriteField('times')) {
    SwimResponse::error(
        'Source "' . $auth->getSourceId() . '" is not authorized to write times data. ' .
        'SimTraffic times ingest requires System or Partner tier with times authority.',
        403,
        'NOT_AUTHORITATIVE'
    );
}

// Get request body
$body = swim_get_json_body();
if (!$body) {
    SwimResponse::error('Request body is required', 400, 'MISSING_BODY');
}

// Determine mode: push or pull
$mode = strtolower(trim($body['mode'] ?? 'push'));
if (!in_array($mode, ['push', 'pull'])) {
    SwimResponse::error('Invalid mode. Must be "push" or "pull".', 400, 'INVALID_MODE');
}

$source = $auth->getSourceId();

// Get SWIM database connection
global $conn_swim;
if (!$conn_swim) {
    SwimResponse::error('SWIM database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

if ($mode === 'pull') {
    // PULL mode: Fetch from SimTraffic API
    handlePullMode($conn_swim, $body, $source);
} else {
    // PUSH mode: Process incoming data
    handlePushMode($conn_swim, $body, $source);
}

/**
 * Handle PUSH mode - SimTraffic sends data directly
 */
function handlePushMode($conn, $body, $source) {
    if (!isset($body['flights']) || !is_array($body['flights'])) {
        SwimResponse::error('Request must contain a "flights" array', 400, 'MISSING_FLIGHTS');
    }

    $flights = $body['flights'];
    $max_batch = 500;

    if (count($flights) > $max_batch) {
        SwimResponse::error(
            "Batch size exceeded. Maximum {$max_batch} flights per request.",
            400,
            'BATCH_TOO_LARGE'
        );
    }

    $processed = 0;
    $updated = 0;
    $not_found = 0;
    $errors = [];

    foreach ($flights as $index => $record) {
        try {
            $result = processSimTrafficFlight($conn, $record, $source);
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
        'mode' => 'push',
        'batch_size' => count($flights)
    ]);
}

/**
 * Handle PULL mode - Fetch from SimTraffic API
 */
function handlePullMode($conn, $body, $source) {
    if (!isset($body['callsigns']) || !is_array($body['callsigns'])) {
        SwimResponse::error('PULL mode requires a "callsigns" array', 400, 'MISSING_CALLSIGNS');
    }

    $callsigns = array_map(function($cs) {
        return strtoupper(trim($cs));
    }, $body['callsigns']);

    // Remove empty/invalid callsigns
    $callsigns = array_filter($callsigns, function($cs) {
        return !empty($cs) && preg_match('/^[A-Z0-9]{2,10}$/', $cs);
    });

    $max_batch = 100;  // Lower limit for PULL mode due to API rate limits
    if (count($callsigns) > $max_batch) {
        SwimResponse::error(
            "Batch size exceeded. Maximum {$max_batch} callsigns per PULL request.",
            400,
            'BATCH_TOO_LARGE'
        );
    }

    $processed = 0;
    $updated = 0;
    $not_found = 0;
    $api_errors = 0;
    $errors = [];

    foreach ($callsigns as $callsign) {
        try {
            // Fetch from SimTraffic API
            $stData = fetchFromSimTrafficAPI($callsign);

            if ($stData === null) {
                $api_errors++;
                continue;
            }

            if ($stData === false) {
                $not_found++;
                $processed++;
                continue;
            }

            // Transform SimTraffic API response to our record format
            $record = transformSimTrafficResponse($stData, $callsign);

            // Process into SWIM
            $result = processSimTrafficFlight($conn, $record, $source);
            if ($result['status'] === 'updated') {
                $updated++;
            } elseif ($result['status'] === 'not_found') {
                $not_found++;
            }
            $processed++;

            // Rate limit: 200ms between calls (5/second)
            usleep(SIMTRAFFIC_RATE_LIMIT_MS * 1000);

        } catch (Exception $e) {
            $errors[] = [
                'callsign' => $callsign,
                'error' => $e->getMessage()
            ];
        }
    }

    SwimResponse::success([
        'processed' => $processed,
        'updated' => $updated,
        'not_found' => $not_found,
        'api_errors' => $api_errors,
        'errors' => count($errors),
        'error_details' => array_slice($errors, 0, 10)
    ], [
        'source' => $source,
        'mode' => 'pull',
        'requested_callsigns' => count($callsigns)
    ]);
}

/**
 * Fetch flight data from SimTraffic API
 *
 * @param string $callsign Aircraft callsign
 * @return array|false|null Data array, false if not found, null on error
 */
function fetchFromSimTrafficAPI($callsign) {
    // Get API key from environment
    $apiKey = getenv('SIMTRAFFIC_API_KEY');
    if (!$apiKey && defined('SIMTRAFFIC_API_KEY')) {
        $apiKey = SIMTRAFFIC_API_KEY;
    }

    if (!$apiKey) {
        throw new Exception('SIMTRAFFIC_API_KEY not configured');
    }

    $url = 'https://api.simtraffic.net/v1/flight/' . rawurlencode($callsign);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $apiKey,
            'Accept: application/json'
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return null;  // API error
    }

    if ($httpCode === 404) {
        return false;  // Not found
    }

    if ($httpCode !== 200) {
        return null;  // API error
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;  // Parse error
    }

    return $data;
}

/**
 * Transform SimTraffic API response to our internal record format
 */
function transformSimTrafficResponse($stData, $callsign) {
    $record = [
        'callsign' => $callsign,
        'departure_afld' => $stData['departure_afld'] ?? null,
        'arrival_afld' => $stData['arrival_afld'] ?? null,
        'departure' => $stData['departure'] ?? [],
        'arrival' => $stData['arrival'] ?? [],
        'status' => $stData['status'] ?? []
    ];

    return $record;
}

/**
 * Process a single SimTraffic flight update
 *
 * @param resource $conn Database connection
 * @param array $record Flight record
 * @param string $source Source identifier
 * @return array Result with status and details
 */
function processSimTrafficFlight($conn, $record, $source) {
    // Validate required fields
    if (empty($record['callsign']) && empty($record['gufi'])) {
        throw new Exception('Missing required field: callsign or gufi');
    }

    $callsign = strtoupper(trim($record['callsign'] ?? ''));
    $gufi = trim($record['gufi'] ?? '');
    $dept_icao = strtoupper(trim($record['departure_afld'] ?? ''));
    $dest_icao = strtoupper(trim($record['arrival_afld'] ?? ''));

    // Look up flight - prefer GUFI if provided
    if (!empty($gufi)) {
        $lookup_sql = "SELECT flight_uid, callsign, fp_dept_icao, fp_dest_icao
                       FROM dbo.swim_flights
                       WHERE gufi = ? AND is_active = 1";
        $params = [$gufi];
    } elseif (!empty($dest_icao)) {
        // Look up by callsign and destination
        $lookup_sql = "SELECT TOP 1 flight_uid, callsign, fp_dept_icao, fp_dest_icao
                       FROM dbo.swim_flights
                       WHERE callsign = ? AND fp_dest_icao = ? AND is_active = 1
                       ORDER BY last_sync_utc DESC";
        $params = [$callsign, $dest_icao];
    } else {
        // Look up by callsign only (most recent)
        $lookup_sql = "SELECT TOP 1 flight_uid, callsign, fp_dept_icao, fp_dest_icao
                       FROM dbo.swim_flights
                       WHERE callsign = ? AND is_active = 1
                       ORDER BY last_sync_utc DESC";
        $params = [$callsign];
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

    // Extract departure, arrival, and status data
    $departure = $record['departure'] ?? [];
    $arrival = $record['arrival'] ?? [];
    $status = $record['status'] ?? [];

    // Build UPDATE statement
    $updates = [];
    $update_params = [];

    // === DEPARTURE TIMES (dual-write: legacy + FIXM columns) ===

    // push_time -> out_utc + actual_off_block_time (AOBT)
    if (!empty($departure['push_time'])) {
        // Legacy column
        $updates[] = 'out_utc = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $departure['push_time'];
        // FIXM column
        $updates[] = 'actual_off_block_time = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $departure['push_time'];
    }

    // taxi_time -> taxi_time_utc + taxi_start_time
    if (!empty($departure['taxi_time'])) {
        $updates[] = 'taxi_time_utc = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $departure['taxi_time'];
        $updates[] = 'taxi_start_time = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $departure['taxi_time'];
    }

    // sequence_time -> sequence_time_utc + departure_sequence_time
    if (!empty($departure['sequence_time'])) {
        $updates[] = 'sequence_time_utc = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $departure['sequence_time'];
        $updates[] = 'departure_sequence_time = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $departure['sequence_time'];
    }

    // holdshort_time -> holdshort_time_utc + hold_short_time
    if (!empty($departure['holdshort_time'])) {
        $updates[] = 'holdshort_time_utc = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $departure['holdshort_time'];
        $updates[] = 'hold_short_time = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $departure['holdshort_time'];
    }

    // runway_time -> runway_time_utc + runway_entry_time
    if (!empty($departure['runway_time'])) {
        $updates[] = 'runway_time_utc = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $departure['runway_time'];
        $updates[] = 'runway_entry_time = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $departure['runway_time'];
    }

    // takeoff_time -> off_utc + actual_time_of_departure (ATOT)
    if (!empty($departure['takeoff_time'])) {
        $updates[] = 'off_utc = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $departure['takeoff_time'];
        $updates[] = 'actual_time_of_departure = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $departure['takeoff_time'];
    }

    // edct -> edct_utc (no FIXM change - already aligned)
    if (!empty($departure['edct'])) {
        $updates[] = 'edct_utc = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $departure['edct'];
    }

    // === ARRIVAL TIMES (dual-write: legacy + FIXM columns) ===

    // eta -> eta_utc + estimated_time_of_arrival, eta_runway_utc + estimated_runway_arrival_time
    if (!empty($arrival['eta'])) {
        // Legacy columns
        $updates[] = 'eta_utc = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $arrival['eta'];
        $updates[] = 'eta_runway_utc = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $arrival['eta'];
        // FIXM columns
        $updates[] = 'estimated_time_of_arrival = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $arrival['eta'];
        $updates[] = 'estimated_runway_arrival_time = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $arrival['eta'];
    }

    // eta_mf / mft -> metering_time (STA at meter fix)
    $eta_mf = $arrival['eta_mf'] ?? $arrival['etaMF'] ?? $arrival['mft'] ?? $arrival['MFT'] ?? null;
    if (!empty($eta_mf)) {
        $updates[] = 'metering_time = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $eta_mf;
    }

    // eta_vertex / vt -> eta_vertex
    $eta_vertex = $arrival['eta_vertex'] ?? $arrival['eta_vt'] ?? $arrival['vt'] ?? $arrival['vertex_time'] ?? null;
    if (!empty($eta_vertex)) {
        $updates[] = 'eta_vertex = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $eta_vertex;
    }

    // on_time -> on_utc/in_utc + actual_landing_time/actual_in_block_time (ALDT/AIBT)
    $on_time = $arrival['on_time'] ?? $arrival['on_utc'] ?? null;
    if (!empty($on_time)) {
        // Legacy columns
        $updates[] = 'on_utc = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $on_time;
        $updates[] = 'in_utc = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $on_time;
        // FIXM columns
        $updates[] = 'actual_landing_time = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $on_time;
        $updates[] = 'actual_in_block_time = TRY_CONVERT(datetime2, ?)';
        $update_params[] = $on_time;
    }

    // metering_fix -> metering_point
    $metering_fix = $arrival['metering_fix'] ?? $arrival['meter_fix'] ?? null;
    if (!empty($metering_fix)) {
        $updates[] = 'metering_point = ?';
        $update_params[] = strtoupper(trim($metering_fix));
    }

    // rwy_assigned -> arr_runway
    $rwy = $arrival['rwy_assigned'] ?? $arrival['runway'] ?? null;
    if (!empty($rwy)) {
        $updates[] = 'arr_runway = ?';
        $update_params[] = strtoupper(trim($rwy));
    }

    // === STATUS ===

    // Determine phase from status flags
    $phase = null;
    if (!empty($status['arrived']) || !empty($arrival['arrived'])) {
        $phase = 'arrived';
    } elseif (!empty($status['departed']) || !empty($departure['takeoff_time'])) {
        $phase = 'enroute';
    } elseif (!empty($departure['taxi_time']) || !empty($departure['push_time'])) {
        $phase = 'taxiing';
    }

    if ($phase) {
        $updates[] = 'phase = ?';
        $update_params[] = $phase;
        $updates[] = 'simtraffic_phase = ?';
        $update_params[] = $phase;
    }

    // in_artcc -> current_artcc
    if (!empty($status['in_artcc'])) {
        $updates[] = 'current_artcc = ?';
        $update_params[] = strtoupper(trim($status['in_artcc']));
    }

    // delay_value -> metering_delay
    if (isset($status['delay_value'])) {
        $updates[] = 'metering_delay = ?';
        $update_params[] = intval($status['delay_value']);
    }

    // === TRACKING FIELDS ===

    // Always update source tracking
    $updates[] = 'metering_source = ?';
    $update_params[] = $source;

    $updates[] = 'simtraffic_sync_utc = GETUTCDATE()';
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
        throw new Exception('Failed to update SimTraffic data: ' . ($err[0]['message'] ?? 'Unknown error'));
    }

    $rows = sqlsrv_rows_affected($stmt);
    sqlsrv_free_stmt($stmt);

    if ($rows > 0) {
        return [
            'status' => 'updated',
            'callsign' => $flight['callsign'],
            'flight_uid' => $flight['flight_uid'],
            'phase' => $phase
        ];
    }

    return ['status' => 'no_changes', 'callsign' => $callsign];
}
