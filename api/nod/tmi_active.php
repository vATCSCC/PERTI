<?php
/**
 * NOD Active TMIs API
 *
 * Consolidates active TMIs from:
 * - Ground Stops (MySQL tmi_ground_stops) + ADL held flight counts
 * - GDPs (Azure SQL gdp_log) + enhanced stats from tmi_programs
 * - Reroutes (Azure SQL tmi_reroutes)
 * - Public Routes (Azure SQL public_routes with active status)
 * - MITs and AFPs (Azure SQL tmi_entries)
 * - Delay reports (Azure SQL vw_tmi_current_delays)
 * - Airport coordinates for map display
 *
 * GET - Returns all active TMIs
 */

header('Content-Type: application/json');

// Include database connections
$config_path = realpath(__DIR__ . '/../../load/config.php');
$connect_path = realpath(__DIR__ . '/../../load/connect.php');

if ($config_path) include($config_path);
if ($connect_path) include($connect_path);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$result = [
    'ground_stops' => [],
    'gdps' => [],
    'reroutes' => [],
    'public_routes' => [],
    'mits' => [],
    'afps' => [],
    'delays' => [],
    'airports' => (object)[],
    'summary' => [
        'total_gs' => 0,
        'total_gdp' => 0,
        'total_reroutes' => 0,
        'total_public_routes' => 0,
        'total_mits' => 0,
        'total_afps' => 0,
        'total_delays' => 0,
        'has_active_tmi' => false
    ],
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z')
];

/**
 * Format a sqlsrv DateTime field to ISO 8601 UTC string.
 */
function formatSqlsrvDateTime($value): ?string {
    if ($value instanceof DateTime) {
        return $value->format('Y-m-d\TH:i:s\Z');
    }
    return $value;
}

/**
 * Resolve a fix name to lat/lon from nav_fixes, using a cache to avoid repeated queries.
 * Returns ['lat' => float, 'lon' => float] or null.
 */
