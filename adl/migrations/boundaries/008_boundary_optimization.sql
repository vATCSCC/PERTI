-- =====================================================
-- Phase 5E: Boundary Detection Optimization
-- Migration: 008_boundary_optimization.sql
-- Description: Adds bounding box columns and index for fast pre-filtering
-- =====================================================

-- Add bounding box columns for fast rectangular pre-filtering
-- This allows us to skip expensive STContains() checks for boundaries
-- that are clearly not near the flight position

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_boundary') AND name = 'bbox_min_lat')
BEGIN
    ALTER TABLE adl_boundary ADD bbox_min_lat DECIMAL(10,6) NULL;
    ALTER TABLE adl_boundary ADD bbox_max_lat DECIMAL(10,6) NULL;
    ALTER TABLE adl_boundary ADD bbox_min_lon DECIMAL(11,6) NULL;
    ALTER TABLE adl_boundary ADD bbox_max_lon DECIMAL(11,6) NULL;
    PRINT 'Added bounding box columns to adl_boundary';
END
GO

-- Populate bounding box values from the geography envelope
UPDATE b
SET
    bbox_min_lat = envelope.STPointN(1).Lat,
    bbox_max_lat = envelope.STPointN(3).Lat,
    bbox_min_lon = envelope.STPointN(1).Long,
    bbox_max_lon = envelope.STPointN(3).Long
FROM adl_boundary b
CROSS APPLY (
    SELECT b.boundary_geography.STEnvelope() AS envelope
) e
WHERE b.bbox_min_lat IS NULL;

PRINT 'Populated bounding box values for ' + CAST(@@ROWCOUNT AS VARCHAR) + ' boundaries';
GO

-- Create composite index for bounding box + type filtering
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_boundary_bbox_type' AND object_id = OBJECT_ID('adl_boundary'))
BEGIN
    CREATE INDEX IX_boundary_bbox_type
    ON adl_boundary(boundary_type, is_active, bbox_min_lat, bbox_max_lat, bbox_min_lon, bbox_max_lon)
    INCLUDE (boundary_id, boundary_code, is_oceanic, floor_altitude, ceiling_altitude);
    PRINT 'Created bounding box index IX_boundary_bbox_type';
END
GO

-- Create index for altitude-based sector filtering
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_boundary_altitude_range' AND object_id = OBJECT_ID('adl_boundary'))
BEGIN
    CREATE INDEX IX_boundary_altitude_range
    ON adl_boundary(boundary_type, is_active, floor_altitude, ceiling_altitude)
    INCLUDE (boundary_id, boundary_code, bbox_min_lat, bbox_max_lat, bbox_min_lon, bbox_max_lon);
    PRINT 'Created altitude range index IX_boundary_altitude_range';
END
GO

PRINT 'Boundary optimization migration completed';
GO
