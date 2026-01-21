-- ============================================================================
-- 016_swim_fixm_simbrief_flow.sql
-- SWIM_API Database: Add SimBrief & external flow management fields
--
-- FIXM Field Mapping Reference: docs/swim/VATSWIM_FIXM_Field_Mapping.md
-- ============================================================================

USE SWIM_API;
GO

PRINT '==========================================================================';
PRINT '  FIXM Migration 016: SimBrief & External Flow Fields';
PRINT '  ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
GO

-- ============================================================================
-- SECTION 1: SimBrief Integration Fields (vATCSCC Extension)
-- ============================================================================

-- simbrief_ofp_id (vATCSCC:simbriefOfpId) - SimBrief OFP ID
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'simbrief_ofp_id')
BEGIN
    ALTER TABLE dbo.swim_flights ADD simbrief_ofp_id NVARCHAR(32) NULL;
    PRINT '+ Added simbrief_ofp_id (vATCSCC:simbriefOfpId)';
END
ELSE PRINT '= simbrief_ofp_id already exists';
GO

-- simbrief_route (vATCSCC:simbriefRoute) - Route from SimBrief OFP
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'simbrief_route')
BEGIN
    ALTER TABLE dbo.swim_flights ADD simbrief_route NVARCHAR(MAX) NULL;
    PRINT '+ Added simbrief_route (vATCSCC:simbriefRoute)';
END
ELSE PRINT '= simbrief_route already exists';
GO

-- cost_index (vATCSCC:costIndex) - SimBrief cost index
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'cost_index')
BEGIN
    ALTER TABLE dbo.swim_flights ADD cost_index INT NULL;
    PRINT '+ Added cost_index (vATCSCC:costIndex)';
END
ELSE PRINT '= cost_index already exists';
GO

-- block_fuel (vATCSCC:blockFuel) - Block fuel in lbs
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'block_fuel')
BEGIN
    ALTER TABLE dbo.swim_flights ADD block_fuel DECIMAL(10,1) NULL;
    PRINT '+ Added block_fuel (vATCSCC:blockFuel)';
END
ELSE PRINT '= block_fuel already exists';
GO

-- zero_fuel_weight (vATCSCC:zeroFuelWeight) - ZFW in lbs
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'zero_fuel_weight')
BEGIN
    ALTER TABLE dbo.swim_flights ADD zero_fuel_weight DECIMAL(10,1) NULL;
    PRINT '+ Added zero_fuel_weight (vATCSCC:zeroFuelWeight)';
END
ELSE PRINT '= zero_fuel_weight already exists';
GO

-- takeoff_weight (vATCSCC:takeoffWeight) - TOW in lbs
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'takeoff_weight')
BEGIN
    ALTER TABLE dbo.swim_flights ADD takeoff_weight DECIMAL(10,1) NULL;
    PRINT '+ Added takeoff_weight (vATCSCC:takeoffWeight)';
END
ELSE PRINT '= takeoff_weight already exists';
GO

-- simbrief_updated_at (vATCSCC:simbriefUpdatedTime) - Last SimBrief sync time
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'simbrief_updated_at')
BEGIN
    ALTER TABLE dbo.swim_flights ADD simbrief_updated_at DATETIME2(0) NULL;
    PRINT '+ Added simbrief_updated_at (vATCSCC:simbriefUpdatedTime)';
END
ELSE PRINT '= simbrief_updated_at already exists';
GO

-- ============================================================================
-- SECTION 2: External Flow Management Fields (ECFMP, etc.)
-- ============================================================================

-- flow_event_id - FK to tmi_flow_events
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'flow_event_id')
BEGIN
    ALTER TABLE dbo.swim_flights ADD flow_event_id INT NULL;
    PRINT '+ Added flow_event_id (FK to tmi_flow_events)';
END
ELSE PRINT '= flow_event_id already exists';
GO

-- flow_event_code (FIXM: specialHandlingCode) - Event code (CTP2026, FNO2026)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'flow_event_code')
BEGIN
    ALTER TABLE dbo.swim_flights ADD flow_event_code NVARCHAR(32) NULL;
    PRINT '+ Added flow_event_code (FIXM: specialHandlingCode)';
END
ELSE PRINT '= flow_event_code already exists';
GO

-- flow_priority (FIXM: priorityIndicator) - EVENT, STANDARD
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'flow_priority')
BEGIN
    ALTER TABLE dbo.swim_flights ADD flow_priority NVARCHAR(16) NULL;
    PRINT '+ Added flow_priority (FIXM: priorityIndicator)';
