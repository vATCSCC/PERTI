-- ============================================================================
-- FIX COLUMN NAMES - Correct schema references
-- Run after DEPLOY_ALL_CROSSINGS.sql to fix column name mismatches
-- ============================================================================

SET NOCOUNT ON;
PRINT 'Fixing column name mismatches...';
GO

-- ============================================================================
-- FIX 1: Index on phase (the actual column name in adl_flight_core)
-- Note: The column is "phase" NOT "flight_phase" - flight_phase is on trajectory table
-- ============================================================================
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_flight_crossing_regional' AND object_id = OBJECT_ID('dbo.adl_flight_core'))
BEGIN
    CREATE INDEX IX_flight_crossing_regional ON dbo.adl_flight_core(crossing_region_flags, phase)
        INCLUDE (current_artcc, current_tracon, level_flight_confirmed)
        WHERE is_active = 1 AND crossing_region_flags > 0;
    PRINT 'Created index: IX_flight_crossing_regional';
END
GO

-- ============================================================================
-- FIX 2: Views - Join to adl_flight_plan for dept/dest/aircraft_type
-- ============================================================================

CREATE OR ALTER VIEW dbo.vw_flights_crossing_boundary
AS
SELECT
    c.boundary_code,
    c.boundary_type,
    c.flight_uid,
    f.callsign,
    p.fp_dept_icao AS dept,
    p.fp_dest_icao AS dest,
    p.aircraft_type,
    c.crossing_order,
    c.entry_fix_name,
    c.exit_fix_name,
    c.planned_entry_utc,
    c.planned_exit_utc,
    DATEDIFF(MINUTE, c.planned_entry_utc, c.planned_exit_utc) AS transit_minutes,
    DATEDIFF(MINUTE, GETUTCDATE(), c.planned_entry_utc) AS minutes_until_entry,
    c.entry_lat,
    c.entry_lon
FROM dbo.adl_flight_planned_crossings c
JOIN dbo.adl_flight_core f ON f.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_plan p ON p.flight_uid = c.flight_uid
WHERE c.planned_entry_utc >= GETUTCDATE()
  AND f.is_active = 1;
GO
PRINT 'Fixed view: vw_flights_crossing_boundary';
GO

CREATE OR ALTER VIEW dbo.vw_flights_crossing_element
AS
SELECT
    e.element_id,
    e.element_name,
    e.element_type,
    e.category,
    c.flight_uid,
    f.callsign,
    p.fp_dept_icao AS dept,
    p.fp_dest_icao AS dest,
    c.crossing_type,
    c.planned_entry_utc,
    c.planned_exit_utc,
    DATEDIFF(MINUTE, GETUTCDATE(), c.planned_entry_utc) AS minutes_until_entry
FROM dbo.adl_flight_planned_crossings c
JOIN dbo.adl_airspace_element e ON e.element_id = c.element_id
JOIN dbo.adl_flight_core f ON f.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_plan p ON p.flight_uid = c.flight_uid
WHERE c.element_id IS NOT NULL
  AND c.planned_entry_utc >= GETUTCDATE()
  AND f.is_active = 1
  AND e.is_active = 1;
GO
PRINT 'Fixed view: vw_flights_crossing_element';
GO

CREATE OR ALTER VIEW dbo.vw_flight_route_crossings
AS
SELECT
    c.flight_uid,
    f.callsign,
    p.fp_dept_icao AS dept,
    p.fp_dest_icao AS dest,
    c.crossing_order,
    c.boundary_type,
    c.boundary_code,
    c.crossing_type,
    c.entry_fix_name,
    c.exit_fix_name,
    c.planned_entry_utc,
    c.planned_exit_utc,
    DATEDIFF(MINUTE, c.planned_entry_utc, c.planned_exit_utc) AS transit_minutes,
    c.entry_lat,
    c.entry_lon
FROM dbo.adl_flight_planned_crossings c
JOIN dbo.adl_flight_core f ON f.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_plan p ON p.flight_uid = c.flight_uid
WHERE f.is_active = 1;
GO
PRINT 'Fixed view: vw_flight_route_crossings';
GO

