-- =====================================================
-- ARTCC Boundary Hierarchy Classification
-- Migration: 011_artcc_hierarchy.sql
-- Database: VATSIM_ADL (Azure SQL)
-- Date: 2026-03-06
--
-- PURPOSE:
--   Reclassify sub-FIR/sub-ARTCC boundaries from boundary_type='ARTCC'
--   to 'ARTCC_SUB' so detection queries (which filter WHERE
--   boundary_type='ARTCC') naturally exclude them.
--
-- TWO-PHASE CLASSIFICATION:
--   Phase A: Dash-based (boundary_code LIKE '%-%')
--     e.g., EDGG-BAD, EGTT-D, KZMA-OCN → always sub-areas
--   Phase B: Spatial containment with coverage ratio heuristic
--     Finds non-dashed FIRs nested within larger FIRs.
--     Coverage ratio = sum(children_area) / parent_area
--       > 50%: children tile the parent → operational divisions (keep ARTCC)
--       < 50%: children are sparse sub-areas → mark ARTCC_SUB
--     Large parent exception: parents > 5M km² excluded (oceanic/
--     continental FIRs where children are real operational FIRs)
--
-- RESULTS:
--   - 522 dash-based boundaries → ARTCC_SUB
--   - 39 spatial-nested boundaries → ARTCC_SUB
--   - 385 remain as operational ARTCC
--   - Detection SP/grid queries unchanged (auto-excluded)
--   - New parent_fir column links sub-areas to parent FIR
--
-- SAFE DURING HIBERNATION: No active GIS daemons affected
-- =====================================================

-- Step 1: Add parent_fir column
IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('adl_boundary') AND name = 'parent_fir'
)
BEGIN
    ALTER TABLE adl_boundary ADD parent_fir VARCHAR(20) NULL;
    PRINT 'Added parent_fir column to adl_boundary';
END
ELSE
    PRINT 'parent_fir column already exists';
GO

-- Step 2: Drop and re-create CHECK constraint with ARTCC_SUB
-- Current constraint: CHK_boundary_type allows ARTCC, SECTOR_HIGH, SECTOR_LOW, SECTOR_SUPERHIGH, TRACON
IF EXISTS (
    SELECT 1 FROM sys.check_constraints
    WHERE name = 'CHK_boundary_type' AND parent_object_id = OBJECT_ID('adl_boundary')
)
BEGIN
    ALTER TABLE adl_boundary DROP CONSTRAINT CHK_boundary_type;
    PRINT 'Dropped existing CHK_boundary_type constraint';
END
GO

ALTER TABLE adl_boundary ADD CONSTRAINT CHK_boundary_type
    CHECK (boundary_type IN ('ARTCC', 'ARTCC_SUB', 'SECTOR_HIGH', 'SECTOR_LOW', 'SECTOR_SUPERHIGH', 'TRACON'));
PRINT 'Re-created CHK_boundary_type with ARTCC_SUB';
GO

-- Step 3: Create filtered index on parent_fir for sub-area lookups
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'IX_boundary_parent_fir' AND object_id = OBJECT_ID('adl_boundary')
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_boundary_parent_fir
    ON adl_boundary (parent_fir)
    WHERE parent_fir IS NOT NULL;
    PRINT 'Created IX_boundary_parent_fir index';
END
GO

-- Step 4: Reclassify existing sub-areas
-- Sub-areas are identified by dash in boundary_code (e.g., EDGG-BAD, EGTT-D, KZMA-OCN)
DECLARE @reclassified INT;

UPDATE adl_boundary
SET boundary_type = 'ARTCC_SUB',
    parent_fir = LEFT(boundary_code, CHARINDEX('-', boundary_code) - 1)
WHERE boundary_type = 'ARTCC'
  AND boundary_code LIKE '%-%';

SET @reclassified = @@ROWCOUNT;
PRINT 'Phase A: Reclassified ' + CAST(@reclassified AS VARCHAR) + ' dash-based boundaries to ARTCC_SUB';
GO

-- Step 4b: Phase B - Spatial containment reclassification
-- Finds non-dashed FIRs nested within larger FIRs using label point containment.
-- Uses coverage ratio heuristic: if children collectively cover < 50% of parent,
-- they are sub-areas. Exception: parents > 5M km² are oceanic/continental FIRs
-- whose children are real operational FIRs (e.g., NZAA within NZZO).
DECLARE @spatial_reclassified INT;

