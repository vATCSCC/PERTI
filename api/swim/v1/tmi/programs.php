<?php
/**
 * VATSWIM API v1 - TMI Programs Endpoint
 *
 * Returns active Traffic Management Initiative programs (Ground Stops, GDPs).
 *
 * Data Sources:
 * - Ground Stops: Azure SQL (dbo.ntml table - new GDT schema)
 * - GDP Programs: Azure SQL (dbo.ntml for new programs, dbo.gdp_log for legacy)
 *
 * @version 2.0.0 - Updated to use dbo.ntml for Ground Stops (new GDT schema)
 */

require_once __DIR__ . '/../auth.php';

// Get database connections
global $conn_sqli, $conn_adl, $conn_swim;

// All TMI queries use Azure SQL (SWIM_API or VATSIM_ADL)
$conn_sql = $conn_swim ?: $conn_adl;

$auth = swim_init_auth(true, false);

$type = swim_get_param('type', 'all');
$airport = swim_get_param('airport');
$artcc = swim_get_param('artcc');
$include_history = swim_get_param('include_history', 'false') === 'true';

$response = [
    'ground_stops' => [],
    'gdp_programs' => [],
    'summary' => [
        'active_ground_stops' => 0,
        'active_gdp_programs' => 0,
        'total_controlled_airports' => 0
    ]
];

