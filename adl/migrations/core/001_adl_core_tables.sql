-- ============================================================================
-- ADL Normalized Schema - Migration 001: Core Tables
-- 
-- Part of the ADL Database Redesign
-- Creates the foundational normalized tables for flight data
-- 
-- Run Order: 1 of 5
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Migration 001: Core Tables ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. adl_flight_core - Master Flight Registry
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_core') AND type = 'U')
BEGIN
    CREATE TABLE dbo.adl_flight_core (
        -- Primary Key (surrogate)
        flight_uid          BIGINT IDENTITY(1,1) NOT NULL,
        
        -- Natural Key
        flight_key          NVARCHAR(64) NOT NULL,           -- cid|callsign|dept|dest|deptime
        
        -- Core Identifiers
        cid                 INT NOT NULL,
        callsign            NVARCHAR(16) NOT NULL,
        flight_id           NVARCHAR(32) NULL,               -- VATSIM flight ID if available
        
        -- Lifecycle State
        phase               NVARCHAR(16) NOT NULL DEFAULT 'unknown',  -- prefile, taxiing, departed, enroute, descending, arrived, unknown
        last_source         NVARCHAR(16) NOT NULL DEFAULT 'vatsim',   -- vatsim, prefile, simtraffic
        is_active           BIT NOT NULL DEFAULT 1,
        
        -- Timestamps
        first_seen_utc      DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        last_seen_utc       DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        logon_time_utc      DATETIME2(0) NULL,
        
        -- ADL Bookkeeping
        adl_date            DATE NOT NULL DEFAULT CAST(SYSUTCDATETIME() AS DATE),
        adl_time            TIME(0) NOT NULL DEFAULT CAST(SYSUTCDATETIME() AS TIME),
        snapshot_utc        DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        
        -- Constraints
        CONSTRAINT PK_adl_flight_core PRIMARY KEY CLUSTERED (flight_uid),
        CONSTRAINT UK_adl_flight_core_key UNIQUE NONCLUSTERED (flight_key)
    );
    
    -- Indexes
    CREATE NONCLUSTERED INDEX IX_core_callsign ON dbo.adl_flight_core (callsign);
    CREATE NONCLUSTERED INDEX IX_core_cid ON dbo.adl_flight_core (cid);
    CREATE NONCLUSTERED INDEX IX_core_active ON dbo.adl_flight_core (is_active) WHERE is_active = 1;
    CREATE NONCLUSTERED INDEX IX_core_phase ON dbo.adl_flight_core (phase) WHERE is_active = 1;
    CREATE NONCLUSTERED INDEX IX_core_last_seen ON dbo.adl_flight_core (last_seen_utc DESC);
    
    PRINT 'Created table dbo.adl_flight_core';
END
ELSE
BEGIN
    PRINT 'Table dbo.adl_flight_core already exists - skipping';
END
GO

-- ============================================================================
-- 2. adl_flight_position - Real-time Position & Velocity
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_position') AND type = 'U')
BEGIN
    CREATE TABLE dbo.adl_flight_position (
        flight_uid          BIGINT NOT NULL,
        
        -- Position
        lat                 DECIMAL(10,7) NULL,
        lon                 DECIMAL(11,7) NULL,
        
        -- Altitude
        altitude_ft         INT NULL,
        altitude_assigned   INT NULL,
        altitude_cleared    INT NULL,
        
        -- Velocity
        groundspeed_kts     INT NULL,
        true_airspeed_kts   INT NULL,
        mach                DECIMAL(4,3) NULL,
        vertical_rate_fpm   INT NULL,
        
        -- Heading/Track
        heading_deg         SMALLINT NULL,
        track_deg           SMALLINT NULL,
        
        -- Atmospheric
        qnh_in_hg           DECIMAL(5,2) NULL,
        qnh_mb              INT NULL,
        
        -- Computed Fields
        dist_to_dest_nm     DECIMAL(8,2) NULL,
        dist_flown_nm       DECIMAL(8,2) NULL,
        pct_complete        DECIMAL(5,2) NULL,
        
        -- Update Tracking
        position_updated_utc DATETIME2(3) NOT NULL DEFAULT SYSUTCDATETIME(),
        
        CONSTRAINT PK_adl_flight_position PRIMARY KEY CLUSTERED (flight_uid),
        CONSTRAINT FK_position_core FOREIGN KEY (flight_uid) 
            REFERENCES dbo.adl_flight_core(flight_uid) ON DELETE CASCADE
    );
    
    PRINT 'Created table dbo.adl_flight_position';
