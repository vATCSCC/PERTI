<?php
/**
 * Network Analysis Cache Generator
 *
 * Pre-computes expensive analytics queries and caches the results as JSON.
 * Called by load_network_stats.php after new data is loaded, or on-demand.
 *
 * Usage:
 *   php generate_cache.php                  - CLI mode
 *   curl .../generate_cache.php?internal=1  - HTTP (internal only)
 *
 * Cache files are stored in system temp directory with 5-minute TTL.
 */

// Only allow CLI or internal calls
$isInternal = isset($_GET['internal']) && $_GET['internal'] === '1';
$isCli = php_sapi_name() === 'cli';

if (!$isCli && !$isInternal) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config_stats.php';

// Cache configuration
define('CACHE_DIR', sys_get_temp_dir() . '/vatsim_stats_cache');
define('CACHE_TTL', 300); // 5 minutes

// Ensure cache directory exists
if (!is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
}

// Helper function to get cache file path
function getCacheFile($section) {
    return CACHE_DIR . '/network_analysis_' . $section . '.json';
}

// Helper function to check if cache is fresh
function isCacheFresh($section) {
    $file = getCacheFile($section);
    if (!file_exists($file)) return false;
    return (time() - filemtime($file)) < CACHE_TTL;
}

// Helper function to write cache
function writeCache($section, $data) {
    $file = getCacheFile($section);
    $tempFile = $file . '.tmp';

    // Write to temp file first, then atomic rename
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_NUMERIC_CHECK);
    if (file_put_contents($tempFile, $json, LOCK_EX) !== false) {
        rename($tempFile, $file);
        return true;
    }
    return false;
}

// Helper function to read cache
function readCache($section) {
    $file = getCacheFile($section);
    if (!file_exists($file)) return null;
    $content = file_get_contents($file);
    return $content ? json_decode($content, true) : null;
}

// Connect to database
if (!function_exists('sqlsrv_connect')) {
    echo json_encode(['error' => 'sqlsrv extension not loaded']);
    exit(1);
}

$connectionInfo = [
    "Database" => STATS_SQL_DATABASE,
    "UID"      => STATS_SQL_USERNAME,
    "PWD"      => STATS_SQL_PASSWORD,
    "ConnectionPooling" => 1
];

$conn = sqlsrv_connect(STATS_SQL_HOST, $connectionInfo);
if ($conn === false) {
    echo json_encode(['error' => 'Database connection failed']);
    exit(1);
}

// Helper function to execute query
function executeQuery($conn, $sql) {
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) {
        return [];
    }
    $results = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
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

$startTime = microtime(true);
$response = [
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'data_source' => 'VATSIM_STATS',
    'cache_mode' => 'precomputed'
];

// ============================================================================
// OVERVIEW SECTION
// ============================================================================
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
        STDEV(pilots) as std_dev_pilots,
        VAR(pilots) as variance_pilots,
        STDEV(controllers) as std_dev_controllers
    FROM historical_network_stats
";
$overview = executeQuery($conn, $overviewSql);

// Percentiles
$percentileSql = "
    SELECT
        PERCENTILE_CONT(0.10) WITHIN GROUP (ORDER BY pilots) OVER () as p10,
        PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY pilots) OVER () as p25,
        PERCENTILE_CONT(0.50) WITHIN GROUP (ORDER BY pilots) OVER () as median,
        PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY pilots) OVER () as p75,
        PERCENTILE_CONT(0.90) WITHIN GROUP (ORDER BY pilots) OVER () as p90,
        PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY pilots) OVER () as p95,
        PERCENTILE_CONT(0.99) WITHIN GROUP (ORDER BY pilots) OVER () as p99
    FROM historical_network_stats
";
$stmt = sqlsrv_query($conn, $percentileSql);
$percentiles = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
if ($stmt) sqlsrv_free_stmt($stmt);

