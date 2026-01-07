-- =====================================================
-- Flight Statistics Stored Procedures
-- Migration: 071_flight_stats_procedures.sql
-- Database: VATSIM_ADL (Azure SQL)
-- Purpose: Aggregation procedures for flight statistics
-- =====================================================

SET NOCOUNT ON;
GO

-- =====================================================
-- 1. sp_GenerateFlightStats_Hourly
-- Runs every hour to aggregate hourly statistics
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_GenerateFlightStats_Hourly')
    DROP PROCEDURE dbo.sp_GenerateFlightStats_Hourly;
GO

CREATE PROCEDURE dbo.sp_GenerateFlightStats_Hourly
    @hours_back INT = 2     -- Process last N hours (default 2 for overlap/late arrivals)
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @start_time DATETIME2 = GETUTCDATE();
    DECLARE @run_id BIGINT;
    DECLARE @records_processed INT = 0;
    DECLARE @records_inserted INT = 0;

    -- Log run start
    INSERT INTO dbo.flight_stats_run_log (run_type, started_utc, status)
    VALUES ('HOURLY', @start_time, 'RUNNING');
    SET @run_id = SCOPE_IDENTITY();

    BEGIN TRY
        -- Calculate time range
        DECLARE @bucket_start DATETIME2 = DATEADD(HOUR, -@hours_back, DATEADD(HOUR, DATEDIFF(HOUR, 0, GETUTCDATE()), 0));
        DECLARE @bucket_end DATETIME2 = DATEADD(HOUR, DATEDIFF(HOUR, 0, GETUTCDATE()), 0);

        -- Process each hour in range
        DECLARE @current_bucket DATETIME2 = @bucket_start;

        WHILE @current_bucket < @bucket_end
        BEGIN
            DECLARE @next_bucket DATETIME2 = DATEADD(HOUR, 1, @current_bucket);

            -- Delete existing record for this bucket (upsert pattern)
            DELETE FROM dbo.flight_stats_hourly WHERE bucket_utc = @current_bucket;

            -- Insert aggregated data
            INSERT INTO dbo.flight_stats_hourly (
                bucket_utc,
                departures, arrivals, enroute,
                domestic_dep, domestic_arr, intl_dep, intl_arr,
                arr_ne, arr_se, arr_mw, arr_sc, arr_w,
                avg_taxi_out_min, avg_taxi_in_min,
                tmi_affected, avg_tmi_delay_min
            )
            SELECT
                @current_bucket AS bucket_utc,

                -- Departures: flights that departed (off_utc) in this hour
                COUNT(DISTINCT CASE WHEN t.off_utc >= @current_bucket AND t.off_utc < @next_bucket THEN c.flight_uid END) AS departures,

                -- Arrivals: flights that arrived (on_utc) in this hour
                COUNT(DISTINCT CASE WHEN t.on_utc >= @current_bucket AND t.on_utc < @next_bucket THEN c.flight_uid END) AS arrivals,

                -- Enroute: flights active at the start of this hour
                COUNT(DISTINCT CASE WHEN c.phase IN ('enroute', 'descending')
                    AND t.off_utc < @current_bucket
                    AND (t.on_utc IS NULL OR t.on_utc >= @current_bucket) THEN c.flight_uid END) AS enroute,

                -- Regional breakdown for departures
                COUNT(DISTINCT CASE WHEN t.off_utc >= @current_bucket AND t.off_utc < @next_bucket
                    AND LEFT(p.fp_dept_icao, 1) = 'K' AND LEFT(p.fp_dest_icao, 1) = 'K' THEN c.flight_uid END) AS domestic_dep,
                COUNT(DISTINCT CASE WHEN t.on_utc >= @current_bucket AND t.on_utc < @next_bucket
                    AND LEFT(p.fp_dept_icao, 1) = 'K' AND LEFT(p.fp_dest_icao, 1) = 'K' THEN c.flight_uid END) AS domestic_arr,
                COUNT(DISTINCT CASE WHEN t.off_utc >= @current_bucket AND t.off_utc < @next_bucket
                    AND (LEFT(p.fp_dept_icao, 1) <> 'K' OR LEFT(p.fp_dest_icao, 1) <> 'K') THEN c.flight_uid END) AS intl_dep,
                COUNT(DISTINCT CASE WHEN t.on_utc >= @current_bucket AND t.on_utc < @next_bucket
                    AND (LEFT(p.fp_dept_icao, 1) <> 'K' OR LEFT(p.fp_dest_icao, 1) <> 'K') THEN c.flight_uid END) AS intl_arr,

                -- DCC Region arrivals (join to apts table)
                COUNT(DISTINCT CASE WHEN t.on_utc >= @current_bucket AND t.on_utc < @next_bucket
                    AND a.DCC_REGION = 'Northeast' THEN c.flight_uid END) AS arr_ne,
                COUNT(DISTINCT CASE WHEN t.on_utc >= @current_bucket AND t.on_utc < @next_bucket
                    AND a.DCC_REGION = 'Southeast' THEN c.flight_uid END) AS arr_se,
                COUNT(DISTINCT CASE WHEN t.on_utc >= @current_bucket AND t.on_utc < @next_bucket
                    AND a.DCC_REGION = 'Midwest' THEN c.flight_uid END) AS arr_mw,
                COUNT(DISTINCT CASE WHEN t.on_utc >= @current_bucket AND t.on_utc < @next_bucket
                    AND a.DCC_REGION = 'South Central' THEN c.flight_uid END) AS arr_sc,
                COUNT(DISTINCT CASE WHEN t.on_utc >= @current_bucket AND t.on_utc < @next_bucket
                    AND a.DCC_REGION = 'West' THEN c.flight_uid END) AS arr_w,

                -- Average taxi times for flights completing this hour
                AVG(CASE WHEN t.off_utc >= @current_bucket AND t.off_utc < @next_bucket
                    AND t.out_utc IS NOT NULL AND t.off_utc IS NOT NULL
                    THEN CAST(DATEDIFF(SECOND, t.out_utc, t.off_utc) AS DECIMAL) / 60.0 END) AS avg_taxi_out_min,
                AVG(CASE WHEN t.in_utc >= @current_bucket AND t.in_utc < @next_bucket
                    AND t.on_utc IS NOT NULL AND t.in_utc IS NOT NULL
                    THEN CAST(DATEDIFF(SECOND, t.on_utc, t.in_utc) AS DECIMAL) / 60.0 END) AS avg_taxi_in_min,

                -- TMI metrics
                COUNT(DISTINCT CASE WHEN tmi.ctl_type IS NOT NULL
                    AND (t.off_utc >= @current_bucket AND t.off_utc < @next_bucket) THEN c.flight_uid END) AS tmi_affected,
                AVG(CASE WHEN tmi.delay_minutes IS NOT NULL
                    AND (t.off_utc >= @current_bucket AND t.off_utc < @next_bucket)
                    THEN CAST(tmi.delay_minutes AS DECIMAL) END) AS avg_tmi_delay_min

            FROM dbo.adl_flight_core c
            LEFT JOIN dbo.adl_flight_times t ON c.flight_uid = t.flight_uid
            LEFT JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
            LEFT JOIN dbo.adl_flight_tmi tmi ON c.flight_uid = tmi.flight_uid
            LEFT JOIN dbo.apts a ON p.fp_dest_icao = a.ICAO_ID
            WHERE c.first_seen_utc < @next_bucket
              AND (c.last_seen_utc >= @current_bucket OR c.is_active = 1);

            SET @records_processed = @records_processed + @@ROWCOUNT;
            IF @@ROWCOUNT > 0 SET @records_inserted = @records_inserted + 1;

            SET @current_bucket = @next_bucket;
        END;

        -- Update run log
        UPDATE dbo.flight_stats_run_log
        SET completed_utc = GETUTCDATE(),
            status = 'SUCCESS',
            records_processed = @records_processed,
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

