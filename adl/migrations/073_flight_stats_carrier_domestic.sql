-- =====================================================
-- Flight Statistics: Carrier Stats & Domestic Fix
-- Migration: 073_flight_stats_carrier_domestic.sql
-- Database: VATSIM_ADL (Azure SQL)
-- Purpose: Add carrier statistics and fix US domestic classification
-- =====================================================

SET NOCOUNT ON;
GO

-- =====================================================
-- 1. US DOMESTIC HELPER FUNCTION
-- Includes: CONUS (K), Alaska (PA), Hawaii (PH),
--           Guam (PG), Puerto Rico (TJ), USVI (TI)
-- =====================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.fn_IsUSDomestic') AND type = 'FN')
    DROP FUNCTION dbo.fn_IsUSDomestic;
GO

CREATE FUNCTION dbo.fn_IsUSDomestic(@icao VARCHAR(4))
RETURNS BIT
AS
BEGIN
    -- US airport ICAO prefixes:
    -- K    = CONUS (lower 48)
    -- PA   = Alaska (PAFA, PANC, etc.)
    -- PH   = Hawaii (PHNL, PHOG, etc.)
    -- PG   = Guam (PGUM)
    -- PW   = Palau (PTRO) - US associated
    -- TJ   = Puerto Rico (TJSJ)
    -- TI   = US Virgin Islands (TIST, TISX)

    RETURN CASE
        WHEN LEFT(@icao, 1) = 'K' THEN 1
        WHEN LEFT(@icao, 2) = 'PA' THEN 1
        WHEN LEFT(@icao, 2) = 'PH' THEN 1
        WHEN LEFT(@icao, 2) = 'PG' THEN 1
        WHEN LEFT(@icao, 2) = 'PW' THEN 1
        WHEN LEFT(@icao, 2) = 'TJ' THEN 1
        WHEN LEFT(@icao, 2) = 'TI' THEN 1
        ELSE 0
    END;
END;
GO

PRINT 'Created fn_IsUSDomestic function';
GO

-- =====================================================
-- 2. CARRIER STATISTICS TABLE
-- =====================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'flight_stats_carrier')
CREATE TABLE dbo.flight_stats_carrier (
    id                  BIGINT IDENTITY(1,1) PRIMARY KEY,
    stats_date          DATE NOT NULL,
    carrier_icao        VARCHAR(4) NOT NULL,           -- Airline ICAO code (e.g., AAL, UAL)
    carrier_name        NVARCHAR(64) NULL,             -- Full name from airlines table

    -- Flight Counts
    flight_count        INT NOT NULL DEFAULT 0,
    completed_flights   INT NULL,                      -- All 4 OOOI times
    domestic_flights    INT NULL,                      -- US domestic
    international_flights INT NULL,

    -- OOOI Capture Rates
    pct_complete_oooi   DECIMAL(5,2) NULL,

    -- Time Metrics (minutes)
    avg_block_time_min  DECIMAL(8,2) NULL,
    avg_flight_time_min DECIMAL(8,2) NULL,
    avg_taxi_out_min    DECIMAL(6,2) NULL,
    avg_taxi_in_min     DECIMAL(6,2) NULL,

    -- Performance
    avg_groundspeed_kts INT NULL,
    avg_cruise_altitude INT NULL,

    -- Fleet Mix
    unique_aircraft_types INT NULL,
    top_aircraft_types  NVARCHAR(500) NULL,            -- JSON: [{"type":"B738","count":50},...]

    -- Network
    unique_origins      INT NULL,
    unique_destinations INT NULL,
    top_routes          NVARCHAR(500) NULL,            -- JSON: [{"orig":"KJFK","dest":"KLAX","count":10},...]

    -- TMI Impact
    tmi_affected        INT NULL,
    avg_tmi_delay_min   DECIMAL(6,2) NULL,

    -- Hourly Distribution
    peak_dep_hour       TINYINT NULL,
    peak_arr_hour       TINYINT NULL,

    created_utc         DATETIME2 DEFAULT GETUTCDATE(),
    retention_tier      TINYINT DEFAULT 1,

    CONSTRAINT UQ_stats_carrier UNIQUE (stats_date, carrier_icao),
    INDEX IX_stats_carrier_date (stats_date),
    INDEX IX_stats_carrier_icao (carrier_icao)
);
GO

PRINT 'Created flight_stats_carrier table';
GO

-- =====================================================
-- 3. CARRIER STATISTICS PROCEDURE
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_GenerateFlightStats_Carrier')
    DROP PROCEDURE dbo.sp_GenerateFlightStats_Carrier;
