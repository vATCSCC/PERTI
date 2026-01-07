-- ============================================================================
-- sp_ProcessBoundaryDetectionBatch.sql
-- Phase 5E.2: Batch boundary detection for all active flights
-- 
-- Detects:
--   - ARTCC (single value)
--   - SECTOR_LOW (multiple overlapping)
--   - SECTOR_HIGH (multiple overlapping)
--   - SECTOR_SUPERHIGH (multiple overlapping)
--   - TRACON (single value, below FL180 only)
-- 
-- Called from sp_Adl_RefreshFromVatsim_Normalized Step 10
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
        -- Current assignments
        c.current_artcc,
        c.current_artcc_id,
        c.current_sector_low,
        c.current_sector_high,
        c.current_sector_superhigh,
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
        RETURN;
    
    CREATE CLUSTERED INDEX IX_flights_uid ON #flights_to_check(flight_uid);
    
    -- ========================================================================
    -- Step 2: Detect ARTCC boundaries
    -- ========================================================================
    
    SELECT 
        f.flight_uid,
        f.lat,
        f.lon,
        f.altitude_ft,
        f.current_artcc AS prev_artcc,
        f.current_artcc_id AS prev_artcc_id,
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
    -- Step 3: Detect LOW sectors (all overlapping)
    -- ========================================================================
    
    -- Get all matching low sectors per flight
    SELECT 
        f.flight_uid,
        b.boundary_id,
        b.boundary_code
    INTO #low_sectors_raw
    FROM #flights_to_check f
    CROSS APPLY (
        SELECT boundary_id, boundary_code
        FROM dbo.adl_boundary b
        WHERE b.boundary_type = 'SECTOR_LOW'
          AND b.is_active = 1
          AND b.boundary_geography.STContains(f.position_geo) = 1
    ) b;
    
    -- Aggregate to comma-separated and JSON
    SELECT 
        f.flight_uid,
        f.lat,
        f.lon,
        f.altitude_ft,
        f.current_sector_low AS prev_sector_low,
        STUFF((
            SELECT ',' + ls.boundary_code
            FROM #low_sectors_raw ls
            WHERE ls.flight_uid = f.flight_uid
            ORDER BY ls.boundary_code
            FOR XML PATH('')
        ), 1, 1, '') AS new_sector_low,
        (
            SELECT ls.boundary_id AS id
            FROM #low_sectors_raw ls
            WHERE ls.flight_uid = f.flight_uid
            ORDER BY ls.boundary_code
            FOR JSON PATH
        ) AS new_sector_low_ids
    INTO #low_detection
    FROM #flights_to_check f;
    
    -- ========================================================================
    -- Step 4: Detect HIGH sectors (all overlapping)
    -- ========================================================================
    
    SELECT 
        f.flight_uid,
        b.boundary_id,
        b.boundary_code
    INTO #high_sectors_raw
    FROM #flights_to_check f
    CROSS APPLY (
        SELECT boundary_id, boundary_code
        FROM dbo.adl_boundary b
        WHERE b.boundary_type = 'SECTOR_HIGH'
          AND b.is_active = 1
          AND b.boundary_geography.STContains(f.position_geo) = 1
    ) b;
    
    SELECT 
        f.flight_uid,
        f.current_sector_high AS prev_sector_high,
        STUFF((
            SELECT ',' + hs.boundary_code
            FROM #high_sectors_raw hs
            WHERE hs.flight_uid = f.flight_uid
            ORDER BY hs.boundary_code
            FOR XML PATH('')
        ), 1, 1, '') AS new_sector_high,
        (
            SELECT hs.boundary_id AS id
            FROM #high_sectors_raw hs
            WHERE hs.flight_uid = f.flight_uid
            ORDER BY hs.boundary_code
            FOR JSON PATH
        ) AS new_sector_high_ids
    INTO #high_detection
    FROM #flights_to_check f;
    
    -- ========================================================================
    -- Step 5: Detect SUPERHIGH sectors (all overlapping)
    -- ========================================================================
    
    SELECT 
        f.flight_uid,
        b.boundary_id,
        b.boundary_code
    INTO #superhigh_sectors_raw
    FROM #flights_to_check f
    CROSS APPLY (
        SELECT boundary_id, boundary_code
        FROM dbo.adl_boundary b
        WHERE b.boundary_type = 'SECTOR_SUPERHIGH'
          AND b.is_active = 1
          AND b.boundary_geography.STContains(f.position_geo) = 1
    ) b;
    
    SELECT 
        f.flight_uid,
        f.current_sector_superhigh AS prev_sector_superhigh,
        STUFF((
            SELECT ',' + sh.boundary_code
            FROM #superhigh_sectors_raw sh
            WHERE sh.flight_uid = f.flight_uid
            ORDER BY sh.boundary_code
            FOR XML PATH('')
        ), 1, 1, '') AS new_sector_superhigh,
        (
            SELECT sh.boundary_id AS id
            FROM #superhigh_sectors_raw sh
            WHERE sh.flight_uid = f.flight_uid
            ORDER BY sh.boundary_code
            FOR JSON PATH
        ) AS new_sector_superhigh_ids
    INTO #superhigh_detection
    FROM #flights_to_check f;
    
    -- ========================================================================
    -- Step 6: Detect TRACON boundaries (below FL180)
    -- ========================================================================
    
    SELECT 
        f.flight_uid,
        f.lat,
        f.lon,
        f.altitude_ft,
        f.current_tracon AS prev_tracon,
        f.current_tracon_id AS prev_tracon_id,
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
    -- Step 7: Log ARTCC transitions
    -- ========================================================================
    
    -- Close existing ARTCC entries
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
    -- Step 8: Log LOW sector transitions (per individual sector)
    -- ========================================================================
    
    -- Close sectors we've left
    UPDATE log
    SET exit_time = @now,
        exit_lat = f.lat,
        exit_lon = f.lon,
        exit_altitude = f.altitude_ft,
        duration_seconds = DATEDIFF(SECOND, log.entry_time, @now)
    FROM dbo.adl_flight_boundary_log log
    JOIN #flights_to_check f ON log.flight_uid = f.flight_uid
    WHERE log.boundary_type = 'SECTOR_LOW'
      AND log.exit_time IS NULL
      AND NOT EXISTS (
          SELECT 1 FROM #low_sectors_raw ls 
          WHERE ls.flight_uid = log.flight_uid 
            AND ls.boundary_id = log.boundary_id
      );
    
    -- Insert new sector entries
    INSERT INTO dbo.adl_flight_boundary_log (
        flight_uid, boundary_id, boundary_type, boundary_code,
        entry_time, entry_lat, entry_lon, entry_altitude
    )
    SELECT 
        ls.flight_uid, ls.boundary_id, 'SECTOR_LOW', ls.boundary_code,
        @now, f.lat, f.lon, f.altitude_ft
    FROM #low_sectors_raw ls
    JOIN #flights_to_check f ON f.flight_uid = ls.flight_uid
    WHERE NOT EXISTS (
        SELECT 1 FROM dbo.adl_flight_boundary_log log
        WHERE log.flight_uid = ls.flight_uid
          AND log.boundary_id = ls.boundary_id
          AND log.exit_time IS NULL
    );
    
    SET @transitions_detected = @transitions_detected + @@ROWCOUNT;
    
    -- ========================================================================
    -- Step 9: Log HIGH sector transitions
    -- ========================================================================
    
    UPDATE log
    SET exit_time = @now,
        exit_lat = f.lat,
        exit_lon = f.lon,
        exit_altitude = f.altitude_ft,
        duration_seconds = DATEDIFF(SECOND, log.entry_time, @now)
    FROM dbo.adl_flight_boundary_log log
    JOIN #flights_to_check f ON log.flight_uid = f.flight_uid
    WHERE log.boundary_type = 'SECTOR_HIGH'
      AND log.exit_time IS NULL
      AND NOT EXISTS (
          SELECT 1 FROM #high_sectors_raw hs 
          WHERE hs.flight_uid = log.flight_uid 
            AND hs.boundary_id = log.boundary_id
      );
    
    INSERT INTO dbo.adl_flight_boundary_log (
        flight_uid, boundary_id, boundary_type, boundary_code,
        entry_time, entry_lat, entry_lon, entry_altitude
    )
    SELECT 
        hs.flight_uid, hs.boundary_id, 'SECTOR_HIGH', hs.boundary_code,
        @now, f.lat, f.lon, f.altitude_ft
    FROM #high_sectors_raw hs
    JOIN #flights_to_check f ON f.flight_uid = hs.flight_uid
    WHERE NOT EXISTS (
        SELECT 1 FROM dbo.adl_flight_boundary_log log
        WHERE log.flight_uid = hs.flight_uid
          AND log.boundary_id = hs.boundary_id
          AND log.exit_time IS NULL
    );
    
    SET @transitions_detected = @transitions_detected + @@ROWCOUNT;
    
    -- ========================================================================
    -- Step 10: Log SUPERHIGH sector transitions
    -- ========================================================================
    
    UPDATE log
    SET exit_time = @now,
        exit_lat = f.lat,
        exit_lon = f.lon,
        exit_altitude = f.altitude_ft,
        duration_seconds = DATEDIFF(SECOND, log.entry_time, @now)
    FROM dbo.adl_flight_boundary_log log
    JOIN #flights_to_check f ON log.flight_uid = f.flight_uid
    WHERE log.boundary_type = 'SECTOR_SUPERHIGH'
      AND log.exit_time IS NULL
      AND NOT EXISTS (
          SELECT 1 FROM #superhigh_sectors_raw sh 
          WHERE sh.flight_uid = log.flight_uid 
            AND sh.boundary_id = log.boundary_id
      );
    
    INSERT INTO dbo.adl_flight_boundary_log (
        flight_uid, boundary_id, boundary_type, boundary_code,
        entry_time, entry_lat, entry_lon, entry_altitude
    )
    SELECT 
        sh.flight_uid, sh.boundary_id, 'SECTOR_SUPERHIGH', sh.boundary_code,
        @now, f.lat, f.lon, f.altitude_ft
    FROM #superhigh_sectors_raw sh
    JOIN #flights_to_check f ON f.flight_uid = sh.flight_uid
    WHERE NOT EXISTS (
        SELECT 1 FROM dbo.adl_flight_boundary_log log
        WHERE log.flight_uid = sh.flight_uid
          AND log.boundary_id = sh.boundary_id
          AND log.exit_time IS NULL
    );
    
    SET @transitions_detected = @transitions_detected + @@ROWCOUNT;
    
    -- ========================================================================
    -- Step 11: Log TRACON transitions
    -- ========================================================================
    
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
    -- Cleanup
    -- ========================================================================
    
    DROP TABLE IF EXISTS #flights_to_check;
    DROP TABLE IF EXISTS #artcc_detection;
    DROP TABLE IF EXISTS #low_sectors_raw;
    DROP TABLE IF EXISTS #low_detection;
    DROP TABLE IF EXISTS #high_sectors_raw;
    DROP TABLE IF EXISTS #high_detection;
    DROP TABLE IF EXISTS #superhigh_sectors_raw;
    DROP TABLE IF EXISTS #superhigh_detection;
    DROP TABLE IF EXISTS #tracon_detection;
    
END
GO

PRINT 'Created stored procedure dbo.sp_ProcessBoundaryDetectionBatch';
PRINT 'Detects: ARTCC, SECTOR_LOW, SECTOR_HIGH, SECTOR_SUPERHIGH, TRACON';
PRINT 'Supports multiple overlapping sectors per type';
GO