WITH artcc_only AS (
    SELECT boundary_id, boundary_code,
           boundary_geography.STArea() as geo_area,
           label_lat, label_lon, boundary_geography
    FROM adl_boundary
    WHERE boundary_type = 'ARTCC'
      AND is_active = 1
      AND boundary_geography IS NOT NULL
      AND label_lat IS NOT NULL
      AND label_lon IS NOT NULL
),
immediate_parent AS (
    SELECT
        child.boundary_code AS nested_code,
        child.geo_area AS child_area,
        parent.boundary_code AS parent_code,
        parent.geo_area AS parent_area,
        ROW_NUMBER() OVER (PARTITION BY child.boundary_code ORDER BY parent.geo_area ASC) as rn
    FROM artcc_only child
    JOIN artcc_only parent
        ON child.boundary_id != parent.boundary_id
        AND child.geo_area < parent.geo_area
        AND parent.boundary_geography.STIntersects(
            GEOGRAPHY::Point(child.label_lat, child.label_lon, 4326)
        ) = 1
    WHERE child.boundary_code NOT LIKE '%-%'
),
nesting AS (
    SELECT nested_code, child_area, parent_code, parent_area
    FROM immediate_parent
    WHERE rn = 1
),
parent_coverage AS (
    SELECT parent_code, parent_area,
           SUM(child_area) / parent_area AS coverage_ratio
    FROM nesting
    GROUP BY parent_code, parent_area
),
sub_areas AS (
    SELECT n.nested_code, n.parent_code
    FROM nesting n
    JOIN parent_coverage pc ON n.parent_code = pc.parent_code
    WHERE pc.coverage_ratio < 0.50
      AND pc.parent_area < 5e12  -- Large parent exception (~5M km²)
)
UPDATE b
SET boundary_type = 'ARTCC_SUB',
    parent_fir = sa.parent_code
FROM adl_boundary b
JOIN sub_areas sa ON b.boundary_code = sa.nested_code
WHERE b.boundary_type = 'ARTCC'
  AND b.is_active = 1;

SET @spatial_reclassified = @@ROWCOUNT;
PRINT 'Phase B: Reclassified ' + CAST(@spatial_reclassified AS VARCHAR) + ' spatial-nested FIRs to ARTCC_SUB';
GO

