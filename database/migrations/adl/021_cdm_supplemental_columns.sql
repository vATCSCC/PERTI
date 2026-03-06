-- ============================================================================
-- VATSIM_ADL Migration 021: CDM Supplemental Columns on adl_flight_times
-- Adds remaining A-CDM fields not covered by migration 020
-- ============================================================================
-- Run on: VATSIM_ADL (Azure SQL)
-- Date: 2026-03-05
-- Run as: jpeterson (DDL admin)
--
-- Migration 020 added: tobt_utc, tsat_utc, ttot_utc, tobt_source, tsat_source,
--   gate_hold_active/issued/released, cdm_readiness_state
--
-- This migration adds: tldt_utc, asat_utc, exot_minutes, cdm_source, cdm_updated_utc
-- These are needed for reverse sync from swim_flights CDM columns.
-- ============================================================================

USE VATSIM_ADL;
GO

PRINT '==========================================================================';
PRINT '  Migration 021: CDM Supplemental Columns on adl_flight_times';
PRINT '  ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
GO

-- TLDT — Target Landing Time (arrival-side CDM milestone)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'tldt_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD tldt_utc DATETIME2(0) NULL;
    PRINT '+ Added tldt_utc';
END
ELSE PRINT '= tldt_utc already exists';
GO

-- ASAT — Actual Startup Approval Time (pushback approval moment)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'asat_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD asat_utc DATETIME2(0) NULL;
    PRINT '+ Added asat_utc';
END
ELSE PRINT '= asat_utc already exists';
GO

-- EXOT — Expected Taxi Out Time (minutes)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'exot_minutes')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD exot_minutes INT NULL;
    PRINT '+ Added exot_minutes';
END
ELSE PRINT '= exot_minutes already exists';
GO

-- CDM data source (VACDM, CDM_PLUGIN, VATCSCC, etc.)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'cdm_source')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD cdm_source NVARCHAR(50) NULL;
    PRINT '+ Added cdm_source';
END
ELSE PRINT '= cdm_source already exists';
GO

-- CDM last update timestamp (for freshness checking in reverse sync)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_times') AND name = 'cdm_updated_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD cdm_updated_utc DATETIME2(0) NULL;
    PRINT '+ Added cdm_updated_utc';
END
ELSE PRINT '= cdm_updated_utc already exists';
GO

PRINT '';
PRINT '==========================================================================';
PRINT '  Migration 021 Complete';
PRINT '  New columns: tldt_utc, asat_utc, exot_minutes, cdm_source, cdm_updated_utc';
PRINT '==========================================================================';
GO