-- =====================================================
-- 2. sp_GenerateFlightStats_Daily
-- Runs daily to aggregate previous day's statistics
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_GenerateFlightStats_Daily')
    DROP PROCEDURE dbo.sp_GenerateFlightStats_Daily;
GO

CREATE PROCEDURE dbo.sp_GenerateFlightStats_Daily
    @target_date DATE = NULL    -- NULL = yesterday
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @start_time DATETIME2 = GETUTCDATE();
    DECLARE @run_id BIGINT;
    DECLARE @records_processed INT = 0;

    -- Default to yesterday
    IF @target_date IS NULL
        SET @target_date = DATEADD(DAY, -1, CAST(GETUTCDATE() AS DATE));

    DECLARE @day_start DATETIME2 = CAST(@target_date AS DATETIME2);
    DECLARE @day_end DATETIME2 = DATEADD(DAY, 1, @day_start);

    -- Log run start
    INSERT INTO dbo.flight_stats_run_log (run_type, started_utc, status)
    VALUES ('DAILY', @start_time, 'RUNNING');
    SET @run_id = SCOPE_IDENTITY();

    BEGIN TRY
        -- Delete existing record for this date
        DELETE FROM dbo.flight_stats_daily WHERE stats_date = @target_date;

        -- Calculate peak hour
        DECLARE @peak_hour TINYINT, @peak_flights INT;

        SELECT TOP 1 @peak_hour = DATEPART(HOUR, t.off_utc), @peak_flights = COUNT(*)
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_times t ON c.flight_uid = t.flight_uid
        WHERE t.off_utc >= @day_start AND t.off_utc < @day_end
        GROUP BY DATEPART(HOUR, t.off_utc)
        ORDER BY COUNT(*) DESC;

        -- Insert daily summary
        INSERT INTO dbo.flight_stats_daily (
            stats_date,
            total_flights, completed_flights, domestic_flights, international_flights,
            pct_out_captured, pct_off_captured, pct_on_captured, pct_in_captured, pct_complete_oooi,
            avg_block_time_min, avg_flight_time_min, avg_taxi_out_min, avg_taxi_in_min,
            peak_hour_utc, peak_hour_flights,
            flights_with_tmi, total_tmi_delay_min, gs_affected_flights, gdp_affected_flights,
            arr_ne, arr_se, arr_mw, arr_sc, arr_w
        )
        SELECT
            @target_date AS stats_date,

            -- Flight counts
            COUNT(DISTINCT c.flight_uid) AS total_flights,
            COUNT(DISTINCT CASE WHEN t.out_utc IS NOT NULL AND t.off_utc IS NOT NULL
                AND t.on_utc IS NOT NULL AND t.in_utc IS NOT NULL THEN c.flight_uid END) AS completed_flights,
            COUNT(DISTINCT CASE WHEN LEFT(p.fp_dept_icao, 1) = 'K' AND LEFT(p.fp_dest_icao, 1) = 'K'
                THEN c.flight_uid END) AS domestic_flights,
            COUNT(DISTINCT CASE WHEN LEFT(p.fp_dept_icao, 1) <> 'K' OR LEFT(p.fp_dest_icao, 1) <> 'K'
                THEN c.flight_uid END) AS international_flights,

            -- OOOI capture rates
            CAST(COUNT(DISTINCT CASE WHEN t.out_utc IS NOT NULL THEN c.flight_uid END) * 100.0 / NULLIF(COUNT(DISTINCT c.flight_uid), 0) AS DECIMAL(5,2)),
            CAST(COUNT(DISTINCT CASE WHEN t.off_utc IS NOT NULL THEN c.flight_uid END) * 100.0 / NULLIF(COUNT(DISTINCT c.flight_uid), 0) AS DECIMAL(5,2)),
            CAST(COUNT(DISTINCT CASE WHEN t.on_utc IS NOT NULL THEN c.flight_uid END) * 100.0 / NULLIF(COUNT(DISTINCT c.flight_uid), 0) AS DECIMAL(5,2)),
            CAST(COUNT(DISTINCT CASE WHEN t.in_utc IS NOT NULL THEN c.flight_uid END) * 100.0 / NULLIF(COUNT(DISTINCT c.flight_uid), 0) AS DECIMAL(5,2)),
            CAST(COUNT(DISTINCT CASE WHEN t.out_utc IS NOT NULL AND t.off_utc IS NOT NULL
                AND t.on_utc IS NOT NULL AND t.in_utc IS NOT NULL THEN c.flight_uid END) * 100.0 / NULLIF(COUNT(DISTINCT c.flight_uid), 0) AS DECIMAL(5,2)),

            -- Time averages
            AVG(CASE WHEN t.out_utc IS NOT NULL AND t.in_utc IS NOT NULL
                THEN CAST(DATEDIFF(SECOND, t.out_utc, t.in_utc) AS DECIMAL) / 60.0 END),
            AVG(CASE WHEN t.off_utc IS NOT NULL AND t.on_utc IS NOT NULL
                THEN CAST(DATEDIFF(SECOND, t.off_utc, t.on_utc) AS DECIMAL) / 60.0 END),
            AVG(CASE WHEN t.out_utc IS NOT NULL AND t.off_utc IS NOT NULL
                THEN CAST(DATEDIFF(SECOND, t.out_utc, t.off_utc) AS DECIMAL) / 60.0 END),
            AVG(CASE WHEN t.on_utc IS NOT NULL AND t.in_utc IS NOT NULL
                THEN CAST(DATEDIFF(SECOND, t.on_utc, t.in_utc) AS DECIMAL) / 60.0 END),

            -- Peak hour
            @peak_hour, @peak_flights,

            -- TMI impact
            COUNT(DISTINCT CASE WHEN tmi.ctl_type IS NOT NULL THEN c.flight_uid END),
            SUM(ISNULL(tmi.delay_minutes, 0)),
            COUNT(DISTINCT CASE WHEN tmi.ctl_type = 'GS' THEN c.flight_uid END),
            COUNT(DISTINCT CASE WHEN tmi.ctl_type = 'GDP' THEN c.flight_uid END),

            -- DCC regions
            COUNT(DISTINCT CASE WHEN a.DCC_REGION = 'Northeast' AND t.on_utc IS NOT NULL THEN c.flight_uid END),
            COUNT(DISTINCT CASE WHEN a.DCC_REGION = 'Southeast' AND t.on_utc IS NOT NULL THEN c.flight_uid END),
            COUNT(DISTINCT CASE WHEN a.DCC_REGION = 'Midwest' AND t.on_utc IS NOT NULL THEN c.flight_uid END),
            COUNT(DISTINCT CASE WHEN a.DCC_REGION = 'South Central' AND t.on_utc IS NOT NULL THEN c.flight_uid END),
            COUNT(DISTINCT CASE WHEN a.DCC_REGION = 'West' AND t.on_utc IS NOT NULL THEN c.flight_uid END)

        FROM dbo.adl_flight_core c
        LEFT JOIN dbo.adl_flight_times t ON c.flight_uid = t.flight_uid
        LEFT JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
        LEFT JOIN dbo.adl_flight_tmi tmi ON c.flight_uid = tmi.flight_uid
        LEFT JOIN dbo.apts a ON p.fp_dest_icao = a.ICAO_ID
        WHERE (t.off_utc >= @day_start AND t.off_utc < @day_end)
           OR (t.on_utc >= @day_start AND t.on_utc < @day_end);

        SET @records_processed = @@ROWCOUNT;

        -- Call sub-procedures for detailed stats
        EXEC dbo.sp_GenerateFlightStats_Airport @target_date;
        EXEC dbo.sp_GenerateFlightStats_Citypair @target_date;
        EXEC dbo.sp_GenerateFlightStats_Aircraft @target_date;
        EXEC dbo.sp_GenerateFlightStats_TMI @target_date;
        EXEC dbo.sp_GenerateFlightStats_ARTCC @target_date;

        -- Run cleanup for expired retention
        EXEC dbo.sp_CleanupFlightStats;

        -- Update run log
        UPDATE dbo.flight_stats_run_log
        SET completed_utc = GETUTCDATE(),
            status = 'SUCCESS',
            records_processed = @records_processed,
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

