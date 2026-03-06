-- ============================================================================
-- SWIM_API Migration 023: CDM Columns on swim_flights
-- Adds A-CDM milestone columns for collaborative decision making
-- ============================================================================
-- Run on: SWIM_API (Azure SQL)
-- Date: 2026-03-05
-- Run as: jpeterson (DDL admin)
--
-- Existing columns (migration 014): target_off_block_time,
--   target_startup_approval_time, target_takeoff_time, target_landing_time
--
-- New columns: actual_startup_approval_time, expected_taxi_out_time,
--   cdm_source, cdm_updated_at
-- ============================================================================

USE SWIM_API;
GO

PRINT '==========================================================================';
PRINT '  Migration 023: CDM Columns on swim_flights';
PRINT '  ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
GO

-- ASAT: Actual Startup Approval Time (pushback approval moment)
IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'swim_flights' AND COLUMN_NAME = 'actual_startup_approval_time'
)
BEGIN
    ALTER TABLE dbo.swim_flights ADD actual_startup_approval_time DATETIME2(0) NULL;
    PRINT '+ Added column actual_startup_approval_time';
END
ELSE PRINT '= actual_startup_approval_time already exists';
GO

-- EXOT: Expected Taxi Out Time (minutes)
IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'swim_flights' AND COLUMN_NAME = 'expected_taxi_out_time'
)
BEGIN
    ALTER TABLE dbo.swim_flights ADD expected_taxi_out_time INT NULL;
    PRINT '+ Added column expected_taxi_out_time';
END
ELSE PRINT '= expected_taxi_out_time already exists';
GO

-- CDM data source (vacdm, cdm_plugin, vatcscc)
IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'swim_flights' AND COLUMN_NAME = 'cdm_source'
)
BEGIN
    ALTER TABLE dbo.swim_flights ADD cdm_source NVARCHAR(50) NULL;
    PRINT '+ Added column cdm_source';
END
ELSE PRINT '= cdm_source already exists';
GO

-- CDM last update timestamp
IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'swim_flights' AND COLUMN_NAME = 'cdm_updated_at'
)
BEGIN
    ALTER TABLE dbo.swim_flights ADD cdm_updated_at DATETIME2(0) NULL;
    PRINT '+ Added column cdm_updated_at';
END
ELSE PRINT '= cdm_updated_at already exists';
GO

PRINT '';
PRINT '==========================================================================';
PRINT '  Migration 023 Complete';
PRINT '  New columns: actual_startup_approval_time, expected_taxi_out_time,';
PRINT '               cdm_source, cdm_updated_at';
PRINT '==========================================================================';
GO
