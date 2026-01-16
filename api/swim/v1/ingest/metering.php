<?php
/**
 * VATSIM SWIM API v1 - Metering Data Ingest Endpoint
 * 
 * Receives TBFM-style metering data from authoritative sources (SimTraffic, vATCSCC).
 * Updates Scheduled Times of Arrival (STA), sequences, and metering status.
 * 
 * @version 1.0.0
 * @since 2026-01-16
 * 
 * Expected payload:
 * {
 *   "airport": "KJFK",                    // Required - airport receiving traffic
 *   "meter_reference_element": "CAMRN",   // Optional - meter fix/arc
 *   "metering": [
 *     {
 *       "callsign": "UAL123",              // Required
 *       "sequence": 5,                     // Optional - arrival sequence number
 *       "sta_utc": "2026-01-16T18:30:00Z", // Optional - Scheduled Time of Arrival
 *       "eta_runway_utc": "2026-01-16T18:28:00Z", // Optional - ETA to runway threshold
 *       "sta_meter_fix_utc": "2026-01-16T18:15:00Z", // Optional - STA at meter fix
 *       "delay_minutes": 5,                // Optional - delay from unimpeded
 *       "frozen": true,                    // Optional - sequence frozen
 *       "runway": "31L",                   // Optional - assigned runway
 *       "gate": "NORTH",                   // Optional - gate/stream assignment
 *       "status": "METERED"                // Optional (UNMETERED|METERED|FROZEN|SUSPENDED)
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
        'Source "' . $auth->getSourceId() . '" is not authorized to write metering data.',
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

// Extract optional context fields
$airport = strtoupper(trim($body['airport'] ?? ''));
$meter_reference_element = strtoupper(trim($body['meter_reference_element'] ?? ''));

$metering = $body['metering'];
$max_batch = 500;

if (count($metering) > $max_batch) {
    SwimResponse::error("Batch size exceeded. Maximum {$max_batch} metering records per request.", 400, 'BATCH_TOO_LARGE');
}

$processed = 0;
$updated = 0;
$not_found = 0;
$errors = [];

foreach ($metering as $index => $record) {
    try {
        $result = processMeteringUpdate($record, $auth->getSourceId(), $airport, $meter_reference_element);
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
    'source' => $auth->getSourceId(),
    'airport' => $airport,
    'meter_reference_element' => $meter_reference_element,
    'batch_size' => count($metering)
]);

/**
 * Process a single metering update
 */
function processMeteringUpdate($record, $source, $airport, $mre) {
    global $conn_adl;
    
    // Validate required fields
    if (empty($record['callsign'])) {
        throw new Exception('Missing required field: callsign');
    }
    
    $callsign = strtoupper(trim($record['callsign']));
    
    // Look up flight by callsign
    // If airport is provided, filter by destination
    $check_sql = "SELECT TOP 1 id, gufi, flight_key, dest_icao
                  FROM dbo.swim_flight_cache 
                  WHERE callsign = ? AND status = 'active'";
    $params = [$callsign];
    
    if ($airport) {
        $check_sql .= " AND dest_icao = ?";
        $params[] = $airport;
    }
    
    $check_sql .= " ORDER BY created_at DESC";
    
    $check_stmt = sqlsrv_query($conn_adl, $check_sql, $params);
    if ($check_stmt === false) {
        throw new Exception('Database error looking up flight');
    }
    
    $existing = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($check_stmt);
    
    if (!$existing) {
        // Flight not found
        return ['status' => 'not_found', 'callsign' => $callsign];
    }
    
    // Build metering data object
    $metering_data = [
        'airport' => $airport ?: $existing['dest_icao'],
        'meter_reference_element' => $mre ?: null,
        'sequence' => isset($record['sequence']) ? intval($record['sequence']) : null,
        'sta_utc' => $record['sta_utc'] ?? null,
        'eta_runway_utc' => $record['eta_runway_utc'] ?? null,
        'sta_meter_fix_utc' => $record['sta_meter_fix_utc'] ?? null,
        'delay_minutes' => isset($record['delay_minutes']) ? intval($record['delay_minutes']) : null,
        'frozen' => isset($record['frozen']) ? (bool)$record['frozen'] : false,
        'runway' => $record['runway'] ?? null,
        'gate' => $record['gate'] ?? null,
        'status' => strtoupper($record['status'] ?? 'METERED'),
        '_source' => $source,
        '_updated' => gmdate('c')
    ];
    
    $metering_json = json_encode($metering_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    // Update swim_flight_cache with metering timestamp
    $update_sql = "UPDATE dbo.swim_flight_cache 
                   SET metering_updated_at = GETUTCDATE(),
                       updated_at = GETUTCDATE(),
                       version = version + 1
                   WHERE id = ?";
    
    $stmt = sqlsrv_query($conn_adl, $update_sql, [$existing['id']]);
    if ($stmt === false) {
        throw new Exception('Failed to update metering data');
    }
    sqlsrv_free_stmt($stmt);
    
    // Also update the denormalized swim_flights table if it exists
    $swim_update_sql = "UPDATE dbo.swim_flights
                        SET eta_runway_utc = COALESCE(
                                TRY_CONVERT(datetime2, ?), 
                                eta_runway_utc
                            ),
                            arr_runway = COALESCE(?, arr_runway),
                            last_sync_utc = GETUTCDATE()
                        WHERE callsign = ? AND is_active = 1";
    
    $params = [
        $record['eta_runway_utc'] ?? null,
        $record['runway'] ?? null,
        $callsign
    ];
    
    $swim_stmt = sqlsrv_query($conn_adl, $swim_update_sql, $params);
    // Don't fail if swim_flights table doesn't exist yet
    if ($swim_stmt) {
        sqlsrv_free_stmt($swim_stmt);
    }
    
    // If STA is provided, consider updating TMI tables as well (future enhancement)
    // For now, the metering data is primarily used for visualization/display
    
    return ['status' => 'updated', 'gufi' => $existing['gufi']];
}

/**
 * Validate metering status value
 */
function validateMeteringStatus($status) {
    $valid = ['UNMETERED', 'METERED', 'FROZEN', 'SUSPENDED', 'EXEMPT'];
    return in_array(strtoupper($status), $valid);
}
