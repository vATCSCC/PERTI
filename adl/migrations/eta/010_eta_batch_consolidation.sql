-- ============================================================================
-- 044_eta_batch_consolidation.sql
-- ETA Calculation Consolidation - Single Source of Truth
-- 
-- Consolidates ETA logic from:
--   - sp_CalculateETA (sophisticated per-flight)
--   - sp_ProcessTrajectoryBatch (inline CTE, simplified)
-- Into:
--   - sp_CalculateETABatch (batch processing with full features)
--
-- Date: 2026-01-06
-- ============================================================================

SET NOCOUNT ON;
GO

PRINT '=== Starting ETA Batch Consolidation Migration ===';
PRINT 'Timestamp: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- STEP 1: Add eta_method column to track calculation source
-- ============================================================================

IF NOT EXISTS (
    SELECT 1 FROM sys.columns 
    WHERE object_id = OBJECT_ID('dbo.adl_flight_times') 
    AND name = 'eta_method'
)
BEGIN
    ALTER TABLE dbo.adl_flight_times
    ADD eta_method NVARCHAR(16) NULL;
    
    PRINT 'Added eta_method column to adl_flight_times';
END
ELSE
BEGIN
    PRINT 'eta_method column already exists';
END
GO

-- Add eta_weather_delay_min if missing (used by sophisticated calc)
IF NOT EXISTS (
    SELECT 1 FROM sys.columns 
    WHERE object_id = OBJECT_ID('dbo.adl_flight_times') 
    AND name = 'eta_weather_delay_min'
)
BEGIN
    ALTER TABLE dbo.adl_flight_times
    ADD eta_weather_delay_min INT NULL;
    
    PRINT 'Added eta_weather_delay_min column to adl_flight_times';
END
GO

-- Add eta_wind_component_kts if missing
IF NOT EXISTS (
    SELECT 1 FROM sys.columns 
    WHERE object_id = OBJECT_ID('dbo.adl_flight_times') 
    AND name = 'eta_wind_component_kts'
)
BEGIN
    ALTER TABLE dbo.adl_flight_times
    ADD eta_wind_component_kts INT NULL;
    
    PRINT 'Added eta_wind_component_kts column to adl_flight_times';
END
GO

-- Add tod_eta_utc if missing (ETA at top of descent)
IF NOT EXISTS (
    SELECT 1 FROM sys.columns 
    WHERE object_id = OBJECT_ID('dbo.adl_flight_times') 
    AND name = 'tod_eta_utc'
)
BEGIN
    ALTER TABLE dbo.adl_flight_times
    ADD tod_eta_utc DATETIME2(0) NULL;
    
    PRINT 'Added tod_eta_utc column to adl_flight_times';
END
GO

-- Add tod_dist_nm if missing
IF NOT EXISTS (
    SELECT 1 FROM sys.columns 
    WHERE object_id = OBJECT_ID('dbo.adl_flight_times') 
    AND name = 'tod_dist_nm'
)
BEGIN
    ALTER TABLE dbo.adl_flight_times
    ADD tod_dist_nm DECIMAL(10,2) NULL;
    
    PRINT 'Added tod_dist_nm column to adl_flight_times';
END
GO

-- ============================================================================
-- STEP 2: Create sp_CalculateETABatch
-- ============================================================================

