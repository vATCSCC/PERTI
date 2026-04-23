<?php
/**
 * VATSWIM API v1 - ETE Query Endpoint
 *
 * Public endpoint (no API key required). Flight times are public information.
 *
 * CTP sends callsigns + optional TOBT per flight.
 * PERTI computes ETE/ETA using sp_CalculateETA with departure override,
 * stores TOBT/ETOT/EET, and returns computed times.
 *
 * POST /api/swim/v1/ete.php
 *
 * @see docs/superpowers/specs/2026-04-23-ctp-ete-edct-api-design.md
 */

require_once __DIR__ . '/auth.php';

// Public endpoint: handle CORS/OPTIONS, no auth required
swim_init_auth(false, false);

global $conn_swim;
if (!$conn_swim) {
    SwimResponse::error('SWIM database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

// POST only
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    SwimResponse::error('Method not allowed. Use POST.', 405, 'METHOD_NOT_ALLOWED');
}

$body = swim_get_json_body();
if (!$body || !isset($body['flights']) || !is_array($body['flights'])) {
    SwimResponse::error('Request body must contain a "flights" array', 400, 'INVALID_REQUEST');
}

$flights_input = $body['flights'];
if (count($flights_input) === 0) {
    SwimResponse::error('flights array must not be empty', 400, 'INVALID_REQUEST');
}
if (count($flights_input) > 50) {
    SwimResponse::error('flights array must not exceed 50 items', 400, 'INVALID_REQUEST');
}

// Get ADL connection for sp_CalculateETA + taxi reference
$conn_adl = get_conn_adl();
if (!$conn_adl) {
    SwimResponse::error('ADL database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

$results = [];
$errors = [];
$unmatched = [];

foreach ($flights_input as $item) {
    $callsign = strtoupper(trim($item['callsign'] ?? ''));
    if (strlen($callsign) < 2 || strlen($callsign) > 12 || !preg_match('/^[A-Z0-9]+$/', $callsign)) {
        $unmatched[] = $callsign ?: '(invalid)';
        continue;
    }

    // Validate TOBT if provided
    $tobt_str = null;
    if (!empty($item['tobt'])) {
        $tobt_str = ete_parse_utc_datetime($item['tobt']);
        if ($tobt_str === null) {
            $errors[] = [
                'callsign' => $callsign,
                'error' => 'Invalid tobt datetime format. Use ISO 8601 (e.g., 2026-04-23T12:00:00Z).'
            ];
            continue;
        }
    }

    // Find matching active flight in swim_flights
    $flight = ete_find_flight($conn_swim, $callsign);
    if (!$flight) {
        $unmatched[] = $callsign;
        continue;
    }

    $flight_uid = (int)$flight['flight_uid'];
    $dept_icao = $flight['fp_dept_icao'];

    // Resolve departure basis: CTP-provided TOBT, or existing EOBT/ETD
    $departure_basis = $tobt_str;
    if (!$departure_basis) {
        $db_eobt = $flight['estimated_off_block_time'] ?? null;
        $db_etd = $flight['etd_utc'] ?? null;
        if ($db_eobt instanceof \DateTime) {
            $departure_basis = $db_eobt->format('Y-m-d H:i:s');
        } elseif ($db_etd instanceof \DateTime) {
            $departure_basis = $db_etd->format('Y-m-d H:i:s');
        } elseif ($db_eobt) {
            $departure_basis = $db_eobt;
        } elseif ($db_etd) {
            $departure_basis = $db_etd;
        }
    }

    if (!$departure_basis) {
        $errors[] = [
            'callsign' => $callsign,
            'flight_uid' => $flight_uid,
            'error' => 'No TOBT provided and no existing ETD available for this flight.'
        ];
        continue;
    }

    // Get taxi reference for departure airport (default 600s = 10 min)
    $taxi_seconds = ete_get_taxi_reference($conn_adl, $dept_icao);
    $taxi_minutes = (int)round($taxi_seconds / 60);

    // ETOT = TOBT + taxi (estimated wheels-up time)
    $tobt_ts = strtotime($departure_basis . ' UTC');
    if ($tobt_ts === false) {
        $errors[] = [
            'callsign' => $callsign,
            'flight_uid' => $flight_uid,
            'error' => 'Failed to parse departure basis datetime.'
        ];
        continue;
    }
    $etot_ts = $tobt_ts + $taxi_seconds;
    $etot_str = gmdate('Y-m-d H:i:s', $etot_ts);
    $tobt_iso = gmdate('Y-m-d\TH:i:s\Z', $tobt_ts);
    $etot_iso = gmdate('Y-m-d\TH:i:s\Z', $etot_ts);

    // Call sp_CalculateETA with @departure_override = ETOT (wheels-up anchor)
    $sp = sqlsrv_query($conn_adl,
        "EXEC dbo.sp_CalculateETA @flight_uid = ?, @departure_override = ?",
        [$flight_uid, $etot_str]
    );
    if ($sp) {
        sqlsrv_free_stmt($sp);
    } else {
        error_log('ETE: sp_CalculateETA failed for flight_uid=' . $flight_uid . ': ' . print_r(sqlsrv_errors(), true));
    }

    // Read computed results from adl_flight_times
    $times = ete_read_flight_times($conn_adl, $flight_uid);
    $eta_utc_raw = $times['eta_utc'] ?? null;

    // Compute ETE = minutes from ETOT to ETA
    $ete_minutes = null;
    $eta_iso = null;
    if ($eta_utc_raw) {
        if ($eta_utc_raw instanceof \DateTime) {
            $eta_ts = $eta_utc_raw->getTimestamp();
        } else {
            $eta_ts = strtotime($eta_utc_raw . ' UTC');
        }
        $ete_minutes = max(0, (int)round(($eta_ts - $etot_ts) / 60));
        $eta_iso = gmdate('Y-m-d\TH:i:s\Z', $eta_ts);
    }

    // Get aircraft cruise speed from performance function
    $cruise_speed = ete_get_cruise_speed($conn_adl, $flight);

    // Store computed values in swim_flights + adl_flight_times
    ete_store_results($conn_swim, $conn_adl, $flight_uid, $tobt_str, $etot_str, $ete_minutes);

    // Build response record
    $results[] = [
        'callsign' => $callsign,
        'flight_uid' => $flight_uid,
        'gufi' => $flight['gufi'] ?? null,
        'departure_airport' => $dept_icao,
        'arrival_airport' => $flight['fp_dest_icao'],
        'aircraft_type' => $flight['aircraft_type'] ?? $flight['aircraft_icao'] ?? null,
        'tobt' => $tobt_iso,
        'etot' => $etot_iso,
        'estimated_elapsed_time' => $ete_minutes,
        'estimated_time_of_arrival' => $eta_iso,
        'taxi_time_minutes' => $taxi_minutes,
        'eta_method' => $times['eta_method'] ?? null,
        'eta_confidence' => isset($times['eta_confidence']) ? round((float)$times['eta_confidence'], 2) : null,
        'route_distance_nm' => isset($times['eta_route_dist_nm']) ? round((float)$times['eta_route_dist_nm'], 1) : null,
        'aircraft_cruise_speed_kts' => $cruise_speed,
        'flight_phase' => $flight['phase'],
        'filed_route' => $flight['fp_route'] ?? null,
        'latitude' => isset($flight['lat']) ? (float)$flight['lat'] : null,
        'longitude' => isset($flight['lon']) ? (float)$flight['lon'] : null,
    ];
}

SwimResponse::success([
    'flights' => $results,
    'errors' => $errors,
    'unmatched' => $unmatched,
], [
    'total_requested' => count($flights_input),
    'total_matched' => count($results),
    'total_errors' => count($errors),
    'total_unmatched' => count($unmatched),
]);

// ============================================================================
// Helper Functions
// ============================================================================

/**
 * Parse ISO 8601 datetime string to 'Y-m-d H:i:s' format.
 * Returns null if invalid.
 */
function ete_parse_utc_datetime(string $str): ?string {
    $str = trim($str);
    $ts = strtotime($str);
    if ($ts === false || $ts < 0) return null;
    return gmdate('Y-m-d H:i:s', $ts);
}

/**
 * Find an active flight by callsign in swim_flights.
 * Returns the flight row or null.
 */
function ete_find_flight($conn_swim, string $callsign): ?array {
    $stmt = sqlsrv_query($conn_swim,
        "SELECT TOP 1
            flight_uid, gufi, callsign, fp_dept_icao, fp_dest_icao,
            aircraft_type, aircraft_icao, weight_class, engine_type,
            phase, fp_route, lat, lon,
            estimated_off_block_time, etd_utc
         FROM dbo.swim_flights
         WHERE callsign = ? AND is_active = 1
         ORDER BY flight_uid DESC",
        [$callsign]
    );
    if (!$stmt) {
        error_log('ETE: swim_flights lookup failed for callsign=' . $callsign . ': ' . print_r(sqlsrv_errors(), true));
        return null;
    }
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return $row ?: null;
}

/**
 * Get unimpeded taxi time for an airport.
 * Returns seconds (default 600s = 10 min).
 */
function ete_get_taxi_reference($conn_adl, ?string $icao): int {
    if (!$icao) return 600;
    $stmt = sqlsrv_query($conn_adl,
        "SELECT unimpeded_taxi_sec FROM dbo.airport_taxi_reference WHERE airport_icao = ?",
        [$icao]
    );
    if (!$stmt) return 600;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return $row ? (int)$row['unimpeded_taxi_sec'] : 600;
}

/**
 * Read ETA results from adl_flight_times after sp_CalculateETA execution.
 */
function ete_read_flight_times($conn_adl, int $flight_uid): array {
    $stmt = sqlsrv_query($conn_adl,
        "SELECT eta_utc, eta_method, eta_confidence, eta_route_dist_nm, eta_dist_source
         FROM dbo.adl_flight_times WHERE flight_uid = ?",
        [$flight_uid]
    );
    if (!$stmt) return [];
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return $row ?: [];
}

/**
 * Get aircraft cruise speed via fn_GetAircraftPerformance.
 * Returns cruise_speed_ktas or null.
 */
function ete_get_cruise_speed($conn_adl, array $flight): ?int {
    $icao = $flight['aircraft_icao'] ?? $flight['aircraft_type'] ?? null;
    $wc = $flight['weight_class'] ?? 'L';
    $et = $flight['engine_type'] ?? 'JET';
    if (!$icao) return null;

    $stmt = sqlsrv_query($conn_adl,
        "SELECT cruise_speed_ktas FROM dbo.fn_GetAircraftPerformance(?, ?, ?)",
        [$icao, $wc, $et]
    );
    if (!$stmt) return null;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return $row ? (int)$row['cruise_speed_ktas'] : null;
}

/**
 * Store TOBT/ETOT/EET in swim_flights and adl_flight_times.
 */
function ete_store_results($conn_swim, $conn_adl, int $flight_uid, ?string $tobt, string $etot, ?int $ete_minutes): void {
    // Update swim_flights
    $stmt = sqlsrv_query($conn_swim,
        "UPDATE dbo.swim_flights SET
            target_off_block_time = COALESCE(?, target_off_block_time),
            estimated_takeoff_time = ?,
            computed_ete_minutes = ?
         WHERE flight_uid = ?",
        [$tobt, $etot, $ete_minutes, $flight_uid]
    );
    if ($stmt) {
        sqlsrv_free_stmt($stmt);
    } else {
        error_log('ETE: swim_flights UPDATE failed for flight_uid=' . $flight_uid . ': ' . print_r(sqlsrv_errors(), true));
    }

    // Update adl_flight_times
    $stmt = sqlsrv_query($conn_adl,
        "UPDATE dbo.adl_flight_times SET
            estimated_takeoff_time = ?,
            computed_ete_minutes = ?
         WHERE flight_uid = ?",
        [$etot, $ete_minutes, $flight_uid]
    );
    if ($stmt) {
        sqlsrv_free_stmt($stmt);
    } else {
        error_log('ETE: adl_flight_times UPDATE failed for flight_uid=' . $flight_uid . ': ' . print_r(sqlsrv_errors(), true));
    }
}
