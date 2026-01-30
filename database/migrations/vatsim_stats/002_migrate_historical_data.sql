-- ============================================================================
-- VATSIM_STATS: Historical Data Migration
-- Migrates data from VATSIM_Data.Running_VATSIM_Data_2 to VATSIM_STATS
--
-- Source: VATSIM_Data.dbo.Running_VATSIM_Data_2 (444K rows, 4.4 years)
-- Target: VATSIM_STATS.dbo.historical_network_stats
--
-- Source Columns (verified 2026-01-30):
--   ID              bigint
--   File_Time       datetime
--   #_of_Pilots     int
--   #_of_Controllers int
--   #_of_Prefiles   int
--   Hour_UTC        time
--   DateHour_UTC    datetime2
--   day_of_week     int
--   week_number     int
--   quarter_number  int
--
-- Run this AFTER creating VATSIM_STATS database and running 001_complete_schema.sql
-- ============================================================================

-- ============================================================================
-- STEP 1: Verify source data
-- Run on VATSIM_Data database
-- ============================================================================

/*
USE VATSIM_Data;
GO

SELECT
    COUNT(*) AS total_rows,
    MIN(File_Time) AS earliest,
    MAX(File_Time) AS latest,
    DATEDIFF(DAY, MIN(File_Time), MAX(File_Time)) AS days_span
FROM dbo.Running_VATSIM_Data_2;

-- Expected: ~444K rows, Sep 2021 to Jan 2026
*/

-- ============================================================================
-- STEP 2: Migration (run on VATSIM_STATS database)
-- Cross-database query to same server (vatsim.database.windows.net)
-- ============================================================================

USE VATSIM_STATS;
GO