GO

CREATE PROCEDURE dbo.sp_GenerateFlightStats_Carrier
    @target_date DATE
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @day_start DATETIME2 = CAST(@target_date AS DATETIME2);
    DECLARE @day_end DATETIME2 = DATEADD(DAY, 1, @day_start);

    DELETE FROM dbo.flight_stats_carrier WHERE stats_date = @target_date;

    ;WITH CarrierFlights AS (
        SELECT
            p.airline_icao AS carrier_icao,
            c.flight_uid,
            p.fp_dept_icao,
            p.fp_dest_icao,
            p.aircraft_type,
            t.out_utc, t.off_utc, t.on_utc, t.in_utc,
            tmi.ctl_type,
            tmi.delay_minutes,
            CASE WHEN t.out_utc IS NOT NULL AND t.off_utc IS NOT NULL
                 AND t.on_utc IS NOT NULL AND t.in_utc IS NOT NULL THEN 1 ELSE 0 END AS is_complete,
            CASE WHEN dbo.fn_IsUSDomestic(p.fp_dept_icao) = 1
                  AND dbo.fn_IsUSDomestic(p.fp_dest_icao) = 1 THEN 1 ELSE 0 END AS is_domestic,
            DATEDIFF(SECOND, t.out_utc, t.in_utc) / 60.0 AS block_time_min,
            DATEDIFF(SECOND, t.off_utc, t.on_utc) / 60.0 AS flight_time_min,
            DATEDIFF(SECOND, t.out_utc, t.off_utc) / 60.0 AS taxi_out_min,
            DATEDIFF(SECOND, t.on_utc, t.in_utc) / 60.0 AS taxi_in_min,
            DATEPART(HOUR, t.off_utc) AS dep_hour,
            DATEPART(HOUR, t.on_utc) AS arr_hour
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
        LEFT JOIN dbo.adl_flight_times t ON c.flight_uid = t.flight_uid
        LEFT JOIN dbo.adl_flight_tmi tmi ON c.flight_uid = tmi.flight_uid
        WHERE p.airline_icao IS NOT NULL
          AND LEN(p.airline_icao) = 3
          AND ((t.off_utc >= @day_start AND t.off_utc < @day_end)
               OR (t.on_utc >= @day_start AND t.on_utc < @day_end))
    ),
    CarrierStats AS (
        SELECT
            carrier_icao,
            COUNT(DISTINCT flight_uid) AS flight_count,
            SUM(is_complete) AS completed_flights,
            SUM(is_domestic) AS domestic_flights,
            SUM(CASE WHEN is_domestic = 0 THEN 1 ELSE 0 END) AS international_flights,
            CAST(SUM(is_complete) * 100.0 / NULLIF(COUNT(*), 0) AS DECIMAL(5,2)) AS pct_complete_oooi,
            AVG(block_time_min) AS avg_block_time_min,
            AVG(flight_time_min) AS avg_flight_time_min,
            AVG(CASE WHEN taxi_out_min BETWEEN 1 AND 120 THEN taxi_out_min END) AS avg_taxi_out_min,
            AVG(CASE WHEN taxi_in_min BETWEEN 1 AND 60 THEN taxi_in_min END) AS avg_taxi_in_min,
            COUNT(DISTINCT aircraft_type) AS unique_aircraft_types,
            COUNT(DISTINCT fp_dept_icao) AS unique_origins,
            COUNT(DISTINCT fp_dest_icao) AS unique_destinations,
            SUM(CASE WHEN ctl_type IS NOT NULL THEN 1 ELSE 0 END) AS tmi_affected,
            AVG(CAST(delay_minutes AS DECIMAL)) AS avg_tmi_delay_min
        FROM CarrierFlights
        GROUP BY carrier_icao
    ),
    AircraftTypes AS (
        SELECT
            carrier_icao,
            aircraft_type,
            COUNT(*) AS type_count,
            ROW_NUMBER() OVER (PARTITION BY carrier_icao ORDER BY COUNT(*) DESC) AS rn
        FROM CarrierFlights
        WHERE aircraft_type IS NOT NULL
        GROUP BY carrier_icao, aircraft_type
    ),
    TopAircraft AS (
        SELECT
            carrier_icao,
            '[' + STRING_AGG('{"type":"' + aircraft_type + '","count":' + CAST(type_count AS VARCHAR) + '}', ',') + ']' AS top_aircraft_types
        FROM AircraftTypes
        WHERE rn <= 5
        GROUP BY carrier_icao
    ),
    Routes AS (
        SELECT
            carrier_icao,
            fp_dept_icao,
            fp_dest_icao,
            COUNT(*) AS route_count,
            ROW_NUMBER() OVER (PARTITION BY carrier_icao ORDER BY COUNT(*) DESC) AS rn
        FROM CarrierFlights
        WHERE fp_dept_icao IS NOT NULL AND fp_dest_icao IS NOT NULL
        GROUP BY carrier_icao, fp_dept_icao, fp_dest_icao
    ),
    TopRoutes AS (
        SELECT
            carrier_icao,
            '[' + STRING_AGG('{"orig":"' + fp_dept_icao + '","dest":"' + fp_dest_icao + '","count":' + CAST(route_count AS VARCHAR) + '}', ',') + ']' AS top_routes
        FROM Routes
        WHERE rn <= 5
        GROUP BY carrier_icao
    ),
    PeakHours AS (
        SELECT
            carrier_icao,
            dep_hour,
            arr_hour,
            COUNT(*) AS hour_count,
            ROW_NUMBER() OVER (PARTITION BY carrier_icao ORDER BY COUNT(*) DESC) AS dep_rn
        FROM CarrierFlights
        WHERE dep_hour IS NOT NULL
        GROUP BY carrier_icao, dep_hour, arr_hour
    )
    INSERT INTO dbo.flight_stats_carrier (
        stats_date, carrier_icao, carrier_name,
        flight_count, completed_flights, domestic_flights, international_flights,
        pct_complete_oooi,
        avg_block_time_min, avg_flight_time_min, avg_taxi_out_min, avg_taxi_in_min,
        unique_aircraft_types, top_aircraft_types,
        unique_origins, unique_destinations, top_routes,
        tmi_affected, avg_tmi_delay_min,
        peak_dep_hour
    )
    SELECT
        @target_date,
        cs.carrier_icao,
        a.name,
        cs.flight_count,
        cs.completed_flights,
        cs.domestic_flights,
        cs.international_flights,
        cs.pct_complete_oooi,
        cs.avg_block_time_min,
        cs.avg_flight_time_min,
        cs.avg_taxi_out_min,
        cs.avg_taxi_in_min,
        cs.unique_aircraft_types,
        ta.top_aircraft_types,
        cs.unique_origins,
        cs.unique_destinations,
        tr.top_routes,
        cs.tmi_affected,
        cs.avg_tmi_delay_min,
        ph.dep_hour
    FROM CarrierStats cs
    LEFT JOIN dbo.airlines a ON cs.carrier_icao = a.icao
    LEFT JOIN TopAircraft ta ON cs.carrier_icao = ta.carrier_icao
    LEFT JOIN TopRoutes tr ON cs.carrier_icao = tr.carrier_icao
    LEFT JOIN PeakHours ph ON cs.carrier_icao = ph.carrier_icao AND ph.dep_rn = 1
    WHERE cs.flight_count >= 1;
