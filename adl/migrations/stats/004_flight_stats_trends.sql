-- =====================================================
-- Flight Statistics Trends
-- Migration: 073_flight_stats_trends.sql
-- Database: VATSIM_ADL (Azure SQL)
-- Purpose: Add long-term trend tracking tables and procedures
--          for seasonality, day-of-week, and hourly patterns
-- =====================================================

SET NOCOUNT ON;
GO

-- =====================================================
-- 1. UPDATE RETENTION TIER FOR DAILY TABLES
-- Extend from 180 days to 730 days (2 years)
-- =====================================================

UPDATE dbo.flight_stats_retention_tiers
SET retention_days = 730,
    description = 'Daily statistics - trend analysis (2 years)'
WHERE tier_id = 1;
GO

PRINT 'Updated DAILY retention tier to 730 days';
GO

-- =====================================================
-- 2. WEEKLY STATISTICS TABLE
-- Aggregates by ISO week with day-of-week breakdown
-- =====================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.flight_stats_weekly') AND type = 'U')
CREATE TABLE dbo.flight_stats_weekly (
    week_start          DATE NOT NULL PRIMARY KEY,  -- Monday of ISO week
    iso_year            INT NOT NULL,
    iso_week            INT NOT NULL,

    -- Total for week
    total_flights       INT,
    completed_flights   INT,

    -- Day-of-week breakdown (flight counts)
    flights_mon         INT,
    flights_tue         INT,
    flights_wed         INT,
    flights_thu         INT,
    flights_fri         INT,
    flights_sat         INT,
    flights_sun         INT,

    -- Peak day analysis
    busiest_day         TINYINT,                    -- 1=Mon, 7=Sun (ISO weekday)
    busiest_day_flights INT,
    slowest_day         TINYINT,
    slowest_day_flights INT,

    -- Averages
    avg_daily_flights   DECIMAL(10,2),

    -- Time metrics (weekly averages)
    avg_block_time_min  DECIMAL(8,2),
    avg_flight_time_min DECIMAL(8,2),
    avg_taxi_out_min    DECIMAL(6,2),
    avg_taxi_in_min     DECIMAL(6,2),

    -- TMI impact
    total_tmi_affected  INT,
    avg_tmi_delay_min   DECIMAL(8,2),

    -- Metadata
    days_with_data      TINYINT,                    -- How many days contributed (1-7)
    created_utc         DATETIME2 DEFAULT GETUTCDATE(),
    retention_tier      TINYINT DEFAULT 2,

    INDEX IX_weekly_iso (iso_year, iso_week)
);
GO

PRINT 'Created flight_stats_weekly table';
GO

-- =====================================================
-- 3. HOURLY PATTERNS TABLE
-- Monthly hour-of-day aggregates for long-term peak analysis
-- =====================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.flight_stats_hourly_patterns') AND type = 'U')
CREATE TABLE dbo.flight_stats_hourly_patterns (
    id                  BIGINT IDENTITY(1,1) PRIMARY KEY,
    stats_month         DATE NOT NULL,              -- First of month
    hour_of_day         TINYINT NOT NULL,           -- 0-23

    -- Aggregates for this hour across the month
    total_departures    INT,
    total_arrivals      INT,
    total_enroute       INT,

    -- Daily averages for this hour
    avg_daily_departures DECIMAL(8,2),
    avg_daily_arrivals  DECIMAL(8,2),
    avg_daily_enroute   DECIMAL(8,2),

    -- Peak single-day values
    peak_day_departures INT,
    peak_day_arrivals   INT,

    -- TMI during this hour
    tmi_affected        INT,
    avg_tmi_delay_min   DECIMAL(6,2),

    -- Metadata
    days_with_data      INT,                        -- How many days contributed
    created_utc         DATETIME2 DEFAULT GETUTCDATE(),
    retention_tier      TINYINT DEFAULT 2,

    CONSTRAINT UQ_hourly_patterns UNIQUE (stats_month, hour_of_day),
    INDEX IX_hourly_patterns_month (stats_month),
    INDEX IX_hourly_patterns_hour (hour_of_day)
);
GO

PRINT 'Created flight_stats_hourly_patterns table';
GO