END
ELSE
BEGIN
    PRINT 'Table dbo.adl_flight_position already exists - skipping';
END
GO

-- Add spatial column and index (separate step for compatibility)
IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_position') AND type = 'U')
BEGIN
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_position') AND name = 'position_geo')
    BEGIN
        ALTER TABLE dbo.adl_flight_position ADD position_geo GEOGRAPHY NULL;
        PRINT 'Added position_geo column to adl_flight_position';
    END
    
    IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.adl_flight_position') AND name = 'IX_position_geo')
    BEGIN
        CREATE SPATIAL INDEX IX_position_geo ON dbo.adl_flight_position (position_geo);
        PRINT 'Created spatial index IX_position_geo';
    END
END
GO

-- ============================================================================
-- 3. adl_flight_plan - Flight Plan Data with GIS
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_plan') AND type = 'U')
BEGIN
    CREATE TABLE dbo.adl_flight_plan (
        flight_uid          BIGINT NOT NULL,
        
        -- Flight Rules
        fp_rule             NCHAR(1) NULL,                   -- I, V, Y, Z
        
        -- Airports
        fp_dept_icao        CHAR(4) NULL,
        fp_dest_icao        CHAR(4) NULL,
        fp_alt_icao         CHAR(4) NULL,
        
        -- Departure Info
        fp_dept_tracon      NVARCHAR(4) NULL,
        fp_dept_artcc       NVARCHAR(4) NULL,
        dfix                NVARCHAR(8) NULL,                -- Departure fix
        dp_name             NVARCHAR(16) NULL,               -- SID name
        dtrsn               NVARCHAR(16) NULL,               -- Departure transition
        
        -- Arrival Info
        fp_dest_tracon      NVARCHAR(4) NULL,
        fp_dest_artcc       NVARCHAR(4) NULL,
        afix                NVARCHAR(8) NULL,                -- Arrival fix
        star_name           NVARCHAR(16) NULL,               -- STAR name
        strsn               NVARCHAR(16) NULL,               -- STAR transition
        approach            NVARCHAR(16) NULL,
        runway              NVARCHAR(8) NULL,
        eaft_utc            DATETIME2(0) NULL,               -- Expected approach fix time
        
        -- Route (Raw)
        fp_route            NVARCHAR(MAX) NULL,
        fp_route_expanded   NVARCHAR(MAX) NULL,              -- After CDR/airway expansion
        
        -- GIS Route Geometry (nullable until parsed)
        route_geometry      GEOGRAPHY NULL,
        waypoints_json      NVARCHAR(MAX) NULL,              -- JSON array of parsed waypoints
        waypoint_count      INT NULL,
        
        -- Parse Status
        parse_status        NVARCHAR(16) NULL,               -- COMPLETE, PARTIAL, FAILED, PENDING
        parse_tier          TINYINT NULL,                    -- 0-4 tier assignment
        unresolved_fixes    NVARCHAR(512) NULL,
        parse_utc           DATETIME2(0) NULL,
        
        -- Runway Specifications (SimBrief)
        dep_runway          NVARCHAR(4) NULL,
        dep_runway_source   NVARCHAR(16) NULL,               -- ROUTE, REMARKS, SIMBRIEF
        arr_runway          NVARCHAR(4) NULL,
        arr_runway_source   NVARCHAR(16) NULL,
        
        -- Step Climb Data
        initial_alt_ft      INT NULL,
        final_alt_ft        INT NULL,
        stepclimb_count     INT NULL,
        
        -- SimBrief Metadata
        is_simbrief         BIT NOT NULL DEFAULT 0,
        simbrief_id         NVARCHAR(32) NULL,               -- OFP ID
        cost_index          INT NULL,
        
        -- Filed Values
        fp_dept_time_z      CHAR(4) NULL,                    -- Filed ETD (HHMM)
        fp_altitude_ft      INT NULL,
        fp_tas_kts          INT NULL,
        fp_enroute_minutes  INT NULL,
        fp_fuel_minutes     INT NULL,
        fp_remarks          NVARCHAR(MAX) NULL,
        gcd_nm              DECIMAL(8,2) NULL,               -- Great circle distance
        
        -- Equipment
        aircraft_type       NVARCHAR(8) NULL,
        aircraft_equip      NVARCHAR(32) NULL,
        
        -- Airspace Traversal (computed)
        artccs_traversed    NVARCHAR(256) NULL,              -- Space-delimited ARTCC list
        tracons_traversed   NVARCHAR(256) NULL,
        
        -- Change Detection
        fp_hash             BINARY(32) NULL,                 -- SHA2_256 of route+remarks
        fp_updated_utc      DATETIME2(3) NOT NULL DEFAULT SYSUTCDATETIME(),
        
        CONSTRAINT PK_adl_flight_plan PRIMARY KEY CLUSTERED (flight_uid),
        CONSTRAINT FK_plan_core FOREIGN KEY (flight_uid) 
            REFERENCES dbo.adl_flight_core(flight_uid) ON DELETE CASCADE
    );
    
    -- Indexes
    CREATE NONCLUSTERED INDEX IX_fp_dept ON dbo.adl_flight_plan (fp_dept_icao) WHERE fp_dept_icao IS NOT NULL;
    CREATE NONCLUSTERED INDEX IX_fp_dest ON dbo.adl_flight_plan (fp_dest_icao) WHERE fp_dest_icao IS NOT NULL;
    CREATE NONCLUSTERED INDEX IX_fp_dept_dest ON dbo.adl_flight_plan (fp_dept_icao, fp_dest_icao);
    CREATE NONCLUSTERED INDEX IX_fp_simbrief ON dbo.adl_flight_plan (is_simbrief) WHERE is_simbrief = 1;
    CREATE NONCLUSTERED INDEX IX_fp_parse_status ON dbo.adl_flight_plan (parse_status) WHERE parse_status IS NOT NULL;
    CREATE NONCLUSTERED INDEX IX_fp_dept_artcc ON dbo.adl_flight_plan (fp_dept_artcc) WHERE fp_dept_artcc IS NOT NULL;
    CREATE NONCLUSTERED INDEX IX_fp_dest_artcc ON dbo.adl_flight_plan (fp_dest_artcc) WHERE fp_dest_artcc IS NOT NULL;
    
    PRINT 'Created table dbo.adl_flight_plan';
