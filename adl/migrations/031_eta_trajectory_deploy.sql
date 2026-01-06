-- ============================================================================
-- 031_eta_trajectory_deploy.sql
-- Complete ETA & Trajectory System Deployment
-- 
-- This script deploys the full ETA & Trajectory Calculation System:
-- 1. Schema migrations (tables, columns)
-- 2. Reference data (FIRs, aircraft profiles)
-- 3. Functions (fn_IsFlightRelevant, fn_GetTrajectoryTier, etc.)
-- 4. Stored procedures (sp_CalculateETA, sp_LogTrajectory, sp_ProcessTrajectoryBatch)
-- 
-- Run this on the VATSIM_ADL database.
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '==========================================================================';
PRINT '  PERTI ETA & Trajectory Calculation System - Full Deployment';
PRINT '  Version 1.0 - ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
PRINT '';
GO

-- ============================================================================
-- PART 1: SCHEMA CHANGES
-- ============================================================================

PRINT '? PART 1: Schema Changes';
PRINT '';

-- Add OOOI fields to adl_flight_times
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'out_utc')
    ALTER TABLE dbo.adl_flight_times ADD out_utc DATETIME2(0) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'off_utc')
    ALTER TABLE dbo.adl_flight_times ADD off_utc DATETIME2(0) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'on_utc')
    ALTER TABLE dbo.adl_flight_times ADD on_utc DATETIME2(0) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'in_utc')
    ALTER TABLE dbo.adl_flight_times ADD in_utc DATETIME2(0) NULL;

-- ETA calculation fields
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'eta_prefix')
    ALTER TABLE dbo.adl_flight_times ADD eta_prefix NCHAR(1) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'eta_route_dist_nm')
    ALTER TABLE dbo.adl_flight_times ADD eta_route_dist_nm DECIMAL(10,2) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'eta_wind_component_kts')
    ALTER TABLE dbo.adl_flight_times ADD eta_wind_component_kts INT NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_times') AND name = 'eta_weather_delay_min')
    ALTER TABLE dbo.adl_flight_times ADD eta_weather_delay_min INT NULL;
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

PRINT '  ? adl_flight_times columns added';
GO

-- Add tier tracking to adl_flight_trajectory
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

PRINT '  ? adl_flight_trajectory columns added';
GO

-- Add trajectory tracking to adl_flight_core
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_core') AND name = 'last_trajectory_tier')
    ALTER TABLE dbo.adl_flight_core ADD last_trajectory_tier TINYINT NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_core') AND name = 'last_trajectory_utc')
    ALTER TABLE dbo.adl_flight_core ADD last_trajectory_utc DATETIME2(0) NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_core') AND name = 'is_relevant')
    ALTER TABLE dbo.adl_flight_core ADD is_relevant BIT NULL;
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_core') AND name = 'flight_phase')
    ALTER TABLE dbo.adl_flight_core ADD flight_phase NVARCHAR(16) NULL;

PRINT '  ? adl_flight_core columns added';
GO

-- Create FIR boundaries table
IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.fir_boundaries') AND type = 'U')
BEGIN
    CREATE TABLE dbo.fir_boundaries (
        fir_id              INT IDENTITY(1,1) PRIMARY KEY,
        fir_icao            NVARCHAR(4) NOT NULL,
        fir_name            NVARCHAR(64) NOT NULL,
        fir_type            NVARCHAR(16) NOT NULL,
        is_covered_region   BIT NOT NULL DEFAULT 0,
        is_us_ca_oceanic    BIT NOT NULL DEFAULT 0,
        min_lat             DECIMAL(10,7) NULL,
        max_lat             DECIMAL(10,7) NULL,
        min_lon             DECIMAL(10,7) NULL,
        max_lon             DECIMAL(10,7) NULL,
        source              NVARCHAR(32) NULL,
        created_utc         DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME()
    );
    CREATE NONCLUSTERED INDEX IX_fir_covered ON dbo.fir_boundaries (is_covered_region);
    CREATE NONCLUSTERED INDEX IX_fir_icao ON dbo.fir_boundaries (fir_icao);
    PRINT '  ? Created fir_boundaries table';