-- Insert historical data with time tags
INSERT INTO dbo.historical_network_stats (
    file_time,
    pilots,
    controllers,
    hour_of_day,
    day_of_week,
    month_num,
    year_num,
    season_code,
    source_table
)
SELECT
    src.File_Time,
    src.[#_of_Pilots],
    src.[#_of_Controllers],
    DATEPART(HOUR, src.File_Time),
    COALESCE(src.day_of_week, DATEPART(WEEKDAY, src.File_Time)),  -- Use existing or compute
    MONTH(src.File_Time),
    YEAR(src.File_Time),
    CASE
        WHEN MONTH(src.File_Time) IN (12, 1, 2) THEN 'DJF'
        WHEN MONTH(src.File_Time) IN (3, 4, 5) THEN 'MAM'
        WHEN MONTH(src.File_Time) IN (6, 7, 8) THEN 'JJA'
        ELSE 'SON'
    END,
    'Running_VATSIM_Data_2'
FROM VATSIM_Data.dbo.Running_VATSIM_Data_2 src
WHERE src.File_Time IS NOT NULL
  AND src.[#_of_Pilots] IS NOT NULL
ORDER BY src.File_Time;

PRINT 'Migrated ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows to historical_network_stats';
GO

-- ============================================================================
-- STEP 3: Verify migration
-- ============================================================================

SELECT
    COUNT(*) AS total_migrated,
    MIN(file_time) AS earliest,
    MAX(file_time) AS latest,
    COUNT(DISTINCT CAST(file_time AS DATE)) AS days_with_data,
    MIN(pilots) AS min_pilots,
    MAX(pilots) AS max_pilots,
    AVG(pilots) AS avg_pilots
FROM dbo.historical_network_stats;
GO

-- ============================================================================
-- STEP 4: Build traffic baselines from historical data
-- These baselines enable real-time traffic level tagging
-- ============================================================================

-- Clear any existing baselines
DELETE FROM dbo.traffic_baselines WHERE baseline_type = 'hourly_dow';
GO

-- Insert baselines by hour and day-of-week
-- Using a CTE to compute percentiles properly
WITH hourly_stats AS (
    SELECT
        day_of_week,
        hour_of_day,
        pilots,
        COUNT(*) OVER (PARTITION BY day_of_week, hour_of_day) AS sample_count,
        AVG(CAST(pilots AS FLOAT)) OVER (PARTITION BY day_of_week, hour_of_day) AS avg_pilots,
        STDEV(pilots) OVER (PARTITION BY day_of_week, hour_of_day) AS std_pilots,
        MIN(pilots) OVER (PARTITION BY day_of_week, hour_of_day) AS min_pilots,
        MAX(pilots) OVER (PARTITION BY day_of_week, hour_of_day) AS max_pilots,
        PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY pilots) OVER (PARTITION BY day_of_week, hour_of_day) AS p25,
        PERCENTILE_CONT(0.50) WITHIN GROUP (ORDER BY pilots) OVER (PARTITION BY day_of_week, hour_of_day) AS p50,
        PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY pilots) OVER (PARTITION BY day_of_week, hour_of_day) AS p75,
        PERCENTILE_CONT(0.90) WITHIN GROUP (ORDER BY pilots) OVER (PARTITION BY day_of_week, hour_of_day) AS p90,
        PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY pilots) OVER (PARTITION BY day_of_week, hour_of_day) AS p95,
        PERCENTILE_CONT(0.99) WITHIN GROUP (ORDER BY pilots) OVER (PARTITION BY day_of_week, hour_of_day) AS p99,
        MIN(CAST(file_time AS DATE)) OVER (PARTITION BY day_of_week, hour_of_day) AS from_date,
        MAX(CAST(file_time AS DATE)) OVER (PARTITION BY day_of_week, hour_of_day) AS to_date
    FROM dbo.historical_network_stats
),
distinct_stats AS (
    SELECT DISTINCT
        day_of_week,
        hour_of_day,
        sample_count,
        avg_pilots,
        std_pilots,
        min_pilots,
        max_pilots,
        p25, p50, p75, p90, p95, p99,
        from_date,
        to_date
    FROM hourly_stats
)
INSERT INTO dbo.traffic_baselines (
    baseline_type,
    group_key,
    sample_count,
    avg_value,
    std_dev,
    min_value,
    max_value,
    p25, p50, p75, p90, p95, p99,
    computed_from_date,
    computed_to_date
)
SELECT
    'hourly_dow',
    CASE day_of_week
        WHEN 1 THEN 'Sun'
        WHEN 2 THEN 'Mon'
        WHEN 3 THEN 'Tue'
        WHEN 4 THEN 'Wed'
        WHEN 5 THEN 'Thu'
        WHEN 6 THEN 'Fri'
        WHEN 7 THEN 'Sat'
    END + '_' + CAST(hour_of_day AS VARCHAR),
    sample_count,
    avg_pilots,
    std_pilots,
    min_pilots,
    max_pilots,
    CAST(p25 AS INT),
    CAST(p50 AS INT),
    CAST(p75 AS INT),
    CAST(p90 AS INT),
    CAST(p95 AS INT),
    CAST(p99 AS INT),
    from_date,
    to_date
FROM distinct_stats;

PRINT 'Created ' + CAST(@@ROWCOUNT AS VARCHAR) + ' baseline records';
GO

-- ============================================================================
-- STEP 5: Generate daily stats from historical data
-- ============================================================================

INSERT INTO dbo.stats_network_daily (
    stat_date,
    total_snapshots,
    pilots_min,
    pilots_max,
    pilots_avg,
    pilots_std_dev,
    controllers_min,
    controllers_max,
    controllers_avg,
    day_of_week,
    day_of_week_name,
    is_weekend,
    week_of_year,
    month_num,
    season_code,
    year_num,
    retention_tier
)
SELECT
    CAST(file_time AS DATE) AS stat_date,
    COUNT(*) AS total_snapshots,
    MIN(pilots) AS pilots_min,
    MAX(pilots) AS pilots_max,
    AVG(CAST(pilots AS DECIMAL(10,2))) AS pilots_avg,
    STDEV(pilots) AS pilots_std_dev,
    MIN(controllers) AS controllers_min,
    MAX(controllers) AS controllers_max,
    AVG(CAST(controllers AS DECIMAL(10,2))) AS controllers_avg,
    MIN(day_of_week) AS day_of_week,
    CASE MIN(day_of_week)
        WHEN 1 THEN 'Sun'
        WHEN 2 THEN 'Mon'
        WHEN 3 THEN 'Tue'
        WHEN 4 THEN 'Wed'
        WHEN 5 THEN 'Thu'
        WHEN 6 THEN 'Fri'
        WHEN 7 THEN 'Sat'
    END AS day_of_week_name,
    CASE WHEN MIN(day_of_week) IN (1, 7) THEN 1 ELSE 0 END AS is_weekend,
    DATEPART(WEEK, CAST(file_time AS DATE)) AS week_of_year,
    MIN(month_num) AS month_num,
    MIN(season_code) AS season_code,
    MIN(year_num) AS year_num,
    2 AS retention_tier  -- COOL tier for historical