END
ELSE
BEGIN
    PRINT 'Table dbo.adl_flight_plan already exists - skipping';
END
GO

-- Add spatial index for route_geometry
IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_plan') AND type = 'U')
BEGIN
    IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.adl_flight_plan') AND name = 'IX_route_geo')
    BEGIN
        CREATE SPATIAL INDEX IX_route_geo ON dbo.adl_flight_plan (route_geometry);
        PRINT 'Created spatial index IX_route_geo';
    END
END
GO

-- ============================================================================
-- 4. adl_flight_aircraft - Aircraft Information
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_aircraft') AND type = 'U')
BEGIN
    CREATE TABLE dbo.adl_flight_aircraft (
        flight_uid          BIGINT NOT NULL,
        
        -- Aircraft Type
        aircraft_icao       NVARCHAR(8) NULL,                -- ICAO type code (B738, A320, etc.)
        aircraft_faa        NVARCHAR(8) NULL,                -- FAA type code if different
        
        -- Category
        weight_class        NCHAR(1) NULL,                   -- S, L, H, J (Super)
        engine_type         NVARCHAR(8) NULL,                -- JET, TURBOPROP, PISTON
        engine_count        TINYINT NULL,
        wake_category       NVARCHAR(8) NULL,                -- LIGHT, MEDIUM, HEAVY, SUPER
        
        -- Performance (reference)
        cruise_tas_kts      INT NULL,
        ceiling_ft          INT NULL,
        
        -- Carrier/Operator
        airline_icao        NVARCHAR(4) NULL,
        airline_name        NVARCHAR(64) NULL,
        
        -- Update tracking
        aircraft_updated_utc DATETIME2(3) NOT NULL DEFAULT SYSUTCDATETIME(),
        
        CONSTRAINT PK_adl_flight_aircraft PRIMARY KEY CLUSTERED (flight_uid),
        CONSTRAINT FK_aircraft_core FOREIGN KEY (flight_uid) 
            REFERENCES dbo.adl_flight_core(flight_uid) ON DELETE CASCADE
    );
    
    CREATE NONCLUSTERED INDEX IX_aircraft_type ON dbo.adl_flight_aircraft (aircraft_icao);
    CREATE NONCLUSTERED INDEX IX_aircraft_weight ON dbo.adl_flight_aircraft (weight_class);
    
    PRINT 'Created table dbo.adl_flight_aircraft';