END
ELSE
    PRINT '  - fir_boundaries already exists';
GO

-- Create aircraft performance profiles table
IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.aircraft_performance_profiles') AND type = 'U')
BEGIN
    CREATE TABLE dbo.aircraft_performance_profiles (
        profile_id          INT IDENTITY(1,1) PRIMARY KEY,
        aircraft_icao       NVARCHAR(8) NOT NULL,
        climb_rate_fpm      INT NOT NULL DEFAULT 2000,
        climb_speed_kias    INT NOT NULL DEFAULT 280,
        climb_speed_mach    DECIMAL(3,2) NULL,
        cruise_speed_ktas   INT NOT NULL DEFAULT 450,
        cruise_mach         DECIMAL(3,2) NULL,
        optimal_fl          INT NULL,
        descent_rate_fpm    INT NOT NULL DEFAULT 2000,
        descent_speed_kias  INT NOT NULL DEFAULT 280,
        descent_angle_deg   DECIMAL(4,2) NULL DEFAULT 3.0,
        weight_class        NCHAR(1) NULL,
        engine_type         NVARCHAR(16) NULL,
        range_nm            INT NULL,
        fuel_burn_lbs_hr    INT NULL,
        source              NVARCHAR(32) NULL,
        created_utc         DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        CONSTRAINT UK_aircraft_perf_icao UNIQUE (aircraft_icao)
    );
    PRINT '  ? Created aircraft_performance_profiles table';
END
ELSE
BEGIN
    -- Ensure engine_type column is wide enough
    ALTER TABLE dbo.aircraft_performance_profiles ALTER COLUMN engine_type NVARCHAR(16) NULL;
    PRINT '  - aircraft_performance_profiles already exists (column expanded)';
END
GO

PRINT '';
PRINT '? PART 2: Reference Data';
PRINT '';

-- Seed FIR boundaries
IF NOT EXISTS (SELECT 1 FROM dbo.fir_boundaries WHERE fir_icao = 'KZAB')
BEGIN
    INSERT INTO dbo.fir_boundaries (fir_icao, fir_name, fir_type, is_covered_region, is_us_ca_oceanic) VALUES
    ('KZAB', 'Albuquerque Center', 'DOMESTIC', 1, 0),
    ('KZAU', 'Chicago Center', 'DOMESTIC', 1, 0),
    ('KZBW', 'Boston Center', 'DOMESTIC', 1, 0),
    ('KZDC', 'Washington Center', 'DOMESTIC', 1, 0),
    ('KZDV', 'Denver Center', 'DOMESTIC', 1, 0),
    ('KZFW', 'Fort Worth Center', 'DOMESTIC', 1, 0),
    ('KZHU', 'Houston Center', 'DOMESTIC', 1, 0),
    ('KZID', 'Indianapolis Center', 'DOMESTIC', 1, 0),
    ('KZJX', 'Jacksonville Center', 'DOMESTIC', 1, 0),
    ('KZKC', 'Kansas City Center', 'DOMESTIC', 1, 0),
    ('KZLA', 'Los Angeles Center', 'DOMESTIC', 1, 0),
    ('KZLC', 'Salt Lake Center', 'DOMESTIC', 1, 0),
    ('KZMA', 'Miami Center', 'DOMESTIC', 1, 0),
    ('KZME', 'Memphis Center', 'DOMESTIC', 1, 0),
    ('KZMP', 'Minneapolis Center', 'DOMESTIC', 1, 0),
    ('KZNY', 'New York Center', 'DOMESTIC', 1, 0),
    ('KZOA', 'Oakland Center', 'DOMESTIC', 1, 0),
    ('KZOB', 'Cleveland Center', 'DOMESTIC', 1, 0),
    ('KZSE', 'Seattle Center', 'DOMESTIC', 1, 0),
    ('KZTL', 'Atlanta Center', 'DOMESTIC', 1, 0),
    ('PZAN', 'Anchorage Center', 'DOMESTIC', 1, 0),
    ('PHZH', 'Honolulu Control', 'DOMESTIC', 1, 0),
    ('KZAK', 'Oakland Oceanic', 'OCEANIC', 1, 1),
    ('KZWY', 'New York Oceanic', 'OCEANIC', 1, 1),
    ('CZEG', 'Edmonton FIR', 'DOMESTIC', 1, 0),
    ('CZUL', 'Montreal FIR', 'DOMESTIC', 1, 0),
    ('CZWG', 'Winnipeg FIR', 'DOMESTIC', 1, 0),
    ('CZVR', 'Vancouver FIR', 'DOMESTIC', 1, 0),
    ('CZYZ', 'Toronto FIR', 'DOMESTIC', 1, 0),
    ('CZQX', 'Gander Oceanic', 'OCEANIC', 1, 1),
    ('CZQM', 'Moncton FIR', 'DOMESTIC', 1, 0);
    PRINT '  ? Seeded ' + CAST(@@ROWCOUNT AS VARCHAR) + ' FIR boundaries';
