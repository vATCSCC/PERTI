-- ============================================================================
-- 014_swim_fixm_times_acdm.sql
-- SWIM_API Database: Add FIXM 4.3 compliant time & A-CDM milestone fields
--
-- FIXM Field Mapping Reference: docs/swim/VATSWIM_FIXM_Field_Mapping.md
-- ============================================================================

USE SWIM_API;
GO

PRINT '==========================================================================';
PRINT '  FIXM Migration 014: Times & A-CDM Milestone Fields';
PRINT '  ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
GO

-- ============================================================================
-- SECTION 1: Estimated Time Fields
-- ============================================================================

-- estimated_time_of_departure (FIXM: estimatedTimeOfDeparture) - ETD (wheels up)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'estimated_time_of_departure')
BEGIN
    ALTER TABLE dbo.swim_flights ADD estimated_time_of_departure DATETIME2(0) NULL;
    PRINT '+ Added estimated_time_of_departure (FIXM: estimatedTimeOfDeparture)';
END
ELSE PRINT '= estimated_time_of_departure already exists';
GO

-- estimated_in_block_time (FIXM: estimatedInBlockTime) - EIBT
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'estimated_in_block_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD estimated_in_block_time DATETIME2(0) NULL;
    PRINT '+ Added estimated_in_block_time (FIXM: estimatedInBlockTime)';
END
ELSE PRINT '= estimated_in_block_time already exists';
GO

-- eta_qualifier (FIXM: etaQualifier) - ETA prefix qualifier (P=Proposed, E=Estimated, A=Actual)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'eta_qualifier')
BEGIN
    ALTER TABLE dbo.swim_flights ADD eta_qualifier NVARCHAR(8) NULL;
    PRINT '+ Added eta_qualifier (FIXM: etaQualifier)';
END
ELSE PRINT '= eta_qualifier already exists';
GO

-- etd_qualifier (FIXM: etdQualifier) - ETD prefix qualifier
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'etd_qualifier')
BEGIN
    ALTER TABLE dbo.swim_flights ADD etd_qualifier NVARCHAR(8) NULL;
    PRINT '+ Added etd_qualifier (FIXM: etdQualifier)';
END
ELSE PRINT '= etd_qualifier already exists';
GO

-- ============================================================================
-- SECTION 2: Controlled Time Fields (TMI)
-- ============================================================================

-- original_edct (FIXM: originalEdct) - Original EDCT before amendments
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'original_edct')
BEGIN
    ALTER TABLE dbo.swim_flights ADD original_edct DATETIME2(0) NULL;
    PRINT '+ Added original_edct (FIXM: originalEdct)';
END
ELSE PRINT '= original_edct already exists';
GO

-- controlled_time_of_departure (FIXM: controlledTimeOfDeparture) - CTD
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'controlled_time_of_departure')
BEGIN
    ALTER TABLE dbo.swim_flights ADD controlled_time_of_departure DATETIME2(0) NULL;
    PRINT '+ Added controlled_time_of_departure (FIXM: controlledTimeOfDeparture)';
END
ELSE PRINT '= controlled_time_of_departure already exists';
GO

-- original_ctd (FIXM: originalCtd) - Original CTD before amendments
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'original_ctd')
BEGIN
    ALTER TABLE dbo.swim_flights ADD original_ctd DATETIME2(0) NULL;
    PRINT '+ Added original_ctd (FIXM: originalCtd)';
END
ELSE PRINT '= original_ctd already exists';
GO

-- controlled_time_of_arrival (FIXM: controlledTimeOfArrival) - CTA
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'controlled_time_of_arrival')
BEGIN
    ALTER TABLE dbo.swim_flights ADD controlled_time_of_arrival DATETIME2(0) NULL;
    PRINT '+ Added controlled_time_of_arrival (FIXM: controlledTimeOfArrival)';
END
ELSE PRINT '= controlled_time_of_arrival already exists';
GO

-- slot_time (FIXM: slotTime) - Assigned slot time
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'slot_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD slot_time DATETIME2(0) NULL;
    PRINT '+ Added slot_time (FIXM: slotTime)';
END
ELSE PRINT '= slot_time already exists';
GO

-- ============================================================================
-- SECTION 3: A-CDM Milestone Fields
-- ============================================================================

-- target_off_block_time (FIXM: targetOffBlockTime) - TOBT
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'target_off_block_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD target_off_block_time DATETIME2(0) NULL;
    PRINT '+ Added target_off_block_time (FIXM: targetOffBlockTime/TOBT)';
END
ELSE PRINT '= target_off_block_time already exists';
GO

-- target_startup_approval_time (FIXM: targetStartupApprovalTime) - TSAT
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'target_startup_approval_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD target_startup_approval_time DATETIME2(0) NULL;
    PRINT '+ Added target_startup_approval_time (FIXM: targetStartupApprovalTime/TSAT)';
END
ELSE PRINT '= target_startup_approval_time already exists';
GO

-- target_takeoff_time (FIXM: targetTakeoffTime) - TTOT
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'target_takeoff_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD target_takeoff_time DATETIME2(0) NULL;
    PRINT '+ Added target_takeoff_time (FIXM: targetTakeoffTime/TTOT)';
END
ELSE PRINT '= target_takeoff_time already exists';
GO

-- target_landing_time (FIXM: targetLandingTime) - TLDT
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'target_landing_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD target_landing_time DATETIME2(0) NULL;
    PRINT '+ Added target_landing_time (FIXM: targetLandingTime/TLDT)';
END
ELSE PRINT '= target_landing_time already exists';
GO

-- ============================================================================
-- SECTION 4: Indexes for controlled time queries
-- ============================================================================

-- Index on EDCT for TMI compliance monitoring
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'IX_swim_flights_edct_active')
BEGIN
    CREATE INDEX IX_swim_flights_edct_active ON dbo.swim_flights (edct_utc) WHERE edct_utc IS NOT NULL AND is_active = 1;
    PRINT '+ Created index IX_swim_flights_edct_active';
END
GO

-- Index on TOBT for A-CDM monitoring
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'IX_swim_flights_tobt')
BEGIN
    CREATE INDEX IX_swim_flights_tobt ON dbo.swim_flights (target_off_block_time) WHERE target_off_block_time IS NOT NULL AND is_active = 1;
    PRINT '+ Created index IX_swim_flights_tobt';
END
GO

PRINT '';
PRINT '==========================================================================';
PRINT '  Migration 014 Complete: Times & A-CDM Milestone Fields';
PRINT '';
PRINT '  New fields added:';
PRINT '  - Estimated times: estimated_time_of_departure, estimated_in_block_time';
PRINT '  - Time qualifiers: eta_qualifier, etd_qualifier';
PRINT '  - Controlled times: original_edct, controlled_time_of_departure,';
PRINT '    original_ctd, controlled_time_of_arrival, slot_time';
PRINT '  - A-CDM milestones: target_off_block_time (TOBT),';
PRINT '    target_startup_approval_time (TSAT), target_takeoff_time (TTOT),';
PRINT '    target_landing_time (TLDT)';
PRINT '==========================================================================';
GO
