-- ============================================================================
-- ADL Migration 060: CIFP Procedure Legs Schema
--
-- Creates nav_procedure_legs table for storing detailed leg information from
-- X-Plane CIFP files, and extends existing tables for constraint tracking.
--
-- Supports: SIDs (DPs), STARs with leg types, altitude/speed constraints
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Migration 060: CIFP Procedure Legs Schema ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. Create nav_procedure_legs table
-- ============================================================================

IF OBJECT_ID('dbo.nav_procedure_legs', 'U') IS NOT NULL
BEGIN
    PRINT 'Table nav_procedure_legs already exists, skipping creation';
END
ELSE
BEGIN
    PRINT 'Creating table: nav_procedure_legs';

    CREATE TABLE dbo.nav_procedure_legs (
        leg_id              BIGINT IDENTITY(1,1) NOT NULL,
        procedure_id        INT NOT NULL,

        -- Leg sequencing
        sequence_num        INT NOT NULL,
        route_type          TINYINT NOT NULL,            -- 1=RWY, 2=COMMON, 4=PROC, 5=ENROUTE, 6=TRANS

        -- Waypoint/Fix
        fix_name            NVARCHAR(16) NULL,           -- NULL for VA/VM/CA legs (no waypoint)
        fix_region          NVARCHAR(4) NULL,            -- K6, E, etc.
        fix_section         NVARCHAR(4) NULL,            -- E=enroute, A=airport, D=navaid, P=fix

        -- Leg type (ARINC 424 path terminators)
        leg_type            CHAR(2) NOT NULL,            -- IF, TF, CF, DF, VA, VM, CA, VI, FM, HM

        -- Course/Distance
        outbound_course     DECIMAL(5,1) NULL,           -- Outbound magnetic course
        inbound_course      DECIMAL(5,1) NULL,           -- Inbound magnetic course
        distance_nm         DECIMAL(6,2) NULL,           -- Distance in NM

        -- Recommended Navaid (for CF/AF legs)
        rec_navaid          NVARCHAR(16) NULL,
        rec_navaid_region   NVARCHAR(4) NULL,

        -- Altitude Constraints
        alt_restriction     CHAR(1) NULL,                -- +, -, B, @, J, H
        altitude_1_ft       INT NULL,                    -- Primary altitude (feet)
        altitude_2_ft       INT NULL,                    -- Secondary altitude (for BETWEEN)

        -- Speed Constraints
        speed_limit_kts     SMALLINT NULL,               -- Speed in knots
        speed_restriction   CHAR(1) NULL,                -- +, -, @

        -- Flags
        is_flyover          BIT NOT NULL DEFAULT 0,      -- Must fly over (not fly-by)
        is_hold_waypoint    BIT NOT NULL DEFAULT 0,      -- Holding allowed at this fix

        -- Constraints
        CONSTRAINT PK_nav_procedure_legs PRIMARY KEY CLUSTERED (leg_id),
        CONSTRAINT FK_procedure_legs_procedure FOREIGN KEY (procedure_id)
            REFERENCES dbo.nav_procedures(procedure_id) ON DELETE CASCADE
    );

    PRINT 'Created table: nav_procedure_legs';
END
GO

-- Create indexes for nav_procedure_legs
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_legs_procedure_seq' AND object_id = OBJECT_ID('dbo.nav_procedure_legs'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_legs_procedure_seq
        ON dbo.nav_procedure_legs (procedure_id, sequence_num);
    PRINT 'Created index: IX_legs_procedure_seq';
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_legs_fix_name' AND object_id = OBJECT_ID('dbo.nav_procedure_legs'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_legs_fix_name
        ON dbo.nav_procedure_legs (fix_name)
        WHERE fix_name IS NOT NULL;
    PRINT 'Created index: IX_legs_fix_name';
END
GO

-- ============================================================================
-- 2. Extend nav_procedures table
-- ============================================================================

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.nav_procedures') AND name = 'has_leg_detail')
BEGIN
    ALTER TABLE dbo.nav_procedures ADD has_leg_detail BIT NOT NULL DEFAULT 0;
    PRINT 'Added column: nav_procedures.has_leg_detail';
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.nav_procedures') AND name = 'cifp_file')
BEGIN
    ALTER TABLE dbo.nav_procedures ADD cifp_file NVARCHAR(32) NULL;
    PRINT 'Added column: nav_procedures.cifp_file';
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.nav_procedures') AND name = 'cifp_import_utc')
BEGIN
    ALTER TABLE dbo.nav_procedures ADD cifp_import_utc DATETIME2(0) NULL;
    PRINT 'Added column: nav_procedures.cifp_import_utc';
END
GO

