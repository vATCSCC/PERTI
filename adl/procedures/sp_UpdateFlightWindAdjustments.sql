-- ============================================================================
-- sp_UpdateFlightWindAdjustments.sql
-- Tiered wind calculation procedure - runs independently from ETA batch
--
-- Features:
-- - Tiered calculation frequency (Tier 0-7, like trajectory system)
-- - Grid-based wind with GS-based fallback
-- - Confidence scoring based on data source and freshness
-- - Only updates flights due for recalculation
-- - Designed to run every 30 seconds via daemon
--
-- Wind Tiers:
-- 0: Critical (<50nm, descending) - every 30s
-- 1: High Priority (<100nm or climbing/descending) - every 60s
-- 2: Active Enroute (relevant, cruise) - every 2 min
-- 3: Stable Cruise (>200nm, level) - every 5 min
-- 4: Low Priority (oceanic, far) - every 10 min
-- 7: Skip (irrelevant, arrived, prefile)
--
-- Part of the Tiered Wind Calculation System
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

-- Add wind_last_calc_utc column if not exists
IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times')
    AND name = 'wind_last_calc_utc'
)
BEGIN
    ALTER TABLE dbo.adl_flight_times
    ADD wind_last_calc_utc DATETIME2(0) NULL;

    PRINT 'Added wind_last_calc_utc column to adl_flight_times';
END
GO

