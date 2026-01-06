-- ============================================================================
-- ADL Normalized Schema - Migration 002: Times, Trajectory, and Changelog
-- 
-- Part of the ADL Database Redesign
-- Creates time tracking, position history, and audit trail tables
-- 
-- Run Order: 2 of 5
-- Depends on: 001_adl_core_tables.sql
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Migration 002: Times, Trajectory, and Changelog ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. adl_flight_times - TFMS Time Fields (40+ columns)
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND type = 'U')
BEGIN
    CREATE TABLE dbo.adl_flight_times (
        flight_uid          BIGINT NOT NULL,
        
        -- ═══════════════════════════════════════════════════════════════════
        -- DEPARTURE TIMES
        -- ═══════════════════════════════════════════════════════════════════
        
        -- Scheduled
        std_utc             DATETIME2(0) NULL,               -- Scheduled time of departure
        
        -- Estimated
        etd_utc             DATETIME2(0) NULL,               -- Estimated time of departure
        etd_runway_utc      DATETIME2(0) NULL,               -- Estimated runway departure
        etd_source          NVARCHAR(16) NULL,               -- Source of ETD
        
        -- Actual
        atd_utc             DATETIME2(0) NULL,               -- Actual time of departure
        atd_runway_utc      DATETIME2(0) NULL,               -- Actual runway departure (wheels up)
        
        -- Controlled
        ctd_utc             DATETIME2(0) NULL,               -- Controlled time of departure
        edct_utc            DATETIME2(0) NULL,               -- Expect departure clearance time
        
        -- Proposed
        ptd_utc             DATETIME2(0) NULL,               -- Proposed time of departure
        
        -- ═══════════════════════════════════════════════════════════════════
        -- ARRIVAL TIMES
        -- ═══════════════════════════════════════════════════════════════════
        
        -- Scheduled
        sta_utc             DATETIME2(0) NULL,               -- Scheduled time of arrival
        
        -- Estimated (multiple sources)
        eta_utc             DATETIME2(0) NULL,               -- Best estimated time of arrival
        eta_runway_utc      DATETIME2(0) NULL,               -- Estimated runway arrival
        eta_source          NVARCHAR(16) NULL,               -- Source of ETA
        
        -- TFMS-specific ETAs
        eta_tfms_utc        DATETIME2(0) NULL,               -- TFMS-computed ETA
        eta_airline_utc     DATETIME2(0) NULL,               -- Airline-reported ETA
        eta_flight_utc      DATETIME2(0) NULL,               -- Flight-reported ETA
        
        -- Actual
        ata_utc             DATETIME2(0) NULL,               -- Actual time of arrival
        ata_runway_utc      DATETIME2(0) NULL,               -- Actual runway arrival (wheels down)
        
        -- Controlled
        cta_utc             DATETIME2(0) NULL,               -- Controlled time of arrival
        
        -- ═══════════════════════════════════════════════════════════════════
        -- FIX TIMES
        -- ═══════════════════════════════════════════════════════════════════
        
        -- Departure Fix
        etd_dfix_utc        DATETIME2(0) NULL,               -- ETA at departure fix
        atd_dfix_utc        DATETIME2(0) NULL,               -- ATA at departure fix
        
        -- Arrival Fix  
        eta_afix_utc        DATETIME2(0) NULL,               -- ETA at arrival fix
        ata_afix_utc        DATETIME2(0) NULL,               -- ATA at arrival fix
        eaft_utc            DATETIME2(0) NULL,               -- Expected approach fix time
        
        -- Meter Fix
        eta_meterfix_utc    DATETIME2(0) NULL,
        sta_meterfix_utc    DATETIME2(0) NULL,
        
        -- ═══════════════════════════════════════════════════════════════════
        -- AIRSPACE TIMES
        -- ═══════════════════════════════════════════════════════════════════
        
        -- Center Entry/Exit
        center_entry_utc    DATETIME2(0) NULL,
        center_exit_utc     DATETIME2(0) NULL,
        
        -- Sector Entry/Exit
        sector_entry_utc    DATETIME2(0) NULL,
        sector_exit_utc     DATETIME2(0) NULL,
        
        -- Oceanic Entry
        oceanic_entry_utc   DATETIME2(0) NULL,
        
        -- ═══════════════════════════════════════════════════════════════════
        -- EPOCH VERSIONS (for sorting/calculations)
        -- ═══════════════════════════════════════════════════════════════════
        
        eta_epoch           BIGINT NULL,
        etd_epoch           BIGINT NULL,
        
        -- ═══════════════════════════════════════════════════════════════════
        -- BUCKET TIMES (for demand aggregation)
        -- ═══════════════════════════════════════════════════════════════════
        
        arrival_bucket_utc  DATETIME2(0) NULL,               -- Rounded to 15-min bucket
        departure_bucket_utc DATETIME2(0) NULL,
        
        -- ═══════════════════════════════════════════════════════════════════
        -- COMPUTED DURATIONS
        -- ═══════════════════════════════════════════════════════════════════
        
        ete_minutes         INT NULL,                        -- Estimated time enroute
        ate_minutes         INT NULL,                        -- Actual time enroute
        delay_minutes       INT NULL,                        -- Total delay
        
        -- Update tracking
        times_updated_utc   DATETIME2(3) NOT NULL DEFAULT SYSUTCDATETIME(),
        
        CONSTRAINT PK_adl_flight_times PRIMARY KEY CLUSTERED (flight_uid),
        CONSTRAINT FK_times_core FOREIGN KEY (flight_uid) 
            REFERENCES dbo.adl_flight_core(flight_uid) ON DELETE CASCADE
    );
    
    -- Indexes for common queries
    CREATE NONCLUSTERED INDEX IX_times_eta ON dbo.adl_flight_times (eta_utc) WHERE eta_utc IS NOT NULL;
    CREATE NONCLUSTERED INDEX IX_times_etd ON dbo.adl_flight_times (etd_utc) WHERE etd_utc IS NOT NULL;
    CREATE NONCLUSTERED INDEX IX_times_arr_bucket ON dbo.adl_flight_times (arrival_bucket_utc) WHERE arrival_bucket_utc IS NOT NULL;
    CREATE NONCLUSTERED INDEX IX_times_dep_bucket ON dbo.adl_flight_times (departure_bucket_utc) WHERE departure_bucket_utc IS NOT NULL;
    CREATE NONCLUSTERED INDEX IX_times_eta_epoch ON dbo.adl_flight_times (eta_epoch) WHERE eta_epoch IS NOT NULL;
    
    PRINT 'Created table dbo.adl_flight_times';
