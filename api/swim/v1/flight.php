<?php
/**
 * VATSWIM API v1 - Single Flight Endpoint
 * 
 * Returns a single flight record by GUFI, flight_uid, or flight_key.
 * Uses denormalized swim_flights table from SWIM_API database.
 *
 * Supports `?format=fixm` parameter for FIXM 4.3.0 aligned field names.
 * 
 * GET /api/swim/v1/flight?gufi=VAT-20260115-UAL123-KJFK-KLAX
 * GET /api/swim/v1/flight?flight_uid=123456
 * GET /api/swim/v1/flight?flight_key=...
 * GET /api/swim/v1/flight?gufi=...&format=fixm
 * 
 * @version 2.1.0 - Added FIXM format support
 */

require_once __DIR__ . '/auth.php';

// SWIM_API database connection (SWIM-only, no ADL fallback)
global $conn_swim;

if (!$conn_swim) {
    SwimResponse::error('SWIM database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

$conn = $conn_swim;

$auth = swim_init_auth(true, false);

// Get format parameter - FIXM only after transition
// Legacy format redirects to FIXM for backward compatibility
$format = swim_get_param('format', 'fixm');
$format = 'fixm';  // FIXM is the only supported format

// Get identifier parameters
$gufi = swim_get_param('gufi');
$flight_uid = swim_get_param('flight_uid');
$flight_key = swim_get_param('flight_key');
$include_history = swim_get_param('include_history', 'false') === 'true';

// Validate - need at least one identifier
if (!$gufi && !$flight_uid && !$flight_key) {
    SwimResponse::error('Missing required parameter: gufi, flight_uid, or flight_key', 400, 'MISSING_PARAM');
}

// Build query based on identifier type (using swim_flights single table)
$where_clause = '';
$params = [];

if ($flight_uid) {
    $where_clause = 'f.flight_uid = ?';
    $params[] = intval($flight_uid);
} elseif ($flight_key) {
    $where_clause = 'f.flight_key = ?';
    $params[] = $flight_key;
} elseif ($gufi) {
    // Auto-detect UUID vs legacy format
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $gufi)) {
        $where_clause = 'f.gufi = ?';
        $params[] = $gufi;
    } else {
        $where_clause = 'f.gufi_legacy = ?';
        $params[] = $gufi;
    }
}

if (!$include_history && !$flight_uid) {
    $where_clause .= ' AND f.is_active = 1';
}

// Main query - single table against swim_flights (SWIM_API database)
$sql = "
    SELECT TOP 1
        f.flight_uid, f.flight_key, f.gufi, f.gufi_legacy, f.gufi_created_utc, f.callsign, f.cid, f.flight_id,
        f.phase, f.is_active,
        f.first_seen_utc, f.last_seen_utc, f.logon_time_utc,
        f.current_artcc, f.current_tracon, f.current_zone,
        -- Position
        f.lat, f.lon, f.altitude_ft, f.heading_deg, f.groundspeed_kts,
        f.vertical_rate_fpm,
        f.dist_to_dest_nm, f.dist_flown_nm, f.pct_complete,
        -- Flight plan
        f.fp_dept_icao, f.fp_dest_icao, f.fp_alt_icao,
        f.fp_altitude_ft, f.fp_tas_kts, f.fp_route, f.fp_remarks, f.fp_rule,
        f.fp_dept_artcc, f.fp_dest_artcc, f.fp_dept_tracon, f.fp_dest_tracon,
        f.dfix, f.dp_name, f.afix, f.star_name,
        f.dep_runway, f.arr_runway,
        f.gcd_nm, f.route_total_nm, f.aircraft_type,
        -- FIXM-aligned time columns
        f.estimated_time_of_arrival, f.estimated_runway_arrival_time, f.estimated_off_block_time,
        f.eta_source, f.eta_method, f.ete_minutes,
        f.actual_off_block_time, f.actual_time_of_departure, f.actual_landing_time, f.actual_in_block_time,
        f.controlled_time_of_departure, f.controlled_time_of_arrival, f.edct_utc,
        -- SimTraffic FIXM-aligned times
        f.taxi_start_time, f.departure_sequence_time, f.hold_short_time, f.runway_entry_time,
        -- TMI
        f.ctl_type, f.ctl_prgm, f.ctl_element,
        f.gs_held, f.gs_release_utc,
        f.is_exempt, f.exempt_reason,
        f.slot_time_utc, f.slot_status,
        f.delay_minutes, f.delay_status,
        f.program_id, f.slot_id,
        -- Aircraft
        f.aircraft_icao, f.aircraft_faa, f.weight_class, f.wake_category,
        f.engine_type, f.airline_icao, f.airline_name,
        -- Metering
        f.sequence_number, f.scheduled_time_of_arrival, f.scheduled_time_of_departure,
        f.metering_point, f.metering_time, f.metering_delay, f.metering_frozen,
        f.metering_status, f.arrival_stream, f.undelayed_eta,
        f.eta_vertex, f.sta_vertex, f.vertex_point,
        f.metering_source, f.metering_updated_at,
        -- Sync metadata
        f.last_sync_utc
    FROM dbo.swim_flights f
    WHERE $where_clause
    ORDER BY f.last_seen_utc DESC
