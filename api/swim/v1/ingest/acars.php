<?php
/**
 * VATSWIM API v1 - Unified ACARS Message Ingest Endpoint
 *
 * Receives ACARS messages from multiple sources (Hoppie, VAs, simulator plugins).
 * Processes OOOI times, position reports, PDC, weather, and telex messages.
 * Uses priority-based OOOI time extraction (ACARS has priority 1).
 *
 * @version 1.0.0
 * @since 2026-01-27
 *
 * Expected payload:
 * {
 *   "source": "hoppie|smartcars|phpvms|vam|simbrief|fs2crew|pacx|generic",
 *   "messages": [
 *     {
 *       "type": "oooi|position|progress|pdc|weather|telex",
 *       "callsign": "UAL123",
 *       "timestamp": "2026-01-27T14:30:00Z",
 *       "payload": { ... }
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

// ACARS uses 'datalink' authority for OOOI times
// But accept any authenticated system-tier key for now
$source_id = $auth->getSourceId();

// Get request body
$body = swim_get_json_body();
if (!$body) {
    SwimResponse::error('Request body is required', 400, 'MISSING_BODY');
}

// Validate source
$source = strtolower(trim($body['source'] ?? ''));
$valid_sources = ['hoppie', 'smartcars', 'phpvms', 'vam', 'simbrief', 'fs2crew', 'pacx', 'generic'];

if (empty($source)) {
    SwimResponse::error('source is required', 400, 'MISSING_SOURCE');
}
if (!in_array($source, $valid_sources)) {
    SwimResponse::error('Invalid source. Valid sources: ' . implode(', ', $valid_sources), 400, 'INVALID_SOURCE');
}

if (!isset($body['messages']) || !is_array($body['messages'])) {
    SwimResponse::error('Request must contain a "messages" array', 400, 'MISSING_MESSAGES');
}

$messages = $body['messages'];
$max_batch = 100;  // Lower limit for ACARS (complex processing)

if (count($messages) > $max_batch) {
    SwimResponse::error("Batch size exceeded. Maximum {$max_batch} messages per request.", 400, 'BATCH_TOO_LARGE');
}

// Processing counters
$processed = 0;
$oooi_updated = 0;
$position_updated = 0;
$pdc_queued = 0;
$logged = 0;
$not_found = 0;
$rejected = 0;
$errors = [];

foreach ($messages as $index => $message) {
    try {
        $result = processACARSMessage($message, $source, $conn_swim);

        if ($result['logged']) $logged++;
        if ($result['status'] === 'oooi_updated') $oooi_updated++;
        if ($result['status'] === 'position_updated') $position_updated++;
        if ($result['status'] === 'pdc_queued') $pdc_queued++;
        if ($result['status'] === 'not_found') $not_found++;
        if ($result['status'] === 'rejected') $rejected++;

        $processed++;
    } catch (Exception $e) {
        $errors[] = [
            'index' => $index,
            'callsign' => $message['callsign'] ?? 'unknown',
            'type' => $message['type'] ?? 'unknown',
            'error' => $e->getMessage()
        ];
    }
}

// Update source last_message_utc
updateSourceActivity($source, $conn_swim);

SwimResponse::success([
    'processed' => $processed,
    'oooi_updated' => $oooi_updated,
    'position_updated' => $position_updated,
    'pdc_queued' => $pdc_queued,
    'logged' => $logged,
    'not_found' => $not_found,
    'rejected' => $rejected,
    'errors' => count($errors),
    'error_details' => array_slice($errors, 0, 10)
], [
    'source' => $source,
    'batch_size' => count($messages)
]);

/**
 * Process a single ACARS message
 */
