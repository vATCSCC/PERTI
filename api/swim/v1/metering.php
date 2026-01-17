<?php
/**
 * VATSIM SWIM API v1 - Metering Data Query Endpoint
 *
 * Returns TBFM-style metering data for an airport, optimized for vNAS/CRC datablock display.
 * Field naming follows FIXM 4.3 specification.
 *
 * @version 1.0.0
 * @since 2026-01-16
 *
 * Endpoints:
 *   GET /metering/{airport}           - All metered arrivals for airport
 *   GET /metering/{airport}/sequence  - Arrival sequence list (sorted by sequence_number)
 *
 * Query Parameters:
 *   status      - Filter by metering_status (UNMETERED|METERED|FROZEN|SUSPENDED|EXEMPT)
 *   runway      - Filter by arrival runway
 *   stream      - Filter by arrival_stream (corner post)
 *   metered_only - If true, only return flights with metering data (default: true)
 *   format      - Response format: json (default) or fixm
 *
 * Response includes FIXM-aligned fields for datablock display:
 *   - sequence_number (SEQ)
 *   - scheduled_time_of_arrival (STA)
 *   - metering_time (MF_TIME)
 *   - metering_delay (DLA_ASGN)
 *   - metering_status
 *   - arrival_runway
 *   - arrival_stream
 */

require_once __DIR__ . '/auth.php';

// Require authentication (read-only)
$auth = swim_init_auth(true, false);

// Parse request path
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($request_uri, PHP_URL_PATH);
$path = preg_replace('#^.*/metering/?#', '', $path);
$path_parts = array_filter(explode('/', $path));

if (count($path_parts) < 1) {
    SwimResponse::error('Airport code required. Usage: /metering/{airport}', 400, 'MISSING_AIRPORT');
}

$airport = strtoupper(trim($path_parts[0] ?? ''));
$sub_resource = strtolower(trim($path_parts[1] ?? ''));

if (empty($airport) || strlen($airport) < 3 || strlen($airport) > 4) {
    SwimResponse::error('Invalid airport code', 400, 'INVALID_AIRPORT');
}

// Query parameters
$status_filter = swim_get_param('status');
$runway_filter = swim_get_param('runway');
$stream_filter = swim_get_param('stream');
$metered_only = swim_get_param('metered_only', 'true') !== 'false';
$format = swim_get_param('format', 'json');
$use_fixm = ($format === 'fixm');

// Build cache key parameters
$cache_params = array_filter([
    'airport' => $airport,
    'sub_resource' => $sub_resource,
    'status' => $status_filter,
    'runway' => $runway_filter,
    'stream' => $stream_filter,
    'metered_only' => $metered_only ? 'true' : 'false',
    'format' => $format
], fn($v) => $v !== null && $v !== '');

// Check cache first - returns early if hit
if (SwimResponse::tryCached('metering', $cache_params)) {
    exit;
}

