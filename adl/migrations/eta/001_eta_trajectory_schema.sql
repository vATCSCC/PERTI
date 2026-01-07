-- ============================================================================
-- ADL Migration 030: ETA & Trajectory System Schema
-- 
-- Part of the ETA & Trajectory Calculation System Implementation
-- 
-- This migration:
-- 1. ALTERs adl_flight_times to add OOOI and ETA calculation fields
-- 2. ALTERs adl_flight_trajectory to add tier tracking columns
-- 3. CREATEs fir_boundaries table for relevance checking
-- 4. CREATEs sector_boundaries table for boundary crossing detection
-- 5. CREATEs weather_alerts table for TCF/SIGMET storage
-- 6. CREATEs aircraft_performance_profiles table
-- 
-- Run Order: After 006_airlines_table.sql
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Migration 030: ETA & Trajectory Schema ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. ALTER adl_flight_times - Add OOOI and ETA Calculation Fields
-- ============================================================================

PRINT 'Altering adl_flight_times...';

-- OOOI Core Times
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'out_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD out_utc DATETIME2(0) NULL;
    PRINT '  Added out_utc';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'off_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD off_utc DATETIME2(0) NULL;
    PRINT '  Added off_utc';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'on_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD on_utc DATETIME2(0) NULL;
    PRINT '  Added on_utc';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'in_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD in_utc DATETIME2(0) NULL;
    PRINT '  Added in_utc';
END

-- Extended Zone Times - Departure
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'parking_left_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD parking_left_utc DATETIME2(0) NULL;
    PRINT '  Added parking_left_utc';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'taxiway_entered_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD taxiway_entered_utc DATETIME2(0) NULL;
    PRINT '  Added taxiway_entered_utc';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'hold_entered_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD hold_entered_utc DATETIME2(0) NULL;
    PRINT '  Added hold_entered_utc';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'runway_entered_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD runway_entered_utc DATETIME2(0) NULL;
    PRINT '  Added runway_entered_utc';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'takeoff_roll_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD takeoff_roll_utc DATETIME2(0) NULL;
    PRINT '  Added takeoff_roll_utc';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'rotation_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD rotation_utc DATETIME2(0) NULL;
    PRINT '  Added rotation_utc';
END

-- Extended Zone Times - Arrival
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'approach_start_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD approach_start_utc DATETIME2(0) NULL;
    PRINT '  Added approach_start_utc';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'threshold_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD threshold_utc DATETIME2(0) NULL;
    PRINT '  Added threshold_utc';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'touchdown_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD touchdown_utc DATETIME2(0) NULL;
    PRINT '  Added touchdown_utc';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'rollout_end_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD rollout_end_utc DATETIME2(0) NULL;
    PRINT '  Added rollout_end_utc';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'taxiway_arr_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD taxiway_arr_utc DATETIME2(0) NULL;
    PRINT '  Added taxiway_arr_utc';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'parking_entered_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD parking_entered_utc DATETIME2(0) NULL;
    PRINT '  Added parking_entered_utc';
END

-- ETA Calculation Components
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'eta_prefix')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD eta_prefix NCHAR(1) NULL;
    PRINT '  Added eta_prefix';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'eta_route_dist_nm')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD eta_route_dist_nm DECIMAL(10,2) NULL;
    PRINT '  Added eta_route_dist_nm';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'eta_wind_component_kts')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD eta_wind_component_kts INT NULL;
    PRINT '  Added eta_wind_component_kts';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'eta_weather_delay_min')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD eta_weather_delay_min INT NULL;
    PRINT '  Added eta_weather_delay_min';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'eta_tmi_delay_min')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD eta_tmi_delay_min INT NULL;
    PRINT '  Added eta_tmi_delay_min';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'eta_confidence')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD eta_confidence DECIMAL(3,2) NULL;
    PRINT '  Added eta_confidence';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'eta_last_calc_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD eta_last_calc_utc DATETIME2(0) NULL;
    PRINT '  Added eta_last_calc_utc';
END

-- TOD/TOC Prediction Fields
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'tod_dist_nm')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD tod_dist_nm DECIMAL(10,2) NULL;
    PRINT '  Added tod_dist_nm';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'tod_eta_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD tod_eta_utc DATETIME2(0) NULL;
    PRINT '  Added tod_eta_utc';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'toc_eta_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_times ADD toc_eta_utc DATETIME2(0) NULL;
    PRINT '  Added toc_eta_utc';
END

-- Add indexes for OOOI times
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'IX_times_out')
BEGIN
    CREATE NONCLUSTERED INDEX IX_times_out ON dbo.adl_flight_times (out_utc) WHERE out_utc IS NOT NULL;
    PRINT '  Created index IX_times_out';