-- =====================================================
-- 4. sp_GenerateFlightStats_Weekly
-- Runs every Monday to aggregate previous week
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_GenerateFlightStats_Weekly')
    DROP PROCEDURE dbo.sp_GenerateFlightStats_Weekly;
GO

CREATE PROCEDURE dbo.sp_GenerateFlightStats_Weekly
    @target_week_start DATE = NULL   -- Monday of target week, NULL = previous week
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @start_time DATETIME2 = GETUTCDATE();
    DECLARE @run_id BIGINT;
    DECLARE @records_inserted INT = 0;

    -- Default to previous week's Monday
    -- DATEPART(WEEKDAY) is 1=Sunday in default config, so we adjust
    IF @target_week_start IS NULL
    BEGIN
        -- Get last Monday: subtract days to get to Monday, then subtract 7 for previous week
        DECLARE @today DATE = CAST(GETUTCDATE() AS DATE);
        DECLARE @dow INT = (DATEPART(WEEKDAY, @today) + 5) % 7;  -- 0=Mon, 6=Sun
        SET @target_week_start = DATEADD(DAY, -@dow - 7, @today);
    END

    DECLARE @week_end DATE = DATEADD(DAY, 7, @target_week_start);
    DECLARE @iso_year INT = DATEPART(ISO_WEEK, @target_week_start);
    DECLARE @iso_week INT = DATEPART(ISO_WEEK, @target_week_start);

    -- Recalculate ISO year properly (ISO week 1 can span Dec/Jan)
    SET @iso_year = YEAR(DATEADD(DAY, 3, @target_week_start));

    -- Log run start
    INSERT INTO dbo.flight_stats_run_log (run_type, started_utc, status)
    VALUES ('WEEKLY', @start_time, 'RUNNING');
    SET @run_id = SCOPE_IDENTITY();

    BEGIN TRY
        -- Delete existing record for this week
        DELETE FROM dbo.flight_stats_weekly WHERE week_start = @target_week_start;

        -- Get day-of-week breakdown from daily stats
        ;WITH DailyData AS (
            SELECT
                stats_date,
                total_flights,
                completed_flights,
                avg_block_time_min,
                avg_flight_time_min,
                avg_taxi_out_min,
                avg_taxi_in_min,
                flights_with_tmi,
                total_tmi_delay_min,
                (DATEPART(WEEKDAY, stats_date) + 5) % 7 + 1 AS iso_dow  -- 1=Mon, 7=Sun
            FROM dbo.flight_stats_daily
            WHERE stats_date >= @target_week_start AND stats_date < @week_end
        ),
        BusiestDay AS (
            SELECT TOP 1 iso_dow, total_flights
            FROM DailyData
            ORDER BY total_flights DESC
        ),
        SlowestDay AS (
            SELECT TOP 1 iso_dow, total_flights
            FROM DailyData
            ORDER BY total_flights ASC
        )
        INSERT INTO dbo.flight_stats_weekly (
            week_start, iso_year, iso_week,
            total_flights, completed_flights,
            flights_mon, flights_tue, flights_wed, flights_thu, flights_fri, flights_sat, flights_sun,
            busiest_day, busiest_day_flights, slowest_day, slowest_day_flights,
            avg_daily_flights,
            avg_block_time_min, avg_flight_time_min, avg_taxi_out_min, avg_taxi_in_min,
            total_tmi_affected, avg_tmi_delay_min,
            days_with_data
        )
        SELECT
            @target_week_start,
            @iso_year,
            @iso_week,

            SUM(d.total_flights),
            SUM(d.completed_flights),

            -- Day-of-week breakdown
            SUM(CASE WHEN d.iso_dow = 1 THEN d.total_flights ELSE 0 END),  -- Mon
            SUM(CASE WHEN d.iso_dow = 2 THEN d.total_flights ELSE 0 END),  -- Tue
            SUM(CASE WHEN d.iso_dow = 3 THEN d.total_flights ELSE 0 END),  -- Wed
            SUM(CASE WHEN d.iso_dow = 4 THEN d.total_flights ELSE 0 END),  -- Thu
            SUM(CASE WHEN d.iso_dow = 5 THEN d.total_flights ELSE 0 END),  -- Fri
            SUM(CASE WHEN d.iso_dow = 6 THEN d.total_flights ELSE 0 END),  -- Sat
            SUM(CASE WHEN d.iso_dow = 7 THEN d.total_flights ELSE 0 END),  -- Sun

            -- Peak analysis
            (SELECT iso_dow FROM BusiestDay),
            (SELECT total_flights FROM BusiestDay),
            (SELECT iso_dow FROM SlowestDay),
            (SELECT total_flights FROM SlowestDay),

            AVG(CAST(d.total_flights AS DECIMAL(10,2))),

            -- Time averages
            AVG(d.avg_block_time_min),
            AVG(d.avg_flight_time_min),
            AVG(d.avg_taxi_out_min),
            AVG(d.avg_taxi_in_min),

            -- TMI
            SUM(d.flights_with_tmi),
            AVG(CAST(d.total_tmi_delay_min AS DECIMAL) / NULLIF(d.flights_with_tmi, 0)),

            COUNT(*)
        FROM DailyData d;

        SET @records_inserted = @@ROWCOUNT;

        -- Update run log
        UPDATE dbo.flight_stats_run_log
        SET completed_utc = GETUTCDATE(),
            status = 'SUCCESS',
            records_processed = @records_inserted,
            records_inserted = @records_inserted,
            execution_ms = DATEDIFF(MILLISECOND, @start_time, GETUTCDATE())
        WHERE id = @run_id;

    END TRY
    BEGIN CATCH
        UPDATE dbo.flight_stats_run_log
        SET completed_utc = GETUTCDATE(),
            status = 'FAILED',
            error_message = ERROR_MESSAGE(),
            execution_ms = DATEDIFF(MILLISECOND, @start_time, GETUTCDATE())
        WHERE id = @run_id;

        THROW;
    END CATCH