// Get SWIM database connection
global $conn_swim;
if (!$conn_swim) {
    SwimResponse::error('SWIM database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

// Build query
$where_clauses = ["fp_dest_icao = ?", "is_active = 1"];
$params = [$airport];

if ($metered_only) {
    $where_clauses[] = "(sequence_number IS NOT NULL OR metering_status IS NOT NULL)";
}

if ($status_filter) {
    $where_clauses[] = "metering_status = ?";
    $params[] = strtoupper($status_filter);
}

if ($runway_filter) {
    $where_clauses[] = "arr_runway = ?";
    $params[] = strtoupper($runway_filter);
}

if ($stream_filter) {
    $where_clauses[] = "arrival_stream = ?";
    $params[] = strtoupper($stream_filter);
}

$where_sql = implode(' AND ', $where_clauses);

// Different queries based on sub-resource
if ($sub_resource === 'sequence') {
    // Sequence list - sorted by sequence number, compact format for datablock
    $sql = "SELECT
                callsign,
                gufi,
                sequence_number,
                scheduled_time_of_arrival,
                eta_runway_utc,
                metering_delay,
                metering_frozen,
                metering_status,
                arr_runway,
                arrival_stream,
                aircraft_type,
                weight_class,
                fp_dept_icao
            FROM dbo.swim_flights
            WHERE {$where_sql}
              AND sequence_number IS NOT NULL
            ORDER BY sequence_number ASC";
} else {
    // Full metering data
    $sql = "SELECT
                flight_uid,
                gufi,
                callsign,
                fp_dept_icao,
                fp_dest_icao,
                aircraft_type,
                weight_class,
                phase,
                lat,
                lon,
                altitude_ft,
                groundspeed_kts,
                eta_utc,
                eta_runway_utc,
                -- FIXM metering fields
                sequence_number,
                scheduled_time_of_arrival,
                scheduled_time_of_departure,
                metering_point,
                metering_time,
                metering_delay,
                metering_frozen,
                metering_status,
                arrival_stream,
                arr_runway,
                undelayed_eta,
                eta_vertex,
                sta_vertex,
                vertex_point,
                metering_source,
                metering_updated_at,
                -- TMI fields (for context)
                gs_held,
                edct_utc,
                ctl_type,
                delay_minutes
            FROM dbo.swim_flights
            WHERE {$where_sql}
            ORDER BY
                CASE WHEN sequence_number IS NOT NULL THEN 0 ELSE 1 END,
                sequence_number ASC,
                eta_runway_utc ASC";
}

$stmt = sqlsrv_query($conn_swim, $sql, $params);
if ($stmt === false) {
    $err = sqlsrv_errors();
    SwimResponse::error('Database query failed: ' . ($err[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}

$flights = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    // Format datetime fields
    foreach ($row as $key => $val) {
        if ($val instanceof DateTime) {
            $row[$key] = $val->format('c');
        }
    }

    // Convert to FIXM naming if requested
    if ($use_fixm) {
        $row = convertToFixmNaming($row);
    }

    $flights[] = $row;
}
sqlsrv_free_stmt($stmt);

// Build response
$response_data = [
    'airport' => $airport,
    'flights' => $flights,
    'count' => count($flights)
];

if ($sub_resource === 'sequence') {
    $response_data['type'] = 'sequence_list';
} else {
    $response_data['type'] = 'metering_data';
}

// Add summary stats
$metered_count = 0;
$frozen_count = 0;
$total_delay = 0;

foreach ($flights as $f) {
    $status_key = $use_fixm ? 'meteringStatus' : 'metering_status';
    $delay_key = $use_fixm ? 'delayValue' : 'metering_delay';
    $frozen_key = $use_fixm ? 'frozenIndicator' : 'metering_frozen';

    if (!empty($f[$status_key]) && $f[$status_key] !== 'UNMETERED') {
        $metered_count++;
    }
    if (!empty($f[$frozen_key])) {
        $frozen_count++;
    }
    if (!empty($f[$delay_key])) {
        $total_delay += intval($f[$delay_key]);
    }
}

$response_data['summary'] = [
    'total' => count($flights),
    'metered' => $metered_count,
    'frozen' => $frozen_count,
    'avg_delay_minutes' => $metered_count > 0 ? round($total_delay / $metered_count, 1) : 0
];

// Send response and cache it (tier-aware TTL)
SwimResponse::successCached($response_data, 'metering', $cache_params, [
    'format' => $format,
    'metered_only' => $metered_only
]);

/**
 * Convert snake_case field names to FIXM camelCase
 */
function convertToFixmNaming($row) {
    $mapping = [
        'flight_uid' => 'flightUid',
        'callsign' => 'aircraftIdentification',
        'fp_dept_icao' => 'departureAerodrome',
        'fp_dest_icao' => 'arrivalAerodrome',
        'aircraft_type' => 'aircraftType',
        'weight_class' => 'weightClass',
        'lat' => 'latitude',
        'lon' => 'longitude',
        'altitude_ft' => 'altitude',
        'groundspeed_kts' => 'groundSpeed',
        'eta_utc' => 'estimatedTimeOfArrival',
        'eta_runway_utc' => 'estimatedRunwayTimeOfArrival',
        // Metering fields
        'sequence_number' => 'sequenceNumber',
        'scheduled_time_of_arrival' => 'scheduledTimeOfArrival',
        'scheduled_time_of_departure' => 'scheduledTimeOfDeparture',
        'metering_point' => 'meteringPoint',
        'metering_time' => 'meteringTime',
        'metering_delay' => 'delayValue',
        'metering_frozen' => 'frozenIndicator',
        'metering_status' => 'meteringStatus',
        'arrival_stream' => 'arrivalStream',
        'arr_runway' => 'arrivalRunway',
        'undelayed_eta' => 'undelayedEta',
        'eta_vertex' => 'etaVertex',
        'sta_vertex' => 'staVertex',
        'vertex_point' => 'vertexPoint',
        'metering_source' => 'meteringSource',
        'metering_updated_at' => 'meteringUpdatedTime',
        // TMI fields
        'gs_held' => 'groundStopHeld',
        'edct_utc' => 'expectedDepartureClearanceTime',
        'ctl_type' => 'controlType',
        'delay_minutes' => 'tmiDelayValue'
    ];

    $result = [];
    foreach ($row as $key => $val) {
        $newKey = $mapping[$key] ?? $key;
        $result[$newKey] = $val;
    }
    return $result;
}
