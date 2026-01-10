-- ============================================================================
-- 009b_populate_all_boundary_grids.sql
--
-- Populates the boundary grid lookup table for ALL boundary types:
--   - TRACON (Terminal radar approach control)
--   - SECTOR_LOW (Low altitude sectors)
--   - SECTOR_HIGH (High altitude sectors)
--   - SECTOR_SUPERHIGH (Super high altitude sectors)
--
-- Compatible with Azure SQL Database (no master.dbo.spt_values dependency)
-- ============================================================================

SET NOCOUNT ON;

PRINT '============================================================================';
PRINT 'Populating boundary grid for all types';
PRINT 'Grid size: 0.5 degrees (~30nm)';
PRINT '============================================================================';
PRINT '';

DECLARE @grid_size DECIMAL(5,3) = 0.5;
DECLARE @lat_min INT = -90;
DECLARE @lon_min INT = -180;
DECLARE @start_time DATETIME2 = SYSUTCDATETIME();
DECLARE @type_start DATETIME2;
DECLARE @count INT;

-- ============================================================================
-- Create a numbers table for grid cell generation (Azure SQL compatible)
-- ============================================================================

IF OBJECT_ID('tempdb..#Numbers') IS NOT NULL DROP TABLE #Numbers;

-- Generate numbers 0-719 using recursive CTE
;WITH N AS (
    SELECT 0 AS num
    UNION ALL
    SELECT num + 1 FROM N WHERE num < 719
)
SELECT num AS number INTO #Numbers FROM N
OPTION (MAXRECURSION 720);

CREATE INDEX IX_num ON #Numbers(number);

DECLARE @num_count INT;
SELECT @num_count = COUNT(*) FROM #Numbers;
PRINT CONCAT('Generated ', @num_count, ' grid cell indices');
PRINT '';

-- ============================================================================
-- Clear existing grid data for types we're repopulating
-- ============================================================================

PRINT 'Clearing existing grid data...';

DELETE FROM dbo.adl_boundary_grid
WHERE boundary_type IN ('TRACON', 'SECTOR_LOW', 'SECTOR_HIGH', 'SECTOR_SUPERHIGH');

PRINT CONCAT('  Deleted ', @@ROWCOUNT, ' existing grid cells');
PRINT '';

-- ============================================================================
-- TRACON Grid Population
-- ============================================================================

SET @type_start = SYSUTCDATETIME();
PRINT 'Populating TRACON grid...';

-- Note: Latitude range is -90 to 90 (360 cells), Longitude is -180 to 180 (720 cells)
-- n = latitude index (0-359), m = longitude index (0-719)

INSERT INTO adl_boundary_grid (grid_lat, grid_lon, boundary_type, boundary_id, boundary_code, is_oceanic, boundary_area)
SELECT DISTINCT
    CAST(FLOOR((@lat_min + (n.number * @grid_size)) / @grid_size) AS SMALLINT) AS grid_lat,
    CAST(FLOOR((@lon_min + (m.number * @grid_size)) / @grid_size) AS SMALLINT) AS grid_lon,
    b.boundary_type,
    b.boundary_id,
    b.boundary_code,
    ISNULL(b.is_oceanic, 0),
    b.boundary_geography.STArea()
FROM dbo.adl_boundary b
CROSS JOIN #Numbers n
CROSS JOIN #Numbers m
WHERE b.boundary_type = 'TRACON'
  AND b.is_active = 1
  AND b.boundary_geography IS NOT NULL
  AND b.bbox_min_lat IS NOT NULL
  AND n.number < 360  -- Limit latitude to valid range
  AND (@lat_min + (n.number * @grid_size)) BETWEEN b.bbox_min_lat - @grid_size AND b.bbox_max_lat + @grid_size
  AND (@lon_min + (m.number * @grid_size)) BETWEEN b.bbox_min_lon - @grid_size AND b.bbox_max_lon + @grid_size
  AND b.boundary_geography.STIntersects(
      geography::Point(
          @lat_min + (n.number * @grid_size) + (@grid_size / 2),
          @lon_min + (m.number * @grid_size) + (@grid_size / 2),
          4326
      )
  ) = 1;

SET @count = @@ROWCOUNT;
PRINT CONCAT('  TRACON: ', @count, ' cells indexed in ', DATEDIFF(SECOND, @type_start, SYSUTCDATETIME()), 's');

-- ============================================================================
-- SECTOR_LOW Grid Population
-- ============================================================================

SET @type_start = SYSUTCDATETIME();
PRINT 'Populating SECTOR_LOW grid...';

INSERT INTO adl_boundary_grid (grid_lat, grid_lon, boundary_type, boundary_id, boundary_code, is_oceanic, boundary_area)
SELECT DISTINCT
    CAST(FLOOR((@lat_min + (n.number * @grid_size)) / @grid_size) AS SMALLINT) AS grid_lat,
    CAST(FLOOR((@lon_min + (m.number * @grid_size)) / @grid_size) AS SMALLINT) AS grid_lon,
    b.boundary_type,
    b.boundary_id,
    b.boundary_code,
    ISNULL(b.is_oceanic, 0),
    b.boundary_geography.STArea()