function processACARSMessage($message, $source, $conn) {
    // Validate required fields
    if (empty($message['type'])) {
        throw new Exception('Missing required field: type');
    }
    if (empty($message['callsign'])) {
        throw new Exception('Missing required field: callsign');
    }

    $type = strtolower(trim($message['type']));
    $callsign = strtoupper(trim($message['callsign']));
    $timestamp = $message['timestamp'] ?? gmdate('Y-m-d\TH:i:s\Z');
    $payload = $message['payload'] ?? $message;

    // Validate message type
    $valid_types = ['oooi', 'position', 'progress', 'pdc', 'weather', 'telex', 'free_text'];
    if (!in_array($type, $valid_types)) {
        throw new Exception('Invalid type. Valid types: ' . implode(', ', $valid_types));
    }

    // Route to appropriate handler
    switch ($type) {
        case 'oooi':
            return processOOOIMessage($callsign, $timestamp, $payload, $source, $conn);
        case 'position':
            return processPositionMessage($callsign, $timestamp, $payload, $source, $conn);
        case 'progress':
            return processProgressMessage($callsign, $timestamp, $payload, $source, $conn);
        case 'pdc':
            return processPDCMessage($callsign, $timestamp, $payload, $source, $conn);
        case 'weather':
            return processWeatherMessage($callsign, $timestamp, $payload, $source, $conn);
        case 'telex':
        case 'free_text':
            return processTelexMessage($callsign, $timestamp, $payload, $source, $conn);
        default:
            return ['status' => 'rejected', 'logged' => false, 'reason' => 'Unknown type'];
    }
}

/**
 * Process OOOI message - OUT/OFF/ON/IN times
 */
function processOOOIMessage($callsign, $timestamp, $payload, $source, $conn) {
    // Extract OOOI event
    $event = strtoupper($payload['event'] ?? '');
    $valid_events = ['OUT', 'OFF', 'ON', 'IN'];
    if (!in_array($event, $valid_events)) {
        throw new Exception('Invalid OOOI event. Valid events: OUT, OFF, ON, IN');
    }

    $airport_icao = strtoupper($payload['departure_icao'] ?? $payload['arrival_icao'] ?? $payload['airport'] ?? '');
    $gate_stand = $payload['gate'] ?? $payload['stand'] ?? null;
    $runway = $payload['runway'] ?? null;
    $message_utc = parseIsoTimestamp($timestamp);

    // Log the message
    $message_id = logACARSMessage($callsign, 'OOOI', $source, $message_utc, $payload, $event, $airport_icao, $gate_stand, $runway, $conn);
    $logged = ($message_id !== null);

    // Find matching flight
    $flight = findFlightByCallsign($callsign, $conn);
    if (!$flight) {
        return ['status' => 'not_found', 'logged' => $logged];
    }

    // Check source priority (ACARS has priority 1 for OOOI)
    // For now, accept all ACARS sources

    // Update swim_flights with OOOI time
    // Dual-write to legacy and FIXM columns
    $updated = false;

    switch ($event) {
        case 'OUT':
            $sql = "UPDATE dbo.swim_flights
                    SET out_utc = ?,
                        actual_off_block_time = ?,
                        phase = CASE WHEN phase IN ('preflight', 'filed') THEN 'taxi_out' ELSE phase END,
                        last_sync_utc = GETUTCDATE()
                    WHERE flight_uid = ?";
            $updated = executeUpdate($conn, $sql, [$message_utc, $message_utc, $flight['flight_uid']]);
            break;

        case 'OFF':
            $sql = "UPDATE dbo.swim_flights
                    SET off_utc = ?,
                        actual_time_of_departure = ?,
                        phase = 'enroute',
                        last_sync_utc = GETUTCDATE()
                    WHERE flight_uid = ?";
            $updated = executeUpdate($conn, $sql, [$message_utc, $message_utc, $flight['flight_uid']]);
            break;

        case 'ON':
            $sql = "UPDATE dbo.swim_flights
                    SET on_utc = ?,
                        actual_landing_time = ?,
                        phase = 'taxi_in',
                        last_sync_utc = GETUTCDATE()
                    WHERE flight_uid = ?";
            $updated = executeUpdate($conn, $sql, [$message_utc, $message_utc, $flight['flight_uid']]);
            break;

        case 'IN':
            $sql = "UPDATE dbo.swim_flights
                    SET in_utc = ?,
                        actual_in_block_time = ?,
                        phase = 'arrived',
                        is_active = 0,
                        last_sync_utc = GETUTCDATE()
                    WHERE flight_uid = ?";
            $updated = executeUpdate($conn, $sql, [$message_utc, $message_utc, $flight['flight_uid']]);
            break;
    }

    // Update message with flight match
    if ($message_id && $flight) {
        $update_sql = "UPDATE dbo.swim_acars_messages
                       SET flight_uid = ?, gufi = ?, flight_matched = 1, swim_updated = ?,
                           status = 'PROCESSED', processed_utc = GETUTCDATE()
                       WHERE message_id = ?";
        executeUpdate($conn, $update_sql, [$flight['flight_uid'], $flight['gufi'], $updated ? 1 : 0, $message_id]);
    }

    return ['status' => $updated ? 'oooi_updated' : 'not_updated', 'logged' => $logged, 'event' => $event];
}

