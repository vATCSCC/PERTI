<?php
/**
 * VATSIM Network Analysis API
 *
 * Provides comprehensive network statistics for the documentation dashboard.
 * Queries VATSIM_STATS database for historical trends, seasonality, and growth metrics.
 *
 * Usage:
 *   GET /api/stats/network_analysis.php
 *   GET /api/stats/network_analysis.php?section=overview
 *   GET /api/stats/network_analysis.php?section=growth
 *   GET /api/stats/network_analysis.php?section=seasonality
 *   GET /api/stats/network_analysis.php?section=regional
 *   GET /api/stats/network_analysis.php?section=events
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=300'); // Cache for 5 minutes

require_once __DIR__ . '/config_stats.php';

// Connect to VATSIM_STATS
if (!function_exists('sqlsrv_connect')) {
    http_response_code(500);
    echo json_encode(['error' => 'sqlsrv extension not loaded']);
    exit;
}

$connectionInfo = [
    "Database" => STATS_SQL_DATABASE,
    "UID"      => STATS_SQL_USERNAME,
    "PWD"      => STATS_SQL_PASSWORD,
    "ConnectionPooling" => 1
];

$conn = sqlsrv_connect(STATS_SQL_HOST, $connectionInfo);
if ($conn === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$section = isset($_GET['section']) ? $_GET['section'] : 'all';

$response = [
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'data_source' => 'VATSIM_STATS'
];

// Helper function to execute query and return results
function executeQuery($conn, $sql) {
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) {
        return [];
    }
    $results = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Convert DateTime objects to strings
        foreach ($row as $key => $value) {
            if ($value instanceof DateTime) {
                $row[$key] = $value->format('Y-m-d H:i:s');
            }
        }
        $results[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    return $results;
}

// Overview Section
if ($section === 'all' || $section === 'overview') {
    $overviewSql = "
        SELECT
            COUNT(*) as total_snapshots,
            MIN(file_time) as earliest_data,
            MAX(file_time) as latest_data,
            AVG(pilots) as avg_pilots,
            MAX(pilots) as max_pilots,
            MIN(pilots) as min_pilots,
            AVG(controllers) as avg_controllers,
            MAX(controllers) as max_controllers,
            STDEV(pilots) as std_dev_pilots
        FROM historical_network_stats
    ";
    $overview = executeQuery($conn, $overviewSql);

    // Current status
    $currentSql = "
        SELECT TOP 1
            file_time as snapshot_time,
            pilots,
            controllers
        FROM historical_network_stats
        ORDER BY file_time DESC
    ";
    $current = executeQuery($conn, $currentSql);

    $response['overview'] = [
        'summary' => $overview[0] ?? null,
        'current' => $current[0] ?? null
    ];
}

// Growth Section
if ($section === 'all' || $section === 'growth') {
    // Year over year
    $yoySql = "
        SELECT
            year_num,
            AVG(pilots) as avg_pilots,
            MAX(pilots) as peak_pilots,
            AVG(controllers) as avg_controllers,
            COUNT(*) as samples
        FROM historical_network_stats
        WHERE year_num >= 2021
        GROUP BY year_num
        ORDER BY year_num
    ";
    $yoyData = executeQuery($conn, $yoySql);

    // Monthly trend (last 24 months)
    $monthlySql = "
        SELECT TOP 24
            year_num,
            month_num,
            AVG(pilots) as avg_pilots,
            MAX(pilots) as peak_pilots
        FROM historical_network_stats
        GROUP BY year_num, month_num
        ORDER BY year_num DESC, month_num DESC
    ";
    $monthlyData = executeQuery($conn, $monthlySql);

    // Regional growth (APAC, Europe, Americas)
    $regionalGrowthSql = "
        SELECT
            year_num,
            AVG(CASE WHEN hour_of_day BETWEEN 0 AND 7 THEN pilots END) as apac_hours,
            AVG(CASE WHEN hour_of_day BETWEEN 8 AND 15 THEN pilots END) as europe_hours,
            AVG(CASE WHEN hour_of_day BETWEEN 16 AND 23 THEN pilots END) as americas_hours
        FROM historical_network_stats
        WHERE year_num >= 2022
        GROUP BY year_num
        ORDER BY year_num
    ";
    $regionalGrowth = executeQuery($conn, $regionalGrowthSql);

    $response['growth'] = [
        'year_over_year' => $yoyData,
        'monthly_trend' => array_reverse($monthlyData),
        'regional_growth' => $regionalGrowth
    ];
}

// Seasonality Section
if ($section === 'all' || $section === 'seasonality') {
    // Monthly seasonality
    $monthlySeasSql = "
        WITH monthly_avg AS (
            SELECT AVG(CAST(pilots AS FLOAT)) as overall_avg FROM historical_network_stats
        )
        SELECT
            m.month_num,
            AVG(m.pilots) as avg_pilots,
            STDEV(m.pilots) as std_dev,
            MAX(m.pilots) as peak,
            CAST(AVG(m.pilots) / a.overall_avg * 100 AS DECIMAL(5,1)) as seasonal_index
        FROM historical_network_stats m
        CROSS JOIN monthly_avg a
        GROUP BY m.month_num, a.overall_avg
        ORDER BY m.month_num
    ";
    $monthlySeas = executeQuery($conn, $monthlySeasSql);

    // Day of week pattern
    $dowSql = "
        SELECT
            day_of_week,
            AVG(pilots) as avg_pilots,
            AVG(controllers) as avg_controllers
        FROM historical_network_stats
        GROUP BY day_of_week
        ORDER BY day_of_week
    ";
    $dowData = executeQuery($conn, $dowSql);

    // Hour of day pattern
    $hourlySql = "
        SELECT
            hour_of_day,
            AVG(pilots) as avg_pilots,
            AVG(controllers) as avg_controllers,
            STDEV(pilots) as std_dev
        FROM historical_network_stats
        GROUP BY hour_of_day
        ORDER BY hour_of_day
    ";
    $hourlyData = executeQuery($conn, $hourlySql);

    // Season pattern
    $seasonSql = "
        SELECT
            season_code,
            AVG(pilots) as avg_pilots,
            STDEV(pilots) as std_dev,
            MAX(pilots) as peak
        FROM historical_network_stats
        WHERE season_code IS NOT NULL
        GROUP BY season_code
    ";
    $seasonData = executeQuery($conn, $seasonSql);

    $response['seasonality'] = [
        'monthly' => $monthlySeas,
        'day_of_week' => $dowData,
        'hourly' => $hourlyData,
        'seasonal' => $seasonData
    ];
}

// Regional Section
if ($section === 'all' || $section === 'regional') {
    // Traffic by time window
    $timeWindowSql = "
        SELECT
            CASE
                WHEN hour_of_day BETWEEN 0 AND 5 THEN '00-06 UTC (APAC Evening)'
                WHEN hour_of_day BETWEEN 6 AND 11 THEN '06-12 UTC (Europe AM)'
                WHEN hour_of_day BETWEEN 12 AND 17 THEN '12-18 UTC (EU/US Overlap)'
                WHEN hour_of_day BETWEEN 18 AND 23 THEN '18-24 UTC (Americas PM)'
            END as time_window,
            AVG(pilots) as avg_pilots,
            AVG(controllers) as avg_controllers,
            COUNT(*) as samples
        FROM historical_network_stats
        GROUP BY
            CASE
                WHEN hour_of_day BETWEEN 0 AND 5 THEN '00-06 UTC (APAC Evening)'
                WHEN hour_of_day BETWEEN 6 AND 11 THEN '06-12 UTC (Europe AM)'
                WHEN hour_of_day BETWEEN 12 AND 17 THEN '12-18 UTC (EU/US Overlap)'
                WHEN hour_of_day BETWEEN 18 AND 23 THEN '18-24 UTC (Americas PM)'
            END
        ORDER BY MIN(hour_of_day)
    ";
    $timeWindowData = executeQuery($conn, $timeWindowSql);

    // Controller coverage by region
    $ctrlCoverageSql = "
        SELECT
            CASE
                WHEN hour_of_day BETWEEN 0 AND 7 THEN 'APAC Hours'
                WHEN hour_of_day BETWEEN 8 AND 15 THEN 'Europe Hours'
                WHEN hour_of_day BETWEEN 16 AND 23 THEN 'Americas Hours'
            END as time_window,
            AVG(pilots) as avg_pilots,
            AVG(controllers) as avg_controllers,
            CAST(AVG(CAST(controllers AS FLOAT) / NULLIF(pilots, 0) * 100) AS DECIMAL(5,2)) as ctrl_ratio
        FROM historical_network_stats
        WHERE pilots > 0 AND year_num >= 2024
        GROUP BY
            CASE
                WHEN hour_of_day BETWEEN 0 AND 7 THEN 'APAC Hours'
                WHEN hour_of_day BETWEEN 8 AND 15 THEN 'Europe Hours'
                WHEN hour_of_day BETWEEN 16 AND 23 THEN 'Americas Hours'
            END
        ORDER BY MIN(hour_of_day)
    ";
    $ctrlCoverage = executeQuery($conn, $ctrlCoverageSql);

    $response['regional'] = [
        'time_windows' => $timeWindowData,
        'controller_coverage' => $ctrlCoverage
    ];
}

// Events Section
if ($section === 'all' || $section === 'events') {
    // Top traffic days
    $topDaysSql = "
        SELECT TOP 20
            CONVERT(VARCHAR, MIN(file_time), 23) as date,
            DATENAME(weekday, MIN(file_time)) as day_name,
            MAX(pilots) as peak_pilots,
            AVG(pilots) as avg_pilots,
            MAX(controllers) as peak_controllers
        FROM historical_network_stats
        GROUP BY CAST(file_time AS DATE)
        ORDER BY MAX(pilots) DESC
    ";
    $topDays = executeQuery($conn, $topDaysSql);

    // Recent records (last 30 days)
    $recentSql = "
        SELECT
            CONVERT(VARCHAR, MIN(file_time), 23) as date,
            MAX(pilots) as peak_pilots,
            AVG(pilots) as avg_pilots
        FROM historical_network_stats
        WHERE file_time >= DATEADD(day, -30, GETUTCDATE())
        GROUP BY CAST(file_time AS DATE)
        ORDER BY CAST(file_time AS DATE) DESC
    ";
    $recentData = executeQuery($conn, $recentSql);

    $response['events'] = [
        'top_traffic_days' => $topDays,
        'recent_30_days' => $recentData
    ];
}

// Distribution Section
if ($section === 'all' || $section === 'distribution') {
    $distSql = "
        SELECT
            CASE
                WHEN pilots < 300 THEN '0-299'
                WHEN pilots < 600 THEN '300-599'
                WHEN pilots < 900 THEN '600-899'
                WHEN pilots < 1200 THEN '900-1199'
                WHEN pilots < 1500 THEN '1200-1499'
                WHEN pilots < 1800 THEN '1500-1799'
                WHEN pilots < 2100 THEN '1800-2099'
                ELSE '2100+'
            END as traffic_bucket,
            COUNT(*) as occurrences,
            CAST(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM historical_network_stats) AS DECIMAL(5,2)) as pct
        FROM historical_network_stats
        GROUP BY
            CASE
                WHEN pilots < 300 THEN '0-299'
                WHEN pilots < 600 THEN '300-599'
                WHEN pilots < 900 THEN '600-899'
                WHEN pilots < 1200 THEN '900-1199'
                WHEN pilots < 1500 THEN '1200-1499'
                WHEN pilots < 1800 THEN '1500-1799'
                WHEN pilots < 2100 THEN '1800-2099'
                ELSE '2100+'
            END
        ORDER BY MIN(pilots)
    ";
    $distData = executeQuery($conn, $distSql);

    $response['distribution'] = $distData;
}

sqlsrv_close($conn);

echo json_encode($response, JSON_PRETTY_PRINT | JSON_NUMERIC_CHECK);