END;
GO

PRINT 'Created sp_GenerateFlightStats_Weekly procedure';
GO

-- =====================================================
-- 5. sp_GenerateFlightStats_HourlyPatterns
-- Generates monthly hour-of-day patterns
-- Called as part of monthly rollup
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_GenerateFlightStats_HourlyPatterns')
    DROP PROCEDURE dbo.sp_GenerateFlightStats_HourlyPatterns;
GO

CREATE PROCEDURE dbo.sp_GenerateFlightStats_HourlyPatterns
    @target_month DATE = NULL   -- First day of month, NULL = previous month
AS
BEGIN
    SET NOCOUNT ON;

    IF @target_month IS NULL
        SET @target_month = DATEADD(MONTH, DATEDIFF(MONTH, 0, GETUTCDATE()) - 1, 0);

    DECLARE @month_end DATE = DATEADD(MONTH, 1, @target_month);

    -- Delete existing patterns for this month
    DELETE FROM dbo.flight_stats_hourly_patterns WHERE stats_month = @target_month;

    -- Aggregate hourly data by hour-of-day
    INSERT INTO dbo.flight_stats_hourly_patterns (
        stats_month, hour_of_day,
        total_departures, total_arrivals, total_enroute,
        avg_daily_departures, avg_daily_arrivals, avg_daily_enroute,
        peak_day_departures, peak_day_arrivals,
        tmi_affected, avg_tmi_delay_min,
        days_with_data
    )
    SELECT
        @target_month,
        DATEPART(HOUR, bucket_utc) AS hour_of_day,

        SUM(departures),
        SUM(arrivals),
        SUM(enroute),

        AVG(CAST(departures AS DECIMAL(8,2))),
        AVG(CAST(arrivals AS DECIMAL(8,2))),
        AVG(CAST(enroute AS DECIMAL(8,2))),

        MAX(departures),
        MAX(arrivals),

        SUM(tmi_affected),
        AVG(avg_tmi_delay_min),

        COUNT(*)
    FROM dbo.flight_stats_hourly
    WHERE bucket_utc >= CAST(@target_month AS DATETIME2)
      AND bucket_utc < CAST(@month_end AS DATETIME2)
    GROUP BY DATEPART(HOUR, bucket_utc);
END;
GO

PRINT 'Created sp_GenerateFlightStats_HourlyPatterns procedure';
GO

