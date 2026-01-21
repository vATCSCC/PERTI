-- ============================================================================
-- 011_swim_fixm_route_trajectory.sql
-- SWIM_API Database: Add FIXM 4.3 compliant route & trajectory fields
--
-- FIXM Field Mapping Reference: docs/swim/VATSWIM_FIXM_Field_Mapping.md
-- ============================================================================

USE SWIM_API;
GO

PRINT '==========================================================================';
PRINT '  FIXM Migration 011: Route & Trajectory Fields';
PRINT '  ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
GO

-- ============================================================================
-- SECTION 1: Route Fields
-- ============================================================================

-- current_route_text (FIXM: currentRouteText) - Current/amended route
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'current_route_text')
BEGIN
    ALTER TABLE dbo.swim_flights ADD current_route_text NVARCHAR(MAX) NULL;
    PRINT '+ Added current_route_text (FIXM: currentRouteText)';
END
ELSE PRINT '= current_route_text already exists';
GO

-- sid (FIXM: standardInstrumentDeparture) - SID procedure name
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'sid')
BEGIN
    ALTER TABLE dbo.swim_flights ADD sid NVARCHAR(20) NULL;
    PRINT '+ Added sid (FIXM: standardInstrumentDeparture)';
END
ELSE PRINT '= sid already exists';
GO

-- star (FIXM: standardInstrumentArrival) - STAR procedure name
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'star')
BEGIN
    ALTER TABLE dbo.swim_flights ADD star NVARCHAR(20) NULL;
    PRINT '+ Added star (FIXM: standardInstrumentArrival)';
END
ELSE PRINT '= star already exists';
GO

-- approach_procedure (FIXM: approachProcedure) - IAP procedure name
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'approach_procedure')
BEGIN
    ALTER TABLE dbo.swim_flights ADD approach_procedure NVARCHAR(20) NULL;
    PRINT '+ Added approach_procedure (FIXM: approachProcedure)';
END
ELSE PRINT '= approach_procedure already exists';
GO

-- departure_point (FIXM: departurePoint) - Departure fix identifier
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'departure_point')
BEGIN
    ALTER TABLE dbo.swim_flights ADD departure_point NVARCHAR(10) NULL;
    PRINT '+ Added departure_point (FIXM: departurePoint)';
END
ELSE PRINT '= departure_point already exists';
GO

-- arrival_point (FIXM: arrivalPoint) - Arrival fix identifier
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'arrival_point')
BEGIN
    ALTER TABLE dbo.swim_flights ADD arrival_point NVARCHAR(10) NULL;
    PRINT '+ Added arrival_point (FIXM: arrivalPoint)';
END
ELSE PRINT '= arrival_point already exists';
GO

-- ============================================================================
-- SECTION 2: Altitude & Speed Fields
-- ============================================================================

-- flight_level (FIXM: flightLevel) - Flight level (e.g., 350 for FL350)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'flight_level')
BEGIN
    ALTER TABLE dbo.swim_flights ADD flight_level SMALLINT NULL;
    PRINT '+ Added flight_level (FIXM: flightLevel)';
END
ELSE PRINT '= flight_level already exists';
GO

-- mach_number (FIXM: machNumber) - Mach number (e.g., 0.82)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'mach_number')
BEGIN
    ALTER TABLE dbo.swim_flights ADD mach_number DECIMAL(4,3) NULL;
    PRINT '+ Added mach_number (FIXM: machNumber)';
END
ELSE PRINT '= mach_number already exists';
GO

-- ============================================================================
-- SECTION 3: Indexes for route fields
-- ============================================================================

-- Index on SID for departure flow analysis
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'IX_swim_flights_sid')
BEGIN
    CREATE INDEX IX_swim_flights_sid ON dbo.swim_flights (fp_dept_icao, sid) WHERE sid IS NOT NULL AND is_active = 1;
    PRINT '+ Created index IX_swim_flights_sid';
END
GO

-- Index on STAR for arrival flow analysis
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'IX_swim_flights_star')
BEGIN
    CREATE INDEX IX_swim_flights_star ON dbo.swim_flights (fp_dest_icao, star) WHERE star IS NOT NULL AND is_active = 1;
    PRINT '+ Created index IX_swim_flights_star';
END
GO

PRINT '';
PRINT '==========================================================================';
PRINT '  Migration 011 Complete: Route & Trajectory Fields';
PRINT '';
PRINT '  New fields added:';
PRINT '  - current_route_text (amended route)';
PRINT '  - sid, star, approach_procedure (procedures)';
PRINT '  - departure_point, arrival_point (route fixes)';
PRINT '  - flight_level, mach_number (altitude/speed)';
PRINT '==========================================================================';
GO
