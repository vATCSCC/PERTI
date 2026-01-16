<?php
/**
 * VATSIM SWIM API v1 - TMI Programs Endpoint
 * 
 * Returns active Traffic Management Initiative programs (Ground Stops, GDPs).
 * Ground Stops: MySQL (tmi_ground_stops)
 * GDP Programs: Azure SQL (gdp_log)
 * 
 * @version 1.2.0 - Added error handling
 */

require_once __DIR__ . '/../auth.php';

// Get database connections
// MySQL: $conn_sqli for Ground Stops (tmi_ground_stops table)
// Azure SQL: $conn_adl for GDP programs (gdp_log table)
global $conn_sqli, $conn_adl, $conn_swim;

// GDP queries can use SWIM_API if available, fall back to VATSIM_ADL
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

// GROUND STOPS - MySQL
if ($type === 'all' || $type === 'gs') {
    // Check if MySQL connection is available
    if (isset($conn_sqli) && $conn_sqli) {
        $gs_where = [];
        $gs_params = [];
        $gs_types = '';
        
        if ($include_history) {
            $gs_where[] = "(status = 1 OR (status != 1 AND end_utc >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 HOUR)))";
        } else {
            $gs_where[] = "status = 1";
        }
        
        if ($airport) {
            $airport_list = array_map('trim', explode(',', strtoupper($airport)));
            $placeholders = implode(',', array_fill(0, count($airport_list), '?'));
            $gs_where[] = "ctl_element IN ($placeholders)";
            $gs_params = array_merge($gs_params, $airport_list);
            $gs_types .= str_repeat('s', count($airport_list));
        }
        
        $gs_sql = "SELECT name, ctl_element, element_type, airports, start_utc, end_utc, prob_ext,
                          origin_centers, origin_airports, comments, adv_number, advisory_text, status
                   FROM tmi_ground_stops WHERE " . implode(' AND ', $gs_where) . " ORDER BY ctl_element";
        
        try {
            if (!empty($gs_params)) {
                $gs_stmt = $conn_sqli->prepare($gs_sql);
                if ($gs_stmt) {
                    $gs_stmt->bind_param($gs_types, ...$gs_params);
                    $gs_stmt->execute();
                    $gs_result = $gs_stmt->get_result();
                }
            } else {
                $gs_result = $conn_sqli->query($gs_sql);
            }
            
            if ($gs_result) {
                while ($row = $gs_result->fetch_assoc()) {
                    $airport_info = getAirportInfo($row['ctl_element']);
                    
                    if ($artcc) {
                        $artcc_list = array_map('trim', explode(',', strtoupper($artcc)));
                        if ($airport_info && !in_array($airport_info['artcc'], $artcc_list)) continue;
                    }
                    
                    $response['ground_stops'][] = [
                        'type' => 'ground_stop',
                        'airport' => $row['ctl_element'],
                        'airport_name' => $airport_info ? $airport_info['name'] : null,
                        'artcc' => $airport_info ? $airport_info['artcc'] : null,
                        'name' => $row['name'],
                        'reason' => $row['comments'],
                        'probability_of_extension' => intval($row['prob_ext']),
                        'times' => ['start' => $row['start_utc'], 'end' => $row['end_utc']],
                        'advisory' => ['number' => $row['adv_number'], 'text' => $row['advisory_text']],
                        'is_active' => ($row['status'] == 1)
                    ];
                    
                    if ($row['status'] == 1) $response['summary']['active_ground_stops']++;
                }
                if (isset($gs_stmt)) $gs_stmt->close();
            }
        } catch (Exception $e) {
            // Log error but continue - GDPs may still work
            error_log("SWIM TMI Programs - MySQL error: " . $e->getMessage());
        }
    }
}

// GDP PROGRAMS - Azure SQL
if ($type === 'all' || $type === 'gdp') {
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
                'is_active' => ($row['status'] === 'ACTIVE')
            ];
            if ($row['status'] === 'ACTIVE') $response['summary']['active_gdp_programs']++;
        }
        sqlsrv_free_stmt($gdp_stmt);
    } else {
        // Log SQL Server error
        $errors = sqlsrv_errors();
        error_log("SWIM TMI Programs - SQL Server error: " . ($errors[0]['message'] ?? 'Unknown'));
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