END
ELSE
BEGIN
    PRINT 'Table dbo.adl_flight_times already exists - skipping';
END
GO

-- ============================================================================
-- 2. adl_flight_trajectory - Position History (15-second resolution)
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_trajectory') AND type = 'U')
BEGIN
    CREATE TABLE dbo.adl_flight_trajectory (
        trajectory_id       BIGINT IDENTITY(1,1) NOT NULL,
        flight_uid          BIGINT NOT NULL,
        
        -- Timestamp
        timestamp_utc       DATETIME2(0) NOT NULL,
        
        -- Position
        lat                 DECIMAL(10,7) NOT NULL,
        lon                 DECIMAL(11,7) NOT NULL,
        
        -- Altitude & Speed
        altitude_ft         INT NULL,
        groundspeed_kts     INT NULL,
        vertical_rate_fpm   INT NULL,
        
        -- Heading
        heading_deg         SMALLINT NULL,
        track_deg           SMALLINT NULL,
        
        -- Source
        source              NVARCHAR(16) NOT NULL DEFAULT 'vatsim',
        
        CONSTRAINT PK_adl_flight_trajectory PRIMARY KEY CLUSTERED (trajectory_id),
        CONSTRAINT FK_trajectory_core FOREIGN KEY (flight_uid) 
            REFERENCES dbo.adl_flight_core(flight_uid) ON DELETE CASCADE
    );
    
    -- Indexes for common queries
    CREATE NONCLUSTERED INDEX IX_traj_flight_time ON dbo.adl_flight_trajectory (flight_uid, timestamp_utc DESC);
    CREATE NONCLUSTERED INDEX IX_traj_timestamp ON dbo.adl_flight_trajectory (timestamp_utc DESC);
    
    PRINT 'Created table dbo.adl_flight_trajectory';
