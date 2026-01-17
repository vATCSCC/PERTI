-- ============================================================================
-- VATSIM_REF Schema - Migration 001: Reference Data Tables
--
-- Database: VATSIM_REF (Azure SQL Basic tier - $5/mo)
-- Purpose: Authoritative source for static navigation reference data
--
-- These tables are synced TO VATSIM_ADL nightly or on AIRAC update.
-- sp_ParseRoute and other procedures use the VATSIM_ADL local cache.
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== VATSIM_REF Migration 001: Reference Data Tables ==='
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. nav_fixes - Navigation Waypoints (~270K records from FAA/EUROCONTROL)
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.nav_fixes') AND type = 'U')
BEGIN
    CREATE TABLE dbo.nav_fixes (
        fix_id              INT IDENTITY(1,1) NOT NULL,
        fix_name            NVARCHAR(16) NOT NULL,
        fix_type            NVARCHAR(16) NULL,               -- WAYPOINT, VOR, NDB, AIRPORT, DME, TACAN

        -- Position
        lat                 DECIMAL(10,7) NOT NULL,
        lon                 DECIMAL(11,7) NOT NULL,

        -- Disambiguation
        artcc_id            NVARCHAR(4) NULL,                -- Owning ARTCC
        state_code          NVARCHAR(4) NULL,
        country_code        NVARCHAR(4) NULL,

        -- Additional info
        freq_mhz            DECIMAL(7,3) NULL,               -- For VORs/NDBs
        mag_var             DECIMAL(5,2) NULL,
        elevation_ft        INT NULL,

        -- Metadata
        source              NVARCHAR(32) NULL,               -- FAA, EUROCONTROL, etc.
        effective_date      DATE NULL,

        CONSTRAINT PK_nav_fixes PRIMARY KEY CLUSTERED (fix_id)
    );

    -- Indexes
    CREATE NONCLUSTERED INDEX IX_fix_name ON dbo.nav_fixes (fix_name);
    CREATE NONCLUSTERED INDEX IX_fix_name_type ON dbo.nav_fixes (fix_name, fix_type);
    CREATE NONCLUSTERED INDEX IX_fix_artcc ON dbo.nav_fixes (artcc_id) WHERE artcc_id IS NOT NULL;

    PRINT 'Created table dbo.nav_fixes';
END
ELSE
BEGIN
    PRINT 'Table dbo.nav_fixes already exists - skipping';
END
GO

-- Add spatial column and index (Note: Basic tier supports GEOGRAPHY)
IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.nav_fixes') AND type = 'U')
BEGIN
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.nav_fixes') AND name = 'position_geo')
    BEGIN
        ALTER TABLE dbo.nav_fixes ADD position_geo GEOGRAPHY NULL;
        PRINT 'Added position_geo column to nav_fixes';
    END

    IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.nav_fixes') AND name = 'IX_fix_geo')
    BEGIN
        CREATE SPATIAL INDEX IX_fix_geo ON dbo.nav_fixes (position_geo);
        PRINT 'Created spatial index IX_fix_geo';
    END
END
GO

-- ============================================================================
-- 2. airways - Airway Definitions (~1,200 records)
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.airways') AND type = 'U')
BEGIN
    CREATE TABLE dbo.airways (
        airway_id           INT IDENTITY(1,1) NOT NULL,
        airway_name         NVARCHAR(8) NOT NULL,            -- J60, V1, Q100, etc.
        airway_type         NVARCHAR(16) NULL,               -- JET, VICTOR, RNAV, LOW, HIGH

        -- Fix sequence (space-delimited list)
        fix_sequence        NVARCHAR(MAX) NOT NULL,
        fix_count           INT NULL,

        -- Boundaries
        start_fix           NVARCHAR(16) NULL,
        end_fix             NVARCHAR(16) NULL,

        -- Altitude limits
        min_alt_ft          INT NULL,
        max_alt_ft          INT NULL,

        -- Direction (if one-way)
        direction           NVARCHAR(8) NULL,                -- EAST, WEST, NORTH, SOUTH, BOTH

        -- Metadata
        source              NVARCHAR(32) NULL,
        effective_date      DATE NULL,

        CONSTRAINT PK_airways PRIMARY KEY CLUSTERED (airway_id)
    );

    -- Indexes
    CREATE UNIQUE NONCLUSTERED INDEX IX_airway_name ON dbo.airways (airway_name);
    CREATE NONCLUSTERED INDEX IX_airway_type ON dbo.airways (airway_type);

    PRINT 'Created table dbo.airways';