IF OBJECT_ID('dbo.sp_CalculateETABatch', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_CalculateETABatch;
GO

CREATE PROCEDURE dbo.sp_CalculateETABatch
    @eta_count INT = NULL OUTPUT,
    @debug BIT = 0
AS
BEGIN
    /*
    ============================================================================
    sp_CalculateETABatch
    
    Consolidated batch ETA calculation combining:
    - Aircraft performance lookup (from sp_CalculateETA)
    - TMI delay handling (EDCT/CTA)
    - Wind component estimation
    - Full phase handling (arrived, on, in, descending, enroute, climbing, taxiing, prefile)
    - TOD distance and ETA calculation
    - Efficient set-based processing
    
    Performance Strategy:
    1. Build temp table with all active flights + performance data
    2. Single-pass CTE calculation
    3. Batch UPDATE to flight_times
    
    Arrived Logic:
    - phase = 'on' -> arrived at runway (use on_utc as ATA)
    - phase = 'in' -> arrived at gate (use in_utc as ATA)
    - now >= eta_utc -> treat as arrived
    ============================================================================
    */
    SET NOCOUNT ON;
    
    DECLARE @now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @start_time DATETIME2(3) = SYSDATETIME();
    DECLARE @step_time DATETIME2(3);
    
    SET @eta_count = 0;
    
    -- ========================================================================
    -- Step 1: Build work table with flight data
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
        ISNULL(fp.fp_altitude_ft, 35000) AS filed_alt,
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
    -- Step 2: Build performance lookup table
    -- Get distinct aircraft types and look up performance once
    -- ========================================================================
    IF @debug = 1
    BEGIN
        SET @step_time = SYSDATETIME();
        PRINT 'Step 2: Building performance lookup...';
    END
    
    DROP TABLE IF EXISTS #perf_lookup;
    
    -- Get all distinct aircraft/weight/engine combinations
    SELECT DISTINCT
        aircraft_icao,
        weight_class,
        engine_type
    INTO #perf_lookup
    FROM #eta_work
    WHERE aircraft_icao IS NOT NULL;
    
    -- Add performance columns
    ALTER TABLE #perf_lookup ADD
        climb_speed_kias INT NULL,
        cruise_speed_ktas INT NULL,
        descent_speed_kias INT NULL,
        perf_source NVARCHAR(32) NULL;
    
    -- Update with performance data using table function
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
    
    -- Fill in defaults for any NULLs
    UPDATE #perf_lookup
    SET climb_speed_kias = ISNULL(climb_speed_kias, 280),
        cruise_speed_ktas = ISNULL(cruise_speed_ktas, 450),
        descent_speed_kias = ISNULL(descent_speed_kias, 280),
        perf_source = ISNULL(perf_source, 'DEFAULT');
    
    IF @debug = 1
    BEGIN
        PRINT '  Performance lookup rows: ' + CAST((SELECT COUNT(*) FROM #perf_lookup) AS VARCHAR);
        PRINT '  Duration: ' + CAST(DATEDIFF(MILLISECOND, @step_time, SYSDATETIME()) AS VARCHAR) + 'ms';
    END
    
    -- ========================================================================
    -- Step 3: Add performance to work table
    -- ========================================================================
    ALTER TABLE #eta_work ADD
        climb_speed INT NULL,
        cruise_speed INT NULL,
        descent_speed INT NULL;
    
    UPDATE w
    SET w.climb_speed = ISNULL(pl.climb_speed_kias, 280),
        w.cruise_speed = ISNULL(pl.cruise_speed_ktas, 450),
        w.descent_speed = ISNULL(pl.descent_speed_kias, 280)
    FROM #eta_work w
    LEFT JOIN #perf_lookup pl 
        ON pl.aircraft_icao = w.aircraft_icao
        AND pl.weight_class = w.weight_class
        AND ISNULL(pl.engine_type, '') = ISNULL(w.engine_type, '');
    
    -- Set defaults for any remaining NULLs (no aircraft data)
    UPDATE #eta_work
    SET climb_speed = ISNULL(climb_speed, 280),
        cruise_speed = ISNULL(cruise_speed, 450),
        descent_speed = ISNULL(descent_speed, 280);
    
    -- ========================================================================
    -- Step 4: Calculate ETA values
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
            
            -- TOD distance: 3nm per 1000ft descent
            (w.filed_alt - w.dest_elev) / 1000.0 * 3.0 AS tod_dist,
            
            -- TOC distance: 2nm per 1000ft climb
            CASE 
                WHEN w.altitude_ft < w.filed_alt 
                THEN (w.filed_alt - ISNULL(w.altitude_ft, 0)) / 1000.0 * 2.0 
                ELSE 0 
            END AS toc_dist,
            
            -- Determine if arrived (phase = on/in, or now >= eta)
            CASE 
                WHEN w.phase IN ('on', 'in', 'arrived') THEN 1
                WHEN w.current_eta IS NOT NULL AND @now >= w.current_eta THEN 1
                ELSE 0
            END AS is_arrived,
            
            -- Actual arrival time to use
            CASE 
                WHEN w.phase = 'in' THEN COALESCE(w.in_utc, w.on_utc, w.ata_runway_utc, @now)
                WHEN w.phase = 'on' THEN COALESCE(w.on_utc, w.ata_runway_utc, @now)
                WHEN w.phase = 'arrived' THEN COALESCE(w.ata_runway_utc, w.on_utc, @now)
                ELSE NULL
            END AS actual_arrival,
            
            -- TMI delay calculation
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
            
            -- Calculate time to destination based on phase
            CASE 
                -- Already arrived
                WHEN e.is_arrived = 1 THEN 0
                
                -- Final approach (< 50nm, descending)
                WHEN e.phase = 'descending' AND e.dist_to_dest_nm < 50 THEN
                    e.dist_to_dest_nm / NULLIF(e.groundspeed_kts, 0) * 60
                
                -- Descent phase
                WHEN e.phase = 'descending' THEN
                    e.dist_to_dest_nm / NULLIF(e.descent_speed, 0) * 60
                
                -- Cruise/enroute: cruise to TOD + descent
                WHEN e.phase IN ('enroute', 'cruise') THEN
                    -- Time for cruise portion
                    CASE WHEN e.dist_to_dest_nm > e.tod_dist
                         THEN (e.dist_to_dest_nm - e.tod_dist) / NULLIF(e.cruise_speed, 0) * 60
                         ELSE 0
                    END
                    -- Plus descent time
                    + CASE WHEN e.dist_to_dest_nm > e.tod_dist
                           THEN e.tod_dist / NULLIF(e.descent_speed, 0) * 60
                           ELSE e.dist_to_dest_nm / NULLIF(e.descent_speed, 0) * 60
                    END
                
                -- Climbing: climb + cruise + descent
                WHEN e.phase IN ('departed', 'climbing') THEN
                    -- Climb time
                    e.toc_dist / NULLIF(e.climb_speed, 0) * 60
                    -- Cruise time (remaining after climb and before descent)
                    + CASE WHEN (e.dist_to_dest_nm - e.toc_dist - e.tod_dist) > 0
                           THEN (e.dist_to_dest_nm - e.toc_dist - e.tod_dist) / NULLIF(e.cruise_speed, 0) * 60
                           ELSE 0
                    END
                    -- Descent time
                    + e.tod_dist / NULLIF(e.descent_speed, 0) * 60
                
                -- Taxiing: taxi out + full flight + TMI delay
                WHEN e.phase IN ('taxiing', 'taxi') THEN
                    12  -- Average taxi out time
                    -- Full climb
                    + (e.filed_alt - e.dest_elev) / 1000.0 * 2.0 / NULLIF(e.climb_speed, 0) * 60
                    -- Cruise (with safety margin)
                    + CASE WHEN (e.dist_to_dest_nm - (e.filed_alt - e.dest_elev) / 1000.0 * 2.0 - e.tod_dist) > 0
                           THEN (e.dist_to_dest_nm - (e.filed_alt - e.dest_elev) / 1000.0 * 2.0 - e.tod_dist) / NULLIF(e.cruise_speed, 0) * 60
                           ELSE ISNULL(e.gcd_nm, 500) / NULLIF(e.cruise_speed, 0) * 60
                    END
                    -- Descent
                    + e.tod_dist / NULLIF(e.descent_speed, 0) * 60
                    -- TMI delay
                    + e.tmi_delay
                
                -- Pre-file, parking, gate, unknown: estimate full flight
                ELSE
                    15  -- Taxi estimate
                    + ISNULL(e.dist_to_dest_nm, e.gcd_nm) / NULLIF(e.cruise_speed, 0) * 60 * 1.15  -- 15% buffer
                    + e.tmi_delay
                    
            END AS time_to_dest_min,
            
            -- Confidence based on phase
            CASE 
                WHEN e.is_arrived = 1 THEN 1.00
                WHEN e.phase = 'descending' AND e.dist_to_dest_nm < 50 THEN 0.95
                WHEN e.phase = 'descending' THEN 0.92
                WHEN e.phase IN ('enroute', 'cruise') THEN 0.88
                WHEN e.phase IN ('climbing', 'departed') THEN 0.82
                WHEN e.phase IN ('taxiing', 'taxi') THEN 0.75
                WHEN e.phase IN ('parking', 'gate') THEN 0.70
                ELSE 0.65
            END AS confidence,
            
            -- ETA prefix
            CASE 
                WHEN e.is_arrived = 1 THEN 'A'  -- Actual
                WHEN e.cta_utc IS NOT NULL THEN 'C'  -- Controlled
                WHEN e.edct_utc IS NOT NULL AND e.phase IN ('prefile', 'taxiing', 'unknown', 'parking', 'gate') THEN 'C'
                WHEN e.phase IN ('prefile', 'unknown') THEN 'P'  -- Proposed
                ELSE 'E'  -- Estimated
            END AS eta_prefix,
            
            -- Wind component (enroute flights only, clamp to +/- 100)
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
            
            -- Calculate final ETA
            CASE 
                WHEN et.is_arrived = 1 THEN et.actual_arrival
                WHEN et.time_to_dest_min IS NULL OR et.time_to_dest_min < 0 THEN NULL
                ELSE DATEADD(MINUTE, CAST(et.time_to_dest_min AS INT), @now)
            END AS calc_eta,
            
            -- Calculate TOD ETA (when will aircraft reach top of descent)
            CASE 
                WHEN et.is_arrived = 1 THEN NULL
                WHEN et.phase IN ('descending', 'on', 'in', 'arrived') THEN NULL  -- Already past TOD
                WHEN et.dist_to_dest_nm <= et.tod_dist THEN NULL  -- Should be descending
                WHEN et.groundspeed_kts > 0 THEN
                    DATEADD(MINUTE, 
                        CAST((et.dist_to_dest_nm - et.tod_dist) / NULLIF(et.groundspeed_kts, 0) * 60 AS INT), 
                        @now)
                ELSE NULL
            END AS tod_eta,
            
            et.phase
            
        FROM EtaTime et
    )
    -- Final result with CTA override
    SELECT
        ef.flight_uid,
        -- Apply CTA override if CTA is later than calculated ETA
        CASE 
            WHEN ef.is_arrived = 1 THEN ef.calc_eta
            WHEN ef.cta_utc IS NOT NULL AND ef.calc_eta IS NOT NULL AND ef.cta_utc > ef.calc_eta THEN ef.cta_utc
            ELSE ef.calc_eta
        END AS final_eta,
        ef.calc_eta,
        -- Update prefix if CTA override applied
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
        ef.tod_eta
    INTO #eta_results
    FROM EtaFinal ef;
    
    IF @debug = 1
    BEGIN
        PRINT '  ETA results calculated: ' + CAST((SELECT COUNT(*) FROM #eta_results) AS VARCHAR);
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
        ft.eta_weather_delay_min = 0,  -- Placeholder for future weather integration
        ft.eta_last_calc_utc = @now,
        ft.tod_dist_nm = r.tod_dist,
        ft.tod_eta_utc = r.tod_eta,
        ft.eta_method = 'BATCH_V1',
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
        'BATCH_V1',
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

PRINT 'Created sp_CalculateETABatch';
GO

-- ============================================================================
-- STEP 3: Update sp_ProcessTrajectoryBatch to remove inline ETA
-- ============================================================================

IF OBJECT_ID('dbo.sp_ProcessTrajectoryBatch', 'P') IS NOT NULL
BEGIN
    -- Create updated version that calls sp_CalculateETABatch instead of inline ETA
    
    CREATE OR ALTER PROCEDURE dbo.sp_ProcessTrajectoryBatch
        @process_eta BIT = 1,
        @process_trajectory BIT = 1,
        @eta_count INT = NULL OUTPUT,
        @traj_count INT = NULL OUTPUT
    AS
    BEGIN
        SET NOCOUNT ON;
        DECLARE @now DATETIME2(0) = SYSUTCDATETIME();
        SET @eta_count = 0;
        SET @traj_count = 0;
        
        -- Update relevance
        UPDATE c SET c.is_relevant = dbo.fn_IsFlightRelevant(fp.fp_dept_icao, fp.fp_dest_icao, p.lat, p.lon)
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
        JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        WHERE c.is_active = 1 AND c.is_relevant IS NULL;
        
        -- Trajectory logging (unchanged)
        IF @process_trajectory = 1
        BEGIN
            INSERT INTO dbo.adl_flight_trajectory (flight_uid, recorded_utc, lat, lon, altitude_ft, groundspeed_kts, heading_deg, vertical_rate_fpm, tier, tier_reason, flight_phase, dist_to_dest_nm, dist_from_origin_nm, source)
            SELECT c.flight_uid, @now, p.lat, p.lon, p.altitude_ft, p.groundspeed_kts, p.heading_deg, p.vertical_rate_fpm,
                dbo.fn_GetTrajectoryTier(fp.fp_dept_icao, fp.fp_dest_icao, p.lat, p.lon, p.altitude_ft, p.groundspeed_kts, ISNULL(p.vertical_rate_fpm, 0), p.dist_to_dest_nm, p.dist_flown_nm, fp.fp_altitude_ft, c.phase),
                'BATCH', c.phase, p.dist_to_dest_nm, p.dist_flown_nm, 'vatsim'
            FROM dbo.adl_flight_core c
            JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
            JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
            WHERE c.is_active = 1 AND c.is_relevant = 1 AND p.lat IS NOT NULL
              AND dbo.fn_GetTrajectoryTier(fp.fp_dept_icao, fp.fp_dest_icao, p.lat, p.lon, p.altitude_ft, p.groundspeed_kts, ISNULL(p.vertical_rate_fpm, 0), p.dist_to_dest_nm, p.dist_flown_nm, fp.fp_altitude_ft, c.phase) < 7
              AND (c.last_trajectory_utc IS NULL OR DATEDIFF(SECOND, c.last_trajectory_utc, @now) >= dbo.fn_GetTierIntervalSeconds(ISNULL(c.last_trajectory_tier, 4)));
            SET @traj_count = @@ROWCOUNT;
            
            UPDATE c SET c.last_trajectory_tier = dbo.fn_GetTrajectoryTier(fp.fp_dept_icao, fp.fp_dest_icao, p.lat, p.lon, p.altitude_ft, p.groundspeed_kts, ISNULL(p.vertical_rate_fpm, 0), p.dist_to_dest_nm, p.dist_flown_nm, fp.fp_altitude_ft, c.phase), c.last_trajectory_utc = @now
            FROM dbo.adl_flight_core c
            JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
            JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
            WHERE c.is_active = 1 AND c.is_relevant = 1 AND (c.last_trajectory_utc IS NULL OR c.last_trajectory_utc < @now);
        END
        
        -- ETA calculation - now calls consolidated batch procedure
        IF @process_eta = 1
        BEGIN
            EXEC dbo.sp_CalculateETABatch @eta_count = @eta_count OUTPUT;
        END
    END
    
    PRINT 'Updated sp_ProcessTrajectoryBatch to use sp_CalculateETABatch';
END
GO

-- ============================================================================
-- STEP 4: Verification queries
-- ============================================================================

PRINT '';
PRINT '=== Verification Queries ===';
PRINT '';
PRINT '-- Check eta_method column:';
PRINT 'SELECT eta_method, COUNT(*) FROM dbo.adl_flight_times GROUP BY eta_method;';
PRINT '';
PRINT '-- Test batch ETA calculation:';
PRINT 'DECLARE @count INT;';
PRINT 'EXEC dbo.sp_CalculateETABatch @eta_count = @count OUTPUT, @debug = 1;';
PRINT 'SELECT @count AS flights_processed;';
PRINT '';
PRINT '-- Check ETA distribution by phase:';
PRINT 'SELECT c.phase, COUNT(*) AS flights, AVG(ft.eta_confidence) AS avg_conf';
PRINT 'FROM dbo.adl_flight_core c';
PRINT 'JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid';
PRINT 'WHERE c.is_active = 1';
PRINT 'GROUP BY c.phase ORDER BY flights DESC;';
GO

PRINT '';
PRINT '=== ETA Batch Consolidation Migration Complete ===';
PRINT 'Timestamp: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