-- ============================================================================
-- FIX 3: sp_DetectRegionalFlight - Use adl_flight_plan for dept/dest
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_DetectRegionalFlight
    @flight_uid BIGINT = NULL,
    @batch_mode BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @region_id INT = (SELECT region_id FROM dbo.adl_region_group WHERE region_code = 'US_CA_MX_LATAM_CAR');
    IF @region_id IS NULL RETURN;

    IF @batch_mode = 1
    BEGIN
        -- Batch mode: update all flights with NULL region flags
        UPDATE c
        SET c.crossing_region_flags =
            CASE WHEN EXISTS (
                SELECT 1 FROM dbo.adl_region_airports a
                WHERE a.region_id = @region_id
                  AND (
                      p.fp_dept_icao LIKE a.icao_prefix + '%'
                      OR (a.icao_prefix = 'K' AND p.fp_dept_icao LIKE 'K%' AND LEN(p.fp_dept_icao) = 4)
                  )
            ) THEN 1 ELSE 0 END
            |
            CASE WHEN EXISTS (
                SELECT 1 FROM dbo.adl_region_airports a
                WHERE a.region_id = @region_id
                  AND (
                      p.fp_dest_icao LIKE a.icao_prefix + '%'
                      OR (a.icao_prefix = 'K' AND p.fp_dest_icao LIKE 'K%' AND LEN(p.fp_dest_icao) = 4)
                  )
            ) THEN 2 ELSE 0 END
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_plan p ON p.flight_uid = c.flight_uid
        WHERE c.is_active = 1
          AND c.crossing_region_flags IS NULL;

        SELECT @@ROWCOUNT AS flights_updated;
    END
    ELSE IF @flight_uid IS NOT NULL
    BEGIN
        -- Single flight mode
        UPDATE c
        SET c.crossing_region_flags =
            CASE WHEN EXISTS (
                SELECT 1 FROM dbo.adl_region_airports a
                WHERE a.region_id = @region_id
                  AND (
                      p.fp_dept_icao LIKE a.icao_prefix + '%'
                      OR (a.icao_prefix = 'K' AND p.fp_dept_icao LIKE 'K%' AND LEN(p.fp_dept_icao) = 4)
                  )
            ) THEN 1 ELSE 0 END
            |
            CASE WHEN EXISTS (
                SELECT 1 FROM dbo.adl_region_airports a
                WHERE a.region_id = @region_id
                  AND (
                      p.fp_dest_icao LIKE a.icao_prefix + '%'
                      OR (a.icao_prefix = 'K' AND p.fp_dest_icao LIKE 'K%' AND LEN(p.fp_dest_icao) = 4)
                  )
            ) THEN 2 ELSE 0 END
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_plan p ON p.flight_uid = c.flight_uid
        WHERE c.flight_uid = @flight_uid;

        SELECT @@ROWCOUNT AS flights_updated;
    END
END
GO
PRINT 'Fixed procedure: sp_DetectRegionalFlight';
GO

-- ============================================================================
-- FIX 4: sp_UpdateLevelFlightStatus - Use phase (not lifecycle_state or flight_phase)
-- Note: The column is "phase" in adl_flight_core
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_UpdateLevelFlightStatus
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @level_threshold INT = 200;     -- fpm threshold for level flight
    DECLARE @required_samples INT = 3;       -- Consecutive samples needed

    -- Detect current vertical phase for all active flights
    ;WITH FlightPhases AS (
        SELECT
            c.flight_uid,
            c.level_flight_samples,
            c.level_flight_confirmed,
            c.last_vertical_phase,
            CASE
                WHEN p.vertical_rate_fpm > @level_threshold THEN 'C'      -- Climbing
                WHEN p.vertical_rate_fpm < -@level_threshold THEN 'D'     -- Descending
                ELSE 'L'                                                   -- Level
            END AS current_phase,
            ABS(ISNULL(p.vertical_rate_fpm, 0)) AS abs_vrate
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
        WHERE c.is_active = 1
          AND c.phase IN ('departed', 'enroute', 'descending', 'climbing', 'cruise')
    )
    UPDATE c
    SET
        -- Increment or reset level flight samples
        c.level_flight_samples = CASE
            WHEN fp.current_phase = 'L' THEN
                CASE WHEN c.level_flight_samples < 255
                     THEN c.level_flight_samples + 1
                     ELSE 255 END
            ELSE 0  -- Reset when not level
        END,

        -- Update phase tracking
        c.last_vertical_phase = fp.current_phase,

        -- Confirm level flight after threshold AND trigger recalc
        c.level_flight_confirmed = CASE
            WHEN fp.current_phase = 'L'
                 AND c.level_flight_samples >= @required_samples - 1
                 AND c.level_flight_confirmed = 0
            THEN 1
            WHEN fp.current_phase != 'L'
            THEN 0
            ELSE c.level_flight_confirmed
        END,

        -- Trigger recalc when level flight is newly confirmed
        c.crossing_needs_recalc = CASE
            WHEN fp.current_phase = 'L'
                 AND c.level_flight_samples >= @required_samples - 1
                 AND c.level_flight_confirmed = 0
                 AND c.last_vertical_phase IN ('C', 'D')
            THEN 1
            ELSE c.crossing_needs_recalc
        END
    FROM dbo.adl_flight_core c
    JOIN FlightPhases fp ON fp.flight_uid = c.flight_uid;

    SELECT @@ROWCOUNT AS flights_updated;
