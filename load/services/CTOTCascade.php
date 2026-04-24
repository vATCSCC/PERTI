<?php
/**
 * CTOTCascade — Shared 9-step CTOT recalculation service.
 *
 * Extracted from api/swim/v1/ingest/ctot.php so both the external CTOT push
 * endpoint and the CTP slot engine's confirm-slot can run the same cascade.
 *
 * Steps:
 *   1. tmi_flight_control (VATSIM_TMI) — INSERT/UPDATE control record
 *   2. adl_flight_times (VATSIM_ADL) — UPDATE ETD/STD/takeoff
 *   3. sp_CalculateETA (VATSIM_ADL) — Recalculate ETA from departure override
 *   4. Waypoint ETA (VATSIM_ADL) — Recalculate per-waypoint ETAs
 *   5. Boundary crossings (VATSIM_GIS) — Delete/reinsert planned crossings
 *   6. swim_flights (SWIM_API) — Push CTOT/EOBT/ETA to SWIM mirror
 *   7. rad_amendments (VATSIM_TMI) — Create route amendment if route provided
 *   8. adl_flight_tmi (VATSIM_ADL) — Sync TMI control to ADL
 *   9. ctp_flight_control (VATSIM_TMI) — Update CTP record if segments/track
 */

namespace PERTI\Services;

class CTOTCascade
{
    private $conn_adl;
    private $conn_tmi;
    private $conn_swim;
    private $gisService;

    public function __construct($conn_adl, $conn_tmi, $conn_swim, ?GISService $gisService = null)
    {
        $this->conn_adl = $conn_adl;
        $this->conn_tmi = $conn_tmi;
        $this->conn_swim = $conn_swim;
        $this->gisService = $gisService;
    }