-- =====================================================
-- 6. UPDATE sp_RollupFlightStats_Monthly
-- Add call to hourly patterns procedure
-- =====================================================

-- We'll modify by recreating (safe upsert pattern)
IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_RollupFlightStats_Monthly')
    DROP PROCEDURE dbo.sp_RollupFlightStats_Monthly;
GO

CREATE PROCEDURE dbo.sp_RollupFlightStats_Monthly
    @target_month DATE = NULL   -- First day of month, NULL = previous month
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @start_time DATETIME2 = GETUTCDATE();
    DECLARE @run_id BIGINT;

    IF @target_month IS NULL
        SET @target_month = DATEADD(MONTH, DATEDIFF(MONTH, 0, GETUTCDATE()) - 1, 0);

    DECLARE @month_end DATE = DATEADD(MONTH, 1, @target_month);

    -- Log run start
    INSERT INTO dbo.flight_stats_run_log (run_type, started_utc, status)
    VALUES ('MONTHLY', @start_time, 'RUNNING');
    SET @run_id = SCOPE_IDENTITY();

    BEGIN TRY
        -- Delete existing
        DELETE FROM dbo.flight_stats_monthly_summary WHERE stats_month = @target_month;
        DELETE FROM dbo.flight_stats_monthly_airport WHERE stats_month = @target_month;

        -- Monthly summary
        INSERT INTO dbo.flight_stats_monthly_summary (
            stats_month,
            total_flights, completed_flights, avg_daily_flights,
            avg_pct_complete_oooi,
            avg_block_time_min, avg_flight_time_min, avg_taxi_out_min, avg_taxi_in_min,
            total_tmi_affected, avg_daily_tmi_delay_min,
            busiest_day, busiest_day_flights,
            days_with_data
        )
        SELECT
            @target_month,
            SUM(total_flights),
            SUM(completed_flights),
            AVG(CAST(total_flights AS DECIMAL)),
            AVG(pct_complete_oooi),
            AVG(avg_block_time_min),
            AVG(avg_flight_time_min),
            AVG(avg_taxi_out_min),
            AVG(avg_taxi_in_min),
            SUM(flights_with_tmi),
            AVG(CAST(total_tmi_delay_min AS DECIMAL)),
            (SELECT TOP 1 stats_date FROM dbo.flight_stats_daily
             WHERE stats_date >= @target_month AND stats_date < @month_end ORDER BY total_flights DESC),
            MAX(total_flights),
            COUNT(*)
        FROM dbo.flight_stats_daily
        WHERE stats_date >= @target_month AND stats_date < @month_end;

        -- Monthly airport stats
        INSERT INTO dbo.flight_stats_monthly_airport (
            stats_month, icao,
            total_departures, total_arrivals,
            avg_daily_departures, avg_daily_arrivals,
            avg_taxi_out_min, avg_taxi_in_min,
            p90_taxi_out_min, p90_taxi_in_min,
            days_with_data
        )
        SELECT
            @target_month,
            icao,
            SUM(departures),
            SUM(arrivals),
            AVG(CAST(departures AS DECIMAL)),
            AVG(CAST(arrivals AS DECIMAL)),
            AVG(taxi_out_avg),
            AVG(taxi_in_avg),
            AVG(taxi_out_p90),
            AVG(taxi_in_p90),
            COUNT(*)
        FROM dbo.flight_stats_airport
        WHERE stats_date >= @target_month AND stats_date < @month_end
        GROUP BY icao;

        -- NEW: Generate hourly patterns for trend analysis
        EXEC dbo.sp_GenerateFlightStats_HourlyPatterns @target_month;

        -- Update run log
        UPDATE dbo.flight_stats_run_log
        SET completed_utc = GETUTCDATE(),
            status = 'SUCCESS',
            records_inserted = 1,
            execution_ms = DATEDIFF(MILLISECOND, @start_time, GETUTCDATE())
        WHERE id = @run_id;

    END TRY
    BEGIN CATCH
        UPDATE dbo.flight_stats_run_log
        SET completed_utc = GETUTCDATE(),
            status = 'FAILED',
            error_message = ERROR_MESSAGE(),
            execution_ms = DATEDIFF(MILLISECOND, @start_time, GETUTCDATE())
        WHERE id = @run_id;

        THROW;
    END CATCH
