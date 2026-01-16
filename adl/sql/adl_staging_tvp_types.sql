-- ============================================================================
-- V9.1 Table-Valued Parameter Types for Staged Refresh
--
-- These types allow PHP to pass entire pilot/prefile arrays in a single
-- round-trip instead of 43+ batched INSERTs.
--
-- Performance: 1 round trip (~50ms) vs 43 round trips (~1000ms)
-- ============================================================================

-- Drop existing types if they exist (must drop dependent SPs first)
IF EXISTS (SELECT * FROM sys.table_types WHERE name = 'PilotStagingType')
BEGIN
    -- Check for dependent procedures
    IF OBJECT_ID('dbo.sp_InsertPilotsFromTVP', 'P') IS NOT NULL
        DROP PROCEDURE dbo.sp_InsertPilotsFromTVP;
    IF OBJECT_ID('dbo.sp_InsertPrefilesFromTVP', 'P') IS NOT NULL
        DROP PROCEDURE dbo.sp_InsertPrefilesFromTVP;
    DROP TYPE dbo.PilotStagingType;
END
GO

IF EXISTS (SELECT * FROM sys.table_types WHERE name = 'PrefileStagingType')
    DROP TYPE dbo.PrefileStagingType;
GO

-- ============================================================================
-- Pilot TVP Type
-- Matches adl_staging_pilots columns (excluding identity and defaults)
-- ============================================================================
CREATE TYPE dbo.PilotStagingType AS TABLE (
    cid INT NOT NULL,
    callsign NVARCHAR(16) NOT NULL,
    lat DECIMAL(10,7) NULL,
    lon DECIMAL(11,7) NULL,
    altitude_ft INT NULL,
    groundspeed_kts INT NULL,
    heading_deg SMALLINT NULL,
    qnh_in_hg DECIMAL(5,2) NULL,
    qnh_mb INT NULL,
    flight_server NVARCHAR(32) NULL,
    logon_time DATETIME2(0) NULL,
    fp_rule NCHAR(1) NULL,
    dept_icao CHAR(4) NULL,
    dest_icao CHAR(4) NULL,
    alt_icao CHAR(4) NULL,
    route NVARCHAR(MAX) NULL,
    remarks NVARCHAR(MAX) NULL,
    altitude_filed_raw NVARCHAR(16) NULL,
    tas_filed_raw NVARCHAR(16) NULL,
    dep_time_z CHAR(4) NULL,
    enroute_time_raw NVARCHAR(8) NULL,
    fuel_time_raw NVARCHAR(8) NULL,
    aircraft_faa_raw NVARCHAR(32) NULL,
    aircraft_short NVARCHAR(8) NULL,
    fp_dof_raw NVARCHAR(16) NULL,
    flight_key NVARCHAR(128) NOT NULL,
    route_hash VARBINARY(32) NULL,
    airline_icao CHAR(3) NULL
);
GO

-- ============================================================================
-- Prefile TVP Type
-- Matches adl_staging_prefiles columns (excluding identity and defaults)
-- ============================================================================
CREATE TYPE dbo.PrefileStagingType AS TABLE (
    cid INT NOT NULL,
    callsign NVARCHAR(16) NOT NULL,
    fp_rule NCHAR(1) NULL,
    dept_icao CHAR(4) NULL,
    dest_icao CHAR(4) NULL,
    alt_icao CHAR(4) NULL,
    route NVARCHAR(MAX) NULL,
    remarks NVARCHAR(MAX) NULL,
    altitude_filed_raw NVARCHAR(16) NULL,
    tas_filed_raw NVARCHAR(16) NULL,
    dep_time_z CHAR(4) NULL,
    enroute_time_raw NVARCHAR(8) NULL,
    aircraft_faa_raw NVARCHAR(32) NULL,
    aircraft_short NVARCHAR(8) NULL,
    flight_key NVARCHAR(128) NOT NULL,
    route_hash VARBINARY(32) NULL
);
GO

-- ============================================================================
-- Helper SP: Insert pilots from TVP to staging table
-- ============================================================================
CREATE OR ALTER PROCEDURE dbo.sp_InsertPilotsFromTVP
    @pilots dbo.PilotStagingType READONLY,
    @batch_id UNIQUEIDENTIFIER
AS
BEGIN
    SET NOCOUNT ON;

    -- Clear existing staging data
    TRUNCATE TABLE dbo.adl_staging_pilots;

    -- Bulk insert from TVP
    INSERT INTO dbo.adl_staging_pilots (
        cid, callsign, lat, lon, altitude_ft, groundspeed_kts,
        heading_deg, qnh_in_hg, qnh_mb, flight_server, logon_time,
        fp_rule, dept_icao, dest_icao, alt_icao, route, remarks,
        altitude_filed_raw, tas_filed_raw, dep_time_z, enroute_time_raw,
        fuel_time_raw, aircraft_faa_raw, aircraft_short, fp_dof_raw,
        flight_key, route_hash, airline_icao, batch_id
    )
    SELECT
        cid, callsign, lat, lon, altitude_ft, groundspeed_kts,
        heading_deg, qnh_in_hg, qnh_mb, flight_server, logon_time,
        fp_rule, dept_icao, dest_icao, alt_icao, route, remarks,
        altitude_filed_raw, tas_filed_raw, dep_time_z, enroute_time_raw,
        fuel_time_raw, aircraft_faa_raw, aircraft_short, fp_dof_raw,
        flight_key, route_hash, airline_icao, @batch_id
    FROM @pilots;

    SELECT @@ROWCOUNT AS rows_inserted;
END;
GO

-- ============================================================================
-- Helper SP: Insert prefiles from TVP to staging table
-- ============================================================================
CREATE OR ALTER PROCEDURE dbo.sp_InsertPrefilesFromTVP
    @prefiles dbo.PrefileStagingType READONLY,
    @batch_id UNIQUEIDENTIFIER
AS
BEGIN
    SET NOCOUNT ON;

    -- Clear existing staging data
    TRUNCATE TABLE dbo.adl_staging_prefiles;

    -- Bulk insert from TVP
    INSERT INTO dbo.adl_staging_prefiles (
        cid, callsign, fp_rule, dept_icao, dest_icao, alt_icao,
        route, remarks, altitude_filed_raw, tas_filed_raw,
        dep_time_z, enroute_time_raw, aircraft_faa_raw, aircraft_short,
        flight_key, route_hash, batch_id
    )
    SELECT
        cid, callsign, fp_rule, dept_icao, dest_icao, alt_icao,
        route, remarks, altitude_filed_raw, tas_filed_raw,
        dep_time_z, enroute_time_raw, aircraft_faa_raw, aircraft_short,
        flight_key, route_hash, @batch_id
    FROM @prefiles;

    SELECT @@ROWCOUNT AS rows_inserted;
END;
GO

PRINT 'V9.1 TVP types and helper SPs created successfully';
PRINT 'PilotStagingType: TVP for pilot data';
PRINT 'PrefileStagingType: TVP for prefile data';
PRINT 'sp_InsertPilotsFromTVP: Bulk insert pilots from TVP';
PRINT 'sp_InsertPrefilesFromTVP: Bulk insert prefiles from TVP';
GO