END
ELSE
BEGIN
    PRINT 'Table dbo.adl_flight_aircraft already exists - skipping';
END
GO

-- ============================================================================
-- 5. adl_flight_tmi - Traffic Management Initiative Controls
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_tmi') AND type = 'U')
BEGIN
    CREATE TABLE dbo.adl_flight_tmi (
        flight_uid          BIGINT NOT NULL,
        
        -- Control Type
        ctl_type            NVARCHAR(8) NULL,                -- GS, GDP, AFP, REROUTE, etc.
        ctl_element         NVARCHAR(8) NULL,                -- Control element ID
        
        -- Delay Information
        delay_status        NVARCHAR(16) NULL,
        delay_minutes       INT NULL,
        delay_source        NVARCHAR(16) NULL,               -- GDP, GS, WEATHER, etc.
        
        -- Controlled Times
        ctd_utc             DATETIME2(0) NULL,               -- Controlled time of departure
        cta_utc             DATETIME2(0) NULL,               -- Controlled time of arrival
        edct_utc            DATETIME2(0) NULL,               -- Expect departure clearance time
        
        -- Slot Information
        slot_time_utc       DATETIME2(0) NULL,
        slot_status         NVARCHAR(16) NULL,
        
        -- Exemption Status
        is_exempt           BIT NOT NULL DEFAULT 0,
        exempt_reason       NVARCHAR(64) NULL,
        
        -- Reroute Information
        reroute_status      NVARCHAR(16) NULL,
        reroute_id          NVARCHAR(32) NULL,
        
        -- Update tracking
        tmi_updated_utc     DATETIME2(3) NOT NULL DEFAULT SYSUTCDATETIME(),
        
        CONSTRAINT PK_adl_flight_tmi PRIMARY KEY CLUSTERED (flight_uid),
        CONSTRAINT FK_tmi_core FOREIGN KEY (flight_uid) 
            REFERENCES dbo.adl_flight_core(flight_uid) ON DELETE CASCADE
    );
    
    CREATE NONCLUSTERED INDEX IX_tmi_ctl_type ON dbo.adl_flight_tmi (ctl_type) WHERE ctl_type IS NOT NULL;
    CREATE NONCLUSTERED INDEX IX_tmi_ctd ON dbo.adl_flight_tmi (ctd_utc) WHERE ctd_utc IS NOT NULL;
    
    PRINT 'Created table dbo.adl_flight_tmi';
END
ELSE
BEGIN
    PRINT 'Table dbo.adl_flight_tmi already exists - skipping';
END
GO

PRINT '';
PRINT '=== ADL Migration 001 Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