FROM dbo.historical_network_stats
GROUP BY CAST(file_time AS DATE);

PRINT 'Created ' + CAST(@@ROWCOUNT AS VARCHAR) + ' daily stats records';
GO

-- ============================================================================
-- STEP 6: Generate monthly summaries
-- ============================================================================

INSERT INTO dbo.stats_network_monthly (
    year_month,
    month_start_date,
    total_days,
    pilots_monthly_avg,
    pilots_monthly_max,
    month_num,
    year_num,
    quarter_num,
    season_code,
    retention_tier
)
SELECT
    CAST(year_num AS VARCHAR) + '-' + RIGHT('0' + CAST(month_num AS VARCHAR), 2) AS year_month,
    DATEFROMPARTS(year_num, month_num, 1) AS month_start_date,
    COUNT(DISTINCT CAST(file_time AS DATE)) AS total_days,
    AVG(CAST(pilots AS DECIMAL(10,2))) AS pilots_monthly_avg,
    MAX(pilots) AS pilots_monthly_max,
    month_num,
    year_num,
    CASE
        WHEN month_num <= 3 THEN 1
        WHEN month_num <= 6 THEN 2
        WHEN month_num <= 9 THEN 3
        ELSE 4
    END AS quarter_num,
    MIN(season_code) AS season_code,
    2 AS retention_tier
FROM dbo.historical_network_stats
GROUP BY year_num, month_num;

PRINT 'Created ' + CAST(@@ROWCOUNT AS VARCHAR) + ' monthly stats records';
GO

-- ============================================================================
-- STEP 7: Generate yearly summaries
-- ============================================================================

INSERT INTO dbo.stats_network_yearly (
    year_num,
    total_days,
    pilots_yearly_avg,
    pilots_yearly_max,
    retention_tier
)
SELECT
    year_num,
    COUNT(DISTINCT CAST(file_time AS DATE)) AS total_days,
    AVG(CAST(pilots AS DECIMAL(10,2))) AS pilots_yearly_avg,
    MAX(pilots) AS pilots_yearly_max,
    3 AS retention_tier
FROM dbo.historical_network_stats
GROUP BY year_num;

PRINT 'Created ' + CAST(@@ROWCOUNT AS VARCHAR) + ' yearly stats records';
GO

-- ============================================================================
-- STEP 8: Final verification
-- ============================================================================

SELECT 'historical_network_stats' AS table_name, COUNT(*) AS row_count FROM dbo.historical_network_stats
UNION ALL SELECT 'traffic_baselines', COUNT(*) FROM dbo.traffic_baselines
UNION ALL SELECT 'stats_network_daily', COUNT(*) FROM dbo.stats_network_daily
UNION ALL SELECT 'stats_network_monthly', COUNT(*) FROM dbo.stats_network_monthly
UNION ALL SELECT 'stats_network_yearly', COUNT(*) FROM dbo.stats_network_yearly;
GO

-- Sample baseline verification
SELECT TOP 5 * FROM dbo.traffic_baselines WHERE baseline_type = 'hourly_dow' ORDER BY group_key;
GO

-- ============================================================================
-- MIGRATION COMPLETE
-- ============================================================================

PRINT '';
PRINT '=================================================================';
PRINT 'MIGRATION COMPLETE';
PRINT '=================================================================';
PRINT 'Next steps:';
PRINT '1. Verify row counts above match expectations';
PRINT '2. Update Power BI dataflow to write to VATSIM_STATS';
PRINT '3. Test sample queries on new schema';
PRINT '4. After 7-day verification period, delete VATSIM_Data database';
PRINT '5. Expected savings: $559/month';
PRINT '=================================================================';