    /**
     * Run the full 9-step CTOT recalculation cascade for a single flight.
     *
     * @param array  $flight   Flight data from swim_flights (flight_uid, callsign, fp_dept_icao, fp_dest_icao, etc.)
     * @param string $ctot_str CTOT in 'Y-m-d H:i:s' UTC format
     * @param array  $options  Optional keys: delay_minutes, delay_reason, program_name, program_id,
     *                         source_system, cta_utc, assigned_route, route_segments, assigned_track
     * @return array           Result with status, control_id, timing data, recalc_status
     */
    public function apply(array $flight, string $ctot_str, array $options = []): array
    {
        $callsign = $flight['callsign'];
        $flight_uid = (int)$flight['flight_uid'];
        $dept_icao = $flight['fp_dept_icao'];
        $dest_icao = $flight['fp_dest_icao'];

        $delay_minutes = $options['delay_minutes'] ?? null;
        $program_name = $options['program_name'] ?? null;
        $program_id = isset($options['program_id']) ? (int)$options['program_id'] : null;
        $cta_utc = $options['cta_utc'] ?? null;
        $assigned_route = $options['assigned_route'] ?? null;
        $route_segments = $options['route_segments'] ?? null;
        $assigned_track = $options['assigned_track'] ?? null;

        // Derive EOBT = CTOT - taxi_ref
        $taxi_seconds = self::getTaxiReference($this->conn_adl, $dept_icao);
        $ctot_ts = strtotime($ctot_str . ' UTC');

        if ($ctot_ts === false) {
            return [
                'callsign' => $callsign,
                'status' => 'error',
                'flight_uid' => $flight_uid,
                'error' => 'Failed to parse CTOT datetime',
                'recalc_status' => 'failed',
            ];
        }

        $eobt_ts = $ctot_ts - $taxi_seconds;
        $eobt_str = gmdate('Y-m-d H:i:s', $eobt_ts);

        // ====================================================================
        // Step 1: tmi_flight_control (VATSIM_TMI)
        // ====================================================================
        $existing_control = self::getExistingControl($this->conn_tmi, $flight_uid);

        if ($existing_control) {
            $existing_eobt = $existing_control['ctd_utc'];
            if ($existing_eobt instanceof \DateTime) {
                $existing_eobt = $existing_eobt->format('Y-m-d H:i:s');
            }
            if ($existing_eobt === $eobt_str) {
                return [
                    'callsign' => $callsign,
                    'status' => 'skipped',
                    'flight_uid' => $flight_uid,
                    'control_id' => (int)$existing_control['control_id'],
                    'ctot' => gmdate('Y-m-d\TH:i:s\Z', $ctot_ts),
                    'eobt' => gmdate('Y-m-d\TH:i:s\Z', $eobt_ts),
                    'recalc_status' => 'skipped_idempotent',
                ];
            }

            $stmt = sqlsrv_query($this->conn_tmi,
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
                error_log("CTOTCascade: Step 1 UPDATE failed for $callsign (control_id={$existing_control['control_id']}): " . print_r(sqlsrv_errors(), true));
                return [
                    'callsign' => $callsign, 'status' => 'error',
                    'flight_uid' => $flight_uid, 'error' => 'TMI control update failed',
                    'recalc_status' => 'failed',
                ];
            }
            if ($stmt) sqlsrv_free_stmt($stmt);

            $control_id = (int)$existing_control['control_id'];
            $status = 'updated';
        } else {
            $stmt = sqlsrv_query($this->conn_tmi,
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
                error_log("CTOTCascade: Step 1 INSERT failed for $callsign (flight_uid=$flight_uid): " . print_r(sqlsrv_errors(), true));
                return [
                    'callsign' => $callsign, 'status' => 'error',
                    'flight_uid' => $flight_uid, 'error' => 'TMI control insert failed',
                    'recalc_status' => 'failed',
                ];
            }

            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            $control_id = $row ? (int)$row['control_id'] : null;
            $status = 'created';
        }

        // ====================================================================
        // Step 2: adl_flight_times (VATSIM_ADL)
        // ====================================================================
        $stmt = sqlsrv_query($this->conn_adl,
            "UPDATE dbo.adl_flight_times SET
                etd_utc = ?, std_utc = ?,
                estimated_takeoff_time = ?
             WHERE flight_uid = ?",
            [$eobt_str, $eobt_str, $ctot_str, $flight_uid]
        );

        if ($stmt) {
            sqlsrv_free_stmt($stmt);
        } else {
            error_log("CTOTCascade: Step 2 UPDATE failed for $callsign (flight_uid=$flight_uid): " . print_r(sqlsrv_errors(), true));
        }

        // ====================================================================
        // Step 3: sp_CalculateETA with @departure_override = CTOT
        // ====================================================================
        $sp = sqlsrv_query($this->conn_adl,
            "EXEC dbo.sp_CalculateETA @flight_uid = ?, @departure_override = ?",
            [$flight_uid, $ctot_str]
        );

        if (!$sp) {
            error_log("CTOTCascade: Step 3 SP call failed for $callsign (flight_uid=$flight_uid): " . print_r(sqlsrv_errors(), true));
        }
        if ($sp) sqlsrv_free_stmt($sp);

        // Read recalculated ETA
        $times = self::readFlightTimes($this->conn_adl, $flight_uid);
        $eta_utc = $times['eta_utc'] ?? null;

        // Compute ETE = minutes from CTOT to ETA
        $ete_minutes = null;
        $eta_iso = null;
        if ($eta_utc) {
            $eta_ts = ($eta_utc instanceof \DateTime) ? $eta_utc->getTimestamp() : strtotime($eta_utc . ' UTC');
            $ete_minutes = max(0, (int)round(($eta_ts - $ctot_ts) / 60));
            $eta_iso = gmdate('Y-m-d\TH:i:s\Z', $eta_ts);
        }

        $stmt = sqlsrv_query($this->conn_adl,
            "UPDATE dbo.adl_flight_times SET computed_ete_minutes = ? WHERE flight_uid = ?",
            [$ete_minutes, $flight_uid]
        );
        if ($stmt) sqlsrv_free_stmt($stmt);

        // ====================================================================
        // Step 4: Waypoint ETA recalc (inline SQL)
        // ====================================================================
        $perf = self::getPerformance($this->conn_adl, $flight);
        $effective_speed = $perf ? (int)$perf['cruise_speed_ktas'] : 450;

        $wind = $times['eta_wind_component_kts'] ?? 0;
        $effective_speed += (int)$wind;
        if ($effective_speed < 100) $effective_speed = 100;

        $stmt = sqlsrv_query($this->conn_adl,
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
            error_log("CTOTCascade: Step 4 UPDATE failed for $callsign (flight_uid=$flight_uid): " . print_r(sqlsrv_errors(), true));
        }

        // ====================================================================
        // Step 5: Boundary crossing recalc (PostGIS via GISService)
        // ====================================================================
        if ($this->gisService) {
            $waypoints = self::readWaypoints($this->conn_adl, $flight_uid);
            if (!empty($waypoints)) {
                $crossings = $this->gisService->calculateCrossingEtas(
                    $waypoints,
                    (float)($flight['lat'] ?? 0),
                    (float)($flight['lon'] ?? 0),
                    0,
                    $effective_speed,
                    $ctot_str
                );

                if (!empty($crossings)) {
                    $stmt = sqlsrv_query($this->conn_adl,
                        "DELETE FROM dbo.adl_flight_planned_crossings WHERE flight_uid = ?",
                        [$flight_uid]
                    );
                    if ($stmt) {
                        sqlsrv_free_stmt($stmt);
                    } else {
                        error_log("CTOTCascade: Step 5 DELETE failed for $callsign (flight_uid=$flight_uid): " . print_r(sqlsrv_errors(), true));
                    }

                    foreach ($crossings as $cx) {
                        $stmt = sqlsrv_query($this->conn_adl,
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
                            error_log("CTOTCascade: Step 5 INSERT failed for $callsign (flight_uid=$flight_uid, boundary={$cx['boundary_code']}): " . print_r(sqlsrv_errors(), true));
                        }
                    }
                }
            }
        }

        // ====================================================================
        // Step 6: swim_flights push (SWIM_API)
        // ====================================================================
        $original_edct_clause = "original_edct = CASE WHEN original_edct IS NULL THEN ? ELSE original_edct END,";

        $stmt = sqlsrv_query($this->conn_swim,
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
             $eta_utc instanceof \DateTime ? $eta_utc->format('Y-m-d H:i:s') : $eta_utc,
             $ete_minutes,
             $cta_utc,
             $eobt_str,
             $delay_minutes,
             $flight_uid]
        );

