<?php
/**
 * Trajectory Data Gaps API
 *
 * Checks for trajectory data gaps in ADL database for a given time range.
 * Used by TMI compliance analysis to warn about missing data.
 *
 * Endpoints:
 *   GET ?start={iso_datetime}&end={iso_datetime} - Check for gaps in range
 *   GET ?recent=true - Get gaps from last 30 days
 *   GET ?start=...&end=...&include_counts=true - Also return hourly trajectory counts
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

include("../../load/config.php");

// ADL Database connection
$adl_server = ADL_SQL_HOST;
$adl_db = ADL_SQL_DATABASE;
$adl_user = ADL_SQL_USERNAME;
$adl_pass = ADL_SQL_PASSWORD;

$connectionInfo = [
    "Database" => $adl_db,
    "UID" => $adl_user,
    "PWD" => $adl_pass,
    "TrustServerCertificate" => true,
    "LoginTimeout" => 30
];

try {
    $conn = sqlsrv_connect($adl_server, $connectionInfo);
    if ($conn === false) {
        throw new Exception("ADL connection failed: " . print_r(sqlsrv_errors(), true));
    }

    $response = [
        'success' => true,
        'gaps' => [],
        'has_gaps' => false,
        'total_missing_hours' => 0,
        'hourly_counts' => []
    ];

    // Parse date range
    $start_date = isset($_GET['start']) ? $_GET['start'] : null;
    $end_date = isset($_GET['end']) ? $_GET['end'] : null;
    $recent = isset($_GET['recent']) && $_GET['recent'] === 'true';
    $include_counts = isset($_GET['include_counts']) && $_GET['include_counts'] === 'true';

    if ($recent) {
        // Last 30 days
        $start_date = date('Y-m-d', strtotime('-30 days'));
        $end_date = date('Y-m-d');
    }

    if (!$start_date || !$end_date) {
        throw new Exception("Missing start or end date parameters");
    }

    // Validate date format
    $start_dt = new DateTime($start_date);
    $end_dt = new DateTime($end_date);

    // Query hourly trajectory data coverage
    // We generate all expected hours and left join with actual data to find gaps
    $sql = "
        WITH DateRange AS (
            SELECT CAST(? AS DATE) as dt
            UNION ALL
            SELECT DATEADD(day, 1, dt) FROM DateRange WHERE dt < CAST(? AS DATE)
        ),
        Hours AS (
            SELECT 0 as hr UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
            UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7
            UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11
            UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15
            UNION ALL SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19
            UNION ALL SELECT 20 UNION ALL SELECT 21 UNION ALL SELECT 22 UNION ALL SELECT 23
        ),
        ExpectedSlots AS (
            SELECT d.dt, h.hr FROM DateRange d CROSS JOIN Hours h
        ),
        ActualData AS (
            SELECT
                CAST(timestamp_utc AS DATE) as dt,
                DATEPART(hour, timestamp_utc) as hr,
                COUNT(*) as points
            FROM adl_trajectory_archive
            WHERE timestamp_utc >= ? AND timestamp_utc < DATEADD(day, 1, CAST(? AS DATE))
            GROUP BY CAST(timestamp_utc AS DATE), DATEPART(hour, timestamp_utc)
        )
        SELECT
            e.dt as date,
            e.hr as hour_z,
            ISNULL(a.points, 0) as points
        FROM ExpectedSlots e
        LEFT JOIN ActualData a ON e.dt = a.dt AND e.hr = a.hr
        WHERE ISNULL(a.points, 0) = 0
        ORDER BY e.dt, e.hr
        OPTION (MAXRECURSION 365)
    ";

    $params = [
        $start_dt->format('Y-m-d'),
        $end_dt->format('Y-m-d'),
        $start_dt->format('Y-m-d 00:00:00'),
        $end_dt->format('Y-m-d')
    ];

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        throw new Exception("Query failed: " . print_r(sqlsrv_errors(), true));
    }

    $gaps = [];
    $current_group = null;

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $date = $row['date']->format('Y-m-d');
        $hour = intval($row['hour_z']);

        // Group consecutive hours
        if ($current_group && $current_group['date'] === $date && $hour === $current_group['end_hour'] + 1) {
            // Extend current group
            $current_group['end_hour'] = $hour;
            $current_group['hours'][] = $hour;
        } else {
            // Save previous group if exists
            if ($current_group) {
                $gaps[] = format_gap($current_group);
            }
            // Start new group
            $current_group = [
                'date' => $date,
                'start_hour' => $hour,
                'end_hour' => $hour,
                'hours' => [$hour]
            ];
        }
    }

    // Don't forget the last group
    if ($current_group) {
        $gaps[] = format_gap($current_group);
    }

    sqlsrv_free_stmt($stmt);

    // If include_counts is requested, fetch all hourly trajectory counts
    $hourly_counts = [];
    if ($include_counts) {
        $counts_sql = "
            WITH DateRange AS (
                SELECT CAST(? AS DATE) as dt
                UNION ALL
                SELECT DATEADD(day, 1, dt) FROM DateRange WHERE dt < CAST(? AS DATE)
            ),
            Hours AS (
                SELECT 0 as hr UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
                UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7
                UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11
                UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15
                UNION ALL SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19
                UNION ALL SELECT 20 UNION ALL SELECT 21 UNION ALL SELECT 22 UNION ALL SELECT 23
            ),
            ExpectedSlots AS (
                SELECT d.dt, h.hr FROM DateRange d CROSS JOIN Hours h
            ),
            ActualData AS (
                SELECT
                    CAST(timestamp_utc AS DATE) as dt,
                    DATEPART(hour, timestamp_utc) as hr,
                    COUNT(*) as points,
                    COUNT(DISTINCT callsign) as flights
                FROM adl_trajectory_archive
                WHERE timestamp_utc >= ? AND timestamp_utc < DATEADD(day, 1, CAST(? AS DATE))
                GROUP BY CAST(timestamp_utc AS DATE), DATEPART(hour, timestamp_utc)
            )
            SELECT
                e.dt as date,
                e.hr as hour_z,
                ISNULL(a.points, 0) as traj_points,
                ISNULL(a.flights, 0) as unique_flights
            FROM ExpectedSlots e
            LEFT JOIN ActualData a ON e.dt = a.dt AND e.hr = a.hr
            ORDER BY e.dt, e.hr
            OPTION (MAXRECURSION 365)
        ";

        $counts_stmt = sqlsrv_query($conn, $counts_sql, $params);
        if ($counts_stmt !== false) {
            while ($row = sqlsrv_fetch_array($counts_stmt, SQLSRV_FETCH_ASSOC)) {
                $date = $row['date']->format('Y-m-d');
                $hour = intval($row['hour_z']);
                $key = $date . 'T' . sprintf('%02d', $hour);
                $hourly_counts[$key] = [
                    'date' => $date,
                    'hour' => $hour,
                    'traj_points' => intval($row['traj_points']),
                    'unique_flights' => intval($row['unique_flights'])
                ];
            }
            sqlsrv_free_stmt($counts_stmt);
        }
        $response['hourly_counts'] = $hourly_counts;
    }

    sqlsrv_close($conn);

    $response['gaps'] = $gaps;
    $response['has_gaps'] = count($gaps) > 0;
    $response['total_missing_hours'] = array_sum(array_column($gaps, 'duration_hours'));
    $response['range'] = [
        'start' => $start_dt->format('Y-m-d'),
        'end' => $end_dt->format('Y-m-d')
    ];

    echo json_encode($response, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Format a gap group for API response
 */
function format_gap($group) {
    $hours = $group['hours'];
    $duration = count($hours);

    // Format time range string
    $start_time = sprintf('%02d:00Z', $group['start_hour']);
    $end_time = sprintf('%02d:59Z', $group['end_hour']);

    return [
        'date' => $group['date'],
        'start_hour' => $group['start_hour'],
        'end_hour' => $group['end_hour'],
        'start_time' => $start_time,
        'end_time' => $end_time,
        'duration_hours' => $duration,
        'hours' => $hours,
        'display' => $group['date'] . ' ' . $start_time . ' - ' . $end_time . ' (' . $duration . 'h)'
    ];
}