-- Step 5: Update sp_ImportBoundary to accept parent_fir parameter
CREATE OR ALTER PROCEDURE sp_ImportBoundary
    @boundary_type VARCHAR(20),
    @boundary_code VARCHAR(50),
    @boundary_name NVARCHAR(255) = NULL,
    @parent_artcc VARCHAR(10) = NULL,
    @sector_number VARCHAR(20) = NULL,
    @icao_code VARCHAR(10) = NULL,
    @vatsim_region VARCHAR(50) = NULL,
    @vatsim_division VARCHAR(50) = NULL,
    @vatsim_subdivision VARCHAR(50) = NULL,
    @is_oceanic BIT = 0,
    @floor_altitude INT = NULL,
    @ceiling_altitude INT = NULL,
    @label_lat DECIMAL(10,6) = NULL,
    @label_lon DECIMAL(11,6) = NULL,
    @wkt_geometry NVARCHAR(MAX),
    @shape_length DECIMAL(18,10) = NULL,
    @shape_area DECIMAL(18,10) = NULL,
    @source_object_id INT = NULL,
    @source_fid INT = NULL,
    @source_file VARCHAR(100) = NULL,
    @parent_fir VARCHAR(20) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @geometry GEOMETRY;
    DECLARE @geography GEOGRAPHY;
    DECLARE @oriented_geography GEOGRAPHY;
    DECLARE @boundary_id INT;

    BEGIN TRY
        -- Step 1: Parse as GEOMETRY first (more lenient than GEOGRAPHY)
        SET @geometry = GEOMETRY::STGeomFromText(@wkt_geometry, 4326);

        -- Step 2: Apply MakeValid on geometry to fix any issues
        IF @geometry.STIsValid() = 0
        BEGIN
            SET @geometry = @geometry.MakeValid();
        END

        -- Step 3: Ensure we have a polygon-type geometry
        IF @geometry.STGeometryType() = 'GeometryCollection'
        BEGIN
            DECLARE @i INT = 1;
            DECLARE @polyGeom GEOMETRY = NULL;
            WHILE @i <= @geometry.STNumGeometries()
            BEGIN
                DECLARE @part GEOMETRY = @geometry.STGeometryN(@i);
                IF @part.STGeometryType() IN ('Polygon', 'MultiPolygon')
                BEGIN
                    IF @polyGeom IS NULL
                        SET @polyGeom = @part;
                    ELSE
                        SET @polyGeom = @polyGeom.STUnion(@part);
                END
                SET @i = @i + 1;
            END
            IF @polyGeom IS NOT NULL
                SET @geometry = @polyGeom;
        END

        -- Step 4: Convert to geography
        SET @geography = GEOGRAPHY::STGeomFromText(@geometry.STAsText(), 4326);

        -- Step 5: Check if geography is valid, apply MakeValid if needed
        IF @geography.STIsValid() = 0
        BEGIN
            SET @geometry = GEOMETRY::STGeomFromText(@geography.STAsText(), 4326).MakeValid();
            SET @geography = GEOGRAPHY::STGeomFromText(@geometry.STAsText(), 4326);
        END

        -- Step 6: Apply polygon orientation fix for large polygons
        IF @geography.STArea() > 255000000000000
        BEGIN
            SET @oriented_geography = @geography.ReorientObject();
        END
        ELSE
        BEGIN
            SET @oriented_geography = @geography;
        END

        -- Step 7: Final validity check after reorientation
        IF @oriented_geography.STIsValid() = 0
        BEGIN
            SET @geometry = GEOMETRY::STGeomFromText(@oriented_geography.STAsText(), 4326).MakeValid();
            SET @oriented_geography = GEOGRAPHY::STGeomFromText(@geometry.STAsText(), 4326);
        END

        -- Check for existing boundary with same type and code
        DECLARE @existing_id INT;
        SELECT @existing_id = boundary_id
        FROM adl_boundary
        WHERE boundary_type = @boundary_type
          AND boundary_code = @boundary_code;

        IF @existing_id IS NOT NULL
        BEGIN
            -- Update existing
            UPDATE adl_boundary
            SET boundary_name = @boundary_name,
                parent_artcc = @parent_artcc,
                parent_fir = @parent_fir,
                sector_number = @sector_number,
                icao_code = @icao_code,
                vatsim_region = @vatsim_region,
                vatsim_division = @vatsim_division,
                vatsim_subdivision = @vatsim_subdivision,
                is_oceanic = @is_oceanic,
                floor_altitude = @floor_altitude,
                ceiling_altitude = @ceiling_altitude,
                label_lat = @label_lat,
                label_lon = @label_lon,
                boundary_geography = @oriented_geography,
                shape_length = @shape_length,
                shape_area = @shape_area,
                source_object_id = @source_object_id,
                source_fid = @source_fid,
                source_file = @source_file,
                is_active = 1,
                updated_at = GETUTCDATE()
            WHERE boundary_id = @existing_id;

            SET @boundary_id = @existing_id;
        END
        ELSE
        BEGIN
            -- Insert new
            INSERT INTO adl_boundary (
                boundary_type, boundary_code, boundary_name,
                parent_artcc, parent_fir, sector_number,
                icao_code, vatsim_region, vatsim_division, vatsim_subdivision,
                is_oceanic, floor_altitude, ceiling_altitude,
                label_lat, label_lon,
                boundary_geography,
                shape_length, shape_area,
                source_object_id, source_fid, source_file,
                is_active
            )
            VALUES (
                @boundary_type, @boundary_code, @boundary_name,
                @parent_artcc, @parent_fir, @sector_number,
                @icao_code, @vatsim_region, @vatsim_division, @vatsim_subdivision,
                @is_oceanic, @floor_altitude, @ceiling_altitude,
                @label_lat, @label_lon,
                @oriented_geography,
                @shape_length, @shape_area,
                @source_object_id, @source_fid, @source_file,
                1
            );

            SET @boundary_id = SCOPE_IDENTITY();
        END

    END TRY
    BEGIN CATCH
        PRINT 'Error importing boundary ' + @boundary_code + ': ' + ERROR_MESSAGE();
        SET @boundary_id = -1;
    END CATCH

    -- Return result for PHP to fetch
    SELECT @boundary_id as boundary_id;
END;
GO

-- Verification queries
PRINT '=== Verification ===';
SELECT boundary_type, COUNT(*) as [count]
FROM adl_boundary
WHERE is_active = 1
GROUP BY boundary_type
ORDER BY boundary_type;

SELECT COUNT(DISTINCT parent_fir) as distinct_parents
FROM adl_boundary
WHERE boundary_type = 'ARTCC_SUB';

-- Orphan check: sub-areas whose parent_fir doesn't match any ARTCC boundary_code
SELECT DISTINCT parent_fir as orphan_parent
FROM adl_boundary
WHERE boundary_type = 'ARTCC_SUB'
  AND parent_fir NOT IN (
      SELECT boundary_code FROM adl_boundary WHERE boundary_type = 'ARTCC'
  );

PRINT '011_artcc_hierarchy.sql migration complete';
GO
