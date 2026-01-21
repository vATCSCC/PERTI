-- ============================================================================
-- 015_swim_fixm_tmi_metering.sql
-- SWIM_API Database: Add FIXM 4.3 compliant TMI & TBFM metering fields
--
-- FIXM Field Mapping Reference: docs/swim/VATSWIM_FIXM_Field_Mapping.md
-- ============================================================================

USE SWIM_API;
GO

PRINT '==========================================================================';
PRINT '  FIXM Migration 015: TMI & TBFM Metering Fields';
PRINT '  ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
GO

-- ============================================================================
-- SECTION 1: TMI Control Fields
-- ============================================================================

-- control_type (FIXM: controlType) - Type of TMI control (GS, GDP, AFP, MIT, etc.)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'control_type')
BEGIN
    ALTER TABLE dbo.swim_flights ADD control_type NVARCHAR(16) NULL;
    PRINT '+ Added control_type (FIXM: controlType)';
END
ELSE PRINT '= control_type already exists';
GO

-- control_element (FIXM: controlElement) - TMI control element (airport, FCA, etc.)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'control_element')
BEGIN
    ALTER TABLE dbo.swim_flights ADD control_element NVARCHAR(16) NULL;
    PRINT '+ Added control_element (FIXM: controlElement)';
END
ELSE PRINT '= control_element already exists';
GO

-- program_name (FIXM: programName) - TMI program name/ID
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'program_name')
BEGIN
    ALTER TABLE dbo.swim_flights ADD program_name NVARCHAR(64) NULL;
    PRINT '+ Added program_name (FIXM: programName)';
END
ELSE PRINT '= program_name already exists';
GO

-- delay_value (FIXM: delayValue) - Assigned delay in minutes
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'delay_value')
BEGIN
    ALTER TABLE dbo.swim_flights ADD delay_value INT NULL;
    PRINT '+ Added delay_value (FIXM: delayValue)';
END
ELSE PRINT '= delay_value already exists';
GO

-- ground_stop_held (FIXM: groundStopHeld) - Flight held by ground stop
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'ground_stop_held')
BEGIN
    ALTER TABLE dbo.swim_flights ADD ground_stop_held BIT NULL DEFAULT 0;
    PRINT '+ Added ground_stop_held (FIXM: groundStopHeld)';
END
ELSE PRINT '= ground_stop_held already exists';
GO

-- exempt_indicator (FIXM: exemptIndicator) - Flight exempt from TMI
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'exempt_indicator')
BEGIN
    ALTER TABLE dbo.swim_flights ADD exempt_indicator BIT NULL DEFAULT 0;
    PRINT '+ Added exempt_indicator (FIXM: exemptIndicator)';
END
ELSE PRINT '= exempt_indicator already exists';
GO

-- ============================================================================
-- SECTION 2: TBFM Metering Fields
-- ============================================================================

-- metering_point (FIXM: meteringPoint) - Meter fix identifier
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'metering_point')
BEGIN
    ALTER TABLE dbo.swim_flights ADD metering_point NVARCHAR(10) NULL;
    PRINT '+ Added metering_point (FIXM: meteringPoint)';
END
ELSE PRINT '= metering_point already exists';
GO

-- metering_time (FIXM: meteringTime) - STA at meter fix
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'metering_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD metering_time DATETIME2(0) NULL;
    PRINT '+ Added metering_time (FIXM: meteringTime)';
END
ELSE PRINT '= metering_time already exists';
GO

-- scheduled_time_of_arrival (FIXM: scheduledTimeOfArrival) - STA at runway
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'scheduled_time_of_arrival')
BEGIN
    ALTER TABLE dbo.swim_flights ADD scheduled_time_of_arrival DATETIME2(0) NULL;
    PRINT '+ Added scheduled_time_of_arrival (FIXM: scheduledTimeOfArrival)';
END
ELSE PRINT '= scheduled_time_of_arrival already exists';
GO

-- scheduled_time_of_departure (FIXM: scheduledTimeOfDeparture) - STD at runway
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'scheduled_time_of_departure')
BEGIN
    ALTER TABLE dbo.swim_flights ADD scheduled_time_of_departure DATETIME2(0) NULL;
    PRINT '+ Added scheduled_time_of_departure (FIXM: scheduledTimeOfDeparture)';
END
ELSE PRINT '= scheduled_time_of_departure already exists';
GO

-- sequence_number (FIXM: sequenceNumber) - Arrival sequence position
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'sequence_number')
BEGIN
    ALTER TABLE dbo.swim_flights ADD sequence_number INT NULL;
    PRINT '+ Added sequence_number (FIXM: sequenceNumber)';
END
ELSE PRINT '= sequence_number already exists';
GO

-- metering_delay (FIXM: delayValue for metering) - TBFM delay in minutes
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'metering_delay')
BEGIN
    ALTER TABLE dbo.swim_flights ADD metering_delay INT NULL;
    PRINT '+ Added metering_delay (FIXM: delayValue/metering)';
END
ELSE PRINT '= metering_delay already exists';
GO

