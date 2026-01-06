-- Migration 052: Widen adl_boundary columns
-- Purpose: Ensure columns match sp_ImportBoundary parameter sizes

-- boundary_code: VARCHAR(20) -> VARCHAR(50)
IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_boundary') AND name = 'boundary_code' AND max_length = 20)
BEGIN
    ALTER TABLE adl_boundary ALTER COLUMN boundary_code VARCHAR(50) NOT NULL;
    PRINT 'Widened boundary_code to VARCHAR(50)';
END

-- boundary_name: VARCHAR(100) -> NVARCHAR(255)
IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_boundary') AND name = 'boundary_name' AND max_length < 510)
BEGIN
    ALTER TABLE adl_boundary ALTER COLUMN boundary_name NVARCHAR(255) NULL;
    PRINT 'Widened boundary_name to NVARCHAR(255)';
END

-- sector_number: VARCHAR(10) -> VARCHAR(20)
IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_boundary') AND name = 'sector_number' AND max_length = 10)
BEGIN
    ALTER TABLE adl_boundary ALTER COLUMN sector_number VARCHAR(20) NULL;
    PRINT 'Widened sector_number to VARCHAR(20)';
END

-- vatsim_region: VARCHAR(20) -> VARCHAR(50)
IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_boundary') AND name = 'vatsim_region' AND max_length = 20)
BEGIN
    ALTER TABLE adl_boundary ALTER COLUMN vatsim_region VARCHAR(50) NULL;
    PRINT 'Widened vatsim_region to VARCHAR(50)';
END

-- vatsim_division: VARCHAR(20) -> VARCHAR(50)
IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_boundary') AND name = 'vatsim_division' AND max_length = 20)
BEGIN
    ALTER TABLE adl_boundary ALTER COLUMN vatsim_division VARCHAR(50) NULL;
    PRINT 'Widened vatsim_division to VARCHAR(50)';
END

-- vatsim_subdivision: VARCHAR(20) -> VARCHAR(50)
IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_boundary') AND name = 'vatsim_subdivision' AND max_length = 20)
BEGIN
    ALTER TABLE adl_boundary ALTER COLUMN vatsim_subdivision VARCHAR(50) NULL;
    PRINT 'Widened vatsim_subdivision to VARCHAR(50)';
END

-- source_file: VARCHAR(50) -> VARCHAR(100)
IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_boundary') AND name = 'source_file' AND max_length = 50)
BEGIN
    ALTER TABLE adl_boundary ALTER COLUMN source_file VARCHAR(100) NULL;
    PRINT 'Widened source_file to VARCHAR(100)';
END

-- shape_length: increase precision
IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_boundary') AND name = 'shape_length')
BEGIN
    ALTER TABLE adl_boundary ALTER COLUMN shape_length DECIMAL(18,10) NULL;
    PRINT 'Updated shape_length precision';
END

-- shape_area: increase precision
IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('adl_boundary') AND name = 'shape_area')
BEGIN
    ALTER TABLE adl_boundary ALTER COLUMN shape_area DECIMAL(18,10) NULL;
    PRINT 'Updated shape_area precision';
END

PRINT 'Migration 052 complete - column widths updated';
GO
