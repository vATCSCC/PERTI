-- ============================================================================
-- DEPLOY_ETA_TRAJECTORY_SYSTEM.sql
-- 
-- Master deployment script for ETA & Trajectory Calculation System
-- 
-- Run this script against the VATSIM_ADL database to deploy all components
-- 
-- Components deployed:
-- 1. Schema migrations (new tables, altered columns)
-- 2. Seed data (FIR boundaries, aircraft performance profiles)
-- 3. Functions (tier calculation, relevance check, performance lookup)
-- 4. Stored procedures (ETA calculation, trajectory logging, batch processing)
-- 
-- Prerequisites:
-- - ADL normalized schema (migrations 001-006) must be deployed
-- - apts table with airport data
-- 
-- Estimated deployment time: < 30 seconds
-- ============================================================================

SET NOCOUNT ON;
GO

PRINT '══════════════════════════════════════════════════════════════════════════';
PRINT '  PERTI ETA & Trajectory Calculation System Deployment';
PRINT '  Version 1.0 - ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '══════════════════════════════════════════════════════════════════════════';
PRINT '';
GO

-- ============================================================================
-- Pre-flight checks
-- ============================================================================

PRINT '▶ Running pre-flight checks...';

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_core') AND type = 'U')
BEGIN
    RAISERROR('ERROR: adl_flight_core table not found. Deploy ADL normalized schema first.', 16, 1);
    RETURN;
END

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_position') AND type = 'U')
BEGIN
    RAISERROR('ERROR: adl_flight_position table not found. Deploy ADL normalized schema first.', 16, 1);
    RETURN;
END

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND type = 'U')
BEGIN
    RAISERROR('ERROR: adl_flight_times table not found. Deploy ADL normalized schema first.', 16, 1);
    RETURN;
END

PRINT '  ✓ Required tables exist';
GO

-- ============================================================================
-- Step 1: Schema Migration (030)
-- ============================================================================

PRINT '';
PRINT '▶ Step 1: Applying schema migration...';

-- Run 030_eta_trajectory_schema.sql inline
-- (In production, use :r command or SQLCMD mode)

-- OOOI Core Times
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'out_utc')
    ALTER TABLE dbo.adl_flight_times ADD out_utc DATETIME2(0) NULL;

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'off_utc')
    ALTER TABLE dbo.adl_flight_times ADD off_utc DATETIME2(0) NULL;

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'on_utc')
    ALTER TABLE dbo.adl_flight_times ADD on_utc DATETIME2(0) NULL;

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'in_utc')
    ALTER TABLE dbo.adl_flight_times ADD in_utc DATETIME2(0) NULL;

-- ETA Calculation Fields
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'eta_prefix')
    ALTER TABLE dbo.adl_flight_times ADD eta_prefix NCHAR(1) NULL;

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'eta_route_dist_nm')
    ALTER TABLE dbo.adl_flight_times ADD eta_route_dist_nm DECIMAL(10,2) NULL;

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'eta_tmi_delay_min')
    ALTER TABLE dbo.adl_flight_times ADD eta_tmi_delay_min INT NULL;

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'eta_confidence')
    ALTER TABLE dbo.adl_flight_times ADD eta_confidence DECIMAL(3,2) NULL;

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'eta_last_calc_utc')
    ALTER TABLE dbo.adl_flight_times ADD eta_last_calc_utc DATETIME2(0) NULL;

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'tod_dist_nm')
    ALTER TABLE dbo.adl_flight_times ADD tod_dist_nm DECIMAL(10,2) NULL;

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'tod_eta_utc')
    ALTER TABLE dbo.adl_flight_times ADD tod_eta_utc DATETIME2(0) NULL;

-- Trajectory columns
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_trajectory') AND name = 'tier')
    ALTER TABLE dbo.adl_flight_trajectory ADD tier TINYINT NOT NULL DEFAULT 4;

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_trajectory') AND name = 'tier_reason')
    ALTER TABLE dbo.adl_flight_trajectory ADD tier_reason NVARCHAR(32) NULL;

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_trajectory') AND name = 'flight_phase')
    ALTER TABLE dbo.adl_flight_trajectory ADD flight_phase NVARCHAR(16) NULL;

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_trajectory') AND name = 'dist_to_dest_nm')
    ALTER TABLE dbo.adl_flight_trajectory ADD dist_to_dest_nm DECIMAL(10,2) NULL;

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_trajectory') AND name = 'dist_from_origin_nm')
    ALTER TABLE dbo.adl_flight_trajectory ADD dist_from_origin_nm DECIMAL(10,2) NULL;