END
ELSE
BEGIN
    PRINT 'Table dbo.adl_flight_trajectory already exists - skipping';
END
GO

-- Add spatial column and index
IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_trajectory') AND type = 'U')
BEGIN
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_trajectory') AND name = 'position_geo')
    BEGIN
        ALTER TABLE dbo.adl_flight_trajectory ADD position_geo GEOGRAPHY NULL;
        PRINT 'Added position_geo column to adl_flight_trajectory';
    END
    
    IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.adl_flight_trajectory') AND name = 'IX_traj_geo')
    BEGIN
        CREATE SPATIAL INDEX IX_traj_geo ON dbo.adl_flight_trajectory (position_geo);
        PRINT 'Created spatial index IX_traj_geo';
    END
END
GO

-- ============================================================================
-- 3. adl_flight_changelog - Field-Level Audit Trail
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_changelog') AND type = 'U')
BEGIN
    CREATE TABLE dbo.adl_flight_changelog (
        changelog_id        BIGINT IDENTITY(1,1) NOT NULL,
        flight_uid          BIGINT NOT NULL,
        
        -- Change metadata
        changed_utc         DATETIME2(3) NOT NULL DEFAULT SYSUTCDATETIME(),
        source_table        NVARCHAR(64) NOT NULL,           -- Which table changed
        field_name          NVARCHAR(64) NOT NULL,           -- Which field changed
        
        -- Values
        old_value           NVARCHAR(512) NULL,
        new_value           NVARCHAR(512) NULL,
        
        -- Context
        change_source       NVARCHAR(32) NULL,               -- vatsim, user, system, etc.
        snapshot_utc        DATETIME2(0) NULL,               -- VATSIM snapshot that triggered change
        
        CONSTRAINT PK_adl_flight_changelog PRIMARY KEY CLUSTERED (changelog_id),
        CONSTRAINT FK_changelog_core FOREIGN KEY (flight_uid) 
            REFERENCES dbo.adl_flight_core(flight_uid) ON DELETE CASCADE
    );
    
    -- Indexes
    CREATE NONCLUSTERED INDEX IX_changelog_flight ON dbo.adl_flight_changelog (flight_uid, changed_utc DESC);
    CREATE NONCLUSTERED INDEX IX_changelog_time ON dbo.adl_flight_changelog (changed_utc DESC);
    CREATE NONCLUSTERED INDEX IX_changelog_table_field ON dbo.adl_flight_changelog (source_table, field_name);
    
    PRINT 'Created table dbo.adl_flight_changelog';
END
ELSE
BEGIN
    PRINT 'Table dbo.adl_flight_changelog already exists - skipping';
END
GO

-- ============================================================================
-- 4. adl_flight_legs - Multi-leg Flight Associations (future use)
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_legs') AND type = 'U')
BEGIN
    CREATE TABLE dbo.adl_flight_legs (
        leg_id              BIGINT IDENTITY(1,1) NOT NULL,
        
        -- Flight references
        flight_uid          BIGINT NOT NULL,                 -- This leg's flight
        parent_flight_uid   BIGINT NULL,                     -- Parent flight (if continuation)
        next_flight_uid     BIGINT NULL,                     -- Next leg (if exists)
        
        -- Leg info
        leg_number          INT NOT NULL DEFAULT 1,
        leg_type            NVARCHAR(16) NULL,               -- CONTINUATION, DIVERSION, etc.
        
        -- Linking identifiers
        itinerary_id        NVARCHAR(64) NULL,               -- Shared ID across legs
        
        -- Timestamps
        created_utc         DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        
        CONSTRAINT PK_adl_flight_legs PRIMARY KEY CLUSTERED (leg_id),
        CONSTRAINT FK_legs_flight FOREIGN KEY (flight_uid) 
            REFERENCES dbo.adl_flight_core(flight_uid) ON DELETE CASCADE,
        INDEX IX_legs_parent (parent_flight_uid),
        INDEX IX_legs_itinerary (itinerary_id)
    );
    
    PRINT 'Created table dbo.adl_flight_legs';
END
ELSE
BEGIN
    PRINT 'Table dbo.adl_flight_legs already exists - skipping';
END
GO

PRINT '';
PRINT '=== ADL Migration 002 Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