END
ELSE
    PRINT '  - FIR boundaries already seeded';
GO

-- Seed aircraft performance profiles
IF NOT EXISTS (SELECT 1 FROM dbo.aircraft_performance_profiles WHERE aircraft_icao = '_JET_L')
BEGIN
    INSERT INTO dbo.aircraft_performance_profiles 
        (aircraft_icao, climb_rate_fpm, climb_speed_kias, cruise_speed_ktas, cruise_mach, descent_rate_fpm, weight_class, engine_type, source)
    VALUES
        ('_JET_J', 2000, 300, 500, 0.85, 2500, 'J', 'JET', 'DEFAULT'),
        ('_JET_H', 2500, 290, 480, 0.84, 2500, 'H', 'JET', 'DEFAULT'),
        ('_JET_L', 2000, 280, 450, 0.78, 2000, 'L', 'JET', 'DEFAULT'),
        ('_JET_S', 2500, 250, 400, 0.72, 2500, 'S', 'JET', 'DEFAULT'),
        ('_TURBO', 1500, 200, 280, NULL, 1500, 'L', 'TURBOPROP', 'DEFAULT'),
        ('_PISTON', 800, 120, 150, NULL, 800, 'S', 'PISTON', 'DEFAULT'),
        ('B738', 2500, 280, 460, 0.785, 2500, 'L', 'JET', 'ESTIMATED'),
        ('B739', 2500, 280, 460, 0.785, 2500, 'L', 'JET', 'ESTIMATED'),
        ('B77W', 2000, 290, 490, 0.84, 2000, 'H', 'JET', 'ESTIMATED'),
        ('B744', 1800, 300, 500, 0.85, 2000, 'H', 'JET', 'ESTIMATED'),
        ('B788', 2200, 290, 490, 0.85, 2200, 'H', 'JET', 'ESTIMATED'),
        ('B789', 2200, 290, 490, 0.85, 2200, 'H', 'JET', 'ESTIMATED'),
        ('A320', 2500, 280, 450, 0.78, 2500, 'L', 'JET', 'ESTIMATED'),
        ('A321', 2500, 280, 455, 0.78, 2500, 'L', 'JET', 'ESTIMATED'),
        ('A20N', 2500, 280, 455, 0.78, 2500, 'L', 'JET', 'ESTIMATED'),
        ('A21N', 2500, 280, 455, 0.78, 2500, 'L', 'JET', 'ESTIMATED'),
        ('A333', 2200, 290, 480, 0.82, 2200, 'H', 'JET', 'ESTIMATED'),
        ('A359', 2200, 290, 490, 0.85, 2200, 'H', 'JET', 'ESTIMATED'),
        ('A388', 1500, 300, 500, 0.85, 1800, 'J', 'JET', 'ESTIMATED'),
        ('E190', 2800, 280, 430, 0.78, 2500, 'L', 'JET', 'ESTIMATED'),
        ('E75L', 3000, 280, 430, 0.78, 2500, 'L', 'JET', 'ESTIMATED'),
        ('CRJ9', 3000, 280, 420, 0.78, 2500, 'L', 'JET', 'ESTIMATED'),
        ('C172', 700, 100, 120, NULL, 500, 'S', 'PISTON', 'ESTIMATED'),
        ('C208', 1000, 130, 180, NULL, 800, 'S', 'TURBOPROP', 'ESTIMATED'),
        ('PC12', 1500, 180, 280, NULL, 1500, 'S', 'TURBOPROP', 'ESTIMATED'),
        ('TBM9', 2000, 200, 330, NULL, 2000, 'S', 'TURBOPROP', 'ESTIMATED'),
        ('C56X', 3500, 260, 430, 0.75, 3000, 'S', 'JET', 'ESTIMATED'),
        ('GLEX', 3500, 280, 480, 0.85, 3000, 'L', 'JET', 'ESTIMATED'),
        ('DH8D', 1500, 200, 310, NULL, 1500, 'L', 'TURBOPROP', 'ESTIMATED'),
        ('AT76', 1200, 180, 280, NULL, 1200, 'L', 'TURBOPROP', 'ESTIMATED');
    PRINT '  ? Seeded ' + CAST(@@ROWCOUNT AS VARCHAR) + ' aircraft performance profiles';