-- Core tracking columns
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_core') AND name = 'last_trajectory_tier')
    ALTER TABLE dbo.adl_flight_core ADD last_trajectory_tier TINYINT NULL;

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_core') AND name = 'last_trajectory_utc')
    ALTER TABLE dbo.adl_flight_core ADD last_trajectory_utc DATETIME2(0) NULL;

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_core') AND name = 'is_relevant')
    ALTER TABLE dbo.adl_flight_core ADD is_relevant BIT NULL;

-- Create new tables
IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.fir_boundaries') AND type = 'U')
BEGIN
    CREATE TABLE dbo.fir_boundaries (
        fir_id INT IDENTITY(1,1) PRIMARY KEY,
        fir_icao NVARCHAR(4) NOT NULL,
        fir_name NVARCHAR(64) NOT NULL,
        fir_type NVARCHAR(16) NOT NULL,
        is_covered_region BIT NOT NULL DEFAULT 0,
        is_us_ca_oceanic BIT NOT NULL DEFAULT 0,
        boundary_geo GEOGRAPHY NULL,
        source NVARCHAR(32) NULL,
        created_utc DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME()
    );
    CREATE NONCLUSTERED INDEX IX_fir_covered ON dbo.fir_boundaries (is_covered_region);
END

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.aircraft_performance_profiles') AND type = 'U')
BEGIN
    CREATE TABLE dbo.aircraft_performance_profiles (
        profile_id INT IDENTITY(1,1) PRIMARY KEY,
        aircraft_icao NVARCHAR(8) NOT NULL,
        climb_rate_fpm INT NOT NULL DEFAULT 2000,
        climb_speed_kias INT NOT NULL DEFAULT 280,
        climb_speed_mach DECIMAL(3,2) NULL,
        cruise_speed_ktas INT NOT NULL DEFAULT 450,
        cruise_mach DECIMAL(3,2) NULL,
        optimal_fl INT NULL,
        descent_rate_fpm INT NOT NULL DEFAULT 2000,
        descent_speed_kias INT NOT NULL DEFAULT 280,
        weight_class NCHAR(1) NULL,
        engine_type NVARCHAR(8) NULL,
        source NVARCHAR(32) NULL,
        created_utc DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        CONSTRAINT UK_aircraft_perf_icao UNIQUE (aircraft_icao)
    );
END

PRINT '  ✓ Schema migration complete';
GO

-- ============================================================================
-- Step 2: Seed Data
-- ============================================================================

PRINT '';
PRINT '▶ Step 2: Seeding reference data...';

-- Seed FIR boundaries (abbreviated - full list in 031_eta_trajectory_seed_data.sql)
DELETE FROM dbo.fir_boundaries WHERE source = 'SEED';

INSERT INTO dbo.fir_boundaries (fir_icao, fir_name, fir_type, is_covered_region, is_us_ca_oceanic, source) VALUES
    ('KZAB', 'Albuquerque', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZAU', 'Chicago', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZBW', 'Boston', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZDC', 'Washington', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZDV', 'Denver', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZFW', 'Fort Worth', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZHU', 'Houston', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZID', 'Indianapolis', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZJX', 'Jacksonville', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZKC', 'Kansas City', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZLA', 'Los Angeles', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZLC', 'Salt Lake', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZMA', 'Miami', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZME', 'Memphis', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZMP', 'Minneapolis', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZNY', 'New York', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZOA', 'Oakland', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZOB', 'Cleveland', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZSE', 'Seattle', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZTL', 'Atlanta', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZAK', 'Oakland Oceanic', 'OCEANIC', 1, 1, 'SEED'),
    ('CZEG', 'Edmonton', 'DOMESTIC', 1, 0, 'SEED'),
    ('CZUL', 'Montreal', 'DOMESTIC', 1, 0, 'SEED'),
    ('CZWG', 'Winnipeg', 'DOMESTIC', 1, 0, 'SEED'),
    ('CZVR', 'Vancouver', 'DOMESTIC', 1, 0, 'SEED'),
    ('CZYZ', 'Toronto', 'DOMESTIC', 1, 0, 'SEED'),
    ('CZQX', 'Gander Oceanic', 'OCEANIC', 1, 1, 'SEED');