END;
GO

PRINT 'Updated sp_RollupFlightStats_Monthly to include hourly patterns';
GO

-- =====================================================
-- 7. UPDATE sp_CleanupFlightStats
-- Add cleanup for weekly and hourly_patterns tables
-- =====================================================

-- Read existing cleanup retention and add new tables
IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_CleanupFlightStats')
    DROP PROCEDURE dbo.sp_CleanupFlightStats;
GO

CREATE PROCEDURE dbo.sp_CleanupFlightStats
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @start_time DATETIME2 = GETUTCDATE();
    DECLARE @run_id BIGINT;
    DECLARE @total_deleted INT = 0;
    DECLARE @deleted INT;

    INSERT INTO dbo.flight_stats_run_log (run_type, started_utc, status)
    VALUES ('CLEANUP', @start_time, 'RUNNING');
    SET @run_id = SCOPE_IDENTITY();

    BEGIN TRY
        -- Tier 0: HOURLY (30 days)
        DELETE FROM dbo.flight_stats_hourly
        WHERE bucket_utc < DATEADD(DAY, -30, GETUTCDATE());
        SET @deleted = @@ROWCOUNT;
        SET @total_deleted = @total_deleted + @deleted;

        -- Tier 1: DAILY (730 days - updated from 180)
        DELETE FROM dbo.flight_stats_daily
        WHERE stats_date < DATEADD(DAY, -730, GETUTCDATE());
        SET @deleted = @@ROWCOUNT;
        SET @total_deleted = @total_deleted + @deleted;

        DELETE FROM dbo.flight_stats_airport
        WHERE stats_date < DATEADD(DAY, -730, GETUTCDATE());
        SET @deleted = @@ROWCOUNT;
        SET @total_deleted = @total_deleted + @deleted;

        DELETE FROM dbo.flight_stats_citypair
        WHERE stats_date < DATEADD(DAY, -730, GETUTCDATE());
        SET @deleted = @@ROWCOUNT;
        SET @total_deleted = @total_deleted + @deleted;

        DELETE FROM dbo.flight_stats_artcc
        WHERE stats_date < DATEADD(DAY, -730, GETUTCDATE());
        SET @deleted = @@ROWCOUNT;
        SET @total_deleted = @total_deleted + @deleted;

        DELETE FROM dbo.flight_stats_tmi
        WHERE stats_date < DATEADD(DAY, -730, GETUTCDATE());
        SET @deleted = @@ROWCOUNT;
        SET @total_deleted = @total_deleted + @deleted;

        DELETE FROM dbo.flight_stats_aircraft
        WHERE stats_date < DATEADD(DAY, -730, GETUTCDATE());
        SET @deleted = @@ROWCOUNT;
        SET @total_deleted = @total_deleted + @deleted;

        -- Tier 2: WEEKLY (730 days)
        DELETE FROM dbo.flight_stats_weekly
        WHERE week_start < DATEADD(DAY, -730, GETUTCDATE());
        SET @deleted = @@ROWCOUNT;
        SET @total_deleted = @total_deleted + @deleted;

        -- Tier 2: HOURLY PATTERNS (730 days)
        DELETE FROM dbo.flight_stats_hourly_patterns
        WHERE stats_month < DATEADD(DAY, -730, GETUTCDATE());
        SET @deleted = @@ROWCOUNT;
        SET @total_deleted = @total_deleted + @deleted;

        -- Tier 2: MONTHLY (730 days)
        DELETE FROM dbo.flight_stats_monthly_summary
        WHERE stats_month < DATEADD(DAY, -730, GETUTCDATE());
        SET @deleted = @@ROWCOUNT;
        SET @total_deleted = @total_deleted + @deleted;

        DELETE FROM dbo.flight_stats_monthly_airport
        WHERE stats_month < DATEADD(DAY, -730, GETUTCDATE());
        SET @deleted = @@ROWCOUNT;
        SET @total_deleted = @total_deleted + @deleted;

        -- Tier 3: YEARLY - no cleanup (indefinite)

        -- Cleanup run log (keep 90 days)
        DELETE FROM dbo.flight_stats_run_log
        WHERE started_utc < DATEADD(DAY, -90, GETUTCDATE());
        SET @deleted = @@ROWCOUNT;
        SET @total_deleted = @total_deleted + @deleted;

        UPDATE dbo.flight_stats_run_log
        SET completed_utc = GETUTCDATE(),
            status = 'SUCCESS',
            records_deleted = @total_deleted,
            execution_ms = DATEDIFF(MILLISECOND, @start_time, GETUTCDATE())
        WHERE id = @run_id;

    END TRY
    BEGIN CATCH
        UPDATE dbo.flight_stats_run_log
        SET completed_utc = GETUTCDATE(),
            status = 'FAILED',
            error_message = ERROR_MESSAGE(),
            execution_ms = DATEDIFF(MILLISECOND, @start_time, GETUTCDATE())
        WHERE id = @run_id;

        THROW;
    END CATCH