END
ELSE
    PRINT '  - Aircraft profiles already seeded';
GO

PRINT '';
PRINT '? PART 3: Functions';
PRINT '';

-- fn_IsFlightRelevant
IF OBJECT_ID('dbo.fn_IsFlightRelevant', 'FN') IS NOT NULL
    DROP FUNCTION dbo.fn_IsFlightRelevant;
GO

CREATE FUNCTION dbo.fn_IsFlightRelevant(
    @dept_icao      CHAR(4),
    @dest_icao      CHAR(4),
    @current_lat    DECIMAL(10,7),
    @current_lon    DECIMAL(11,7)
)
RETURNS BIT
AS
BEGIN
    -- Origin in covered region
    IF @dept_icao LIKE 'K%' OR @dept_icao LIKE 'P%' RETURN 1;
    IF @dept_icao LIKE 'C%' RETURN 1;
    IF @dept_icao LIKE 'MM%' RETURN 1;
    IF @dept_icao LIKE 'M[GHNRPSB]%' RETURN 1;
    IF @dept_icao LIKE 'T%' RETURN 1;
    IF @dept_icao LIKE 'S%' RETURN 1;
    
    -- Destination in covered region
    IF @dest_icao LIKE 'K%' OR @dest_icao LIKE 'P%' RETURN 1;
    IF @dest_icao LIKE 'C%' RETURN 1;
    IF @dest_icao LIKE 'MM%' RETURN 1;
    IF @dest_icao LIKE 'M[GHNRPSB]%' RETURN 1;
    IF @dest_icao LIKE 'T%' RETURN 1;
    IF @dest_icao LIKE 'S%' RETURN 1;
    
    -- Position in covered airspace
    IF @current_lat IS NULL OR @current_lon IS NULL RETURN 0;
    IF @current_lat < -60 OR @current_lat > 75 RETURN 0;
    IF @current_lon < -180 OR @current_lon > -10 RETURN 0;
    
    -- North America
    IF @current_lat BETWEEN 15 AND 72 AND @current_lon BETWEEN -180 AND -50 RETURN 1;
    -- Central America / Caribbean
    IF @current_lat BETWEEN 5 AND 35 AND @current_lon BETWEEN -100 AND -55 RETURN 1;
    -- South America
    IF @current_lat BETWEEN -56 AND 15 AND @current_lon BETWEEN -85 AND -30 RETURN 1;
    -- US Atlantic oceanic
    IF @current_lat BETWEEN 20 AND 45 AND @current_lon BETWEEN -80 AND -40 RETURN 1;
    -- US Pacific oceanic
    IF @current_lat BETWEEN 15 AND 60 AND @current_lon BETWEEN -180 AND -130 RETURN 1;
    -- Canadian oceanic
    IF @current_lat BETWEEN 40 AND 60 AND @current_lon BETWEEN -60 AND -30 RETURN 1;
    
    RETURN 0;