        if ($stmt) {
            sqlsrv_free_stmt($stmt);
        } else {
            error_log("CTOTCascade: Step 6 UPDATE failed for $callsign (flight_uid=$flight_uid): " . print_r(sqlsrv_errors(), true));
        }

        // ====================================================================
        // Step 7: rad_amendments if assigned_route provided (VATSIM_TMI)
        // ====================================================================
        $route_amendment_id = null;
        if ($assigned_route) {
            $gufi = $flight['gufi'] ?? ('PERTI-' . $flight_uid);
            $stmt = sqlsrv_query($this->conn_tmi,
                "INSERT INTO dbo.rad_amendments
                    (gufi, callsign, origin, destination, original_route,
                     assigned_route, status, tmi_id_label, created_utc)
                 OUTPUT INSERTED.id
                 VALUES (?, ?, ?, ?, ?, ?, 'DRAFT', ?, SYSUTCDATETIME())",
                [$gufi, $callsign, $dept_icao, $dest_icao,
                 $flight['fp_route'], $assigned_route, $program_name]
            );

            if (!$stmt) {
                error_log("CTOTCascade: Step 7 INSERT failed for $callsign (flight_uid=$flight_uid): " . print_r(sqlsrv_errors(), true));
            }

            $row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
            if ($stmt) sqlsrv_free_stmt($stmt);
            $route_amendment_id = $row ? (int)$row['id'] : null;
        }

        // ====================================================================
        // Step 8: adl_flight_tmi sync (VATSIM_ADL)
        // ====================================================================
        $tmi_update_fields = "ctd_utc = ?, edct_utc = ?, program_delay_min = ?, ctl_type = 'CTP'";
        $tmi_params = [$eobt_str, $eobt_str, $delay_minutes];

        if ($route_amendment_id) {
            $tmi_update_fields .= ", rad_amendment_id = ?, rad_assigned_route = ?";
            $tmi_params[] = $route_amendment_id;
            $tmi_params[] = $assigned_route;
        }

        $tmi_params[] = $flight_uid;
        $stmt = sqlsrv_query($this->conn_adl,
            "UPDATE dbo.adl_flight_tmi SET $tmi_update_fields WHERE flight_uid = ?",
            $tmi_params
        );

        if ($stmt) {
            sqlsrv_free_stmt($stmt);
        } else {
            error_log("CTOTCascade: Step 8 UPDATE failed for $callsign (flight_uid=$flight_uid): " . print_r(sqlsrv_errors(), true));
        }

        // ====================================================================
        // Step 9: ctp_flight_control if route_segments or track (VATSIM_TMI)
        // ====================================================================
        if ($route_segments || $assigned_track) {
            $ctp_exists = self::checkCtpControl($this->conn_tmi, $flight_uid);

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
                $stmt = sqlsrv_query($this->conn_tmi,
                    "UPDATE dbo.ctp_flight_control SET " . implode(', ', $ctp_sets) . " WHERE flight_uid = ?",
                    $ctp_params
                );

                if ($stmt) {
                    sqlsrv_free_stmt($stmt);
                } else {
                    error_log("CTOTCascade: Step 9 UPDATE failed for $callsign (flight_uid=$flight_uid): " . print_r(sqlsrv_errors(), true));
                }
            }
        }

        return [
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

    // ========================================================================
    // Helper Functions
    // ========================================================================

    public static function parseUtcDatetime(string $str): ?string
    {
        $str = trim($str);
        if (empty($str)) return null;
        $ts = strtotime($str);
        if ($ts === false || $ts < 0) return null;
        return gmdate('Y-m-d H:i:s', $ts);
    }

    public static function findFlight($conn_swim, string $callsign): ?array
    {
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
            error_log("CTOTCascade: findFlight query failed for callsign $callsign: " . print_r(sqlsrv_errors(), true));
            return null;
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        return $row ?: null;
    }

    public static function getTaxiReference($conn_adl, ?string $icao): int
    {
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

    public static function readFlightTimes($conn_adl, int $flight_uid): array
    {
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

    public static function getPerformance($conn_adl, array $flight): ?array
    {
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

    public static function readWaypoints($conn_adl, int $flight_uid): array
    {
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

    public static function getExistingControl($conn_tmi, int $flight_uid): ?array
    {
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

    public static function checkCtpControl($conn_tmi, int $flight_uid): bool
    {
        $stmt = sqlsrv_query($conn_tmi,
            "SELECT 1 FROM dbo.ctp_flight_control WHERE flight_uid = ?",
            [$flight_uid]
        );
        if (!$stmt) return false;
        $exists = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) !== null;
        sqlsrv_free_stmt($stmt);
        return $exists;
    }
}
