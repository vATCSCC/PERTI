<?php
/**
 * VATSWIM API v1 - vNAS Track/Surveillance Data Ingest Endpoint
 *
 * Receives real-time track/surveillance data from vNAS (ERAM/STARS) systems.
 * Updates position, track quality, and surveillance data in the swim_flights table.
 *
 * @version 1.0.0
 * @since 2026-01-27
 *
 * Expected payload:
 * {
 *   "facility_id": "ZDC",           // Required - Source facility
 *   "system_type": "ERAM",          // Required - ERAM or STARS
 *   "timestamp": "2026-01-27T15:30:00.000Z",  // Optional - Batch timestamp
 *   "tracks": [
 *     {
 *       "callsign": "UAL123",                // Required
 *       "gufi": "VAT-20260127-...",          // Optional - Direct GUFI lookup
 *       "beacon_code": "1234",               // Optional - Mode A/3 squawk
 *       "position": {
 *         "latitude": 40.6413,               // Required
 *         "longitude": -73.7781,             // Required
 *         "altitude_ft": 35000,              // Optional
 *         "altitude_type": "barometric",     // Optional - barometric|geometric
 *         "ground_speed_kts": 450,           // Optional
 *         "track_deg": 270,                  // Optional - True track
 *         "vertical_rate_fpm": -500          // Optional
 *       },
 *       "track_quality": {
 *         "source": "radar",                 // Optional - radar|ads-b|mlat|mode-s
 *         "mode_c": true,                    // Optional - Mode C validity
 *         "mode_s": true,                    // Optional - Mode S validity
 *         "ads_b": false,                    // Optional - ADS-B equipped
 *         "position_quality": 9              // Optional - Quality 0-9
 *       },
 *       "timestamp": "2026-01-27T15:30:00.000Z"  // Optional - Track timestamp
 *     }
 *   ]
 * }
 */

require_once __DIR__ . '/../../auth.php';

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

// Validate facility context
$facility_id = strtoupper(trim($body['facility_id'] ?? ''));
$system_type = strtoupper(trim($body['system_type'] ?? ''));

if (empty($facility_id)) {
    SwimResponse::error('facility_id is required', 400, 'MISSING_FACILITY');
}

if (!empty($system_type) && !in_array($system_type, ['ERAM', 'STARS'])) {
    SwimResponse::error('system_type must be ERAM or STARS', 400, 'INVALID_SYSTEM_TYPE');
}

if (!isset($body['tracks']) || !is_array($body['tracks'])) {
    SwimResponse::error('Request must contain a "tracks" array', 400, 'MISSING_TRACKS');
}

$tracks = $body['tracks'];
$max_batch = 1000;  // High limit for track data (frequent updates)

if (count($tracks) > $max_batch) {
    SwimResponse::error("Batch size exceeded. Maximum {$max_batch} tracks per request.", 400, 'BATCH_TOO_LARGE');
}

$processed = 0;
$updated = 0;
$not_found = 0;
$errors = [];

foreach ($tracks as $index => $track) {
    try {
        $result = processVnasTrackUpdate($track, $facility_id, $system_type, $auth->getSourceId(), $conn_swim);
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
    'source' => 'vnas',
    'facility' => $facility_id,
    'system' => $system_type,
    'batch_size' => count($tracks)
]);

/**
 * Process a single vNAS track update with surveillance data
 */
function processVnasTrackUpdate($track, $facility_id, $system_type, $source, $conn) {
    // Validate required fields
    if (empty($track['callsign'])) {
        throw new Exception('Missing required field: callsign');
    }

    $position = $track['position'] ?? $track;

    if (!isset($position['latitude']) || !isset($position['longitude'])) {
        throw new Exception('Missing required fields: latitude, longitude');
    }

    $callsign = strtoupper(trim($track['callsign']));
    $gufi = $track['gufi'] ?? null;
    $lat = floatval($position['latitude']);
    $lon = floatval($position['longitude']);

    // Validate coordinate ranges
    if ($lat < -90 || $lat > 90) {
        throw new Exception('Invalid latitude: must be between -90 and 90');
    }
    if ($lon < -180 || $lon > 180) {
        throw new Exception('Invalid longitude: must be between -180 and 180');
    }

    // Look up flight - try GUFI first, then callsign
    $existing = null;

    if (!empty($gufi)) {
        $check_sql = "SELECT TOP 1 flight_uid, gufi
                      FROM dbo.swim_flights
                      WHERE gufi = ? AND is_active = 1";
        $check_stmt = sqlsrv_query($conn, $check_sql, [$gufi]);
        if ($check_stmt !== false) {
            $existing = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($check_stmt);
        }
    }

    if (!$existing) {
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
    }

    if (!$existing) {
        return ['status' => 'not_found', 'callsign' => $callsign];
    }

    // Extract track quality fields
    $quality = $track['track_quality'] ?? [];

    // Build dynamic UPDATE with only provided fields
    $set_clauses = [
        'lat = ?',
        'lon = ?',
        'last_seen_utc = GETUTCDATE()',
        'last_sync_utc = GETUTCDATE()',
        'vnas_sync_utc = GETUTCDATE()'
    ];
    $params = [$lat, $lon];

    // Position fields
    if (isset($position['altitude_ft'])) {
        $set_clauses[] = 'altitude_ft = ?';
        $params[] = intval($position['altitude_ft']);
    }
    if (isset($position['ground_speed_kts'])) {
        $set_clauses[] = 'groundspeed_kts = ?';
        $params[] = intval($position['ground_speed_kts']);
    }
    if (isset($position['track_deg'])) {
        $set_clauses[] = 'heading_deg = ?';
        $params[] = intval($position['track_deg']);
    }
    if (isset($position['vertical_rate_fpm'])) {
        $set_clauses[] = 'vertical_rate_fpm = ?';
        $params[] = intval($position['vertical_rate_fpm']);
    }

    // Beacon code
    if (isset($track['beacon_code'])) {
        $set_clauses[] = 'beacon_code = ?';
        $params[] = substr($track['beacon_code'], 0, 4);
    }

    // Track quality fields
    if (isset($quality['mode_c'])) {
        $set_clauses[] = 'mode_c_valid = ?';
        $params[] = $quality['mode_c'] ? 1 : 0;
    }
    if (isset($quality['mode_s'])) {
        $set_clauses[] = 'mode_s_valid = ?';
        $params[] = $quality['mode_s'] ? 1 : 0;
    }
    if (isset($quality['ads_b'])) {
        $set_clauses[] = 'ads_b_equipped = ?';
        $params[] = $quality['ads_b'] ? 1 : 0;
    }
    if (isset($quality['position_quality'])) {
        $set_clauses[] = 'track_quality = ?';
        $params[] = min(9, max(0, intval($quality['position_quality'])));
    }

    // vNAS source tracking
    if (!empty($facility_id)) {
        $set_clauses[] = 'vnas_source_facility = ?';
        $params[] = $facility_id;
    }
    if (!empty($system_type)) {
        $set_clauses[] = 'vnas_source_system = ?';
        $params[] = $system_type;
    }

    // Add flight_uid to params
    $params[] = $existing['flight_uid'];

    $update_sql = "UPDATE dbo.swim_flights SET " . implode(', ', $set_clauses) . " WHERE flight_uid = ?";

    $stmt = sqlsrv_query($conn, $update_sql, $params);
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        throw new Exception('Failed to update track: ' . ($errors[0]['message'] ?? 'Unknown'));
    }
    sqlsrv_free_stmt($stmt);

    return ['status' => 'updated', 'gufi' => $existing['gufi']];
}