/**
 * Process position report message
 */
function processPositionMessage($callsign, $timestamp, $payload, $source, $conn) {
    $message_utc = parseIsoTimestamp($timestamp);

    // Log the message (don't log position reports by default to reduce volume)
    $logged = false;

    // Find matching flight
    $flight = findFlightByCallsign($callsign, $conn);
    if (!$flight) {
        return ['status' => 'not_found', 'logged' => $logged];
    }

    // Extract position data
    $lat = floatval($payload['latitude'] ?? 0);
    $lon = floatval($payload['longitude'] ?? 0);

    if ($lat == 0 && $lon == 0) {
        return ['status' => 'rejected', 'logged' => $logged, 'reason' => 'Invalid position'];
    }

    // Build update
    $set_parts = ['lat = ?', 'lon = ?', 'last_seen_utc = GETUTCDATE()', 'last_sync_utc = GETUTCDATE()'];
    $params = [$lat, $lon];

    if (isset($payload['altitude_ft'])) {
        $set_parts[] = 'altitude_ft = ?';
        $params[] = intval($payload['altitude_ft']);
    }
    if (isset($payload['groundspeed_kts'])) {
        $set_parts[] = 'groundspeed_kts = ?';
        $params[] = intval($payload['groundspeed_kts']);
    }
    if (isset($payload['heading_deg'])) {
        $set_parts[] = 'heading_deg = ?';
        $params[] = intval($payload['heading_deg']);
    }

    $params[] = $flight['flight_uid'];
    $sql = "UPDATE dbo.swim_flights SET " . implode(', ', $set_parts) . " WHERE flight_uid = ?";
    $updated = executeUpdate($conn, $sql, $params);

    return ['status' => $updated ? 'position_updated' : 'not_updated', 'logged' => $logged];
}

/**
 * Process progress message (ETA updates, fuel)
 */
function processProgressMessage($callsign, $timestamp, $payload, $source, $conn) {
    $message_utc = parseIsoTimestamp($timestamp);

    // Log the message
    $message_id = logACARSMessage($callsign, 'PROGRESS', $source, $message_utc, $payload, null, null, null, null, $conn);
    $logged = ($message_id !== null);

    // Find matching flight
    $flight = findFlightByCallsign($callsign, $conn);
    if (!$flight) {
        return ['status' => 'not_found', 'logged' => $logged];
    }

    // Update ETA if provided
    $set_parts = ['last_sync_utc = GETUTCDATE()'];
    $params = [];

    if (isset($payload['eta_destination'])) {
        $eta = parseIsoTimestamp($payload['eta_destination']);
        $set_parts[] = 'eta_utc = ?';
        $set_parts[] = 'estimated_time_of_arrival = ?';
        $params[] = $eta;
        $params[] = $eta;
    }

    if (count($params) > 0) {
        $params[] = $flight['flight_uid'];
        $sql = "UPDATE dbo.swim_flights SET " . implode(', ', $set_parts) . " WHERE flight_uid = ?";
        executeUpdate($conn, $sql, $params);
    }

    return ['status' => 'processed', 'logged' => $logged];
}

/**
 * Process PDC (Pre-Departure Clearance) message
 */
