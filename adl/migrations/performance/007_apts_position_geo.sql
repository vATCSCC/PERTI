-- ============================================================================
-- 007: Pre-compute geography on apts table
--
-- Adds a position_geo geography column to the apts table and populates it
-- from LAT_DECIMAL / LONG_DECIMAL. This eliminates ~8,500 geography::Point()
-- CLR constructions per SP cycle by allowing direct use of pre-computed
-- geography objects in distance calculations.
--
-- Used by: sp_Adl_RefreshFromVatsim_Staged V9.4.0 (Steps 1b, 2a)
-- ============================================================================

SET NOCOUNT ON;

-- Step 1: Add column if it doesn't already exist
IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.apts') AND name = 'position_geo'
)
BEGIN
    ALTER TABLE dbo.apts ADD position_geo geography NULL;
    PRINT 'Added position_geo column to dbo.apts';
END
ELSE
BEGIN
    PRINT 'Column position_geo already exists on dbo.apts - skipping ALTER';
END
GO

-- Step 2: Populate from existing lat/lon
UPDATE dbo.apts
SET position_geo = geography::Point(LAT_DECIMAL, LONG_DECIMAL, 4326)
WHERE position_geo IS NULL
  AND LAT_DECIMAL IS NOT NULL AND LONG_DECIMAL IS NOT NULL
  AND LAT_DECIMAL BETWEEN -90 AND 90
  AND LONG_DECIMAL BETWEEN -180 AND 180;

DECLARE @populated INT = @@ROWCOUNT;
PRINT 'Populated position_geo for ' + CAST(@populated AS VARCHAR) + ' airports';
GO

-- Step 3: Create spatial index (optional, not used by SP but useful for future spatial queries)
IF NOT EXISTS (
    SELECT 1 FROM sys.spatial_indexes
    WHERE object_id = OBJECT_ID('dbo.apts') AND name = 'IX_apts_position_geo'
)
BEGIN
    CREATE SPATIAL INDEX IX_apts_position_geo ON dbo.apts (position_geo);
    PRINT 'Created spatial index IX_apts_position_geo';
END
ELSE
BEGIN
    PRINT 'Spatial index IX_apts_position_geo already exists - skipping';
END
GO

-- Verification
SELECT
    COUNT(*) AS total_airports,
    COUNT(position_geo) AS with_geography,
    COUNT(*) - COUNT(position_geo) AS without_geography
FROM dbo.apts;
GO