// ============================================================================
// GROUND STOPS - Azure SQL (dbo.ntml table - new GDT schema)
// ============================================================================
if ($type === 'all' || $type === 'gs') {
    $gs_where = ["n.program_type = 'GS'"];
    $gs_params = [];

    if ($include_history) {
        $gs_where[] = "(n.status = 'ACTIVE' OR (n.status NOT IN ('ACTIVE') AND n.end_utc >= DATEADD(HOUR, -2, GETUTCDATE())))";
    } else {
        $gs_where[] = "n.status = 'ACTIVE'";
    }

    if ($airport) {
        $airport_list = array_map('trim', explode(',', strtoupper($airport)));
        $placeholders = implode(',', array_fill(0, count($airport_list), '?'));
        $gs_where[] = "n.ctl_element IN ($placeholders)";
        $gs_params = array_merge($gs_params, $airport_list);
    }

    if ($artcc) {
        $artcc_list = array_map('trim', explode(',', strtoupper($artcc)));
        $placeholders = implode(',', array_fill(0, count($artcc_list), '?'));
        $gs_where[] = "a.RESP_ARTCC_ID IN ($placeholders)";
        $gs_params = array_merge($gs_params, $artcc_list);
    }

    $gs_sql = "
        SELECT
            n.program_id,
            n.program_guid,
            n.ctl_element,
            n.element_type,
            n.program_name,
            n.adv_number,
            n.start_utc,
            n.end_utc,
            n.cumulative_start,
            n.cumulative_end,
            n.status,
            n.is_active,
            n.scope_type,
            n.scope_tier,
            n.scope_json,
            n.impacting_condition,
            n.cause_text,
            n.comments,
            n.prob_extension,
            n.total_flights,
            n.controlled_flights,
            n.exempt_flights,
            n.airborne_flights,
            n.avg_delay_min,
            n.max_delay_min,
            n.total_delay_min,
            n.flt_incl_carrier,
            n.flt_incl_type,
            n.activated_utc,
            n.created_utc,
            a.ARPT_NAME as airport_name,
            a.RESP_ARTCC_ID as artcc
        FROM dbo.ntml n
        LEFT JOIN dbo.apts a ON n.ctl_element = a.ICAO_ID
        WHERE " . implode(' AND ', $gs_where) . "
        ORDER BY n.ctl_element, n.start_utc DESC
    ";

    try {
        $gs_stmt = sqlsrv_query($conn_sql, $gs_sql, $gs_params);

        if ($gs_stmt !== false) {
            while ($row = sqlsrv_fetch_array($gs_stmt, SQLSRV_FETCH_ASSOC)) {
                // Parse scope JSON for dep_facilities
                $scope_data = null;
                if (!empty($row['scope_json'])) {
                    $scope_data = json_decode($row['scope_json'], true);
                }

                $response['ground_stops'][] = [
                    'type' => 'ground_stop',
                    'program_id' => $row['program_id'],
                    'program_guid' => $row['program_guid'],
                    'airport' => $row['ctl_element'],
                    'airport_name' => $row['airport_name'],
                    'artcc' => $row['artcc'],
                    'name' => $row['program_name'] ?? 'CDM GROUND STOP',
                    'reason' => $row['impacting_condition'],
                    'reason_detail' => $row['cause_text'],
                    'comments' => $row['comments'],
                    'probability_of_extension' => $row['prob_extension'],
                    'scope' => [
                        'type' => $row['scope_type'],
                        'tier' => $row['scope_tier'],
                        'dep_facilities' => $scope_data['dep_facilities'] ?? null,
                        'origin_centers' => $scope_data['origin_centers'] ?? null
                    ],
                    'times' => [
                        'start' => formatDT($row['start_utc']),
                        'end' => formatDT($row['end_utc']),
                        'cumulative_start' => formatDT($row['cumulative_start']),
                        'cumulative_end' => formatDT($row['cumulative_end']),
                        'activated' => formatDT($row['activated_utc'])
                    ],
                    'delays' => [
                        'total_minutes' => (int)($row['total_delay_min'] ?? 0),
                        'average_minutes' => (int)($row['avg_delay_min'] ?? 0),
                        'maximum_minutes' => (int)($row['max_delay_min'] ?? 0)
                    ],
                    'flights' => [
                        'total' => (int)($row['total_flights'] ?? 0),
                        'controlled' => (int)($row['controlled_flights'] ?? 0),
                        'exempt' => (int)($row['exempt_flights'] ?? 0),
                        'airborne' => (int)($row['airborne_flights'] ?? 0)
                    ],
                    'filters' => [
                        'carrier' => $row['flt_incl_carrier'],
                        'aircraft_type' => $row['flt_incl_type']
                    ],
                    'advisory' => [
                        'number' => $row['adv_number']
                    ],
                    'status' => $row['status'],
                    'is_active' => (bool)$row['is_active']
                ];

                if ($row['status'] === 'ACTIVE') {
                    $response['summary']['active_ground_stops']++;
                }
            }
            sqlsrv_free_stmt($gs_stmt);
        } else {
            $errors = sqlsrv_errors();
            error_log("SWIM TMI Programs - GS SQL Server error: " . ($errors[0]['message'] ?? 'Unknown'));
        }
    } catch (Exception $e) {
        error_log("SWIM TMI Programs - GS error: " . $e->getMessage());
    }
}