-- =====================================================
-- 3. sp_GenerateFlightStats_Airport
-- Generates per-airport taxi time statistics
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_GenerateFlightStats_Airport')
    DROP PROCEDURE dbo.sp_GenerateFlightStats_Airport;
GO

CREATE PROCEDURE dbo.sp_GenerateFlightStats_Airport
    @target_date DATE
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @day_start DATETIME2 = CAST(@target_date AS DATETIME2);
    DECLARE @day_end DATETIME2 = DATEADD(DAY, 1, @day_start);

    -- Delete existing records for this date
    DELETE FROM dbo.flight_stats_airport WHERE stats_date = @target_date;

    -- Build taxi time data with percentiles calculated separately
    ;WITH TaxiOutData AS (
        SELECT
            p.fp_dept_icao AS icao,
            DATEDIFF(SECOND, t.out_utc, t.off_utc) / 60.0 AS taxi_time_min
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_times t ON c.flight_uid = t.flight_uid
        JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
        WHERE t.off_utc >= @day_start AND t.off_utc < @day_end
          AND t.out_utc IS NOT NULL AND t.off_utc IS NOT NULL
          AND DATEDIFF(SECOND, t.out_utc, t.off_utc) BETWEEN 60 AND 7200
    ),
    TaxiInData AS (
        SELECT
            p.fp_dest_icao AS icao,
            DATEDIFF(SECOND, t.on_utc, t.in_utc) / 60.0 AS taxi_time_min
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_times t ON c.flight_uid = t.flight_uid
        JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
        WHERE t.in_utc >= @day_start AND t.in_utc < @day_end
          AND t.on_utc IS NOT NULL AND t.in_utc IS NOT NULL
          AND DATEDIFF(SECOND, t.on_utc, t.in_utc) BETWEEN 60 AND 7200
    ),
    DepStatsBase AS (
        SELECT
            icao,
            COUNT(*) AS taxi_out_count,
            AVG(taxi_time_min) AS taxi_out_avg,
            MIN(taxi_time_min) AS taxi_out_min,
            MAX(taxi_time_min) AS taxi_out_max
        FROM TaxiOutData
        GROUP BY icao
    ),
    DepPercentiles AS (
        SELECT DISTINCT
            icao,
            PERCENTILE_CONT(0.50) WITHIN GROUP (ORDER BY taxi_time_min) OVER (PARTITION BY icao) AS taxi_out_p50,
            PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY taxi_time_min) OVER (PARTITION BY icao) AS taxi_out_p75,
            PERCENTILE_CONT(0.90) WITHIN GROUP (ORDER BY taxi_time_min) OVER (PARTITION BY icao) AS taxi_out_p90,
            PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY taxi_time_min) OVER (PARTITION BY icao) AS taxi_out_p95
        FROM TaxiOutData
    ),
    ArrStatsBase AS (
        SELECT
            icao,
            COUNT(*) AS taxi_in_count,
            AVG(taxi_time_min) AS taxi_in_avg,
            MIN(taxi_time_min) AS taxi_in_min,
            MAX(taxi_time_min) AS taxi_in_max
        FROM TaxiInData
        GROUP BY icao
    ),
    ArrPercentiles AS (
        SELECT DISTINCT
            icao,
            PERCENTILE_CONT(0.50) WITHIN GROUP (ORDER BY taxi_time_min) OVER (PARTITION BY icao) AS taxi_in_p50,
            PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY taxi_time_min) OVER (PARTITION BY icao) AS taxi_in_p75,
            PERCENTILE_CONT(0.90) WITHIN GROUP (ORDER BY taxi_time_min) OVER (PARTITION BY icao) AS taxi_in_p90,
            PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY taxi_time_min) OVER (PARTITION BY icao) AS taxi_in_p95
        FROM TaxiInData
    ),
    DepCounts AS (
        SELECT p.fp_dept_icao AS icao, COUNT(*) AS departures
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_times t ON c.flight_uid = t.flight_uid
        JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
        WHERE t.off_utc >= @day_start AND t.off_utc < @day_end
        GROUP BY p.fp_dept_icao
    ),
    ArrCounts AS (
        SELECT p.fp_dest_icao AS icao, COUNT(*) AS arrivals
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_times t ON c.flight_uid = t.flight_uid
        JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
        WHERE t.on_utc >= @day_start AND t.on_utc < @day_end
        GROUP BY p.fp_dest_icao
    ),
    ExtendedTimes AS (
        SELECT
            p.fp_dept_icao AS icao,
            AVG(DATEDIFF(SECOND, t.parking_left_utc, t.taxiway_entered_utc)) AS avg_pushback_sec,
            AVG(DATEDIFF(SECOND, t.taxiway_entered_utc, t.hold_entered_utc)) AS avg_taxi_to_hold_sec,
            AVG(DATEDIFF(SECOND, t.hold_entered_utc, t.runway_entered_utc)) AS avg_hold_time_sec,
            AVG(DATEDIFF(SECOND, t.runway_entered_utc, t.off_utc)) AS avg_runway_time_sec
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_times t ON c.flight_uid = t.flight_uid
        JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
        WHERE t.off_utc >= @day_start AND t.off_utc < @day_end
          AND t.parking_left_utc IS NOT NULL
        GROUP BY p.fp_dept_icao
    ),
    ExtendedArrTimes AS (
        SELECT
            p.fp_dest_icao AS icao,
            AVG(DATEDIFF(SECOND, t.touchdown_utc, t.rollout_end_utc)) AS avg_rollout_sec,
            AVG(DATEDIFF(SECOND, t.rollout_end_utc, t.parking_entered_utc)) AS avg_arrival_taxi_sec
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_times t ON c.flight_uid = t.flight_uid
        JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
        WHERE t.in_utc >= @day_start AND t.in_utc < @day_end
          AND t.touchdown_utc IS NOT NULL
        GROUP BY p.fp_dest_icao
    ),
    AllAirports AS (
        SELECT icao FROM DepCounts
        UNION
        SELECT icao FROM ArrCounts
    )
    INSERT INTO dbo.flight_stats_airport (
        stats_date, icao,
        departures, arrivals,
        taxi_out_count, taxi_out_avg, taxi_out_min, taxi_out_max,
        taxi_out_p50, taxi_out_p75, taxi_out_p90, taxi_out_p95,
        taxi_in_count, taxi_in_avg, taxi_in_min, taxi_in_max,
        taxi_in_p50, taxi_in_p75, taxi_in_p90, taxi_in_p95,
        avg_pushback_sec, avg_taxi_to_hold_sec, avg_hold_time_sec, avg_runway_time_sec,
        avg_rollout_sec, avg_arrival_taxi_sec
    )
    SELECT
        @target_date,
        a.icao,
        ISNULL(dc.departures, 0),
        ISNULL(ac.arrivals, 0),
        dsb.taxi_out_count, dsb.taxi_out_avg, dsb.taxi_out_min, dsb.taxi_out_max,
        dp.taxi_out_p50, dp.taxi_out_p75, dp.taxi_out_p90, dp.taxi_out_p95,
        asb.taxi_in_count, asb.taxi_in_avg, asb.taxi_in_min, asb.taxi_in_max,
        ap.taxi_in_p50, ap.taxi_in_p75, ap.taxi_in_p90, ap.taxi_in_p95,
        et.avg_pushback_sec, et.avg_taxi_to_hold_sec, et.avg_hold_time_sec, et.avg_runway_time_sec,
        eat.avg_rollout_sec, eat.avg_arrival_taxi_sec
    FROM AllAirports a
    LEFT JOIN DepCounts dc ON a.icao = dc.icao
    LEFT JOIN ArrCounts ac ON a.icao = ac.icao
    LEFT JOIN DepStatsBase dsb ON a.icao = dsb.icao
    LEFT JOIN DepPercentiles dp ON a.icao = dp.icao
    LEFT JOIN ArrStatsBase asb ON a.icao = asb.icao
    LEFT JOIN ArrPercentiles ap ON a.icao = ap.icao
    LEFT JOIN ExtendedTimes et ON a.icao = et.icao
    LEFT JOIN ExtendedArrTimes eat ON a.icao = eat.icao
    WHERE a.icao IS NOT NULL AND LEN(a.icao) = 4;