END

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'IX_times_off')
BEGIN
    CREATE NONCLUSTERED INDEX IX_times_off ON dbo.adl_flight_times (off_utc) WHERE off_utc IS NOT NULL;
    PRINT '  Created index IX_times_off';
END

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'IX_times_on')
BEGIN
    CREATE NONCLUSTERED INDEX IX_times_on ON dbo.adl_flight_times (on_utc) WHERE on_utc IS NOT NULL;
    PRINT '  Created index IX_times_on';
END

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'IX_times_in')
BEGIN
    CREATE NONCLUSTERED INDEX IX_times_in ON dbo.adl_flight_times (in_utc) WHERE in_utc IS NOT NULL;
    PRINT '  Created index IX_times_in';
END

PRINT 'adl_flight_times alterations complete.';
GO

-- ============================================================================
-- 2. ALTER adl_flight_trajectory - Add Tier Tracking Columns
-- ============================================================================

PRINT 'Altering adl_flight_trajectory...';

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_trajectory') AND name = 'tier')
BEGIN
    ALTER TABLE dbo.adl_flight_trajectory ADD tier TINYINT NOT NULL DEFAULT 4;
    PRINT '  Added tier';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_trajectory') AND name = 'tier_reason')
BEGIN
    ALTER TABLE dbo.adl_flight_trajectory ADD tier_reason NVARCHAR(32) NULL;
    PRINT '  Added tier_reason';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_trajectory') AND name = 'flight_phase')
BEGIN
    ALTER TABLE dbo.adl_flight_trajectory ADD flight_phase NVARCHAR(16) NULL;
    PRINT '  Added flight_phase';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_trajectory') AND name = 'dist_to_dest_nm')
BEGIN
    ALTER TABLE dbo.adl_flight_trajectory ADD dist_to_dest_nm DECIMAL(10,2) NULL;
    PRINT '  Added dist_to_dest_nm';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_trajectory') AND name = 'dist_from_origin_nm')
BEGIN
    ALTER TABLE dbo.adl_flight_trajectory ADD dist_from_origin_nm DECIMAL(10,2) NULL;
    PRINT '  Added dist_from_origin_nm';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_trajectory') AND name = 'current_zone')
BEGIN
    ALTER TABLE dbo.adl_flight_trajectory ADD current_zone NVARCHAR(16) NULL;
    PRINT '  Added current_zone';
END

-- Rename timestamp_utc to recorded_utc if it exists (for consistency)
IF EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_trajectory') AND name = 'timestamp_utc')
   AND NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_trajectory') AND name = 'recorded_utc')
BEGIN
    EXEC sp_rename 'dbo.adl_flight_trajectory.timestamp_utc', 'recorded_utc', 'COLUMN';
    PRINT '  Renamed timestamp_utc to recorded_utc';
END

-- Add index on tier
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.adl_flight_trajectory') AND name = 'IX_traj_tier')
BEGIN
    CREATE NONCLUSTERED INDEX IX_traj_tier ON dbo.adl_flight_trajectory (tier, recorded_utc DESC);
    PRINT '  Created index IX_traj_tier';
END

PRINT 'adl_flight_trajectory alterations complete.';
GO

-- ============================================================================
-- 3. CREATE fir_boundaries - FIR/ARTCC Boundaries for Relevance Checking
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.fir_boundaries') AND type = 'U')
BEGIN
    CREATE TABLE dbo.fir_boundaries (
        fir_id              INT IDENTITY(1,1) PRIMARY KEY,
        
        -- FIR Identification
        fir_icao            NVARCHAR(4) NOT NULL,
        fir_name            NVARCHAR(64) NOT NULL,
        fir_type            NVARCHAR(16) NOT NULL,          -- DOMESTIC/OCEANIC
        
        -- Coverage Flags
        is_covered_region   BIT NOT NULL DEFAULT 0,         -- US/CA/LatAm/Caribbean
        is_us_ca_oceanic    BIT NOT NULL DEFAULT 0,         -- US/CA oceanic FIR
        
        -- Boundaries
        boundary_geo        GEOGRAPHY NULL,
        boundary_wkt        NVARCHAR(MAX) NULL,
        
        -- Bounding Box (for quick filtering)
        min_lat             DECIMAL(10,7) NULL,
        max_lat             DECIMAL(10,7) NULL,
        min_lon             DECIMAL(10,7) NULL,
        max_lon             DECIMAL(10,7) NULL,
        
        -- Metadata
        source              NVARCHAR(32) NULL,
        created_utc         DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        updated_utc         DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME()
    );
    
    CREATE NONCLUSTERED INDEX IX_fir_covered ON dbo.fir_boundaries (is_covered_region);
    CREATE NONCLUSTERED INDEX IX_fir_icao ON dbo.fir_boundaries (fir_icao);
    CREATE NONCLUSTERED INDEX IX_fir_oceanic ON dbo.fir_boundaries (is_us_ca_oceanic) WHERE is_us_ca_oceanic = 1;
    
    -- Spatial index (created after data loaded if boundary_geo populated)
    
    PRINT 'Created table dbo.fir_boundaries';