END
GO
PRINT 'Fixed procedure: sp_UpdateLevelFlightStatus';
GO

-- ============================================================================
-- FIX 5: sp_CalculatePlannedCrossingsBatch - Use phase (not flight_phase)
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

    -- Create batch table
    CREATE TABLE #batch_flights (
        flight_uid      BIGINT PRIMARY KEY,
        tier            TINYINT NOT NULL,
        crossing_order  INT IDENTITY(1,1)
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
          c.crossing_last_calc_utc IS NULL
          OR c.crossing_needs_recalc = 1
      )
      AND c.crossing_region_flags IS NOT NULL
    ORDER BY
        c.crossing_needs_recalc DESC,
        c.crossing_region_flags DESC,
        c.first_seen_utc DESC;

    SET @tier1_count = @@ROWCOUNT;

    -- ========================================================================
    -- TIER 2: Regional + airborne + in TRACON (every 1 min)
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
          AND b.flight_uid IS NULL
          AND c.crossing_region_flags > 0
          AND c.phase IN ('departed', 'enroute', 'descending', 'climbing', 'cruise')
          AND c.current_tracon IS NOT NULL
          AND (c.crossing_last_calc_utc IS NULL
               OR DATEDIFF(SECOND, c.crossing_last_calc_utc, @now) >= 60);

        SET @tier2_count = @@ROWCOUNT;
    END

    -- ========================================================================
    -- TIER 3: Regional + in ARTCC not TRACON (every 2 min)
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
          AND c.crossing_region_flags > 0
          AND c.phase IN ('departed', 'enroute', 'descending', 'climbing', 'cruise')
          AND c.current_artcc IS NOT NULL
          AND c.current_tracon IS NULL
          AND (c.crossing_last_calc_utc IS NULL
               OR DATEDIFF(SECOND, c.crossing_last_calc_utc, @now) >= 120);

        SET @tier3_count = @@ROWCOUNT;
    END

    -- ========================================================================
    -- TIER 4: Regional + level flight (every 5 min)
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
          AND c.phase IN ('enroute', 'cruise')
          AND c.level_flight_confirmed = 1
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
          AND c.crossing_region_flags = 0
          AND c.crossing_region_flags IS NOT NULL
          AND c.phase IN ('departed', 'enroute', 'descending', 'climbing', 'cruise')
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
          AND c.crossing_region_flags = 4
          AND c.phase IN ('departed', 'enroute', 'descending', 'climbing', 'cruise')
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
    -- PROCESS BATCH
    -- ========================================================================
    DECLARE @batch_count INT = (SELECT COUNT(*) FROM #batch_flights);

    IF @batch_count > 0
    BEGIN
        DECLARE @flight_uid BIGINT, @tier TINYINT;

        DECLARE batch_cursor CURSOR LOCAL FAST_FORWARD FOR
            SELECT flight_uid, tier
            FROM #batch_flights
            ORDER BY crossing_order;

        OPEN batch_cursor;
        FETCH NEXT FROM batch_cursor INTO @flight_uid, @tier;

        WHILE @@FETCH_STATUS = 0
        BEGIN
            EXEC dbo.sp_CalculatePlannedCrossings
                @flight_uid = @flight_uid,
                @tier = @tier;

            FETCH NEXT FROM batch_cursor INTO @flight_uid, @tier;
        END

        CLOSE batch_cursor;
        DEALLOCATE batch_cursor;

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
PRINT 'Fixed procedure: sp_CalculatePlannedCrossingsBatch';
GO

-- ============================================================================
PRINT '';
PRINT 'All fixes applied successfully!';
PRINT 'Time: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