PRINT '  ✓ Seeded ' + CAST(@@ROWCOUNT AS VARCHAR) + ' FIR boundaries';

-- Seed aircraft performance (abbreviated)
DELETE FROM dbo.aircraft_performance_profiles WHERE source = 'SEED';

INSERT INTO dbo.aircraft_performance_profiles 
    (aircraft_icao, climb_rate_fpm, climb_speed_kias, climb_speed_mach, cruise_speed_ktas, cruise_mach, descent_rate_fpm, descent_speed_kias, weight_class, engine_type, source) 
VALUES
    ('_DEF_JH', 2500, 290, 0.84, 490, 0.85, 2500, 290, 'H', 'JET', 'SEED'),
    ('_DEF_JL', 2200, 280, 0.78, 460, 0.80, 2200, 280, 'L', 'JET', 'SEED'),
    ('_DEF_JS', 2800, 250, 0.72, 420, 0.74, 2800, 260, 'S', 'JET', 'SEED'),
    ('_DEF_JJ', 1800, 300, 0.85, 500, 0.86, 1800, 300, 'J', 'JET', 'SEED'),
    ('_DEF_TP', 1500, 200, NULL, 300, NULL, 1500, 200, 'L', 'TURBOPROP', 'SEED'),
    ('_DEF_PS', 800, 120, NULL, 160, NULL, 800, 120, 'S', 'PISTON', 'SEED'),
    ('B738', 2500, 280, 0.78, 453, 0.785, 2500, 280, 'L', 'JET', 'SEED'),
    ('B739', 2400, 280, 0.78, 453, 0.785, 2400, 280, 'L', 'JET', 'SEED'),
    ('A320', 2600, 280, 0.78, 454, 0.780, 2600, 280, 'L', 'JET', 'SEED'),
    ('A321', 2400, 280, 0.78, 454, 0.780, 2400, 280, 'L', 'JET', 'SEED'),
    ('B77W', 2400, 295, 0.85, 500, 0.850, 2400, 290, 'H', 'JET', 'SEED'),
    ('B788', 2600, 290, 0.85, 490, 0.850, 2600, 290, 'H', 'JET', 'SEED'),
    ('A359', 2500, 290, 0.85, 495, 0.850, 2500, 290, 'H', 'JET', 'SEED');

PRINT '  ✓ Seeded ' + CAST(@@ROWCOUNT AS VARCHAR) + ' aircraft profiles';
GO

-- ============================================================================
-- Step 3: Deploy Functions
-- ============================================================================

PRINT '';
PRINT '▶ Step 3: Deploying functions...';
GO

-- fn_IsFlightRelevant
IF OBJECT_ID('dbo.fn_IsFlightRelevant', 'FN') IS NOT NULL
    DROP FUNCTION dbo.fn_IsFlightRelevant;
GO

CREATE FUNCTION dbo.fn_IsFlightRelevant(
    @dept_icao NVARCHAR(4),
    @dest_icao NVARCHAR(4),
    @current_lat DECIMAL(10,7),
    @current_lon DECIMAL(11,7)
)
RETURNS BIT
AS
BEGIN
    IF @dept_icao IS NOT NULL AND (
        @dept_icao LIKE 'K%' OR @dept_icao LIKE 'P%' OR @dept_icao LIKE 'C%' OR
        @dept_icao LIKE 'MM%' OR @dept_icao LIKE 'M[GHNRPSB]%' OR
        @dept_icao LIKE 'T%' OR @dept_icao LIKE 'S%'
    ) RETURN 1;
    
    IF @dest_icao IS NOT NULL AND (
        @dest_icao LIKE 'K%' OR @dest_icao LIKE 'P%' OR @dest_icao LIKE 'C%' OR
        @dest_icao LIKE 'MM%' OR @dest_icao LIKE 'M[GHNRPSB]%' OR
        @dest_icao LIKE 'T%' OR @dest_icao LIKE 'S%'
    ) RETURN 1;
    
    IF @current_lat BETWEEN -56 AND 72 AND @current_lon BETWEEN -180 AND -20
        RETURN 1;
    
    RETURN 0;