IF OBJECT_ID('dbo.sp_UpdateFlightWindAdjustments', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_UpdateFlightWindAdjustments;
GO

CREATE PROCEDURE dbo.sp_UpdateFlightWindAdjustments
    @updated_count INT = NULL OUTPUT,
    @debug BIT = 0
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @start_time DATETIME2(3) = SYSDATETIME();
    SET @updated_count = 0;

    -- ========================================================================
    -- Step 1: Check for wind grid data availability
    -- ========================================================================
    DECLARE @grid_available BIT = 0;
    DECLARE @grid_valid_time DATETIME2(0);
    DECLARE @grid_age_hours INT;

    SELECT TOP 1
        @grid_valid_time = valid_time_utc,
        @grid_age_hours = DATEDIFF(HOUR, valid_time_utc, @now)
    FROM dbo.wind_grid
    WHERE valid_time_utc >= DATEADD(HOUR, -12, @now)
      AND valid_time_utc <= DATEADD(HOUR, 6, @now)
    ORDER BY ABS(DATEDIFF(MINUTE, valid_time_utc, @now));

    IF @grid_valid_time IS NOT NULL
        SET @grid_available = 1;

    IF @debug = 1
    BEGIN
        PRINT '=== Wind Adjustment Calculation ===';
        PRINT 'Grid available: ' + CASE WHEN @grid_available = 1 THEN 'YES' ELSE 'NO' END;
        IF @grid_available = 1
            PRINT 'Grid valid time: ' + CONVERT(VARCHAR, @grid_valid_time, 120) + ' (age: ' + CAST(@grid_age_hours AS VARCHAR) + 'h)';
    END

    -- ========================================================================
    -- Step 2: Build work table with tier assignments
    -- ========================================================================
    DROP TABLE IF EXISTS #wind_work;

    SELECT
        c.flight_uid,
        c.phase,
        p.lat AS curr_lat,
        p.lon AS curr_lon,
        p.altitude_ft,
        p.groundspeed_kts,
        ISNULL(p.vertical_rate_fpm, 0) AS vertical_rate_fpm,
        p.dist_to_dest_nm,
        fp.fp_dept_icao,
        fp.fp_dest_icao,
        dest.LAT_DECIMAL AS dest_lat,
        dest.LONG_DECIMAL AS dest_lon,
        ft.wind_last_calc_utc,
        ft.eta_wind_adj_kts AS current_wind_adj,
        perf.cruise_speed_ktas,

        -- Calculate wind tier
        CASE
            -- Skip: Arrived, on ground at destination
            WHEN c.phase IN ('arrived', 'on', 'in') THEN 7
            -- Skip: Prefile (no position data)
            WHEN c.phase = 'prefile' THEN 7
            -- Skip: Not relevant to US/CA/Americas
            WHEN dbo.fn_IsFlightRelevant(fp.fp_dept_icao, fp.fp_dest_icao, p.lat, p.lon) = 0 THEN 7
            -- Tier 0: Critical - very close to destination and descending
            WHEN p.dist_to_dest_nm < 50 AND c.phase = 'descending' THEN 0
            -- Tier 1: High priority - within 100nm or actively climbing/descending
            WHEN p.dist_to_dest_nm < 100 THEN 1
            WHEN c.phase IN ('climbing', 'descending', 'departed') THEN 1
            -- Tier 2: Active enroute - within 200nm
            WHEN p.dist_to_dest_nm < 200 THEN 2
            -- Tier 3: Stable cruise - level flight at altitude
            WHEN ABS(ISNULL(p.vertical_rate_fpm, 0)) < 200 AND p.altitude_ft > 25000 THEN 3
            -- Tier 4: Low priority - everything else (oceanic, far from dest)
            ELSE 4
        END AS wind_tier

    INTO #wind_work
    FROM dbo.adl_flight_core c
    INNER JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
    LEFT JOIN dbo.apts dest ON dest.ICAO_ID = fp.fp_dest_icao
    LEFT JOIN dbo.aircraft_performance_profiles perf ON perf.aircraft_icao =
        (SELECT ac.aircraft_icao FROM dbo.adl_flight_aircraft ac WHERE ac.flight_uid = c.flight_uid)
    WHERE c.is_active = 1
      AND p.lat IS NOT NULL;

    IF @debug = 1
    BEGIN
        DECLARE @total_flights INT;
        SELECT @total_flights = COUNT(*) FROM #wind_work;
        PRINT 'Total active flights with position: ' + CAST(@total_flights AS VARCHAR);

        SELECT wind_tier, COUNT(*) AS flight_count
        FROM #wind_work
        GROUP BY wind_tier
        ORDER BY wind_tier;
    END

    -- ========================================================================
    -- Step 3: Filter to flights due for wind calculation
    -- ========================================================================
    DROP TABLE IF EXISTS #due_for_calc;

    SELECT w.*
    INTO #due_for_calc
    FROM #wind_work w
    WHERE w.wind_tier < 7  -- Skip tier 7 (irrelevant)
      AND (
          w.wind_last_calc_utc IS NULL  -- Never calculated
          OR DATEDIFF(SECOND, w.wind_last_calc_utc, @now) >= dbo.fn_GetWindTierInterval(w.wind_tier)
      );

    DECLARE @due_count INT;
    SELECT @due_count = COUNT(*) FROM #due_for_calc;

    IF @debug = 1
        PRINT 'Flights due for wind calculation: ' + CAST(@due_count AS VARCHAR);

    IF @due_count = 0
    BEGIN
        IF @debug = 1 PRINT 'No flights due for wind calculation - exiting';
        RETURN;
    END

    -- ========================================================================
    -- Step 4: Calculate track bearing to destination
    -- ========================================================================
    ALTER TABLE #due_for_calc ADD
        track_deg INT NULL,
        pressure_hpa INT NULL;

    UPDATE #due_for_calc
    SET track_deg = (CAST(DEGREES(ATN2(
            SIN(RADIANS(dest_lon - curr_lon)) * COS(RADIANS(dest_lat)),
            COS(RADIANS(curr_lat)) * SIN(RADIANS(dest_lat)) -
            SIN(RADIANS(curr_lat)) * COS(RADIANS(dest_lat)) * COS(RADIANS(dest_lon - curr_lon))
        )) AS INT) + 360) % 360,
        pressure_hpa = CASE
            WHEN altitude_ft >= 38000 THEN 200
            WHEN altitude_ft >= 32000 THEN 250
            WHEN altitude_ft >= 26000 THEN 300
            WHEN altitude_ft >= 18000 THEN 400
            ELSE 500
        END
    WHERE dest_lat IS NOT NULL AND dest_lon IS NOT NULL;

    -- ========================================================================
    -- Step 5: Try grid-based wind calculation
    -- ========================================================================
    DROP TABLE IF EXISTS #wind_results;

    CREATE TABLE #wind_results (
        flight_uid      BIGINT PRIMARY KEY,
        wind_adj_kts    DECIMAL(6,2),
        confidence      DECIMAL(3,2),
        source          VARCHAR(16)
    );

    IF @grid_available = 1
    BEGIN
        -- Get wind from grid for each flight
        INSERT INTO #wind_results (flight_uid, wind_adj_kts, confidence, source)
        SELECT
            d.flight_uid,
            -- Calculate wind component along track (positive = tailwind)
            CAST(g.wind_speed_kts * COS(RADIANS((g.wind_dir_deg - d.track_deg + 180 + 360) % 360)) AS DECIMAL(6,2)),
            -- Confidence based on data freshness
            CASE
                WHEN @grid_age_hours <= 3 THEN 0.90
                WHEN @grid_age_hours <= 6 THEN 0.80
                WHEN @grid_age_hours <= 12 THEN 0.70
                ELSE 0.50
            END,
            'GRID'
        FROM #due_for_calc d
        CROSS APPLY (
            SELECT TOP 1 wind_speed_kts, wind_dir_deg
            FROM dbo.wind_grid
            WHERE pressure_hpa = d.pressure_hpa
              AND valid_time_utc = @grid_valid_time
              AND ABS(lat - d.curr_lat) <= 2.5
              AND ABS(lon - d.curr_lon) <= 2.5
            ORDER BY ABS(lat - d.curr_lat) + ABS(lon - d.curr_lon)
        ) g
        WHERE d.track_deg IS NOT NULL;

        IF @debug = 1
        BEGIN
            DECLARE @grid_calc_count INT;
            SELECT @grid_calc_count = COUNT(*) FROM #wind_results;
            PRINT 'Flights with grid-based wind: ' + CAST(@grid_calc_count AS VARCHAR);
        END
    END

    -- ========================================================================
    -- Step 6: GS-based fallback for flights without grid wind
    -- ========================================================================
    INSERT INTO #wind_results (flight_uid, wind_adj_kts, confidence, source)
    SELECT
        d.flight_uid,
        -- Wind estimate: groundspeed - expected TAS (clamped to +/- 100)
        CASE
            WHEN d.groundspeed_kts - ISNULL(d.cruise_speed_ktas, 450) > 100 THEN 100
            WHEN d.groundspeed_kts - ISNULL(d.cruise_speed_ktas, 450) < -100 THEN -100
            ELSE d.groundspeed_kts - ISNULL(d.cruise_speed_ktas, 450)
        END,
        -- Lower confidence for GS-based estimate
        CASE
            WHEN d.phase IN ('enroute', 'cruise') AND d.altitude_ft > 25000 THEN 0.60
            WHEN d.phase IN ('enroute', 'cruise') THEN 0.50
            ELSE 0.40
        END,
        'GS_BASED'
    FROM #due_for_calc d
    WHERE NOT EXISTS (SELECT 1 FROM #wind_results r WHERE r.flight_uid = d.flight_uid)
      AND d.groundspeed_kts > 100  -- Need reasonable groundspeed
      AND d.phase IN ('enroute', 'cruise', 'climbing', 'descending');

    IF @debug = 1
    BEGIN
        DECLARE @gs_calc_count INT;
        SELECT @gs_calc_count = COUNT(*) FROM #wind_results WHERE source = 'GS_BASED';
        PRINT 'Flights with GS-based wind: ' + CAST(@gs_calc_count AS VARCHAR);
    END

    -- ========================================================================
    -- Step 7: Update adl_flight_times with wind adjustments
    -- ========================================================================
    UPDATE ft
    SET ft.eta_wind_adj_kts = r.wind_adj_kts,
        ft.eta_wind_confidence = r.confidence,
        ft.wind_last_calc_utc = @now
    FROM dbo.adl_flight_times ft
    INNER JOIN #wind_results r ON r.flight_uid = ft.flight_uid;

    SET @updated_count = @@ROWCOUNT;

    -- Insert for flights without times record
    INSERT INTO dbo.adl_flight_times (flight_uid, eta_wind_adj_kts, eta_wind_confidence, wind_last_calc_utc)
    SELECT r.flight_uid, r.wind_adj_kts, r.confidence, @now
    FROM #wind_results r
    WHERE NOT EXISTS (
        SELECT 1 FROM dbo.adl_flight_times ft WHERE ft.flight_uid = r.flight_uid
    );

    SET @updated_count = @updated_count + @@ROWCOUNT;

    IF @debug = 1
    BEGIN
        PRINT '';
        PRINT 'Flights updated with wind adjustment: ' + CAST(@updated_count AS VARCHAR);
        PRINT 'Total duration: ' + CAST(DATEDIFF(MILLISECOND, @start_time, SYSDATETIME()) AS VARCHAR) + 'ms';

        -- Show sample results
        PRINT '';
        PRINT 'Sample wind adjustments:';
        SELECT TOP 10
            c.callsign,
            d.phase,
            d.wind_tier AS tier,
            d.dist_to_dest_nm AS dist_nm,
            d.altitude_ft AS alt_ft,
            d.groundspeed_kts AS gs,
            r.wind_adj_kts AS wind_adj,
            r.confidence AS conf,
            r.source
        FROM #due_for_calc d
        JOIN dbo.adl_flight_core c ON c.flight_uid = d.flight_uid
        JOIN #wind_results r ON r.flight_uid = d.flight_uid
        ORDER BY ABS(r.wind_adj_kts) DESC;
    END

    -- Cleanup
    DROP TABLE IF EXISTS #wind_work;
    DROP TABLE IF EXISTS #due_for_calc;
    DROP TABLE IF EXISTS #wind_results;
END
GO

PRINT 'Created procedure dbo.sp_UpdateFlightWindAdjustments';
PRINT '';
PRINT 'Usage:';
PRINT '  EXEC dbo.sp_UpdateFlightWindAdjustments @debug = 1;';
PRINT '';
PRINT 'This procedure should be called every 30 seconds by the daemon.';
PRINT 'It will automatically tier flights and only calculate wind for those due.';
GO
