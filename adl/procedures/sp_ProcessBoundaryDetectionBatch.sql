-- ============================================================================
-- sp_ProcessBoundaryDetectionBatch V7.4 - Granular Tiered Processing
--
-- Fine-grained tier system for optimal performance:
--   Tier 1: New flights, no boundary (EVERY cycle)
--   Tier 2: Grid cell changed (EVERY cycle)
--   Tier 3: Climbing/descending flights (EVERY cycle) - altitude boundary changes
--   Tier 4: CONUS low altitude (every 2nd cycle) - sector updates
--   Tier 5: CONUS high altitude (every 3rd cycle)
--   Tier 6: International/oceanic (every 5th cycle)
--   Tier 7: Validation pass (every 10th cycle)
--
-- Performance target: <3 seconds for 5000 flights
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID('dbo.sp_ProcessBoundaryDetectionBatch', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_ProcessBoundaryDetectionBatch;
GO

CREATE PROCEDURE dbo.sp_ProcessBoundaryDetectionBatch
    @transitions_detected INT = NULL OUTPUT,
    @flights_processed INT = NULL OUTPUT,
    @elapsed_ms INT = NULL OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @start_time DATETIME2(3) = SYSUTCDATETIME();
    DECLARE @now DATETIME2(0) = @start_time;
    SET @transitions_detected = 0;
    SET @flights_processed = 0;

    -- Configuration
    DECLARE @grid_size DECIMAL(5,3) = 0.5;

    -- Cycle tracking: Use seconds modulo for finer control
    DECLARE @cycle INT = DATEPART(SECOND, @now) / 15;  -- 0-3 based on 15-sec cycle
    DECLARE @minute INT = DATEPART(MINUTE, @now);

    -- US CONUS bounding box
    DECLARE @us_lat_min DECIMAL(6,2) = 24.0;
    DECLARE @us_lat_max DECIMAL(6,2) = 50.0;
    DECLARE @us_lon_min DECIMAL(7,2) = -130.0;
    DECLARE @us_lon_max DECIMAL(7,2) = -65.0;

    -- ========================================================================
    -- Step 1: Classify all flights into tiers
    -- ========================================================================

    SELECT
        c.flight_uid,
        p.lat,
        p.lon,
        p.altitude_ft,
        p.vertical_rate_fpm,  -- For climb/descent detection
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
        c.last_grid_lat,
        c.last_grid_lon,
        -- Current grid cell
        CAST(FLOOR(p.lat / @grid_size) AS SMALLINT) AS grid_lat,
        CAST(FLOOR(p.lon / @grid_size) AS SMALLINT) AS grid_lon,
        -- Region flags
        CASE WHEN p.lat BETWEEN @us_lat_min AND @us_lat_max
              AND p.lon BETWEEN @us_lon_min AND @us_lon_max
             THEN 1 ELSE 0 END AS is_conus,
        CASE WHEN c.current_artcc LIKE '%OCA%' OR c.current_artcc LIKE '%OCE%'
              OR c.current_artcc LIKE '%FIR%' THEN 1 ELSE 0 END AS is_oceanic,
        -- Pre-compute geography point
        geography::Point(p.lat, p.lon, 4326) AS position_geo,
        -- Granular tier assignment
        CASE
            -- Tier 1: New flight, no boundary at all (CRITICAL)
            WHEN c.current_artcc_id IS NULL THEN 1

            -- Tier 2: Grid cell changed (CRITICAL - potential boundary crossing)
            WHEN c.last_grid_lat IS NULL THEN 2
            WHEN c.last_grid_lat != CAST(FLOOR(p.lat / @grid_size) AS SMALLINT) THEN 2
            WHEN c.last_grid_lon != CAST(FLOOR(p.lon / @grid_size) AS SMALLINT) THEN 2

            -- Tier 3: Climbing/descending (altitude-based sector changes)
            WHEN ABS(ISNULL(p.vertical_rate_fpm, 0)) > 500 THEN 3  -- >500 fpm = climbing/descending

            -- Tier 4: CONUS low altitude - sectors change frequently (every 2nd cycle)
            WHEN p.lat BETWEEN @us_lat_min AND @us_lat_max
                 AND p.lon BETWEEN @us_lon_min AND @us_lon_max
                 AND p.altitude_ft < 24000
                 AND @cycle % 2 = 0 THEN 4

            -- Tier 5: CONUS high altitude - less frequent updates (every 3rd cycle)
            WHEN p.lat BETWEEN @us_lat_min AND @us_lat_max
                 AND p.lon BETWEEN @us_lon_min AND @us_lon_max
                 AND p.altitude_ft >= 24000
                 AND @cycle % 3 = 0 THEN 5

            -- Tier 6: International/oceanic - stable, infrequent updates (every 5th cycle = ~75sec)
            WHEN @minute % 5 = 0 AND @cycle = 0 THEN 6

            -- Tier 7: Full validation pass (every 10th minute)
            WHEN @minute % 10 = 0 AND @cycle = 0 THEN 7

            ELSE 0  -- Skip this cycle
        END AS tier
    INTO #all_flights
    FROM dbo.adl_flight_core c
    JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    WHERE c.is_active = 1
      AND p.lat IS NOT NULL AND p.lon IS NOT NULL
      AND p.lat BETWEEN -90 AND 90
      AND p.lon BETWEEN -180 AND 180;

    -- Filter to flights we'll process this cycle
    SELECT * INTO #flights FROM #all_flights WHERE tier > 0;

    SET @flights_processed = @@ROWCOUNT;

    IF @flights_processed = 0
    BEGIN
        DROP TABLE #all_flights;
        SET @elapsed_ms = DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME());
        RETURN;
    END

    -- Index for performance
    CREATE INDEX IX_f_grid ON #flights(grid_lat, grid_lon);
    CREATE INDEX IX_f_tier ON #flights(tier);

    -- ========================================================================
    -- Step 2: ARTCC Detection (Tiers 1-3, 6-7)
    -- ========================================================================

    SELECT
        f.flight_uid,
        f.lat,
        f.lon,
        f.altitude_ft,
        f.grid_lat,
        f.grid_lon,
        f.current_artcc AS prev_artcc,
        f.current_artcc_id AS prev_artcc_id,
        artcc.boundary_id AS new_artcc_id,
        artcc.boundary_code AS new_artcc
    INTO #artcc_detection
    FROM #flights f
    OUTER APPLY (
        SELECT TOP 1 g.boundary_id, g.boundary_code
        FROM dbo.adl_boundary_grid g
        JOIN dbo.adl_boundary b ON b.boundary_id = g.boundary_id
        WHERE g.boundary_type = 'ARTCC'
          AND g.grid_lat = f.grid_lat
          AND g.grid_lon = f.grid_lon
          AND b.boundary_geography.STIntersects(f.position_geo) = 1
        ORDER BY g.is_oceanic ASC, g.boundary_area ASC
    ) artcc
    WHERE f.tier IN (1, 2, 3, 6, 7);  -- Critical + international + validation

    -- ========================================================================
    -- Step 3: TRACON Detection (Tiers 1-4, below FL180)
    -- ========================================================================

    SELECT
        f.flight_uid,
        f.lat,
        f.lon,
        f.altitude_ft,
        f.current_tracon AS prev_tracon,
        f.current_tracon_id AS prev_tracon_id,
        tracon.boundary_id AS new_tracon_id,
        tracon.boundary_code AS new_tracon
    INTO #tracon_detection
    FROM #flights f
    OUTER APPLY (
        SELECT TOP 1 g.boundary_id, g.boundary_code
        FROM dbo.adl_boundary_grid g
        JOIN dbo.adl_boundary b ON b.boundary_id = g.boundary_id
        WHERE g.boundary_type = 'TRACON'
          AND f.altitude_ft < 18000
          AND g.grid_lat = f.grid_lat
          AND g.grid_lon = f.grid_lon
          AND b.boundary_geography.STIntersects(f.position_geo) = 1
        ORDER BY g.boundary_area ASC
    ) tracon
    WHERE f.tier IN (1, 2, 3, 4) AND f.altitude_ft < 18000;

    -- ========================================================================
    -- Step 4: SECTOR Detection (Tiers 1-5, 7 - CONUS only)
    -- ========================================================================

    -- SECTOR_LOW (altitude < 24000)
    SELECT DISTINCT f.flight_uid, g.boundary_id, g.boundary_code
    INTO #low_sectors_raw
    FROM #flights f
    JOIN dbo.adl_boundary_grid g ON
        g.boundary_type = 'SECTOR_LOW'
        AND g.grid_lat = f.grid_lat
        AND g.grid_lon = f.grid_lon
    JOIN dbo.adl_boundary b ON b.boundary_id = g.boundary_id
    WHERE f.tier IN (1, 2, 3, 4, 7)
      AND f.is_conus = 1
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
    FROM #flights f
    WHERE f.tier IN (1, 2, 3, 4, 7) AND f.is_conus = 1 AND f.altitude_ft < 24000;

    -- SECTOR_HIGH (altitude 10000-60000)
    SELECT DISTINCT f.flight_uid, g.boundary_id, g.boundary_code
    INTO #high_sectors_raw
    FROM #flights f
    JOIN dbo.adl_boundary_grid g ON
        g.boundary_type = 'SECTOR_HIGH'
        AND g.grid_lat = f.grid_lat
        AND g.grid_lon = f.grid_lon
    JOIN dbo.adl_boundary b ON b.boundary_id = g.boundary_id
    WHERE f.tier IN (1, 2, 3, 5, 7)
      AND f.is_conus = 1
      AND f.altitude_ft >= 10000 AND f.altitude_ft < 60000
      AND b.boundary_geography.STIntersects(f.position_geo) = 1;

    SELECT
        f.flight_uid,
        f.lat,
        f.lon,
        f.altitude_ft,
        f.current_sector_high AS prev_sector_high,
        STUFF((SELECT ',' + hs.boundary_code FROM #high_sectors_raw hs
               WHERE hs.flight_uid = f.flight_uid ORDER BY hs.boundary_code FOR XML PATH('')), 1, 1, '') AS new_sector_high,
        (SELECT hs.boundary_id AS id FROM #high_sectors_raw hs
         WHERE hs.flight_uid = f.flight_uid ORDER BY hs.boundary_code FOR JSON PATH) AS new_sector_high_ids
    INTO #high_detection
    FROM #flights f
    WHERE f.tier IN (1, 2, 3, 5, 7) AND f.is_conus = 1 AND f.altitude_ft >= 10000;

    -- SECTOR_SUPERHIGH (altitude >= 35000)
    SELECT DISTINCT f.flight_uid, g.boundary_id, g.boundary_code
    INTO #superhigh_sectors_raw
    FROM #flights f
    JOIN dbo.adl_boundary_grid g ON
        g.boundary_type = 'SECTOR_SUPERHIGH'
        AND g.grid_lat = f.grid_lat
        AND g.grid_lon = f.grid_lon
    JOIN dbo.adl_boundary b ON b.boundary_id = g.boundary_id
    WHERE f.tier IN (1, 2, 3, 5, 7)
      AND f.is_conus = 1
      AND f.altitude_ft >= 35000
      AND b.boundary_geography.STIntersects(f.position_geo) = 1;

    SELECT
        f.flight_uid,
        f.lat,
        f.lon,
        f.altitude_ft,
        f.current_sector_superhigh AS prev_sector_superhigh,
        STUFF((SELECT ',' + sh.boundary_code FROM #superhigh_sectors_raw sh
               WHERE sh.flight_uid = f.flight_uid ORDER BY sh.boundary_code FOR XML PATH('')), 1, 1, '') AS new_sector_superhigh,
        (SELECT sh.boundary_id AS id FROM #superhigh_sectors_raw sh
         WHERE sh.flight_uid = f.flight_uid ORDER BY sh.boundary_code FOR JSON PATH) AS new_sector_superhigh_ids
    INTO #superhigh_detection
    FROM #flights f
    WHERE f.tier IN (1, 2, 3, 5, 7) AND f.is_conus = 1 AND f.altitude_ft >= 35000;

    -- ========================================================================
    -- Step 5: Log transitions (ARTCC, TRACON, Sectors)
    -- ========================================================================

    -- ARTCC exits
    UPDATE log
    SET exit_time = @now,
        exit_lat = a.lat,
        exit_lon = a.lon,
        exit_altitude = a.altitude_ft,
        duration_seconds = DATEDIFF(SECOND, log.entry_time, @now)
    FROM dbo.adl_flight_boundary_log log
    JOIN #artcc_detection a ON log.flight_uid = a.flight_uid
    WHERE log.boundary_type = 'ARTCC'
      AND log.exit_time IS NULL
      AND log.boundary_id = a.prev_artcc_id
      AND (a.new_artcc_id IS NULL OR a.new_artcc_id != a.prev_artcc_id);

    -- ARTCC entries
    INSERT INTO dbo.adl_flight_boundary_log
        (flight_uid, boundary_id, boundary_type, boundary_code, entry_time, entry_lat, entry_lon, entry_altitude)
    SELECT a.flight_uid, a.new_artcc_id, 'ARTCC', a.new_artcc, @now, a.lat, a.lon, a.altitude_ft
    FROM #artcc_detection a
    WHERE a.new_artcc_id IS NOT NULL
      AND (a.prev_artcc_id IS NULL OR a.new_artcc_id != a.prev_artcc_id);

    SET @transitions_detected = @transitions_detected + @@ROWCOUNT;

    -- TRACON transitions
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

    SET @transitions_detected = @transitions_detected + @@ROWCOUNT;

    -- Sector transitions (simplified)
    -- LOW
    UPDATE log SET exit_time = @now, exit_lat = f.lat, exit_lon = f.lon,
        exit_altitude = f.altitude_ft, duration_seconds = DATEDIFF(SECOND, log.entry_time, @now)
    FROM dbo.adl_flight_boundary_log log
    JOIN #low_detection f ON log.flight_uid = f.flight_uid
    WHERE log.boundary_type = 'SECTOR_LOW' AND log.exit_time IS NULL
      AND NOT EXISTS (SELECT 1 FROM #low_sectors_raw ls WHERE ls.flight_uid = log.flight_uid AND ls.boundary_id = log.boundary_id);

    INSERT INTO dbo.adl_flight_boundary_log (flight_uid, boundary_id, boundary_type, boundary_code, entry_time, entry_lat, entry_lon, entry_altitude)
    SELECT ls.flight_uid, ls.boundary_id, 'SECTOR_LOW', ls.boundary_code, @now, f.lat, f.lon, f.altitude_ft
    FROM #low_sectors_raw ls JOIN #low_detection f ON f.flight_uid = ls.flight_uid
    WHERE NOT EXISTS (SELECT 1 FROM dbo.adl_flight_boundary_log log WHERE log.flight_uid = ls.flight_uid AND log.boundary_id = ls.boundary_id AND log.exit_time IS NULL);

    SET @transitions_detected = @transitions_detected + @@ROWCOUNT;

    -- HIGH
    UPDATE log SET exit_time = @now, exit_lat = f.lat, exit_lon = f.lon,
        exit_altitude = f.altitude_ft, duration_seconds = DATEDIFF(SECOND, log.entry_time, @now)
    FROM dbo.adl_flight_boundary_log log
    JOIN #high_detection f ON log.flight_uid = f.flight_uid
    WHERE log.boundary_type = 'SECTOR_HIGH' AND log.exit_time IS NULL
      AND NOT EXISTS (SELECT 1 FROM #high_sectors_raw hs WHERE hs.flight_uid = log.flight_uid AND hs.boundary_id = log.boundary_id);

    INSERT INTO dbo.adl_flight_boundary_log (flight_uid, boundary_id, boundary_type, boundary_code, entry_time, entry_lat, entry_lon, entry_altitude)
    SELECT hs.flight_uid, hs.boundary_id, 'SECTOR_HIGH', hs.boundary_code, @now, f.lat, f.lon, f.altitude_ft
    FROM #high_sectors_raw hs JOIN #high_detection f ON f.flight_uid = hs.flight_uid
    WHERE NOT EXISTS (SELECT 1 FROM dbo.adl_flight_boundary_log log WHERE log.flight_uid = hs.flight_uid AND log.boundary_id = hs.boundary_id AND log.exit_time IS NULL);

    SET @transitions_detected = @transitions_detected + @@ROWCOUNT;

    -- SUPERHIGH
    UPDATE log SET exit_time = @now, exit_lat = f.lat, exit_lon = f.lon,
        exit_altitude = f.altitude_ft, duration_seconds = DATEDIFF(SECOND, log.entry_time, @now)
    FROM dbo.adl_flight_boundary_log log
    JOIN #superhigh_detection f ON log.flight_uid = f.flight_uid
    WHERE log.boundary_type = 'SECTOR_SUPERHIGH' AND log.exit_time IS NULL
      AND NOT EXISTS (SELECT 1 FROM #superhigh_sectors_raw sh WHERE sh.flight_uid = log.flight_uid AND sh.boundary_id = log.boundary_id);

    INSERT INTO dbo.adl_flight_boundary_log (flight_uid, boundary_id, boundary_type, boundary_code, entry_time, entry_lat, entry_lon, entry_altitude)
    SELECT sh.flight_uid, sh.boundary_id, 'SECTOR_SUPERHIGH', sh.boundary_code, @now, f.lat, f.lon, f.altitude_ft
    FROM #superhigh_sectors_raw sh JOIN #superhigh_detection f ON f.flight_uid = sh.flight_uid
    WHERE NOT EXISTS (SELECT 1 FROM dbo.adl_flight_boundary_log log WHERE log.flight_uid = sh.flight_uid AND log.boundary_id = sh.boundary_id AND log.exit_time IS NULL);

    SET @transitions_detected = @transitions_detected + @@ROWCOUNT;

    -- ========================================================================
    -- Step 6: Update flight_core
    -- ========================================================================

    -- ARTCC updates
    UPDATE c SET c.current_artcc = a.new_artcc, c.current_artcc_id = a.new_artcc_id,
        c.last_grid_lat = a.grid_lat, c.last_grid_lon = a.grid_lon, c.boundary_updated_at = @now
    FROM dbo.adl_flight_core c JOIN #artcc_detection a ON a.flight_uid = c.flight_uid;

    -- TRACON updates
    UPDATE c SET c.current_tracon = t.new_tracon, c.current_tracon_id = t.new_tracon_id
    FROM dbo.adl_flight_core c JOIN #tracon_detection t ON t.flight_uid = c.flight_uid;

    -- Sector updates
    UPDATE c SET c.current_sector_low = l.new_sector_low, c.current_sector_low_ids = l.new_sector_low_ids
    FROM dbo.adl_flight_core c JOIN #low_detection l ON l.flight_uid = c.flight_uid;

    UPDATE c SET c.current_sector_high = h.new_sector_high, c.current_sector_high_ids = h.new_sector_high_ids
    FROM dbo.adl_flight_core c JOIN #high_detection h ON h.flight_uid = c.flight_uid;

    UPDATE c SET c.current_sector_superhigh = s.new_sector_superhigh, c.current_sector_superhigh_ids = s.new_sector_superhigh_ids
    FROM dbo.adl_flight_core c JOIN #superhigh_detection s ON s.flight_uid = c.flight_uid;

    -- Grid cache update for ALL processed flights
    UPDATE c SET c.last_grid_lat = f.grid_lat, c.last_grid_lon = f.grid_lon
    FROM dbo.adl_flight_core c JOIN #flights f ON f.flight_uid = c.flight_uid
    WHERE c.last_grid_lat IS NULL OR c.last_grid_lat != f.grid_lat OR c.last_grid_lon != f.grid_lon;

    -- ========================================================================
    -- Cleanup
    -- ========================================================================

    DROP TABLE IF EXISTS #all_flights;
    DROP TABLE IF EXISTS #flights;
    DROP TABLE IF EXISTS #artcc_detection;
    DROP TABLE IF EXISTS #tracon_detection;
    DROP TABLE IF EXISTS #low_sectors_raw;
    DROP TABLE IF EXISTS #low_detection;
    DROP TABLE IF EXISTS #high_sectors_raw;
    DROP TABLE IF EXISTS #high_detection;
    DROP TABLE IF EXISTS #superhigh_sectors_raw;
    DROP TABLE IF EXISTS #superhigh_detection;

    SET @elapsed_ms = DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME());
END
GO

PRINT 'Created sp_ProcessBoundaryDetectionBatch V7.4 (granular tiered processing)';
PRINT 'Tier 1: New flights (every cycle)';
PRINT 'Tier 2: Grid cell changed (every cycle)';
PRINT 'Tier 3: Climbing/descending (every cycle)';
PRINT 'Tier 4: CONUS low altitude (every 2nd cycle)';
PRINT 'Tier 5: CONUS high altitude (every 3rd cycle)';
PRINT 'Tier 6: International (every 5th cycle)';
PRINT 'Tier 7: Validation (every 10th minute)';
GO
