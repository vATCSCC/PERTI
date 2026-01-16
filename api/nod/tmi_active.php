<?php
/**
 * NOD Active TMIs API
 * 
 * Consolidates active TMIs from:
 * - Ground Stops (MySQL tmi_ground_stops)
 * - GDPs (Azure SQL gdp_log)
 * - Reroutes (Azure SQL tmi_reroutes)
 * - Public Routes (Azure SQL public_routes with active status)
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
    'summary' => [
        'total_gs' => 0,
        'total_gdp' => 0,
        'total_reroutes' => 0,
        'total_public_routes' => 0,
        'has_active_tmi' => false
    ],
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z')
];

try {
    // =========================================
    // 1. Ground Stops (MySQL)
    // =========================================
    if (isset($conn) && $conn) {
        $gs_sql = "SELECT * FROM tmi_ground_stops WHERE status = 1 ORDER BY start_utc DESC";
        $gs_result = mysqli_query($conn, $gs_sql);
        
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
                    'tmi_type' => 'GS',
                    'status_label' => 'Ground Stop'
                ];
            }
            mysqli_free_result($gs_result);
        }
    }
    
    // =========================================
    // 2. GDPs (Azure SQL - gdp_log)
    // =========================================
    if (isset($conn_adl) && $conn_adl) {
        // Check if gdp_log table exists and has active programs
        $gdp_sql = "SELECT TOP 20 * FROM dbo.gdp_log 
                    WHERE status = 'ACTIVE' 
                    ORDER BY created_at DESC";
        
        $gdp_stmt = @sqlsrv_query($conn_adl, $gdp_sql);
        
        if ($gdp_stmt) {
            while ($row = sqlsrv_fetch_array($gdp_stmt, SQLSRV_FETCH_ASSOC)) {
                // Format datetime fields
                $startTime = isset($row['start_time']) && $row['start_time'] instanceof DateTime 
                    ? $row['start_time']->format('Y-m-d\TH:i:s\Z') : $row['start_time'];
                $endTime = isset($row['end_time']) && $row['end_time'] instanceof DateTime 
                    ? $row['end_time']->format('Y-m-d\TH:i:s\Z') : $row['end_time'];
                
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
                    'tmi_type' => 'GDP',
                    'status_label' => 'Ground Delay Program'
                ];
            }
            sqlsrv_free_stmt($gdp_stmt);
        }
        
        // =========================================
        // 3. Reroutes (Azure SQL - tmi_reroutes)
        // =========================================
        $rr_sql = "SELECT TOP 50 
                       r.id, r.name, r.adv_number, r.route_string, r.advisory_text,
                       r.constrained_area, r.reason, r.valid_start_utc, r.valid_end_utc,
                       r.origin_filter, r.dest_filter, r.facilities, r.status, r.color,
                       r.avoid_fixes, r.protect_fixes, r.altitude_filter,
                       r.route_geojson,
                       r.created_by, r.created_at,
                       (SELECT COUNT(*) FROM dbo.tmi_reroute_flights WHERE reroute_id = r.id) as flight_count
                   FROM dbo.tmi_reroutes r
                   WHERE r.status = 'ACTIVE'
                     AND r.valid_start_utc <= GETUTCDATE()
                     AND (r.valid_end_utc IS NULL OR r.valid_end_utc > GETUTCDATE())
                   ORDER BY r.created_at DESC";
        
        $rr_stmt = @sqlsrv_query($conn_adl, $rr_sql);
        
        if ($rr_stmt) {
            while ($row = sqlsrv_fetch_array($rr_stmt, SQLSRV_FETCH_ASSOC)) {
                $validStart = isset($row['valid_start_utc']) && $row['valid_start_utc'] instanceof DateTime 
                    ? $row['valid_start_utc']->format('Y-m-d\TH:i:s\Z') : $row['valid_start_utc'];
                $validEnd = isset($row['valid_end_utc']) && $row['valid_end_utc'] instanceof DateTime 
                    ? $row['valid_end_utc']->format('Y-m-d\TH:i:s\Z') : $row['valid_end_utc'];
                
                $result['reroutes'][] = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'adv_number' => $row['adv_number'],
                    'route_string' => $row['route_string'],
                    'advisory_text' => $row['advisory_text'],
                    'constrained_area' => $row['constrained_area'],
                    'reason' => $row['reason'],
                    'valid_start_utc' => $validStart,
                    'valid_end_utc' => $validEnd,
                    'origin_filter' => $row['origin_filter'],
                    'dest_filter' => $row['dest_filter'],
                    'facilities' => $row['facilities'],
                    'color' => $row['color'],
                    'avoid_fixes' => $row['avoid_fixes'],
                    'protect_fixes' => $row['protect_fixes'],
                    'altitude_filter' => $row['altitude_filter'],
                    'route_geojson' => $row['route_geojson'],
                    'flight_count' => (int)$row['flight_count'],
                    'tmi_type' => 'REROUTE',
                    'status_label' => 'Reroute'
                ];
            }
            sqlsrv_free_stmt($rr_stmt);
        }
        
        // =========================================
        // 4. Public Routes (Azure SQL - public_routes)
        // =========================================
        
        // Debug: count total routes in table
        $count_sql = "SELECT COUNT(*) as total FROM dbo.public_routes";
        $count_stmt = @sqlsrv_query($conn_adl, $count_sql);
        if ($count_stmt) {
            $count_row = sqlsrv_fetch_array($count_stmt, SQLSRV_FETCH_ASSOC);
            $result['debug']['total_routes_in_table'] = (int)($count_row['total'] ?? 0);
            sqlsrv_free_stmt($count_stmt);
        }
        
        // Show routes that are active (either no time filter or currently within time window)
        $pr_sql = "SELECT TOP 50 
                       id, name, adv_number, route_string, advisory_text,
                       color, line_weight, line_style,
                       valid_start_utc, valid_end_utc,
                       constrained_area, reason, origin_filter, dest_filter, facilities,
                       route_geojson,
                       created_by, created_at
                   FROM dbo.public_routes
                   WHERE (valid_start_utc IS NULL OR valid_start_utc <= GETUTCDATE())
                     AND (valid_end_utc IS NULL OR valid_end_utc > GETUTCDATE())
                   ORDER BY created_at DESC";
        
        $pr_stmt = @sqlsrv_query($conn_adl, $pr_sql);
        
        if ($pr_stmt) {
            while ($row = sqlsrv_fetch_array($pr_stmt, SQLSRV_FETCH_ASSOC)) {
                $validStart = isset($row['valid_start_utc']) && $row['valid_start_utc'] instanceof DateTime 
                    ? $row['valid_start_utc']->format('Y-m-d\TH:i:s\Z') : $row['valid_start_utc'];
                $validEnd = isset($row['valid_end_utc']) && $row['valid_end_utc'] instanceof DateTime 
                    ? $row['valid_end_utc']->format('Y-m-d\TH:i:s\Z') : $row['valid_end_utc'];
                
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
    // Summary
    // =========================================
    $result['summary']['total_gs'] = count($result['ground_stops']);
    $result['summary']['total_gdp'] = count($result['gdps']);
    $result['summary']['total_reroutes'] = count($result['reroutes']);
    $result['summary']['total_public_routes'] = count($result['public_routes']);
    $result['summary']['has_active_tmi'] = (
        $result['summary']['total_gs'] > 0 ||
        $result['summary']['total_gdp'] > 0 ||
        $result['summary']['total_reroutes'] > 0
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
        'summary' => [
            'total_gs' => 0,
            'total_gdp' => 0,
            'total_reroutes' => 0,
            'total_public_routes' => 0,
            'has_active_tmi' => false
        ]
    ]);
}