END;
GO

PRINT 'Created sp_GenerateFlightStats_Carrier procedure';
GO

-- =====================================================
-- 4. UPDATE DAILY PROCEDURE TO CALL CARRIER STATS
-- =====================================================

-- Add carrier stats call to daily procedure
-- This is done via ALTER to avoid recreating the entire procedure

DECLARE @sql NVARCHAR(MAX);
SET @sql = (SELECT OBJECT_DEFINITION(OBJECT_ID('dbo.sp_GenerateFlightStats_Daily')));

-- Check if carrier call already exists
IF @sql NOT LIKE '%sp_GenerateFlightStats_Carrier%'
BEGIN
    -- Find the location to insert (after TMI or ARTCC)
    SET @sql = REPLACE(@sql,
        'EXEC dbo.sp_GenerateFlightStats_ARTCC @target_date;',
        'EXEC dbo.sp_GenerateFlightStats_ARTCC @target_date;
        EXEC dbo.sp_GenerateFlightStats_Carrier @target_date;');

    -- Drop and recreate
    DROP PROCEDURE dbo.sp_GenerateFlightStats_Daily;
    EXEC sp_executesql @sql;
    PRINT 'Updated sp_GenerateFlightStats_Daily to include carrier stats';
END
ELSE
BEGIN
    PRINT 'sp_GenerateFlightStats_Daily already includes carrier stats';
END
GO

-- =====================================================
-- 5. UPDATE HOURLY PROCEDURE - FIX DOMESTIC CLASSIFICATION
-- =====================================================

-- Update the hourly procedure to use fn_IsUSDomestic
IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_GenerateFlightStats_Hourly')
    DROP PROCEDURE dbo.sp_GenerateFlightStats_Hourly;
GO

CREATE PROCEDURE dbo.sp_GenerateFlightStats_Hourly
    @hours_back INT = 2
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @start_time DATETIME2 = GETUTCDATE();
    DECLARE @run_id BIGINT;
    DECLARE @records_processed INT = 0;
    DECLARE @records_inserted INT = 0;

    INSERT INTO dbo.flight_stats_run_log (run_type, started_utc, status)
    VALUES ('HOURLY', @start_time, 'RUNNING');
    SET @run_id = SCOPE_IDENTITY();

    BEGIN TRY
        DECLARE @bucket_start DATETIME2 = DATEADD(HOUR, -@hours_back, DATEADD(HOUR, DATEDIFF(HOUR, 0, GETUTCDATE()), 0));
        DECLARE @bucket_end DATETIME2 = DATEADD(HOUR, DATEDIFF(HOUR, 0, GETUTCDATE()), 0);

        DECLARE @current_bucket DATETIME2 = @bucket_start;

        WHILE @current_bucket < @bucket_end
        BEGIN
            DECLARE @next_bucket DATETIME2 = DATEADD(HOUR, 1, @current_bucket);

            DELETE FROM dbo.flight_stats_hourly WHERE bucket_utc = @current_bucket;

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

                -- Departures
                COUNT(DISTINCT CASE WHEN t.off_utc >= @current_bucket AND t.off_utc < @next_bucket THEN c.flight_uid END) AS departures,

                -- Arrivals
                COUNT(DISTINCT CASE WHEN t.on_utc >= @current_bucket AND t.on_utc < @next_bucket THEN c.flight_uid END) AS arrivals,

                -- Enroute
                COUNT(DISTINCT CASE WHEN c.phase IN ('enroute', 'descending')
                    AND t.off_utc < @current_bucket
                    AND (t.on_utc IS NULL OR t.on_utc >= @current_bucket) THEN c.flight_uid END) AS enroute,

                -- Domestic departures (using new function)
                COUNT(DISTINCT CASE WHEN t.off_utc >= @current_bucket AND t.off_utc < @next_bucket
                    AND dbo.fn_IsUSDomestic(p.fp_dept_icao) = 1
                    AND dbo.fn_IsUSDomestic(p.fp_dest_icao) = 1 THEN c.flight_uid END) AS domestic_dep,

                -- Domestic arrivals
                COUNT(DISTINCT CASE WHEN t.on_utc >= @current_bucket AND t.on_utc < @next_bucket
                    AND dbo.fn_IsUSDomestic(p.fp_dept_icao) = 1
                    AND dbo.fn_IsUSDomestic(p.fp_dest_icao) = 1 THEN c.flight_uid END) AS domestic_arr,

                -- International departures
                COUNT(DISTINCT CASE WHEN t.off_utc >= @current_bucket AND t.off_utc < @next_bucket
                    AND (dbo.fn_IsUSDomestic(p.fp_dept_icao) = 0
                         OR dbo.fn_IsUSDomestic(p.fp_dest_icao) = 0) THEN c.flight_uid END) AS intl_dep,

                -- International arrivals
                COUNT(DISTINCT CASE WHEN t.on_utc >= @current_bucket AND t.on_utc < @next_bucket
                    AND (dbo.fn_IsUSDomestic(p.fp_dept_icao) = 0
                         OR dbo.fn_IsUSDomestic(p.fp_dest_icao) = 0) THEN c.flight_uid END) AS intl_arr,

                -- DCC Region arrivals
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

                -- Average taxi times
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

PRINT 'Updated sp_GenerateFlightStats_Hourly with new domestic classification';
GO

-- =====================================================
-- 6. UPDATE DAILY PROCEDURE - FIX DOMESTIC CLASSIFICATION
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_GenerateFlightStats_Daily')
    DROP PROCEDURE dbo.sp_GenerateFlightStats_Daily;
GO

CREATE PROCEDURE dbo.sp_GenerateFlightStats_Daily
    @target_date DATE = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @start_time DATETIME2 = GETUTCDATE();
    DECLARE @run_id BIGINT;
    DECLARE @records_processed INT = 0;

    IF @target_date IS NULL
        SET @target_date = DATEADD(DAY, -1, CAST(GETUTCDATE() AS DATE));

    DECLARE @day_start DATETIME2 = CAST(@target_date AS DATETIME2);
    DECLARE @day_end DATETIME2 = DATEADD(DAY, 1, @day_start);

    INSERT INTO dbo.flight_stats_run_log (run_type, started_utc, status)
    VALUES ('DAILY', @start_time, 'RUNNING');
    SET @run_id = SCOPE_IDENTITY();

    BEGIN TRY
        DELETE FROM dbo.flight_stats_daily WHERE stats_date = @target_date;

        -- Calculate peak hour
        DECLARE @peak_hour TINYINT, @peak_flights INT;

        SELECT TOP 1 @peak_hour = DATEPART(HOUR, t.off_utc), @peak_flights = COUNT(*)
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_times t ON c.flight_uid = t.flight_uid
        WHERE t.off_utc >= @day_start AND t.off_utc < @day_end
        GROUP BY DATEPART(HOUR, t.off_utc)
        ORDER BY COUNT(*) DESC;

        -- Insert daily summary with corrected domestic classification
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

            -- Flight counts using new domestic function
            COUNT(DISTINCT c.flight_uid) AS total_flights,
            COUNT(DISTINCT CASE WHEN t.out_utc IS NOT NULL AND t.off_utc IS NOT NULL
                AND t.on_utc IS NOT NULL AND t.in_utc IS NOT NULL THEN c.flight_uid END) AS completed_flights,
            COUNT(DISTINCT CASE WHEN dbo.fn_IsUSDomestic(p.fp_dept_icao) = 1
                AND dbo.fn_IsUSDomestic(p.fp_dest_icao) = 1 THEN c.flight_uid END) AS domestic_flights,
            COUNT(DISTINCT CASE WHEN dbo.fn_IsUSDomestic(p.fp_dept_icao) = 0
                OR dbo.fn_IsUSDomestic(p.fp_dest_icao) = 0 THEN c.flight_uid END) AS international_flights,

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

        -- Call sub-procedures
        EXEC dbo.sp_GenerateFlightStats_Airport @target_date;
        EXEC dbo.sp_GenerateFlightStats_Citypair @target_date;
        EXEC dbo.sp_GenerateFlightStats_Aircraft @target_date;
        EXEC dbo.sp_GenerateFlightStats_TMI @target_date;
        EXEC dbo.sp_GenerateFlightStats_ARTCC @target_date;
        EXEC dbo.sp_GenerateFlightStats_Carrier @target_date;

        -- Run cleanup
        EXEC dbo.sp_CleanupFlightStats;

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

PRINT 'Updated sp_GenerateFlightStats_Daily with new domestic classification and carrier stats';
GO

-- =====================================================
-- 7. ADD CARRIER TO CLEANUP PROCEDURE
-- =====================================================

-- Update cleanup to include carrier table
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

        DELETE FROM dbo.flight_stats_carrier WHERE stats_date < DATEADD(DAY, -180, GETUTCDATE());
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

PRINT 'Updated sp_CleanupFlightStats to include carrier table';
GO

-- =====================================================
-- 8. ADD CARRIER TO JOB CONFIG
-- =====================================================

IF NOT EXISTS (SELECT 1 FROM dbo.flight_stats_job_config WHERE job_name = 'FlightStats_Carrier')
BEGIN
    INSERT INTO dbo.flight_stats_job_config
    (job_name, procedure_name, schedule_type, schedule_cron, schedule_utc_hour, schedule_utc_minute, description, is_enabled)
    VALUES
    ('FlightStats_Carrier', 'sp_GenerateFlightStats_Carrier', 'DAILY', '20 0 * * *', 0, 20,
     'Runs daily at 00:20 UTC to aggregate carrier statistics', 1);
    PRINT 'Added FlightStats_Carrier job configuration';
END
GO

PRINT '';
PRINT '073_flight_stats_carrier_domestic.sql completed successfully';
PRINT '';
PRINT 'Changes:';
PRINT '  1. Created fn_IsUSDomestic() - includes K, PA, PH, PG, PW, TJ, TI prefixes';
PRINT '  2. Created flight_stats_carrier table';
PRINT '  3. Created sp_GenerateFlightStats_Carrier procedure';
PRINT '  4. Updated sp_GenerateFlightStats_Hourly with new domestic classification';
PRINT '  5. Updated sp_GenerateFlightStats_Daily with new domestic classification + carrier call';
PRINT '  6. Updated sp_CleanupFlightStats to include carrier table';
PRINT '';
PRINT 'Run carrier stats for today: EXEC dbo.sp_GenerateFlightStats_Carrier ''2026-01-07''';
GO