END
GO

PRINT '  ? Created fn_IsFlightRelevant';
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
        WHEN 0 THEN 15
        WHEN 1 THEN 30
        WHEN 2 THEN 60
        WHEN 3 THEN 120
        WHEN 4 THEN 300
        WHEN 5 THEN 600
        WHEN 6 THEN 1800
        WHEN 7 THEN 999999
        ELSE 300
    END;
END
GO

PRINT '  ? Created fn_GetTierIntervalSeconds';
GO

-- fn_GetTrajectoryTier
IF OBJECT_ID('dbo.fn_GetTrajectoryTier', 'FN') IS NOT NULL
    DROP FUNCTION dbo.fn_GetTrajectoryTier;
GO

CREATE FUNCTION dbo.fn_GetTrajectoryTier(
    @dept_icao          CHAR(4),
    @dest_icao          CHAR(4),
    @current_lat        DECIMAL(10,7),
    @current_lon        DECIMAL(11,7),
    @altitude_ft        INT,
    @groundspeed_kts    INT,
    @vertical_rate_fpm  INT,
    @dist_to_dest_nm    DECIMAL(10,2),
    @dist_from_origin_nm DECIMAL(10,2),
    @filed_altitude_ft  INT,
    @phase              NVARCHAR(16)
)
RETURNS TINYINT
AS
BEGIN
    DECLARE @tier TINYINT = 4;
    
    -- Null handling
    SET @altitude_ft = ISNULL(@altitude_ft, 0);
    SET @groundspeed_kts = ISNULL(@groundspeed_kts, 0);
    SET @vertical_rate_fpm = ISNULL(@vertical_rate_fpm, 0);
    SET @dist_to_dest_nm = ISNULL(@dist_to_dest_nm, 9999);
    SET @dist_from_origin_nm = ISNULL(@dist_from_origin_nm, 0);
    SET @filed_altitude_ft = ISNULL(@filed_altitude_ft, 35000);
    
    -- TIER 7: Relevance check
    IF dbo.fn_IsFlightRelevant(@dept_icao, @dest_icao, @current_lat, @current_lon) = 0
        RETURN 7;
    
    -- TIER 0: Critical phases
    IF @dist_from_origin_nm < 50 AND @vertical_rate_fpm > 300 AND @altitude_ft < 18000 RETURN 0;
    IF @dist_to_dest_nm < 15 AND @vertical_rate_fpm < -300 AND @altitude_ft < 10000 RETURN 0;
    IF @dist_to_dest_nm < 5 AND @vertical_rate_fpm > 1000 AND @altitude_ft < 5000 RETURN 0;
    IF @groundspeed_kts BETWEEN 40 AND 180 AND @altitude_ft < 500 AND (@dist_from_origin_nm < 5 OR @dist_to_dest_nm < 5) RETURN 0;
    IF @dist_from_origin_nm < 3 OR @dist_to_dest_nm < 3 RETURN 0;
    
    -- TIER 1: Approaching events
    DECLARE @tod_dist_nm DECIMAL(10,2) = @filed_altitude_ft / 1000.0 * 3.0;
    DECLARE @time_to_tod DECIMAL(10,2) = CASE WHEN @dist_to_dest_nm > @tod_dist_nm AND @groundspeed_kts > 0 
        THEN (@dist_to_dest_nm - @tod_dist_nm) / @groundspeed_kts * 60 ELSE NULL END;
    
    IF @time_to_tod IS NOT NULL AND @time_to_tod <= 5 AND @time_to_tod > 0 RETURN 1;
    IF @dist_to_dest_nm < 100 RETURN 1;
    IF @altitude_ft > 25000 AND ABS(@vertical_rate_fpm) > 500 RETURN 1;
    IF @vertical_rate_fpm > 300 AND @altitude_ft >= 18000 RETURN 1;
    IF @vertical_rate_fpm < -300 AND @altitude_ft >= 10000 AND @dist_to_dest_nm >= 15 RETURN 1;
    IF @phase IN ('departed', 'climbing', 'descending') RETURN 1;
    
    -- TIER 2: Oceanic
    DECLARE @is_oceanic BIT = 0;
    IF @current_lat BETWEEN 35 AND 65 AND @current_lon BETWEEN -60 AND -10 SET @is_oceanic = 1;
    IF @current_lat BETWEEN 20 AND 60 AND @current_lon BETWEEN -180 AND -140 SET @is_oceanic = 1;
    IF @is_oceanic = 1 AND @dist_to_dest_nm > 100 AND ABS(@vertical_rate_fpm) < 500 SET @tier = 2;
    
    -- TIER 3: Ground ops
    IF @groundspeed_kts BETWEEN 5 AND 35 AND @altitude_ft < 500 RETURN 3;
    IF @phase = 'taxiing' RETURN 3;
    
    -- TIER 4: Stable cruise
    IF ABS(@vertical_rate_fpm) < 200 AND @altitude_ft > 20000 AND @dist_to_dest_nm > 200 AND @dist_from_origin_nm > 100
    BEGIN
        IF @time_to_tod IS NOT NULL AND @time_to_tod <= 10 RETURN 1;
        SET @tier = 4;
    END
    IF @phase IN ('prefile', 'unknown') AND @groundspeed_kts < 5 SET @tier = 4;
    
    -- TIER 5: Extended oceanic
    IF @is_oceanic = 1 AND ABS(@vertical_rate_fpm) < 100 AND @altitude_ft > 30000 AND @dist_to_dest_nm > 500
    BEGIN
        IF @time_to_tod IS NOT NULL AND @time_to_tod <= 15 RETURN 1;
        SET @tier = 5;
    END
    
    -- TIER 6: Ultra-long
    IF @is_oceanic = 1 AND ABS(@vertical_rate_fpm) < 50 AND @altitude_ft > 35000 AND @dist_to_dest_nm > 1000 AND @dist_from_origin_nm > 1000
    BEGIN
        IF @time_to_tod IS NOT NULL AND @time_to_tod <= 30 RETURN 1;
        SET @tier = 6;
    END
    
    RETURN @tier;