END;
GO

PRINT 'Updated sp_CleanupFlightStats with weekly and hourly_patterns cleanup';
GO

-- =====================================================
-- 8. ADD WEEKLY JOB CONFIGURATION
-- =====================================================

IF NOT EXISTS (SELECT 1 FROM dbo.flight_stats_job_config WHERE job_name = 'FlightStats_Weekly')
BEGIN
    INSERT INTO dbo.flight_stats_job_config
    (job_name, procedure_name, schedule_type, schedule_cron, schedule_utc_hour, schedule_utc_minute, schedule_day, description)
    VALUES
    ('FlightStats_Weekly', 'sp_GenerateFlightStats_Weekly', 'WEEKLY', '30 0 * * 1', 0, 30, 1,
     'Runs every Monday at 00:30 UTC to aggregate previous week statistics');
END
GO

PRINT 'Added FlightStats_Weekly job configuration';
GO

-- =====================================================
-- 9. UPDATE sp_ShouldRunFlightStatsJob FOR WEEKLY
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_ShouldRunFlightStatsJob')
    DROP PROCEDURE dbo.sp_ShouldRunFlightStatsJob;
GO

CREATE PROCEDURE dbo.sp_ShouldRunFlightStatsJob
    @job_name VARCHAR(64),
    @should_run BIT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @schedule_type VARCHAR(32);
    DECLARE @schedule_utc_hour TINYINT;
    DECLARE @schedule_utc_minute TINYINT;
    DECLARE @schedule_day TINYINT;
    DECLARE @last_run_utc DATETIME2;
    DECLARE @is_enabled BIT;

    SELECT
        @schedule_type = schedule_type,
        @schedule_utc_hour = schedule_utc_hour,
        @schedule_utc_minute = schedule_utc_minute,
        @schedule_day = schedule_day,
        @last_run_utc = last_run_utc,
        @is_enabled = is_enabled
    FROM dbo.flight_stats_job_config
    WHERE job_name = @job_name;

    SET @should_run = 0;

    IF @is_enabled = 0
        RETURN;

    DECLARE @now DATETIME2 = GETUTCDATE();
    DECLARE @current_hour TINYINT = DATEPART(HOUR, @now);
    DECLARE @current_minute TINYINT = DATEPART(MINUTE, @now);
    DECLARE @current_day TINYINT = DATEPART(DAY, @now);
    DECLARE @current_weekday TINYINT = DATEPART(WEEKDAY, @now);  -- 1=Sunday in default

    -- Check based on schedule type
    IF @schedule_type = 'HOURLY'
    BEGIN
        IF @current_minute >= ISNULL(@schedule_utc_minute, 5)
           AND (@last_run_utc IS NULL OR DATEDIFF(HOUR, @last_run_utc, @now) >= 1)
            SET @should_run = 1;
    END
    ELSE IF @schedule_type = 'DAILY'
    BEGIN
        IF (@current_hour > ISNULL(@schedule_utc_hour, 0)
            OR (@current_hour = ISNULL(@schedule_utc_hour, 0) AND @current_minute >= ISNULL(@schedule_utc_minute, 15)))
           AND (@last_run_utc IS NULL OR CAST(@last_run_utc AS DATE) < CAST(@now AS DATE))
            SET @should_run = 1;
    END
    ELSE IF @schedule_type = 'WEEKLY'
    BEGIN
        -- schedule_day for weekly: 1=Sunday, 2=Monday, etc. (SQL Server DATEPART convention)
        -- Default to Monday (2)
        DECLARE @target_weekday TINYINT = ISNULL(@schedule_day, 2);
        IF @current_weekday = @target_weekday
           AND (@current_hour > ISNULL(@schedule_utc_hour, 0)
                OR (@current_hour = ISNULL(@schedule_utc_hour, 0) AND @current_minute >= ISNULL(@schedule_utc_minute, 30)))
           AND (@last_run_utc IS NULL OR DATEDIFF(WEEK, @last_run_utc, @now) >= 1)
            SET @should_run = 1;
    END
    ELSE IF @schedule_type = 'MONTHLY'
    BEGIN
        IF @current_day = ISNULL(@schedule_day, 1)
           AND (@current_hour > ISNULL(@schedule_utc_hour, 1)
                OR (@current_hour = ISNULL(@schedule_utc_hour, 1) AND @current_minute >= ISNULL(@schedule_utc_minute, 30)))
           AND (@last_run_utc IS NULL OR DATEDIFF(MONTH, @last_run_utc, @now) >= 1)
            SET @should_run = 1;
    END
