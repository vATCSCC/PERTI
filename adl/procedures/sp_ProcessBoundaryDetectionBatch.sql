-- ============================================================================
-- sp_ProcessBoundaryDetectionBatch.sql
-- Phase 5E.2: Batch boundary detection for all active flights
-- 
-- Detects ARTCC, sector, and TRACON boundaries for active flights
-- Called from the main refresh procedure
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
    @flights_processed INT = NULL OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @now DATETIME2(0) = SYSUTCDATETIME();
    SET @transitions_detected = 0;
    SET @flights_processed = 0;
    
    -- ========================================================================
    -- Step 1: Identify active flights with valid positions
    -- ========================================================================
    
    SELECT 
        c.flight_uid,
        p.lat,
        p.lon,
        p.altitude_ft,
        -- Current boundary assignments
        c.current_artcc,
        c.current_artcc_id,
        c.current_sector,
        c.current_sector_id,
        c.current_tracon,
        c.current_tracon_id,
        -- Geography point for containment queries
        geography::Point(p.lat, p.lon, 4326) AS position_geo
    INTO #flights_to_check
    FROM dbo.adl_flight_core c
    JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    WHERE c.is_active = 1
      AND p.lat IS NOT NULL
      AND p.lon IS NOT NULL
      AND p.lat BETWEEN -90 AND 90
      AND p.lon BETWEEN -180 AND 180;
    
    SET @flights_processed = @@ROWCOUNT;
    
    IF @flights_processed = 0
    BEGIN
        RETURN;
    END
    
    -- Index for joins
    CREATE CLUSTERED INDEX IX_flights_uid ON #flights_to_check(flight_uid);
    
    -- ========================================================================
    -- Step 2: Detect ARTCC boundaries (always check)
    -- ========================================================================
    
    SELECT 
        f.flight_uid,
        f.lat,
        f.lon,
        f.altitude_ft,
        f.current_artcc AS prev_artcc,
        f.current_artcc_id AS prev_artcc_id,
        -- Find containing ARTCC (prefer non-oceanic, smallest area)
        (SELECT TOP 1 b.boundary_id
         FROM dbo.adl_boundary b
         WHERE b.boundary_type = 'ARTCC'
           AND b.is_active = 1
           AND b.boundary_geography.STContains(f.position_geo) = 1
         ORDER BY b.is_oceanic ASC, b.boundary_geography.STArea() ASC) AS new_artcc_id,
        (SELECT TOP 1 b.boundary_code
         FROM dbo.adl_boundary b
         WHERE b.boundary_type = 'ARTCC'
           AND b.is_active = 1
           AND b.boundary_geography.STContains(f.position_geo) = 1
         ORDER BY b.is_oceanic ASC, b.boundary_geography.STArea() ASC) AS new_artcc
    INTO #artcc_detection
    FROM #flights_to_check f;
    
    -- ========================================================================
    -- Step 3: Detect Sector boundaries (for flights in US airspace)
    -- ========================================================================
    
    SELECT 
        f.flight_uid,
        f.lat,
        f.lon,
        f.altitude_ft,
        f.current_sector AS prev_sector,
        f.current_sector_id AS prev_sector_id,
        -- Find containing sector (smallest area, altitude-appropriate)
        (SELECT TOP 1 b.boundary_id
         FROM dbo.adl_boundary b
         WHERE b.boundary_type IN ('SECTOR_HIGH', 'SECTOR_LOW', 'SECTOR_SUPERHIGH')
           AND b.is_active = 1
           AND b.boundary_geography.STContains(f.position_geo) = 1
           -- Altitude filtering: skip sectors where flight is clearly outside altitude range
           AND (b.floor_altitude IS NULL OR f.altitude_ft >= b.floor_altitude * 100)
           AND (b.ceiling_altitude IS NULL OR f.altitude_ft <= b.ceiling_altitude * 100)
         ORDER BY b.boundary_geography.STArea() ASC) AS new_sector_id,
        (SELECT TOP 1 b.boundary_code
         FROM dbo.adl_boundary b
         WHERE b.boundary_type IN ('SECTOR_HIGH', 'SECTOR_LOW', 'SECTOR_SUPERHIGH')
           AND b.is_active = 1
           AND b.boundary_geography.STContains(f.position_geo) = 1
           AND (b.floor_altitude IS NULL OR f.altitude_ft >= b.floor_altitude * 100)
           AND (b.ceiling_altitude IS NULL OR f.altitude_ft <= b.ceiling_altitude * 100)
         ORDER BY b.boundary_geography.STArea() ASC) AS new_sector,
        (SELECT TOP 1 b.boundary_type
         FROM dbo.adl_boundary b
         WHERE b.boundary_type IN ('SECTOR_HIGH', 'SECTOR_LOW', 'SECTOR_SUPERHIGH')
           AND b.is_active = 1
           AND b.boundary_geography.STContains(f.position_geo) = 1
           AND (b.floor_altitude IS NULL OR f.altitude_ft >= b.floor_altitude * 100)
           AND (b.ceiling_altitude IS NULL OR f.altitude_ft <= b.ceiling_altitude * 100)
         ORDER BY b.boundary_geography.STArea() ASC) AS new_sector_type
    INTO #sector_detection
    FROM #flights_to_check f;
    
    -- ========================================================================
    -- Step 4: Detect TRACON boundaries (for low-altitude flights)
    -- ========================================================================
    
    SELECT 
        f.flight_uid,
        f.lat,
        f.lon,
        f.altitude_ft,
        f.current_tracon AS prev_tracon,
        f.current_tracon_id AS prev_tracon_id,
        -- Find containing TRACON (smallest area, only for low altitude)
        CASE WHEN f.altitude_ft < 18000 THEN
            (SELECT TOP 1 b.boundary_id
             FROM dbo.adl_boundary b
             WHERE b.boundary_type = 'TRACON'
               AND b.is_active = 1
               AND b.boundary_geography.STContains(f.position_geo) = 1
             ORDER BY b.boundary_geography.STArea() ASC)
        ELSE NULL END AS new_tracon_id,
        CASE WHEN f.altitude_ft < 18000 THEN
            (SELECT TOP 1 b.boundary_code
             FROM dbo.adl_boundary b
             WHERE b.boundary_type = 'TRACON'
               AND b.is_active = 1
               AND b.boundary_geography.STContains(f.position_geo) = 1
             ORDER BY b.boundary_geography.STArea() ASC)
        ELSE NULL END AS new_tracon
    INTO #tracon_detection
    FROM #flights_to_check f;
    
    -- ========================================================================
    -- Step 5: Log ARTCC transitions
    -- ========================================================================
    
    -- Close existing ARTCC entries where boundary changed
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
    
    -- Insert new ARTCC entries
    INSERT INTO dbo.adl_flight_boundary_log (
        flight_uid, boundary_id, boundary_type, boundary_code,
        entry_time, entry_lat, entry_lon, entry_altitude
    )
    SELECT 
        a.flight_uid, a.new_artcc_id, 'ARTCC', a.new_artcc,
        @now, a.lat, a.lon, a.altitude_ft
    FROM #artcc_detection a
    WHERE a.new_artcc_id IS NOT NULL
      AND (a.prev_artcc_id IS NULL OR a.new_artcc_id != a.prev_artcc_id);
    
    SET @transitions_detected = @transitions_detected + @@ROWCOUNT;
    
    -- ========================================================================
    -- Step 6: Log Sector transitions
    -- ========================================================================
    
    -- Close existing sector entries where boundary changed
    UPDATE log
    SET exit_time = @now,
        exit_lat = s.lat,
        exit_lon = s.lon,
        exit_altitude = s.altitude_ft,
        duration_seconds = DATEDIFF(SECOND, log.entry_time, @now)
    FROM dbo.adl_flight_boundary_log log
    JOIN #sector_detection s ON log.flight_uid = s.flight_uid
    WHERE log.boundary_type IN ('SECTOR_HIGH', 'SECTOR_LOW', 'SECTOR_SUPERHIGH')
      AND log.exit_time IS NULL
      AND log.boundary_id = s.prev_sector_id
      AND (s.new_sector_id IS NULL OR s.new_sector_id != s.prev_sector_id);
    
    -- Insert new sector entries
    INSERT INTO dbo.adl_flight_boundary_log (
        flight_uid, boundary_id, boundary_type, boundary_code,
        entry_time, entry_lat, entry_lon, entry_altitude
    )
    SELECT 
        s.flight_uid, s.new_sector_id, s.new_sector_type, s.new_sector,
        @now, s.lat, s.lon, s.altitude_ft
    FROM #sector_detection s
    WHERE s.new_sector_id IS NOT NULL
      AND (s.prev_sector_id IS NULL OR s.new_sector_id != s.prev_sector_id);
    
    SET @transitions_detected = @transitions_detected + @@ROWCOUNT;
    
    -- ========================================================================
    -- Step 7: Log TRACON transitions
    -- ========================================================================
    
    -- Close existing TRACON entries where boundary changed
    UPDATE log
    SET exit_time = @now,
        exit_lat = t.lat,
        exit_lon = t.lon,
        exit_altitude = t.altitude_ft,
        duration_seconds = DATEDIFF(SECOND, log.entry_time, @now)
    FROM dbo.adl_flight_boundary_log log
    JOIN #tracon_detection t ON log.flight_uid = t.flight_uid
    WHERE log.boundary_type = 'TRACON'
      AND log.exit_time IS NULL
      AND log.boundary_id = t.prev_tracon_id
      AND (t.new_tracon_id IS NULL OR t.new_tracon_id != t.prev_tracon_id);
    
    -- Insert new TRACON entries
    INSERT INTO dbo.adl_flight_boundary_log (
        flight_uid, boundary_id, boundary_type, boundary_code,
        entry_time, entry_lat, entry_lon, entry_altitude
    )
    SELECT 
        t.flight_uid, t.new_tracon_id, 'TRACON', t.new_tracon,
        @now, t.lat, t.lon, t.altitude_ft
    FROM #tracon_detection t
    WHERE t.new_tracon_id IS NOT NULL
      AND (t.prev_tracon_id IS NULL OR t.new_tracon_id != t.prev_tracon_id);
    
    SET @transitions_detected = @transitions_detected + @@ROWCOUNT;
    
    -- ========================================================================
    -- Step 8: Update flight_core with current boundaries
    -- ========================================================================
    
    UPDATE c
    SET c.current_artcc = a.new_artcc,
        c.current_artcc_id = a.new_artcc_id,
        c.current_sector = s.new_sector,
        c.current_sector_id = s.new_sector_id,
        c.current_tracon = t.new_tracon,
        c.current_tracon_id = t.new_tracon_id,
        c.boundary_updated_at = @now
    FROM dbo.adl_flight_core c
    JOIN #artcc_detection a ON a.flight_uid = c.flight_uid
    JOIN #sector_detection s ON s.flight_uid = c.flight_uid
    JOIN #tracon_detection t ON t.flight_uid = c.flight_uid;
    
    -- ========================================================================
    -- Cleanup
    -- ========================================================================
    
    DROP TABLE IF EXISTS #flights_to_check;
    DROP TABLE IF EXISTS #artcc_detection;
    DROP TABLE IF EXISTS #sector_detection;
    DROP TABLE IF EXISTS #tracon_detection;
    
END
GO

PRINT 'Created stored procedure dbo.sp_ProcessBoundaryDetectionBatch';
GO
