<?php
/**
 * VATSWIM API v1 - AMAN Arrival Sequence Ingest Endpoint
 *
 * Receives arrival sequence data from external AMAN (Arrival Manager) systems.
 * Resolves flight UIDs from swim_flights by callsign + destination airport,
 * and publishes the sequence to the aman.sequence WebSocket channel for
 * real-time consumption by EuroScope plugins and Pilot Portal clients.
 *
 * @version 1.0.0
 * @since 2026-03-30
 *
 * Expected payload:
 * {
 *   "airport": "KJFK",
 *   "runway": "22L",
 *   "sequence": [
 *     {
 *       "callsign": "DAL123",
 *       "sequence_number": 1,
 *       "eta_utc": "2026-03-30T14:30:00Z",
 *       "sta_utc": "2026-03-30T14:32:00Z",
 *       "delay_seconds": 120,
 *       "fix": "CAMRN",
 *       "speed_restriction": 210,
 *       "status": "FROZEN"
 *     }
 *   ],
 *   "source": "PERTI_AMAN"
 * }
 *
 * Valid status values: COMPUTED, TENTATIVE, FROZEN, LANDED, CANCELLED
 */

require_once __DIR__ . '/../auth.php';

// Require authentication with write access
$auth = swim_init_auth(true, true);