-- metering_frozen (FIXM: frozenIndicator) - Sequence frozen flag
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'metering_frozen')
BEGIN
    ALTER TABLE dbo.swim_flights ADD metering_frozen BIT NULL DEFAULT 0;
    PRINT '+ Added metering_frozen (FIXM: frozenIndicator)';
END
ELSE PRINT '= metering_frozen already exists';
GO

-- arrival_stream (FIXM: arrivalStream) - Corner post assignment (NORTH, SOUTH, etc.)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'arrival_stream')
BEGIN
    ALTER TABLE dbo.swim_flights ADD arrival_stream NVARCHAR(16) NULL;
    PRINT '+ Added arrival_stream (FIXM: arrivalStream)';
END
ELSE PRINT '= arrival_stream already exists';
GO

-- ============================================================================
-- SECTION 3: Extended Metering Fields (vATCSCC)
-- ============================================================================

-- metering_status (vATCSCC:meteringStatus) - UNMETERED/METERED/FROZEN/SUSPENDED/EXEMPT
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'metering_status')
BEGIN
    ALTER TABLE dbo.swim_flights ADD metering_status NVARCHAR(16) NULL;
    PRINT '+ Added metering_status (vATCSCC:meteringStatus)';
END
ELSE PRINT '= metering_status already exists';
GO

-- undelayed_eta (vATCSCC:undelayedEta) - Baseline ETA without TBFM delay
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'undelayed_eta')
BEGIN
    ALTER TABLE dbo.swim_flights ADD undelayed_eta DATETIME2(0) NULL;
    PRINT '+ Added undelayed_eta (vATCSCC:undelayedEta)';
END
ELSE PRINT '= undelayed_eta already exists';
GO

-- eta_vertex (vATCSCC:etaVertex) - ETA at corner post/vertex
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'eta_vertex')
BEGIN
    ALTER TABLE dbo.swim_flights ADD eta_vertex DATETIME2(0) NULL;
    PRINT '+ Added eta_vertex (vATCSCC:etaVertex)';
END
ELSE PRINT '= eta_vertex already exists';
GO

-- sta_vertex (vATCSCC:staVertex) - Assigned time at vertex
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'sta_vertex')
BEGIN
    ALTER TABLE dbo.swim_flights ADD sta_vertex DATETIME2(0) NULL;
    PRINT '+ Added sta_vertex (vATCSCC:staVertex)';
END
ELSE PRINT '= sta_vertex already exists';
GO

-- vertex_point (vATCSCC:vertexPoint) - Vertex fix identifier
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'vertex_point')
BEGIN
    ALTER TABLE dbo.swim_flights ADD vertex_point NVARCHAR(10) NULL;
    PRINT '+ Added vertex_point (vATCSCC:vertexPoint)';
END
ELSE PRINT '= vertex_point already exists';
GO

-- metering_source (vATCSCC:meteringSource) - Source of metering data
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'metering_source')
BEGIN
    ALTER TABLE dbo.swim_flights ADD metering_source NVARCHAR(32) NULL;
    PRINT '+ Added metering_source (vATCSCC:meteringSource)';
END
ELSE PRINT '= metering_source already exists';
GO

-- metering_updated_at (vATCSCC:meteringUpdatedTime) - Last metering update
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'metering_updated_at')
BEGIN
    ALTER TABLE dbo.swim_flights ADD metering_updated_at DATETIME2(0) NULL;
    PRINT '+ Added metering_updated_at (vATCSCC:meteringUpdatedTime)';
END
ELSE PRINT '= metering_updated_at already exists';
GO

-- ============================================================================
-- SECTION 4: Indexes for metering queries
-- ============================================================================

-- Index for metering sequence queries
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'IX_swim_flights_metering_seq')
BEGIN
    CREATE INDEX IX_swim_flights_metering_seq ON dbo.swim_flights (fp_dest_icao, sequence_number) WHERE sequence_number IS NOT NULL AND is_active = 1;
    PRINT '+ Created index IX_swim_flights_metering_seq';
END
GO

-- Index for arrival stream queries
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'IX_swim_flights_arrival_stream')
BEGIN
    CREATE INDEX IX_swim_flights_arrival_stream ON dbo.swim_flights (fp_dest_icao, arrival_stream) WHERE arrival_stream IS NOT NULL AND is_active = 1;
    PRINT '+ Created index IX_swim_flights_arrival_stream';
END
GO

PRINT '';
PRINT '==========================================================================';
PRINT '  Migration 015 Complete: TMI & TBFM Metering Fields';
PRINT '';
PRINT '  TMI fields: control_type, control_element, program_name,';
PRINT '    delay_value, ground_stop_held, exempt_indicator';
PRINT '';
PRINT '  TBFM metering fields: metering_point, metering_time,';
PRINT '    scheduled_time_of_arrival, scheduled_time_of_departure,';
PRINT '    sequence_number, metering_delay, metering_frozen, arrival_stream';
PRINT '';
PRINT '  Extended metering: metering_status, undelayed_eta, eta_vertex,';
PRINT '    sta_vertex, vertex_point, metering_source, metering_updated_at';
PRINT '==========================================================================';
GO