END;
GO

PRINT '  ✓ Created fn_IsFlightRelevant';
GO

-- fn_GetTierIntervalSeconds
IF OBJECT_ID('dbo.fn_GetTierIntervalSeconds', 'FN') IS NOT NULL
    DROP FUNCTION dbo.fn_GetTierIntervalSeconds;
GO

CREATE FUNCTION dbo.fn_GetTierIntervalSeconds(@tier TINYINT)
RETURNS INT
AS
BEGIN
    RETURN CASE @tier
        WHEN 0 THEN 15 WHEN 1 THEN 30 WHEN 2 THEN 60 WHEN 3 THEN 120
        WHEN 4 THEN 300 WHEN 5 THEN 600 WHEN 6 THEN 1800 WHEN 7 THEN NULL
        ELSE 300
    END;
END;
GO

PRINT '  ✓ Created fn_GetTierIntervalSeconds';
GO

-- fn_GetTrajectoryTier (abbreviated - full version in separate file)
IF OBJECT_ID('dbo.fn_GetTrajectoryTier', 'FN') IS NOT NULL
    DROP FUNCTION dbo.fn_GetTrajectoryTier;
GO

CREATE FUNCTION dbo.fn_GetTrajectoryTier(
    @dept_icao NVARCHAR(4), @dest_icao NVARCHAR(4),
    @current_lat DECIMAL(10,7), @current_lon DECIMAL(11,7),
    @altitude_ft INT, @groundspeed_kts INT, @heading_deg SMALLINT,
    @vertical_rate_fpm INT, @flight_phase NVARCHAR(16),
    @dist_to_dest_nm DECIMAL(10,2), @dist_from_origin_nm DECIMAL(10,2),
    @filed_altitude_ft INT, @dest_elevation_ft INT, @has_active_tmi BIT,
    @prev_altitude_ft INT, @prev_groundspeed_kts INT, @prev_heading_deg SMALLINT,
    @time_at_level_min INT, @last_fp_change_min INT
)
RETURNS TINYINT
AS
BEGIN
    DECLARE @tier TINYINT = 4;
    
    -- Relevance check
    IF dbo.fn_IsFlightRelevant(@dept_icao, @dest_icao, @current_lat, @current_lon) = 0
        RETURN 7;
    
    -- Set defaults
    SET @altitude_ft = ISNULL(@altitude_ft, 0);
    SET @groundspeed_kts = ISNULL(@groundspeed_kts, 0);
    SET @vertical_rate_fpm = ISNULL(@vertical_rate_fpm, 0);
    SET @dist_to_dest_nm = ISNULL(@dist_to_dest_nm, 9999);
    SET @dist_from_origin_nm = ISNULL(@dist_from_origin_nm, 0);
    
    -- Tier 0: Critical phases
    IF @dist_from_origin_nm < 50 AND @vertical_rate_fpm > 300 AND @altitude_ft < 18000 RETURN 0;
    IF @dist_to_dest_nm < 15 AND @vertical_rate_fpm < -300 AND @altitude_ft < 10000 RETURN 0;
    IF @dist_to_dest_nm < 3 AND @vertical_rate_fpm > 500 AND @altitude_ft < 3000 RETURN 0;
    IF @dist_to_dest_nm < 2 AND @altitude_ft < 500 RETURN 0;
    IF @last_fp_change_min IS NOT NULL AND @last_fp_change_min <= 5 RETURN 0;
    
    -- Tier 1: Approaching events
    IF @dist_to_dest_nm <= 50 AND @altitude_ft > 10000 RETURN 1;
    IF @has_active_tmi = 1 RETURN 1;
    IF @prev_groundspeed_kts IS NOT NULL AND ABS(@groundspeed_kts - @prev_groundspeed_kts) > 30 AND ABS(@vertical_rate_fpm) < 500 RETURN 1;
    IF @vertical_rate_fpm < -1000 AND @altitude_ft > 10000 RETURN 1;
    IF @vertical_rate_fpm > 1000 AND @altitude_ft > 20000 RETURN 1;
    
    -- Tier 3: Ground ops
    IF @groundspeed_kts BETWEEN 5 AND 35 AND @altitude_ft < 500 RETURN 3;
    
    -- Tier 4: Stable cruise
    IF @time_at_level_min >= 10 AND @dist_to_dest_nm > 100 AND @dist_from_origin_nm > 100 AND ABS(@vertical_rate_fpm) < 200
        RETURN 4;
    IF @groundspeed_kts < 2 AND @altitude_ft < 500 RETURN 4;
    
    -- Tier 5/6: Extended stable (simplified)
    IF @time_at_level_min >= 30 AND @dist_to_dest_nm > 300 RETURN 5;
    IF @time_at_level_min >= 60 AND @dist_to_dest_nm > 500 RETURN 6;
    
    RETURN @tier;