END;
GO

-- =====================================================
-- 4. sp_GenerateFlightStats_Citypair
-- Generates city-pair route statistics
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_GenerateFlightStats_Citypair')
    DROP PROCEDURE dbo.sp_GenerateFlightStats_Citypair;
GO

CREATE PROCEDURE dbo.sp_GenerateFlightStats_Citypair
    @target_date DATE
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @day_start DATETIME2 = CAST(@target_date AS DATETIME2);
    DECLARE @day_end DATETIME2 = DATEADD(DAY, 1, @day_start);

    DELETE FROM dbo.flight_stats_citypair WHERE stats_date = @target_date;

    ;WITH FlightData AS (
        SELECT
            p.fp_dept_icao AS origin_icao,
            p.fp_dest_icao AS dest_icao,
            p.aircraft_type,
            DATEDIFF(SECOND, t.out_utc, t.in_utc) / 60.0 AS block_time_min,
            DATEDIFF(SECOND, t.off_utc, t.on_utc) / 60.0 AS flight_time_min,
            DATEDIFF(SECOND, t.out_utc, t.off_utc) / 60.0 AS taxi_out_min,
            DATEDIFF(SECOND, t.on_utc, t.in_utc) / 60.0 AS taxi_in_min,
            CASE WHEN t.out_utc IS NOT NULL AND t.off_utc IS NOT NULL
                AND t.on_utc IS NOT NULL AND t.in_utc IS NOT NULL THEN 1 ELSE 0 END AS is_complete,
            CASE WHEN tmi.ctl_type IS NOT NULL THEN 1 ELSE 0 END AS has_tmi,
            ISNULL(tmi.delay_minutes, 0) AS tmi_delay_min
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_times t ON c.flight_uid = t.flight_uid
        JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
        LEFT JOIN dbo.adl_flight_tmi tmi ON c.flight_uid = tmi.flight_uid
        WHERE (t.off_utc >= @day_start AND t.off_utc < @day_end)
          AND p.fp_dept_icao IS NOT NULL AND p.fp_dest_icao IS NOT NULL
    ),
    RouteStats AS (
        SELECT
            origin_icao,
            dest_icao,
            COUNT(*) AS flight_count,
            SUM(is_complete) AS completed_count,
            AVG(block_time_min) AS block_time_avg,
            MIN(block_time_min) AS block_time_min,
            MAX(block_time_min) AS block_time_max,
            AVG(flight_time_min) AS flight_time_avg,
            MIN(flight_time_min) AS flight_time_min,
            MAX(flight_time_min) AS flight_time_max,
            AVG(taxi_out_min) AS taxi_out_avg,
            AVG(taxi_in_min) AS taxi_in_avg,
            SUM(has_tmi) AS tmi_affected,
            AVG(CASE WHEN has_tmi = 1 THEN CAST(tmi_delay_min AS DECIMAL) END) AS avg_tmi_delay_min
        FROM FlightData
        GROUP BY origin_icao, dest_icao
        HAVING COUNT(*) >= 2
    ),
    RoutePercentiles AS (
        SELECT DISTINCT
            origin_icao,
            dest_icao,
            PERCENTILE_CONT(0.50) WITHIN GROUP (ORDER BY block_time_min) OVER (PARTITION BY origin_icao, dest_icao) AS block_time_p50,
            PERCENTILE_CONT(0.50) WITHIN GROUP (ORDER BY flight_time_min) OVER (PARTITION BY origin_icao, dest_icao) AS flight_time_p50
        FROM FlightData
    ),
    AircraftCounts AS (
        SELECT
            origin_icao, dest_icao, aircraft_type,
            COUNT(*) AS type_count,
            ROW_NUMBER() OVER (PARTITION BY origin_icao, dest_icao ORDER BY COUNT(*) DESC) AS rn
        FROM FlightData
        WHERE aircraft_type IS NOT NULL
        GROUP BY origin_icao, dest_icao, aircraft_type
    ),
    AircraftJson AS (
        SELECT
            origin_icao, dest_icao,
            '[' + STRING_AGG('{"type":"' + aircraft_type + '","count":' + CAST(type_count AS VARCHAR) + '}', ',') + ']' AS top_aircraft_types
        FROM AircraftCounts
        WHERE rn <= 5
        GROUP BY origin_icao, dest_icao
    )
    INSERT INTO dbo.flight_stats_citypair (
        stats_date, origin_icao, dest_icao,
        flight_count, completed_count,
        block_time_avg, block_time_min, block_time_max, block_time_p50,
        flight_time_avg, flight_time_min, flight_time_max, flight_time_p50,
        taxi_out_avg, taxi_in_avg,
        tmi_affected, avg_tmi_delay_min,
        top_aircraft_types
    )
    SELECT
        @target_date,
        rs.origin_icao,
        rs.dest_icao,
        rs.flight_count,
        rs.completed_count,
        rs.block_time_avg,
        rs.block_time_min,
        rs.block_time_max,
        rp.block_time_p50,
        rs.flight_time_avg,
        rs.flight_time_min,
        rs.flight_time_max,
        rp.flight_time_p50,
        rs.taxi_out_avg,
        rs.taxi_in_avg,
        rs.tmi_affected,
        rs.avg_tmi_delay_min,
        aj.top_aircraft_types
    FROM RouteStats rs
    LEFT JOIN RoutePercentiles rp ON rs.origin_icao = rp.origin_icao AND rs.dest_icao = rp.dest_icao
    LEFT JOIN AircraftJson aj ON rs.origin_icao = aj.origin_icao AND rs.dest_icao = aj.dest_icao;