// AMAN and CDM share the same authority level
if (!$auth->canWriteField('cdm')) {
    SwimResponse::error(
        'Source "' . $auth->getSourceId() . '" is not authorized to write AMAN data. ' .
        'AMAN requires System or Partner tier with cdm authority.',
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

// Validate required fields
if (empty($body['airport']) || !is_string($body['airport'])) {
    SwimResponse::error('Required field "airport" must be a non-empty string', 400, 'MISSING_AIRPORT');
}

if (!isset($body['sequence']) || !is_array($body['sequence'])) {
    SwimResponse::error('Required field "sequence" must be an array', 400, 'MISSING_SEQUENCE');
}

$airport = strtoupper(trim($body['airport']));
$runway = isset($body['runway']) ? strtoupper(trim($body['runway'])) : null;
$sequence = $body['sequence'];
$source = $auth->getSourceId();
$aman_source = strtoupper(trim($body['source'] ?? $source));

$max_batch = 200;
if (count($sequence) > $max_batch) {
    SwimResponse::error(
        "Batch size exceeded. Maximum {$max_batch} sequence entries per request.",
        400,
        'BATCH_TOO_LARGE'
    );
}

// Valid AMAN status values
$valid_statuses = ['COMPUTED', 'TENTATIVE', 'FROZEN', 'LANDED', 'CANCELLED'];

// SWIM database connection for flight UID resolution
global $conn_swim;
if (!$conn_swim) {
    SwimResponse::error('SWIM database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

$resolved = 0;
$not_found = 0;
$errors = [];
$resolved_sequence = [];

foreach ($sequence as $index => $entry) {
    try {
        $result = resolveAmanEntry($conn_swim, $entry, $airport, $valid_statuses, $index);
        if ($result['resolved']) {
            $resolved++;
        } else {
            $not_found++;
        }
        $resolved_sequence[] = $result['entry'];
    } catch (Exception $e) {
        $errors[] = [
            'index' => $index,
            'callsign' => $entry['callsign'] ?? 'unknown',
            'error' => $e->getMessage()
        ];
    }
}

// Publish to WebSocket channel for real-time consumers
publishAmanSequence($airport, $runway, $resolved_sequence, $aman_source);

SwimResponse::success([
    'airport' => $airport,
    'runway' => $runway,
    'sequence_count' => count($resolved_sequence),
    'resolved' => $resolved,
    'not_found' => $not_found,
    'errors' => count($errors),
    'error_details' => array_slice($errors, 0, 10),
    'sequence' => $resolved_sequence,
], [
    'source' => $aman_source,
    'websocket_channel' => 'aman.sequence',
]);


/**
 * Resolve a single AMAN sequence entry against swim_flights.
 *
 * Looks up flight_uid by callsign + destination airport. Validates
 * and normalizes entry fields.
 *
 * @param resource $conn_swim SWIM database connection
 * @param array $entry AMAN sequence entry
 * @param string $airport Destination airport ICAO code
 * @param array $valid_statuses Valid AMAN status values
 * @param int $index Entry index in the batch
 * @return array Result with 'resolved' bool and normalized 'entry'
 */
function resolveAmanEntry($conn_swim, $entry, $airport, $valid_statuses, $index) {
    if (empty($entry['callsign'])) {
        throw new Exception("Missing required field 'callsign' at index {$index}");
    }

    $callsign = strtoupper(trim($entry['callsign']));

    // Look up flight by callsign + destination airport (AMAN is arrival-focused)
    $lookup_sql = "SELECT TOP 1 flight_uid, callsign, fp_dept_icao, fp_dest_icao
                   FROM dbo.swim_flights
                   WHERE callsign = ? AND fp_dest_icao = ? AND is_active = 1
                   ORDER BY last_sync_utc DESC";
    $params = [$callsign, $airport];

    $stmt = sqlsrv_query($conn_swim, $lookup_sql, $params);
    if ($stmt === false) {
        throw new Exception("Database error looking up flight {$callsign}");
    }

    $flight = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    $flight_uid = $flight ? $flight['flight_uid'] : null;

    // Validate status if provided
    $status = null;
    if (!empty($entry['status'])) {
        $status = strtoupper(trim($entry['status']));
        if (!in_array($status, $valid_statuses)) {
            throw new Exception("Invalid status '{$status}' at index {$index}. " .
                "Valid values: " . implode(', ', $valid_statuses));
        }
    }

    // Validate sequence_number if provided
    $sequence_number = null;
    if (isset($entry['sequence_number'])) {
        $sequence_number = intval($entry['sequence_number']);
        if ($sequence_number < 1 || $sequence_number > 999) {
            throw new Exception("Invalid sequence_number at index {$index}. Must be 1-999.");
        }
    }

    // Validate delay_seconds if provided
    $delay_seconds = null;
    if (isset($entry['delay_seconds'])) {
        $delay_seconds = intval($entry['delay_seconds']);
        if ($delay_seconds < 0 || $delay_seconds > 7200) {
            throw new Exception("Invalid delay_seconds at index {$index}. Must be 0-7200.");
        }
    }

    // Validate speed_restriction if provided
    $speed_restriction = null;
    if (isset($entry['speed_restriction'])) {
        $speed_restriction = intval($entry['speed_restriction']);
        if ($speed_restriction < 100 || $speed_restriction > 400) {
            throw new Exception("Invalid speed_restriction at index {$index}. Must be 100-400 knots.");
        }
    }

    // Validate UTC timestamps
    $eta_utc = validateUtcTimestamp($entry['eta_utc'] ?? null, 'eta_utc', $index);
    $sta_utc = validateUtcTimestamp($entry['sta_utc'] ?? null, 'sta_utc', $index);

    // Build normalized entry
    $normalized = [
        'callsign' => $callsign,
        'flight_uid' => $flight_uid,
        'sequence_number' => $sequence_number,
        'eta_utc' => $eta_utc,
        'sta_utc' => $sta_utc,
        'delay_seconds' => $delay_seconds,
        'fix' => !empty($entry['fix']) ? strtoupper(trim($entry['fix'])) : null,
        'speed_restriction' => $speed_restriction,
        'status' => $status,
    ];

    return [
        'resolved' => $flight_uid !== null,
        'entry' => $normalized,
    ];
}


/**
 * Validate a UTC timestamp string.
 *
 * @param string|null $value Timestamp value
 * @param string $field_name Field name for error messages
 * @param int $index Entry index in the batch
 * @return string|null Normalized ISO 8601 UTC timestamp, or null
 */
function validateUtcTimestamp($value, $field_name, $index) {
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_string($value)) {
        throw new Exception("Invalid {$field_name} at index {$index}: must be an ISO 8601 UTC string");
    }
    try {
        $dt = new DateTime(trim($value));
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt->format('Y-m-d\TH:i:s\Z');
    } catch (Exception $e) {
        throw new Exception("Invalid {$field_name} at index {$index}: could not parse '{$value}'");
    }
}


/**
 * Publish AMAN sequence to WebSocket via IPC event file.
 *
 * Writes an aman.sequence event to the shared event file that the
 * SWIM WebSocket server reads and broadcasts to subscribers.
 *
 * @param string $airport Destination airport ICAO code
 * @param string|null $runway Active arrival runway
 * @param array $sequence Resolved sequence entries
 * @param string $source AMAN source identifier
 */
function publishAmanSequence($airport, $runway, $sequence, $source) {
    $events = [[
        'type' => 'aman.sequence',
        'data' => [
            'airport' => $airport,
            'runway' => $runway,
            'sequence' => $sequence,
            'source' => $source,
            'published_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        ],
    ]];

    $eventFile = sys_get_temp_dir() . '/swim_ws_events.json';
    $existingEvents = [];
    if (file_exists($eventFile)) {
        $content = @file_get_contents($eventFile);
        if ($content) {
            $existingEvents = json_decode($content, true) ?: [];
        }
    }

    foreach ($events as $event) {
        $existingEvents[] = array_merge($event, [
            '_received_at' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);
    }

    if (count($existingEvents) > 10000) {
        $existingEvents = array_slice($existingEvents, -5000);
    }

    $tempFile = $eventFile . '.tmp.' . getmypid();
    if (file_put_contents($tempFile, json_encode($existingEvents)) !== false) {
        @rename($tempFile, $eventFile);
    }
}