FROM dbo.adl_boundary b
CROSS JOIN #Numbers n
CROSS JOIN #Numbers m
WHERE b.boundary_type = 'SECTOR_LOW'
  AND b.is_active = 1
  AND b.boundary_geography IS NOT NULL
  AND b.bbox_min_lat IS NOT NULL
  AND n.number < 360  -- Limit latitude to valid range
  AND (@lat_min + (n.number * @grid_size)) BETWEEN b.bbox_min_lat - @grid_size AND b.bbox_max_lat + @grid_size
  AND (@lon_min + (m.number * @grid_size)) BETWEEN b.bbox_min_lon - @grid_size AND b.bbox_max_lon + @grid_size
  AND b.boundary_geography.STIntersects(
      geography::Point(
          @lat_min + (n.number * @grid_size) + (@grid_size / 2),
          @lon_min + (m.number * @grid_size) + (@grid_size / 2),
          4326
      )
  ) = 1;

SET @count = @@ROWCOUNT;
PRINT CONCAT('  SECTOR_LOW: ', @count, ' cells indexed in ', DATEDIFF(SECOND, @type_start, SYSUTCDATETIME()), 's');

-- ============================================================================
-- SECTOR_HIGH Grid Population
-- ============================================================================

SET @type_start = SYSUTCDATETIME();
PRINT 'Populating SECTOR_HIGH grid...';

INSERT INTO adl_boundary_grid (grid_lat, grid_lon, boundary_type, boundary_id, boundary_code, is_oceanic, boundary_area)
SELECT DISTINCT
    CAST(FLOOR((@lat_min + (n.number * @grid_size)) / @grid_size) AS SMALLINT) AS grid_lat,
    CAST(FLOOR((@lon_min + (m.number * @grid_size)) / @grid_size) AS SMALLINT) AS grid_lon,
    b.boundary_type,
    b.boundary_id,
    b.boundary_code,
    ISNULL(b.is_oceanic, 0),
    b.boundary_geography.STArea()
FROM dbo.adl_boundary b
CROSS JOIN #Numbers n
CROSS JOIN #Numbers m
WHERE b.boundary_type = 'SECTOR_HIGH'
  AND b.is_active = 1
  AND b.boundary_geography IS NOT NULL
  AND b.bbox_min_lat IS NOT NULL
  AND n.number < 360  -- Limit latitude to valid range
  AND (@lat_min + (n.number * @grid_size)) BETWEEN b.bbox_min_lat - @grid_size AND b.bbox_max_lat + @grid_size
  AND (@lon_min + (m.number * @grid_size)) BETWEEN b.bbox_min_lon - @grid_size AND b.bbox_max_lon + @grid_size
  AND b.boundary_geography.STIntersects(
      geography::Point(
          @lat_min + (n.number * @grid_size) + (@grid_size / 2),
          @lon_min + (m.number * @grid_size) + (@grid_size / 2),
          4326
      )
  ) = 1;

SET @count = @@ROWCOUNT;
PRINT CONCAT('  SECTOR_HIGH: ', @count, ' cells indexed in ', DATEDIFF(SECOND, @type_start, SYSUTCDATETIME()), 's');

-- ============================================================================
-- SECTOR_SUPERHIGH Grid Population
-- ============================================================================

SET @type_start = SYSUTCDATETIME();
PRINT 'Populating SECTOR_SUPERHIGH grid...';

INSERT INTO adl_boundary_grid (grid_lat, grid_lon, boundary_type, boundary_id, boundary_code, is_oceanic, boundary_area)
SELECT DISTINCT
    CAST(FLOOR((@lat_min + (n.number * @grid_size)) / @grid_size) AS SMALLINT) AS grid_lat,
    CAST(FLOOR((@lon_min + (m.number * @grid_size)) / @grid_size) AS SMALLINT) AS grid_lon,
    b.boundary_type,
    b.boundary_id,
    b.boundary_code,
    ISNULL(b.is_oceanic, 0),
    b.boundary_geography.STArea()
FROM dbo.adl_boundary b
CROSS JOIN #Numbers n
CROSS JOIN #Numbers m
WHERE b.boundary_type = 'SECTOR_SUPERHIGH'
  AND b.is_active = 1
  AND b.boundary_geography IS NOT NULL
  AND b.bbox_min_lat IS NOT NULL
  AND n.number < 360  -- Limit latitude to valid range
  AND (@lat_min + (n.number * @grid_size)) BETWEEN b.bbox_min_lat - @grid_size AND b.bbox_max_lat + @grid_size
  AND (@lon_min + (m.number * @grid_size)) BETWEEN b.bbox_min_lon - @grid_size AND b.bbox_max_lon + @grid_size
  AND b.boundary_geography.STIntersects(
      geography::Point(
          @lat_min + (n.number * @grid_size) + (@grid_size / 2),
          @lon_min + (m.number * @grid_size) + (@grid_size / 2),
          4326
      )
  ) = 1;

SET @count = @@ROWCOUNT;
PRINT CONCAT('  SECTOR_SUPERHIGH: ', @count, ' cells indexed in ', DATEDIFF(SECOND, @type_start, SYSUTCDATETIME()), 's');

-- ============================================================================
-- Cleanup and Summary
-- ============================================================================

DROP TABLE #Numbers;

PRINT '';
PRINT '============================================================================';
PRINT 'Grid Population Complete';
PRINT CONCAT('Total elapsed: ', DATEDIFF(SECOND, @start_time, SYSUTCDATETIME()), ' seconds');
PRINT '';
PRINT 'Final grid counts by type:';

SELECT boundary_type, COUNT(DISTINCT boundary_id) AS boundaries, COUNT(*) AS cells
FROM dbo.adl_boundary_grid
GROUP BY boundary_type
ORDER BY boundary_type;

PRINT '============================================================================';
GO
