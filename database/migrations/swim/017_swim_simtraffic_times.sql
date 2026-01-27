-- ============================================================================
-- 017_swim_simtraffic_times.sql
-- SWIM_API Database: Add SimTraffic departure sequence times and actual arrival times
--
-- Purpose: Support SimTraffic times ingestion through VATSWIM
-- Reference: SimTraffic API v1 flight response structure
-- ============================================================================

USE SWIM_API;
GO

PRINT '==========================================================================';
PRINT '  FIXM Migration 017: SimTraffic Times & Metering Compliance Fields';
PRINT '  ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
GO

-- ============================================================================
-- SECTION 1: Departure Sequence Times (from SimTraffic)
-- ============================================================================

-- taxi_time_utc - When taxi begins (after pushback)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'taxi_time_utc')
BEGIN
    ALTER TABLE dbo.swim_flights ADD taxi_time_utc DATETIME2(0) NULL;
    PRINT '+ Added taxi_time_utc (SimTraffic: departure.taxi_time)';
END
ELSE PRINT '= taxi_time_utc already exists';
GO

-- sequence_time_utc - Departure sequencing time assignment
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'sequence_time_utc')
BEGIN
    ALTER TABLE dbo.swim_flights ADD sequence_time_utc DATETIME2(0) NULL;
    PRINT '+ Added sequence_time_utc (SimTraffic: departure.sequence_time)';
END
ELSE PRINT '= sequence_time_utc already exists';
GO

-- holdshort_time_utc - Hold short point entry time
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'holdshort_time_utc')
BEGIN
    ALTER TABLE dbo.swim_flights ADD holdshort_time_utc DATETIME2(0) NULL;
    PRINT '+ Added holdshort_time_utc (SimTraffic: departure.holdshort_time)';
END
ELSE PRINT '= holdshort_time_utc already exists';
GO

-- runway_time_utc - Runway entry time
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'runway_time_utc')
BEGIN
    ALTER TABLE dbo.swim_flights ADD runway_time_utc DATETIME2(0) NULL;
    PRINT '+ Added runway_time_utc (SimTraffic: departure.runway_time)';
END
ELSE PRINT '= runway_time_utc already exists';
GO

-- ============================================================================
-- SECTION 2: Actual Arrival Times (for metering compliance analysis)
-- ============================================================================

-- actual_metering_time - Actual time at meter fix (ATA vs STA comparison)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'actual_metering_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD actual_metering_time DATETIME2(0) NULL;
    PRINT '+ Added actual_metering_time (PERTI calculated: ATA at meter fix)';
END
ELSE PRINT '= actual_metering_time already exists';
GO

-- actual_vertex_time - Actual time at vertex/corner post (ATA vs STA comparison)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'actual_vertex_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD actual_vertex_time DATETIME2(0) NULL;
    PRINT '+ Added actual_vertex_time (PERTI calculated: ATA at vertex)';
END
ELSE PRINT '= actual_vertex_time already exists';
GO

-- ============================================================================
-- SECTION 3: Airspace Location
-- ============================================================================

-- current_artcc - Current ARTCC (from SimTraffic status.in_artcc)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'current_artcc')
BEGIN
    ALTER TABLE dbo.swim_flights ADD current_artcc NVARCHAR(8) NULL;
    PRINT '+ Added current_artcc (SimTraffic: status.in_artcc)';
END
ELSE PRINT '= current_artcc already exists';
GO

-- ============================================================================
-- SECTION 4: SimTraffic Sync Tracking
-- ============================================================================

-- simtraffic_sync_utc - Last sync from SimTraffic API
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'simtraffic_sync_utc')
BEGIN
    ALTER TABLE dbo.swim_flights ADD simtraffic_sync_utc DATETIME2(0) NULL;
    PRINT '+ Added simtraffic_sync_utc (Last SimTraffic API sync time)';
END
ELSE PRINT '= simtraffic_sync_utc already exists';
GO

-- simtraffic_phase - Flight phase from SimTraffic (preflight, taxiing, departed, enroute, arrived)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'simtraffic_phase')
BEGIN
    ALTER TABLE dbo.swim_flights ADD simtraffic_phase NVARCHAR(16) NULL;
    PRINT '+ Added simtraffic_phase (SimTraffic: status-derived phase)';
END
ELSE PRINT '= simtraffic_phase already exists';
GO

-- ============================================================================
-- SECTION 5: Indexes for SimTraffic queries
-- ============================================================================

-- Index for SimTraffic delta sync queries
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'IX_swim_flights_simtraffic_sync')
BEGIN
    CREATE INDEX IX_swim_flights_simtraffic_sync
    ON dbo.swim_flights (simtraffic_sync_utc)
    WHERE simtraffic_sync_utc IS NOT NULL AND is_active = 1;
    PRINT '+ Created index IX_swim_flights_simtraffic_sync';
END
ELSE PRINT '= Index IX_swim_flights_simtraffic_sync already exists';
GO

-- Index for ARTCC-based queries
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'IX_swim_flights_current_artcc')
BEGIN
    CREATE INDEX IX_swim_flights_current_artcc
    ON dbo.swim_flights (current_artcc)
    WHERE current_artcc IS NOT NULL AND is_active = 1;
    PRINT '+ Created index IX_swim_flights_current_artcc';
END
ELSE PRINT '= Index IX_swim_flights_current_artcc already exists';
GO

PRINT '';
PRINT '==========================================================================';
PRINT '  Migration 017 Complete: SimTraffic Times & Metering Compliance Fields';
PRINT '';
PRINT '  Departure sequence times: taxi_time_utc, sequence_time_utc,';
PRINT '    holdshort_time_utc, runway_time_utc';
PRINT '';
PRINT '  Actual arrival times (for metering compliance):';
PRINT '    actual_metering_time, actual_vertex_time';
PRINT '';
PRINT '  Airspace: current_artcc';
PRINT '';
PRINT '  Sync tracking: simtraffic_sync_utc, simtraffic_phase';
PRINT '==========================================================================';
GO