END
ELSE
BEGIN
    PRINT 'Table dbo.fir_boundaries already exists - skipping';
END
GO

-- ============================================================================
-- 4. CREATE sector_boundaries - ARTCC/TRACON/Sector Boundaries
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.sector_boundaries') AND type = 'U')
BEGIN
    CREATE TABLE dbo.sector_boundaries (
        sector_id           INT IDENTITY(1,1) PRIMARY KEY,
        
        -- Sector Identification
        sector_name         NVARCHAR(16) NOT NULL,
        artcc_id            NVARCHAR(4) NOT NULL,
        sector_type         NVARCHAR(16) NOT NULL,          -- TRACON/LOW/HIGH/SUPERHIGH/ARTCC
        
        -- Altitude Range
        altitude_low_ft     INT NOT NULL DEFAULT 0,
        altitude_high_ft    INT NOT NULL DEFAULT 60000,
        
        -- Boundaries
        boundary_geo        GEOGRAPHY NULL,
        boundary_wkt        NVARCHAR(MAX) NULL,
        
        -- Bounding Box
        min_lat             DECIMAL(10,7) NULL,
        max_lat             DECIMAL(10,7) NULL,
        min_lon             DECIMAL(10,7) NULL,
        max_lon             DECIMAL(10,7) NULL,
        
        -- Status
        is_active           BIT NOT NULL DEFAULT 1,
        
        -- Metadata
        source              NVARCHAR(32) NULL,
        created_utc         DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME()
    );
    
    CREATE NONCLUSTERED INDEX IX_sector_artcc ON dbo.sector_boundaries (artcc_id, sector_type);
    CREATE NONCLUSTERED INDEX IX_sector_type ON dbo.sector_boundaries (sector_type);
    
    PRINT 'Created table dbo.sector_boundaries';
END
ELSE
BEGIN
    PRINT 'Table dbo.sector_boundaries already exists - skipping';
END
GO

-- ============================================================================
-- 5. CREATE weather_alerts - TCF/eTCF/SIGMET Storage
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.weather_alerts') AND type = 'U')
BEGIN
    CREATE TABLE dbo.weather_alerts (
        alert_id            INT IDENTITY(1,1) PRIMARY KEY,
        
        -- Alert Identification
        alert_type          NVARCHAR(16) NOT NULL,          -- TCF/ETCF/SIGMET/AIRMET/CWA
        alert_name          NVARCHAR(64) NOT NULL,
        source              NVARCHAR(32) NOT NULL,          -- AWC/CWSU
        external_id         NVARCHAR(64) NULL,              -- Source system ID
        
        -- Boundaries
        boundary_geo        GEOGRAPHY NULL,
        boundary_wkt        NVARCHAR(MAX) NULL,
        
        -- Bounding Box
        min_lat             DECIMAL(10,7) NULL,
        max_lat             DECIMAL(10,7) NULL,
        min_lon             DECIMAL(10,7) NULL,
        max_lon             DECIMAL(10,7) NULL,
        
        -- Validity
        issued_utc          DATETIME2(0) NOT NULL,
        effective_utc       DATETIME2(0) NOT NULL,
        expiry_utc          DATETIME2(0) NOT NULL,
        
        -- Hazard Info
        hazard_type         NVARCHAR(32) NULL,              -- CONVECTIVE/TURB/ICE/IFR/MTN_OBSCN
        severity            NVARCHAR(16) NULL,              -- LIGHT/MODERATE/SEVERE/EXTREME
        altitude_low_ft     INT NULL,
        altitude_high_ft    INT NULL,
        
        -- Metadata
        raw_text            NVARCHAR(MAX) NULL,
        created_utc         DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME()
    );
    
    CREATE NONCLUSTERED INDEX IX_weather_type ON dbo.weather_alerts (alert_type, expiry_utc);
    CREATE NONCLUSTERED INDEX IX_weather_expiry ON dbo.weather_alerts (expiry_utc);
    CREATE NONCLUSTERED INDEX IX_weather_effective ON dbo.weather_alerts (effective_utc, expiry_utc);
    
    PRINT 'Created table dbo.weather_alerts';
