-- ============================================================================
-- sp_CalculateWaypointETABatch_Tiered.sql (V1.0)
-- Tiered waypoint ETA calculation for scalable processing
--
-- Purpose:
--   - Calculates ETA at each waypoint along the route
--   - Uses tiered processing to prioritize flights needing immediate updates
--   - Designed to run in separate daemon, not main ADL refresh
--
-- Tiers (lower = more frequent):
--   Tier 0: Flights within 30nm of next waypoint (imminent crossing)
--   Tier 1: Enroute flights > 30nm but < 100nm from next waypoint
--   Tier 2: All other active enroute flights
--   Tier 3: Climbing/descending flights
--   Tier 4: Prefiles and taxiing (lowest priority)
--
-- Performance:
--   - Uses dist_to_next_waypoint_nm from adl_flight_position (pre-calculated)
--   - Processes only flights in requested tier
--   - Uses batch limits to prevent runaway queries
--
-- Date: 2026-01-16
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID('dbo.sp_CalculateWaypointETABatch_Tiered', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_CalculateWaypointETABatch_Tiered;
GO

CREATE PROCEDURE dbo.sp_CalculateWaypointETABatch_Tiered
    @tier TINYINT = NULL,           -- NULL = all tiers, 0-4 = specific tier
    @max_flights INT = 500,         -- Max flights to process per call
    @waypoint_count INT = NULL OUTPUT,
    @flights_processed INT = NULL OUTPUT,
    @debug BIT = 0
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @start_time DATETIME2(3) = SYSDATETIME();

    SET @waypoint_count = 0;
    SET @flights_processed = 0;

    -- ========================================================================
    -- Step 1: Select flights to process with tier assignment
    -- Uses dist_to_next_waypoint_nm from position table (already calculated)
    -- ========================================================================
    IF @debug = 1
        PRINT 'Step 1: Building tiered flight context...';

    DROP TABLE IF EXISTS #flight_context;

    SELECT TOP (@max_flights)
        c.flight_uid,
        c.phase,
        p.dist_flown_nm,
        p.groundspeed_kts,
        p.dist_to_next_waypoint_nm,
        -- Effective speed: use groundspeed if reasonable, else estimate
        CASE
            WHEN p.groundspeed_kts > 50 AND p.groundspeed_kts < 700 THEN p.groundspeed_kts
            WHEN c.phase IN ('enroute', 'cruise', 'descending') THEN 450
            WHEN c.phase IN ('climbing', 'departed') THEN 380
            ELSE 350
        END AS effective_speed_kts,
        -- Assign tier based on urgency
        CASE
            WHEN ISNULL(p.dist_to_next_waypoint_nm, 999) <= 30 THEN 0              -- Imminent waypoint crossing
            WHEN ISNULL(p.dist_to_next_waypoint_nm, 999) <= 100 AND c.phase = 'enroute' THEN 1  -- Approaching waypoint
            WHEN c.phase = 'enroute' THEN 2                                         -- Enroute, not urgent
            WHEN c.phase IN ('climbing', 'departed', 'descending') THEN 3           -- Transitioning
            ELSE 4                                                                  -- Prefile/taxiing
        END AS calc_tier
    INTO #flight_context
    FROM dbo.adl_flight_core c
    INNER JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    WHERE c.is_active = 1
      AND c.phase NOT IN ('arrived')
      -- Only process flights that have waypoints
      AND EXISTS (SELECT 1 FROM dbo.adl_flight_waypoints w WHERE w.flight_uid = c.flight_uid)
      -- Filter by tier if specified
      AND (@tier IS NULL OR
           CASE
               WHEN ISNULL(p.dist_to_next_waypoint_nm, 999) <= 30 THEN 0
               WHEN ISNULL(p.dist_to_next_waypoint_nm, 999) <= 100 AND c.phase = 'enroute' THEN 1
               WHEN c.phase = 'enroute' THEN 2
               WHEN c.phase IN ('climbing', 'departed', 'descending') THEN 3
               ELSE 4
           END <= @tier)  -- Process requested tier and all higher-priority tiers
    ORDER BY
        -- Prioritize by tier, then by proximity to next waypoint
        CASE
            WHEN ISNULL(p.dist_to_next_waypoint_nm, 999) <= 30 THEN 0
            WHEN ISNULL(p.dist_to_next_waypoint_nm, 999) <= 100 AND c.phase = 'enroute' THEN 1
            WHEN c.phase = 'enroute' THEN 2
            WHEN c.phase IN ('climbing', 'departed', 'descending') THEN 3
            ELSE 4
        END,
        p.dist_to_next_waypoint_nm;

    SELECT @flights_processed = COUNT(*) FROM #flight_context;

    IF @debug = 1
    BEGIN
        PRINT '  Flights selected: ' + CAST(@flights_processed AS VARCHAR);
        SELECT calc_tier, COUNT(*) AS cnt FROM #flight_context GROUP BY calc_tier ORDER BY calc_tier;
    END

    IF @flights_processed = 0
    BEGIN
        IF @debug = 1 PRINT '  No flights to process';
        DROP TABLE IF EXISTS #flight_context;
        RETURN;
    END

    -- ========================================================================
    -- Step 2: Calculate waypoint ETAs for selected flights
    -- ========================================================================
    IF @debug = 1
        PRINT 'Step 2: Calculating waypoint ETAs...';

    DROP TABLE IF EXISTS #waypoint_etas;

    SELECT
        w.waypoint_id,
        w.flight_uid,
        w.sequence_num,
        w.cum_dist_nm,
        fc.dist_flown_nm,
        fc.effective_speed_kts,

        -- Distance remaining to this waypoint
        CASE
            WHEN w.cum_dist_nm > ISNULL(fc.dist_flown_nm, 0)
            THEN w.cum_dist_nm - ISNULL(fc.dist_flown_nm, 0)
            ELSE 0
        END AS dist_remaining_nm,

        -- Is waypoint ahead?
        CASE WHEN w.cum_dist_nm > ISNULL(fc.dist_flown_nm, 0) THEN 1 ELSE 0 END AS is_ahead,

        -- Calculate ETA
        CASE
            WHEN w.cum_dist_nm <= ISNULL(fc.dist_flown_nm, 0) THEN NULL  -- Passed
            WHEN fc.effective_speed_kts < 50 THEN NULL                   -- Invalid speed
            ELSE DATEADD(
                SECOND,
                CAST((w.cum_dist_nm - ISNULL(fc.dist_flown_nm, 0)) / fc.effective_speed_kts * 3600 AS INT),
                @now
            )
        END AS calc_eta_utc

    INTO #waypoint_etas
    FROM dbo.adl_flight_waypoints w
    INNER JOIN #flight_context fc ON fc.flight_uid = w.flight_uid
    WHERE w.cum_dist_nm IS NOT NULL;  -- Only waypoints with distance data

    -- ========================================================================
    -- Step 3: Update waypoint table
    -- ========================================================================
    IF @debug = 1
        PRINT 'Step 3: Updating waypoints...';

    -- Update ETAs for waypoints ahead
    UPDATE w
    SET w.eta_utc = we.calc_eta_utc
    FROM dbo.adl_flight_waypoints w
    INNER JOIN #waypoint_etas we ON we.waypoint_id = w.waypoint_id
    WHERE we.is_ahead = 1 AND we.calc_eta_utc IS NOT NULL;

    SET @waypoint_count = @@ROWCOUNT;

    -- Clear ETAs for passed waypoints
    UPDATE w
    SET w.eta_utc = NULL
    FROM dbo.adl_flight_waypoints w
    INNER JOIN #waypoint_etas we ON we.waypoint_id = w.waypoint_id
    WHERE we.is_ahead = 0 AND w.eta_utc IS NOT NULL;

    -- ========================================================================
    -- Cleanup and return
    -- ========================================================================
    DROP TABLE IF EXISTS #flight_context;
    DROP TABLE IF EXISTS #waypoint_etas;

    DECLARE @elapsed_ms INT = DATEDIFF(MILLISECOND, @start_time, SYSDATETIME());

    IF @debug = 1
    BEGIN
        PRINT '  Waypoints updated: ' + CAST(@waypoint_count AS VARCHAR);
        PRINT '  Duration: ' + CAST(@elapsed_ms AS VARCHAR) + 'ms';
    END

    -- Return stats
    SELECT
        @flights_processed AS flights_processed,
        @waypoint_count AS waypoints_updated,
        @elapsed_ms AS elapsed_ms,
        @tier AS tier_requested;
END
GO

PRINT 'Created sp_CalculateWaypointETABatch_Tiered V1.0';
PRINT 'Tiers: 0=imminent (<30nm), 1=approaching (<100nm), 2=enroute, 3=transitioning, 4=ground';
GO