END
ELSE
BEGIN
    PRINT 'Table dbo.airways already exists - skipping';
END
GO

-- ============================================================================
-- 3. airway_segments - Individual Airway Segments with Geometry (~50K records)
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.airway_segments') AND type = 'U')
BEGIN
    CREATE TABLE dbo.airway_segments (
        segment_id          INT IDENTITY(1,1) NOT NULL,
        airway_id           INT NOT NULL,
        airway_name         NVARCHAR(8) NOT NULL,

        -- Segment endpoints
        sequence_num        INT NOT NULL,
        from_fix            NVARCHAR(16) NOT NULL,
        to_fix              NVARCHAR(16) NOT NULL,

        -- Positions
        from_lat            DECIMAL(10,7) NOT NULL,
        from_lon            DECIMAL(11,7) NOT NULL,
        to_lat              DECIMAL(10,7) NOT NULL,
        to_lon              DECIMAL(11,7) NOT NULL,

        -- Segment properties
        distance_nm         DECIMAL(8,2) NULL,
        course_deg          SMALLINT NULL,

        -- Altitude for this segment
        min_alt_ft          INT NULL,
        max_alt_ft          INT NULL,

        CONSTRAINT PK_airway_segments PRIMARY KEY CLUSTERED (segment_id),
        CONSTRAINT FK_segment_airway FOREIGN KEY (airway_id)
            REFERENCES dbo.airways(airway_id) ON DELETE CASCADE
    );

    -- Indexes
    CREATE NONCLUSTERED INDEX IX_segment_airway ON dbo.airway_segments (airway_name);
    CREATE NONCLUSTERED INDEX IX_segment_from ON dbo.airway_segments (from_fix);
    CREATE NONCLUSTERED INDEX IX_segment_to ON dbo.airway_segments (to_fix);

    PRINT 'Created table dbo.airway_segments';
END
ELSE
BEGIN
    PRINT 'Table dbo.airway_segments already exists - skipping';
END
GO

-- Add spatial column for segment geometry
IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.airway_segments') AND type = 'U')
BEGIN
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.airway_segments') AND name = 'segment_geo')
    BEGIN
        ALTER TABLE dbo.airway_segments ADD segment_geo GEOGRAPHY NULL;
        PRINT 'Added segment_geo column to airway_segments';
    END

    IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.airway_segments') AND name = 'IX_segment_geo')
    BEGIN
        CREATE SPATIAL INDEX IX_segment_geo ON dbo.airway_segments (segment_geo);
        PRINT 'Created spatial index IX_segment_geo';
    END
END
GO

-- ============================================================================
-- 4. coded_departure_routes - CDR Expansions (~2,500 records)
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.coded_departure_routes') AND type = 'U')
BEGIN
    CREATE TABLE dbo.coded_departure_routes (
        cdr_id              INT IDENTITY(1,1) NOT NULL,
        cdr_code            NVARCHAR(16) NOT NULL,           -- JFKMIA1, ORDLAX2, etc.

        -- Route
        full_route          NVARCHAR(MAX) NOT NULL,

        -- Endpoints
        origin_icao         CHAR(4) NULL,
        dest_icao           CHAR(4) NULL,

        -- Applicability
        direction           NVARCHAR(8) NULL,                -- NORTH, SOUTH, EAST, WEST
        altitude_min_ft     INT NULL,
        altitude_max_ft     INT NULL,

        -- Status
        is_active           BIT NOT NULL DEFAULT 1,

        -- Metadata
        source              NVARCHAR(32) NULL,
        effective_date      DATE NULL,

        CONSTRAINT PK_cdr PRIMARY KEY CLUSTERED (cdr_id)
    );

    -- Indexes
    CREATE UNIQUE NONCLUSTERED INDEX IX_cdr_code ON dbo.coded_departure_routes (cdr_code);
    CREATE NONCLUSTERED INDEX IX_cdr_origin_dest ON dbo.coded_departure_routes (origin_icao, dest_icao);

    PRINT 'Created table dbo.coded_departure_routes';