END;
GO

-- =====================================================
-- 5. sp_GenerateFlightStats_Aircraft
-- Generates aircraft type statistics
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_GenerateFlightStats_Aircraft')
    DROP PROCEDURE dbo.sp_GenerateFlightStats_Aircraft;
GO

CREATE PROCEDURE dbo.sp_GenerateFlightStats_Aircraft
    @target_date DATE
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @day_start DATETIME2 = CAST(@target_date AS DATETIME2);
    DECLARE @day_end DATETIME2 = DATEADD(DAY, 1, @day_start);

    DELETE FROM dbo.flight_stats_aircraft WHERE stats_date = @target_date;

    ;WITH FlightData AS (
        SELECT
            p.aircraft_type,
            p.fp_dept_icao,
            p.fp_dest_icao,
            c.callsign,
            pos.groundspeed_kts,
            pos.altitude_ft,
            DATEDIFF(SECOND, t.off_utc, t.on_utc) / 60.0 AS flight_time_min,
            DATEDIFF(SECOND, t.out_utc, t.off_utc) / 60.0 AS taxi_out_min,
            DATEDIFF(SECOND, t.on_utc, t.in_utc) / 60.0 AS taxi_in_min,
            CASE WHEN t.out_utc IS NOT NULL AND t.off_utc IS NOT NULL
                AND t.on_utc IS NOT NULL AND t.in_utc IS NOT NULL THEN 1 ELSE 0 END AS is_complete
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_times t ON c.flight_uid = t.flight_uid
        JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
        LEFT JOIN dbo.adl_flight_position pos ON c.flight_uid = pos.flight_uid
        WHERE t.off_utc >= @day_start AND t.off_utc < @day_end
          AND p.aircraft_type IS NOT NULL
    ),
    RouteCounts AS (
        SELECT
            aircraft_type,
            fp_dept_icao AS origin,
            fp_dest_icao AS dest,
            COUNT(*) AS route_count,
            ROW_NUMBER() OVER (PARTITION BY aircraft_type ORDER BY COUNT(*) DESC) AS rn
        FROM FlightData
        GROUP BY aircraft_type, fp_dept_icao, fp_dest_icao
    ),
    AirlineCounts AS (
        SELECT
            aircraft_type,
            LEFT(callsign, 3) AS airline,
            COUNT(*) AS airline_count,
            ROW_NUMBER() OVER (PARTITION BY aircraft_type ORDER BY COUNT(*) DESC) AS rn
        FROM FlightData
        WHERE callsign LIKE '[A-Z][A-Z][A-Z]%'
        GROUP BY aircraft_type, LEFT(callsign, 3)
    )
    INSERT INTO dbo.flight_stats_aircraft (
        stats_date, aircraft_type,
        flight_count, completed_count,
        avg_groundspeed_kts, avg_cruise_altitude, avg_flight_time_min,
        avg_taxi_out_min, avg_taxi_in_min,
        top_routes, top_airlines
    )
    SELECT
        @target_date,
        fd.aircraft_type,
        COUNT(*),
        SUM(fd.is_complete),
        AVG(fd.groundspeed_kts),
        AVG(CASE WHEN fd.altitude_ft > 10000 THEN fd.altitude_ft END),  -- Only cruise altitudes
        AVG(fd.flight_time_min),
        AVG(fd.taxi_out_min),
        AVG(fd.taxi_in_min),
        (SELECT '[' + STRING_AGG('{"origin":"' + rc.origin + '","dest":"' + rc.dest + '","count":' + CAST(rc.route_count AS VARCHAR) + '}', ',') + ']'
         FROM RouteCounts rc WHERE rc.aircraft_type = fd.aircraft_type AND rc.rn <= 5),
        (SELECT '[' + STRING_AGG('{"airline":"' + ac.airline + '","count":' + CAST(ac.airline_count AS VARCHAR) + '}', ',') + ']'
         FROM AirlineCounts ac WHERE ac.aircraft_type = fd.aircraft_type AND ac.rn <= 5)
    FROM FlightData fd
    GROUP BY fd.aircraft_type
    HAVING COUNT(*) >= 3;  -- Only include types with 3+ flights