// ============================================================================
// GDP PROGRAMS - Azure SQL (dbo.ntml for new programs + dbo.gdp_log for legacy)
// ============================================================================
if ($type === 'all' || $type === 'gdp') {
    // First, query new GDP programs from dbo.ntml (GDP-DAS, GDP-GAAP, GDP-UDP)
    $gdp_ntml_where = ["n.program_type LIKE 'GDP%'"];
    $gdp_ntml_params = [];

    if ($include_history) {
        $gdp_ntml_where[] = "(n.status = 'ACTIVE' OR (n.status NOT IN ('ACTIVE') AND n.end_utc >= DATEADD(HOUR, -2, GETUTCDATE())))";
    } else {
        $gdp_ntml_where[] = "n.status = 'ACTIVE'";
    }

    if ($airport) {
        $airport_list = array_map('trim', explode(',', strtoupper($airport)));
        $placeholders = implode(',', array_fill(0, count($airport_list), '?'));
        $gdp_ntml_where[] = "n.ctl_element IN ($placeholders)";
        $gdp_ntml_params = array_merge($gdp_ntml_params, $airport_list);
    }

    if ($artcc) {
        $artcc_list = array_map('trim', explode(',', strtoupper($artcc)));
        $placeholders = implode(',', array_fill(0, count($artcc_list), '?'));
        $gdp_ntml_where[] = "a.RESP_ARTCC_ID IN ($placeholders)";
        $gdp_ntml_params = array_merge($gdp_ntml_params, $artcc_list);
    }

    $gdp_ntml_sql = "
        SELECT
            n.program_id,
            n.program_guid,
            n.ctl_element,
            n.program_type,
            n.program_name,
            n.adv_number,
            n.start_utc,
            n.end_utc,
            n.status,
            n.is_active,
            n.program_rate,
            n.reserve_rate,
            n.delay_limit_min,
            n.scope_json,
            n.impacting_condition,
            n.prob_extension,
            n.total_flights,
            n.controlled_flights,
            n.avg_delay_min,
            n.max_delay_min,
            n.total_delay_min,
            a.ARPT_NAME as airport_name,
            a.RESP_ARTCC_ID as artcc
        FROM dbo.ntml n
        LEFT JOIN dbo.apts a ON n.ctl_element = a.ICAO_ID
        WHERE " . implode(' AND ', $gdp_ntml_where) . "
        ORDER BY n.ctl_element, n.start_utc DESC
    ";

    try {
        $gdp_ntml_stmt = sqlsrv_query($conn_sql, $gdp_ntml_sql, $gdp_ntml_params);

        if ($gdp_ntml_stmt !== false) {
            while ($row = sqlsrv_fetch_array($gdp_ntml_stmt, SQLSRV_FETCH_ASSOC)) {
                $response['gdp_programs'][] = [
                    'type' => 'gdp',
                    'program_id' => $row['program_id'],
                    'program_guid' => $row['program_guid'],
                    'program_type' => $row['program_type'],
                    'airport' => $row['ctl_element'],
                    'airport_name' => $row['airport_name'],
                    'artcc' => $row['artcc'],
                    'name' => $row['program_name'],
                    'reason' => $row['impacting_condition'],
                    'probability_of_extension' => $row['prob_extension'],
                    'rates' => [
                        'program_rate' => (int)($row['program_rate'] ?? 0),
                        'reserve_rate' => (int)($row['reserve_rate'] ?? 0)
                    ],
                    'delays' => [
                        'limit_minutes' => (int)($row['delay_limit_min'] ?? 180),
                        'total_minutes' => (int)($row['total_delay_min'] ?? 0),
                        'average_minutes' => (int)($row['avg_delay_min'] ?? 0),
                        'maximum_minutes' => (int)($row['max_delay_min'] ?? 0)
                    ],
                    'times' => [
                        'start' => formatDT($row['start_utc']),
                        'end' => formatDT($row['end_utc'])
                    ],
                    'flights' => [
                        'total' => (int)($row['total_flights'] ?? 0),
                        'controlled' => (int)($row['controlled_flights'] ?? 0)
                    ],
                    'advisory' => [
                        'number' => $row['adv_number']
                    ],
                    'status' => $row['status'],
                    'is_active' => (bool)$row['is_active'],
                    'source' => 'ntml'
                ];

                if ($row['status'] === 'ACTIVE') {
                    $response['summary']['active_gdp_programs']++;
                }
            }
            sqlsrv_free_stmt($gdp_ntml_stmt);
        }
    } catch (Exception $e) {
        error_log("SWIM TMI Programs - GDP NTML error: " . $e->getMessage());
    }

    // Also query legacy GDP programs from gdp_log (for backwards compatibility)
    $gdp_where = [];
    $gdp_params = [];

    if ($include_history) {
        $gdp_where[] = "(g.status = 'ACTIVE' OR (g.status != 'ACTIVE' AND g.program_end_utc >= DATEADD(HOUR, -2, GETUTCDATE())))";
    } else {
        $gdp_where[] = "g.status = 'ACTIVE'";
    }

    if ($airport) {
        $airport_list = array_map('trim', explode(',', strtoupper($airport)));
        $placeholders = implode(',', array_fill(0, count($airport_list), '?'));
        $gdp_where[] = "g.ctl_element IN ($placeholders)";
        $gdp_params = array_merge($gdp_params, $airport_list);
    }

    if ($artcc) {
        $artcc_list = array_map('trim', explode(',', strtoupper($artcc)));
        $placeholders = implode(',', array_fill(0, count($artcc_list), '?'));
        $gdp_where[] = "a.RESP_ARTCC_ID IN ($placeholders)";
        $gdp_params = array_merge($gdp_params, $artcc_list);
    }

    $gdp_sql = "
        SELECT g.id, g.program_id, g.ctl_element, g.adv_number,
               g.program_start_utc, g.program_end_utc, g.program_rate,
               g.delay_limit_minutes, g.scope_centers, g.status,
               g.impacting_condition, g.probability_of_extension,
               g.total_flights, g.affected_flights, g.avg_delay_min, g.max_delay_min,
               a.ARPT_NAME as airport_name, a.RESP_ARTCC_ID as artcc
        FROM dbo.gdp_log g
        LEFT JOIN dbo.apts a ON g.ctl_element = a.ICAO_ID
        WHERE " . implode(' AND ', $gdp_where) . "
        ORDER BY g.ctl_element";

    try {
        $gdp_stmt = sqlsrv_query($conn_sql, $gdp_sql, $gdp_params);
        if ($gdp_stmt !== false) {
            while ($row = sqlsrv_fetch_array($gdp_stmt, SQLSRV_FETCH_ASSOC)) {
                $response['gdp_programs'][] = [
                    'id' => $row['id'],
                    'type' => 'gdp',
                    'program_id' => $row['program_id'],
                    'airport' => $row['ctl_element'],
                    'airport_name' => $row['airport_name'],
                    'artcc' => $row['artcc'],
                    'reason' => $row['impacting_condition'],
                    'probability_of_extension' => intval($row['probability_of_extension']),
                    'rates' => ['program_rate' => intval($row['program_rate'])],
                    'delays' => [
                        'limit_minutes' => intval($row['delay_limit_minutes']),
                        'average_minutes' => intval($row['avg_delay_min']),
                        'maximum_minutes' => intval($row['max_delay_min'])
                    ],
                    'times' => [
                        'start' => formatDT($row['program_start_utc']),
                        'end' => formatDT($row['program_end_utc'])
                    ],
                    'flights' => [
                        'total' => intval($row['total_flights']),
                        'affected' => intval($row['affected_flights'])
                    ],
                    'status' => $row['status'],
                    'is_active' => ($row['status'] === 'ACTIVE'),
                    'source' => 'gdp_log'
                ];
                if ($row['status'] === 'ACTIVE') $response['summary']['active_gdp_programs']++;
            }
            sqlsrv_free_stmt($gdp_stmt);
        }
    } catch (Exception $e) {
        error_log("SWIM TMI Programs - GDP legacy error: " . $e->getMessage());
    }
}

$controlled_airports = array_unique(array_merge(
    array_column($response['ground_stops'], 'airport'),
    array_column($response['gdp_programs'], 'airport')
));
$response['summary']['total_controlled_airports'] = count($controlled_airports);

SwimResponse::success($response, ['source' => 'vatcscc', 'type_filter' => $type]);

function getAirportInfo($icao) {
    global $conn_adl, $conn_swim;
    $conn = $conn_swim ?: $conn_adl;
    if (!$conn) return null;
    
    $stmt = sqlsrv_query($conn, "SELECT ICAO_ID, ARPT_NAME, RESP_ARTCC_ID FROM dbo.apts WHERE ICAO_ID = ?", [$icao]);
    if ($stmt === false) return null;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return $row ? ['icao' => $row['ICAO_ID'], 'name' => $row['ARPT_NAME'], 'artcc' => $row['RESP_ARTCC_ID']] : null;
}

function formatDT($dt) {
    if ($dt === null) return null;
    return ($dt instanceof DateTime) ? $dt->format('c') : $dt;
}