END
ELSE
BEGIN
    PRINT 'Table dbo.coded_departure_routes already exists - skipping';
END
GO

-- ============================================================================
-- 5. playbook_routes - Playbook Route Expansions (~3,000 records)
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.playbook_routes') AND type = 'U')
BEGIN
    CREATE TABLE dbo.playbook_routes (
        playbook_id         INT IDENTITY(1,1) NOT NULL,
        play_name           NVARCHAR(32) NOT NULL,           -- BURNN1_NORTH, etc.

        -- Route
        full_route          NVARCHAR(MAX) NOT NULL,

        -- Applicability filters
        origin_airports     NVARCHAR(256) NULL,              -- Comma-separated ICAO codes
        origin_tracons      NVARCHAR(128) NULL,
        origin_artccs       NVARCHAR(64) NULL,
        dest_airports       NVARCHAR(256) NULL,
        dest_tracons        NVARCHAR(128) NULL,
        dest_artccs         NVARCHAR(64) NULL,

        -- Constraints
        altitude_min_ft     INT NULL,
        altitude_max_ft     INT NULL,

        -- Status
        is_active           BIT NOT NULL DEFAULT 1,

        -- Metadata
        source              NVARCHAR(32) NULL,
        effective_date      DATE NULL,

        CONSTRAINT PK_playbook PRIMARY KEY CLUSTERED (playbook_id)
    );

    -- Indexes
    CREATE NONCLUSTERED INDEX IX_playbook_name ON dbo.playbook_routes (play_name);

    PRINT 'Created table dbo.playbook_routes';
END
ELSE
BEGIN
    PRINT 'Table dbo.playbook_routes already exists - skipping';
END
GO

-- ============================================================================
-- 6. area_centers - ARTCC/TRACON Pseudo-Fixes (~200 records)
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.area_centers') AND type = 'U')
BEGIN
    CREATE TABLE dbo.area_centers (
        center_id           INT IDENTITY(1,1) NOT NULL,
        center_code         NVARCHAR(8) NOT NULL,            -- ZNY, N90, A80, etc.
        center_type         NVARCHAR(16) NOT NULL,           -- ARTCC, TRACON, ATCT, FSS
        center_name         NVARCHAR(64) NULL,

        -- Position (center point)
        lat                 DECIMAL(10,7) NOT NULL,
        lon                 DECIMAL(11,7) NOT NULL,

        -- Hierarchy
        parent_artcc        NVARCHAR(4) NULL,

        CONSTRAINT PK_area_centers PRIMARY KEY CLUSTERED (center_id),
        CONSTRAINT UK_center_code UNIQUE NONCLUSTERED (center_code)
    );

    PRINT 'Created table dbo.area_centers';
END
ELSE
BEGIN
    PRINT 'Table dbo.area_centers already exists - skipping';
END
GO

-- Add spatial column
IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.area_centers') AND type = 'U')
BEGIN
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.area_centers') AND name = 'position_geo')
    BEGIN
        ALTER TABLE dbo.area_centers ADD position_geo GEOGRAPHY NULL;
        PRINT 'Added position_geo column to area_centers';
    END
END
GO

