-- ============================================================================
-- sp_UpdateFlightWindAdjustments V2 - Segment-Based Wind Calculation
--
-- IMPROVEMENTS FROM V1:
-- 1. Segment-based wind: separate adjustments for climb/cruise/descent
-- 2. Wind at cruise altitude: For climbing flights, look ahead to cruise winds
-- 3. Weighted wind adjustment: Combines segment winds based on remaining distance
-- 4. Altitude-interpolated wind: Better pressure level selection
--
-- Output columns in adl_flight_times:
-- - eta_wind_adj_kts: Weighted average wind adjustment for remaining flight
-- - eta_wind_climb_kts: Wind component during climb segment
-- - eta_wind_cruise_kts: Wind component during cruise segment
-- - eta_wind_descent_kts: Wind component during descent segment
-- - eta_wind_confidence: Confidence score (0-1)
--
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

-- Add segment wind columns if not exist
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'eta_wind_climb_kts')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD
        eta_wind_climb_kts DECIMAL(6,2) NULL,
        eta_wind_cruise_kts DECIMAL(6,2) NULL,
        eta_wind_descent_kts DECIMAL(6,2) NULL;
    PRINT 'Added segment wind columns to adl_flight_times';
END
GO

IF OBJECT_ID('dbo.sp_UpdateFlightWindAdjustments_V2', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_UpdateFlightWindAdjustments_V2;
GO

CREATE PROCEDURE dbo.sp_UpdateFlightWindAdjustments_V2
    @updated_count INT = NULL OUTPUT,
    @debug BIT = 0
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @start_time DATETIME2(3) = SYSDATETIME();
    SET @updated_count = 0;

    -- ========================================================================
    -- Step 1: Check grid data availability
    -- ========================================================================
    DECLARE @grid_available BIT = 0;
    DECLARE @grid_valid_time DATETIME2(0);
    DECLARE @grid_age_hours INT;
    DECLARE @base_confidence DECIMAL(3,2);

    SELECT TOP 1
        @grid_valid_time = valid_time_utc,
        @grid_age_hours = DATEDIFF(HOUR, valid_time_utc, @now)
    FROM dbo.wind_grid
    WHERE valid_time_utc >= DATEADD(HOUR, -12, @now)
      AND valid_time_utc <= DATEADD(HOUR, 6, @now)
    ORDER BY ABS(DATEDIFF(MINUTE, valid_time_utc, @now));

    IF @grid_valid_time IS NOT NULL
    BEGIN
        SET @grid_available = 1;
        SET @base_confidence = CASE
            WHEN @grid_age_hours <= 3 THEN 0.95
            WHEN @grid_age_hours <= 6 THEN 0.85
            WHEN @grid_age_hours <= 12 THEN 0.70
            ELSE 0.50
        END;
    END

    IF @debug = 1
    BEGIN
        PRINT '=== Wind Adjustment Calculation V2 (Segment-Based) ===';
        PRINT 'Grid available: ' + CASE WHEN @grid_available = 1 THEN 'YES' ELSE 'NO' END;
        IF @grid_available = 1
            PRINT 'Grid time: ' + CONVERT(VARCHAR, @grid_valid_time, 120) + ' (age: ' + CAST(@grid_age_hours AS VARCHAR) + 'h, conf: ' + CAST(@base_confidence AS VARCHAR) + ')';
    END

    -- ========================================================================
    -- Step 2: Build work table with flight data and tier
    -- ========================================================================
    DROP TABLE IF EXISTS #wind_work;

    SELECT
        c.flight_uid,
        c.phase,
        p.lat AS curr_lat,
        p.lon AS curr_lon,
        p.altitude_ft AS curr_alt,
        p.groundspeed_kts,
        ISNULL(p.vertical_rate_fpm, 0) AS vertical_rate_fpm,
        p.dist_to_dest_nm,
        fp.fp_dept_icao,
        fp.fp_dest_icao,
        fp.fp_altitude_ft AS filed_alt,
        dest.LAT_DECIMAL AS dest_lat,
        dest.LONG_DECIMAL AS dest_lon,
        ISNULL(dest.ELEV, 0) AS dest_elev,
        ft.wind_last_calc_utc,
        ft.eta_wind_adj_kts AS current_wind_adj,
        ISNULL(perf.climb_speed_kias, 280) AS climb_speed,
        ISNULL(perf.cruise_speed_ktas, 450) AS cruise_speed,
        ISNULL(perf.descent_speed_kias, 280) AS descent_speed,

        -- Calculate wind tier
        CASE
            WHEN c.phase IN ('arrived', 'on', 'in') THEN 7
            WHEN c.phase = 'prefile' THEN 7
            WHEN dbo.fn_IsFlightRelevant(fp.fp_dept_icao, fp.fp_dest_icao, p.lat, p.lon) = 0 THEN 7
            WHEN p.dist_to_dest_nm < 50 AND c.phase = 'descending' THEN 0
            WHEN p.dist_to_dest_nm < 100 THEN 1
            WHEN c.phase IN ('climbing', 'descending', 'departed') THEN 1
            WHEN p.dist_to_dest_nm < 200 THEN 2
            WHEN ABS(ISNULL(p.vertical_rate_fpm, 0)) < 200 AND p.altitude_ft > 25000 THEN 3
            ELSE 4
        END AS wind_tier,

        -- Pre-calculate distances for each segment
        CASE WHEN c.phase IN ('departed', 'climbing') AND p.altitude_ft < ISNULL(fp.fp_altitude_ft, 35000)
             THEN (ISNULL(fp.fp_altitude_ft, 35000) - p.altitude_ft) / 1000.0 * 2.0  -- ~2nm per 1000ft climb
             ELSE 0 END AS remaining_climb_nm,

        -- Standard TOD: 3nm per 1000ft descent
        (ISNULL(fp.fp_altitude_ft, 35000) - ISNULL(dest.ELEV, 0)) / 1000.0 * 3.0 AS descent_dist_nm

    INTO #wind_work
    FROM dbo.adl_flight_core c
    INNER JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
    LEFT JOIN dbo.apts dest ON dest.ICAO_ID = fp.fp_dest_icao
    LEFT JOIN dbo.aircraft_performance_profiles perf ON perf.aircraft_icao =
        (SELECT TOP 1 ac.aircraft_icao FROM dbo.adl_flight_aircraft ac WHERE ac.flight_uid = c.flight_uid)
    WHERE c.is_active = 1
      AND p.lat IS NOT NULL;

    -- Filter to flights due for calculation
    DROP TABLE IF EXISTS #due_for_calc;

    SELECT w.*,
        -- Calculate cruise distance (remaining - climb - descent)
        GREATEST(0, w.dist_to_dest_nm - w.remaining_climb_nm - w.descent_dist_nm) AS cruise_dist_nm
    INTO #due_for_calc
    FROM #wind_work w
    WHERE w.wind_tier < 7
      AND (w.wind_last_calc_utc IS NULL
           OR DATEDIFF(SECOND, w.wind_last_calc_utc, @now) >= dbo.fn_GetWindTierInterval(w.wind_tier));

    DECLARE @due_count INT;
    SELECT @due_count = COUNT(*) FROM #due_for_calc;

    IF @debug = 1
        PRINT 'Flights due for calculation: ' + CAST(@due_count AS VARCHAR);

    IF @due_count = 0 RETURN;

    -- ========================================================================
    -- Step 3: Calculate track bearing and pressure levels
    -- ========================================================================
    ALTER TABLE #due_for_calc ADD
        track_deg INT NULL,
        pressure_current INT NULL,
        pressure_cruise INT NULL,
        pressure_descent INT NULL;

    UPDATE #due_for_calc
    SET
        -- Track bearing to destination
        track_deg = (CAST(DEGREES(ATN2(
            SIN(RADIANS(dest_lon - curr_lon)) * COS(RADIANS(dest_lat)),
            COS(RADIANS(curr_lat)) * SIN(RADIANS(dest_lat)) -
            SIN(RADIANS(curr_lat)) * COS(RADIANS(dest_lat)) * COS(RADIANS(dest_lon - curr_lon))
        )) AS INT) + 360) % 360,

        -- Pressure at current altitude
        pressure_current = CASE
            WHEN curr_alt >= 38000 THEN 200
            WHEN curr_alt >= 32000 THEN 250
            WHEN curr_alt >= 26000 THEN 300
            WHEN curr_alt >= 18000 THEN 400
            WHEN curr_alt >= 10000 THEN 500
            ELSE 700
        END,

        -- Pressure at cruise altitude (filed altitude)
        pressure_cruise = CASE
            WHEN ISNULL(filed_alt, 35000) >= 38000 THEN 200
            WHEN ISNULL(filed_alt, 35000) >= 32000 THEN 250
            WHEN ISNULL(filed_alt, 35000) >= 26000 THEN 300
            WHEN ISNULL(filed_alt, 35000) >= 18000 THEN 400
            ELSE 500
        END,

        -- Pressure at mid-descent (~15000ft)
        pressure_descent = 500  -- ~18000ft is typical descent altitude
    WHERE dest_lat IS NOT NULL;

    -- ========================================================================
    -- Step 4: Get wind at each segment altitude from grid
    -- ========================================================================
    DROP TABLE IF EXISTS #wind_results;

    CREATE TABLE #wind_results (
        flight_uid          BIGINT PRIMARY KEY,
        wind_climb_kts      DECIMAL(6,2),   -- Wind during climb (current alt to cruise)
        wind_cruise_kts     DECIMAL(6,2),   -- Wind at cruise altitude
        wind_descent_kts    DECIMAL(6,2),   -- Wind during descent
        wind_weighted_kts   DECIMAL(6,2),   -- Distance-weighted average
        confidence          DECIMAL(3,2),
        source              VARCHAR(16)
    );

    IF @grid_available = 1
    BEGIN
        -- Calculate wind for each segment using appropriate altitude
        INSERT INTO #wind_results (flight_uid, wind_climb_kts, wind_cruise_kts, wind_descent_kts,
                                   wind_weighted_kts, confidence, source)
        SELECT
            d.flight_uid,

            -- Climb wind: Use wind between current altitude and cruise altitude (clamped to ±200 kts)
            -- Average of current and cruise pressure levels
            CASE
                WHEN (ISNULL(g_curr.wind_component, 0) + ISNULL(g_cruise.wind_component, 0)) / 2.0 > 200 THEN 200
                WHEN (ISNULL(g_curr.wind_component, 0) + ISNULL(g_cruise.wind_component, 0)) / 2.0 < -200 THEN -200
                ELSE (ISNULL(g_curr.wind_component, 0) + ISNULL(g_cruise.wind_component, 0)) / 2.0
            END,

            -- Cruise wind: Wind at cruise altitude (clamped to ±200 kts)
            CASE
                WHEN g_cruise.wind_component > 200 THEN 200
                WHEN g_cruise.wind_component < -200 THEN -200
                ELSE g_cruise.wind_component
            END,

            -- Descent wind: Wind at descent altitude (clamped to ±200 kts)
            CASE
                WHEN g_desc.wind_component > 200 THEN 200
                WHEN g_desc.wind_component < -200 THEN -200
                ELSE g_desc.wind_component
            END,

            -- Weighted average based on remaining segment distances (clamped to ±200 kts)
            CASE
                WHEN d.dist_to_dest_nm >= 10 THEN
                    CASE
                        WHEN (
                            ISNULL(g_curr.wind_component, 0) * d.remaining_climb_nm +
                            ISNULL(g_cruise.wind_component, 0) * d.cruise_dist_nm +
                            ISNULL(g_desc.wind_component, 0) * d.descent_dist_nm
                        ) / d.dist_to_dest_nm > 200 THEN 200
                        WHEN (
                            ISNULL(g_curr.wind_component, 0) * d.remaining_climb_nm +
                            ISNULL(g_cruise.wind_component, 0) * d.cruise_dist_nm +
                            ISNULL(g_desc.wind_component, 0) * d.descent_dist_nm
                        ) / d.dist_to_dest_nm < -200 THEN -200
                        ELSE (
                            ISNULL(g_curr.wind_component, 0) * d.remaining_climb_nm +
                            ISNULL(g_cruise.wind_component, 0) * d.cruise_dist_nm +
                            ISNULL(g_desc.wind_component, 0) * d.descent_dist_nm
                        ) / d.dist_to_dest_nm
                    END
                ELSE ISNULL(g_cruise.wind_component, 0)
            END,

            @base_confidence,
            'GRID_V2'

        FROM #due_for_calc d
        -- Wind at current altitude
        OUTER APPLY (
            SELECT TOP 1
                wind_speed_kts * COS(RADIANS((wind_dir_deg - d.track_deg + 180 + 360) % 360)) AS wind_component
            FROM dbo.wind_grid
            WHERE pressure_hpa = d.pressure_current
              AND valid_time_utc = @grid_valid_time
              AND ABS(lat - d.curr_lat) <= 2.5
              AND ABS(lon - d.curr_lon) <= 2.5
            ORDER BY ABS(lat - d.curr_lat) + ABS(lon - d.curr_lon)
        ) g_curr
        -- Wind at cruise altitude
        OUTER APPLY (
            SELECT TOP 1
                wind_speed_kts * COS(RADIANS((wind_dir_deg - d.track_deg + 180 + 360) % 360)) AS wind_component
            FROM dbo.wind_grid
            WHERE pressure_hpa = d.pressure_cruise
              AND valid_time_utc = @grid_valid_time
              AND ABS(lat - d.curr_lat) <= 2.5
              AND ABS(lon - d.curr_lon) <= 2.5
            ORDER BY ABS(lat - d.curr_lat) + ABS(lon - d.curr_lon)
        ) g_cruise
        -- Wind at descent altitude
        OUTER APPLY (
            SELECT TOP 1
                wind_speed_kts * COS(RADIANS((wind_dir_deg - d.track_deg + 180 + 360) % 360)) AS wind_component
            FROM dbo.wind_grid
            WHERE pressure_hpa = d.pressure_descent
              AND valid_time_utc = @grid_valid_time
              AND ABS(lat - d.curr_lat) <= 2.5
              AND ABS(lon - d.curr_lon) <= 2.5
            ORDER BY ABS(lat - d.curr_lat) + ABS(lon - d.curr_lon)
        ) g_desc
        WHERE d.track_deg IS NOT NULL
          AND g_cruise.wind_component IS NOT NULL;  -- At least cruise wind required

        IF @debug = 1
        BEGIN
            DECLARE @grid_count INT;
            SELECT @grid_count = COUNT(*) FROM #wind_results;
            PRINT 'Flights with grid-based segment wind: ' + CAST(@grid_count AS VARCHAR);
        END
    END

    -- ========================================================================
    -- Step 5: GS-based fallback for flights without grid data
    -- ========================================================================
    INSERT INTO #wind_results (flight_uid, wind_climb_kts, wind_cruise_kts, wind_descent_kts,
                               wind_weighted_kts, confidence, source)
    SELECT
        d.flight_uid,
        NULL,  -- Can't determine climb wind without grid
        -- Cruise wind from GS vs expected TAS
        CASE
            WHEN d.groundspeed_kts - d.cruise_speed > 100 THEN 100
            WHEN d.groundspeed_kts - d.cruise_speed < -100 THEN -100
            ELSE d.groundspeed_kts - d.cruise_speed
        END,
        NULL,  -- Can't determine descent wind without grid
        -- Use cruise wind as weighted (only wind we have)
        CASE
            WHEN d.groundspeed_kts - d.cruise_speed > 100 THEN 100
            WHEN d.groundspeed_kts - d.cruise_speed < -100 THEN -100
            ELSE d.groundspeed_kts - d.cruise_speed
        END,
        CASE
            WHEN d.phase IN ('enroute', 'cruise') AND d.curr_alt > 25000 THEN 0.60
            WHEN d.phase IN ('enroute', 'cruise') THEN 0.50
            ELSE 0.40
        END,
        'GS_BASED'
    FROM #due_for_calc d
    WHERE NOT EXISTS (SELECT 1 FROM #wind_results r WHERE r.flight_uid = d.flight_uid)
      AND d.groundspeed_kts > 100
      AND d.phase IN ('enroute', 'cruise', 'climbing', 'descending');

    IF @debug = 1
    BEGIN
        DECLARE @gs_count INT;
        SELECT @gs_count = COUNT(*) FROM #wind_results WHERE source = 'GS_BASED';
        PRINT 'Flights with GS-based wind: ' + CAST(@gs_count AS VARCHAR);
    END

    -- ========================================================================
    -- Step 6: Update adl_flight_times with segment winds
    -- ========================================================================
    UPDATE ft
    SET ft.eta_wind_adj_kts = r.wind_weighted_kts,
        ft.eta_wind_climb_kts = r.wind_climb_kts,
        ft.eta_wind_cruise_kts = r.wind_cruise_kts,
        ft.eta_wind_descent_kts = r.wind_descent_kts,
        ft.eta_wind_confidence = r.confidence,
        ft.wind_last_calc_utc = @now
    FROM dbo.adl_flight_times ft
    INNER JOIN #wind_results r ON r.flight_uid = ft.flight_uid;

    SET @updated_count = @@ROWCOUNT;

    -- Insert for flights without times record
    INSERT INTO dbo.adl_flight_times (flight_uid, eta_wind_adj_kts, eta_wind_climb_kts,
                                       eta_wind_cruise_kts, eta_wind_descent_kts,
                                       eta_wind_confidence, wind_last_calc_utc)
    SELECT r.flight_uid, r.wind_weighted_kts, r.wind_climb_kts, r.wind_cruise_kts,
           r.wind_descent_kts, r.confidence, @now
    FROM #wind_results r
    WHERE NOT EXISTS (SELECT 1 FROM dbo.adl_flight_times ft WHERE ft.flight_uid = r.flight_uid);

    SET @updated_count = @updated_count + @@ROWCOUNT;

    IF @debug = 1
    BEGIN
        PRINT '';
        PRINT 'Flights updated: ' + CAST(@updated_count AS VARCHAR);
        PRINT 'Duration: ' + CAST(DATEDIFF(MILLISECOND, @start_time, SYSDATETIME()) AS VARCHAR) + 'ms';
        PRINT '';
        PRINT 'Sample segment wind results:';

        SELECT TOP 10
            c.callsign,
            d.phase,
            CAST(d.curr_alt/100 AS VARCHAR) + '00' AS alt,
            CAST(d.filed_alt/100 AS VARCHAR) + '00' AS cruise_alt,
            CAST(d.remaining_climb_nm AS INT) AS climb_nm,
            CAST(d.cruise_dist_nm AS INT) AS cruise_nm,
            CAST(d.descent_dist_nm AS INT) AS desc_nm,
            CAST(r.wind_climb_kts AS INT) AS wind_clb,
            CAST(r.wind_cruise_kts AS INT) AS wind_crs,
            CAST(r.wind_descent_kts AS INT) AS wind_dsc,
            CAST(r.wind_weighted_kts AS INT) AS wind_avg,
            r.source
        FROM #due_for_calc d
        JOIN dbo.adl_flight_core c ON c.flight_uid = d.flight_uid
        JOIN #wind_results r ON r.flight_uid = d.flight_uid
        WHERE r.source = 'GRID_V2'
        ORDER BY d.dist_to_dest_nm DESC;
    END

    -- Cleanup
    DROP TABLE IF EXISTS #wind_work;
    DROP TABLE IF EXISTS #due_for_calc;
    DROP TABLE IF EXISTS #wind_results;
END
GO

PRINT 'Created sp_UpdateFlightWindAdjustments_V2 (Segment-Based Wind)';
PRINT '';
PRINT 'New features:';
PRINT '  - Separate wind calculations for climb/cruise/descent segments';
PRINT '  - Looks up wind at cruise altitude even when still climbing';
PRINT '  - Distance-weighted average for overall wind adjustment';
PRINT '  - New columns: eta_wind_climb_kts, eta_wind_cruise_kts, eta_wind_descent_kts';
GO