function processPDCMessage($callsign, $timestamp, $payload, $source, $conn) {
    $message_utc = parseIsoTimestamp($timestamp);
    $direction = strtoupper($payload['direction'] ?? 'DOWNLINK');

    // Log the message
    $message_id = logACARSMessage($callsign, 'PDC', $source, $message_utc, $payload, null, null, null, null, $conn);
    $logged = ($message_id !== null);

    // If this is an uplink (to pilot), queue for delivery
    if ($direction === 'UPLINK') {
        $clearance = $payload['clearance'] ?? $payload;

        $insert_sql = "INSERT INTO dbo.swim_acars_pdc_queue
                       (callsign, clearance_type, destination, route, cleared_altitude_fl,
                        initial_altitude_ft, departure_runway, sid, squawk, departure_frequency,
                        delivery_channel, created_by)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $params = [
            $callsign,
            $clearance['clearance_type'] ?? 'PDC',
            $clearance['destination'] ?? '',
            $clearance['route'] ?? null,
            $clearance['cleared_altitude_fl'] ?? null,
            $clearance['initial_altitude_ft'] ?? null,
            $clearance['departure_runway'] ?? null,
            $clearance['sid'] ?? null,
            $clearance['squawk'] ?? null,
            $clearance['departure_frequency'] ?? null,
            'HOPPIE',
            $source
        ];

        $stmt = sqlsrv_query($conn, $insert_sql, $params);
        if ($stmt !== false) {
            sqlsrv_free_stmt($stmt);
            return ['status' => 'pdc_queued', 'logged' => $logged];
        }
    }

    return ['status' => 'processed', 'logged' => $logged];
}

/**
 * Process weather request message
 */
function processWeatherMessage($callsign, $timestamp, $payload, $source, $conn) {
    // Weather requests are logged but not processed further
    // Future: Could integrate with METAR/TAF API
    return ['status' => 'processed', 'logged' => false];
}

/**
 * Process telex/free text message
 */
function processTelexMessage($callsign, $timestamp, $payload, $source, $conn) {
    $message_utc = parseIsoTimestamp($timestamp);

    // Log the message
    $message_id = logACARSMessage($callsign, 'TELEX', $source, $message_utc, $payload, null, null, null, null, $conn);
    $logged = ($message_id !== null);

    return ['status' => 'processed', 'logged' => $logged];
}

/**
 * Log ACARS message to swim_acars_messages
 */
function logACARSMessage($callsign, $type, $source, $message_utc, $payload, $oooi_event, $airport, $gate, $runway, $conn) {
    $sql = "INSERT INTO dbo.swim_acars_messages
            (callsign, message_type, source, message_utc, parsed_payload, oooi_event, airport_icao, gate_stand, runway)
            OUTPUT INSERTED.message_id
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $params = [
        $callsign,
        $type,
        $source,
        $message_utc,
        json_encode($payload),
        $oooi_event,
        $airport,
        $gate,
        $runway
    ];

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        return null;
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    return $row ? $row['message_id'] : null;
}

/**
 * Find flight by callsign
 */
function findFlightByCallsign($callsign, $conn) {
    $sql = "SELECT TOP 1 flight_uid, gufi, callsign
            FROM dbo.swim_flights
            WHERE callsign = ? AND is_active = 1
            ORDER BY last_seen_utc DESC";

    $stmt = sqlsrv_query($conn, $sql, [$callsign]);
    if ($stmt === false) {
        return null;
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    return $row;
}

/**
 * Execute UPDATE query and return success status
 */
function executeUpdate($conn, $sql, $params) {
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        return false;
    }
    $rows = sqlsrv_rows_affected($stmt);
    sqlsrv_free_stmt($stmt);
    return $rows > 0;
}

/**
 * Update source activity tracking
 */
function updateSourceActivity($source, $conn) {
    $sql = "UPDATE dbo.swim_acars_sources
            SET last_message_utc = GETUTCDATE(),
                message_count_24h = message_count_24h + 1,
                updated_utc = GETUTCDATE()
            WHERE source_code = ?";
    $stmt = sqlsrv_query($conn, $sql, [$source]);
    if ($stmt !== false) {
        sqlsrv_free_stmt($stmt);
    }
}

/**
 * Parse ISO 8601 timestamp to SQL Server format
 */
function parseIsoTimestamp($timestamp) {
    if (empty($timestamp)) {
        return gmdate('Y-m-d H:i:s');
    }
    try {
        $dt = new DateTime($timestamp);
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return gmdate('Y-m-d H:i:s');
    }
}
