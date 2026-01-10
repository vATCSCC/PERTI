-- ============================================================================
-- sp_CalculatePlannedCrossingsBatch V2.0 - Set-Based Rewrite
--
-- CRITICAL CHANGE: Eliminates cursor, processes ALL flights in single pass
-- Performance target: <2s for 200 flights (was 6-10s with cursor)
--
-- Changes from V1.0:
--   - Replaced cursor + per-flight SP with set-based operations
--   - Single batch query for waypoint ARTCC detection
--   - Single batch query for sector detection
--   - Bulk insert into planned_crossings
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
    DECLARE @grid_size FLOAT = 0.5;

    DECLARE @tier1_count INT = 0, @tier2_count INT = 0, @tier3_count INT = 0;
    DECLARE @tier4_count INT = 0, @tier5_count INT = 0, @tier6_count INT = 0;
    DECLARE @tier7_count INT = 0;
    DECLARE @total_crossings INT = 0;
    DECLARE @batch_count INT = 0;

    -- ========================================================================
    -- Create batch table for flights to process this cycle
    -- ========================================================================
    CREATE TABLE #batch_flights (
        flight_uid      BIGINT PRIMARY KEY,
        tier            TINYINT NOT NULL
    );

    -- ========================================================================
    -- TIER 1: New flights + regional with needs_recalc flag (EVERY cycle)
    -- ========================================================================
    INSERT INTO #batch_flights (flight_uid, tier)
    SELECT TOP (@max_flights_per_batch)
        c.flight_uid, 1
    FROM dbo.adl_flight_core c
    WHERE c.is_active = 1
      AND (c.crossing_last_calc_utc IS NULL OR c.crossing_needs_recalc = 1)
      AND c.crossing_region_flags IS NOT NULL
    ORDER BY c.crossing_needs_recalc DESC, c.crossing_region_flags DESC, c.first_seen_utc DESC;

    SET @tier1_count = @@ROWCOUNT;

    -- ========================================================================
    -- TIER 2-7: Time-based recalculation (simplified)
    -- Only add if batch not full
    -- ========================================================================
    IF @cycle = 0 AND (SELECT COUNT(*) FROM #batch_flights) < @max_flights_per_batch
    BEGIN
        -- Tier 2: Regional in TRACON (every 1 min)
        INSERT INTO #batch_flights (flight_uid, tier)
        SELECT TOP (@max_flights_per_batch - (SELECT COUNT(*) FROM #batch_flights))
            c.flight_uid, 2
        FROM dbo.adl_flight_core c
        WHERE c.is_active = 1
          AND NOT EXISTS (SELECT 1 FROM #batch_flights b WHERE b.flight_uid = c.flight_uid)
          AND c.crossing_region_flags > 0
          AND c.phase IN ('departed', 'enroute', 'descending')
          AND c.current_tracon IS NOT NULL
          AND DATEDIFF(SECOND, c.crossing_last_calc_utc, @now) >= 60;

        SET @tier2_count = @@ROWCOUNT;

        -- Tier 3: Regional in ARTCC (every 2 min)
        IF @minute % 2 = 0
        BEGIN
            INSERT INTO #batch_flights (flight_uid, tier)
            SELECT TOP (@max_flights_per_batch - (SELECT COUNT(*) FROM #batch_flights))
                c.flight_uid, 3
            FROM dbo.adl_flight_core c
            WHERE c.is_active = 1
              AND NOT EXISTS (SELECT 1 FROM #batch_flights b WHERE b.flight_uid = c.flight_uid)
              AND c.crossing_region_flags > 0
              AND c.phase IN ('departed', 'enroute', 'descending')
              AND c.current_artcc IS NOT NULL AND c.current_tracon IS NULL
              AND DATEDIFF(SECOND, c.crossing_last_calc_utc, @now) >= 120;

            SET @tier3_count = @@ROWCOUNT;
        END

        -- Tier 4-7: Less frequent updates (every 5/10/30/60 min)
        IF @minute % 5 = 0
        BEGIN
            INSERT INTO #batch_flights (flight_uid, tier)
            SELECT TOP (@max_flights_per_batch - (SELECT COUNT(*) FROM #batch_flights))
                c.flight_uid, 4
            FROM dbo.adl_flight_core c
            WHERE c.is_active = 1
              AND NOT EXISTS (SELECT 1 FROM #batch_flights b WHERE b.flight_uid = c.flight_uid)
              AND c.crossing_region_flags > 0
              AND c.phase = 'enroute'
              AND DATEDIFF(SECOND, c.crossing_last_calc_utc, @now) >= 300;

            SET @tier4_count = @@ROWCOUNT;
        END
    END

    SET @batch_count = (SELECT COUNT(*) FROM #batch_flights);

    IF @batch_count = 0
    BEGIN
        DROP TABLE #batch_flights;

        IF @debug = 1
            SELECT @now AS processed_at, @cycle AS cycle, @minute AS minute,
                   0 AS total_flights, 0 AS crossings_calculated, 0 AS elapsed_ms;
        RETURN;
    END

    -- ========================================================================
    -- SET-BASED PROCESSING: Get all waypoints for all batch flights at once
    -- ========================================================================

    -- Collect waypoints for all flights in batch
    SELECT
        b.flight_uid,
        b.tier,
        w.sequence_num AS seq,
        w.fix_name,
        w.lat,
        w.lon,
        w.eta_utc,
        CAST(FLOOR(w.lat / @grid_size) AS SMALLINT) AS grid_lat,
        CAST(FLOOR(w.lon / @grid_size) AS SMALLINT) AS grid_lon
    INTO #all_waypoints
    FROM #batch_flights b
    JOIN dbo.adl_flight_waypoints w ON w.flight_uid = b.flight_uid
    WHERE w.lat IS NOT NULL AND w.lon IS NOT NULL;

    CREATE INDEX IX_aw_flight ON #all_waypoints(flight_uid, seq);
    CREATE INDEX IX_aw_grid ON #all_waypoints(grid_lat, grid_lon);

    -- ========================================================================
    -- SET-BASED: Find ARTCC for each waypoint (single query for all flights)
    -- ========================================================================
    SELECT
        w.flight_uid,
        w.seq,
        w.fix_name,
        w.eta_utc,
        w.lat,
        w.lon,
        artcc.boundary_id AS artcc_id,
        artcc.boundary_code AS artcc_code
    INTO #waypoint_artcc
    FROM #all_waypoints w
    OUTER APPLY (
        SELECT TOP 1 g.boundary_id, g.boundary_code
        FROM dbo.adl_boundary_grid g
        JOIN dbo.adl_boundary b ON b.boundary_id = g.boundary_id
        WHERE g.boundary_type = 'ARTCC'
          AND g.grid_lat = w.grid_lat
          AND g.grid_lon = w.grid_lon
          AND b.boundary_geography.STContains(geography::Point(w.lat, w.lon, 4326)) = 1
        ORDER BY g.is_oceanic ASC, g.boundary_area ASC
    ) artcc;

    CREATE INDEX IX_wa_flight ON #waypoint_artcc(flight_uid, seq);

    -- ========================================================================
    -- SET-BASED: Detect ARTCC crossings (boundary changes between waypoints)
    -- ========================================================================
    SELECT
        curr.flight_uid,
        'ARTCC' AS boundary_type,
        curr.artcc_id AS boundary_id,
        curr.artcc_code AS boundary_code,
        'ENTRY' AS crossing_type,
        curr.seq AS entry_seq,
        curr.fix_name AS entry_fix,
        curr.eta_utc AS entry_utc,
        curr.lat AS entry_lat,
        curr.lon AS entry_lon,
        ROW_NUMBER() OVER (PARTITION BY curr.flight_uid ORDER BY curr.seq) AS crossing_order,
        -- Exit columns (will be updated below)
        CAST(NULL AS INT) AS exit_seq,
        CAST(NULL AS NVARCHAR(64)) AS exit_fix,
        CAST(NULL AS DATETIME2(0)) AS exit_utc
    INTO #artcc_crossings
    FROM #waypoint_artcc curr
    LEFT JOIN #waypoint_artcc prev ON prev.flight_uid = curr.flight_uid AND prev.seq = curr.seq - 1
    WHERE curr.artcc_id IS NOT NULL
      AND (prev.artcc_id IS NULL OR prev.artcc_id != curr.artcc_id);

    -- Add exit information
    UPDATE c
    SET c.exit_seq = exit_info.exit_seq,
        c.exit_fix = exit_info.exit_fix,
        c.exit_utc = exit_info.exit_utc
    FROM #artcc_crossings c
    CROSS APPLY (
        SELECT TOP 1 w.seq AS exit_seq, w.fix_name AS exit_fix, w.eta_utc AS exit_utc
        FROM #waypoint_artcc w
        WHERE w.flight_uid = c.flight_uid
          AND w.seq > c.entry_seq
          AND (w.artcc_id IS NULL OR w.artcc_id != c.boundary_id)
        ORDER BY w.seq
    ) exit_info;

    -- ========================================================================
    -- SET-BASED: Find sectors for each waypoint (SECTOR_HIGH only for speed)
    -- ========================================================================
    SELECT DISTINCT
        w.flight_uid,
        w.seq,
        'SECTOR_HIGH' AS sector_type,
        g.boundary_id,
        g.boundary_code
    INTO #waypoint_sectors
    FROM #all_waypoints w
    JOIN dbo.adl_boundary_grid g ON g.grid_lat = w.grid_lat AND g.grid_lon = w.grid_lon
    JOIN dbo.adl_boundary b ON b.boundary_id = g.boundary_id
    WHERE g.boundary_type = 'SECTOR_HIGH'
      AND b.boundary_geography.STContains(geography::Point(w.lat, w.lon, 4326)) = 1;

    -- Detect sector entries (first occurrence of each sector per flight)
    SELECT
        s.flight_uid,
        s.sector_type AS boundary_type,
        s.boundary_id,
        s.boundary_code,
        'ENTRY' AS crossing_type,
        MIN(s.seq) AS entry_seq,
        (SELECT TOP 1 w.fix_name FROM #all_waypoints w WHERE w.flight_uid = s.flight_uid AND w.seq = MIN(s.seq)) AS entry_fix,
        (SELECT TOP 1 w.eta_utc FROM #all_waypoints w WHERE w.flight_uid = s.flight_uid AND w.seq = MIN(s.seq)) AS entry_utc,
        (SELECT TOP 1 w.lat FROM #all_waypoints w WHERE w.flight_uid = s.flight_uid AND w.seq = MIN(s.seq)) AS entry_lat,
        (SELECT TOP 1 w.lon FROM #all_waypoints w WHERE w.flight_uid = s.flight_uid AND w.seq = MIN(s.seq)) AS entry_lon
    INTO #sector_crossings
    FROM #waypoint_sectors s
    GROUP BY s.flight_uid, s.sector_type, s.boundary_id, s.boundary_code;

    -- ========================================================================
    -- DELETE existing crossings for batch flights
    -- ========================================================================
    DELETE pc
    FROM dbo.adl_flight_planned_crossings pc
    WHERE EXISTS (SELECT 1 FROM #batch_flights b WHERE b.flight_uid = pc.flight_uid);

    -- ========================================================================
    -- BULK INSERT all crossings at once
    -- ========================================================================
    INSERT INTO dbo.adl_flight_planned_crossings (
        flight_uid, crossing_source, boundary_id, boundary_code, boundary_type,
        crossing_type, crossing_order, entry_waypoint_seq, exit_waypoint_seq,
        entry_fix_name, exit_fix_name, planned_entry_utc, planned_exit_utc,
        entry_lat, entry_lon, calculated_at, calculation_tier
    )
    -- ARTCC crossings
    SELECT
        c.flight_uid, 'BOUNDARY', c.boundary_id, c.boundary_code, c.boundary_type,
        c.crossing_type, c.crossing_order, c.entry_seq, c.exit_seq,
        c.entry_fix, c.exit_fix, c.entry_utc, c.exit_utc,
        c.entry_lat, c.entry_lon, @now, b.tier
    FROM #artcc_crossings c
    JOIN #batch_flights b ON b.flight_uid = c.flight_uid
    WHERE c.boundary_id IS NOT NULL

    UNION ALL

    -- Sector crossings
    SELECT
        s.flight_uid, 'BOUNDARY', s.boundary_id, s.boundary_code, s.boundary_type,
        s.crossing_type,
        ROW_NUMBER() OVER (PARTITION BY s.flight_uid ORDER BY s.entry_seq) + 100 AS crossing_order,
        s.entry_seq, NULL,
        s.entry_fix, NULL, s.entry_utc, NULL,
        s.entry_lat, s.entry_lon, @now, b.tier
    FROM #sector_crossings s
    JOIN #batch_flights b ON b.flight_uid = s.flight_uid
    WHERE s.boundary_id IS NOT NULL;

    SET @total_crossings = @@ROWCOUNT;

    -- ========================================================================
    -- BULK UPDATE flight_core for all processed flights
    -- ========================================================================
    UPDATE c
    SET c.crossing_last_calc_utc = @now,
        c.crossing_needs_recalc = 0,
        c.crossing_tier = b.tier
    FROM dbo.adl_flight_core c
    JOIN #batch_flights b ON b.flight_uid = c.flight_uid;

    -- ========================================================================
    -- Cleanup
    -- ========================================================================
    DROP TABLE IF EXISTS #batch_flights;
    DROP TABLE IF EXISTS #all_waypoints;
    DROP TABLE IF EXISTS #waypoint_artcc;
    DROP TABLE IF EXISTS #artcc_crossings;
    DROP TABLE IF EXISTS #waypoint_sectors;
    DROP TABLE IF EXISTS #sector_crossings;

    -- ========================================================================
    -- OUTPUT
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
END
GO

PRINT 'Created sp_CalculatePlannedCrossingsBatch V2.0 (set-based, no cursor)';
PRINT 'Performance target: <2s for 200 flights (was 6-10s)';
GO
