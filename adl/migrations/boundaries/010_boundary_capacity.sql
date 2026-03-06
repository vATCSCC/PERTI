-- ============================================================================
-- ADL Migration 010: Boundary Capacity Columns
-- Adds airspace capacity tracking columns to adl_boundary
-- ============================================================================
-- Run on: VATSIM_ADL (Azure SQL)
-- Date: 2026-03-05
-- Run as: jpeterson (DDL admin)
--
-- New columns: capacity, capacity_type, capacity_source
-- ============================================================================

USE VATSIM_ADL;
GO

PRINT '==========================================================================';
PRINT '  Migration 010: Boundary Capacity Columns';
PRINT '  ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
GO

-- capacity: Maximum number of aircraft or entry rate for the sector/boundary
IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'adl_boundary' AND COLUMN_NAME = 'capacity'
)
BEGIN
    ALTER TABLE dbo.adl_boundary ADD capacity INT NULL;
    PRINT '+ Added column capacity';
END
ELSE PRINT '= capacity already exists';
GO

-- capacity_type: What the capacity value represents
--   MONITOR   = monitoring threshold (soft limit, advisory only)
--   ENTRY_RATE = max aircraft entries per hour
--   OCCUPANCY  = max simultaneous aircraft in sector
IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'adl_boundary' AND COLUMN_NAME = 'capacity_type'
)
BEGIN
    ALTER TABLE dbo.adl_boundary ADD capacity_type NVARCHAR(20) NULL;
    PRINT '+ Added column capacity_type';
END
ELSE PRINT '= capacity_type already exists';
GO

-- capacity_source: Where the capacity value came from
--   CAD      = Controller Assignment Display (EUROCONTROL/vACDM)
--   MANUAL   = Manually configured by vATCSCC
--   ECFMP    = ECFMP flow measure derived
--   VATSIM   = VATSIM division data
IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'adl_boundary' AND COLUMN_NAME = 'capacity_source'
)
BEGIN
    ALTER TABLE dbo.adl_boundary ADD capacity_source NVARCHAR(50) NULL;
    PRINT '+ Added column capacity_source';
END
ELSE PRINT '= capacity_source already exists';
GO

PRINT '';
PRINT '==========================================================================';
PRINT '  Migration 010 Complete';
PRINT '  New columns: capacity, capacity_type, capacity_source';
PRINT '==========================================================================';
GO
