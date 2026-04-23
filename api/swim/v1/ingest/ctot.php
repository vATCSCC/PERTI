<?php
/**
 * VATSWIM API v1 - CTOT Assignment Endpoint
 *
 * Authenticated endpoint: requires SWIM API key with write permission + CTP authority.
 *
 * CTP assigns Controlled Take-Off Times and optional routes/tracks.
 * PERTI derives EOBT/EDCT, stores in TMI pipeline, and immediately
 * recalculates ETAs, waypoint times, and boundary crossings.
 *
 * POST /api/swim/v1/ingest/ctot.php
 *
 * 9-step recalculation cascade:
 *   1. tmi_flight_control (VATSIM_TMI)
 *   2. adl_flight_times (VATSIM_ADL)
 *   3. sp_CalculateETA with @departure_override (VATSIM_ADL)
 *   4. Waypoint ETA inline SQL (VATSIM_ADL)
 *   5. Boundary crossing recalc (VATSIM_GIS via GISService)
 *   6. swim_flights push (SWIM_API)
 *   7. rad_amendments if route provided (VATSIM_TMI)
 *   8. adl_flight_tmi sync (VATSIM_ADL)
 *   9. ctp_flight_control if segments/track (VATSIM_TMI)
 *
 * @see docs/superpowers/specs/2026-04-23-ctp-ete-edct-api-design.md
 */

require_once __DIR__ . '/../auth.php';