END;
GO

PRINT 'Updated sp_ShouldRunFlightStatsJob for WEEKLY schedule type';
GO

-- =====================================================
-- 10. TREND ANALYSIS VIEWS
-- =====================================================

-- View: Day-of-week comparison (last 52 weeks)
IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_flight_trends_dow')
    DROP VIEW dbo.vw_flight_trends_dow;
GO

CREATE VIEW dbo.vw_flight_trends_dow AS
SELECT
    'Monday' AS day_name, 1 AS iso_dow,
    AVG(CAST(flights_mon AS DECIMAL(10,2))) AS avg_flights,
    MIN(flights_mon) AS min_flights,
    MAX(flights_mon) AS max_flights
FROM dbo.flight_stats_weekly
WHERE week_start >= DATEADD(WEEK, -52, GETUTCDATE())
UNION ALL
SELECT 'Tuesday', 2, AVG(CAST(flights_tue AS DECIMAL(10,2))), MIN(flights_tue), MAX(flights_tue)
FROM dbo.flight_stats_weekly WHERE week_start >= DATEADD(WEEK, -52, GETUTCDATE())
UNION ALL
SELECT 'Wednesday', 3, AVG(CAST(flights_wed AS DECIMAL(10,2))), MIN(flights_wed), MAX(flights_wed)
FROM dbo.flight_stats_weekly WHERE week_start >= DATEADD(WEEK, -52, GETUTCDATE())
UNION ALL
SELECT 'Thursday', 4, AVG(CAST(flights_thu AS DECIMAL(10,2))), MIN(flights_thu), MAX(flights_thu)
FROM dbo.flight_stats_weekly WHERE week_start >= DATEADD(WEEK, -52, GETUTCDATE())
UNION ALL
SELECT 'Friday', 5, AVG(CAST(flights_fri AS DECIMAL(10,2))), MIN(flights_fri), MAX(flights_fri)
FROM dbo.flight_stats_weekly WHERE week_start >= DATEADD(WEEK, -52, GETUTCDATE())
UNION ALL
SELECT 'Saturday', 6, AVG(CAST(flights_sat AS DECIMAL(10,2))), MIN(flights_sat), MAX(flights_sat)
FROM dbo.flight_stats_weekly WHERE week_start >= DATEADD(WEEK, -52, GETUTCDATE())
UNION ALL
SELECT 'Sunday', 7, AVG(CAST(flights_sun AS DECIMAL(10,2))), MIN(flights_sun), MAX(flights_sun)
FROM dbo.flight_stats_weekly WHERE week_start >= DATEADD(WEEK, -52, GETUTCDATE());
GO

-- View: Monthly seasonality (last 24 months)
IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_flight_trends_monthly')
    DROP VIEW dbo.vw_flight_trends_monthly;
GO

