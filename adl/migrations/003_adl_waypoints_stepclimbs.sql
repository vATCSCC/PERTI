-- ============================================================================
-- ADL Normalized Schema - Migration 003: Waypoints, Step Climbs, Parse Queue
-- 
-- Part of the ADL Database Redesign
-- Creates GIS route parsing support tables
-- 
-- Run Order: 3 of 5
-- Depends on: 001_adl_core_tables.sql
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Migration 003: Waypoints, Step Climbs, Parse Queue ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. adl_flight_waypoints - Parsed Route Waypoints
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_waypoints') AND type = 'U')
BEGIN
    CREATE TABLE dbo.adl_flight_waypoints (
        waypoint_id         BIGINT IDENTITY(1,1) NOT NULL,
        flight_uid          BIGINT NOT NULL,
        
        -- Waypoint sequence
        sequence_num        INT NOT NULL,
        
        -- Waypoint data
        fix_name            NVARCHAR(64) NOT NULL,
        lat                 DECIMAL(10,7) NOT NULL,
        lon                 DECIMAL(11,7) NOT NULL,
        
        -- Waypoint type
        fix_type            NVARCHAR(16) NULL,               -- WAYPOINT, AIRPORT, VOR, NDB, LATLON, NAVAID
        source              NVARCHAR(32) NULL,               -- ROUTE, SID, STAR, AIRWAY, COORD
        on_airway           NVARCHAR(8) NULL,                -- Airway identifier if on airway
        
        -- Altitude/Speed at this waypoint (from step climbs)
        planned_alt_ft      INT NULL,
        planned_speed_kts   INT NULL,
        planned_speed_mach  DECIMAL(4,3) NULL,
        
        -- Special flags
        is_step_climb_point BIT NOT NULL DEFAULT 0,
        is_toc              BIT NOT NULL DEFAULT 0,          -- Top of climb
        is_tod              BIT NOT NULL DEFAULT 0,          -- Top of descent
        is_constraint       BIT NOT NULL DEFAULT 0,
        constraint_type     NVARCHAR(16) NULL,               -- AT, AT_OR_ABOVE, AT_OR_BELOW, BETWEEN
        
        -- Timestamps (computed)
        eta_utc             DATETIME2(0) NULL,
        ata_utc             DATETIME2(0) NULL,
        
        CONSTRAINT PK_waypoints PRIMARY KEY CLUSTERED (waypoint_id),
        CONSTRAINT FK_waypoints_core FOREIGN KEY (flight_uid) 
            REFERENCES dbo.adl_flight_core(flight_uid) ON DELETE CASCADE
    );
    
    -- Indexes
    CREATE NONCLUSTERED INDEX IX_waypoint_flight ON dbo.adl_flight_waypoints (flight_uid, sequence_num);
    CREATE NONCLUSTERED INDEX IX_waypoint_fix ON dbo.adl_flight_waypoints (fix_name);
    CREATE NONCLUSTERED INDEX IX_waypoint_airway ON dbo.adl_flight_waypoints (on_airway) WHERE on_airway IS NOT NULL;
    CREATE NONCLUSTERED INDEX IX_waypoint_stepclimb ON dbo.adl_flight_waypoints (flight_uid) WHERE is_step_climb_point = 1;
    
    PRINT 'Created table dbo.adl_flight_waypoints';
END
ELSE
BEGIN
    PRINT 'Table dbo.adl_flight_waypoints already exists - skipping';
END
GO

-- Add spatial column and index
IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_waypoints') AND type = 'U')
BEGIN
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_waypoints') AND name = 'position_geo')
    BEGIN
        ALTER TABLE dbo.adl_flight_waypoints ADD position_geo GEOGRAPHY NULL;
        PRINT 'Added position_geo column to adl_flight_waypoints';
    END
    
    IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.adl_flight_waypoints') AND name = 'IX_waypoint_geo')
    BEGIN
        CREATE SPATIAL INDEX IX_waypoint_geo ON dbo.adl_flight_waypoints (position_geo);
        PRINT 'Created spatial index IX_waypoint_geo';
    END
END
GO