global $conn_swim;
if (!$conn_swim) {
    SwimResponse::error('SWIM database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

// Require write + CTP authority
$auth = swim_init_auth(true, true);
if (!$auth->canWriteField('ctp')) {
    SwimResponse::error('CTP write authority required', 403, 'FORBIDDEN');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    SwimResponse::error('Method not allowed. Use POST.', 405, 'METHOD_NOT_ALLOWED');
}

$body = swim_get_json_body();
if (!$body || !isset($body['assignments']) || !is_array($body['assignments'])) {
    SwimResponse::error('Request body must contain an "assignments" array', 400, 'INVALID_REQUEST');
}

$assignments = $body['assignments'];
if (count($assignments) === 0) {
    SwimResponse::error('assignments array must not be empty', 400, 'INVALID_REQUEST');
}
if (count($assignments) > 50) {
    SwimResponse::error('assignments array must not exceed 50 items', 400, 'INVALID_REQUEST');
}

// Get all database connections
$conn_adl = get_conn_adl();
$conn_tmi = get_conn_tmi();
$conn_gis = get_conn_gis();

if (!$conn_adl || !$conn_tmi) {
    SwimResponse::error('Required database connections not available', 503, 'SERVICE_UNAVAILABLE');
}

// Load GISService for boundary crossing recalc (step 5)
require_once __DIR__ . '/../../../load/services/GISService.php';
$gisService = $conn_gis ? new PERTI\Services\GISService($conn_gis) : null;

$results = [];
$errors = [];
$unmatched = [];
$counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];

foreach ($assignments as $item) {
    $callsign = strtoupper(trim($item['callsign'] ?? ''));
    if (strlen($callsign) < 2 || strlen($callsign) > 12 || !preg_match('/^[A-Z0-9]+$/', $callsign)) {
        $unmatched[] = $callsign ?: '(invalid)';
        continue;
    }

    // Validate CTOT (required)
    $ctot_str = ctot_parse_utc_datetime($item['ctot'] ?? '');
    if (!$ctot_str) {
        $errors[] = ['callsign' => $callsign, 'error' => 'Missing or invalid ctot datetime'];
        continue;
    }

    // Validate assigned_track format if provided
    $assigned_track = $item['assigned_track'] ?? null;
    if ($assigned_track && !preg_match('/^[A-Z]{1,2}\d?$/', $assigned_track)) {
        $errors[] = ['callsign' => $callsign, 'error' => 'Invalid assigned_track format (expected: A, B, SM1, etc.)'];
        continue;
    }

    // Find matching flight
    $flight = ctot_find_flight($conn_swim, $callsign);
    if (!$flight) {
        $unmatched[] = $callsign;
        continue;
    }

    $flight_uid = (int)$flight['flight_uid'];
    $dept_icao = $flight['fp_dept_icao'];
    $dest_icao = $flight['fp_dest_icao'];

    // Derive EOBT = CTOT - taxi_ref
    $taxi_seconds = ctot_get_taxi_reference($conn_adl, $dept_icao);
    $ctot_ts = strtotime($ctot_str . ' UTC');

    // Quality improvement: check strtotime() failure
    if ($ctot_ts === false) {
        $errors[] = ['callsign' => $callsign, 'error' => 'Failed to parse CTOT datetime'];
        continue;
    }

    $eobt_ts = $ctot_ts - $taxi_seconds;
    $eobt_str = gmdate('Y-m-d H:i:s', $eobt_ts);

    $delay_minutes = isset($item['delay_minutes']) ? (int)$item['delay_minutes'] : null;
    $delay_reason = $item['delay_reason'] ?? null;
    $program_name = $item['program_name'] ?? null;
    $program_id = isset($item['program_id']) ? (int)$item['program_id'] : null;
    $source_system = $item['source_system'] ?? ($auth->getKeyInfo()['source_id'] ?? 'CTP');
    $cta_utc = !empty($item['cta_utc']) ? ctot_parse_utc_datetime($item['cta_utc']) : null;
    $assigned_route = $item['assigned_route'] ?? null;
    $route_segments = $item['route_segments'] ?? null;

    // ========================================================================
    // Step 1: tmi_flight_control (VATSIM_TMI)
    // ========================================================================
    $existing_control = ctot_get_existing_control($conn_tmi, $flight_uid);

    if ($existing_control) {
        // Check idempotency: same CTOT → skip
        $existing_eobt = $existing_control['ctd_utc'];
        if ($existing_eobt instanceof DateTime) {
            $existing_eobt = $existing_eobt->format('Y-m-d H:i:s');
        }
        if ($existing_eobt === $eobt_str) {
            $results[] = [
                'callsign' => $callsign,
                'status' => 'skipped',
                'flight_uid' => $flight_uid,
                'control_id' => (int)$existing_control['control_id'],
                'ctot' => gmdate('Y-m-d\TH:i:s\Z', $ctot_ts),
                'eobt' => gmdate('Y-m-d\TH:i:s\Z', $eobt_ts),
                'recalc_status' => 'skipped_idempotent',
            ];
            $counts['skipped']++;
            continue;
        }

        // Update existing control (preserve octd_utc)
        $stmt = sqlsrv_query($conn_tmi,
            "UPDATE dbo.tmi_flight_control SET
                ctd_utc = ?, cta_utc = ?,
                program_delay_min = ?, ctl_type = 'CTP', ctl_prgm = ?,
                program_id = ?, dep_airport = ?, arr_airport = ?,
                modified_utc = SYSUTCDATETIME()
             WHERE control_id = ?",
            [$eobt_str, $cta_utc, $delay_minutes, $program_name,
             $program_id, $dept_icao, $dest_icao,
             $existing_control['control_id']]
        );

        if (!$stmt) {
            error_log("CTOT: Step 1 UPDATE failed for callsign $callsign (control_id={$existing_control['control_id']}): " . print_r(sqlsrv_errors(), true));
            $errors[] = ['callsign' => $callsign, 'flight_uid' => $flight_uid, 'error' => 'TMI control update failed'];
            continue;
        }
        if ($stmt) sqlsrv_free_stmt($stmt);

        $control_id = (int)$existing_control['control_id'];
        $status = 'updated';
        $counts['updated']++;
    } else {
        // Insert new control
        $stmt = sqlsrv_query($conn_tmi,
            "INSERT INTO dbo.tmi_flight_control
                (flight_uid, callsign, ctd_utc, octd_utc, cta_utc,
                 program_delay_min, ctl_type, ctl_prgm, ctl_elem,
                 program_id, dep_airport, arr_airport,
                 orig_etd_utc, control_assigned_utc)
             OUTPUT INSERTED.control_id
             VALUES (?, ?, ?, ?, ?, ?, 'CTP', ?, ?,
                     ?, ?, ?,
                     ?, SYSUTCDATETIME())",
            [$flight_uid, $callsign, $eobt_str, $eobt_str, $cta_utc,
             $delay_minutes, $program_name, $dest_icao,
             $program_id, $dept_icao, $dest_icao,
             $flight['estimated_off_block_time'] ?? $flight['etd_utc']]
        );

        if (!$stmt) {
            error_log("CTOT: Step 1 INSERT failed for callsign $callsign (flight_uid=$flight_uid): " . print_r(sqlsrv_errors(), true));
            $errors[] = ['callsign' => $callsign, 'flight_uid' => $flight_uid, 'error' => 'TMI control insert failed'];
            continue;
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        $control_id = $row ? (int)$row['control_id'] : null;
        $status = 'created';
        $counts['created']++;
    }

    // ========================================================================
    // Step 2: adl_flight_times (VATSIM_ADL)
    // ========================================================================
    $stmt = sqlsrv_query($conn_adl,
        "UPDATE dbo.adl_flight_times SET
            etd_utc = ?, std_utc = ?,
            estimated_takeoff_time = ?
         WHERE flight_uid = ?",
        [$eobt_str, $eobt_str, $ctot_str, $flight_uid]
    );

    if ($stmt) {
        sqlsrv_free_stmt($stmt);
    } else {
        error_log("CTOT: Step 2 UPDATE failed for callsign $callsign (flight_uid=$flight_uid): " . print_r(sqlsrv_errors(), true));
    }

    // ========================================================================
    // Step 3: sp_CalculateETA with @departure_override = CTOT
    // ========================================================================
    $sp = sqlsrv_query($conn_adl,
        "EXEC dbo.sp_CalculateETA @flight_uid = ?, @departure_override = ?",
        [$flight_uid, $ctot_str]
    );

    // Quality improvement: log SQL failure
    if (!$sp) {
        error_log("CTOT: Step 3 SP call failed for callsign $callsign (flight_uid=$flight_uid): " . print_r(sqlsrv_errors(), true));
    }

    if ($sp) sqlsrv_free_stmt($sp);

    // Read recalculated ETA
    $times = ctot_read_flight_times($conn_adl, $flight_uid);
    $eta_utc = $times['eta_utc'] ?? null;

    // Compute ETE = minutes from CTOT to ETA
    // Quality improvement: clamp ETE to zero
    $ete_minutes = null;
    $eta_iso = null;
    if ($eta_utc) {
        $eta_ts = ($eta_utc instanceof DateTime) ? $eta_utc->getTimestamp() : strtotime($eta_utc . ' UTC');
        $ete_minutes = max(0, (int)round(($eta_ts - $ctot_ts) / 60));
        $eta_iso = gmdate('Y-m-d\TH:i:s\Z', $eta_ts);
    }

    // Store computed_ete_minutes
    $stmt = sqlsrv_query($conn_adl,
        "UPDATE dbo.adl_flight_times SET computed_ete_minutes = ? WHERE flight_uid = ?",
        [$ete_minutes, $flight_uid]
    );
    if ($stmt) sqlsrv_free_stmt($stmt);

    // ========================================================================
    // Step 4: Waypoint ETA recalc (inline SQL)
    // sp_CalculateWaypointETABatch_Tiered cannot target a single flight.
    // ========================================================================
    $perf = ctot_get_performance($conn_adl, $flight);
    $effective_speed = $perf ? (int)$perf['cruise_speed_ktas'] : 450;

    // Apply wind adjustment if available
    $wind = $times['eta_wind_component_kts'] ?? 0;
    $effective_speed += (int)$wind;
    if ($effective_speed < 100) $effective_speed = 100; // floor

    $stmt = sqlsrv_query($conn_adl,
        "UPDATE dbo.adl_flight_waypoints SET
            eta_utc = DATEADD(SECOND,
                CAST(distance_from_dep_nm / ? * 3600 AS INT),
                ?)
         WHERE flight_uid = ? AND distance_from_dep_nm IS NOT NULL",
        [(float)$effective_speed, $ctot_str, $flight_uid]
    );

    if ($stmt) {
        sqlsrv_free_stmt($stmt);
    } else {
        error_log("CTOT: Step 4 UPDATE failed for callsign $callsign (flight_uid=$flight_uid): " . print_r(sqlsrv_errors(), true));
    }

    // ========================================================================
    // Step 5: Boundary crossing recalc (PostGIS via GISService)
    // ========================================================================
    if ($gisService) {
        // Read waypoints for crossing calculation
        $waypoints = ctot_read_waypoints($conn_adl, $flight_uid);
        if (!empty($waypoints)) {
            // Use CTOT as current time anchor
            $crossings = $gisService->calculateCrossingEtas(
                $waypoints,
                (float)($flight['lat'] ?? 0),
                (float)($flight['lon'] ?? 0),
                0, // dist_flown = 0 for prefiles
                $effective_speed,
                $ctot_str
            );

            // Update adl_flight_planned_crossings
            if (!empty($crossings)) {
                // Clear existing crossings for this flight
                $stmt = sqlsrv_query($conn_adl,
                    "DELETE FROM dbo.adl_flight_planned_crossings WHERE flight_uid = ?",
                    [$flight_uid]
                );

                if ($stmt) {
                    sqlsrv_free_stmt($stmt);
                } else {
                    error_log("CTOT: Step 5 DELETE failed for callsign $callsign (flight_uid=$flight_uid): " . print_r(sqlsrv_errors(), true));
                }

                foreach ($crossings as $cx) {
                    $stmt = sqlsrv_query($conn_adl,
                        "INSERT INTO dbo.adl_flight_planned_crossings
                            (flight_uid, boundary_type, boundary_code, boundary_name,
                             parent_artcc, crossing_lat, crossing_lon,
                             distance_from_origin_nm, distance_remaining_nm,
                             eta_utc, crossing_type)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [$flight_uid, $cx['boundary_type'], $cx['boundary_code'],
                         $cx['boundary_name'], $cx['parent_artcc'],
                         $cx['crossing_lat'], $cx['crossing_lon'],
                         $cx['distance_from_origin_nm'], $cx['distance_remaining_nm'],
                         $cx['eta_utc'], $cx['crossing_type']]
                    );

                    if ($stmt) {
                        sqlsrv_free_stmt($stmt);
                    } else {
                        error_log("CTOT: Step 5 INSERT failed for callsign $callsign (flight_uid=$flight_uid, boundary={$cx['boundary_code']}): " . print_r(sqlsrv_errors(), true));
                    }
                }
            }
        }
    }

    // ========================================================================
    // Step 6: swim_flights push (SWIM_API)
    // ========================================================================
    $original_edct_clause = "original_edct = CASE WHEN original_edct IS NULL THEN ? ELSE original_edct END,";

    $stmt = sqlsrv_query($conn_swim,
        "UPDATE dbo.swim_flights SET
            target_takeoff_time = ?,
            controlled_time_of_departure = ?,
            estimated_off_block_time = ?,
            estimated_takeoff_time = ?,
            edct_utc = ?,
            estimated_time_of_arrival = ?,
            computed_ete_minutes = ?,
            controlled_time_of_arrival = COALESCE(?, controlled_time_of_arrival),
            $original_edct_clause
            delay_minutes = ?,
            ctl_type = 'CTP'
         WHERE flight_uid = ?",
        [$ctot_str, $eobt_str, $eobt_str, $ctot_str, $eobt_str,
         $eta_utc instanceof DateTime ? $eta_utc->format('Y-m-d H:i:s') : $eta_utc,
         $ete_minutes,
         $cta_utc,
         $eobt_str, // for original_edct CASE
         $delay_minutes,
         $flight_uid]
    );

    if ($stmt) {
        sqlsrv_free_stmt($stmt);
    } else {
        error_log("CTOT: Step 6 UPDATE failed for callsign $callsign (flight_uid=$flight_uid): " . print_r(sqlsrv_errors(), true));
    }

    // ========================================================================
    // Step 7: rad_amendments if assigned_route provided (VATSIM_TMI)
    // ========================================================================
    $route_amendment_id = null;
    if ($assigned_route) {
        $gufi = $flight['gufi'] ?? ('PERTI-' . $flight_uid);
        $stmt = sqlsrv_query($conn_tmi,
            "INSERT INTO dbo.rad_amendments
                (gufi, callsign, origin, destination, original_route,
                 assigned_route, status, tmi_id_label, created_utc)
             OUTPUT INSERTED.id
             VALUES (?, ?, ?, ?, ?, ?, 'DRAFT', ?, SYSUTCDATETIME())",
            [$gufi, $callsign, $dept_icao, $dest_icao,
             $flight['fp_route'], $assigned_route, $program_name]
        );

        // Quality improvement: log SQL failure
        if (!$stmt) {
            error_log("CTOT: Step 7 INSERT failed for callsign $callsign (flight_uid=$flight_uid): " . print_r(sqlsrv_errors(), true));
        }

        $row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
        if ($stmt) sqlsrv_free_stmt($stmt);
        $route_amendment_id = $row ? (int)$row['id'] : null;
    }

    // ========================================================================
    // Step 8: adl_flight_tmi sync (VATSIM_ADL)
    // ========================================================================
    $tmi_update_fields = "ctd_utc = ?, edct_utc = ?, program_delay_min = ?, ctl_type = 'CTP'";
    $tmi_params = [$eobt_str, $eobt_str, $delay_minutes];

    if ($route_amendment_id) {
        $tmi_update_fields .= ", rad_amendment_id = ?, rad_assigned_route = ?";
        $tmi_params[] = $route_amendment_id;
        $tmi_params[] = $assigned_route;
    }

    $tmi_params[] = $flight_uid;
    $stmt = sqlsrv_query($conn_adl,
        "UPDATE dbo.adl_flight_tmi SET $tmi_update_fields WHERE flight_uid = ?",
        $tmi_params
    );

    if ($stmt) {
        sqlsrv_free_stmt($stmt);
    } else {
        error_log("CTOT: Step 8 UPDATE failed for callsign $callsign (flight_uid=$flight_uid): " . print_r(sqlsrv_errors(), true));
    }

    // ========================================================================
    // Step 9: ctp_flight_control if route_segments or track (VATSIM_TMI)
    // ========================================================================
    if ($route_segments || $assigned_track) {
        $ctp_exists = ctot_check_ctp_control($conn_tmi, $flight_uid);

        if ($ctp_exists) {
            $ctp_sets = ["edct_utc = ?", "tmi_control_id = ?"];
            $ctp_params = [$eobt_str, $control_id];

            if ($assigned_track) {
                $ctp_sets[] = "assigned_nat_track = ?";
                $ctp_params[] = $assigned_track;
            }
            if (isset($route_segments['na'])) {
                $ctp_sets[] = "seg_na_route = ?";
                $ctp_sets[] = "seg_na_status = 'VALIDATED'";
                $ctp_params[] = $route_segments['na'];
            }
            if (isset($route_segments['oceanic'])) {
                $ctp_sets[] = "seg_oceanic_route = ?";
                $ctp_sets[] = "seg_oceanic_status = 'VALIDATED'";
                $ctp_params[] = $route_segments['oceanic'];
            }
            if (isset($route_segments['eu'])) {
                $ctp_sets[] = "seg_eu_route = ?";
                $ctp_sets[] = "seg_eu_status = 'VALIDATED'";
                $ctp_params[] = $route_segments['eu'];
            }

            $ctp_params[] = $flight_uid;
            $stmt = sqlsrv_query($conn_tmi,
                "UPDATE dbo.ctp_flight_control SET " . implode(', ', $ctp_sets) . " WHERE flight_uid = ?",
                $ctp_params
            );

            if ($stmt) {
                sqlsrv_free_stmt($stmt);
            } else {
                error_log("CTOT: Step 9 UPDATE failed for callsign $callsign (flight_uid=$flight_uid): " . print_r(sqlsrv_errors(), true));
            }
        }
        // If no ctp_flight_control record exists, the flight wasn't imported via CTP session.
        // Don't create one here — that's done by ingest/ctp.php during session import.
    }

    // Build response record
    $results[] = [
        'callsign' => $callsign,
        'status' => $status,
        'flight_uid' => $flight_uid,
        'control_id' => $control_id,
        'ctot' => gmdate('Y-m-d\TH:i:s\Z', $ctot_ts),
        'eobt' => gmdate('Y-m-d\TH:i:s\Z', $eobt_ts),
        'edct_utc' => gmdate('Y-m-d\TH:i:s\Z', $eobt_ts),
        'estimated_time_of_arrival' => $eta_iso,
        'estimated_elapsed_time' => $ete_minutes,
        'eta_method' => $times['eta_method'] ?? null,
        'delay_minutes' => $delay_minutes,
        'route_amendment_id' => $route_amendment_id,
        'assigned_track' => $assigned_track,
        'recalc_status' => 'complete',
    ];
}

SwimResponse::success([
    'results' => $results,
    'errors' => $errors,
    'unmatched' => $unmatched,
], [
    'total_submitted' => count($assignments),
    'created' => $counts['created'],
    'updated' => $counts['updated'],
    'skipped' => $counts['skipped'],
    'total_errors' => count($errors),
    'unmatched' => count($unmatched),
]);

// ============================================================================
// Helper Functions
// ============================================================================

function ctot_parse_utc_datetime(string $str): ?string {
    $str = trim($str);
    if (empty($str)) return null;
    $ts = strtotime($str);
    if ($ts === false || $ts < 0) return null;
    return gmdate('Y-m-d H:i:s', $ts);
}

function ctot_find_flight($conn_swim, string $callsign): ?array {
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

    // Quality improvement: log SQL failure
    if (!$stmt) {
        error_log("CTOT: ctot_find_flight query failed for callsign $callsign: " . print_r(sqlsrv_errors(), true));
        return null;
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return $row ?: null;
}

function ctot_get_taxi_reference($conn_adl, ?string $icao): int {
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

function ctot_read_flight_times($conn_adl, int $flight_uid): array {
    $stmt = sqlsrv_query($conn_adl,
        "SELECT eta_utc, eta_method, eta_confidence, eta_route_dist_nm,
                eta_wind_component_kts
         FROM dbo.adl_flight_times WHERE flight_uid = ?",
        [$flight_uid]
    );
    if (!$stmt) return [];
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return $row ?: [];
}

function ctot_get_performance($conn_adl, array $flight): ?array {
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
    return $row ?: null;
}

function ctot_read_waypoints($conn_adl, int $flight_uid): array {
    $stmt = sqlsrv_query($conn_adl,
        "SELECT fix_name, latitude, longitude, distance_from_dep_nm, waypoint_sequence
         FROM dbo.adl_flight_waypoints
         WHERE flight_uid = ? AND latitude IS NOT NULL
         ORDER BY waypoint_sequence",
        [$flight_uid]
    );
    if (!$stmt) return [];
    $waypoints = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $waypoints[] = [
            'name' => $row['fix_name'],
            'lat' => (float)$row['latitude'],
            'lon' => (float)$row['longitude'],
            'dist_from_dep' => (float)$row['distance_from_dep_nm'],
            'sequence' => (int)$row['waypoint_sequence'],
        ];
    }
    sqlsrv_free_stmt($stmt);
    return $waypoints;
}

function ctot_get_existing_control($conn_tmi, int $flight_uid): ?array {
    $stmt = sqlsrv_query($conn_tmi,
        "SELECT control_id, ctd_utc, ctl_type
         FROM dbo.tmi_flight_control
         WHERE flight_uid = ? AND ctl_type = 'CTP'",
        [$flight_uid]
    );
    if (!$stmt) return null;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return $row ?: null;
}

function ctot_check_ctp_control($conn_tmi, int $flight_uid): bool {
    $stmt = sqlsrv_query($conn_tmi,
        "SELECT 1 FROM dbo.ctp_flight_control WHERE flight_uid = ?",
        [$flight_uid]
    );
    if (!$stmt) return false;
    $exists = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) !== null;
    sqlsrv_free_stmt($stmt);
    return $exists;
}
