-- ============================================================================
-- 013_swim_fixm_airspace_position.sql
-- SWIM_API Database: Add FIXM 4.3 compliant airspace & position fields
--
-- FIXM Field Mapping Reference: docs/swim/VATSWIM_FIXM_Field_Mapping.md
-- ============================================================================

USE SWIM_API;
GO

PRINT '==========================================================================';
PRINT '  FIXM Migration 013: Airspace & Position Fields';
PRINT '  ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
GO

-- ============================================================================
-- SECTION 1: Airspace Fields
-- ============================================================================

-- current_airspace (FIXM: currentAirspace) - Current controlling ARTCC
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'current_airspace')
BEGIN
    ALTER TABLE dbo.swim_flights ADD current_airspace NVARCHAR(16) NULL;
    PRINT '+ Added current_airspace (FIXM: currentAirspace)';
END
ELSE PRINT '= current_airspace already exists';
GO

-- current_sector (FIXM: currentSector) - Current sector identifier
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'current_sector')
BEGIN
    ALTER TABLE dbo.swim_flights ADD current_sector NVARCHAR(16) NULL;
    PRINT '+ Added current_sector (FIXM: currentSector)';
END
ELSE PRINT '= current_sector already exists';
GO

-- ============================================================================
-- SECTION 2: Zone Detection Times (vATCSCC Extension)
-- ============================================================================

-- parking_left_time (vATCSCC:parkingLeftTime) - Time aircraft left parking
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'parking_left_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD parking_left_time DATETIME2(0) NULL;
    PRINT '+ Added parking_left_time (vATCSCC:parkingLeftTime)';
END
ELSE PRINT '= parking_left_time already exists';
GO

-- taxiway_entered_time (vATCSCC:taxiwayEnteredTime)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'taxiway_entered_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD taxiway_entered_time DATETIME2(0) NULL;
    PRINT '+ Added taxiway_entered_time (vATCSCC:taxiwayEnteredTime)';
END
ELSE PRINT '= taxiway_entered_time already exists';
GO

-- hold_entered_time (vATCSCC:holdEnteredTime)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'hold_entered_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD hold_entered_time DATETIME2(0) NULL;
    PRINT '+ Added hold_entered_time (vATCSCC:holdEnteredTime)';
END
ELSE PRINT '= hold_entered_time already exists';
GO

-- runway_entered_time (vATCSCC:runwayEnteredTime)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'runway_entered_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD runway_entered_time DATETIME2(0) NULL;
    PRINT '+ Added runway_entered_time (vATCSCC:runwayEnteredTime)';
END
ELSE PRINT '= runway_entered_time already exists';
GO

-- rotation_time (vATCSCC:rotationTime)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'rotation_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD rotation_time DATETIME2(0) NULL;
    PRINT '+ Added rotation_time (vATCSCC:rotationTime)';
END
ELSE PRINT '= rotation_time already exists';
GO

-- approach_start_time (vATCSCC:approachStartTime)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'approach_start_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD approach_start_time DATETIME2(0) NULL;
    PRINT '+ Added approach_start_time (vATCSCC:approachStartTime)';
END
ELSE PRINT '= approach_start_time already exists';
GO

-- threshold_time (vATCSCC:thresholdTime)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'threshold_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD threshold_time DATETIME2(0) NULL;
    PRINT '+ Added threshold_time (vATCSCC:thresholdTime)';
END
ELSE PRINT '= threshold_time already exists';
GO

-- touchdown_time (vATCSCC:touchdownTime)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'touchdown_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD touchdown_time DATETIME2(0) NULL;
    PRINT '+ Added touchdown_time (vATCSCC:touchdownTime)';
END
ELSE PRINT '= touchdown_time already exists';
GO

-- rollout_end_time (vATCSCC:rolloutEndTime)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'rollout_end_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD rollout_end_time DATETIME2(0) NULL;
    PRINT '+ Added rollout_end_time (vATCSCC:rolloutEndTime)';
END
ELSE PRINT '= rollout_end_time already exists';
GO

-- parking_entered_time (vATCSCC:parkingEnteredTime)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'parking_entered_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD parking_entered_time DATETIME2(0) NULL;
    PRINT '+ Added parking_entered_time (vATCSCC:parkingEnteredTime)';
END
ELSE PRINT '= parking_entered_time already exists';
GO

-- ============================================================================
-- SECTION 3: Indexes for airspace queries
-- ============================================================================

-- Index on current sector for sector load analysis
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'IX_swim_flights_sector')
BEGIN
    CREATE INDEX IX_swim_flights_sector ON dbo.swim_flights (current_airspace, current_sector) WHERE current_sector IS NOT NULL AND is_active = 1;
    PRINT '+ Created index IX_swim_flights_sector';
END
GO

PRINT '';
PRINT '==========================================================================';
PRINT '  Migration 013 Complete: Airspace & Position Fields';
PRINT '';
PRINT '  New fields added:';
PRINT '  - current_airspace, current_sector (FIXM airspace)';
PRINT '  - Zone detection times (10 vATCSCC extension fields):';
PRINT '    parking_left_time, taxiway_entered_time, hold_entered_time,';
PRINT '    runway_entered_time, rotation_time, approach_start_time,';
PRINT '    threshold_time, touchdown_time, rollout_end_time,';
PRINT '    parking_entered_time';
PRINT '==========================================================================';
GO
