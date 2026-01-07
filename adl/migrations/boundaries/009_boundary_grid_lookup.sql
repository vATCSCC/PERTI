-- ============================================================================
-- Boundary Grid Lookup Table
--
-- Creates a pre-computed lookup table mapping geographic grid cells to
-- overlapping boundaries. This converts expensive spatial queries to
-- simple integer lookups.
--
-- Grid resolution: 0.5 degree (~30nm at mid-latitudes)
-- Coverage: -90 to 90 lat, -180 to 180 lon = 360 x 720 = 259,200 cells max
-- ============================================================================

-- Drop existing table if it exists
IF OBJECT_ID('dbo.adl_boundary_grid', 'U') IS NOT NULL
    DROP TABLE dbo.adl_boundary_grid;
GO

-- Create grid lookup table
CREATE TABLE dbo.adl_boundary_grid (
    grid_id INT IDENTITY(1,1) PRIMARY KEY,
    grid_lat SMALLINT NOT NULL,      -- Grid cell latitude index (-180 to 180)
    grid_lon SMALLINT NOT NULL,      -- Grid cell longitude index (-360 to 360)
    boundary_type VARCHAR(20) NOT NULL,
    boundary_id INT NOT NULL,
    boundary_code VARCHAR(20) NOT NULL,
    is_oceanic BIT DEFAULT 0,
    boundary_area FLOAT NULL,        -- For sorting overlaps
    CONSTRAINT FK_grid_boundary FOREIGN KEY (boundary_id) REFERENCES adl_boundary(boundary_id)
);
GO

-- Create indexes for fast lookup
CREATE UNIQUE CLUSTERED INDEX IX_grid_cell ON adl_boundary_grid(grid_lat, grid_lon, boundary_type, boundary_id);
CREATE INDEX IX_grid_type ON adl_boundary_grid(boundary_type, grid_lat, grid_lon) INCLUDE (boundary_id, boundary_code, is_oceanic, boundary_area);
GO

-- Populate the grid for ARTCC boundaries
-- This iterates through each 0.5-degree cell and checks if the boundary intersects
PRINT 'Starting ARTCC grid population...';

DECLARE @lat_min INT = -90, @lat_max INT = 90;
DECLARE @lon_min INT = -180, @lon_max INT = 180;
DECLARE @grid_size DECIMAL(5,2) = 0.5;

DECLARE @lat DECIMAL(10,6), @lon DECIMAL(11,6);
DECLARE @grid_lat SMALLINT, @grid_lon SMALLINT;

-- For ARTCC boundaries
INSERT INTO adl_boundary_grid (grid_lat, grid_lon, boundary_type, boundary_id, boundary_code, is_oceanic, boundary_area)
SELECT DISTINCT
    CAST(FLOOR((@lat_min + (n.number * @grid_size)) / @grid_size) AS SMALLINT) AS grid_lat,
    CAST(FLOOR((@lon_min + (m.number * @grid_size)) / @grid_size) AS SMALLINT) AS grid_lon,
    b.boundary_type,
    b.boundary_id,
    b.boundary_code,
    b.is_oceanic,
    b.boundary_geography.STArea()
FROM dbo.adl_boundary b
-- Generate grid cells that this boundary might cover
CROSS JOIN (SELECT number FROM master.dbo.spt_values WHERE type = 'P' AND number < 720) n  -- lat cells
CROSS JOIN (SELECT number FROM master.dbo.spt_values WHERE type = 'P' AND number < 720) m  -- lon cells
WHERE b.boundary_type IN ('ARTCC', 'TRACON')
  AND b.is_active = 1
  -- Cell is within boundary's bounding box (coarse filter)
  AND (@lat_min + (n.number * @grid_size)) BETWEEN b.bbox_min_lat - @grid_size AND b.bbox_max_lat + @grid_size
  AND (@lon_min + (m.number * @grid_size)) BETWEEN b.bbox_min_lon - @grid_size AND b.bbox_max_lon + @grid_size
  -- Cell center intersects boundary (precise check)
  AND b.boundary_geography.STIntersects(
      geography::Point(
          @lat_min + (n.number * @grid_size) + (@grid_size / 2),
          @lon_min + (m.number * @grid_size) + (@grid_size / 2),
          4326
      )
  ) = 1;

PRINT 'Grid cells populated: ' + CAST(@@ROWCOUNT AS VARCHAR);
GO

PRINT 'Boundary grid lookup table created';
GO