END;
GO

PRINT '  ✓ Created fn_GetTrajectoryTier';
GO

-- ============================================================================
-- Step 4: Deploy Stored Procedures
-- ============================================================================

PRINT '';
PRINT '▶ Step 4: Deploying stored procedures...';
PRINT '  (Run individual procedure files for full versions)';
PRINT '  - sp_CalculateETA.sql';
PRINT '  - sp_LogTrajectory.sql';
PRINT '  - sp_ProcessTrajectoryBatch.sql';
GO

-- ============================================================================
-- Verification
-- ============================================================================

PRINT '';
PRINT '▶ Verifying deployment...';

DECLARE @errors INT = 0;

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'eta_prefix')
BEGIN PRINT '  ✗ Missing eta_prefix column'; SET @errors = @errors + 1; END

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_trajectory') AND name = 'tier')
BEGIN PRINT '  ✗ Missing tier column'; SET @errors = @errors + 1; END

IF OBJECT_ID('dbo.fn_IsFlightRelevant', 'FN') IS NULL
BEGIN PRINT '  ✗ Missing fn_IsFlightRelevant'; SET @errors = @errors + 1; END

IF OBJECT_ID('dbo.fn_GetTrajectoryTier', 'FN') IS NULL
BEGIN PRINT '  ✗ Missing fn_GetTrajectoryTier'; SET @errors = @errors + 1; END

IF NOT EXISTS (SELECT 1 FROM dbo.fir_boundaries WHERE source = 'SEED')
BEGIN PRINT '  ✗ FIR boundaries not seeded'; SET @errors = @errors + 1; END

IF NOT EXISTS (SELECT 1 FROM dbo.aircraft_performance_profiles WHERE source = 'SEED')
BEGIN PRINT '  ✗ Aircraft profiles not seeded'; SET @errors = @errors + 1; END

IF @errors = 0
    PRINT '  ✓ All components verified';
ELSE
    PRINT '  WARNING: ' + CAST(@errors AS VARCHAR) + ' component(s) missing';
GO

-- ============================================================================
-- Summary
-- ============================================================================

PRINT '';
PRINT '══════════════════════════════════════════════════════════════════════════';
PRINT '  Deployment Summary';
PRINT '══════════════════════════════════════════════════════════════════════════';
PRINT '';
PRINT '  Tables Created/Modified:';
PRINT '    • adl_flight_times - Added OOOI and ETA fields';
PRINT '    • adl_flight_trajectory - Added tier tracking';
PRINT '    • adl_flight_core - Added trajectory tracking fields';
PRINT '    • fir_boundaries - NEW';
PRINT '    • aircraft_performance_profiles - NEW';
PRINT '';
PRINT '  Functions Created:';
PRINT '    • fn_IsFlightRelevant - Relevance check for tier 7';
PRINT '    • fn_GetTierIntervalSeconds - Tier to seconds lookup';
PRINT '    • fn_GetTrajectoryTier - Main tier calculation';
PRINT '';
PRINT '  Next Steps:';
PRINT '    1. Run full procedure files (sp_CalculateETA, sp_LogTrajectory,';
PRINT '       sp_ProcessTrajectoryBatch) for complete implementations';
PRINT '    2. Run 031_eta_trajectory_seed_data.sql for complete seed data';
PRINT '    3. Integrate sp_ProcessTrajectoryBatch into refresh procedure';
PRINT '    4. Test with live VATSIM data';
PRINT '';
PRINT '══════════════════════════════════════════════════════════════════════════';
PRINT '  Deployment complete at ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '══════════════════════════════════════════════════════════════════════════';
GO