END;
GO

-- =====================================================
-- 6. sp_GenerateFlightStats_TMI
-- Generates TMI impact statistics
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_GenerateFlightStats_TMI')
    DROP PROCEDURE dbo.sp_GenerateFlightStats_TMI;
GO

CREATE PROCEDURE dbo.sp_GenerateFlightStats_TMI
    @target_date DATE
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @day_start DATETIME2 = CAST(@target_date AS DATETIME2);
    DECLARE @day_end DATETIME2 = DATEADD(DAY, 1, @day_start);

    DELETE FROM dbo.flight_stats_tmi WHERE stats_date = @target_date;

    ;WITH TMIData AS (
        SELECT
            tmi.ctl_type AS tmi_type,
            p.fp_dest_icao AS airport_icao,
            ISNULL(tmi.delay_minutes, 0) AS delay_minutes,
            tmi.is_exempt,
            DATEPART(HOUR, t.off_utc) AS dep_hour
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_times t ON c.flight_uid = t.flight_uid
        JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
        JOIN dbo.adl_flight_tmi tmi ON c.flight_uid = tmi.flight_uid
        WHERE t.off_utc >= @day_start AND t.off_utc < @day_end
          AND tmi.ctl_type IS NOT NULL
    ),
    TMIStats AS (
        SELECT
            tmi_type,
            airport_icao,
            COUNT(*) AS affected_flights,
            SUM(CAST(is_exempt AS INT)) AS exempt_flights,
            SUM(delay_minutes) AS total_delay_min,
            AVG(CAST(delay_minutes AS DECIMAL)) AS avg_delay_min,
            MAX(delay_minutes) AS max_delay_min
        FROM TMIData
        GROUP BY tmi_type, airport_icao
    ),
    TMIPercentiles AS (
        SELECT DISTINCT
            tmi_type,
            airport_icao,
            PERCENTILE_CONT(0.50) WITHIN GROUP (ORDER BY delay_minutes) OVER (PARTITION BY tmi_type, airport_icao) AS p50_delay_min,
            PERCENTILE_CONT(0.90) WITHIN GROUP (ORDER BY delay_minutes) OVER (PARTITION BY tmi_type, airport_icao) AS p90_delay_min
        FROM TMIData
    ),
    HourlyAgg AS (
        SELECT
            tmi_type, airport_icao, dep_hour,
            COUNT(*) AS hour_count
        FROM TMIData
        GROUP BY tmi_type, airport_icao, dep_hour
    ),
    HourlyJson AS (
        SELECT
            tmi_type, airport_icao,
            '{' + STRING_AGG('"h' + CAST(dep_hour AS VARCHAR) + '":' + CAST(hour_count AS VARCHAR), ',') + '}' AS hourly_affected
        FROM HourlyAgg
        GROUP BY tmi_type, airport_icao
    )
    INSERT INTO dbo.flight_stats_tmi (
        stats_date, tmi_type, airport_icao,
        affected_flights, exempt_flights,
        total_delay_min, avg_delay_min, max_delay_min,
        p50_delay_min, p90_delay_min,
        hourly_affected
    )
    SELECT
        @target_date,
        ts.tmi_type,
        ts.airport_icao,
        ts.affected_flights,
        ts.exempt_flights,
        ts.total_delay_min,
        ts.avg_delay_min,
        ts.max_delay_min,
        tp.p50_delay_min,
        tp.p90_delay_min,
        hj.hourly_affected
    FROM TMIStats ts
    LEFT JOIN TMIPercentiles tp ON ts.tmi_type = tp.tmi_type AND ts.airport_icao = tp.airport_icao
    LEFT JOIN HourlyJson hj ON ts.tmi_type = hj.tmi_type AND ts.airport_icao = hj.airport_icao;
