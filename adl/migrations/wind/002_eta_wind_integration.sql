-- ============================================================================
-- ETA Wind Integration Migration
--
-- Adds wind adjustment columns to flight times and creates batch wind
-- calculation procedure for efficient ETA enhancement.
--
-- Part of ETA Accuracy Improvement Initiative
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ETA Wind Integration Migration ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. Add wind columns to adl_flight_times if not exists
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'eta_wind_adj_kts')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD
        eta_wind_adj_kts        DECIMAL(6,2) NULL,    -- Calculated wind adjustment (+ = tailwind)
        eta_wind_confidence     DECIMAL(3,2) NULL;    -- Wind data confidence (0-1)

    PRINT 'Added wind adjustment columns to adl_flight_times';
END
ELSE
BEGIN
    PRINT 'Wind columns already exist in adl_flight_times - skipping';
END
GO

-- ============================================================================
-- 2. Create batch wind calculation procedure
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.sp_CalculateWindBatch') AND type = 'P')
    DROP PROCEDURE dbo.sp_CalculateWindBatch;
GO

CREATE PROCEDURE dbo.sp_CalculateWindBatch
    @processed_count INT = 0 OUTPUT,
    @debug BIT = 0
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @now DATETIME2(0) = SYSUTCDATETIME();
    SET @processed_count = 0;

    -- Check if wind data is available
    DECLARE @wind_count INT;
    SELECT @wind_count = COUNT(*)
    FROM dbo.wind_grid
    WHERE valid_time_utc >= DATEADD(HOUR, -6, @now)
      AND valid_time_utc <= DATEADD(HOUR, 6, @now);

    IF @wind_count = 0
    BEGIN
        IF @debug = 1 PRINT 'No wind data available - skipping wind calculations';
        RETURN;
    END

    IF @debug = 1
        PRINT 'Wind grid points available: ' + CAST(@wind_count AS VARCHAR);

    -- Get current valid time for wind data
    DECLARE @valid_time DATETIME2(0);
    SELECT TOP 1 @valid_time = valid_time_utc
    FROM dbo.wind_grid
    WHERE valid_time_utc >= DATEADD(HOUR, -3, @now)
      AND valid_time_utc <= DATEADD(HOUR, 3, @now)
    ORDER BY ABS(DATEDIFF(MINUTE, valid_time_utc, @now));

    IF @debug = 1
        PRINT 'Using wind valid time: ' + CONVERT(VARCHAR, @valid_time, 120);

    -- Create work table with flight positions and tracks
    CREATE TABLE #wind_work (
        flight_uid      BIGINT PRIMARY KEY,
        curr_lat        DECIMAL(10,7),
        curr_lon        DECIMAL(11,7),
        dest_lat        DECIMAL(10,7),
        dest_lon        DECIMAL(11,7),
        altitude_ft     INT,
        track_deg       INT,
        pressure_hpa    INT
    );

    -- Populate work table with active flights that have positions and destinations
    INSERT INTO #wind_work (flight_uid, curr_lat, curr_lon, dest_lat, dest_lon, altitude_ft)
    SELECT
        c.flight_uid,
        COALESCE(p.lat, dept.LAT_DECIMAL) AS curr_lat,
        COALESCE(p.lon, dept.LONG_DECIMAL) AS curr_lon,
        dest.LAT_DECIMAL AS dest_lat,
        dest.LONG_DECIMAL AS dest_lon,
        COALESCE(p.altitude_ft, fp.fp_altitude_ft, 35000) AS altitude_ft
    FROM dbo.adl_flight_core c
    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    LEFT JOIN dbo.apts dest ON dest.ICAO_ID = fp.fp_dest_icao
    LEFT JOIN dbo.apts dept ON dept.ICAO_ID = fp.fp_dept_icao
    WHERE c.is_active = 1
      AND c.phase NOT IN ('arrived', 'on', 'in')
      AND dest.LAT_DECIMAL IS NOT NULL
      AND (p.lat IS NOT NULL OR dept.LAT_DECIMAL IS NOT NULL);

    IF @debug = 1
    BEGIN
        DECLARE @work_count INT;
        SELECT @work_count = COUNT(*) FROM #wind_work;
        PRINT 'Flights in wind work table: ' + CAST(@work_count AS VARCHAR);
    END

    -- Calculate track bearing for each flight
    UPDATE #wind_work
    SET track_deg = (CAST(DEGREES(ATN2(
            SIN(RADIANS(dest_lon - curr_lon)) * COS(RADIANS(dest_lat)),
            COS(RADIANS(curr_lat)) * SIN(RADIANS(dest_lat)) -
            SIN(RADIANS(curr_lat)) * COS(RADIANS(dest_lat)) * COS(RADIANS(dest_lon - curr_lon))
        )) AS INT) + 360) % 360,
        pressure_hpa = CASE
            WHEN altitude_ft >= 38000 THEN 200
            WHEN altitude_ft >= 32000 THEN 250
            WHEN altitude_ft >= 26000 THEN 300
            ELSE 300
        END;

    -- Get wind at flight positions using grid lookup
    -- Snap to nearest grid point for efficiency
    CREATE TABLE #wind_results (
        flight_uid      BIGINT PRIMARY KEY,
        wind_u          DECIMAL(6,2),
        wind_v          DECIMAL(6,2),
        wind_speed      DECIMAL(5,1),
        wind_dir        SMALLINT
    );

    INSERT INTO #wind_results (flight_uid, wind_u, wind_v, wind_speed, wind_dir)
    SELECT
        w.flight_uid,
        g.wind_u_kts,
        g.wind_v_kts,
        g.wind_speed_kts,
        g.wind_dir_deg
    FROM #wind_work w
    CROSS APPLY (
        SELECT TOP 1 wind_u_kts, wind_v_kts, wind_speed_kts, wind_dir_deg
        FROM dbo.wind_grid
        WHERE pressure_hpa = w.pressure_hpa
          AND valid_time_utc = @valid_time
          AND ABS(lat - w.curr_lat) <= 5
          AND ABS(lon - w.curr_lon) <= 5
        ORDER BY ABS(lat - w.curr_lat) + ABS(lon - w.curr_lon)
    ) g;

    IF @debug = 1
    BEGIN
        DECLARE @result_count INT;
        SELECT @result_count = COUNT(*) FROM #wind_results;
        PRINT 'Flights with wind data: ' + CAST(@result_count AS VARCHAR);
    END

    -- Calculate wind component along track and update times table
    UPDATE ft
    SET ft.eta_wind_adj_kts = CAST(
            r.wind_speed * COS(RADIANS((r.wind_dir - w.track_deg + 180 + 360) % 360))
        AS DECIMAL(6,2)),
        ft.eta_wind_confidence = 1.0
    FROM dbo.adl_flight_times ft
    INNER JOIN #wind_work w ON w.flight_uid = ft.flight_uid
    INNER JOIN #wind_results r ON r.flight_uid = ft.flight_uid;

    SET @processed_count = @@ROWCOUNT;

    IF @debug = 1
    BEGIN
        PRINT 'Flights updated with wind adjustment: ' + CAST(@processed_count AS VARCHAR);

        -- Show sample results
        SELECT TOP 10
            c.callsign,
            w.track_deg,
            r.wind_speed AS wind_spd,
            r.wind_dir AS wind_dir,
            ft.eta_wind_adj_kts AS wind_adj
        FROM #wind_work w
        JOIN dbo.adl_flight_core c ON c.flight_uid = w.flight_uid
        JOIN #wind_results r ON r.flight_uid = w.flight_uid
        JOIN dbo.adl_flight_times ft ON ft.flight_uid = w.flight_uid
        WHERE ft.eta_wind_adj_kts IS NOT NULL
        ORDER BY ABS(ft.eta_wind_adj_kts) DESC;
    END

    -- Cleanup
    DROP TABLE IF EXISTS #wind_work;
    DROP TABLE IF EXISTS #wind_results;
