-- ============================================================================
-- sp_CalculateETABatch.sql (V3.5 - Segment Wind Integration)
-- Consolidated batch ETA calculation - Single Source of Truth
--
-- V3.5 Changes:
--   - Segment-based wind: Uses separate wind adjustments for climb/cruise/descent
--   - Reads eta_wind_climb_kts, eta_wind_cruise_kts, eta_wind_descent_kts
--   - Applies appropriate wind to each flight phase's time calculation
--   - More accurate ETA especially for climbing/descending flights
--   - Works with sp_UpdateFlightWindAdjustments_V2
--
-- V3.4 Changes:
--   - Integrated pre-calculated wind adjustment from separate wind process
--   - Cruise/enroute and climbing phases now use wind-adjusted effective speed
--   - Wind adjustment only applied when significant (>5 kts) to avoid noise
--   - Works with sp_UpdateFlightWindAdjustments for tiered wind calculation
--
-- V3.3 Changes:
--   - Fixed: dist_source was missing from #eta_results output
--   - This caused UPDATE/INSERT to fail when setting eta_dist_source
--
-- V3.2 Changes:
--   - Prefiles now get ETA calculations using GCD distance
--   - LEFT JOIN on position table allows flights without position
--   - Distance fallback chain: position dist → route dist → GCD
--
-- V3.1 Changes:
--   - Now sets eta_dist_source column in addition to eta_method
--   - Enables timing analysis to track GCD vs ROUTE usage
--
-- V3 Changes:
--   - Uses parsed route_dist_nm when available (more accurate than GCD)
--   - Falls back to gcd_nm when route not parsed
--   - Tracks distance source for analysis
--   - Method tracking: BATCH_V3_ROUTE, BATCH_V3_SB, BATCH_V3
--
-- V2 Changes:
--   - Uses SimBrief step climb data when available
--   - Uses final_alt_ft for more accurate TOD calculation
--   - Integrates planned cruise speed from step climbs
--
-- Features:
--   - Aircraft performance lookup via fn_GetAircraftPerformance
--   - Route distance integration (NEW in V3)
--   - Step climb speed/altitude integration
--   - TMI delay handling (EDCT/CTA)
--   - Segment wind integration (NEW in V3.5)
--   - Full phase handling
--   - TOD distance and ETA calculation
--   - Efficient set-based batch processing
--
-- Date: 2026-01-15
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
        -- V3.1: Handle NULL position data for prefiles
        COALESCE(p.altitude_ft, fp.fp_altitude_ft) AS altitude_ft,
        p.groundspeed_kts,  -- NULL for prefiles, handled in ETA calc
        -- V3.1: Use position distance, then route distance, then GCD
        COALESCE(p.dist_to_dest_nm, p.route_dist_to_dest_nm, fp.gcd_nm) AS dist_to_dest_nm,
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
        -- V3: Use route_dist_nm if available, else gcd_nm
        fp.route_dist_nm,
        fp.gcd_nm,
        COALESCE(fp.route_dist_nm, fp.gcd_nm) AS total_route_dist,
        CASE
            WHEN p.route_dist_to_dest_nm IS NOT NULL THEN 'ROUTE'
            WHEN fp.route_dist_nm IS NOT NULL THEN 'ROUTE'
            WHEN fp.gcd_nm IS NOT NULL THEN 'GCD'
            ELSE 'NONE'
        END AS dist_source,
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
        ft.eta_utc AS current_eta,
        -- V3.5: Segment wind adjustments
        ft.eta_wind_adj_kts AS precalc_wind_adj,
        ft.eta_wind_climb_kts AS wind_climb,
        ft.eta_wind_cruise_kts AS wind_cruise,
        ft.eta_wind_descent_kts AS wind_descent
    INTO #eta_work
    FROM dbo.adl_flight_core c
    LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid  -- V3.1: LEFT JOIN for prefiles
    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid
    LEFT JOIN dbo.apts apt ON apt.ICAO_ID = fp.fp_dest_icao
    LEFT JOIN dbo.adl_flight_tmi tmi ON tmi.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
    WHERE c.is_active = 1
      AND (p.lat IS NOT NULL OR c.phase = 'prefile');  -- V3.1: Include prefiles without position

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

        -- V3.5: Show wind coverage
        DECLARE @with_segment_wind INT, @with_any_wind INT;
        SELECT
            @with_segment_wind = COUNT(CASE WHEN wind_climb IS NOT NULL OR wind_descent IS NOT NULL THEN 1 END),
            @with_any_wind = COUNT(CASE WHEN precalc_wind_adj IS NOT NULL THEN 1 END)
        FROM #eta_work;
        PRINT '  Flights with segment winds: ' + CAST(@with_segment_wind AS VARCHAR);
        PRINT '  Flights with any wind: ' + CAST(@with_any_wind AS VARCHAR);
    END

    -- ========================================================================
    -- Step 4: Calculate ETA values (V3.5 with segment wind)
    -- ========================================================================
    IF @debug = 1
    BEGIN
        SET @step_time = SYSDATETIME();
        PRINT 'Step 4: Calculating ETAs with segment winds...';
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
            -- V3: Use total_route_dist instead of just gcd_nm
            w.total_route_dist,
            w.gcd_nm,
            w.dist_source,
            w.is_simbrief,
            w.stepclimb_count,
            w.speed_source,
            -- V3.5: Segment wind adjustments
            w.precalc_wind_adj,
            w.wind_climb,
            w.wind_cruise,
            w.wind_descent,

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

            -- V3.5: Calculate effective speeds with segment wind adjustments
            -- Climb: climb_speed + wind_climb (or wind_cruise as fallback for climbing flights)
            CASE
                WHEN e.wind_climb IS NOT NULL AND ABS(e.wind_climb) > 5
                THEN e.climb_speed + e.wind_climb
                WHEN e.wind_cruise IS NOT NULL AND ABS(e.wind_cruise) > 5
                THEN e.climb_speed + (e.wind_cruise * 0.5)  -- 50% of cruise wind during climb
                ELSE e.climb_speed
            END AS eff_climb_speed,

            -- Cruise: cruise_speed + wind_cruise (or precalc_wind_adj as fallback)
            CASE
                WHEN e.wind_cruise IS NOT NULL AND ABS(e.wind_cruise) > 5
                THEN e.cruise_speed + e.wind_cruise
                WHEN e.precalc_wind_adj IS NOT NULL AND ABS(e.precalc_wind_adj) > 5
                THEN e.cruise_speed + e.precalc_wind_adj
                ELSE e.cruise_speed
            END AS eff_cruise_speed,

            -- Descent: descent_speed + wind_descent (or wind_cruise * 0.7 as fallback)
            CASE
                WHEN e.wind_descent IS NOT NULL AND ABS(e.wind_descent) > 5
                THEN e.descent_speed + e.wind_descent
                WHEN e.wind_cruise IS NOT NULL AND ABS(e.wind_cruise) > 5
                THEN e.descent_speed + (e.wind_cruise * 0.7)  -- 70% of cruise wind during descent
                ELSE e.descent_speed
            END AS eff_descent_speed,

            -- Calculate time to destination using effective speeds
            CASE
                WHEN e.is_arrived = 1 THEN 0

                -- Final approach (use actual GS when close and descending)
                WHEN e.phase = 'descending' AND e.dist_to_dest_nm < 50 THEN
                    e.dist_to_dest_nm / NULLIF(e.groundspeed_kts, 0) * 60

                -- Descent phase: Apply descent wind
                WHEN e.phase = 'descending' THEN
                    e.dist_to_dest_nm / NULLIF(
                        CASE
                            WHEN e.wind_descent IS NOT NULL AND ABS(e.wind_descent) > 5
                            THEN e.descent_speed + e.wind_descent
                            WHEN e.wind_cruise IS NOT NULL AND ABS(e.wind_cruise) > 5
                            THEN e.descent_speed + (e.wind_cruise * 0.7)
                            ELSE e.descent_speed
                        END, 0) * 60

                -- Cruise/enroute: Apply cruise wind + descent wind
                WHEN e.phase IN ('enroute', 'cruise') THEN
                    CASE WHEN e.dist_to_dest_nm > e.tod_dist
                         -- Cruise portion with cruise wind
                         THEN (e.dist_to_dest_nm - e.tod_dist) / NULLIF(
                             CASE
                                 WHEN e.wind_cruise IS NOT NULL AND ABS(e.wind_cruise) > 5
                                 THEN e.cruise_speed + e.wind_cruise
                                 WHEN e.precalc_wind_adj IS NOT NULL AND ABS(e.precalc_wind_adj) > 5
                                 THEN e.cruise_speed + e.precalc_wind_adj
                                 ELSE e.cruise_speed
                             END, 0) * 60
                         ELSE 0
                    END
                    -- Descent portion with descent wind
                    + CASE WHEN e.dist_to_dest_nm > e.tod_dist
                           THEN e.tod_dist / NULLIF(
                               CASE
                                   WHEN e.wind_descent IS NOT NULL AND ABS(e.wind_descent) > 5
                                   THEN e.descent_speed + e.wind_descent
                                   WHEN e.wind_cruise IS NOT NULL AND ABS(e.wind_cruise) > 5
                                   THEN e.descent_speed + (e.wind_cruise * 0.7)
                                   ELSE e.descent_speed
                               END, 0) * 60
                           ELSE e.dist_to_dest_nm / NULLIF(
                               CASE
                                   WHEN e.wind_descent IS NOT NULL AND ABS(e.wind_descent) > 5
                                   THEN e.descent_speed + e.wind_descent
                                   ELSE e.descent_speed
                               END, 0) * 60
                    END

                -- Climbing: Apply climb wind + cruise wind + descent wind
                WHEN e.phase IN ('departed', 'climbing') THEN
                    -- Climb portion with climb wind
                    e.toc_dist / NULLIF(
                        CASE
                            WHEN e.wind_climb IS NOT NULL AND ABS(e.wind_climb) > 5
                            THEN e.climb_speed + e.wind_climb
                            WHEN e.wind_cruise IS NOT NULL AND ABS(e.wind_cruise) > 5
                            THEN e.climb_speed + (e.wind_cruise * 0.5)
                            ELSE e.climb_speed
                        END, 0) * 60
                    -- Cruise portion with cruise wind
                    + CASE WHEN (e.dist_to_dest_nm - e.toc_dist - e.tod_dist) > 0
                           THEN (e.dist_to_dest_nm - e.toc_dist - e.tod_dist) / NULLIF(
                               CASE
                                   WHEN e.wind_cruise IS NOT NULL AND ABS(e.wind_cruise) > 5
                                   THEN e.cruise_speed + e.wind_cruise
                                   WHEN e.precalc_wind_adj IS NOT NULL AND ABS(e.precalc_wind_adj) > 5
                                   THEN e.cruise_speed + e.precalc_wind_adj
                                   ELSE e.cruise_speed
                               END, 0) * 60
                           ELSE 0
                    END
                    -- Descent portion with descent wind
                    + e.tod_dist / NULLIF(
                        CASE
                            WHEN e.wind_descent IS NOT NULL AND ABS(e.wind_descent) > 5
                            THEN e.descent_speed + e.wind_descent
                            WHEN e.wind_cruise IS NOT NULL AND ABS(e.wind_cruise) > 5
                            THEN e.descent_speed + (e.wind_cruise * 0.7)
                            ELSE e.descent_speed
                        END, 0) * 60

                -- Taxiing (no wind adjustment for taxi/initial climb estimate)
                WHEN e.phase IN ('taxiing', 'taxi') THEN
                    12
                    + (e.filed_alt - e.dest_elev) / 1000.0 * 2.0 / NULLIF(e.climb_speed, 0) * 60
                    + CASE WHEN (e.dist_to_dest_nm - (e.filed_alt - e.dest_elev) / 1000.0 * 2.0 - e.tod_dist) > 0
                           THEN (e.dist_to_dest_nm - (e.filed_alt - e.dest_elev) / 1000.0 * 2.0 - e.tod_dist) / NULLIF(e.cruise_speed, 0) * 60
                           -- V3: Use total_route_dist as fallback
                           ELSE ISNULL(e.total_route_dist, 500) / NULLIF(e.cruise_speed, 0) * 60
                    END
                    + e.tod_dist / NULLIF(e.descent_speed, 0) * 60
                    + e.tmi_delay

                -- Pre-file: V3 uses total_route_dist (no wind - too early)
                ELSE
                    15
                    + ISNULL(e.dist_to_dest_nm, e.total_route_dist) / NULLIF(e.cruise_speed, 0) * 60 * 1.15
                    + e.tmi_delay

            END AS time_to_dest_min,

            -- V3.5: Higher confidence when segment winds available
            CASE
                WHEN e.is_arrived = 1 THEN 1.00
                WHEN e.phase = 'descending' AND e.dist_to_dest_nm < 50 THEN 0.95
                WHEN e.phase = 'descending' AND e.wind_descent IS NOT NULL THEN 0.94  -- Segment wind boost
                WHEN e.phase = 'descending' THEN 0.92
                WHEN e.phase IN ('enroute', 'cruise') AND e.is_simbrief = 1 AND e.stepclimb_count > 0 AND e.wind_cruise IS NOT NULL THEN 0.94
                WHEN e.phase IN ('enroute', 'cruise') AND e.wind_cruise IS NOT NULL THEN 0.92  -- Segment wind boost
                WHEN e.phase IN ('enroute', 'cruise') AND e.is_simbrief = 1 AND e.stepclimb_count > 0 THEN 0.92
                WHEN e.phase IN ('enroute', 'cruise') THEN 0.88
                WHEN e.phase IN ('climbing', 'departed') AND e.is_simbrief = 1 AND e.wind_climb IS NOT NULL THEN 0.88  -- Segment wind boost
                WHEN e.phase IN ('climbing', 'departed') AND e.wind_climb IS NOT NULL THEN 0.86
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

            -- Wind component (legacy - GS-based for display)
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
            et.dist_source,  -- V3: Track distance source
            et.wind_climb,
            et.wind_cruise,
            et.wind_descent,

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
        ef.dist_source,  -- V3.1: Include dist_source for UPDATE
        -- V3.5: Track method with segment wind indicator
        CASE
            WHEN ef.wind_climb IS NOT NULL OR ef.wind_descent IS NOT NULL THEN 'V35_SEG_WIND'
            WHEN ef.dist_source = 'ROUTE' AND ef.is_simbrief = 1 THEN 'V35_ROUTE_SB'
            WHEN ef.dist_source = 'ROUTE' THEN 'V35_ROUTE'
            WHEN ef.is_simbrief = 1 AND ef.stepclimb_count > 0 THEN 'V35_SB'
            WHEN ef.speed_source = 'SIMBRIEF_TAS' OR ef.speed_source = 'SIMBRIEF_MACH' THEN 'V35_SB'
            ELSE 'V35'
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
            THEN DATEDIFF_BIG(SECOND, '1970-01-01', r.final_eta)
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
        ft.eta_dist_source = r.dist_source,  -- V3.1: Also set eta_dist_source for analysis
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
        eta_dist_source,
        times_updated_utc
    )
    SELECT
        r.flight_uid,
        r.final_eta,
        r.final_eta,
        CASE WHEN r.final_eta IS NOT NULL
             THEN DATEDIFF_BIG(SECOND, '1970-01-01', r.final_eta)
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
        r.dist_source,
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

PRINT 'Created sp_CalculateETABatch V3.5 (Segment Wind Integration)';
PRINT '';
PRINT 'V3.5: Segment-based wind integration for improved ETA accuracy';
PRINT '      - Uses eta_wind_climb_kts for climb phase time calculation';
PRINT '      - Uses eta_wind_cruise_kts for cruise phase time calculation';
PRINT '      - Uses eta_wind_descent_kts for descent phase time calculation';
PRINT '      - Fallback logic: segment wind -> precalc_wind_adj -> no wind';
PRINT '      - Method tracking: V35_SEG_WIND when segment winds used';
PRINT '';
PRINT 'V3.4: Single weighted wind adjustment for cruise only';
PRINT 'V3.3: Fixed dist_source missing from #eta_results';
PRINT 'V3.2: Prefiles now get ETA calculations using GCD distance';
PRINT '';
GO