END;
GO

-- =====================================================
-- 6b. sp_GenerateFlightStats_ARTCC
-- Generates ARTCC traffic statistics from boundary log
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_GenerateFlightStats_ARTCC')
    DROP PROCEDURE dbo.sp_GenerateFlightStats_ARTCC;
GO

CREATE PROCEDURE dbo.sp_GenerateFlightStats_ARTCC
    @target_date DATE
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @day_start DATETIME2 = CAST(@target_date AS DATETIME2);
    DECLARE @day_end DATETIME2 = DATEADD(DAY, 1, @day_start);

    DELETE FROM dbo.flight_stats_artcc WHERE stats_date = @target_date;

    -- Build ARTCC statistics from boundary log
    ;WITH ARTCCEntries AS (
        SELECT
            boundary_code AS artcc,
            flight_uid,
            entry_time,
            exit_time,
            duration_seconds,
            DATEPART(HOUR, entry_time) AS entry_hour
        FROM dbo.adl_flight_boundary_log
        WHERE boundary_type = 'ARTCC'
          AND entry_time >= @day_start AND entry_time < @day_end
    ),
    ARTCCStats AS (
        SELECT
            artcc,
            COUNT(*) AS entries,
            COUNT(CASE WHEN exit_time IS NOT NULL THEN 1 END) AS exits,
            COUNT(CASE WHEN exit_time IS NOT NULL THEN 1 END) AS transits,
            AVG(CASE WHEN duration_seconds IS NOT NULL
                THEN CAST(duration_seconds AS DECIMAL) / 60.0 END) AS avg_time_in_artcc
        FROM ARTCCEntries
        GROUP BY artcc
    ),
    HourlyEntries AS (
        SELECT
            artcc,
            entry_hour,
            COUNT(*) AS hour_count
        FROM ARTCCEntries
        GROUP BY artcc, entry_hour
    ),
    PeakHours AS (
        SELECT
            artcc,
            entry_hour AS peak_hour,
            hour_count AS peak_hour_entries,
            ROW_NUMBER() OVER (PARTITION BY artcc ORDER BY hour_count DESC) AS rn
        FROM HourlyEntries
    ),
    HourlyJson AS (
        SELECT
            artcc,
            (
                SELECT
                    ISNULL(SUM(CASE WHEN entry_hour = 0 THEN hour_count END), 0) AS h0,
                    ISNULL(SUM(CASE WHEN entry_hour = 1 THEN hour_count END), 0) AS h1,
                    ISNULL(SUM(CASE WHEN entry_hour = 2 THEN hour_count END), 0) AS h2,
                    ISNULL(SUM(CASE WHEN entry_hour = 3 THEN hour_count END), 0) AS h3,
                    ISNULL(SUM(CASE WHEN entry_hour = 4 THEN hour_count END), 0) AS h4,
                    ISNULL(SUM(CASE WHEN entry_hour = 5 THEN hour_count END), 0) AS h5,
                    ISNULL(SUM(CASE WHEN entry_hour = 6 THEN hour_count END), 0) AS h6,
                    ISNULL(SUM(CASE WHEN entry_hour = 7 THEN hour_count END), 0) AS h7,
                    ISNULL(SUM(CASE WHEN entry_hour = 8 THEN hour_count END), 0) AS h8,
                    ISNULL(SUM(CASE WHEN entry_hour = 9 THEN hour_count END), 0) AS h9,
                    ISNULL(SUM(CASE WHEN entry_hour = 10 THEN hour_count END), 0) AS h10,
                    ISNULL(SUM(CASE WHEN entry_hour = 11 THEN hour_count END), 0) AS h11,
                    ISNULL(SUM(CASE WHEN entry_hour = 12 THEN hour_count END), 0) AS h12,
                    ISNULL(SUM(CASE WHEN entry_hour = 13 THEN hour_count END), 0) AS h13,
                    ISNULL(SUM(CASE WHEN entry_hour = 14 THEN hour_count END), 0) AS h14,
                    ISNULL(SUM(CASE WHEN entry_hour = 15 THEN hour_count END), 0) AS h15,
                    ISNULL(SUM(CASE WHEN entry_hour = 16 THEN hour_count END), 0) AS h16,
                    ISNULL(SUM(CASE WHEN entry_hour = 17 THEN hour_count END), 0) AS h17,
                    ISNULL(SUM(CASE WHEN entry_hour = 18 THEN hour_count END), 0) AS h18,
                    ISNULL(SUM(CASE WHEN entry_hour = 19 THEN hour_count END), 0) AS h19,
                    ISNULL(SUM(CASE WHEN entry_hour = 20 THEN hour_count END), 0) AS h20,
                    ISNULL(SUM(CASE WHEN entry_hour = 21 THEN hour_count END), 0) AS h21,
                    ISNULL(SUM(CASE WHEN entry_hour = 22 THEN hour_count END), 0) AS h22,
                    ISNULL(SUM(CASE WHEN entry_hour = 23 THEN hour_count END), 0) AS h23
                FROM HourlyEntries h2
                WHERE h2.artcc = HourlyEntries.artcc
                FOR JSON PATH, WITHOUT_ARRAY_WRAPPER
            ) AS hourly_entries
        FROM HourlyEntries
        GROUP BY artcc
    )
    INSERT INTO dbo.flight_stats_artcc (
        stats_date, artcc,
        entries, exits, transits,
        avg_time_in_artcc,
        hourly_entries,
        peak_hour, peak_hour_entries
    )
    SELECT
        @target_date,
        s.artcc,
        s.entries,
        s.exits,
        s.transits,
        s.avg_time_in_artcc,
        hj.hourly_entries,
        ph.peak_hour,
        ph.peak_hour_entries
    FROM ARTCCStats s
    LEFT JOIN HourlyJson hj ON s.artcc = hj.artcc
    LEFT JOIN PeakHours ph ON s.artcc = ph.artcc AND ph.rn = 1
    WHERE s.artcc IS NOT NULL AND LEN(s.artcc) <= 4;