END
GO

PRINT 'Created procedure dbo.sp_CalculateWindBatch';
GO

-- ============================================================================
-- 3. Create function to apply wind to ETA calculation
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.fn_WindAdjustedETE') AND type = 'FN')
    DROP FUNCTION dbo.fn_WindAdjustedETE;
GO

CREATE FUNCTION dbo.fn_WindAdjustedETE(
    @distance_nm        DECIMAL(10,2),
    @tas_kts            INT,
    @wind_component_kts DECIMAL(6,2)
)
RETURNS INT
AS
BEGIN
    -- Calculate effective groundspeed
    DECLARE @gs_kts DECIMAL(10,2);
    SET @gs_kts = @tas_kts + ISNULL(@wind_component_kts, 0);

    -- Prevent division by zero and unrealistic speeds
    IF @gs_kts < 100
        SET @gs_kts = 100;

    -- Return time in minutes
    RETURN CAST(@distance_nm / @gs_kts * 60 AS INT);
END
GO

PRINT 'Created function dbo.fn_WindAdjustedETE';
GO

PRINT '';
PRINT '=== ETA Wind Integration Complete ===';
PRINT '';
PRINT 'Next steps:';
PRINT '  1. Deploy wind grid schema (001_wind_grid_schema.sql)';
PRINT '  2. Run wind fetch service to populate data';
PRINT '  3. Call sp_CalculateWindBatch after ETA batch to add wind adjustments';
PRINT '  4. Update sp_CalculateETABatch to use wind-adjusted calculations';
PRINT '';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
