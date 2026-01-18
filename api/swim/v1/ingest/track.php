<?php
/**
 * VATSWIM API v1 - Track Data Ingest Endpoint
 * 
 * Receives real-time track data from authoritative sources (vNAS, CRC, EuroScope, AOC).
 * Updates position and track information in the swim_flights table.
 * 
 * @version 1.2.0 - Fixed database connection, removed true_airspeed (not in schema)
 * @since 2026-01-16
 * 
 * Expected payload:
 * {
 *   "tracks": [
 *     {
 *       "callsign": "UAL123",           // Required
 *       "latitude": 40.6413,            // Required
 *       "longitude": -73.7781,          // Required
 *       "altitude_ft": 35000,           // Optional (feet MSL)
 *       "ground_speed_kts": 450,        // Optional (knots)
 *       "heading_deg": 270,             // Optional (0-360 magnetic)
 *       "vertical_rate_fpm": -500,      // Optional (feet per minute, + = climb, - = descend)
 *       "squawk": "1200",               // Optional (transponder code)
 *       "track_source": "radar",        // Optional (radar|ads-b|mlat|mode-s|acars)
 *       "timestamp": "2026-01-16T12:00:00Z"  // Optional (ISO 8601)
 *     }
 *   ]
 * }
 */

require_once __DIR__ . '/../auth.php';

// Use SWIM_API database for all operations
global $conn_swim;

if (!$conn_swim) {
    SwimResponse::error('SWIM database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

// Require authentication with write access
$auth = swim_init_auth(true, true);

// Validate source can write track data
if (!$auth->canWriteField('track')) {
    SwimResponse::error(
        'Source "' . $auth->getSourceId() . '" is not authorized to write track data.',
        403, 
        'NOT_AUTHORITATIVE'
    );
}

// Get request body
$body = swim_get_json_body();
if (!$body) {
    SwimResponse::error('Request body is required', 400, 'MISSING_BODY');
}

if (!isset($body['tracks']) || !is_array($body['tracks'])) {
    SwimResponse::error('Request must contain a "tracks" array', 400, 'MISSING_TRACKS');
}

$tracks = $body['tracks'];
$max_batch = 1000;  // Higher limit for track data (frequent updates)

if (count($tracks) > $max_batch) {
    SwimResponse::error("Batch size exceeded. Maximum {$max_batch} tracks per request.", 400, 'BATCH_TOO_LARGE');
}

$processed = 0;
$updated = 0;
$not_found = 0;
$errors = [];

foreach ($tracks as $index => $track) {
    try {
        $result = processTrackUpdate($track, $auth->getSourceId(), $conn_swim);
        if ($result['status'] === 'updated') {
            $updated++;
        } elseif ($result['status'] === 'not_found') {
            $not_found++;
        }
        $processed++;
    } catch (Exception $e) {
        $errors[] = [
            'index' => $index,
            'callsign' => $track['callsign'] ?? 'unknown',
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
    'batch_size' => count($tracks)
]);

/**
 * Process a single track update
 */
function processTrackUpdate($track, $source, $conn) {
    // Validate required fields
    if (empty($track['callsign'])) {
        throw new Exception('Missing required field: callsign');
    }
    if (!isset($track['latitude']) || !isset($track['longitude'])) {
        throw new Exception('Missing required fields: latitude, longitude');
    }
    
    $callsign = strtoupper(trim($track['callsign']));
    $lat = floatval($track['latitude']);
    $lon = floatval($track['longitude']);
    
    // Validate coordinate ranges
    if ($lat < -90 || $lat > 90) {
        throw new Exception('Invalid latitude: must be between -90 and 90');
    }
    if ($lon < -180 || $lon > 180) {
        throw new Exception('Invalid longitude: must be between -180 and 180');
    }
    
    // Look up flight by callsign (most recent active flight) in swim_flights
    $check_sql = "SELECT TOP 1 flight_uid, gufi 
                  FROM dbo.swim_flights 
                  WHERE callsign = ? AND is_active = 1
                  ORDER BY last_seen_utc DESC";
    
    $check_stmt = sqlsrv_query($conn, $check_sql, [$callsign]);
    if ($check_stmt === false) {
        throw new Exception('Database error looking up flight');
    }
    
    $existing = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($check_stmt);
    
    if (!$existing) {
        // Flight not found - log for debugging but don't fail
        return ['status' => 'not_found', 'callsign' => $callsign];
    }
    
    // Build update - only columns that exist in schema
    $update_sql = "UPDATE dbo.swim_flights
                   SET lat = ?,
                       lon = ?,
                       altitude_ft = COALESCE(?, altitude_ft),
                       groundspeed_kts = COALESCE(?, groundspeed_kts),
                       heading_deg = COALESCE(?, heading_deg),
                       vertical_rate_fpm = COALESCE(?, vertical_rate_fpm),
                       last_seen_utc = GETUTCDATE(),
                       last_sync_utc = GETUTCDATE()
                   WHERE flight_uid = ?";
    
    $params = [
        $lat,
        $lon,
        isset($track['altitude_ft']) ? intval($track['altitude_ft']) : null,
        isset($track['ground_speed_kts']) ? intval($track['ground_speed_kts']) : null,
        isset($track['heading_deg']) ? intval($track['heading_deg']) : null,
        isset($track['vertical_rate_fpm']) ? intval($track['vertical_rate_fpm']) : null,
        $existing['flight_uid']
    ];
    
    $stmt = sqlsrv_query($conn, $update_sql, $params);
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        throw new Exception('Failed to update track: ' . ($errors[0]['message'] ?? 'Unknown'));
    }
    sqlsrv_free_stmt($stmt);
    
    return ['status' => 'updated', 'gufi' => $existing['gufi']];
}
