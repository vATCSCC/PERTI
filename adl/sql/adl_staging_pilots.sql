-- ============================================================================
-- adl_staging_pilots - Staging table for PHP-parsed pilot data
--
-- This table receives pilot data parsed by PHP from VATSIM JSON.
-- The refresh SP reads from this table instead of using OPENJSON,
-- reducing SQL compute costs by 3-5 seconds per cycle.
--
-- Usage:
--   1. PHP daemon truncates table
--   2. PHP daemon bulk-inserts parsed pilots
--   3. SP reads from staging table (no OPENJSON needed)
--
-- Performance: ~100ms PHP parse + ~200ms bulk insert vs 3-5s OPENJSON
-- Cost savings: ~50% reduction in Serverless vCore-hours
-- ============================================================================

-- Drop existing table if it exists
IF OBJECT_ID('dbo.adl_staging_pilots', 'U') IS NOT NULL
    DROP TABLE dbo.adl_staging_pilots;
GO

CREATE TABLE dbo.adl_staging_pilots (
    -- Primary key for batch tracking
    staging_id INT IDENTITY(1,1) NOT NULL,

    -- Pilot core data
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

    -- Flight plan fields
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

    -- Derived fields (computed by PHP)
    flight_key NVARCHAR(128) NOT NULL,
    route_hash VARBINARY(32) NULL,
    airline_icao CHAR(3) NULL,

    -- Delta detection (V9.3.0)
    -- Bitmask: 0=heartbeat, 1=POSITION_CHANGED, 2=PLAN_CHANGED, 4=NEW_FLIGHT
    -- Default 15 = full processing (backward-compatible for old daemon code)
    change_flags TINYINT NOT NULL DEFAULT 15,

    -- Batch tracking
    batch_id UNIQUEIDENTIFIER NOT NULL,
    inserted_utc DATETIME2(3) NOT NULL DEFAULT SYSUTCDATETIME(),

    CONSTRAINT PK_adl_staging_pilots PRIMARY KEY CLUSTERED (staging_id)
);
GO

-- Indexes for efficient joins during SP processing
CREATE NONCLUSTERED INDEX IX_staging_pilots_batch ON dbo.adl_staging_pilots (batch_id);
CREATE NONCLUSTERED INDEX IX_staging_pilots_flight_key ON dbo.adl_staging_pilots (flight_key);
CREATE NONCLUSTERED INDEX IX_staging_pilots_dept ON dbo.adl_staging_pilots (dept_icao) WHERE dept_icao IS NOT NULL;
CREATE NONCLUSTERED INDEX IX_staging_pilots_dest ON dbo.adl_staging_pilots (dest_icao) WHERE dest_icao IS NOT NULL;
GO

-- ============================================================================
-- adl_staging_prefiles - Staging table for PHP-parsed prefile data
-- ============================================================================

IF OBJECT_ID('dbo.adl_staging_prefiles', 'U') IS NOT NULL
    DROP TABLE dbo.adl_staging_prefiles;
GO

CREATE TABLE dbo.adl_staging_prefiles (
    staging_id INT IDENTITY(1,1) NOT NULL,

    cid INT NOT NULL,
    callsign NVARCHAR(16) NOT NULL,

    -- Flight plan fields
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

    -- Derived
    flight_key NVARCHAR(128) NOT NULL,
    route_hash VARBINARY(32) NULL,

    -- Batch tracking
    batch_id UNIQUEIDENTIFIER NOT NULL,
    inserted_utc DATETIME2(3) NOT NULL DEFAULT SYSUTCDATETIME(),

    CONSTRAINT PK_adl_staging_prefiles PRIMARY KEY CLUSTERED (staging_id)
);
GO

CREATE NONCLUSTERED INDEX IX_staging_prefiles_batch ON dbo.adl_staging_prefiles (batch_id);
CREATE NONCLUSTERED INDEX IX_staging_prefiles_flight_key ON dbo.adl_staging_prefiles (flight_key);
GO

-- ============================================================================
-- Helper procedure: Clear staging tables for a new batch
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_ClearStagingTables
AS
BEGIN
    SET NOCOUNT ON;
    TRUNCATE TABLE dbo.adl_staging_pilots;
    TRUNCATE TABLE dbo.adl_staging_prefiles;
END;
GO

PRINT 'Staging tables created successfully';
PRINT 'adl_staging_pilots: Receives PHP-parsed pilot data';
PRINT 'adl_staging_prefiles: Receives PHP-parsed prefile data';
GO