function resolveFixLatLon(string $fixName, $conn_adl, array &$fixCache): ?array {
    if (isset($fixCache[$fixName])) {
        return $fixCache[$fixName];
    }

    $fix_sql = "SELECT TOP 1 fix_name, lat, lon FROM dbo.nav_fixes WHERE fix_name = ?";
    $fix_stmt = @sqlsrv_query($conn_adl, $fix_sql, [$fixName]);
    if ($fix_stmt) {
        $fix_row = sqlsrv_fetch_array($fix_stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($fix_stmt);
        if ($fix_row) {
            $fixCache[$fixName] = [
                'lat' => (float)$fix_row['lat'],
                'lon' => (float)$fix_row['lon']
            ];
            return $fixCache[$fixName];
        }
    }

    $fixCache[$fixName] = null;
    return null;
}

try {
    // Lazy-loaded connections (initialized on first use)
    $conn_adl_lazy = null;
    $conn_tmi_lazy = null;
    $fix_cache = [];
    $airport_codes = [];

    // =========================================
    // 1. Ground Stops (MySQL)
    // =========================================
    if (isset($conn_sqli) && $conn_sqli) {
        $gs_sql = "SELECT * FROM tmi_ground_stops WHERE status = 1 ORDER BY start_utc DESC";
        $gs_result = mysqli_query($conn_sqli, $gs_sql);

        if ($gs_result) {
            while ($row = mysqli_fetch_assoc($gs_result)) {
                $result['ground_stops'][] = [
                    'id' => (int)$row['id'],
                    'name' => $row['name'],
                    'ctl_element' => $row['ctl_element'],
                    'element_type' => $row['element_type'],
                    'airports' => $row['airports'],
                    'start_utc' => $row['start_utc'],
                    'end_utc' => $row['end_utc'],
                    'prob_ext' => (int)$row['prob_ext'],
                    'origin_centers' => $row['origin_centers'],
                    'origin_airports' => $row['origin_airports'],
                    'flt_incl_carrier' => $row['flt_incl_carrier'],
                    'flt_incl_type' => $row['flt_incl_type'],
                    'dep_facilities' => $row['dep_facilities'],
                    'comments' => $row['comments'],
                    'adv_number' => $row['adv_number'],
                    'advisory_text' => $row['advisory_text'],
                    'flights_held' => 0,
                    'avg_hold_minutes' => 0,
                    'tmi_type' => 'GS',
                    'status_label' => 'Ground Stop'
                ];

                // Collect airport codes for coordinate lookup
                if (!empty($row['ctl_element'])) {
                    $airport_codes[] = $row['ctl_element'];
                }
                if (!empty($row['airports'])) {
                    foreach (preg_split('/[\s,;]+/', $row['airports']) as $apt) {
                        $apt = trim($apt);
                        if ($apt !== '') {
                            $airport_codes[] = $apt;
                        }
                    }
                }
            }
            mysqli_free_result($gs_result);
        }
    }

    // =========================================
    // 1a. GS Flight Held Counts (ADL - Azure SQL)
    // =========================================
    if (!empty($result['ground_stops'])) {
        $conn_adl_lazy = get_conn_adl();
        if ($conn_adl_lazy) {
            $gs_counts_sql = "SELECT ctl_element, COUNT(*) as held_count
                              FROM dbo.adl_flight_tmi
                              WHERE gs_held = 1 AND ctl_element IS NOT NULL
                              GROUP BY ctl_element";
            $gs_counts_stmt = @sqlsrv_query($conn_adl_lazy, $gs_counts_sql);

            if ($gs_counts_stmt) {
                $gs_held_lookup = [];
                while ($row = sqlsrv_fetch_array($gs_counts_stmt, SQLSRV_FETCH_ASSOC)) {
                    $gs_held_lookup[$row['ctl_element']] = (int)$row['held_count'];
                }
                sqlsrv_free_stmt($gs_counts_stmt);

                // Merge held counts into ground stop records
                foreach ($result['ground_stops'] as &$gs) {
                    $elem = $gs['ctl_element'] ?? '';
                    if (isset($gs_held_lookup[$elem])) {
                        $gs['flights_held'] = $gs_held_lookup[$elem];
                    }
                }
                unset($gs);
            }
        }
    }

    // =========================================
    // 2. GDPs (Azure SQL - gdp_log)
    // =========================================
    if (isset($conn_adl) && $conn_adl) {
        $gdp_sql = "SELECT TOP 20 * FROM dbo.gdp_log
                    WHERE status = 'ACTIVE'
                    ORDER BY created_at DESC";

        $gdp_stmt = @sqlsrv_query($conn_adl, $gdp_sql);

        if ($gdp_stmt) {
            while ($row = sqlsrv_fetch_array($gdp_stmt, SQLSRV_FETCH_ASSOC)) {
                $startTime = formatSqlsrvDateTime($row['start_time'] ?? null);
                $endTime = formatSqlsrvDateTime($row['end_time'] ?? null);

                $result['gdps'][] = [
                    'id' => $row['id'] ?? null,
                    'airport' => $row['airport'] ?? $row['ctl_element'] ?? null,
                    'program_type' => $row['program_type'] ?? 'GDP',
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'scope_type' => $row['scope_type'] ?? null,
                    'scope_value' => $row['scope_value'] ?? null,
                    'delay_mode' => $row['delay_mode'] ?? null,
                    'max_delay' => $row['max_delay'] ?? null,
                    'avg_delay' => $row['avg_delay'] ?? null,
                    'impacting_condition' => $row['impacting_condition'] ?? null,
                    'comments' => $row['comments'] ?? null,
                    'controlled_count' => 0,
                    'exempt_count' => 0,
                    'total_delay_minutes' => 0,
                    'tmi_type' => 'GDP',
                    'status_label' => 'Ground Delay Program'
                ];

                // Collect airport code for coordinate lookup
                $apt = $row['airport'] ?? $row['ctl_element'] ?? null;
                if ($apt) {
                    $airport_codes[] = $apt;
                }
            }
            sqlsrv_free_stmt($gdp_stmt);
        }

        // =========================================
        // 2a. GDP Enhanced Stats (TMI - tmi_programs)
        // =========================================
        if (!empty($result['gdps'])) {
            $conn_tmi_lazy = get_conn_tmi();
            if ($conn_tmi_lazy) {
                $prog_sql = "SELECT program_id, ctl_element, controlled_flights, exempt_flights,
                                    total_delay_min, avg_delay_min, max_delay_min
                             FROM dbo.tmi_programs
                             WHERE is_active = 1 AND program_type IN ('GDP', 'GS_GDP')";
                $prog_stmt = @sqlsrv_query($conn_tmi_lazy, $prog_sql);

                if ($prog_stmt) {
                    $prog_lookup = [];
                    while ($row = sqlsrv_fetch_array($prog_stmt, SQLSRV_FETCH_ASSOC)) {
                        $elem = $row['ctl_element'] ?? '';
                        $prog_lookup[$elem] = [
                            'controlled_count' => (int)($row['controlled_flights'] ?? 0),
                            'exempt_count' => (int)($row['exempt_flights'] ?? 0),
                            'total_delay_minutes' => (int)($row['total_delay_min'] ?? 0),
                        ];
                    }
                    sqlsrv_free_stmt($prog_stmt);

                    // Merge enhanced stats into GDP records
                    foreach ($result['gdps'] as &$gdp) {
                        $apt = $gdp['airport'] ?? '';
                        if (isset($prog_lookup[$apt])) {
                            $gdp['controlled_count'] = $prog_lookup[$apt]['controlled_count'];
                            $gdp['exempt_count'] = $prog_lookup[$apt]['exempt_count'];
                            $gdp['total_delay_minutes'] = $prog_lookup[$apt]['total_delay_minutes'];
                        }
                    }
                    unset($gdp);
                }
            }
        }

        // =========================================
        // 3. Reroutes (Azure SQL - VATSIM_TMI.tmi_reroutes)
        // =========================================

        // Use TMI connection if available, fallback to ADL
        $rr_conn = isset($conn_tmi) && $conn_tmi ? $conn_tmi : $conn_adl;

        $rr_sql = "SELECT TOP 50
                       r.reroute_id as id, r.name, r.adv_number, r.status,
                       r.protected_segment, r.protected_fixes, r.avoid_fixes,
                       r.start_utc, r.end_utc, r.time_basis,
                       r.origin_airports, r.origin_centers, r.dest_airports, r.dest_centers,
                       r.impacting_condition, r.advisory_text, r.comments,
                       r.color, r.line_weight, r.line_style, r.route_geojson,
                       r.total_assigned, r.compliant_count, r.non_compliant_count, r.compliance_rate,
                       r.created_by, r.created_at
                   FROM dbo.tmi_reroutes r
                   WHERE r.status IN (2, 3)
                     AND (r.start_utc IS NULL OR r.start_utc <= GETUTCDATE())
                     AND (r.end_utc IS NULL OR r.end_utc > GETUTCDATE())
                   ORDER BY r.created_at DESC";

        $rr_stmt = @sqlsrv_query($rr_conn, $rr_sql);

        if ($rr_stmt) {
            while ($row = sqlsrv_fetch_array($rr_stmt, SQLSRV_FETCH_ASSOC)) {
                $startUtc = formatSqlsrvDateTime($row['start_utc'] ?? null);
                $endUtc = formatSqlsrvDateTime($row['end_utc'] ?? null);

                $result['reroutes'][] = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'adv_number' => $row['adv_number'],
                    'status' => $row['status'],
                    'protected_segment' => $row['protected_segment'],
                    'protected_fixes' => $row['protected_fixes'],
                    'avoid_fixes' => $row['avoid_fixes'],
                    'start_utc' => $startUtc,
                    'end_utc' => $endUtc,
                    'time_basis' => $row['time_basis'],
                    'origin_airports' => $row['origin_airports'],
                    'origin_centers' => $row['origin_centers'],
                    'dest_airports' => $row['dest_airports'],
                    'dest_centers' => $row['dest_centers'],
                    'impacting_condition' => $row['impacting_condition'],
                    'advisory_text' => $row['advisory_text'],
                    'comments' => $row['comments'],
                    'color' => $row['color'] ?? '#3498db',
                    'line_weight' => $row['line_weight'] ?? 3,
                    'route_geojson' => $row['route_geojson'],
                    'total_assigned' => (int)($row['total_assigned'] ?? 0),
                    'compliant_count' => (int)($row['compliant_count'] ?? 0),
                    'non_compliant_count' => (int)($row['non_compliant_count'] ?? 0),
                    'compliance_rate' => $row['compliance_rate'],
                    'tmi_type' => 'REROUTE',
                    'status_label' => $row['status'] == 2 ? 'Active Reroute' : 'Monitoring Reroute'
                ];
            }
            sqlsrv_free_stmt($rr_stmt);
        }

        // Add debug info for reroutes
        $result['debug']['reroutes_source'] = isset($conn_tmi) && $conn_tmi ? 'VATSIM_TMI' : 'VATSIM_ADL';

        // =========================================
        // 4. Public Routes (Azure SQL - VATSIM_TMI.tmi_public_routes)
        // =========================================

        // Use TMI connection if available, fallback to ADL
        $pr_conn = isset($conn_tmi) && $conn_tmi ? $conn_tmi : $conn_adl;
        $pr_table = isset($conn_tmi) && $conn_tmi ? 'dbo.tmi_public_routes' : 'dbo.public_routes';
        $pr_id_col = isset($conn_tmi) && $conn_tmi ? 'route_id' : 'id';

        // Debug: count total routes in table
        $count_sql = "SELECT COUNT(*) as total FROM $pr_table";
        $count_stmt = @sqlsrv_query($pr_conn, $count_sql);
        if ($count_stmt) {
            $count_row = sqlsrv_fetch_array($count_stmt, SQLSRV_FETCH_ASSOC);
            $result['debug']['total_routes_in_table'] = (int)($count_row['total'] ?? 0);
            $result['debug']['public_routes_source'] = isset($conn_tmi) && $conn_tmi ? 'VATSIM_TMI' : 'VATSIM_ADL';
            sqlsrv_free_stmt($count_stmt);
        }

        // Show routes that are active (status=1 and within time window)
        $pr_sql = "SELECT TOP 50
                       $pr_id_col as id, name, adv_number, route_string, advisory_text,
                       color, line_weight, line_style,
                       valid_start_utc, valid_end_utc,
                       constrained_area, reason, origin_filter, dest_filter, facilities,
                       route_geojson,
                       created_by, created_at
                   FROM $pr_table
                   WHERE status = 1
                     AND (valid_start_utc IS NULL OR valid_start_utc <= GETUTCDATE())
                     AND (valid_end_utc IS NULL OR valid_end_utc > GETUTCDATE())
                   ORDER BY created_at DESC";

        $pr_stmt = @sqlsrv_query($pr_conn, $pr_sql);

        if ($pr_stmt) {
            while ($row = sqlsrv_fetch_array($pr_stmt, SQLSRV_FETCH_ASSOC)) {
                $validStart = formatSqlsrvDateTime($row['valid_start_utc'] ?? null);
                $validEnd = formatSqlsrvDateTime($row['valid_end_utc'] ?? null);

                $result['public_routes'][] = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'adv_number' => $row['adv_number'],
                    'route_string' => $row['route_string'],
                    'advisory_text' => $row['advisory_text'],
                    'color' => $row['color'],
                    'line_weight' => $row['line_weight'],
                    'line_style' => $row['line_style'],
                    'valid_start_utc' => $validStart,
                    'valid_end_utc' => $validEnd,
                    'constrained_area' => $row['constrained_area'],
                    'reason' => $row['reason'],
                    'origin_filter' => $row['origin_filter'],
                    'dest_filter' => $row['dest_filter'],
                    'facilities' => $row['facilities'],
                    'route_geojson' => $row['route_geojson'],
                    'tmi_type' => 'PUBLIC_ROUTE',
                    'status_label' => 'Public Route'
                ];
            }
            sqlsrv_free_stmt($pr_stmt);
        } else {
            // Log query error for debugging
            $errors = sqlsrv_errors();
            $result['debug']['public_routes_error'] = $errors;
        }
    }

    // =========================================
    // 5. MITs and AFPs (Azure SQL - VATSIM_TMI.tmi_entries)
    // =========================================
    $conn_tmi_lazy = $conn_tmi_lazy ?? get_conn_tmi();
    if ($conn_tmi_lazy) {
        $entries_sql = "SELECT entry_id, entry_type, ctl_element, restriction_value,
                               restriction_unit, requesting_facility, providing_facility,
                               reason_code, valid_from, valid_until, status
                        FROM dbo.tmi_entries
                        WHERE status = 'ACTIVE'
                          AND entry_type IN ('MIT', 'AFP', 'MINIT', 'DSP')
                          AND (valid_until IS NULL OR valid_until > GETUTCDATE())
                        ORDER BY valid_from DESC";
        $entries_stmt = @sqlsrv_query($conn_tmi_lazy, $entries_sql);

        if ($entries_stmt) {
            // Ensure ADL connection is available for fix lookups
            $conn_adl_lazy = $conn_adl_lazy ?? get_conn_adl();

            while ($row = sqlsrv_fetch_array($entries_stmt, SQLSRV_FETCH_ASSOC)) {
                $validFrom = formatSqlsrvDateTime($row['valid_from'] ?? null);
                $validUntil = formatSqlsrvDateTime($row['valid_until'] ?? null);

                $fix_lat = null;
                $fix_lon = null;
                $ctl = $row['ctl_element'] ?? '';
                if ($ctl !== '' && $conn_adl_lazy) {
                    $coords = resolveFixLatLon($ctl, $conn_adl_lazy, $fix_cache);
                    if ($coords) {
                        $fix_lat = $coords['lat'];
                        $fix_lon = $coords['lon'];
                    }
                }

                $entry = [
                    'entry_id' => (int)$row['entry_id'],
                    'entry_type' => $row['entry_type'],
                    'ctl_element' => $row['ctl_element'],
                    'restriction_value' => $row['restriction_value'],
                    'restriction_unit' => $row['restriction_unit'],
                    'requesting_facility' => $row['requesting_facility'],
                    'providing_facility' => $row['providing_facility'],
                    'reason_code' => $row['reason_code'],
                    'valid_from' => $validFrom,
                    'valid_until' => $validUntil,
                    'status' => $row['status'],
                    'fix_lat' => $fix_lat,
                    'fix_lon' => $fix_lon,
                ];

                $type = $row['entry_type'] ?? '';
                if ($type === 'MIT' || $type === 'MINIT') {
                    $result['mits'][] = $entry;
                } else {
                    $result['afps'][] = $entry;
                }
            }
            sqlsrv_free_stmt($entries_stmt);
        }
    }

    // =========================================
    // 6. Delay Reports (Azure SQL - VATSIM_TMI.vw_tmi_current_delays)
    // =========================================
    $conn_tmi_lazy = $conn_tmi_lazy ?? get_conn_tmi();
    if ($conn_tmi_lazy) {
        $delays_sql = "SELECT * FROM dbo.vw_tmi_current_delays ORDER BY delay_minutes DESC";
        $delays_stmt = @sqlsrv_query($conn_tmi_lazy, $delays_sql);

        if ($delays_stmt) {
            $conn_adl_lazy = $conn_adl_lazy ?? get_conn_adl();

            while ($row = sqlsrv_fetch_array($delays_stmt, SQLSRV_FETCH_ASSOC)) {
                $timestampUtc = formatSqlsrvDateTime($row['timestamp_utc'] ?? null);

                $holding_lat = null;
                $holding_lon = null;
                $holdingFix = $row['holding_fix'] ?? '';
                if ($holdingFix !== '' && $conn_adl_lazy) {
                    $coords = resolveFixLatLon($holdingFix, $conn_adl_lazy, $fix_cache);
                    if ($coords) {
                        $holding_lat = $coords['lat'];
                        $holding_lon = $coords['lon'];
                    }
                }

                $result['delays'][] = [
                    'delay_id' => (int)($row['delay_id'] ?? 0),
                    'delay_type' => $row['delay_type'] ?? null,
                    'airport' => $row['airport'] ?? null,
                    'facility' => $row['facility'] ?? null,
                    'timestamp_utc' => $timestampUtc,
                    'delay_minutes' => (int)($row['delay_minutes'] ?? 0),
                    'delay_trend' => $row['delay_trend'] ?? null,
                    'holding_status' => $row['holding_status'] ?? null,
                    'holding_fix' => $row['holding_fix'] ?? null,
                    'holding_fix_lat' => $holding_lat,
                    'holding_fix_lon' => $holding_lon,
                    'reason' => $row['reason'] ?? null,
                    'program_id' => $row['program_id'] ?? null,
                ];

                // Collect airport code for coordinate lookup
                $apt = $row['airport'] ?? null;
                if ($apt) {
                    $airport_codes[] = $apt;
                }
            }
            sqlsrv_free_stmt($delays_stmt);
        }
    }

    // =========================================
    // 7. Airport Coordinates (ADL - dbo.apts)
    // =========================================
    $airport_codes = array_unique(array_filter($airport_codes));
    if (!empty($airport_codes)) {
        $conn_adl_lazy = $conn_adl_lazy ?? get_conn_adl();
        if ($conn_adl_lazy) {
            $code_count = count($airport_codes);
            $placeholders = implode(',', array_fill(0, $code_count, '?'));
            $apt_sql = "SELECT ARPT_ID, ICAO_ID, LAT_DECIMAL, LONG_DECIMAL
                        FROM dbo.apts
                        WHERE ARPT_ID IN ($placeholders) OR ICAO_ID IN ($placeholders)";

            // Double the params array: once for ARPT_ID, once for ICAO_ID
            $apt_params = array_merge(array_values($airport_codes), array_values($airport_codes));
            $apt_stmt = @sqlsrv_query($conn_adl_lazy, $apt_sql, $apt_params);

            if ($apt_stmt) {
                $airports_map = [];
                while ($row = sqlsrv_fetch_array($apt_stmt, SQLSRV_FETCH_ASSOC)) {
                    $lat = (float)($row['LAT_DECIMAL'] ?? 0);
                    $lon = (float)($row['LONG_DECIMAL'] ?? 0);
                    $coord = ['lat' => $lat, 'lon' => $lon];

                    if (!empty($row['ARPT_ID'])) {
                        $airports_map[$row['ARPT_ID']] = $coord;
                    }
                    if (!empty($row['ICAO_ID'])) {
                        $airports_map[$row['ICAO_ID']] = $coord;
                    }
                }
                sqlsrv_free_stmt($apt_stmt);

                $result['airports'] = !empty($airports_map) ? $airports_map : (object)[];
            }
        }
    }

    // =========================================
    // Summary
    // =========================================
    $result['summary']['total_gs'] = count($result['ground_stops']);
    $result['summary']['total_gdp'] = count($result['gdps']);
    $result['summary']['total_reroutes'] = count($result['reroutes']);
    $result['summary']['total_public_routes'] = count($result['public_routes']);
    $result['summary']['total_mits'] = count($result['mits']);
    $result['summary']['total_afps'] = count($result['afps']);
    $result['summary']['total_delays'] = count($result['delays']);
    $result['summary']['has_active_tmi'] = (
        $result['summary']['total_gs'] > 0 ||
        $result['summary']['total_gdp'] > 0 ||
        $result['summary']['total_reroutes'] > 0 ||
        $result['summary']['total_mits'] > 0
    );

    echo json_encode($result);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'ground_stops' => [],
        'gdps' => [],
        'reroutes' => [],
        'public_routes' => [],
        'mits' => [],
        'afps' => [],
        'delays' => [],
        'airports' => (object)[],
        'summary' => [
            'total_gs' => 0,
            'total_gdp' => 0,
            'total_reroutes' => 0,
            'total_public_routes' => 0,
            'total_mits' => 0,
            'total_afps' => 0,
            'total_delays' => 0,
            'has_active_tmi' => false
        ]
    ]);
}