-- ============================================================================
-- 2. adl_flight_stepclimbs - Step Climb Detail Records
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_stepclimbs') AND type = 'U')
BEGIN
    CREATE TABLE dbo.adl_flight_stepclimbs (
        stepclimb_id        BIGINT IDENTITY(1,1) NOT NULL,
        flight_uid          BIGINT NOT NULL,
        
        -- Step sequence
        step_sequence       INT NOT NULL,
        
        -- Waypoint reference (if tied to specific waypoint)
        waypoint_fix        NVARCHAR(64) NULL,
        waypoint_seq        INT NULL,
        
        -- Altitude
        altitude_ft         INT NOT NULL,
        flight_level        AS (altitude_ft / 100) PERSISTED,
        
        -- Speed at this step
        speed_kts           INT NULL,
        speed_mach          DECIMAL(4,3) NULL,
        speed_type          NVARCHAR(8) NULL,                -- TAS, MACH, IAS
        
        -- Distance/Time from departure (if known)
        dist_from_dep_nm    DECIMAL(8,2) NULL,
        time_from_dep_min   INT NULL,
        
        -- Source
        source              NVARCHAR(16) NULL,               -- ROUTE, REMARKS, COMPUTED
        raw_text            NVARCHAR(128) NULL,              -- Original text that was parsed
        
        CONSTRAINT PK_stepclimbs PRIMARY KEY CLUSTERED (stepclimb_id),
        CONSTRAINT FK_stepclimbs_core FOREIGN KEY (flight_uid) 
            REFERENCES dbo.adl_flight_core(flight_uid) ON DELETE CASCADE
    );
    
    -- Indexes
    CREATE NONCLUSTERED INDEX IX_stepclimb_flight ON dbo.adl_flight_stepclimbs (flight_uid, step_sequence);
    
    PRINT 'Created table dbo.adl_flight_stepclimbs';
END
ELSE
BEGIN
    PRINT 'Table dbo.adl_flight_stepclimbs already exists - skipping';
END
GO

-- ============================================================================
-- 3. adl_parse_queue - Tiered Route Parsing Queue
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_parse_queue') AND type = 'U')
BEGIN
    CREATE TABLE dbo.adl_parse_queue (
        queue_id            BIGINT IDENTITY(1,1) NOT NULL,
        flight_uid          BIGINT NOT NULL,
        parse_tier          TINYINT NOT NULL,                -- 0-4
        
        -- Scheduling
        queued_utc          DATETIME2(3) NOT NULL DEFAULT SYSUTCDATETIME(),
        next_eligible_utc   DATETIME2(3) NOT NULL,           -- When can be processed
        
        -- Status
        status              NVARCHAR(16) NOT NULL DEFAULT 'PENDING',  -- PENDING, PROCESSING, COMPLETE, FAILED
        attempts            INT NOT NULL DEFAULT 0,
        
        -- Processing timestamps
        started_utc         DATETIME2(3) NULL,
        completed_utc       DATETIME2(3) NULL,
        
        -- Error handling
        error_message       NVARCHAR(512) NULL,
        
        -- Route hash (to detect if route changed while queued)
        route_hash          BINARY(32) NULL,
        
        CONSTRAINT PK_parse_queue PRIMARY KEY CLUSTERED (queue_id)
    );
    
    -- Indexes for queue processing
    CREATE NONCLUSTERED INDEX IX_queue_pending ON dbo.adl_parse_queue (parse_tier, next_eligible_utc) 
        WHERE status = 'PENDING';
    CREATE NONCLUSTERED INDEX IX_queue_flight ON dbo.adl_parse_queue (flight_uid);
    CREATE NONCLUSTERED INDEX IX_queue_status ON dbo.adl_parse_queue (status, queued_utc);
    
    PRINT 'Created table dbo.adl_parse_queue';
END
ELSE
BEGIN
    PRINT 'Table dbo.adl_parse_queue already exists - skipping';
END
GO

-- ============================================================================
-- 4. adl_refresh_perf - Refresh Performance Tracking
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_refresh_perf') AND type = 'U')
BEGIN
    CREATE TABLE dbo.adl_refresh_perf (
        perf_id             BIGINT IDENTITY(1,1) NOT NULL,
        
        -- Timing
        proc_start_utc      DATETIME2(3) NOT NULL,
        proc_end_utc        DATETIME2(3) NULL,
        total_ms            INT NULL,
        
        -- Stage timings
        parse_json_ms       INT NULL,
        merge_core_ms       INT NULL,
        merge_position_ms   INT NULL,
        merge_plan_ms       INT NULL,
        merge_times_ms      INT NULL,
        merge_tmi_ms        INT NULL,
        merge_aircraft_ms   INT NULL,
        insert_trajectory_ms INT NULL,
        insert_changelog_ms INT NULL,
        queue_parse_ms      INT NULL,
        process_tier0_ms    INT NULL,
        
        -- Counts
        flights_processed   INT NULL,
        flights_new         INT NULL,
        flights_updated     INT NULL,
        flights_removed     INT NULL,
        tier0_parsed        INT NULL,
        
        -- Snapshot info
        vatsim_snapshot_utc DATETIME2(0) NULL,
        
        -- Status
        status              NVARCHAR(16) NOT NULL DEFAULT 'RUNNING',  -- RUNNING, SUCCESS, FAILED
        error_message       NVARCHAR(512) NULL,
        
        CONSTRAINT PK_refresh_perf PRIMARY KEY CLUSTERED (perf_id)
    );
    
    -- Index for performance queries
    CREATE NONCLUSTERED INDEX IX_perf_time ON dbo.adl_refresh_perf (proc_start_utc DESC);
    
    PRINT 'Created table dbo.adl_refresh_perf';
END
ELSE
BEGIN
    PRINT 'Table dbo.adl_refresh_perf already exists - skipping';
END
GO

PRINT '';
PRINT '=== ADL Migration 003 Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