END
ELSE
BEGIN
    PRINT 'Table dbo.weather_alerts already exists - skipping';
END
GO

-- ============================================================================
-- 6. CREATE aircraft_performance_profiles - Aircraft Performance Data
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.aircraft_performance_profiles') AND type = 'U')
BEGIN
    CREATE TABLE dbo.aircraft_performance_profiles (
        profile_id          INT IDENTITY(1,1) PRIMARY KEY,
        aircraft_icao       NVARCHAR(8) NOT NULL,
        
        -- Climb Performance
        climb_rate_fpm      INT NOT NULL DEFAULT 2000,
        climb_speed_kias    INT NOT NULL DEFAULT 280,
        climb_speed_mach    DECIMAL(3,2) NULL,
        
        -- Cruise Performance
        cruise_speed_ktas   INT NOT NULL DEFAULT 450,
        cruise_mach         DECIMAL(3,2) NULL,
        optimal_fl          INT NULL,
        
        -- Descent Performance
        descent_rate_fpm    INT NOT NULL DEFAULT 2000,
        descent_speed_kias  INT NOT NULL DEFAULT 280,
        descent_angle_deg   DECIMAL(4,2) NULL DEFAULT 3.0,
        
        -- Category (for defaults)
        weight_class        NCHAR(1) NULL,                  -- J/H/L/S
        engine_type         NVARCHAR(8) NULL,               -- JET/TURBOPROP/PISTON
        
        -- Fuel/Range
        range_nm            INT NULL,
        fuel_burn_lbs_hr    INT NULL,
        
        -- Source
        source              NVARCHAR(32) NULL,              -- BADA/ESTIMATED/USER
        created_utc         DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        
        CONSTRAINT UK_aircraft_perf_icao UNIQUE (aircraft_icao)
    );
    
    CREATE NONCLUSTERED INDEX IX_perf_weight ON dbo.aircraft_performance_profiles (weight_class);
    CREATE NONCLUSTERED INDEX IX_perf_engine ON dbo.aircraft_performance_profiles (engine_type);
    
    PRINT 'Created table dbo.aircraft_performance_profiles';
END
ELSE
BEGIN
    PRINT 'Table dbo.aircraft_performance_profiles already exists - skipping';
END
GO

-- ============================================================================
-- 7. CREATE trajectory_tier_log - Track tier changes for analysis (optional)
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.trajectory_tier_log') AND type = 'U')
BEGIN
    CREATE TABLE dbo.trajectory_tier_log (
        log_id              BIGINT IDENTITY(1,1) PRIMARY KEY,
        flight_uid          BIGINT NOT NULL,
        
        recorded_utc        DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        prev_tier           TINYINT NULL,
        new_tier            TINYINT NOT NULL,
        tier_reason         NVARCHAR(32) NULL,
        
        -- Context
        dist_to_dest_nm     DECIMAL(10,2) NULL,
        altitude_ft         INT NULL,
        groundspeed_kts     INT NULL,
        
        INDEX IX_tier_log_flight (flight_uid, recorded_utc DESC)
    );
    
    PRINT 'Created table dbo.trajectory_tier_log';
END
ELSE
BEGIN
    PRINT 'Table dbo.trajectory_tier_log already exists - skipping';
END
GO

-- ============================================================================
-- 8. Add flight_phase tracking to adl_flight_core if not exists
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_core') AND name = 'flight_phase')
BEGIN
    ALTER TABLE dbo.adl_flight_core ADD flight_phase NVARCHAR(16) NULL;
    PRINT 'Added flight_phase to adl_flight_core';
END
GO

-- ============================================================================
-- 9. Add trajectory tracking fields to adl_flight_core
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_core') AND name = 'last_trajectory_tier')
BEGIN
    ALTER TABLE dbo.adl_flight_core ADD last_trajectory_tier TINYINT NULL;
    PRINT 'Added last_trajectory_tier to adl_flight_core';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_core') AND name = 'last_trajectory_utc')
BEGIN
    ALTER TABLE dbo.adl_flight_core ADD last_trajectory_utc DATETIME2(0) NULL;
    PRINT 'Added last_trajectory_utc to adl_flight_core';
END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_core') AND name = 'is_relevant')
BEGIN
    ALTER TABLE dbo.adl_flight_core ADD is_relevant BIT NULL;
    PRINT 'Added is_relevant to adl_flight_core';
END
GO

PRINT '';
PRINT '=== ADL Migration 030 Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
