-- =====================================================
-- Multi-Level ARTCC/FIR Boundary Hierarchy
-- Migration: 012_boundary_hierarchy.sql
-- Database: VATSIM_ADL (Azure SQL)
-- Date: 2026-03-07
--
-- PURPOSE:
--   Add hierarchy_level and hierarchy_type columns to adl_boundary.
--   Expand CHECK constraint for ARTCC_SUPER and ARTCC_SUB_3..6.
--   Create boundary_hierarchy edge table for parent-child tree.
--   Update sp_ImportBoundary with hierarchy params + ARTCC-family upsert logic.
--   Backfill existing rows with approximate hierarchy values.
--
-- DEPENDS ON: 011_artcc_hierarchy.sql (parent_fir column, ARTCC_SUB type)
-- SAFE DURING HIBERNATION: No active GIS daemons affected
-- =====================================================

-- =====================
-- Step 1A: Add hierarchy columns to adl_boundary
-- =====================
IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('adl_boundary') AND name = 'hierarchy_level'
)
BEGIN
    ALTER TABLE adl_boundary ADD hierarchy_level TINYINT NULL;
    PRINT 'Added hierarchy_level column to adl_boundary';
END
ELSE
    PRINT 'hierarchy_level column already exists';
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('adl_boundary') AND name = 'hierarchy_type'
)
BEGIN
    ALTER TABLE adl_boundary ADD hierarchy_type VARCHAR(30) NULL;
    PRINT 'Added hierarchy_type column to adl_boundary';
END
ELSE
    PRINT 'hierarchy_type column already exists';
GO

-- =====================
-- Step 1B: Expand CHECK constraint
-- Current: ARTCC, ARTCC_SUB, SECTOR_HIGH, SECTOR_LOW, SECTOR_SUPERHIGH, TRACON
-- New: adds ARTCC_SUPER, ARTCC_SUB_3, ARTCC_SUB_4, ARTCC_SUB_5, ARTCC_SUB_6
-- =====================
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
    CHECK (boundary_type IN (
        'ARTCC', 'ARTCC_SUPER', 'ARTCC_SUB',
        'ARTCC_SUB_3', 'ARTCC_SUB_4', 'ARTCC_SUB_5', 'ARTCC_SUB_6',
        'SECTOR_HIGH', 'SECTOR_LOW', 'SECTOR_SUPERHIGH', 'TRACON'
    ));
PRINT 'Re-created CHK_boundary_type with ARTCC_SUPER and ARTCC_SUB_3..6';
GO

-- =====================
-- Step 1C: Create boundary_hierarchy edge table
-- =====================
IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'boundary_hierarchy')
BEGIN
    CREATE TABLE boundary_hierarchy (
        edge_id             INT IDENTITY(1,1) PRIMARY KEY,
        parent_boundary_id  INT NOT NULL REFERENCES adl_boundary(boundary_id),
        child_boundary_id   INT NOT NULL REFERENCES adl_boundary(boundary_id),
        parent_code         VARCHAR(50) NOT NULL,
        child_code          VARCHAR(50) NOT NULL,
        relationship_type   VARCHAR(20) NOT NULL,  -- CONTAINS, TILES, SECTOR_OF, TRACON_OF
        coverage_ratio      DECIMAL(5,4) NULL,
        computed_at         DATETIME2 DEFAULT GETUTCDATE(),
        CONSTRAINT UQ_hierarchy_edge UNIQUE (parent_boundary_id, child_boundary_id)
    );
    PRINT 'Created boundary_hierarchy table';

    CREATE INDEX IX_hier_parent ON boundary_hierarchy(parent_boundary_id);
    CREATE INDEX IX_hier_child ON boundary_hierarchy(child_boundary_id);
    CREATE INDEX IX_hier_parent_code ON boundary_hierarchy(parent_code);
    CREATE INDEX IX_hier_child_code ON boundary_hierarchy(child_code);
    PRINT 'Created boundary_hierarchy indexes';
END
ELSE
    PRINT 'boundary_hierarchy table already exists';
GO

