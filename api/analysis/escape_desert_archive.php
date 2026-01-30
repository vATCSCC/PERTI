<?php
/**
 * api/analysis/escape_desert_archive.php
 * 
 * GET - Retrieve historical flight archive data for Escape the Desert FNO
 * Event: January 17-18, 2026, 2300Z - 0400Z
 * 
 * No authentication required for read-only archive access
 * 
 * Parameters:
 *   include  - What to include: summary, flights, trajectory, fixes, demand (comma-separated)
 *   dest     - Filter by destination ICAO (default: all event airports)
 *   format   - Output format: json (default), csv
 * 
 * Examples:
 *   ?include=summary
 *   ?include=flights,fixes&dest=KLAS
 *   ?include=trajectory&dest=KLAS
 *   ?include=demand
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=3600'); // Cache for 1 hour

require_once __DIR__ . '/../../load/config.php';

// Event parameters
$EVENT_START = '2026-01-17 22:00:00';
$EVENT_END = '2026-01-18 05:00:00';
$EVENT_AIRPORTS = ['KLAS', 'KVGT', 'KHND', 'KSFO', 'KOAK', 'KSJC', 'KSMF'];
$MIT_FIXES = ['FLCHR', 'ELLDA', 'HAHAA', 'GGAPP', 'STEWW', 'TYEGR', 'NTELL', 'RUSME', 'INYOE', 'LEGGS', 'OAL'];

// TMI reference data for the event
$TMI_REFERENCE = [
    ['fix' => 'FLCHR', 'dest' => 'KLAS', 'mit_nm' => 20, 'provider' => 'ZLA', 'requestor' => 'ZOA', 'time' => '2359-0400'],
    ['fix' => 'ELLDA', 'dest' => 'KLAS', 'mit_nm' => 20, 'provider' => 'ZLA', 'requestor' => 'ZAB', 'time' => '2359-0400'],
    ['fix' => 'HAHAA', 'dest' => 'KLAS', 'mit_nm' => 30, 'provider' => 'ZLA', 'requestor' => 'ZAB', 'time' => '2359-0400'],
    ['fix' => 'GGAPP/STEWW', 'dest' => 'KLAS', 'mit_nm' => 20, 'provider' => 'ZLA', 'requestor' => 'ZLC', 'time' => '2359-0400', 'as_one' => true],
    ['fix' => 'TYEGR', 'dest' => 'KLAS', 'mit_nm' => 20, 'provider' => 'ZLA', 'requestor' => 'ZDV', 'time' => '2359-0400'],
    ['fix' => 'NTELL', 'dest' => 'KLAS', 'mit_nm' => 15, 'provider' => 'ZOA', 'requestor' => 'NCT', 'time' => '2359-0400'],
    ['fix' => 'ALL', 'dest' => 'KLAS', 'mit_nm' => 35, 'provider' => 'ZOA', 'requestor' => 'ZSE', 'time' => '2359-0400', 'per_stream' => true],
    ['fix' => 'RUSME/INYOE', 'dest' => 'KSFO', 'mit_nm' => 20, 'provider' => 'ZOA', 'requestor' => 'ZLA/ZLC', 'time' => '2359-0400', 'per_fix' => true],
    ['fix' => 'LEGGS', 'dest' => 'KSFO', 'mit_nm' => 35, 'provider' => 'ZOA', 'requestor' => 'ZLC', 'time' => '2300-0300'],
    ['fix' => 'ALL', 'dest' => 'KOAK/KSJC/KSMF', 'mit_nm' => 20, 'provider' => 'ZOA', 'requestor' => 'ZSE/ZLC/ZLA', 'time' => '2359-0400', 'per_stream' => true],
    ['fix' => 'OAL', 'dest' => 'KLAS', 'mit_nm' => 0, 'provider' => 'ZOA', 'requestor' => '-', 'time' => '0242-', 'type' => 'A/D +45'],
];

try {
    // Connect to ADL database using sqlsrv
    $connectionInfo = [
        "Database" => ADL_SQL_DATABASE,
        "UID" => ADL_SQL_USERNAME,
        "PWD" => ADL_SQL_PASSWORD,
        "TrustServerCertificate" => true
    ];
    
    $conn = sqlsrv_connect(ADL_SQL_HOST, $connectionInfo);
    if ($conn === false) {
        throw new Exception("Database connection failed: " . print_r(sqlsrv_errors(), true));
    }
    
    // Parse parameters
    $include = isset($_GET['include']) ? array_map('trim', explode(',', $_GET['include'])) : ['summary'];
    $destFilter = isset($_GET['dest']) ? strtoupper(trim($_GET['dest'])) : null;
    
    // Build destination list
    $destList = $destFilter ? [$destFilter] : $EVENT_AIRPORTS;
    $destIn = "'" . implode("','", $destList) . "'";
    
    $response = [
        'event' => 'Escape the Desert FNO',
        'event_start_utc' => $EVENT_START,
        'event_end_utc' => $EVENT_END,
        'generated_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'tmi_reference' => $TMI_REFERENCE,
        'data' => []
    ];
    
    // =========================================
    // SUMMARY
    // =========================================
    if (in_array('summary', $include)) {
        // Archive range
        $sql = "SELECT MIN(archived_utc) as earliest, MAX(archived_utc) as latest, COUNT(*) as total FROM dbo.adl_flight_archive";
        $stmt = sqlsrv_query($conn, $sql);
        $archiveRange = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        
        // Format dates
        if ($archiveRange['earliest'] instanceof DateTime) {
            $archiveRange['earliest'] = $archiveRange['earliest']->format('Y-m-d H:i:s');
        }
        if ($archiveRange['latest'] instanceof DateTime) {
            $archiveRange['latest'] = $archiveRange['latest']->format('Y-m-d H:i:s');
        }
        
        // Event counts by destination - query live data from flight_core/flight_plan
        $sql = "SELECT p.fp_dest_icao as dest, COUNT(*) as flight_count
                FROM dbo.adl_flight_core c
                INNER JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
                WHERE p.fp_dest_icao IN ($destIn)
                  AND c.first_seen_utc <= '$EVENT_END'
                  AND c.last_seen_utc >= '$EVENT_START'
                GROUP BY p.fp_dest_icao ORDER BY flight_count DESC";
        $stmt = sqlsrv_query($conn, $sql);
        $destCounts = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $destCounts[] = $row;
        }
        sqlsrv_free_stmt($stmt);
        
        // Trajectory count - JOIN to get destination
        $sql = "SELECT COUNT(*) as cnt
                FROM dbo.adl_trajectory_compressed t
                INNER JOIN dbo.adl_flight_plan p ON t.flight_uid = p.flight_uid
                WHERE p.fp_dest_icao IN ($destIn)
                  AND t.first_timestamp_utc <= '$EVENT_END'
                  AND t.last_timestamp_utc >= '$EVENT_START'";
        $stmt = sqlsrv_query($conn, $sql);
        $trajRow = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        
        $response['data']['summary'] = [
            'archive_range' => $archiveRange,
            'event_flights_by_dest' => $destCounts,
            'trajectory_records' => $trajRow['cnt']
        ];
    }
    
    // =========================================
    // FLIGHTS - Query from live flight_core/flight_plan/flight_position tables
    // =========================================
    if (in_array('flights', $include)) {
        $sql = "SELECT
                    c.flight_uid, c.flight_key, c.callsign, c.cid,
                    p.fp_dept_icao, p.fp_dest_icao, p.aircraft_type, p.fp_route, p.fp_altitude_ft,
                    pos.lat as last_lat, pos.lon as last_lon, pos.altitude_ft as last_altitude_ft,
                    pos.heading_deg as last_heading_deg, pos.groundspeed_kts as last_groundspeed_kts,
                    c.first_seen_utc, c.last_seen_utc, c.phase as flight_status,
                    p.fp_dept_artcc as dept_artcc, p.fp_dest_artcc as dest_artcc,
                    p.star_name, p.afix, p.dfix, p.dp_name
                FROM dbo.adl_flight_core c
                INNER JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
                LEFT JOIN dbo.adl_flight_position pos ON c.flight_uid = pos.flight_uid
                WHERE p.fp_dest_icao IN ($destIn)
                  AND c.first_seen_utc <= '$EVENT_END'
                  AND c.last_seen_utc >= '$EVENT_START'
                ORDER BY c.first_seen_utc";
        
        $stmt = sqlsrv_query($conn, $sql);
        if ($stmt === false) {
            throw new Exception("Flights query failed: " . print_r(sqlsrv_errors(), true));
        }
        
        $flights = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Format DateTime objects
            foreach (['first_seen_utc', 'last_seen_utc'] as $field) {
                if (isset($row[$field]) && $row[$field] instanceof DateTime) {
                    $row[$field] = $row[$field]->format('Y-m-d H:i:s');
                }
            }

            // Detect arrival fix from route
            $route = strtoupper($row['fp_route'] ?? '');
            $row['detected_fix'] = 'UNKNOWN';
            $row['arrival_stream'] = 'OTHER';
            
            foreach ($MIT_FIXES as $fix) {
                if (strpos($route, $fix) !== false) {
                    $row['detected_fix'] = $fix;
                    break;
                }
            }
            
            // Categorize by arrival stream
            if (preg_match('/FLCHR|KEPEC|TYSSN/', $route)) {
                $row['arrival_stream'] = 'SOUTH_ZOA_ZLA';
            } elseif (preg_match('/TYEGR|CLARR/', $route)) {
                $row['arrival_stream'] = 'NE_ZDV';
            } elseif (preg_match('/GGAPP|STEWW|PRFUM|SUNST/', $route)) {
                $row['arrival_stream'] = 'NNE_ZLC';
            } elseif (preg_match('/ELLDA|HAHAA|GRNPA|RNCHH/', $route)) {
                $row['arrival_stream'] = 'EAST_ZAB';
            } elseif (preg_match('/NTELL/', $route)) {
                $row['arrival_stream'] = 'NCT_LOCAL';
            }
            
            $flights[] = $row;
        }
        sqlsrv_free_stmt($stmt);
        
        $response['data']['flights'] = $flights;
        $response['data']['flight_count'] = count($flights);
    }
    
    // =========================================
    // FIXES
    // =========================================
    if (in_array('fixes', $include)) {
        $fixIn = "'" . implode("','", $MIT_FIXES) . "'";
        $sql = "SELECT fix_name, fix_type, lat, lon, artcc_id, state_code
                FROM dbo.nav_fixes WHERE fix_name IN ($fixIn) ORDER BY fix_name";
        $stmt = sqlsrv_query($conn, $sql);
        $fixes = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $fixes[] = $row;
        }
        sqlsrv_free_stmt($stmt);
        
        $response['data']['fixes'] = $fixes;
    }
    
    // =========================================
    // TRAJECTORY - JOIN to get dept/dest from flight_plan
    // =========================================
    if (in_array('trajectory', $include)) {
        $sql = "SELECT t.compressed_id, t.callsign, t.flight_uid,
                       p.fp_dept_icao as dept, p.fp_dest_icao as dest,
                       t.first_timestamp_utc, t.last_timestamp_utc,
                       t.point_count, t.trajectory_data,
                       t.anchor_lat, t.anchor_lon
                FROM dbo.adl_trajectory_compressed t
                INNER JOIN dbo.adl_flight_plan p ON t.flight_uid = p.flight_uid
                WHERE p.fp_dest_icao IN ($destIn)
                  AND t.first_timestamp_utc <= '$EVENT_END'
                  AND t.last_timestamp_utc >= '$EVENT_START'
                ORDER BY t.first_timestamp_utc";

        $stmt = sqlsrv_query($conn, $sql);
        $trajectories = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Format dates
            foreach (['first_timestamp_utc', 'last_timestamp_utc'] as $field) {
                if (isset($row[$field]) && $row[$field] instanceof DateTime) {
                    $row[$field] = $row[$field]->format('Y-m-d H:i:s');
                }
            }
            // Trajectory data is stored as binary, convert if needed
            if (!empty($row['trajectory_data'])) {
                // Try to decode as JSON, otherwise leave as-is
                $decoded = @json_decode($row['trajectory_data'], true);
                if ($decoded !== null) {
                    $row['trajectory_data'] = $decoded;
                }
            }
            $trajectories[] = $row;
        }
        sqlsrv_free_stmt($stmt);

        $response['data']['trajectory'] = $trajectories;
        $response['data']['trajectory_count'] = count($trajectories);
    }
    
    // =========================================
    // DEMAND (15-min bins) - Calculate from flight_core/flight_plan
    // =========================================
    if (in_array('demand', $include)) {
        $sql = "SELECT
                    DATEADD(MINUTE, (DATEDIFF(MINUTE, '$EVENT_START', c.last_seen_utc) / 15) * 15, '$EVENT_START') as time_bin,
                    p.fp_dest_icao as dest,
                    COUNT(*) as arrivals
                FROM dbo.adl_flight_core c
                INNER JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
                WHERE p.fp_dest_icao IN ($destIn)
                  AND c.first_seen_utc <= '$EVENT_END'
                  AND c.last_seen_utc >= '$EVENT_START'
                GROUP BY
                    DATEADD(MINUTE, (DATEDIFF(MINUTE, '$EVENT_START', c.last_seen_utc) / 15) * 15, '$EVENT_START'),
                    p.fp_dest_icao
                ORDER BY time_bin, dest";

        $stmt = sqlsrv_query($conn, $sql);
        $demand = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if (isset($row['time_bin']) && $row['time_bin'] instanceof DateTime) {
                $row['time_bin'] = $row['time_bin']->format('Y-m-d H:i:s');
            }
            $demand[] = $row;
        }
        sqlsrv_free_stmt($stmt);

        $response['data']['demand'] = $demand;
    }
    
    sqlsrv_close($conn);
    
    // Output
    if (isset($_GET['format']) && $_GET['format'] === 'csv' && isset($response['data']['flights'])) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="escape_desert_flights.csv"');

        $output = fopen('php://output', 'w');
        if (!empty($response['data']['flights'])) {
            fputcsv($output, array_keys($response['data']['flights'][0]));
            foreach ($response['data']['flights'] as $row) {
                $row['fp_route'] = substr($row['fp_route'] ?? '', 0, 200); // Truncate long routes
                fputcsv($output, $row);
            }
        }
        fclose($output);
    } else {
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage()
    ]);
}
