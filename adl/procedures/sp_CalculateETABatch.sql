-- ============================================================================
-- sp_CalculateETABatch.sql (V2 - Step Climb Integration)
-- Consolidated batch ETA calculation - Single Source of Truth
-- 
-- V2 Changes:
--   - Uses SimBrief step climb data when available
--   - Uses final_alt_ft for more accurate TOD calculation
--   - Integrates planned cruise speed from step climbs
--   - Enhanced method tracking ('BATCH_V2_SIMBRIEF' vs 'BATCH_V2')
--
-- Features:
--   - Aircraft performance lookup via fn_GetAircraftPerformance
--   - Step climb speed/altitude integration (NEW)
--   - TMI delay handling (EDCT/CTA)
--   - Wind component estimation
--   - Full phase handling
--   - TOD distance and ETA calculation
--   - Efficient set-based batch processing
--
-- Date: 2026-01-07
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID('dbo.sp_CalculateETABatch', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_CalculateETABatch;
GO

CREATE PROCEDURE dbo.sp_CalculateETABatch
    @eta_count INT = NULL OUTPUT,
    @debug BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @start_time DATETIME2(3) = SYSDATETIME();
    DECLARE @step_time DATETIME2(3);
    
    SET @eta_count = 0;
    
    -- ========================================================================
    -- Step 1: Build work table with flight data (including SimBrief data)
    -- ========================================================================
    IF @debug = 1
    BEGIN
        SET @step_time = SYSDATETIME();
        PRINT 'Step 1: Building work table...';
    END
    
    DROP TABLE IF EXISTS #eta_work;
    
    SELECT 
        c.flight_uid,
        c.phase,
        p.altitude_ft,
        p.groundspeed_kts,
        p.dist_to_dest_nm,
        p.dist_flown_nm,
        -- Use filed altitude, but prefer final_alt from step climbs if available
        COALESCE(fp.final_alt_ft, fp.fp_altitude_ft, 35000) AS filed_alt,
        -- Also track initial cruise alt for step climb detection
        fp.initial_alt_ft,
        fp.final_alt_ft,
        fp.stepclimb_count,
        fp.is_simbrief,
        fp.fp_dest_icao,
        fp.fp_dept_icao,
        fp.gcd_nm,
        ac.aircraft_icao,
        ISNULL(ac.weight_class, 'L') AS weight_class,
        ac.engine_type,
        ISNULL(CAST(apt.ELEV AS INT), 0) AS dest_elev,
        -- TMI data
        tmi.edct_utc,
        tmi.cta_utc,
        -- Existing times (for arrived flights)
        ft.on_utc,
        ft.in_utc,
        ft.ata_runway_utc,
        ft.eta_utc AS current_eta
    INTO #eta_work
    FROM dbo.adl_flight_core c
    INNER JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid
    LEFT JOIN dbo.apts apt ON apt.ICAO_ID = fp.fp_dest_icao
    LEFT JOIN dbo.adl_flight_tmi tmi ON tmi.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
    WHERE c.is_active = 1
      AND p.lat IS NOT NULL;
    
    IF @debug = 1
    BEGIN
        PRINT '  Work table rows: ' + CAST(@@ROWCOUNT AS VARCHAR);
        PRINT '  Duration: ' + CAST(DATEDIFF(MILLISECOND, @step_time, SYSDATETIME()) AS VARCHAR) + 'ms';
    END
    
    -- ========================================================================
    -- Step 1b: Add step climb cruise speed data (NEW in V2)
    -- Get average cruise speed from step climbs for SimBrief flights
    -- ========================================================================
    IF @debug = 1
    BEGIN
        SET @step_time = SYSDATETIME();
        PRINT 'Step 1b: Loading step climb speeds...';
    END
    
    ALTER TABLE #eta_work ADD
        simbrief_cruise_kts INT NULL,
        simbrief_cruise_mach DECIMAL(4,3) NULL;
    
    -- Get weighted average cruise speed from step climbs
    -- Use a temp table instead of CTE for compatibility
    DROP TABLE IF EXISTS #step_speeds;
    
    SELECT 
        sc.flight_uid,
        AVG(sc.speed_kts) AS avg_speed_kts,
        AVG(sc.speed_mach) AS avg_speed_mach,
        MAX(sc.speed_kts) AS max_speed_kts,
        MAX(sc.speed_mach) AS max_speed_mach
    INTO #step_speeds
    FROM dbo.adl_flight_stepclimbs sc
    INNER JOIN #eta_work w ON w.flight_uid = sc.flight_uid
    WHERE sc.speed_kts IS NOT NULL OR sc.speed_mach IS NOT NULL
    GROUP BY sc.flight_uid;
    
    UPDATE w
    SET w.simbrief_cruise_kts = COALESCE(ss.max_speed_kts, ss.avg_speed_kts),
        w.simbrief_cruise_mach = COALESCE(ss.max_speed_mach, ss.avg_speed_mach)
    FROM #eta_work w
    INNER JOIN #step_speeds ss ON ss.flight_uid = w.flight_uid;
    
    DROP TABLE IF EXISTS #step_speeds;
    
    IF @debug = 1
    BEGIN
        DECLARE @simbrief_speed_count INT;
        SELECT @simbrief_speed_count = COUNT(*) FROM #eta_work WHERE simbrief_cruise_kts IS NOT NULL;
        PRINT '  Flights with SimBrief cruise speed: ' + CAST(@simbrief_speed_count AS VARCHAR);
        PRINT '  Duration: ' + CAST(DATEDIFF(MILLISECOND, @step_time, SYSDATETIME()) AS VARCHAR) + 'ms';
    END
    
    -- ========================================================================
    -- Step 2: Build performance lookup table
    -- ========================================================================
    IF @debug = 1
    BEGIN
        SET @step_time = SYSDATETIME();
        PRINT 'Step 2: Building performance lookup...';
    END
    
    DROP TABLE IF EXISTS #perf_lookup;
    
    SELECT DISTINCT
        aircraft_icao,
        weight_class,
        engine_type
    INTO #perf_lookup
    FROM #eta_work
    WHERE aircraft_icao IS NOT NULL;
    
    ALTER TABLE #perf_lookup ADD
        climb_speed_kias INT NULL,
        cruise_speed_ktas INT NULL,
        descent_speed_kias INT NULL,
        perf_source NVARCHAR(32) NULL;
    
    UPDATE pl
    SET pl.climb_speed_kias = perf.climb_speed_kias,
        pl.cruise_speed_ktas = perf.cruise_speed_ktas,
        pl.descent_speed_kias = perf.descent_speed_kias,
        pl.perf_source = perf.source
    FROM #perf_lookup pl
    CROSS APPLY dbo.fn_GetAircraftPerformance(
        pl.aircraft_icao, 
        pl.weight_class, 
        pl.engine_type
    ) perf;
    
    UPDATE #perf_lookup
    SET climb_speed_kias = ISNULL(climb_speed_kias, 280),
        cruise_speed_ktas = ISNULL(cruise_speed_ktas, 450),
        descent_speed_kias = ISNULL(descent_speed_kias, 280),
        perf_source = ISNULL(perf_source, 'DEFAULT');
    
    IF @debug = 1
    BEGIN
        DECLARE @perf_lookup_count INT;
        SELECT @perf_lookup_count = COUNT(*) FROM #perf_lookup;
        PRINT '  Performance lookup rows: ' + CAST(@perf_lookup_count AS VARCHAR);
        PRINT '  Duration: ' + CAST(DATEDIFF(MILLISECOND, @step_time, SYSDATETIME()) AS VARCHAR) + 'ms';
    END
    
    -- ========================================================================
    -- Step 3: Add performance to work table (with SimBrief override)
    -- ========================================================================
    ALTER TABLE #eta_work ADD
        climb_speed INT NULL,
        cruise_speed INT NULL,
        descent_speed INT NULL,
        speed_source NVARCHAR(16) NULL;
    
    UPDATE w
    SET w.climb_speed = ISNULL(pl.climb_speed_kias, 280),
        -- V2: Use SimBrief cruise speed if available, else aircraft performance
        w.cruise_speed = COALESCE(
            w.simbrief_cruise_kts,  -- SimBrief step climb speed (priority)
            CASE WHEN w.simbrief_cruise_mach IS NOT NULL 
                 THEN CAST(w.simbrief_cruise_mach * 600 AS INT)  -- Rough Mach to TAS @ FL350
                 ELSE NULL END,
            pl.cruise_speed_ktas,   -- Aircraft performance lookup
            450                      -- Default
        ),
        w.descent_speed = ISNULL(pl.descent_speed_kias, 280),
        w.speed_source = CASE 
            WHEN w.simbrief_cruise_kts IS NOT NULL THEN 'SIMBRIEF_TAS'
            WHEN w.simbrief_cruise_mach IS NOT NULL THEN 'SIMBRIEF_MACH'
            WHEN pl.cruise_speed_ktas IS NOT NULL THEN 'AIRCRAFT_PERF'
            ELSE 'DEFAULT'
        END
    FROM #eta_work w
    LEFT JOIN #perf_lookup pl 
        ON pl.aircraft_icao = w.aircraft_icao
        AND pl.weight_class = w.weight_class
        AND ISNULL(pl.engine_type, '') = ISNULL(w.engine_type, '');
    
    -- Set defaults for any remaining NULLs
    UPDATE #eta_work
    SET climb_speed = ISNULL(climb_speed, 280),
        cruise_speed = ISNULL(cruise_speed, 450),
        descent_speed = ISNULL(descent_speed, 280),
        speed_source = ISNULL(speed_source, 'DEFAULT');
    
    IF @debug = 1
    BEGIN
        SELECT 
            speed_source, 
            COUNT(*) AS flights,
            AVG(cruise_speed) AS avg_cruise_speed
        FROM #eta_work
        GROUP BY speed_source;
    END
    
    -- ========================================================================
    -- Step 4: Calculate ETA values (V2 with step climb awareness)
    -- ========================================================================
    IF @debug = 1
    BEGIN
        SET @step_time = SYSDATETIME();
        PRINT 'Step 4: Calculating ETAs...';
    END
    
    ;WITH EtaCalc AS (
        SELECT
            w.flight_uid,
            w.phase,
            w.dist_to_dest_nm,
            w.groundspeed_kts,
            w.cruise_speed,
            w.descent_speed,
            w.climb_speed,
            w.filed_alt,
            w.altitude_ft,
            w.dest_elev,
            w.edct_utc,
            w.cta_utc,
            w.on_utc,
            w.in_utc,
            w.ata_runway_utc,
            w.current_eta,
            w.gcd_nm,
            w.is_simbrief,
            w.stepclimb_count,
            w.speed_source,
            
            -- V2: TOD distance using final cruise altitude (more accurate for step climbs)
            (w.filed_alt - w.dest_elev) / 1000.0 * 3.0 AS tod_dist,
            
            -- TOC distance: 2nm per 1000ft climb
            CASE 
                WHEN w.altitude_ft < w.filed_alt 
                THEN (w.filed_alt - ISNULL(w.altitude_ft, 0)) / 1000.0 * 2.0 
                ELSE 0 
            END AS toc_dist,
            
            -- Determine if arrived
            CASE 
                WHEN w.phase IN ('on', 'in', 'arrived') THEN 1
                WHEN w.current_eta IS NOT NULL AND @now >= w.current_eta THEN 1
                ELSE 0
            END AS is_arrived,
            
            -- Actual arrival time
            CASE 
                WHEN w.phase = 'in' THEN COALESCE(w.in_utc, w.on_utc, w.ata_runway_utc, @now)
                WHEN w.phase = 'on' THEN COALESCE(w.on_utc, w.ata_runway_utc, @now)
                WHEN w.phase = 'arrived' THEN COALESCE(w.ata_runway_utc, w.on_utc, @now)
                ELSE NULL
            END AS actual_arrival,
            
            -- TMI delay
            CASE 
                WHEN w.edct_utc IS NOT NULL AND w.phase IN ('prefile', 'taxiing', 'unknown', 'parking', 'gate')
                THEN CASE WHEN DATEDIFF(MINUTE, @now, w.edct_utc) > 0 
                          THEN DATEDIFF(MINUTE, @now, w.edct_utc) 
                          ELSE 0 END
                ELSE 0
            END AS tmi_delay
            
        FROM #eta_work w
    ),
    EtaTime AS (
        SELECT
            e.*,
            
            -- Calculate time to destination
            CASE 
                WHEN e.is_arrived = 1 THEN 0
                
                -- Final approach
                WHEN e.phase = 'descending' AND e.dist_to_dest_nm < 50 THEN
                    e.dist_to_dest_nm / NULLIF(e.groundspeed_kts, 0) * 60
                
                -- Descent phase
                WHEN e.phase = 'descending' THEN
                    e.dist_to_dest_nm / NULLIF(e.descent_speed, 0) * 60
                
                -- Cruise/enroute
                WHEN e.phase IN ('enroute', 'cruise') THEN
                    CASE WHEN e.dist_to_dest_nm > e.tod_dist
                         THEN (e.dist_to_dest_nm - e.tod_dist) / NULLIF(e.cruise_speed, 0) * 60
                         ELSE 0
                    END
                    + CASE WHEN e.dist_to_dest_nm > e.tod_dist
                           THEN e.tod_dist / NULLIF(e.descent_speed, 0) * 60
                           ELSE e.dist_to_dest_nm / NULLIF(e.descent_speed, 0) * 60
                    END
                
                -- Climbing
                WHEN e.phase IN ('departed', 'climbing') THEN
                    e.toc_dist / NULLIF(e.climb_speed, 0) * 60
                    + CASE WHEN (e.dist_to_dest_nm - e.toc_dist - e.tod_dist) > 0
                           THEN (e.dist_to_dest_nm - e.toc_dist - e.tod_dist) / NULLIF(e.cruise_speed, 0) * 60
                           ELSE 0
                    END
                    + e.tod_dist / NULLIF(e.descent_speed, 0) * 60
                
                -- Taxiing
                WHEN e.phase IN ('taxiing', 'taxi') THEN
                    12
                    + (e.filed_alt - e.dest_elev) / 1000.0 * 2.0 / NULLIF(e.climb_speed, 0) * 60
                    + CASE WHEN (e.dist_to_dest_nm - (e.filed_alt - e.dest_elev) / 1000.0 * 2.0 - e.tod_dist) > 0
                           THEN (e.dist_to_dest_nm - (e.filed_alt - e.dest_elev) / 1000.0 * 2.0 - e.tod_dist) / NULLIF(e.cruise_speed, 0) * 60
                           ELSE ISNULL(e.gcd_nm, 500) / NULLIF(e.cruise_speed, 0) * 60
                    END
                    + e.tod_dist / NULLIF(e.descent_speed, 0) * 60
                    + e.tmi_delay
                
                -- Pre-file
                ELSE
                    15
                    + ISNULL(e.dist_to_dest_nm, e.gcd_nm) / NULLIF(e.cruise_speed, 0) * 60 * 1.15
                    + e.tmi_delay
                    
            END AS time_to_dest_min,
            
            -- V2: Confidence boost for SimBrief flights with step climb data
            CASE 
                WHEN e.is_arrived = 1 THEN 1.00
                WHEN e.phase = 'descending' AND e.dist_to_dest_nm < 50 THEN 0.95
                WHEN e.phase = 'descending' THEN 0.92
                WHEN e.phase IN ('enroute', 'cruise') AND e.is_simbrief = 1 AND e.stepclimb_count > 0 THEN 0.92  -- Higher confidence with SimBrief
                WHEN e.phase IN ('enroute', 'cruise') THEN 0.88
                WHEN e.phase IN ('climbing', 'departed') AND e.is_simbrief = 1 THEN 0.85
                WHEN e.phase IN ('climbing', 'departed') THEN 0.82
                WHEN e.phase IN ('taxiing', 'taxi') THEN 0.75
                WHEN e.phase IN ('parking', 'gate') THEN 0.70
                ELSE 0.65
            END AS confidence,
            
            -- ETA prefix
            CASE 
                WHEN e.is_arrived = 1 THEN 'A'
                WHEN e.cta_utc IS NOT NULL THEN 'C'
                WHEN e.edct_utc IS NOT NULL AND e.phase IN ('prefile', 'taxiing', 'unknown', 'parking', 'gate') THEN 'C'
                WHEN e.phase IN ('prefile', 'unknown') THEN 'P'
                ELSE 'E'
            END AS eta_prefix,
            
            -- Wind component
            CASE 
                WHEN e.phase IN ('enroute', 'cruise') AND e.groundspeed_kts > 0 THEN
                    CASE 
                        WHEN e.groundspeed_kts - e.cruise_speed > 100 THEN 100
                        WHEN e.groundspeed_kts - e.cruise_speed < -100 THEN -100
                        ELSE e.groundspeed_kts - e.cruise_speed
                    END
                ELSE 0
            END AS wind_component
            
        FROM EtaCalc e
    ),
    EtaFinal AS (
        SELECT
            et.flight_uid,
            et.dist_to_dest_nm,
            et.tod_dist,
            et.time_to_dest_min,
            et.confidence,
            et.eta_prefix,
            et.wind_component,
            et.tmi_delay,
            et.is_arrived,
            et.actual_arrival,
            et.cta_utc,
            et.groundspeed_kts,
            et.is_simbrief,
            et.stepclimb_count,
            et.speed_source,
            
            CASE 
                WHEN et.is_arrived = 1 THEN et.actual_arrival
                WHEN et.time_to_dest_min IS NULL OR et.time_to_dest_min < 0 THEN NULL
                ELSE DATEADD(MINUTE, CAST(et.time_to_dest_min AS INT), @now)
            END AS calc_eta,
            
            CASE 
                WHEN et.is_arrived = 1 THEN NULL
                WHEN et.phase IN ('descending', 'on', 'in', 'arrived') THEN NULL
                WHEN et.dist_to_dest_nm <= et.tod_dist THEN NULL
                WHEN et.groundspeed_kts > 0 THEN
                    DATEADD(MINUTE, 
                        CAST((et.dist_to_dest_nm - et.tod_dist) / NULLIF(et.groundspeed_kts, 0) * 60 AS INT), 
                        @now)
                ELSE NULL
            END AS tod_eta,
            
            et.phase
            
        FROM EtaTime et
    )
    SELECT
        ef.flight_uid,
        CASE 
            WHEN ef.is_arrived = 1 THEN ef.calc_eta
            WHEN ef.cta_utc IS NOT NULL AND ef.calc_eta IS NOT NULL AND ef.cta_utc > ef.calc_eta THEN ef.cta_utc
            ELSE ef.calc_eta
        END AS final_eta,
        ef.calc_eta,
        CASE 
            WHEN ef.is_arrived = 1 THEN 'A'
            WHEN ef.cta_utc IS NOT NULL AND ef.calc_eta IS NOT NULL AND ef.cta_utc > ef.calc_eta THEN 'C'
            ELSE ef.eta_prefix
        END AS final_prefix,
        ef.confidence,
        ef.dist_to_dest_nm,
        ef.wind_component,
        ef.tmi_delay,
        ef.tod_dist,
        ef.tod_eta,
        -- V2: Track method for analysis
        CASE 
            WHEN ef.is_simbrief = 1 AND ef.stepclimb_count > 0 THEN 'BATCH_V2_SB'
            WHEN ef.speed_source = 'SIMBRIEF_TAS' OR ef.speed_source = 'SIMBRIEF_MACH' THEN 'BATCH_V2_SB'
            ELSE 'BATCH_V2'
        END AS eta_method
    INTO #eta_results
    FROM EtaFinal ef;
    
    IF @debug = 1
    BEGIN
        DECLARE @eta_result_count INT;
        SELECT @eta_result_count = COUNT(*) FROM #eta_results;
        PRINT '  ETA results calculated: ' + CAST(@eta_result_count AS VARCHAR);
        SELECT eta_method, COUNT(*) AS flights FROM #eta_results GROUP BY eta_method;
        PRINT '  Duration: ' + CAST(DATEDIFF(MILLISECOND, @step_time, SYSDATETIME()) AS VARCHAR) + 'ms';
    END
    
    -- ========================================================================
    -- Step 5: Update flight_times table
    -- ========================================================================
    IF @debug = 1
    BEGIN
        SET @step_time = SYSDATETIME();
        PRINT 'Step 5: Updating flight_times...';
    END
    
    UPDATE ft
    SET ft.eta_utc = r.final_eta,
        ft.eta_runway_utc = r.final_eta,
        ft.eta_epoch = CASE 
            WHEN r.final_eta IS NOT NULL 
            THEN DATEDIFF(SECOND, '1970-01-01', r.final_eta) 
            ELSE NULL 
        END,
        ft.eta_prefix = r.final_prefix,
        ft.eta_confidence = r.confidence,
        ft.eta_route_dist_nm = r.dist_to_dest_nm,
        ft.eta_wind_component_kts = r.wind_component,
        ft.eta_tmi_delay_min = r.tmi_delay,
        ft.eta_weather_delay_min = 0,
        ft.eta_last_calc_utc = @now,
        ft.tod_dist_nm = r.tod_dist,
        ft.tod_eta_utc = r.tod_eta,
        ft.eta_method = r.eta_method,
        ft.times_updated_utc = @now
    FROM dbo.adl_flight_times ft
    INNER JOIN #eta_results r ON r.flight_uid = ft.flight_uid;
    
    SET @eta_count = @@ROWCOUNT;
    
    IF @debug = 1
    BEGIN
        PRINT '  Rows updated: ' + CAST(@eta_count AS VARCHAR);
        PRINT '  Duration: ' + CAST(DATEDIFF(MILLISECOND, @step_time, SYSDATETIME()) AS VARCHAR) + 'ms';
    END
    
    -- ========================================================================
    -- Step 6: Insert rows for flights without times record
    -- ========================================================================
    INSERT INTO dbo.adl_flight_times (
        flight_uid,
        eta_utc,
        eta_runway_utc,
        eta_epoch,
        eta_prefix,
        eta_confidence,
        eta_route_dist_nm,
        eta_wind_component_kts,
        eta_tmi_delay_min,
        eta_weather_delay_min,
        eta_last_calc_utc,
        tod_dist_nm,
        tod_eta_utc,
        eta_method,
        times_updated_utc
    )
    SELECT 
        r.flight_uid,
        r.final_eta,
        r.final_eta,
        CASE WHEN r.final_eta IS NOT NULL 
             THEN DATEDIFF(SECOND, '1970-01-01', r.final_eta) 
             ELSE NULL END,
        r.final_prefix,
        r.confidence,
        r.dist_to_dest_nm,
        r.wind_component,
        r.tmi_delay,
        0,
        @now,
        r.tod_dist,
        r.tod_eta,
        r.eta_method,
        @now
    FROM #eta_results r
    WHERE NOT EXISTS (
        SELECT 1 FROM dbo.adl_flight_times ft 
        WHERE ft.flight_uid = r.flight_uid
    );
    
    IF @debug = 1
    BEGIN
        PRINT '  New rows inserted: ' + CAST(@@ROWCOUNT AS VARCHAR);
        PRINT 'Total duration: ' + CAST(DATEDIFF(MILLISECOND, @start_time, SYSDATETIME()) AS VARCHAR) + 'ms';
    END
    
    -- Cleanup
    DROP TABLE IF EXISTS #eta_work;
    DROP TABLE IF EXISTS #perf_lookup;
    DROP TABLE IF EXISTS #eta_results;
    
END
GO

PRINT 'Created sp_CalculateETABatch V2 (Step Climb Integration)';
PRINT '';
PRINT 'V2 Enhancements:';
PRINT '  - Uses SimBrief final_alt_ft for accurate TOD calculation';
PRINT '  - Integrates step climb cruise speeds (TAS/Mach)';
PRINT '  - Higher confidence for SimBrief flights with step climbs';
PRINT '  - Method tracking: BATCH_V2_SB vs BATCH_V2';
GO