END;
GO

-- =====================================================
-- 7. sp_CleanupFlightStats
-- Applies tiered retention policy
-- =====================================================

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
        -- Tier 0: Hourly (30 days)
        DELETE FROM dbo.flight_stats_hourly
        WHERE bucket_utc < DATEADD(DAY, -30, GETUTCDATE());
        SET @deleted = @@ROWCOUNT;
        SET @total_deleted = @total_deleted + @deleted;

        -- Tier 1: Daily tables (180 days)
        DELETE FROM dbo.flight_stats_daily WHERE stats_date < DATEADD(DAY, -180, GETUTCDATE());
        SET @total_deleted = @total_deleted + @@ROWCOUNT;

        DELETE FROM dbo.flight_stats_airport WHERE stats_date < DATEADD(DAY, -180, GETUTCDATE());
        SET @total_deleted = @total_deleted + @@ROWCOUNT;

        DELETE FROM dbo.flight_stats_citypair WHERE stats_date < DATEADD(DAY, -180, GETUTCDATE());
        SET @total_deleted = @total_deleted + @@ROWCOUNT;

        DELETE FROM dbo.flight_stats_artcc WHERE stats_date < DATEADD(DAY, -180, GETUTCDATE());
        SET @total_deleted = @total_deleted + @@ROWCOUNT;

        DELETE FROM dbo.flight_stats_tmi WHERE stats_date < DATEADD(DAY, -180, GETUTCDATE());
        SET @total_deleted = @total_deleted + @@ROWCOUNT;

        DELETE FROM dbo.flight_stats_aircraft WHERE stats_date < DATEADD(DAY, -180, GETUTCDATE());
        SET @total_deleted = @total_deleted + @@ROWCOUNT;

        -- Tier 2: Monthly (730 days = 2 years)
        DELETE FROM dbo.flight_stats_monthly_summary WHERE stats_month < DATEADD(DAY, -730, GETUTCDATE());
        SET @total_deleted = @total_deleted + @@ROWCOUNT;

        DELETE FROM dbo.flight_stats_monthly_airport WHERE stats_month < DATEADD(DAY, -730, GETUTCDATE());
        SET @total_deleted = @total_deleted + @@ROWCOUNT;

        -- Clean up old run logs (keep 90 days)
        DELETE FROM dbo.flight_stats_run_log
        WHERE started_utc < DATEADD(DAY, -90, GETUTCDATE())
          AND id <> @run_id;
        SET @total_deleted = @total_deleted + @@ROWCOUNT;

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

-- =====================================================
-- 8. sp_RollupFlightStats_Monthly
-- Aggregates daily stats into monthly summaries
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_RollupFlightStats_Monthly')
    DROP PROCEDURE dbo.sp_RollupFlightStats_Monthly;
GO

CREATE PROCEDURE dbo.sp_RollupFlightStats_Monthly
    @target_month DATE = NULL   -- First day of month, NULL = previous month
AS
BEGIN
    SET NOCOUNT ON;

    IF @target_month IS NULL
        SET @target_month = DATEADD(MONTH, DATEDIFF(MONTH, 0, GETUTCDATE()) - 1, 0);

    DECLARE @month_end DATE = DATEADD(MONTH, 1, @target_month);

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
END;
GO

PRINT '071_flight_stats_procedures.sql completed successfully';
GO
