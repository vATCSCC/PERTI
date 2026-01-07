-- ============================================================================
-- sp_ProcessBoundaryDetectionBatch V7.0 - Production-Ready
--
-- Designed for 5000+ flights with:
--   - Smart edge detection (check neighbors only when near cell boundary)
--   - Fallback to bounding box when grid has no entries
--   - Performance monitoring and logging
--   - Batch processing for large flight counts
--   - CONUS-optimized sector detection
--
-- Performance target: <5 seconds for 5000 flights
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
    DECLARE @edge_threshold DECIMAL(5,3) = 0.1;  -- 10% from edge = ~5nm

    -- US CONUS bounding box (sectors only apply here)
    DECLARE @us_lat_min DECIMAL(6,2) = 24.0;
    DECLARE @us_lat_max DECIMAL(6,2) = 50.0;
    DECLARE @us_lon_min DECIMAL(7,2) = -130.0;
    DECLARE @us_lon_max DECIMAL(7,2) = -65.0;

    -- ========================================================================
    -- Step 1: Get all active flights with grid coordinates
    -- ========================================================================

    SELECT
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
        -- Region: 1=CONUS (has sectors), 0=International
        CASE WHEN p.lat BETWEEN @us_lat_min AND @us_lat_max
              AND p.lon BETWEEN @us_lon_min AND @us_lon_max
             THEN 1 ELSE 0 END AS is_conus,
        -- Grid cell coordinates
        CAST(FLOOR(p.lat / @grid_size) AS SMALLINT) AS grid_lat,
        CAST(FLOOR(p.lon / @grid_size) AS SMALLINT) AS grid_lon,
        -- Fractional position in cell (0-1) for edge detection
        ABS((p.lat / @grid_size) - FLOOR(p.lat / @grid_size)) AS frac_lat,
        ABS((p.lon / @grid_size) - FLOOR(p.lon / @grid_size)) AS frac_lon,
        -- Pre-compute geography point once
        geography::Point(p.lat, p.lon, 4326) AS position_geo
    INTO #flights_raw
    FROM dbo.adl_flight_core c
    JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    WHERE c.is_active = 1
      AND p.lat IS NOT NULL AND p.lon IS NOT NULL
      AND p.lat BETWEEN -90 AND 90
      AND p.lon BETWEEN -180 AND 180;

    SET @flights_processed = @@ROWCOUNT;
    IF @flights_processed = 0
    BEGIN
        SET @elapsed_ms = DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME());
        RETURN;
    END

    -- Add smart edge-aware grid ranges
    -- Only expand search in directions where flight is near edge
    SELECT
        f.*,
        CASE WHEN f.frac_lat < @edge_threshold THEN f.grid_lat - 1 ELSE f.grid_lat END AS grid_lat_min,
        CASE WHEN f.frac_lat > (1.0 - @edge_threshold) THEN f.grid_lat + 1 ELSE f.grid_lat END AS grid_lat_max,
        CASE WHEN f.frac_lon < @edge_threshold THEN f.grid_lon - 1 ELSE f.grid_lon END AS grid_lon_min,
        CASE WHEN f.frac_lon > (1.0 - @edge_threshold) THEN f.grid_lon + 1 ELSE f.grid_lon END AS grid_lon_max
    INTO #flights
    FROM #flights_raw f;

    DROP TABLE #flights_raw;

    -- Indexes for performance
    CREATE CLUSTERED INDEX IX_f_uid ON #flights(flight_uid);
    CREATE INDEX IX_f_grid ON #flights(grid_lat_min, grid_lat_max, grid_lon_min, grid_lon_max) INCLUDE (is_conus, altitude_ft);

    -- ========================================================================
    -- Step 2: ARTCC Detection with grid lookup + bounding box fallback
    -- ========================================================================

    -- First: Try grid-based lookup (fast path)
    SELECT
        f.flight_uid,
        f.lat,
        f.lon,
        f.altitude_ft,
        f.position_geo,
        f.current_artcc AS prev_artcc,
        f.current_artcc_id AS prev_artcc_id,
        artcc.boundary_id AS new_artcc_id,
        artcc.boundary_code AS new_artcc,
        CASE WHEN artcc.boundary_id IS NULL THEN 1 ELSE 0 END AS needs_fallback
    INTO #artcc_grid
    FROM #flights f
    OUTER APPLY (
        SELECT TOP 1 g.boundary_id, g.boundary_code
        FROM dbo.adl_boundary_grid g
        JOIN dbo.adl_boundary b ON b.boundary_id = g.boundary_id
        WHERE g.boundary_type = 'ARTCC'
          AND g.grid_lat BETWEEN f.grid_lat_min AND f.grid_lat_max
          AND g.grid_lon BETWEEN f.grid_lon_min AND f.grid_lon_max
          AND b.boundary_geography.STIntersects(f.position_geo) = 1
        ORDER BY g.is_oceanic ASC, g.boundary_area ASC
    ) artcc;

    -- Fallback: For flights with no grid match, try bounding box search
    UPDATE ag
    SET ag.new_artcc_id = fallback.boundary_id,
        ag.new_artcc = fallback.boundary_code,
        ag.needs_fallback = 0
    FROM #artcc_grid ag
    OUTER APPLY (
        SELECT TOP 1 b.boundary_id, b.boundary_code
        FROM dbo.adl_boundary b
        WHERE b.boundary_type = 'ARTCC'
          AND b.is_active = 1
          AND ag.lat BETWEEN b.bbox_min_lat AND b.bbox_max_lat
          AND ag.lon BETWEEN b.bbox_min_lon AND b.bbox_max_lon
          AND b.boundary_geography.STIntersects(ag.position_geo) = 1
        ORDER BY b.is_oceanic ASC, b.boundary_geography.STArea() ASC
    ) fallback
    WHERE ag.needs_fallback = 1;

    -- Final ARTCC detection results
    SELECT flight_uid, lat, lon, altitude_ft, position_geo,
           prev_artcc, prev_artcc_id, new_artcc_id, new_artcc
    INTO #artcc_detection
    FROM #artcc_grid;

    DROP TABLE #artcc_grid;

    -- ========================================================================
    -- Step 3: TRACON Detection (below FL180, with fallback)
    -- ========================================================================

    SELECT
        f.flight_uid,
        f.lat,
        f.lon,
        f.altitude_ft,
        f.current_tracon AS prev_tracon,
        f.current_tracon_id AS prev_tracon_id,
        COALESCE(tracon_grid.boundary_id, tracon_fallback.boundary_id) AS new_tracon_id,
        COALESCE(tracon_grid.boundary_code, tracon_fallback.boundary_code) AS new_tracon
    INTO #tracon_detection
    FROM #flights f
    -- Grid lookup (fast)
    OUTER APPLY (
        SELECT TOP 1 g.boundary_id, g.boundary_code
        FROM dbo.adl_boundary_grid g
        JOIN dbo.adl_boundary b ON b.boundary_id = g.boundary_id
        WHERE g.boundary_type = 'TRACON'
          AND f.altitude_ft < 18000
          AND g.grid_lat BETWEEN f.grid_lat_min AND f.grid_lat_max
          AND g.grid_lon BETWEEN f.grid_lon_min AND f.grid_lon_max
          AND b.boundary_geography.STIntersects(f.position_geo) = 1
        ORDER BY g.boundary_area ASC
    ) tracon_grid
    -- Bounding box fallback (only if grid returned nothing)
    OUTER APPLY (
        SELECT TOP 1 b.boundary_id, b.boundary_code
        FROM dbo.adl_boundary b
        WHERE tracon_grid.boundary_id IS NULL
          AND b.boundary_type = 'TRACON'
          AND b.is_active = 1
          AND f.altitude_ft < 18000
          AND f.lat BETWEEN b.bbox_min_lat AND b.bbox_max_lat
          AND f.lon BETWEEN b.bbox_min_lon AND b.bbox_max_lon
          AND b.boundary_geography.STIntersects(f.position_geo) = 1
        ORDER BY b.boundary_geography.STArea() ASC
    ) tracon_fallback;

    -- ========================================================================
    -- Step 4: SECTOR_LOW Detection (CONUS only, altitude < 24000)
    -- ========================================================================

    SELECT DISTINCT f.flight_uid, g.boundary_id, g.boundary_code
    INTO #low_sectors_raw
    FROM #flights f
    JOIN dbo.adl_boundary_grid g ON
        g.boundary_type = 'SECTOR_LOW'
        AND g.grid_lat BETWEEN f.grid_lat_min AND f.grid_lat_max
        AND g.grid_lon BETWEEN f.grid_lon_min AND f.grid_lon_max
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
    FROM #flights f;

    -- ========================================================================
    -- Step 5: SECTOR_HIGH Detection (CONUS, altitude 10000-60000)
    -- ========================================================================

    SELECT DISTINCT f.flight_uid, g.boundary_id, g.boundary_code
    INTO #high_sectors_raw
    FROM #flights f
    JOIN dbo.adl_boundary_grid g ON
        g.boundary_type = 'SECTOR_HIGH'
        AND g.grid_lat BETWEEN f.grid_lat_min AND f.grid_lat_max
        AND g.grid_lon BETWEEN f.grid_lon_min AND f.grid_lon_max
    JOIN dbo.adl_boundary b ON b.boundary_id = g.boundary_id
    WHERE f.is_conus = 1
      AND f.altitude_ft >= 10000 AND f.altitude_ft < 60000
      AND b.boundary_geography.STIntersects(f.position_geo) = 1;

    SELECT
        f.flight_uid,
        f.current_sector_high AS prev_sector_high,
        STUFF((SELECT ',' + hs.boundary_code FROM #high_sectors_raw hs
               WHERE hs.flight_uid = f.flight_uid ORDER BY hs.boundary_code FOR XML PATH('')), 1, 1, '') AS new_sector_high,
        (SELECT hs.boundary_id AS id FROM #high_sectors_raw hs
         WHERE hs.flight_uid = f.flight_uid ORDER BY hs.boundary_code FOR JSON PATH) AS new_sector_high_ids
    INTO #high_detection
    FROM #flights f;

    -- ========================================================================
    -- Step 6: SECTOR_SUPERHIGH Detection (CONUS, altitude >= 35000)
    -- ========================================================================

    SELECT DISTINCT f.flight_uid, g.boundary_id, g.boundary_code
    INTO #superhigh_sectors_raw
    FROM #flights f
    JOIN dbo.adl_boundary_grid g ON
        g.boundary_type = 'SECTOR_SUPERHIGH'
        AND g.grid_lat BETWEEN f.grid_lat_min AND f.grid_lat_max
        AND g.grid_lon BETWEEN f.grid_lon_min AND f.grid_lon_max
    JOIN dbo.adl_boundary b ON b.boundary_id = g.boundary_id
    WHERE f.is_conus = 1
      AND f.altitude_ft >= 35000
      AND b.boundary_geography.STIntersects(f.position_geo) = 1;

    SELECT
        f.flight_uid,
        f.current_sector_superhigh AS prev_sector_superhigh,
        STUFF((SELECT ',' + sh.boundary_code FROM #superhigh_sectors_raw sh
               WHERE sh.flight_uid = f.flight_uid ORDER BY sh.boundary_code FOR XML PATH('')), 1, 1, '') AS new_sector_superhigh,
        (SELECT sh.boundary_id AS id FROM #superhigh_sectors_raw sh
         WHERE sh.flight_uid = f.flight_uid ORDER BY sh.boundary_code FOR JSON PATH) AS new_sector_superhigh_ids
    INTO #superhigh_detection
    FROM #flights f;

    -- ========================================================================
    -- Step 7-11: Log boundary transitions
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

    -- TRACON exits
    UPDATE log
    SET exit_time = @now, exit_lat = t.lat, exit_lon = t.lon,
        exit_altitude = t.altitude_ft, duration_seconds = DATEDIFF(SECOND, log.entry_time, @now)
    FROM dbo.adl_flight_boundary_log log
    JOIN #tracon_detection t ON log.flight_uid = t.flight_uid
    WHERE log.boundary_type = 'TRACON' AND log.exit_time IS NULL
      AND log.boundary_id = t.prev_tracon_id
      AND (t.new_tracon_id IS NULL OR t.new_tracon_id != t.prev_tracon_id);

    -- TRACON entries
    INSERT INTO dbo.adl_flight_boundary_log
        (flight_uid, boundary_id, boundary_type, boundary_code, entry_time, entry_lat, entry_lon, entry_altitude)
    SELECT t.flight_uid, t.new_tracon_id, 'TRACON', t.new_tracon, @now, t.lat, t.lon, t.altitude_ft
    FROM #tracon_detection t
    WHERE t.new_tracon_id IS NOT NULL
      AND (t.prev_tracon_id IS NULL OR t.new_tracon_id != t.prev_tracon_id);

    SET @transitions_detected = @transitions_detected + @@ROWCOUNT;

    -- SECTOR_LOW transitions
    UPDATE log
    SET exit_time = @now, exit_lat = f.lat, exit_lon = f.lon,
        exit_altitude = f.altitude_ft, duration_seconds = DATEDIFF(SECOND, log.entry_time, @now)
    FROM dbo.adl_flight_boundary_log log
    JOIN #flights f ON log.flight_uid = f.flight_uid
    WHERE log.boundary_type = 'SECTOR_LOW' AND log.exit_time IS NULL
      AND NOT EXISTS (SELECT 1 FROM #low_sectors_raw ls
                      WHERE ls.flight_uid = log.flight_uid AND ls.boundary_id = log.boundary_id);

    INSERT INTO dbo.adl_flight_boundary_log
        (flight_uid, boundary_id, boundary_type, boundary_code, entry_time, entry_lat, entry_lon, entry_altitude)
    SELECT ls.flight_uid, ls.boundary_id, 'SECTOR_LOW', ls.boundary_code, @now, f.lat, f.lon, f.altitude_ft
    FROM #low_sectors_raw ls
    JOIN #flights f ON f.flight_uid = ls.flight_uid
    WHERE NOT EXISTS (SELECT 1 FROM dbo.adl_flight_boundary_log log
                      WHERE log.flight_uid = ls.flight_uid AND log.boundary_id = ls.boundary_id AND log.exit_time IS NULL);

    SET @transitions_detected = @transitions_detected + @@ROWCOUNT;

    -- SECTOR_HIGH transitions
    UPDATE log
    SET exit_time = @now, exit_lat = f.lat, exit_lon = f.lon,
        exit_altitude = f.altitude_ft, duration_seconds = DATEDIFF(SECOND, log.entry_time, @now)
    FROM dbo.adl_flight_boundary_log log
    JOIN #flights f ON log.flight_uid = f.flight_uid
    WHERE log.boundary_type = 'SECTOR_HIGH' AND log.exit_time IS NULL
      AND NOT EXISTS (SELECT 1 FROM #high_sectors_raw hs
                      WHERE hs.flight_uid = log.flight_uid AND hs.boundary_id = log.boundary_id);

    INSERT INTO dbo.adl_flight_boundary_log
        (flight_uid, boundary_id, boundary_type, boundary_code, entry_time, entry_lat, entry_lon, entry_altitude)
    SELECT hs.flight_uid, hs.boundary_id, 'SECTOR_HIGH', hs.boundary_code, @now, f.lat, f.lon, f.altitude_ft
    FROM #high_sectors_raw hs
    JOIN #flights f ON f.flight_uid = hs.flight_uid
    WHERE NOT EXISTS (SELECT 1 FROM dbo.adl_flight_boundary_log log
                      WHERE log.flight_uid = hs.flight_uid AND log.boundary_id = hs.boundary_id AND log.exit_time IS NULL);

    SET @transitions_detected = @transitions_detected + @@ROWCOUNT;

    -- SECTOR_SUPERHIGH transitions
    UPDATE log
    SET exit_time = @now, exit_lat = f.lat, exit_lon = f.lon,
        exit_altitude = f.altitude_ft, duration_seconds = DATEDIFF(SECOND, log.entry_time, @now)
    FROM dbo.adl_flight_boundary_log log
    JOIN #flights f ON log.flight_uid = f.flight_uid
    WHERE log.boundary_type = 'SECTOR_SUPERHIGH' AND log.exit_time IS NULL
      AND NOT EXISTS (SELECT 1 FROM #superhigh_sectors_raw sh
                      WHERE sh.flight_uid = log.flight_uid AND sh.boundary_id = log.boundary_id);

    INSERT INTO dbo.adl_flight_boundary_log
        (flight_uid, boundary_id, boundary_type, boundary_code, entry_time, entry_lat, entry_lon, entry_altitude)
    SELECT sh.flight_uid, sh.boundary_id, 'SECTOR_SUPERHIGH', sh.boundary_code, @now, f.lat, f.lon, f.altitude_ft
    FROM #superhigh_sectors_raw sh
    JOIN #flights f ON f.flight_uid = sh.flight_uid
    WHERE NOT EXISTS (SELECT 1 FROM dbo.adl_flight_boundary_log log
                      WHERE log.flight_uid = sh.flight_uid AND log.boundary_id = sh.boundary_id AND log.exit_time IS NULL);

    SET @transitions_detected = @transitions_detected + @@ROWCOUNT;

    -- ========================================================================
    -- Step 12: Update flight_core with current boundaries
    -- ========================================================================

    UPDATE c
    SET c.current_artcc = a.new_artcc,
        c.current_artcc_id = a.new_artcc_id,
        c.current_sector_low = l.new_sector_low,
        c.current_sector_low_ids = l.new_sector_low_ids,
        c.current_sector_high = h.new_sector_high,
        c.current_sector_high_ids = h.new_sector_high_ids,
        c.current_sector_superhigh = s.new_sector_superhigh,
        c.current_sector_superhigh_ids = s.new_sector_superhigh_ids,
        c.current_tracon = t.new_tracon,
        c.current_tracon_id = t.new_tracon_id,
        c.boundary_updated_at = @now
    FROM dbo.adl_flight_core c
    JOIN #artcc_detection a ON a.flight_uid = c.flight_uid
    JOIN #low_detection l ON l.flight_uid = c.flight_uid
    JOIN #high_detection h ON h.flight_uid = c.flight_uid
    JOIN #superhigh_detection s ON s.flight_uid = c.flight_uid
    JOIN #tracon_detection t ON t.flight_uid = c.flight_uid;

    -- ========================================================================
    -- Cleanup and performance logging
    -- ========================================================================

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

    -- Log slow executions (>5 seconds) for investigation
    IF @elapsed_ms > 5000
    BEGIN
        INSERT INTO dbo.adl_system_log (log_type, log_message, created_at)
        SELECT 'BOUNDARY_SLOW',
               'Boundary detection took ' + CAST(@elapsed_ms AS VARCHAR) + 'ms for ' +
               CAST(@flights_processed AS VARCHAR) + ' flights',
               @now
        WHERE EXISTS (SELECT 1 FROM sys.tables WHERE name = 'adl_system_log');
    END
END
GO

PRINT 'Created sp_ProcessBoundaryDetectionBatch V7.0 (production-ready)';
PRINT 'Features: Smart edge detection, bounding box fallback, performance logging';
PRINT 'Target: <5 seconds for 5000 flights';
GO
