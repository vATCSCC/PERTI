-- ============================================================================
-- sp_ProcessBoundaryAndCrossings_Background
-- Version: 1.7
-- Date: 2026-01-10
--
-- Description: Background job for boundary detection and planned crossings
--              Runs separately from main refresh cycle (every 60 seconds)
--              Uses tiered processing to spread load across time
--
-- V1.7 Changes:
--   - Added SECTOR_LOW, SECTOR_HIGH, SECTOR_SUPERHIGH detection (was missing!)
--   - Added ARTCC fallback for grid lookup failures
--   - Full boundary detection now matches sp_ProcessBoundaryDetectionBatch
--
-- V1.6 Optimizations:
--   - Removed fallback query (scanned all boundaries - too slow)
--   - Set-based JOIN with ROW_NUMBER instead of OUTER APPLY
--   - Skip TRACON for flights >= FL180 earlier in the query
--   - Fixed: V1.5 incorrectly used STContains (doesn't exist for geography)
--   - Uses STIntersects for geography point-in-polygon checks
--
-- Tier Schedule:
--   Tier 1: Every 1 min  - New flights, needs_recalc flag
--   Tier 2: Every 2 min  - Regional in TRACON
--   Tier 3: Every 5 min  - Regional in ARTCC only
--   Tier 4: Every 10 min - Enroute flights
--   Tier 5: Every 15 min - International flights
--   Tier 6: Every 30 min - Transit flights
--   Tier 7: Every 60 min - Outside region / catch-all
--
-- Usage: Called by SQL Agent job or separate PHP daemon every 60 seconds
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

CREATE OR ALTER PROCEDURE dbo.sp_ProcessBoundaryAndCrossings_Background
    @max_flights_per_run INT = 100,
    @max_crossings_per_run INT = 50,
    @debug BIT = 0
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @start_time DATETIME2(3) = SYSUTCDATETIME();
    DECLARE @now DATETIME2(0) = @start_time;
    DECLARE @minute INT = DATEPART(MINUTE, @now);
    DECLARE @grid_size DECIMAL(5,3) = 0.5;

    -- US CONUS bounding box (for sector detection)
    DECLARE @us_lat_min DECIMAL(6,2) = 24.0;
    DECLARE @us_lat_max DECIMAL(6,2) = 50.0;
    DECLARE @us_lon_min DECIMAL(7,2) = -130.0;
    DECLARE @us_lon_max DECIMAL(7,2) = -65.0;

    -- Counters
    DECLARE @boundary_flights INT = 0;
    DECLARE @boundary_transitions INT = 0;
    DECLARE @crossings_calculated INT = 0;
    DECLARE @tier1 INT = 0, @tier2 INT = 0, @tier3 INT = 0, @tier4 INT = 0;
    DECLARE @tier5 INT = 0, @tier6 INT = 0, @tier7 INT = 0;

    -- ========================================================================
    -- PART A: BOUNDARY DETECTION (Optimized V1.5)
    -- ========================================================================

    -- Step A1: Find flights needing boundary detection
    SELECT TOP (@max_flights_per_run)
        c.flight_uid,
        p.lat,
        p.lon,
        p.altitude_ft,
        c.current_artcc,
        c.current_artcc_id,
        c.current_tracon,
        c.current_tracon_id,
        c.current_sector_low,
        c.current_sector_low_ids,
        c.current_sector_high,
        c.current_sector_high_ids,
        c.current_sector_superhigh,
        c.current_sector_superhigh_ids,
        CAST(FLOOR(p.lat / @grid_size) AS SMALLINT) AS grid_lat,
        CAST(FLOOR(p.lon / @grid_size) AS SMALLINT) AS grid_lon,
        geography::Point(p.lat, p.lon, 4326) AS position_geo,
        -- CONUS flag for sector detection
        CASE WHEN p.lat BETWEEN @us_lat_min AND @us_lat_max
              AND p.lon BETWEEN @us_lon_min AND @us_lon_max
             THEN 1 ELSE 0 END AS is_conus
    INTO #boundary_flights
    FROM dbo.adl_flight_core c
    JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    WHERE c.is_active = 1
      AND p.lat IS NOT NULL
      AND p.lat BETWEEN -90 AND 90
      AND p.lon BETWEEN -180 AND 180
      AND (
          c.current_artcc_id IS NULL
          OR c.last_grid_lat IS NULL
          OR c.last_grid_lat != CAST(FLOOR(p.lat / @grid_size) AS SMALLINT)
          OR c.last_grid_lon != CAST(FLOOR(p.lon / @grid_size) AS SMALLINT)
      )
    ORDER BY
        CASE WHEN c.current_artcc_id IS NULL THEN 0 ELSE 1 END,
        ISNULL(c.boundary_updated_at, '1900-01-01') ASC;

    SET @boundary_flights = (SELECT COUNT(*) FROM #boundary_flights);

    IF @boundary_flights > 0
    BEGIN
        CREATE INDEX IX_bf_grid ON #boundary_flights(grid_lat, grid_lon);
        CREATE INDEX IX_bf_uid ON #boundary_flights(flight_uid);

        -- Step A2: ARTCC Detection (Set-based with ROW_NUMBER - faster than OUTER APPLY)
        ;WITH artcc_matches AS (
            SELECT
                f.flight_uid,
                g.boundary_id,
                g.boundary_code,
                ROW_NUMBER() OVER (
                    PARTITION BY f.flight_uid
                    ORDER BY g.is_oceanic ASC, g.boundary_area ASC
                ) AS rn
            FROM #boundary_flights f
            JOIN dbo.adl_boundary_grid g
                ON g.grid_lat = f.grid_lat
                AND g.grid_lon = f.grid_lon
                AND g.boundary_type = 'ARTCC'
            JOIN dbo.adl_boundary b ON b.boundary_id = g.boundary_id
            WHERE b.boundary_geography.STIntersects(f.position_geo) = 1
        )
        SELECT
            f.flight_uid, f.lat, f.lon, f.altitude_ft, f.grid_lat, f.grid_lon, f.is_conus,
            f.current_artcc AS prev_artcc, f.current_artcc_id AS prev_artcc_id,
            am.boundary_id AS new_artcc_id, am.boundary_code AS new_artcc
        INTO #artcc_detection
        FROM #boundary_flights f
        LEFT JOIN artcc_matches am ON am.flight_uid = f.flight_uid AND am.rn = 1;

        -- Step A2b: ARTCC Fallback - Brute force for grid lookup failures (V1.7)
        -- When grid lookup fails (boundary crosses cell edge), fall back to direct spatial lookup
        UPDATE a
        SET a.new_artcc_id = fallback.boundary_id,
            a.new_artcc = fallback.boundary_code
        FROM #artcc_detection a
        CROSS APPLY (
            SELECT TOP 1 b.boundary_id, b.boundary_code
            FROM dbo.adl_boundary b
            WHERE b.boundary_type = 'ARTCC'
              AND b.is_active = 1
              AND b.boundary_geography.STIntersects(geography::Point(a.lat, a.lon, 4326)) = 1
            ORDER BY b.is_oceanic ASC, b.boundary_geography.STArea() ASC
        ) fallback
        WHERE a.new_artcc_id IS NULL
          AND a.prev_artcc_id IS NULL;  -- Only for flights that truly have no ARTCC

        -- Step A3: TRACON Detection (only for flights < FL180)
        ;WITH tracon_matches AS (
            SELECT
                f.flight_uid,
                g.boundary_id,
                g.boundary_code,
                ROW_NUMBER() OVER (
                    PARTITION BY f.flight_uid
                    ORDER BY g.boundary_area ASC
                ) AS rn
            FROM #boundary_flights f
            JOIN dbo.adl_boundary_grid g
                ON g.grid_lat = f.grid_lat
                AND g.grid_lon = f.grid_lon
                AND g.boundary_type = 'TRACON'
            JOIN dbo.adl_boundary b ON b.boundary_id = g.boundary_id
            WHERE f.altitude_ft < 18000
              AND b.boundary_geography.STIntersects(f.position_geo) = 1
        )
        SELECT
            f.flight_uid, f.lat, f.lon, f.altitude_ft,
            f.current_tracon AS prev_tracon, f.current_tracon_id AS prev_tracon_id,
            tm.boundary_id AS new_tracon_id, tm.boundary_code AS new_tracon
        INTO #tracon_detection
        FROM #boundary_flights f
        LEFT JOIN tracon_matches tm ON tm.flight_uid = f.flight_uid AND tm.rn = 1
        WHERE f.altitude_ft < 18000;

        -- ========================================================================
        -- Step A4: SECTOR Detection (CONUS only) - V1.7
        -- ========================================================================

        -- SECTOR_LOW (altitude < 24000)
        SELECT DISTINCT f.flight_uid, g.boundary_id, g.boundary_code
        INTO #low_sectors_raw
        FROM #boundary_flights f
        JOIN dbo.adl_boundary_grid g ON
            g.boundary_type = 'SECTOR_LOW'
            AND g.grid_lat = f.grid_lat
            AND g.grid_lon = f.grid_lon
        JOIN dbo.adl_boundary b ON b.boundary_id = g.boundary_id
        WHERE f.is_conus = 1
          AND f.altitude_ft < 24000
          AND b.boundary_geography.STIntersects(f.position_geo) = 1;

        SELECT
            f.flight_uid, f.lat, f.lon, f.altitude_ft,
            f.current_sector_low AS prev_sector_low,
            STUFF((SELECT ',' + ls.boundary_code FROM #low_sectors_raw ls
                   WHERE ls.flight_uid = f.flight_uid ORDER BY ls.boundary_code FOR XML PATH('')), 1, 1, '') AS new_sector_low,
            (SELECT ls.boundary_id AS id FROM #low_sectors_raw ls
             WHERE ls.flight_uid = f.flight_uid ORDER BY ls.boundary_code FOR JSON PATH) AS new_sector_low_ids
        INTO #low_detection
        FROM #boundary_flights f
        WHERE f.is_conus = 1 AND f.altitude_ft < 24000;

        -- SECTOR_HIGH (altitude 10000-60000)
        SELECT DISTINCT f.flight_uid, g.boundary_id, g.boundary_code
        INTO #high_sectors_raw
        FROM #boundary_flights f
        JOIN dbo.adl_boundary_grid g ON
            g.boundary_type = 'SECTOR_HIGH'
            AND g.grid_lat = f.grid_lat
            AND g.grid_lon = f.grid_lon
        JOIN dbo.adl_boundary b ON b.boundary_id = g.boundary_id
        WHERE f.is_conus = 1
          AND f.altitude_ft >= 10000 AND f.altitude_ft < 60000
          AND b.boundary_geography.STIntersects(f.position_geo) = 1;

        SELECT
            f.flight_uid, f.lat, f.lon, f.altitude_ft,
            f.current_sector_high AS prev_sector_high,
            STUFF((SELECT ',' + hs.boundary_code FROM #high_sectors_raw hs
                   WHERE hs.flight_uid = f.flight_uid ORDER BY hs.boundary_code FOR XML PATH('')), 1, 1, '') AS new_sector_high,
            (SELECT hs.boundary_id AS id FROM #high_sectors_raw hs
             WHERE hs.flight_uid = f.flight_uid ORDER BY hs.boundary_code FOR JSON PATH) AS new_sector_high_ids
        INTO #high_detection
        FROM #boundary_flights f
        WHERE f.is_conus = 1 AND f.altitude_ft >= 10000;

        -- SECTOR_SUPERHIGH (altitude >= 35000)
        SELECT DISTINCT f.flight_uid, g.boundary_id, g.boundary_code
        INTO #superhigh_sectors_raw
        FROM #boundary_flights f
        JOIN dbo.adl_boundary_grid g ON
            g.boundary_type = 'SECTOR_SUPERHIGH'
            AND g.grid_lat = f.grid_lat
            AND g.grid_lon = f.grid_lon
        JOIN dbo.adl_boundary b ON b.boundary_id = g.boundary_id
        WHERE f.is_conus = 1
          AND f.altitude_ft >= 35000
          AND b.boundary_geography.STIntersects(f.position_geo) = 1;

        SELECT
            f.flight_uid, f.lat, f.lon, f.altitude_ft,
            f.current_sector_superhigh AS prev_sector_superhigh,
            STUFF((SELECT ',' + sh.boundary_code FROM #superhigh_sectors_raw sh
                   WHERE sh.flight_uid = f.flight_uid ORDER BY sh.boundary_code FOR XML PATH('')), 1, 1, '') AS new_sector_superhigh,
            (SELECT sh.boundary_id AS id FROM #superhigh_sectors_raw sh
             WHERE sh.flight_uid = f.flight_uid ORDER BY sh.boundary_code FOR JSON PATH) AS new_sector_superhigh_ids
        INTO #superhigh_detection
        FROM #boundary_flights f
        WHERE f.is_conus = 1 AND f.altitude_ft >= 35000;

        -- Step A5: Log ARTCC transitions (exit old boundary)
        UPDATE log
        SET exit_time = @now, exit_lat = a.lat, exit_lon = a.lon,
            exit_altitude = a.altitude_ft, duration_seconds = DATEDIFF(SECOND, log.entry_time, @now)
        FROM dbo.adl_flight_boundary_log log
        JOIN #artcc_detection a ON log.flight_uid = a.flight_uid
        WHERE log.boundary_type = 'ARTCC' AND log.exit_time IS NULL
          AND log.boundary_id = a.prev_artcc_id
          AND (a.new_artcc_id IS NULL OR a.new_artcc_id != a.prev_artcc_id);

        -- Log ARTCC entry (new boundary)
        INSERT INTO dbo.adl_flight_boundary_log
            (flight_uid, boundary_id, boundary_type, boundary_code, entry_time, entry_lat, entry_lon, entry_altitude)
        SELECT a.flight_uid, a.new_artcc_id, 'ARTCC', a.new_artcc, @now, a.lat, a.lon, a.altitude_ft
        FROM #artcc_detection a
        WHERE a.new_artcc_id IS NOT NULL
          AND (a.prev_artcc_id IS NULL OR a.new_artcc_id != a.prev_artcc_id);

        SET @boundary_transitions = @boundary_transitions + @@ROWCOUNT;

        -- Step A5: Log TRACON transitions
        UPDATE log
        SET exit_time = @now, exit_lat = t.lat, exit_lon = t.lon,
            exit_altitude = t.altitude_ft, duration_seconds = DATEDIFF(SECOND, log.entry_time, @now)
        FROM dbo.adl_flight_boundary_log log
        JOIN #tracon_detection t ON log.flight_uid = t.flight_uid
        WHERE log.boundary_type = 'TRACON' AND log.exit_time IS NULL
          AND log.boundary_id = t.prev_tracon_id
          AND (t.new_tracon_id IS NULL OR t.new_tracon_id != t.prev_tracon_id);

        INSERT INTO dbo.adl_flight_boundary_log
            (flight_uid, boundary_id, boundary_type, boundary_code, entry_time, entry_lat, entry_lon, entry_altitude)
        SELECT t.flight_uid, t.new_tracon_id, 'TRACON', t.new_tracon, @now, t.lat, t.lon, t.altitude_ft
        FROM #tracon_detection t
        WHERE t.new_tracon_id IS NOT NULL
          AND (t.prev_tracon_id IS NULL OR t.new_tracon_id != t.prev_tracon_id);

        SET @boundary_transitions = @boundary_transitions + @@ROWCOUNT;

        -- Step A6: Log SECTOR transitions (V1.7)
        -- SECTOR_LOW exits
        UPDATE log SET exit_time = @now, exit_lat = f.lat, exit_lon = f.lon,
            exit_altitude = f.altitude_ft, duration_seconds = DATEDIFF(SECOND, log.entry_time, @now)
        FROM dbo.adl_flight_boundary_log log
        JOIN #low_detection f ON log.flight_uid = f.flight_uid
        WHERE log.boundary_type = 'SECTOR_LOW' AND log.exit_time IS NULL
          AND NOT EXISTS (SELECT 1 FROM #low_sectors_raw ls WHERE ls.flight_uid = log.flight_uid AND ls.boundary_id = log.boundary_id);

        -- SECTOR_LOW entries
        INSERT INTO dbo.adl_flight_boundary_log (flight_uid, boundary_id, boundary_type, boundary_code, entry_time, entry_lat, entry_lon, entry_altitude)
        SELECT ls.flight_uid, ls.boundary_id, 'SECTOR_LOW', ls.boundary_code, @now, f.lat, f.lon, f.altitude_ft
        FROM #low_sectors_raw ls JOIN #low_detection f ON f.flight_uid = ls.flight_uid
        WHERE NOT EXISTS (SELECT 1 FROM dbo.adl_flight_boundary_log log WHERE log.flight_uid = ls.flight_uid AND log.boundary_id = ls.boundary_id AND log.exit_time IS NULL);

        SET @boundary_transitions = @boundary_transitions + @@ROWCOUNT;

        -- SECTOR_HIGH exits
        UPDATE log SET exit_time = @now, exit_lat = f.lat, exit_lon = f.lon,
            exit_altitude = f.altitude_ft, duration_seconds = DATEDIFF(SECOND, log.entry_time, @now)
        FROM dbo.adl_flight_boundary_log log
        JOIN #high_detection f ON log.flight_uid = f.flight_uid
        WHERE log.boundary_type = 'SECTOR_HIGH' AND log.exit_time IS NULL
          AND NOT EXISTS (SELECT 1 FROM #high_sectors_raw hs WHERE hs.flight_uid = log.flight_uid AND hs.boundary_id = log.boundary_id);

        -- SECTOR_HIGH entries
        INSERT INTO dbo.adl_flight_boundary_log (flight_uid, boundary_id, boundary_type, boundary_code, entry_time, entry_lat, entry_lon, entry_altitude)
        SELECT hs.flight_uid, hs.boundary_id, 'SECTOR_HIGH', hs.boundary_code, @now, f.lat, f.lon, f.altitude_ft
        FROM #high_sectors_raw hs JOIN #high_detection f ON f.flight_uid = hs.flight_uid
        WHERE NOT EXISTS (SELECT 1 FROM dbo.adl_flight_boundary_log log WHERE log.flight_uid = hs.flight_uid AND log.boundary_id = hs.boundary_id AND log.exit_time IS NULL);

        SET @boundary_transitions = @boundary_transitions + @@ROWCOUNT;

        -- SECTOR_SUPERHIGH exits
        UPDATE log SET exit_time = @now, exit_lat = f.lat, exit_lon = f.lon,
            exit_altitude = f.altitude_ft, duration_seconds = DATEDIFF(SECOND, log.entry_time, @now)
        FROM dbo.adl_flight_boundary_log log
        JOIN #superhigh_detection f ON log.flight_uid = f.flight_uid
        WHERE log.boundary_type = 'SECTOR_SUPERHIGH' AND log.exit_time IS NULL
          AND NOT EXISTS (SELECT 1 FROM #superhigh_sectors_raw sh WHERE sh.flight_uid = log.flight_uid AND sh.boundary_id = log.boundary_id);

        -- SECTOR_SUPERHIGH entries
        INSERT INTO dbo.adl_flight_boundary_log (flight_uid, boundary_id, boundary_type, boundary_code, entry_time, entry_lat, entry_lon, entry_altitude)
        SELECT sh.flight_uid, sh.boundary_id, 'SECTOR_SUPERHIGH', sh.boundary_code, @now, f.lat, f.lon, f.altitude_ft
        FROM #superhigh_sectors_raw sh JOIN #superhigh_detection f ON f.flight_uid = sh.flight_uid
        WHERE NOT EXISTS (SELECT 1 FROM dbo.adl_flight_boundary_log log WHERE log.flight_uid = sh.flight_uid AND log.boundary_id = sh.boundary_id AND log.exit_time IS NULL);

        SET @boundary_transitions = @boundary_transitions + @@ROWCOUNT;

        -- Step A7: Update flight_core with new boundaries
        UPDATE c
        SET c.current_artcc = a.new_artcc,
            c.current_artcc_id = a.new_artcc_id,
            c.last_grid_lat = a.grid_lat,
            c.last_grid_lon = a.grid_lon,
            c.boundary_updated_at = @now
        FROM dbo.adl_flight_core c
        JOIN #artcc_detection a ON a.flight_uid = c.flight_uid;

        UPDATE c
        SET c.current_tracon = t.new_tracon, c.current_tracon_id = t.new_tracon_id
        FROM dbo.adl_flight_core c
        JOIN #tracon_detection t ON t.flight_uid = c.flight_uid;

        -- Clear TRACON for high-altitude flights
        UPDATE c
        SET c.current_tracon = NULL, c.current_tracon_id = NULL
        FROM dbo.adl_flight_core c
        JOIN #boundary_flights f ON f.flight_uid = c.flight_uid
        WHERE f.altitude_ft >= 18000 AND c.current_tracon_id IS NOT NULL;

        -- Sector updates (V1.7)
        UPDATE c SET c.current_sector_low = l.new_sector_low, c.current_sector_low_ids = l.new_sector_low_ids
        FROM dbo.adl_flight_core c JOIN #low_detection l ON l.flight_uid = c.flight_uid;

        UPDATE c SET c.current_sector_high = h.new_sector_high, c.current_sector_high_ids = h.new_sector_high_ids
        FROM dbo.adl_flight_core c JOIN #high_detection h ON h.flight_uid = c.flight_uid;

        UPDATE c SET c.current_sector_superhigh = s.new_sector_superhigh, c.current_sector_superhigh_ids = s.new_sector_superhigh_ids
        FROM dbo.adl_flight_core c JOIN #superhigh_detection s ON s.flight_uid = c.flight_uid;

        -- Cleanup temp tables
        DROP TABLE IF EXISTS #artcc_detection;
        DROP TABLE IF EXISTS #tracon_detection;
        DROP TABLE IF EXISTS #low_sectors_raw;
        DROP TABLE IF EXISTS #low_detection;
        DROP TABLE IF EXISTS #high_sectors_raw;
        DROP TABLE IF EXISTS #high_detection;
        DROP TABLE IF EXISTS #superhigh_sectors_raw;
        DROP TABLE IF EXISTS #superhigh_detection;
    END

    DROP TABLE IF EXISTS #boundary_flights;

    -- ========================================================================
    -- PART B: PLANNED CROSSINGS CALCULATION (tiered)
    -- ========================================================================

    CREATE TABLE #crossing_batch (
        flight_uid BIGINT PRIMARY KEY,
        tier TINYINT NOT NULL
    );

    -- Tier 1: Every 1 min - New flights + needs_recalc (ALWAYS)
    INSERT INTO #crossing_batch (flight_uid, tier)
    SELECT TOP (@max_crossings_per_run)
        c.flight_uid, 1
    FROM dbo.adl_flight_core c
    WHERE c.is_active = 1
      AND c.crossing_region_flags IS NOT NULL
      AND (c.crossing_last_calc_utc IS NULL OR c.crossing_needs_recalc = 1)
    ORDER BY c.crossing_needs_recalc DESC, c.first_seen_utc DESC;

    SET @tier1 = @@ROWCOUNT;

    -- Tier 2: Every 2 min - Regional in TRACON
    IF @minute % 2 = 0 AND (SELECT COUNT(*) FROM #crossing_batch) < @max_crossings_per_run
    BEGIN
        INSERT INTO #crossing_batch (flight_uid, tier)
        SELECT TOP (@max_crossings_per_run - (SELECT COUNT(*) FROM #crossing_batch))
            c.flight_uid, 2
        FROM dbo.adl_flight_core c
        WHERE c.is_active = 1
          AND NOT EXISTS (SELECT 1 FROM #crossing_batch b WHERE b.flight_uid = c.flight_uid)
          AND c.crossing_region_flags > 0
          AND c.phase IN ('departed', 'enroute', 'descending')
          AND c.current_tracon IS NOT NULL
          AND DATEDIFF(SECOND, c.crossing_last_calc_utc, @now) >= 120;

        SET @tier2 = @@ROWCOUNT;
    END

    -- Tier 3: Every 5 min - Regional in ARTCC only
    IF @minute % 5 = 0 AND (SELECT COUNT(*) FROM #crossing_batch) < @max_crossings_per_run
    BEGIN
        INSERT INTO #crossing_batch (flight_uid, tier)
        SELECT TOP (@max_crossings_per_run - (SELECT COUNT(*) FROM #crossing_batch))
            c.flight_uid, 3
        FROM dbo.adl_flight_core c
        WHERE c.is_active = 1
          AND NOT EXISTS (SELECT 1 FROM #crossing_batch b WHERE b.flight_uid = c.flight_uid)
          AND c.crossing_region_flags > 0
          AND c.phase IN ('departed', 'enroute', 'descending')
          AND c.current_artcc IS NOT NULL AND c.current_tracon IS NULL
          AND DATEDIFF(SECOND, c.crossing_last_calc_utc, @now) >= 300;

        SET @tier3 = @@ROWCOUNT;
    END

    -- Tier 4: Every 10 min - Enroute flights
    IF @minute % 10 = 0 AND (SELECT COUNT(*) FROM #crossing_batch) < @max_crossings_per_run
    BEGIN
        INSERT INTO #crossing_batch (flight_uid, tier)
        SELECT TOP (@max_crossings_per_run - (SELECT COUNT(*) FROM #crossing_batch))
            c.flight_uid, 4
        FROM dbo.adl_flight_core c
        WHERE c.is_active = 1
          AND NOT EXISTS (SELECT 1 FROM #crossing_batch b WHERE b.flight_uid = c.flight_uid)
          AND c.crossing_region_flags > 0
          AND c.phase = 'enroute'
          AND DATEDIFF(SECOND, c.crossing_last_calc_utc, @now) >= 600;

        SET @tier4 = @@ROWCOUNT;
    END

    -- Tier 5: Every 15 min - International flights
    IF @minute % 15 = 0 AND (SELECT COUNT(*) FROM #crossing_batch) < @max_crossings_per_run
    BEGIN
        INSERT INTO #crossing_batch (flight_uid, tier)
        SELECT TOP (@max_crossings_per_run - (SELECT COUNT(*) FROM #crossing_batch))
            c.flight_uid, 5
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        WHERE c.is_active = 1
          AND NOT EXISTS (SELECT 1 FROM #crossing_batch b WHERE b.flight_uid = c.flight_uid)
          AND c.crossing_region_flags > 0
          AND (fp.fp_dept_icao NOT LIKE 'K%' OR fp.fp_dest_icao NOT LIKE 'K%')
          AND DATEDIFF(SECOND, c.crossing_last_calc_utc, @now) >= 900;

        SET @tier5 = @@ROWCOUNT;
    END

    -- Tier 6: Every 30 min - Transit flights (flag = 4)
    IF @minute % 30 = 0 AND (SELECT COUNT(*) FROM #crossing_batch) < @max_crossings_per_run
    BEGIN
        INSERT INTO #crossing_batch (flight_uid, tier)
        SELECT TOP (@max_crossings_per_run - (SELECT COUNT(*) FROM #crossing_batch))
            c.flight_uid, 6
        FROM dbo.adl_flight_core c
        WHERE c.is_active = 1
          AND NOT EXISTS (SELECT 1 FROM #crossing_batch b WHERE b.flight_uid = c.flight_uid)
          AND c.crossing_region_flags = 4
          AND DATEDIFF(SECOND, c.crossing_last_calc_utc, @now) >= 1800;

        SET @tier6 = @@ROWCOUNT;
    END

    -- Tier 7: Every 60 min - Catch-all
    IF @minute = 0 AND (SELECT COUNT(*) FROM #crossing_batch) < @max_crossings_per_run
    BEGIN
        INSERT INTO #crossing_batch (flight_uid, tier)
        SELECT TOP (@max_crossings_per_run - (SELECT COUNT(*) FROM #crossing_batch))
            c.flight_uid, 7
        FROM dbo.adl_flight_core c
        WHERE c.is_active = 1
          AND NOT EXISTS (SELECT 1 FROM #crossing_batch b WHERE b.flight_uid = c.flight_uid)
          AND c.crossing_region_flags IS NOT NULL
          AND DATEDIFF(SECOND, c.crossing_last_calc_utc, @now) >= 3600;

        SET @tier7 = @@ROWCOUNT;
    END

    -- Process crossings if we have any flights
    DECLARE @batch_count INT = (SELECT COUNT(*) FROM #crossing_batch);

    IF @batch_count > 0
    BEGIN
        -- Detect regional flights first
        EXEC dbo.sp_DetectRegionalFlight @batch_mode = 1;

        -- Get waypoints for batch
        SELECT
            b.flight_uid, b.tier,
            w.sequence_num AS seq, w.fix_name, w.lat, w.lon, w.eta_utc,
            CAST(FLOOR(w.lat / @grid_size) AS SMALLINT) AS grid_lat,
            CAST(FLOOR(w.lon / @grid_size) AS SMALLINT) AS grid_lon
        INTO #all_waypoints
        FROM #crossing_batch b
        JOIN dbo.adl_flight_waypoints w ON w.flight_uid = b.flight_uid
        WHERE w.lat IS NOT NULL AND w.lon IS NOT NULL;

        CREATE INDEX IX_aw_flight ON #all_waypoints(flight_uid, seq);
        CREATE INDEX IX_aw_grid ON #all_waypoints(grid_lat, grid_lon);

        -- Find ARTCC for each waypoint (using set-based approach)
        ;WITH waypoint_artcc_matches AS (
            SELECT
                w.flight_uid, w.seq, w.fix_name, w.eta_utc, w.lat, w.lon,
                g.boundary_id, g.boundary_code,
                ROW_NUMBER() OVER (
                    PARTITION BY w.flight_uid, w.seq
                    ORDER BY g.is_oceanic ASC, g.boundary_area ASC
                ) AS rn
            FROM #all_waypoints w
            JOIN dbo.adl_boundary_grid g
                ON g.grid_lat = w.grid_lat
                AND g.grid_lon = w.grid_lon
                AND g.boundary_type = 'ARTCC'
            JOIN dbo.adl_boundary b ON b.boundary_id = g.boundary_id
            WHERE b.boundary_geography.STIntersects(geography::Point(w.lat, w.lon, 4326)) = 1
        )
        SELECT
            w.flight_uid, w.seq, w.fix_name, w.eta_utc, w.lat, w.lon,
            wam.boundary_id AS artcc_id, wam.boundary_code AS artcc_code
        INTO #waypoint_artcc
        FROM #all_waypoints w
        LEFT JOIN waypoint_artcc_matches wam
            ON wam.flight_uid = w.flight_uid
            AND wam.seq = w.seq
            AND wam.rn = 1;

        CREATE INDEX IX_wa_flight ON #waypoint_artcc(flight_uid, seq);

        -- Detect ARTCC crossings
        SELECT
            curr.flight_uid, 'ARTCC' AS boundary_type,
            curr.artcc_id AS boundary_id, curr.artcc_code AS boundary_code,
            'ENTRY' AS crossing_type, curr.seq AS entry_seq,
            curr.fix_name AS entry_fix, curr.eta_utc AS entry_utc,
            curr.lat AS entry_lat, curr.lon AS entry_lon,
            ROW_NUMBER() OVER (PARTITION BY curr.flight_uid ORDER BY curr.seq) AS crossing_order,
            CAST(NULL AS INT) AS exit_seq,
            CAST(NULL AS NVARCHAR(64)) AS exit_fix,
            CAST(NULL AS DATETIME2(0)) AS exit_utc
        INTO #artcc_crossings
        FROM #waypoint_artcc curr
        LEFT JOIN #waypoint_artcc prev ON prev.flight_uid = curr.flight_uid AND prev.seq = curr.seq - 1
        WHERE curr.artcc_id IS NOT NULL
          AND (prev.artcc_id IS NULL OR prev.artcc_id != curr.artcc_id);

        -- Add exit info
        UPDATE c
        SET c.exit_seq = exit_info.exit_seq, c.exit_fix = exit_info.exit_fix, c.exit_utc = exit_info.exit_utc
        FROM #artcc_crossings c
        CROSS APPLY (
            SELECT TOP 1 w.seq AS exit_seq, w.fix_name AS exit_fix, w.eta_utc AS exit_utc
            FROM #waypoint_artcc w
            WHERE w.flight_uid = c.flight_uid AND w.seq > c.entry_seq
              AND (w.artcc_id IS NULL OR w.artcc_id != c.boundary_id)
            ORDER BY w.seq
        ) exit_info;

        -- Delete existing crossings for batch
        DELETE pc
        FROM dbo.adl_flight_planned_crossings pc
        WHERE EXISTS (SELECT 1 FROM #crossing_batch b WHERE b.flight_uid = pc.flight_uid);

        -- Insert new crossings
        INSERT INTO dbo.adl_flight_planned_crossings (
            flight_uid, crossing_source, boundary_id, boundary_code, boundary_type,
            crossing_type, crossing_order, entry_waypoint_seq, exit_waypoint_seq,
            entry_fix_name, exit_fix_name, planned_entry_utc, planned_exit_utc,
            entry_lat, entry_lon, calculated_at, calculation_tier
        )
        SELECT
            c.flight_uid, 'BOUNDARY', c.boundary_id, c.boundary_code, c.boundary_type,
            c.crossing_type, c.crossing_order, c.entry_seq, c.exit_seq,
            c.entry_fix, c.exit_fix, c.entry_utc, c.exit_utc,
            c.entry_lat, c.entry_lon, @now, b.tier
        FROM #artcc_crossings c
        JOIN #crossing_batch b ON b.flight_uid = c.flight_uid
        WHERE c.boundary_id IS NOT NULL;

        SET @crossings_calculated = @@ROWCOUNT;

        -- Update flight_core
        UPDATE c
        SET c.crossing_last_calc_utc = @now, c.crossing_needs_recalc = 0, c.crossing_tier = b.tier
        FROM dbo.adl_flight_core c
        JOIN #crossing_batch b ON b.flight_uid = c.flight_uid;

        DROP TABLE IF EXISTS #all_waypoints;
        DROP TABLE IF EXISTS #waypoint_artcc;
        DROP TABLE IF EXISTS #artcc_crossings;
    END

    DROP TABLE IF EXISTS #crossing_batch;

    -- ========================================================================
    -- OUTPUT
    -- ========================================================================
    DECLARE @elapsed_ms INT = DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME());

    IF @debug = 1
    BEGIN
        SELECT
            @now AS processed_at,
            @minute AS minute,
            @boundary_flights AS boundary_flights,
            @boundary_transitions AS boundary_transitions,
            @tier1 AS tier1_new,
            @tier2 AS tier2_tracon,
            @tier3 AS tier3_artcc,
            @tier4 AS tier4_enroute,
            @tier5 AS tier5_intl,
            @tier6 AS tier6_transit,
            @tier7 AS tier7_catchall,
            @batch_count AS crossing_flights,
            @crossings_calculated AS crossings_calculated,
            @elapsed_ms AS elapsed_ms;
    END
    ELSE
    BEGIN
        SELECT
            @boundary_flights AS boundary_flights,
            @boundary_transitions AS boundary_transitions,
            @crossings_calculated AS crossings_calculated,
            @elapsed_ms AS elapsed_ms;
    END
END
GO

PRINT 'Created sp_ProcessBoundaryAndCrossings_Background V1.7';
PRINT 'V1.7: Added SECTOR_LOW, SECTOR_HIGH, SECTOR_SUPERHIGH detection';
PRINT 'V1.7: Added ARTCC fallback for grid lookup failures';
PRINT 'Tier schedule: 1=1min, 2=2min, 3=5min, 4=10min, 5=15min, 6=30min, 7=60min';
GO