CREATE VIEW dbo.vw_flight_trends_monthly AS
SELECT
    DATENAME(MONTH, stats_month) AS month_name,
    MONTH(stats_month) AS month_num,
    AVG(CAST(total_flights AS DECIMAL(12,2))) AS avg_total_flights,
    AVG(avg_daily_flights) AS avg_daily_flights,
    MIN(total_flights) AS min_total_flights,
    MAX(total_flights) AS max_total_flights
FROM dbo.flight_stats_monthly_summary
WHERE stats_month >= DATEADD(MONTH, -24, GETUTCDATE())
GROUP BY DATENAME(MONTH, stats_month), MONTH(stats_month);
GO

-- View: Hourly patterns (last 12 months)
IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_flight_trends_hourly')
    DROP VIEW dbo.vw_flight_trends_hourly;
GO

CREATE VIEW dbo.vw_flight_trends_hourly AS
SELECT
    hour_of_day,
    AVG(avg_daily_departures) AS avg_departures,
    AVG(avg_daily_arrivals) AS avg_arrivals,
    AVG(avg_daily_enroute) AS avg_enroute,
    MAX(peak_day_departures) AS peak_departures,
    MAX(peak_day_arrivals) AS peak_arrivals
FROM dbo.flight_stats_hourly_patterns
WHERE stats_month >= DATEADD(MONTH, -12, GETUTCDATE())
GROUP BY hour_of_day;
GO

PRINT 'Created trend analysis views';
GO

-- =====================================================
-- 11. BACKFILL PROCEDURE (OPTIONAL)
-- Run once to populate historical weekly data
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_BackfillFlightStats_Weekly')
    DROP PROCEDURE dbo.sp_BackfillFlightStats_Weekly;
GO

CREATE PROCEDURE dbo.sp_BackfillFlightStats_Weekly
    @weeks_back INT = 52   -- How many weeks to backfill
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @current_week DATE;
    DECLARE @today DATE = CAST(GETUTCDATE() AS DATE);
    DECLARE @dow INT = (DATEPART(WEEKDAY, @today) + 5) % 7;
    DECLARE @this_monday DATE = DATEADD(DAY, -@dow, @today);

    DECLARE @i INT = 1;
    WHILE @i <= @weeks_back
    BEGIN
        SET @current_week = DATEADD(WEEK, -@i, @this_monday);

        -- Only process if we have daily data for that week
        IF EXISTS (SELECT 1 FROM dbo.flight_stats_daily
                   WHERE stats_date >= @current_week
                     AND stats_date < DATEADD(DAY, 7, @current_week))
        BEGIN
            EXEC dbo.sp_GenerateFlightStats_Weekly @current_week;
            PRINT 'Processed week starting: ' + CAST(@current_week AS VARCHAR);
        END

        SET @i = @i + 1;
    END

    PRINT 'Weekly backfill complete';
END;
GO

PRINT 'Created sp_BackfillFlightStats_Weekly procedure';
GO

-- =====================================================
-- COMPLETE
-- =====================================================

PRINT '';
PRINT '073_flight_stats_trends.sql completed successfully';
PRINT '';
PRINT 'New tables:';
PRINT '  - flight_stats_weekly (day-of-week breakdown)';
PRINT '  - flight_stats_hourly_patterns (monthly hour patterns)';
PRINT '';
PRINT 'New procedures:';
PRINT '  - sp_GenerateFlightStats_Weekly (runs Mondays)';
PRINT '  - sp_GenerateFlightStats_HourlyPatterns (called by monthly)';
PRINT '  - sp_BackfillFlightStats_Weekly (one-time backfill)';
PRINT '';
PRINT 'New views:';
PRINT '  - vw_flight_trends_dow (day-of-week averages)';
PRINT '  - vw_flight_trends_monthly (seasonal patterns)';
PRINT '  - vw_flight_trends_hourly (peak hour patterns)';
PRINT '';
PRINT 'Updated:';
PRINT '  - DAILY retention tier: 180 -> 730 days';
PRINT '  - sp_RollupFlightStats_Monthly: now generates hourly patterns';
PRINT '  - sp_CleanupFlightStats: includes new tables';
PRINT '';
PRINT 'To backfill historical weekly data, run:';
PRINT '  EXEC sp_BackfillFlightStats_Weekly @weeks_back = 26;';
GO