-- ============================================================================
-- 7. nav_procedures - DP/STAR Definitions (~10,000 records)
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.nav_procedures') AND type = 'U')
BEGIN
    CREATE TABLE dbo.nav_procedures (
        procedure_id        INT IDENTITY(1,1) NOT NULL,
        procedure_type      NVARCHAR(8) NOT NULL,            -- DP, STAR, APPROACH

        -- Identification
        airport_icao        CHAR(4) NOT NULL,
        procedure_name      NVARCHAR(32) NOT NULL,           -- RNAV name
        computer_code       NVARCHAR(16) NULL,               -- Computer code (MERIT3, CAMRN4, etc.)

        -- Transitions
        transition_name     NVARCHAR(16) NULL,               -- Transition identifier

        -- Route
        full_route          NVARCHAR(MAX) NULL,              -- Fix sequence

        -- Runway applicability
        runways             NVARCHAR(64) NULL,               -- Comma-separated runway list

        -- Status
        is_active           BIT NOT NULL DEFAULT 1,

        -- Metadata
        source              NVARCHAR(32) NULL,
        effective_date      DATE NULL,

        CONSTRAINT PK_nav_procedures PRIMARY KEY CLUSTERED (procedure_id)
    );

    -- Indexes
    CREATE NONCLUSTERED INDEX IX_proc_airport ON dbo.nav_procedures (airport_icao);
    CREATE NONCLUSTERED INDEX IX_proc_name ON dbo.nav_procedures (procedure_name);
    CREATE NONCLUSTERED INDEX IX_proc_code ON dbo.nav_procedures (computer_code) WHERE computer_code IS NOT NULL;
    CREATE NONCLUSTERED INDEX IX_proc_type_airport ON dbo.nav_procedures (procedure_type, airport_icao);

    PRINT 'Created table dbo.nav_procedures';
END
ELSE
BEGIN
    PRINT 'Table dbo.nav_procedures already exists - skipping';
END
GO

-- ============================================================================
-- 8. oceanic_fir_bounds - Oceanic FIR Bounding Boxes (~50 records)
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.oceanic_fir_bounds') AND type = 'U')
BEGIN
    CREATE TABLE dbo.oceanic_fir_bounds (
        fir_id              INT IDENTITY(1,1) NOT NULL,
        fir_code            NVARCHAR(8) NOT NULL,            -- ZNY, CZQX, ZAK, etc.
        fir_name            NVARCHAR(64) NULL,
        fir_type            NVARCHAR(16) NOT NULL,           -- US_OCEANIC, CA_OCEANIC, OTHER

        -- Bounding box
        min_lat             DECIMAL(10,7) NOT NULL,
        max_lat             DECIMAL(10,7) NOT NULL,
        min_lon             DECIMAL(11,7) NOT NULL,
        max_lon             DECIMAL(11,7) NOT NULL,

        -- Parse tier effect
        keeps_tier_1        BIT NOT NULL DEFAULT 0,          -- If 1, keeps flights in Tier 1 instead of Tier 4

        CONSTRAINT PK_oceanic_fir PRIMARY KEY CLUSTERED (fir_id)
    );

    PRINT 'Created table dbo.oceanic_fir_bounds';
END
ELSE
BEGIN
    PRINT 'Table dbo.oceanic_fir_bounds already exists - skipping';
END
GO

-- ============================================================================
-- 9. ref_sync_log - Track synchronization to VATSIM_ADL
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.ref_sync_log') AND type = 'U')
BEGIN
    CREATE TABLE dbo.ref_sync_log (
        sync_id             INT IDENTITY(1,1) NOT NULL,
        sync_timestamp      DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
        table_name          NVARCHAR(64) NOT NULL,
        rows_synced         INT NOT NULL,
        sync_direction      NVARCHAR(16) NOT NULL,           -- TO_ADL, FROM_SOURCE
        sync_status         NVARCHAR(16) NOT NULL,           -- SUCCESS, FAILED, PARTIAL
        error_message       NVARCHAR(MAX) NULL,
        duration_ms         INT NULL,

        CONSTRAINT PK_ref_sync_log PRIMARY KEY CLUSTERED (sync_id)
    );

    CREATE NONCLUSTERED INDEX IX_sync_timestamp ON dbo.ref_sync_log (sync_timestamp DESC);

    PRINT 'Created table dbo.ref_sync_log';
END
ELSE
BEGIN
    PRINT 'Table dbo.ref_sync_log already exists - skipping';
END
GO

PRINT '';
PRINT '=== VATSIM_REF Migration 001 Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '';
PRINT 'Next steps:';
PRINT '1. Run 002_initial_data_load.sql to populate from VATSIM_ADL';
PRINT '2. Configure sync job to run nightly or on AIRAC update';
GO