-- ============================================================================
-- 3. Extend adl_flight_waypoints table for constraint tracking
-- ============================================================================

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_waypoints') AND name = 'leg_type')
BEGIN
    ALTER TABLE dbo.adl_flight_waypoints ADD leg_type CHAR(2) NULL;
    PRINT 'Added column: adl_flight_waypoints.leg_type';
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_waypoints') AND name = 'alt_restriction')
BEGIN
    ALTER TABLE dbo.adl_flight_waypoints ADD alt_restriction CHAR(1) NULL;
    PRINT 'Added column: adl_flight_waypoints.alt_restriction';
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_waypoints') AND name = 'altitude_1_ft')
BEGIN
    ALTER TABLE dbo.adl_flight_waypoints ADD altitude_1_ft INT NULL;
    PRINT 'Added column: adl_flight_waypoints.altitude_1_ft';
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_waypoints') AND name = 'altitude_2_ft')
BEGIN
    ALTER TABLE dbo.adl_flight_waypoints ADD altitude_2_ft INT NULL;
    PRINT 'Added column: adl_flight_waypoints.altitude_2_ft';
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_waypoints') AND name = 'speed_limit_kts')
BEGIN
    ALTER TABLE dbo.adl_flight_waypoints ADD speed_limit_kts SMALLINT NULL;
    PRINT 'Added column: adl_flight_waypoints.speed_limit_kts';
END
GO

-- ============================================================================
-- 4. Create staging tables for CIFP bulk import
-- ============================================================================

IF OBJECT_ID('dbo.cifp_procedures_staging', 'U') IS NOT NULL
    DROP TABLE dbo.cifp_procedures_staging;
GO

CREATE TABLE dbo.cifp_procedures_staging (
    airport_icao        NVARCHAR(8) NOT NULL,
    procedure_type      NVARCHAR(8) NOT NULL,            -- SID, STAR
    procedure_name      NVARCHAR(32) NOT NULL,
    runway_transition   NVARCHAR(16) NULL,               -- RW31L, ALL, transition name
    cifp_file           NVARCHAR(32) NOT NULL
);
GO

PRINT 'Created staging table: cifp_procedures_staging';
GO

IF OBJECT_ID('dbo.cifp_legs_staging', 'U') IS NOT NULL
    DROP TABLE dbo.cifp_legs_staging;
GO

CREATE TABLE dbo.cifp_legs_staging (
    airport_icao        NVARCHAR(8) NOT NULL,
    procedure_type      NVARCHAR(8) NOT NULL,
    procedure_name      NVARCHAR(32) NOT NULL,
    runway_transition   NVARCHAR(16) NULL,
    sequence_num        INT NOT NULL,
    route_type          TINYINT NOT NULL,
    fix_name            NVARCHAR(16) NULL,
    fix_region          NVARCHAR(4) NULL,
    fix_section         NVARCHAR(4) NULL,
    leg_type            CHAR(2) NOT NULL,
    outbound_course     DECIMAL(5,1) NULL,
    inbound_course      DECIMAL(5,1) NULL,
    distance_nm         DECIMAL(6,2) NULL,
    rec_navaid          NVARCHAR(16) NULL,
    rec_navaid_region   NVARCHAR(4) NULL,
    alt_restriction     CHAR(1) NULL,
    altitude_1_ft       INT NULL,
    altitude_2_ft       INT NULL,
    speed_limit_kts     SMALLINT NULL,
    speed_restriction   CHAR(1) NULL,
    is_flyover          BIT NOT NULL DEFAULT 0,
    is_hold_waypoint    BIT NOT NULL DEFAULT 0,
    cifp_file           NVARCHAR(32) NOT NULL
);
GO

PRINT 'Created staging table: cifp_legs_staging';
GO

-- ============================================================================
-- 5. Helper function: Expand procedure legs with coordinates
-- ============================================================================

IF OBJECT_ID('dbo.fn_ExpandProcedureLegs', 'IF') IS NOT NULL
    DROP FUNCTION dbo.fn_ExpandProcedureLegs;
GO

CREATE FUNCTION dbo.fn_ExpandProcedureLegs (
    @procedure_id INT
)
RETURNS TABLE
AS
RETURN
(
    SELECT
        pl.sequence_num,
        pl.fix_name,
        nf.lat,
        nf.lon,
        pl.leg_type,
        pl.alt_restriction,
        pl.altitude_1_ft,
        pl.altitude_2_ft,
        pl.speed_limit_kts,
        pl.is_flyover,
        pl.is_hold_waypoint
    FROM dbo.nav_procedure_legs pl
    OUTER APPLY (
        -- Get first matching fix (prefer exact name match, then by fix_id)
        SELECT TOP 1 lat, lon
        FROM dbo.nav_fixes
        WHERE fix_name = pl.fix_name
        ORDER BY fix_id
    ) nf
    WHERE pl.procedure_id = @procedure_id
      AND pl.fix_name IS NOT NULL          -- Skip VA/VM/CA legs (no waypoint)
);
GO

PRINT 'Created function: fn_ExpandProcedureLegs';
GO

-- ============================================================================
-- Complete
-- ============================================================================

PRINT '';
PRINT '=== Migration 060 Complete ===';
PRINT 'Completed at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '';
PRINT 'Tables created:';
PRINT '  - nav_procedure_legs (leg-level procedure data)';
PRINT '  - cifp_procedures_staging (bulk import staging)';
PRINT '  - cifp_legs_staging (bulk import staging)';
PRINT '';
PRINT 'Columns added:';
PRINT '  - nav_procedures: has_leg_detail, cifp_file, cifp_import_utc';
PRINT '  - adl_flight_waypoints: leg_type, alt_restriction, altitude_1_ft, altitude_2_ft, speed_limit_kts';
PRINT '';
PRINT 'Functions created:';
PRINT '  - fn_ExpandProcedureLegs (expand procedure legs with coordinates)';
GO