// Controller ratio
$ratioSql = "
    SELECT
        AVG(CAST(controllers AS FLOAT) / NULLIF(pilots, 0) * 100) as avg_ctrl_ratio,
        STDEV(CAST(controllers AS FLOAT) / NULLIF(pilots, 0) * 100) as std_ctrl_ratio
    FROM historical_network_stats
    WHERE pilots > 100
";
$ratioData = executeQuery($conn, $ratioSql);

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

// 24-hour change
$changeSql = "
    WITH current_avg AS (
        SELECT AVG(pilots) as avg_now
        FROM historical_network_stats
        WHERE file_time >= DATEADD(hour, -1, GETUTCDATE())
    ),
    yesterday_avg AS (
        SELECT AVG(pilots) as avg_yesterday
        FROM historical_network_stats
        WHERE file_time >= DATEADD(hour, -25, GETUTCDATE())
          AND file_time < DATEADD(hour, -24, GETUTCDATE())
    )
    SELECT
        c.avg_now,
        y.avg_yesterday,
        CASE WHEN y.avg_yesterday > 0
            THEN (c.avg_now - y.avg_yesterday) / y.avg_yesterday * 100
            ELSE 0
        END as pct_change_24h
    FROM current_avg c, yesterday_avg y
";
$changeData = executeQuery($conn, $changeSql);

// Data quality
$qualitySql = "
    SELECT
        COUNT(DISTINCT CAST(file_time AS DATE)) as days_with_data,
        DATEDIFF(day, MIN(file_time), MAX(file_time)) + 1 as total_days,
        COUNT(*) * 1.0 / (DATEDIFF(day, MIN(file_time), MAX(file_time)) + 1) as avg_samples_per_day
    FROM historical_network_stats
";
$qualityData = executeQuery($conn, $qualitySql);

$response['overview'] = [
    'summary' => $overview[0] ?? null,
    'percentiles' => $percentiles,
    'controller_ratio' => $ratioData[0] ?? null,
    'current' => $current[0] ?? null,
    'change_24h' => $changeData[0] ?? null,
    'data_quality' => $qualityData[0] ?? null
];

// ============================================================================
// GROWTH SECTION
// ============================================================================
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

// ============================================================================
// SEASONALITY SECTION
// ============================================================================
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

// ============================================================================
// REGIONAL SECTION
// ============================================================================
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

// ============================================================================
// EVENTS SECTION
// ============================================================================
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

// ============================================================================
// DISTRIBUTION SECTION
// ============================================================================
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

// ============================================================================
// HEATMAP SECTION
// ============================================================================
$heatmapSql = "
    SELECT
        day_of_week,
        hour_of_day,
        AVG(pilots) as avg_pilots,
        COUNT(*) as samples
    FROM historical_network_stats
    WHERE year_num >= 2023
    GROUP BY day_of_week, hour_of_day
    ORDER BY day_of_week, hour_of_day
";
$heatmapData = executeQuery($conn, $heatmapSql);

$matrix = [];
foreach ($heatmapData as $row) {
    $matrix[] = [
        (int)$row['hour_of_day'],
        (int)$row['day_of_week'] - 1,
        round($row['avg_pilots'])
    ];
}
$response['heatmap'] = [
    'matrix' => $matrix,
    'raw' => $heatmapData
];

// Close connection
sqlsrv_close($conn);

// Add timing info
$response['cache_generated_in_ms'] = round((microtime(true) - $startTime) * 1000, 2);

// Write to cache file
$cacheFile = getCacheFile('all');
if (writeCache('all', $response)) {
    $response['cache_file'] = basename($cacheFile);
    $response['cache_success'] = true;
} else {
    $response['cache_success'] = false;
    $response['cache_error'] = 'Failed to write cache file';
}

echo json_encode([
    'success' => true,
    'cache_file' => basename($cacheFile),
    'generated_in_ms' => $response['cache_generated_in_ms'],
    'sections' => ['overview', 'growth', 'seasonality', 'regional', 'events', 'distribution', 'heatmap']
], JSON_PRETTY_PRINT);