END
ELSE PRINT '= flow_priority already exists';
GO

-- flow_gs_exempt (FIXM: exemptIndicator) - Event flight exempt from GS
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'flow_gs_exempt')
BEGIN
    ALTER TABLE dbo.swim_flights ADD flow_gs_exempt BIT NULL DEFAULT 0;
    PRINT '+ Added flow_gs_exempt (FIXM: exemptIndicator)';
END
ELSE PRINT '= flow_gs_exempt already exists';
GO

-- flow_measure_id - FK to tmi_flow_measures
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'flow_measure_id')
BEGIN
    ALTER TABLE dbo.swim_flights ADD flow_measure_id INT NULL;
    PRINT '+ Added flow_measure_id (FK to tmi_flow_measures)';
END
ELSE PRINT '= flow_measure_id already exists';
GO

-- flow_measure_ident (FIXM: flowMeasureIdentifier) - e.g., EGTT22A
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'flow_measure_ident')
BEGIN
    ALTER TABLE dbo.swim_flights ADD flow_measure_ident NVARCHAR(32) NULL;
    PRINT '+ Added flow_measure_ident (FIXM: flowMeasureIdentifier)';
END
ELSE PRINT '= flow_measure_ident already exists';
GO

-- ============================================================================
-- SECTION 3: Data Source Tracking Fields
-- ============================================================================

-- track_updated_at (vATCSCC:trackUpdatedTime) - Last track update time
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'track_updated_at')
BEGIN
    ALTER TABLE dbo.swim_flights ADD track_updated_at DATETIME2(0) NULL;
    PRINT '+ Added track_updated_at (vATCSCC:trackUpdatedTime)';
END
ELSE PRINT '= track_updated_at already exists';
GO

-- adl_updated_at (vATCSCC:adlUpdatedTime) - Last ADL update time
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'adl_updated_at')
BEGIN
    ALTER TABLE dbo.swim_flights ADD adl_updated_at DATETIME2(0) NULL;
    PRINT '+ Added adl_updated_at (vATCSCC:adlUpdatedTime)';
END
ELSE PRINT '= adl_updated_at already exists';
GO

-- last_source (vATCSCC:lastSource) - Last data source that updated
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'last_source')
BEGIN
    ALTER TABLE dbo.swim_flights ADD last_source NVARCHAR(32) NULL;
    PRINT '+ Added last_source (vATCSCC:lastSource)';
END
ELSE PRINT '= last_source already exists';
GO

-- ============================================================================
-- SECTION 4: Indexes for new fields
-- ============================================================================

-- Index for SimBrief lookup
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'IX_swim_flights_simbrief')
BEGIN
    CREATE INDEX IX_swim_flights_simbrief ON dbo.swim_flights (simbrief_ofp_id) WHERE simbrief_ofp_id IS NOT NULL;
    PRINT '+ Created index IX_swim_flights_simbrief';
END
GO

-- Index for flow event queries
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'IX_swim_flights_flow_event')
BEGIN
    CREATE INDEX IX_swim_flights_flow_event ON dbo.swim_flights (flow_event_code) WHERE flow_event_code IS NOT NULL AND is_active = 1;
    PRINT '+ Created index IX_swim_flights_flow_event';
END
GO

-- Index for flow measure queries
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'IX_swim_flights_flow_measure')
BEGIN
    CREATE INDEX IX_swim_flights_flow_measure ON dbo.swim_flights (flow_measure_ident) WHERE flow_measure_ident IS NOT NULL AND is_active = 1;
    PRINT '+ Created index IX_swim_flights_flow_measure';
END
GO

PRINT '';
PRINT '==========================================================================';
PRINT '  Migration 016 Complete: SimBrief & External Flow Fields';
PRINT '';
PRINT '  SimBrief fields: simbrief_ofp_id, simbrief_route, cost_index,';
PRINT '    block_fuel, zero_fuel_weight, takeoff_weight, simbrief_updated_at';
PRINT '';
PRINT '  External flow fields: flow_event_id, flow_event_code, flow_priority,';
PRINT '    flow_gs_exempt, flow_measure_id, flow_measure_ident';
PRINT '';
PRINT '  Data source tracking: track_updated_at, adl_updated_at, last_source';
PRINT '==========================================================================';
GO