-- =====================
-- Step 1D: Update sp_ImportBoundary
-- Add @hierarchy_level, @hierarchy_type params
-- Change upsert match key for ARTCC-family types (code-only matching)
-- Add hierarchy columns to INSERT and UPDATE
-- =====================
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
    @parent_fir VARCHAR(20) = NULL,
    @hierarchy_level TINYINT = NULL,
    @hierarchy_type VARCHAR(30) = NULL
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

        -- Upsert: find existing boundary
        DECLARE @existing_id INT;

        -- For ARTCC-family types, match on code only (type may change between imports,
        -- e.g., ARTCC -> ARTCC_SUPER or ARTCC_SUB -> ARTCC_SUB_3)
        IF @boundary_type LIKE 'ARTCC%'
        BEGIN
            SELECT @existing_id = boundary_id
            FROM adl_boundary
            WHERE boundary_code = @boundary_code
              AND boundary_type LIKE 'ARTCC%';
        END

        -- For non-ARTCC types (or if ARTCC-family match not found), match on (type, code)
        IF @existing_id IS NULL
        BEGIN
            SELECT @existing_id = boundary_id
            FROM adl_boundary
            WHERE boundary_type = @boundary_type
              AND boundary_code = @boundary_code;
        END

        IF @existing_id IS NOT NULL
        BEGIN
            -- Update existing (including boundary_type which may have changed)
            UPDATE adl_boundary
            SET boundary_type = @boundary_type,
                boundary_name = @boundary_name,
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
                hierarchy_level = @hierarchy_level,
                hierarchy_type = @hierarchy_type,
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
                hierarchy_level, hierarchy_type,
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
                @hierarchy_level, @hierarchy_type,
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

PRINT 'Updated sp_ImportBoundary with hierarchy params and ARTCC-family upsert logic';
GO

-- =====================
-- Step 1E: Add indexes
-- =====================
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'IX_boundary_hierarchy_level' AND object_id = OBJECT_ID('adl_boundary')
)
BEGIN
    CREATE INDEX IX_boundary_hierarchy_level
    ON adl_boundary(hierarchy_level)
    WHERE is_active = 1;
    PRINT 'Created IX_boundary_hierarchy_level index';
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'IX_boundary_super' AND object_id = OBJECT_ID('adl_boundary')
)
BEGIN
    CREATE INDEX IX_boundary_super
    ON adl_boundary(boundary_code)
    WHERE boundary_type = 'ARTCC_SUPER' AND is_active = 1;
    PRINT 'Created IX_boundary_super index';
END
GO

-- =====================
-- Step 1F: Backfill existing data (approximate -- re-import will set precise values)
-- =====================

-- Level 1: all current ARTCCs
UPDATE adl_boundary SET hierarchy_level = 1, hierarchy_type = 'FIR'
WHERE boundary_type = 'ARTCC' AND is_active = 1 AND hierarchy_level IS NULL;
PRINT 'Backfilled ' + CAST(@@ROWCOUNT AS VARCHAR) + ' ARTCC boundaries as Level 1 FIR';

-- Level 2: all current ARTCC_SUBs
UPDATE adl_boundary SET hierarchy_level = 2, hierarchy_type = 'NAMED_SUB_AREA'
WHERE boundary_type = 'ARTCC_SUB' AND is_active = 1 AND hierarchy_level IS NULL;
PRINT 'Backfilled ' + CAST(@@ROWCOUNT AS VARCHAR) + ' ARTCC_SUB boundaries as Level 2 NAMED_SUB_AREA';

-- Sectors: level 2
DECLARE @sector_type VARCHAR(20);

UPDATE adl_boundary
SET hierarchy_level = 2,
    hierarchy_type = boundary_type  -- SECTOR_LOW, SECTOR_HIGH, SECTOR_SUPERHIGH
WHERE boundary_type LIKE 'SECTOR_%' AND is_active = 1 AND hierarchy_level IS NULL;
PRINT 'Backfilled ' + CAST(@@ROWCOUNT AS VARCHAR) + ' sector boundaries as Level 2';

-- TRACONs: level 2 (approximate -- re-import will set TRACON vs TRACON_SECTOR)
UPDATE adl_boundary SET hierarchy_level = 2, hierarchy_type = 'TRACON'
WHERE boundary_type = 'TRACON' AND is_active = 1 AND hierarchy_level IS NULL;
PRINT 'Backfilled ' + CAST(@@ROWCOUNT AS VARCHAR) + ' TRACON boundaries as Level 2 TRACON';
GO

-- =====================
-- Verification
-- =====================
PRINT '=== Verification ===';

SELECT boundary_type, hierarchy_level, hierarchy_type, COUNT(*) as [count]
FROM adl_boundary
WHERE is_active = 1
GROUP BY boundary_type, hierarchy_level, hierarchy_type
ORDER BY boundary_type, hierarchy_level;

SELECT COUNT(*) as hierarchy_edges FROM boundary_hierarchy;

PRINT '012_boundary_hierarchy.sql migration complete';
GO