END
GO

PRINT '  ? Created fn_GetTrajectoryTier';
GO

PRINT '';
PRINT '? PART 4: Stored Procedures';
PRINT '';

-- sp_ProcessTrajectoryBatch (inline for single-file deployment)
IF OBJECT_ID('dbo.sp_ProcessTrajectoryBatch', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_ProcessTrajectoryBatch;
GO

CREATE PROCEDURE dbo.sp_ProcessTrajectoryBatch
    @process_eta        BIT = 1,
    @process_trajectory BIT = 1,
    @eta_count          INT = NULL OUTPUT,
    @traj_count         INT = NULL OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @now DATETIME2(0) = SYSUTCDATETIME();
    SET @eta_count = 0;
    SET @traj_count = 0;
    
    -- Update relevance flags
    UPDATE c SET c.is_relevant = dbo.fn_IsFlightRelevant(fp.fp_dept_icao, fp.fp_dest_icao, p.lat, p.lon)
    FROM dbo.adl_flight_core c
    JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
    WHERE c.is_active = 1 AND c.is_relevant IS NULL;
    
    -- Trajectory logging
    IF @process_trajectory = 1
    BEGIN
        INSERT INTO dbo.adl_flight_trajectory (flight_uid, recorded_utc, lat, lon, altitude_ft, groundspeed_kts, heading_deg, vertical_rate_fpm, tier, tier_reason, flight_phase, dist_to_dest_nm, dist_from_origin_nm, source)
        SELECT c.flight_uid, @now, p.lat, p.lon, p.altitude_ft, p.groundspeed_kts, p.heading_deg, p.vertical_rate_fpm,
            dbo.fn_GetTrajectoryTier(fp.fp_dept_icao, fp.fp_dest_icao, p.lat, p.lon, p.altitude_ft, p.groundspeed_kts, ISNULL(p.vertical_rate_fpm, 0), p.dist_to_dest_nm, p.dist_flown_nm, fp.fp_altitude_ft, c.phase),
            'BATCH', c.phase, p.dist_to_dest_nm, p.dist_flown_nm, 'vatsim'
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
        JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        WHERE c.is_active = 1 AND c.is_relevant = 1 AND p.lat IS NOT NULL
        AND dbo.fn_GetTrajectoryTier(fp.fp_dept_icao, fp.fp_dest_icao, p.lat, p.lon, p.altitude_ft, p.groundspeed_kts, ISNULL(p.vertical_rate_fpm, 0), p.dist_to_dest_nm, p.dist_flown_nm, fp.fp_altitude_ft, c.phase) < 7
        AND (c.last_trajectory_utc IS NULL OR DATEDIFF(SECOND, c.last_trajectory_utc, @now) >= dbo.fn_GetTierIntervalSeconds(ISNULL(c.last_trajectory_tier, 4)));
        
        SET @traj_count = @@ROWCOUNT;
        
        UPDATE c SET c.last_trajectory_tier = dbo.fn_GetTrajectoryTier(fp.fp_dept_icao, fp.fp_dest_icao, p.lat, p.lon, p.altitude_ft, p.groundspeed_kts, ISNULL(p.vertical_rate_fpm, 0), p.dist_to_dest_nm, p.dist_flown_nm, fp.fp_altitude_ft, c.phase), c.last_trajectory_utc = @now
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
        JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        WHERE c.is_active = 1 AND c.is_relevant = 1 AND (c.last_trajectory_utc IS NULL OR c.last_trajectory_utc < @now);
    END
    
    -- ETA calculation
    IF @process_eta = 1
    BEGIN
        UPDATE ft SET
            ft.eta_utc = DATEADD(MINUTE, CAST(CASE WHEN c.phase = 'arrived' THEN 0 WHEN c.phase = 'descending' THEN p.dist_to_dest_nm / NULLIF(p.groundspeed_kts, 0) * 60 ELSE (p.dist_to_dest_nm - ISNULL(fp.fp_altitude_ft, 35000) / 1000.0 * 3.0) / 450.0 * 60 + ISNULL(fp.fp_altitude_ft, 35000) / 1000.0 * 3.0 / 280.0 * 60 END AS INT), @now),
            ft.eta_prefix = CASE WHEN c.phase = 'arrived' THEN 'A' ELSE 'E' END,
            ft.eta_confidence = CASE c.phase WHEN 'arrived' THEN 1.0 WHEN 'descending' THEN 0.92 WHEN 'enroute' THEN 0.88 ELSE 0.75 END,
            ft.eta_last_calc_utc = @now
        FROM dbo.adl_flight_times ft
        JOIN dbo.adl_flight_core c ON c.flight_uid = ft.flight_uid
        JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
        JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        WHERE c.is_active = 1 AND p.lat IS NOT NULL;
        
        SET @eta_count = @@ROWCOUNT;
    END
END
GO

PRINT '  ? Created sp_ProcessTrajectoryBatch';
GO

PRINT '';
PRINT '==========================================================================';
PRINT '  Deployment Complete!';
PRINT '';
PRINT '  To integrate with the main refresh procedure, add this call:';
PRINT '';
PRINT '    EXEC dbo.sp_ProcessTrajectoryBatch';
PRINT '        @process_eta = 1,';
PRINT '        @process_trajectory = 1;';
PRINT '';
PRINT '  Add it at the end of sp_Adl_RefreshFromVatsim_Normalized';
PRINT '==========================================================================';
GO
