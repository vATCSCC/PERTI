-- ============================================================================
-- 012_swim_fixm_airports_gates.sql
-- SWIM_API Database: Add FIXM 4.3 compliant airport & gate fields
--
-- FIXM Field Mapping Reference: docs/swim/VATSWIM_FIXM_Field_Mapping.md
-- ============================================================================

USE SWIM_API;
GO

PRINT '==========================================================================';
PRINT '  FIXM Migration 012: Airports & Gates Fields';
PRINT '  ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
GO

-- ============================================================================
-- SECTION 1: Airport Fields
-- ============================================================================

-- alternate_aerodrome (FIXM: alternateAerodrome) - Alternate airport ICAO
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'alternate_aerodrome')
BEGIN
    ALTER TABLE dbo.swim_flights ADD alternate_aerodrome CHAR(4) NULL;
    PRINT '+ Added alternate_aerodrome (FIXM: alternateAerodrome)';
END
ELSE PRINT '= alternate_aerodrome already exists';
GO

-- diversion_aerodrome (FIXM: diversionAerodrome) - Diversion airport ICAO
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'diversion_aerodrome')
BEGIN
    ALTER TABLE dbo.swim_flights ADD diversion_aerodrome CHAR(4) NULL;
    PRINT '+ Added diversion_aerodrome (FIXM: diversionAerodrome)';
END
ELSE PRINT '= diversion_aerodrome already exists';
GO

-- ============================================================================
-- SECTION 2: Gate Fields
-- ============================================================================

-- departure_gate (FIXM: departureGate) - Departure gate assignment
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'departure_gate')
BEGIN
    ALTER TABLE dbo.swim_flights ADD departure_gate NVARCHAR(10) NULL;
    PRINT '+ Added departure_gate (FIXM: departureGate)';
END
ELSE PRINT '= departure_gate already exists';
GO

-- arrival_gate (FIXM: arrivalGate) - Arrival gate assignment
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'arrival_gate')
BEGIN
    ALTER TABLE dbo.swim_flights ADD arrival_gate NVARCHAR(10) NULL;
    PRINT '+ Added arrival_gate (FIXM: arrivalGate)';
END
ELSE PRINT '= arrival_gate already exists';
GO

-- ============================================================================
-- SECTION 3: Runway Fields (if not already in schema)
-- ============================================================================

-- departure_runway (FIXM: departureRunway) - Assigned departure runway
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'departure_runway')
BEGIN
    ALTER TABLE dbo.swim_flights ADD departure_runway NVARCHAR(4) NULL;
    PRINT '+ Added departure_runway (FIXM: departureRunway)';
END
ELSE PRINT '= departure_runway already exists';
GO

-- arrival_runway (FIXM: arrivalRunway) - Assigned arrival runway
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'arrival_runway')
BEGIN
    ALTER TABLE dbo.swim_flights ADD arrival_runway NVARCHAR(4) NULL;
    PRINT '+ Added arrival_runway (FIXM: arrivalRunway)';
END
ELSE PRINT '= arrival_runway already exists';
GO

-- ============================================================================
-- SECTION 4: Indexes for airport/gate queries
-- ============================================================================

-- Index on departure gate for ground ops
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'IX_swim_flights_departure_gate')
BEGIN
    CREATE INDEX IX_swim_flights_departure_gate ON dbo.swim_flights (fp_dept_icao, departure_gate) WHERE departure_gate IS NOT NULL AND is_active = 1;
    PRINT '+ Created index IX_swim_flights_departure_gate';
END
GO

-- Index on arrival gate for ground ops
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'IX_swim_flights_arrival_gate')
BEGIN
    CREATE INDEX IX_swim_flights_arrival_gate ON dbo.swim_flights (fp_dest_icao, arrival_gate) WHERE arrival_gate IS NOT NULL AND is_active = 1;
    PRINT '+ Created index IX_swim_flights_arrival_gate';
END
GO

-- Index on departure runway for runway utilization
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'IX_swim_flights_dep_runway')
BEGIN
    CREATE INDEX IX_swim_flights_dep_runway ON dbo.swim_flights (fp_dept_icao, departure_runway) WHERE departure_runway IS NOT NULL AND is_active = 1;
    PRINT '+ Created index IX_swim_flights_dep_runway';
END
GO

-- Index on arrival runway for runway utilization
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'IX_swim_flights_arr_runway')
BEGIN
    CREATE INDEX IX_swim_flights_arr_runway ON dbo.swim_flights (fp_dest_icao, arrival_runway) WHERE arrival_runway IS NOT NULL AND is_active = 1;
    PRINT '+ Created index IX_swim_flights_arr_runway';
END
GO

PRINT '';
PRINT '==========================================================================';
PRINT '  Migration 012 Complete: Airports & Gates Fields';
PRINT '';
PRINT '  New fields added:';
PRINT '  - alternate_aerodrome, diversion_aerodrome (airports)';
PRINT '  - departure_gate, arrival_gate (gates)';
PRINT '  - departure_runway, arrival_runway (runways)';
PRINT '==========================================================================';
GO
