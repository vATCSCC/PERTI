-- ============================================================================
-- 010_swim_fixm_identity_aircraft.sql
-- SWIM_API Database: Add FIXM 4.3 compliant identity & aircraft fields
--
-- FIXM Field Mapping Reference: docs/swim/VATSWIM_FIXM_Field_Mapping.md
-- ============================================================================

USE SWIM_API;
GO

PRINT '==========================================================================';
PRINT '  FIXM Migration 010: Identity & Aircraft Fields';
PRINT '  ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
GO

-- ============================================================================
-- SECTION 1: Flight Identification Fields
-- ============================================================================

-- pilot_name (vATCSCC:pilotName) - VATSIM pilot name
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'pilot_name')
BEGIN
    ALTER TABLE dbo.swim_flights ADD pilot_name NVARCHAR(128) NULL;
    PRINT '+ Added pilot_name (vATCSCC:pilotName)';
END
ELSE PRINT '= pilot_name already exists';
GO

-- pilot_rating (vATCSCC:pilotRating) - VATSIM pilot rating (NEW, PPL, IR, CMEL, ATPL)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'pilot_rating')
BEGIN
    ALTER TABLE dbo.swim_flights ADD pilot_rating NVARCHAR(16) NULL;
    PRINT '+ Added pilot_rating (vATCSCC:pilotRating)';
END
ELSE PRINT '= pilot_rating already exists';
GO

-- flight_number (FIXM: flightNumber) - Airline flight number (e.g., "123" from UAL123)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'flight_number')
BEGIN
    ALTER TABLE dbo.swim_flights ADD flight_number NVARCHAR(10) NULL;
    PRINT '+ Added flight_number (FIXM: flightNumber)';
END
ELSE PRINT '= flight_number already exists';
GO

-- operator_iata (FIXM: operatorIataDesignator) - IATA airline code (e.g., "UA")
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'operator_iata')
BEGIN
    ALTER TABLE dbo.swim_flights ADD operator_iata NVARCHAR(3) NULL;
    PRINT '+ Added operator_iata (FIXM: operatorIataDesignator)';
END
ELSE PRINT '= operator_iata already exists';
GO

-- registration (FIXM: registration) - Aircraft tail number (e.g., "N12345")
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'registration')
BEGIN
    ALTER TABLE dbo.swim_flights ADD registration NVARCHAR(10) NULL;
    PRINT '+ Added registration (FIXM: registration)';
END
ELSE PRINT '= registration already exists';
GO

-- ============================================================================
-- SECTION 2: Aircraft Information Fields
-- ============================================================================

-- engine_count (FIXM: engineCount) - Number of engines
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'engine_count')
BEGIN
    ALTER TABLE dbo.swim_flights ADD engine_count TINYINT NULL;
    PRINT '+ Added engine_count (FIXM: engineCount)';
END
ELSE PRINT '= engine_count already exists';
GO

-- equipment_qualifier (FIXM: equipmentQualifier) - RVSM/RNAV capability codes
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'equipment_qualifier')
BEGIN
    ALTER TABLE dbo.swim_flights ADD equipment_qualifier NVARCHAR(32) NULL;
    PRINT '+ Added equipment_qualifier (FIXM: equipmentQualifier)';
END
ELSE PRINT '= equipment_qualifier already exists';
GO

-- ============================================================================
-- SECTION 3: Indexes for new fields
-- ============================================================================

-- Index on registration for aircraft lookup
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'IX_swim_flights_registration')
BEGIN
    CREATE INDEX IX_swim_flights_registration ON dbo.swim_flights (registration) WHERE registration IS NOT NULL;
    PRINT '+ Created index IX_swim_flights_registration';
END
GO

-- Index on flight_number for airline operations
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'IX_swim_flights_flight_number')
BEGIN
    CREATE INDEX IX_swim_flights_flight_number ON dbo.swim_flights (airline_icao, flight_number) WHERE flight_number IS NOT NULL;
    PRINT '+ Created index IX_swim_flights_flight_number';
END
GO

PRINT '';
PRINT '==========================================================================';
PRINT '  Migration 010 Complete: Identity & Aircraft Fields';
PRINT '';
PRINT '  New fields added:';
PRINT '  - pilot_name, pilot_rating (VATSIM-specific)';
PRINT '  - flight_number, operator_iata (FIXM standard)';
PRINT '  - registration (FIXM: aircraft tail number)';
PRINT '  - engine_count, equipment_qualifier (FIXM aircraft info)';
PRINT '==========================================================================';
GO
