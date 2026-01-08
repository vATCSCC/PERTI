-- ============================================================================
-- sp_CalculatePlannedCrossingsBatch
-- Version: 1.0
-- Date: 2026-01-07
-- Description: Tiered batch processing for planned crossing calculations
--
-- Tier Definitions:
--   1 = Every cycle (~15s): New flights + regional with recalc flag
--   2 = 1 min: Regional + airborne + in TRACON
--   3 = 2 min: Regional + in ARTCC (not TRACON)
--   4 = 5 min: Regional + level flight
--   5 = 10 min: International (not regional)
--   6 = 30 min: Transit-only (overflights)
--   7 = 60 min: Wholly outside region
--
-- Performance Target: <10s for typical batch of 100-200 flights
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_CalculatePlannedCrossingsBatch
    @max_flights_per_batch INT = 500,
    @debug BIT = 0
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @now DATETIME2(0) = GETUTCDATE();
    DECLARE @cycle INT = DATEPART(SECOND, @now) / 15;  -- 0-3
    DECLARE @minute INT = DATEPART(MINUTE, @now);
    DECLARE @start_time DATETIME2(3) = SYSDATETIME();

    DECLARE @tier1_count INT = 0, @tier2_count INT = 0, @tier3_count INT = 0;
    DECLARE @tier4_count INT = 0, @tier5_count INT = 0, @tier6_count INT = 0;
    DECLARE @tier7_count INT = 0;
    DECLARE @total_crossings INT = 0;

    -- ========================================================================
    -- Create batch table for flights to process this cycle
    -- ========================================================================
    CREATE TABLE #batch_flights (
        flight_uid      BIGINT PRIMARY KEY,
        tier            TINYINT NOT NULL,
        crossing_order  INT IDENTITY(1,1)  -- Process order
    );

    -- ========================================================================
    -- TIER 1: New flights + regional with needs_recalc flag (EVERY cycle)
    -- ========================================================================
    INSERT INTO #batch_flights (flight_uid, tier)
    SELECT TOP (@max_flights_per_batch)
        c.flight_uid,
        1
    FROM dbo.adl_flight_core c
    WHERE c.is_active = 1
      AND (
          c.crossing_last_calc_utc IS NULL           -- Never calculated
          OR c.crossing_needs_recalc = 1             -- Event-triggered recalc
      )
      AND c.crossing_region_flags IS NOT NULL        -- Region detection complete
    ORDER BY
        c.crossing_needs_recalc DESC,                -- Prioritize event-triggered
        c.crossing_region_flags DESC,                -- Then regional flights
        c.first_seen_utc DESC;                       -- Then newest flights

    SET @tier1_count = @@ROWCOUNT;

    -- ========================================================================
    -- TIER 2: Regional + airborne + in TRACON (every 1 min)
    -- Process at start of each minute (cycle 0)
    -- ========================================================================
    IF @cycle = 0
    BEGIN
        INSERT INTO #batch_flights (flight_uid, tier)
        SELECT TOP (@max_flights_per_batch - (SELECT COUNT(*) FROM #batch_flights))
            c.flight_uid,
            2
        FROM dbo.adl_flight_core c
        LEFT JOIN #batch_flights b ON b.flight_uid = c.flight_uid
        WHERE c.is_active = 1
          AND b.flight_uid IS NULL                   -- Not already in batch
          AND c.crossing_region_flags > 0            -- Regional flight
          AND c.lifecycle_state IN ('departed', 'enroute', 'descending')
          AND c.current_tracon IS NOT NULL           -- In TRACON airspace
          AND (c.crossing_last_calc_utc IS NULL
               OR DATEDIFF(SECOND, c.crossing_last_calc_utc, @now) >= 60);

        SET @tier2_count = @@ROWCOUNT;
    END

    -- ========================================================================
    -- TIER 3: Regional + in ARTCC not TRACON (every 2 min)
    -- Process on even minutes
    -- ========================================================================
    IF @minute % 2 = 0 AND @cycle = 0
    BEGIN
        INSERT INTO #batch_flights (flight_uid, tier)
        SELECT TOP (@max_flights_per_batch - (SELECT COUNT(*) FROM #batch_flights))
            c.flight_uid,
            3
        FROM dbo.adl_flight_core c
        LEFT JOIN #batch_flights b ON b.flight_uid = c.flight_uid
        WHERE c.is_active = 1
          AND b.flight_uid IS NULL
          AND c.crossing_region_flags > 0            -- Regional flight
          AND c.lifecycle_state IN ('departed', 'enroute', 'descending')
          AND c.current_artcc IS NOT NULL
          AND c.current_tracon IS NULL               -- NOT in TRACON (en route)
          AND (c.crossing_last_calc_utc IS NULL
               OR DATEDIFF(SECOND, c.crossing_last_calc_utc, @now) >= 120);

        SET @tier3_count = @@ROWCOUNT;
    END

    -- ========================================================================
    -- TIER 4: Regional + level flight (every 5 min)
    -- Process at 0, 5, 10, 15... minutes
    -- ========================================================================
    IF @minute % 5 = 0 AND @cycle = 0
    BEGIN
        INSERT INTO #batch_flights (flight_uid, tier)
        SELECT TOP (@max_flights_per_batch - (SELECT COUNT(*) FROM #batch_flights))
            c.flight_uid,
            4
        FROM dbo.adl_flight_core c
        LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
        LEFT JOIN #batch_flights b ON b.flight_uid = c.flight_uid
        WHERE c.is_active = 1
          AND b.flight_uid IS NULL
          AND c.crossing_region_flags > 0
          AND c.lifecycle_state = 'enroute'
          AND c.level_flight_confirmed = 1           -- Confirmed level flight
          AND ABS(ISNULL(p.vertical_rate_fpm, 0)) < 200
          AND (c.crossing_last_calc_utc IS NULL
               OR DATEDIFF(SECOND, c.crossing_last_calc_utc, @now) >= 300);

        SET @tier4_count = @@ROWCOUNT;
    END

    -- ========================================================================
    -- TIER 5: International non-regional (every 10 min)
    -- ========================================================================
    IF @minute % 10 = 0 AND @cycle = 0
    BEGIN
        INSERT INTO #batch_flights (flight_uid, tier)
        SELECT TOP (@max_flights_per_batch - (SELECT COUNT(*) FROM #batch_flights))
            c.flight_uid,
            5
        FROM dbo.adl_flight_core c
        LEFT JOIN #batch_flights b ON b.flight_uid = c.flight_uid
        WHERE c.is_active = 1
          AND b.flight_uid IS NULL
          AND c.crossing_region_flags = 0            -- NOT regional
          AND c.crossing_region_flags IS NOT NULL    -- But has been evaluated
          AND c.lifecycle_state IN ('departed', 'enroute', 'descending')
          AND (c.crossing_last_calc_utc IS NULL
               OR DATEDIFF(SECOND, c.crossing_last_calc_utc, @now) >= 600);

        SET @tier5_count = @@ROWCOUNT;
    END

    -- ========================================================================
    -- TIER 6: Transit-only overflights (every 30 min)
    -- ========================================================================
    IF @minute % 30 = 0 AND @cycle = 0
    BEGIN
        INSERT INTO #batch_flights (flight_uid, tier)
        SELECT TOP (@max_flights_per_batch - (SELECT COUNT(*) FROM #batch_flights))
            c.flight_uid,
            6
        FROM dbo.adl_flight_core c
        LEFT JOIN #batch_flights b ON b.flight_uid = c.flight_uid
        WHERE c.is_active = 1
          AND b.flight_uid IS NULL
          AND c.crossing_region_flags = 4            -- Transit only (bit 3)
          AND c.lifecycle_state IN ('departed', 'enroute', 'descending')
          AND (c.crossing_last_calc_utc IS NULL
               OR DATEDIFF(SECOND, c.crossing_last_calc_utc, @now) >= 1800);

        SET @tier6_count = @@ROWCOUNT;
    END

    -- ========================================================================
    -- TIER 7: Wholly outside region (every 60 min)
    -- ========================================================================
    IF @minute = 0 AND @cycle = 0
    BEGIN
        INSERT INTO #batch_flights (flight_uid, tier)
        SELECT TOP (@max_flights_per_batch - (SELECT COUNT(*) FROM #batch_flights))
            c.flight_uid,
            7
        FROM dbo.adl_flight_core c
        LEFT JOIN #batch_flights b ON b.flight_uid = c.flight_uid
        WHERE c.is_active = 1
          AND b.flight_uid IS NULL
          AND c.crossing_region_flags = 0
          AND c.crossing_region_flags IS NOT NULL
          AND (c.crossing_last_calc_utc IS NULL
               OR DATEDIFF(SECOND, c.crossing_last_calc_utc, @now) >= 3600);

        SET @tier7_count = @@ROWCOUNT;
    END

    -- ========================================================================
    -- PROCESS BATCH: Calculate crossings for each flight
    -- Using set-based approach for maximum performance
    -- ========================================================================
    DECLARE @batch_count INT = (SELECT COUNT(*) FROM #batch_flights);

    IF @batch_count > 0
    BEGIN
        -- Process each flight in the batch
        DECLARE @flight_uid BIGINT, @tier TINYINT;
        DECLARE @crossing_result INT;

        DECLARE batch_cursor CURSOR LOCAL FAST_FORWARD FOR
            SELECT flight_uid, tier
            FROM #batch_flights
            ORDER BY crossing_order;

        OPEN batch_cursor;
        FETCH NEXT FROM batch_cursor INTO @flight_uid, @tier;

        WHILE @@FETCH_STATUS = 0
        BEGIN
            -- Calculate crossings for this flight
            EXEC dbo.sp_CalculatePlannedCrossings
                @flight_uid = @flight_uid,
                @tier = @tier;

            FETCH NEXT FROM batch_cursor INTO @flight_uid, @tier;
        END

        CLOSE batch_cursor;
        DEALLOCATE batch_cursor;

        -- Get total crossings calculated
        SELECT @total_crossings = COUNT(*)
        FROM dbo.adl_flight_planned_crossings
        WHERE calculated_at >= @start_time;
    END

    -- ========================================================================
    -- OUTPUT: Processing statistics
    -- ========================================================================
    IF @debug = 1
    BEGIN
        DECLARE @elapsed_ms INT = DATEDIFF(MILLISECOND, @start_time, SYSDATETIME());

        SELECT
            @now AS processed_at,
            @cycle AS cycle,
            @minute AS minute,
            @tier1_count AS tier1_new_recalc,
            @tier2_count AS tier2_tracon,
            @tier3_count AS tier3_artcc,
            @tier4_count AS tier4_level,
            @tier5_count AS tier5_intl,
            @tier6_count AS tier6_transit,
            @tier7_count AS tier7_outside,
            @batch_count AS total_flights,
            @total_crossings AS crossings_calculated,
            @elapsed_ms AS elapsed_ms;
    END

    DROP TABLE #batch_flights;
END
GO

PRINT 'Created procedure: sp_CalculatePlannedCrossingsBatch';
GO