";

$stmt = sqlsrv_query($conn, $sql, $params);
if ($stmt === false) {
    $errors = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}

$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($stmt);

if (!$row) {
    SwimResponse::error('Flight not found', 404, 'NOT_FOUND');
}

// FIXM format using swim_flights columns
$flight = formatSwimFlightRecordFIXM($row);

SwimResponse::success($flight, [
    'source' => 'vatcscc',
    'lookup_method' => $flight_uid ? 'flight_uid' : ($flight_key ? 'flight_key' : 'gufi'),
    'schema_version' => 'swim_v1'
]);


function formatDT($dt) {
    if ($dt === null) return null;
    return ($dt instanceof DateTime) ? $dt->format('c') : $dt;
}

/**
 * Format swim_flights record using FIXM 4.3.0 aligned field names
 * Uses columns available in the denormalized swim_flights table only.
 */
function formatSwimFlightRecordFIXM($row) {
    $gufi = $row['gufi'] ?? '';

    $time_to_dest = null;
    if ($row['groundspeed_kts'] > 50 && $row['dist_to_dest_nm'] > 0) {
        $time_to_dest = round(($row['dist_to_dest_nm'] / $row['groundspeed_kts']) * 60, 1);
    } elseif ($row['ete_minutes']) {
        $time_to_dest = $row['ete_minutes'];
    }

    $result = [
        'gufi' => swim_format_gufi_response(
            $gufi,
            $row['gufi_legacy'] ?? null,
            formatDT($row['gufi_created_utc'] ?? null)
        ),
        'gufi_legacy' => $row['gufi_legacy'] ?? null,
        'flight_uid' => $row['flight_uid'],
        'flight_key' => $row['flight_key'],
        'flight_id' => $row['flight_id'],

        'identity' => [
            'aircraft_identification' => $row['callsign'],
            'pilot_cid' => $row['cid'],
            'aircraft_type' => $row['aircraft_type'],
            'aircraft_type_icao' => $row['aircraft_icao'],
            'aircraft_type_faa' => $row['aircraft_faa'],
            'weight_class' => $row['weight_class'],
            'wake_turbulence' => $row['wake_category'],
            'engine_type' => $row['engine_type'],
            'operator_icao' => $row['airline_icao'],
            'operator_name' => $row['airline_name']
        ],

        'flight_plan' => [
            'departure_aerodrome' => trim($row['fp_dept_icao'] ?? ''),
            'arrival_aerodrome' => trim($row['fp_dest_icao'] ?? ''),
            'alternate_aerodrome' => trim($row['fp_alt_icao'] ?? ''),
            'cruising_level' => $row['fp_altitude_ft'],
            'cruising_speed' => $row['fp_tas_kts'],
            'route_text' => $row['fp_route'],
            'remarks' => $row['fp_remarks'],
            'flight_rules_category' => trim($row['fp_rule'] ?? ''),
            'departure_airspace' => $row['fp_dept_artcc'],
            'arrival_airspace' => $row['fp_dest_artcc'],
            'departure_tracon' => $row['fp_dept_tracon'],
            'arrival_tracon' => $row['fp_dest_tracon'],
            'departure_point' => $row['dfix'],
            'sid' => $row['dp_name'],
            'arrival_point' => $row['afix'],
            'star' => $row['star_name'],
            'departure_runway' => $row['dep_runway'],
            'arrival_runway' => $row['arr_runway']
        ],

        'position' => [
            'latitude' => $row['lat'] !== null ? floatval($row['lat']) : null,
            'longitude' => $row['lon'] !== null ? floatval($row['lon']) : null,
            'altitude' => $row['altitude_ft'],
            'track' => $row['heading_deg'],
            'ground_speed' => $row['groundspeed_kts'],
            'vertical_rate' => $row['vertical_rate_fpm'],
            'current_airspace' => $row['current_artcc'],
            'current_tracon' => $row['current_tracon'],
            'current_airport_zone' => $row['current_zone']
        ],

        'progress' => [
            'flight_status' => $row['phase'],
            'is_active' => (bool)$row['is_active'],
            'great_circle_distance' => $row['gcd_nm'] !== null ? floatval($row['gcd_nm']) : null,
            'total_flight_distance' => $row['route_total_nm'] !== null ? floatval($row['route_total_nm']) : null,
            'distance_to_destination' => $row['dist_to_dest_nm'] !== null ? floatval($row['dist_to_dest_nm']) : null,
            'distance_flown' => $row['dist_flown_nm'] !== null ? floatval($row['dist_flown_nm']) : null,
            'percent_complete' => $row['pct_complete'] !== null ? floatval($row['pct_complete']) : null,
            'time_to_destination' => $time_to_dest
        ],

        'times' => [
            'estimated' => [
                'off_block_time' => formatDT($row['estimated_off_block_time']),
                'time_of_arrival' => formatDT($row['estimated_time_of_arrival']),
                'runway_arrival' => formatDT($row['estimated_runway_arrival_time']),
                'arrival_source' => $row['eta_source'],
                'arrival_method' => $row['eta_method']
            ],
            'actual' => [
                'off_block_time' => formatDT($row['actual_off_block_time']),
                'time_of_departure' => formatDT($row['actual_time_of_departure']),
                'landing_time' => formatDT($row['actual_landing_time']),
                'in_block_time' => formatDT($row['actual_in_block_time'])
            ],
            'controlled' => [
                'time_of_departure' => formatDT($row['controlled_time_of_departure']),
                'time_of_arrival' => formatDT($row['controlled_time_of_arrival']),
                'edct' => formatDT($row['edct_utc'])
            ],
            'simtraffic' => [
                'taxi_start_time' => formatDT($row['taxi_start_time'] ?? null),
                'departure_sequence_time' => formatDT($row['departure_sequence_time'] ?? null),
                'hold_short_time' => formatDT($row['hold_short_time'] ?? null),
                'runway_entry_time' => formatDT($row['runway_entry_time'] ?? null)
            ],
            'estimated_elapsed_time' => $row['ete_minutes']
        ],

        'tmi' => [
            'is_controlled' => ($row['gs_held'] == 1 || $row['ctl_type'] !== null),
            'control_type' => $row['ctl_type'],
            'program_name' => $row['ctl_prgm'],
            'control_element' => $row['ctl_element'],
            'exempt_indicator' => (bool)$row['is_exempt'],
            'exempt_reason' => $row['exempt_reason'],
            'ground_stop' => [
                'held' => $row['gs_held'] == 1,
                'release_time' => formatDT($row['gs_release_utc'])
            ],
            'slot' => [
                'time' => formatDT($row['slot_time_utc']),
                'status' => $row['slot_status'],
                'program_id' => $row['program_id'],
                'slot_id' => $row['slot_id']
            ],
            'delay' => [
                'value' => $row['delay_minutes'],
                'status' => $row['delay_status']
            ]
        ],

        'metering' => [
            'sequence_number' => $row['sequence_number'] ?? null,
            'scheduled_time_of_arrival' => formatDT($row['scheduled_time_of_arrival'] ?? null),
            'scheduled_time_of_departure' => formatDT($row['scheduled_time_of_departure'] ?? null),
            'metering_point' => $row['metering_point'] ?? null,
            'metering_time' => formatDT($row['metering_time'] ?? null),
            'delay_value' => $row['metering_delay'] ?? null,
            'frozen_indicator' => isset($row['metering_frozen']) ? (bool)$row['metering_frozen'] : null,
            'metering_status' => $row['metering_status'] ?? null,
            'arrival_stream' => $row['arrival_stream'] ?? null,
            'undelayed_eta' => formatDT($row['undelayed_eta'] ?? null),
            'eta_vertex' => formatDT($row['eta_vertex'] ?? null),
            'sta_vertex' => formatDT($row['sta_vertex'] ?? null),
            'vertex_point' => $row['vertex_point'] ?? null,
            'metering_source' => $row['metering_source'] ?? null,
            'metering_updated_time' => formatDT($row['metering_updated_at'] ?? null)
        ],

        'data_source' => 'vatcscc',
        'schema_version' => 'swim_v1_fixm',
        'first_tracked_time' => formatDT($row['first_seen_utc']),
        'position_time' => formatDT($row['last_seen_utc']),
        'logon_time' => formatDT($row['logon_time_utc'])
    ];

    // Add sync metadata
    if (isset($row['last_sync_utc'])) {
        $result['last_sync_time'] = formatDT($row['last_sync_utc']);
    }

    return $result;
}
