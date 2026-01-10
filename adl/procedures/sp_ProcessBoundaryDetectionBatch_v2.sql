-- ============================================================================
-- sp_ProcessBoundaryDetectionBatch V8.0 - Aggressive Optimization
--
-- CRITICAL CHANGES:
--   - Only process flights where grid cell CHANGED (skip stable flights)
--   - Single ARTCC detection pass (most important)
--   - Skip TRACON for flights above FL180
--   - Skip sector detection entirely (low value, high cost)
--
-- Performance target: <1s for typical cycle (was 30-60s)
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

CREATE OR ALTER PROCEDURE dbo.sp_ProcessBoundaryDetectionBatch
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

    DECLARE @grid_size DECIMAL(5,3) = 0.5;

    -- ========================================================================
    -- Step 1: Find flights that NEED boundary detection
    -- Only: New flights OR grid cell changed
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
        c.last_grid_lat,
        c.last_grid_lon,
        -- Current grid cell
        CAST(FLOOR(p.lat / @grid_size) AS SMALLINT) AS grid_lat,
        CAST(FLOOR(p.lon / @grid_size) AS SMALLINT) AS grid_lon,
        -- Pre-compute geography point ONCE
        geography::Point(p.lat, p.lon, 4326) AS position_geo
    INTO #flights
    FROM dbo.adl_flight_core c
    JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    WHERE c.is_active = 1
      AND p.lat IS NOT NULL
      AND p.lat BETWEEN -90 AND 90
      AND p.lon BETWEEN -180 AND 180
      -- CRITICAL: Only process if no boundary OR grid changed
      AND (
          c.current_artcc_id IS NULL  -- No boundary yet
          OR c.last_grid_lat IS NULL  -- No cached grid
          OR c.last_grid_lat != CAST(FLOOR(p.lat / @grid_size) AS SMALLINT)
          OR c.last_grid_lon != CAST(FLOOR(p.lon / @grid_size) AS SMALLINT)
      );

    -- Use COUNT instead of @@ROWCOUNT (more reliable after connection recovery)
    SET @flights_processed = (SELECT COUNT(*) FROM #flights);

    IF @flights_processed = 0
    BEGIN
        SET @elapsed_ms = DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME());
        RETURN;
    END

    -- Index for grid lookup
    CREATE INDEX IX_f_grid ON #flights(grid_lat, grid_lon);

    -- ========================================================================
    -- Step 2: ARTCC Detection (single efficient query)
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
    ) artcc;

    -- Brute-force fallback for new flights with no grid match
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
      AND a.prev_artcc_id IS NULL;

    -- ========================================================================
    -- Step 3: TRACON Detection (only for low altitude flights)
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
          AND g.grid_lat = f.grid_lat
          AND g.grid_lon = f.grid_lon
          AND b.boundary_geography.STIntersects(f.position_geo) = 1
        ORDER BY g.boundary_area ASC
    ) tracon
    WHERE f.altitude_ft < 18000;  -- Only below FL180

    -- ========================================================================
    -- Step 4: Log ARTCC transitions
    -- ========================================================================

    -- Close previous ARTCC entries
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

    -- Log new ARTCC entries
    INSERT INTO dbo.adl_flight_boundary_log
        (flight_uid, boundary_id, boundary_type, boundary_code, entry_time, entry_lat, entry_lon, entry_altitude)
    SELECT a.flight_uid, a.new_artcc_id, 'ARTCC', a.new_artcc, @now, a.lat, a.lon, a.altitude_ft
    FROM #artcc_detection a
    WHERE a.new_artcc_id IS NOT NULL
      AND (a.prev_artcc_id IS NULL OR a.new_artcc_id != a.prev_artcc_id);

    SET @transitions_detected = @transitions_detected + @@ROWCOUNT;

    -- ========================================================================
    -- Step 5: Log TRACON transitions
    -- ========================================================================

    -- Close previous TRACON entries
    UPDATE log
    SET exit_time = @now, exit_lat = t.lat, exit_lon = t.lon,
        exit_altitude = t.altitude_ft, duration_seconds = DATEDIFF(SECOND, log.entry_time, @now)
    FROM dbo.adl_flight_boundary_log log
    JOIN #tracon_detection t ON log.flight_uid = t.flight_uid
    WHERE log.boundary_type = 'TRACON' AND log.exit_time IS NULL
      AND log.boundary_id = t.prev_tracon_id
      AND (t.new_tracon_id IS NULL OR t.new_tracon_id != t.prev_tracon_id);

    -- Log new TRACON entries
    INSERT INTO dbo.adl_flight_boundary_log
        (flight_uid, boundary_id, boundary_type, boundary_code, entry_time, entry_lat, entry_lon, entry_altitude)
    SELECT t.flight_uid, t.new_tracon_id, 'TRACON', t.new_tracon, @now, t.lat, t.lon, t.altitude_ft
    FROM #tracon_detection t
    WHERE t.new_tracon_id IS NOT NULL
      AND (t.prev_tracon_id IS NULL OR t.new_tracon_id != t.prev_tracon_id);

    SET @transitions_detected = @transitions_detected + @@ROWCOUNT;

    -- ========================================================================
    -- Step 6: Update flight_core
    -- ========================================================================

    -- ARTCC updates
    UPDATE c
    SET c.current_artcc = a.new_artcc,
        c.current_artcc_id = a.new_artcc_id,
        c.last_grid_lat = a.grid_lat,
        c.last_grid_lon = a.grid_lon,
        c.boundary_updated_at = @now
    FROM dbo.adl_flight_core c
    JOIN #artcc_detection a ON a.flight_uid = c.flight_uid;

    -- TRACON updates
    UPDATE c
    SET c.current_tracon = t.new_tracon,
        c.current_tracon_id = t.new_tracon_id
    FROM dbo.adl_flight_core c
    JOIN #tracon_detection t ON t.flight_uid = c.flight_uid;

    -- Clear TRACON for high-altitude flights (above FL180)
    UPDATE c
    SET c.current_tracon = NULL,
        c.current_tracon_id = NULL
    FROM dbo.adl_flight_core c
    JOIN #flights f ON f.flight_uid = c.flight_uid
    WHERE f.altitude_ft >= 18000
      AND c.current_tracon_id IS NOT NULL;

    -- Update grid cache for ALL processed flights
    UPDATE c
    SET c.last_grid_lat = f.grid_lat,
        c.last_grid_lon = f.grid_lon
    FROM dbo.adl_flight_core c
    JOIN #flights f ON f.flight_uid = c.flight_uid
    WHERE c.last_grid_lat IS NULL
       OR c.last_grid_lat != f.grid_lat
       OR c.last_grid_lon != f.grid_lon;

    -- ========================================================================
    -- Cleanup
    -- ========================================================================

    DROP TABLE IF EXISTS #flights;
    DROP TABLE IF EXISTS #artcc_detection;
    DROP TABLE IF EXISTS #tracon_detection;

    SET @elapsed_ms = DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME());
END
GO

PRINT 'Created sp_ProcessBoundaryDetectionBatch V8.0 (aggressive optimization)';
PRINT 'REMOVED: Sector detection (low value, high cost)';
PRINT 'CHANGED: Only process flights where grid cell changed';
PRINT 'Performance target: <1s (was 30-60s)';
GO
