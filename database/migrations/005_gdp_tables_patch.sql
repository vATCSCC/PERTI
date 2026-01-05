-- GDP Migration Patch: Fix scope column index error
-- Run this if you got "Invalid column name 'scope'" error

-- Drop the problematic index if it was partially created
IF EXISTS (SELECT * FROM sys.indexes WHERE name = 'UQ_adl_flights_gdp_scope_flight')
BEGIN
    DROP INDEX UQ_adl_flights_gdp_scope_flight ON dbo.adl_flights_gdp;
    PRINT 'Dropped old scope index';
END
GO

-- Create the correct unique index on flight_key only
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'UQ_adl_flights_gdp_flight_key' AND object_id = OBJECT_ID('dbo.adl_flights_gdp'))
BEGIN
    CREATE UNIQUE INDEX UQ_adl_flights_gdp_flight_key 
        ON dbo.adl_flights_gdp(flight_key) 
        WHERE flight_key IS NOT NULL;
    PRINT 'Created unique index on flight_key';
END
GO

-- Create remaining indexes if missing
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_adl_flights_gdp_program' AND object_id = OBJECT_ID('dbo.adl_flights_gdp'))
BEGIN
    CREATE INDEX IX_adl_flights_gdp_program ON dbo.adl_flights_gdp(gdp_program_id);
    PRINT 'Created index on gdp_program_id';
END
GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_adl_flights_gdp_dest' AND object_id = OBJECT_ID('dbo.adl_flights_gdp'))
BEGIN
    CREATE INDEX IX_adl_flights_gdp_dest ON dbo.adl_flights_gdp(fp_dest_icao);
    PRINT 'Created index on fp_dest_icao';
END
GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_adl_flights_gdp_eta' AND object_id = OBJECT_ID('dbo.adl_flights_gdp'))
BEGIN
    CREATE INDEX IX_adl_flights_gdp_eta ON dbo.adl_flights_gdp(eta_runway_utc);
    PRINT 'Created index on eta_runway_utc';
END
GO

PRINT 'Patch complete';
