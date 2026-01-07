-- ============================================================================
-- 045_aircraft_performance_seed.sql
-- Populate aircraft_performance_profiles with realistic performance data
-- 
-- Sources: OpenAP, manufacturer specs, pilot operating handbooks
-- Values represent typical/average performance, not max capability
--
-- Date: 2026-01-07
-- ============================================================================

SET NOCOUNT ON;
GO

PRINT '=== Seeding Aircraft Performance Profiles ===';
PRINT 'Timestamp: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- STEP 1: Clear existing data (optional - comment out to preserve custom entries)
-- ============================================================================
-- DELETE FROM dbo.aircraft_performance_profiles WHERE source IN ('SEED', 'DEFAULT');
-- GO

-- ============================================================================
-- STEP 2: Insert DEFAULT category profiles
-- These are used when specific aircraft type not found
-- ============================================================================

PRINT 'Inserting default category profiles...';

-- Super/Jumbo Jet (A380, B747)
IF NOT EXISTS (SELECT 1 FROM dbo.aircraft_performance_profiles WHERE aircraft_icao = '_DEF_JJ')
INSERT INTO dbo.aircraft_performance_profiles 
(aircraft_icao, climb_rate_fpm, climb_speed_kias, climb_speed_mach, cruise_speed_ktas, cruise_mach, descent_rate_fpm, descent_speed_kias, optimal_fl, weight_class, engine_type, source, created_utc)
VALUES ('_DEF_JJ', 1800, 290, 0.84, 490, 0.85, 2000, 290, 390, 'J', 'JET', 'DEFAULT', SYSUTCDATETIME());

-- Heavy Jet (B777, B787, A350, A330)
IF NOT EXISTS (SELECT 1 FROM dbo.aircraft_performance_profiles WHERE aircraft_icao = '_DEF_JH')
INSERT INTO dbo.aircraft_performance_profiles 
(aircraft_icao, climb_rate_fpm, climb_speed_kias, climb_speed_mach, cruise_speed_ktas, cruise_mach, descent_rate_fpm, descent_speed_kias, optimal_fl, weight_class, engine_type, source, created_utc)
VALUES ('_DEF_JH', 2200, 280, 0.82, 480, 0.84, 2200, 280, 380, 'H', 'JET', 'DEFAULT', SYSUTCDATETIME());

-- Large Jet (B737, A320 family, B757)
IF NOT EXISTS (SELECT 1 FROM dbo.aircraft_performance_profiles WHERE aircraft_icao = '_DEF_JL')
INSERT INTO dbo.aircraft_performance_profiles 
(aircraft_icao, climb_rate_fpm, climb_speed_kias, climb_speed_mach, cruise_speed_ktas, cruise_mach, descent_rate_fpm, descent_speed_kias, optimal_fl, weight_class, engine_type, source, created_utc)
VALUES ('_DEF_JL', 2500, 280, 0.78, 450, 0.78, 2500, 280, 370, 'L', 'JET', 'DEFAULT', SYSUTCDATETIME());

-- Small Jet (CRJ, ERJ, Citation, Learjet)
IF NOT EXISTS (SELECT 1 FROM dbo.aircraft_performance_profiles WHERE aircraft_icao = '_DEF_JS')
INSERT INTO dbo.aircraft_performance_profiles 
(aircraft_icao, climb_rate_fpm, climb_speed_kias, climb_speed_mach, cruise_speed_ktas, cruise_mach, descent_rate_fpm, descent_speed_kias, optimal_fl, weight_class, engine_type, source, created_utc)
VALUES ('_DEF_JS', 3000, 250, 0.74, 420, 0.75, 2800, 250, 350, 'S', 'JET', 'DEFAULT', SYSUTCDATETIME());

-- Turboprop (ATR, Dash 8, King Air)
IF NOT EXISTS (SELECT 1 FROM dbo.aircraft_performance_profiles WHERE aircraft_icao = '_DEF_TP')
INSERT INTO dbo.aircraft_performance_profiles 
(aircraft_icao, climb_rate_fpm, climb_speed_kias, climb_speed_mach, cruise_speed_ktas, cruise_mach, descent_rate_fpm, descent_speed_kias, optimal_fl, weight_class, engine_type, source, created_utc)
VALUES ('_DEF_TP', 1500, 180, NULL, 280, NULL, 1800, 200, 250, 'S', 'TURBOPROP', 'DEFAULT', SYSUTCDATETIME());

-- Piston (C172, PA28, Bonanza)
IF NOT EXISTS (SELECT 1 FROM dbo.aircraft_performance_profiles WHERE aircraft_icao = '_DEF_PS')
INSERT INTO dbo.aircraft_performance_profiles 
(aircraft_icao, climb_rate_fpm, climb_speed_kias, climb_speed_mach, cruise_speed_ktas, cruise_mach, descent_rate_fpm, descent_speed_kias, optimal_fl, weight_class, engine_type, source, created_utc)
VALUES ('_DEF_PS', 700, 90, NULL, 120, NULL, 800, 100, 80, 'S', 'PISTON', 'DEFAULT', SYSUTCDATETIME());

-- Helicopter
IF NOT EXISTS (SELECT 1 FROM dbo.aircraft_performance_profiles WHERE aircraft_icao = '_DEF_HE')
INSERT INTO dbo.aircraft_performance_profiles 
(aircraft_icao, climb_rate_fpm, climb_speed_kias, climb_speed_mach, cruise_speed_ktas, cruise_mach, descent_rate_fpm, descent_speed_kias, optimal_fl, weight_class, engine_type, source, created_utc)
VALUES ('_DEF_HE', 1000, 80, NULL, 130, NULL, 1200, 80, 50, 'S', 'HELO', 'DEFAULT', SYSUTCDATETIME());

PRINT 'Default profiles inserted.';
GO

-- ============================================================================
-- STEP 3: Insert SPECIFIC aircraft profiles
-- Common VATSIM aircraft with realistic performance data
-- ============================================================================

PRINT 'Inserting specific aircraft profiles...';

-- Use MERGE for upsert behavior
MERGE dbo.aircraft_performance_profiles AS target
USING (VALUES
    -- ========================================================================
    -- NARROW-BODY JETS (Large)
    -- ========================================================================
    
    -- Boeing 737 Family
    ('B738', 2800, 280, 0.78, 453, 0.785, 2500, 280, 370, 'L', 'JET', 'SEED'),  -- 737-800
    ('B739', 2600, 280, 0.78, 453, 0.785, 2500, 280, 370, 'L', 'JET', 'SEED'),  -- 737-900
    ('B737', 2800, 280, 0.78, 450, 0.78, 2500, 280, 370, 'L', 'JET', 'SEED'),   -- 737 generic
    ('B38M', 2800, 280, 0.78, 453, 0.79, 2500, 280, 390, 'L', 'JET', 'SEED'),   -- 737 MAX 8
    ('B39M', 2600, 280, 0.78, 453, 0.79, 2500, 280, 390, 'L', 'JET', 'SEED'),   -- 737 MAX 9
    ('B736', 3000, 280, 0.78, 453, 0.785, 2600, 280, 370, 'L', 'JET', 'SEED'),  -- 737-600
    ('B733', 2800, 280, 0.74, 430, 0.745, 2500, 280, 350, 'L', 'JET', 'SEED'),  -- 737-300
    
    -- Airbus A320 Family  
    ('A320', 2500, 280, 0.78, 454, 0.78, 2400, 280, 390, 'L', 'JET', 'SEED'),   -- A320
    ('A321', 2200, 280, 0.78, 454, 0.78, 2300, 280, 390, 'L', 'JET', 'SEED'),   -- A321
    ('A319', 2800, 280, 0.78, 454, 0.78, 2500, 280, 390, 'L', 'JET', 'SEED'),   -- A319
    ('A318', 3000, 280, 0.78, 454, 0.78, 2600, 280, 390, 'L', 'JET', 'SEED'),   -- A318
    ('A20N', 2600, 280, 0.78, 454, 0.78, 2400, 280, 410, 'L', 'JET', 'SEED'),   -- A320neo
    ('A21N', 2400, 280, 0.78, 454, 0.78, 2300, 280, 410, 'L', 'JET', 'SEED'),   -- A321neo
    
    -- Boeing 757
    ('B752', 2500, 290, 0.80, 461, 0.80, 2400, 290, 390, 'L', 'JET', 'SEED'),   -- 757-200
    ('B753', 2300, 290, 0.80, 461, 0.80, 2300, 290, 390, 'L', 'JET', 'SEED'),   -- 757-300
    
    -- ========================================================================
    -- WIDE-BODY JETS (Heavy)
    -- ========================================================================
    
    -- Boeing 777 Family
    ('B77W', 2000, 290, 0.84, 490, 0.84, 2200, 290, 410, 'H', 'JET', 'SEED'),   -- 777-300ER
    ('B772', 2200, 290, 0.84, 490, 0.84, 2200, 290, 410, 'H', 'JET', 'SEED'),   -- 777-200
    ('B77L', 2100, 290, 0.84, 490, 0.84, 2200, 290, 410, 'H', 'JET', 'SEED'),   -- 777-200LR
    ('B778', 2100, 290, 0.85, 490, 0.85, 2200, 290, 430, 'H', 'JET', 'SEED'),   -- 777-8
    ('B779', 2000, 290, 0.85, 490, 0.85, 2200, 290, 430, 'H', 'JET', 'SEED'),   -- 777-9
    
    -- Boeing 787 Family
    ('B788', 2400, 290, 0.85, 487, 0.85, 2300, 290, 410, 'H', 'JET', 'SEED'),   -- 787-8
    ('B789', 2300, 290, 0.85, 487, 0.85, 2200, 290, 410, 'H', 'JET', 'SEED'),   -- 787-9
    ('B78X', 2200, 290, 0.85, 487, 0.85, 2200, 290, 410, 'H', 'JET', 'SEED'),   -- 787-10
    
    -- Boeing 767 Family
    ('B763', 2200, 280, 0.80, 459, 0.80, 2200, 280, 390, 'H', 'JET', 'SEED'),   -- 767-300
    ('B764', 2000, 280, 0.80, 459, 0.80, 2100, 280, 390, 'H', 'JET', 'SEED'),   -- 767-400
    ('B762', 2400, 280, 0.80, 459, 0.80, 2300, 280, 390, 'H', 'JET', 'SEED'),   -- 767-200
    
    -- Airbus A330 Family
    ('A332', 2200, 290, 0.82, 470, 0.82, 2200, 290, 390, 'H', 'JET', 'SEED'),   -- A330-200
    ('A333', 2000, 290, 0.82, 470, 0.82, 2100, 290, 390, 'H', 'JET', 'SEED'),   -- A330-300
    ('A339', 2300, 290, 0.82, 470, 0.82, 2200, 290, 410, 'H', 'JET', 'SEED'),   -- A330-900neo
    
    -- Airbus A350 Family
    ('A359', 2400, 290, 0.85, 488, 0.85, 2300, 290, 410, 'H', 'JET', 'SEED'),   -- A350-900
    ('A35K', 2200, 290, 0.85, 488, 0.85, 2200, 290, 410, 'H', 'JET', 'SEED'),   -- A350-1000
    
    -- ========================================================================
    -- SUPER/JUMBO JETS
    -- ========================================================================
    
    -- Boeing 747
    ('B744', 1800, 290, 0.85, 490, 0.855, 2000, 290, 390, 'J', 'JET', 'SEED'),  -- 747-400
    ('B748', 2000, 290, 0.86, 493, 0.86, 2100, 290, 410, 'J', 'JET', 'SEED'),   -- 747-8
    ('B741', 1600, 280, 0.84, 480, 0.84, 1900, 280, 370, 'J', 'JET', 'SEED'),   -- 747-100
    ('B742', 1700, 280, 0.84, 480, 0.84, 1900, 280, 370, 'J', 'JET', 'SEED'),   -- 747-200
    
    -- Airbus A380
    ('A388', 1600, 300, 0.85, 488, 0.85, 1800, 300, 410, 'J', 'JET', 'SEED'),   -- A380-800
    
    -- ========================================================================
    -- REGIONAL JETS (Small)
    -- ========================================================================
    
    -- Bombardier CRJ
    ('CRJ2', 3200, 250, 0.74, 424, 0.74, 3000, 250, 370, 'S', 'JET', 'SEED'),   -- CRJ-200
    ('CRJ7', 2800, 250, 0.78, 447, 0.78, 2800, 250, 370, 'S', 'JET', 'SEED'),   -- CRJ-700
    ('CRJ9', 2600, 250, 0.78, 447, 0.78, 2700, 250, 370, 'S', 'JET', 'SEED'),   -- CRJ-900
    ('CRJX', 2500, 250, 0.78, 447, 0.78, 2600, 250, 370, 'S', 'JET', 'SEED'),   -- CRJ-1000
    
    -- Embraer E-Jets
    ('E170', 3000, 250, 0.78, 435, 0.78, 2800, 250, 370, 'S', 'JET', 'SEED'),   -- E170
    ('E175', 2900, 250, 0.78, 435, 0.78, 2700, 250, 370, 'S', 'JET', 'SEED'),   -- E175
    ('E190', 2700, 250, 0.78, 447, 0.78, 2600, 250, 390, 'L', 'JET', 'SEED'),   -- E190
    ('E195', 2500, 250, 0.78, 447, 0.78, 2500, 250, 390, 'L', 'JET', 'SEED'),   -- E195
    ('E290', 2800, 250, 0.78, 450, 0.78, 2600, 250, 410, 'L', 'JET', 'SEED'),   -- E190-E2
    ('E295', 2600, 250, 0.78, 450, 0.78, 2500, 250, 410, 'L', 'JET', 'SEED'),   -- E195-E2
    
    -- ERJ 145 Family
    ('E145', 3200, 250, 0.72, 410, 0.72, 3000, 250, 350, 'S', 'JET', 'SEED'),   -- ERJ-145
    ('E135', 3400, 250, 0.72, 410, 0.72, 3100, 250, 350, 'S', 'JET', 'SEED'),   -- ERJ-135
    ('E140', 3300, 250, 0.72, 410, 0.72, 3100, 250, 350, 'S', 'JET', 'SEED'),   -- ERJ-140
    
    -- ========================================================================
    -- BUSINESS JETS
    -- ========================================================================
    
    ('C56X', 3800, 250, 0.75, 430, 0.75, 3500, 250, 450, 'S', 'JET', 'SEED'),   -- Citation Excel
    ('C560', 3600, 240, 0.72, 400, 0.72, 3400, 240, 430, 'S', 'JET', 'SEED'),   -- Citation V
    ('C680', 3500, 260, 0.80, 450, 0.80, 3300, 260, 450, 'S', 'JET', 'SEED'),   -- Citation Sovereign
    ('C750', 3400, 270, 0.82, 460, 0.82, 3200, 270, 470, 'S', 'JET', 'SEED'),   -- Citation X
    ('CL35', 3800, 260, 0.80, 450, 0.80, 3500, 260, 450, 'S', 'JET', 'SEED'),   -- Challenger 350
    ('CL60', 3500, 270, 0.80, 460, 0.80, 3300, 270, 450, 'S', 'JET', 'SEED'),   -- Challenger 604
    ('GL5T', 3200, 280, 0.85, 480, 0.85, 3000, 280, 470, 'L', 'JET', 'SEED'),   -- Global 5000
    ('GLEX', 3000, 290, 0.88, 490, 0.88, 2800, 290, 470, 'L', 'JET', 'SEED'),   -- Global Express
    ('G280', 4000, 250, 0.80, 450, 0.80, 3600, 250, 450, 'S', 'JET', 'SEED'),   -- Gulfstream G280
    ('GLF4', 3600, 260, 0.80, 460, 0.80, 3400, 260, 450, 'S', 'JET', 'SEED'),   -- Gulfstream IV
    ('GLF5', 3400, 270, 0.85, 480, 0.85, 3200, 270, 470, 'L', 'JET', 'SEED'),   -- Gulfstream V
    ('G550', 3400, 270, 0.85, 480, 0.85, 3200, 270, 510, 'L', 'JET', 'SEED'),   -- Gulfstream G550
    ('G650', 3200, 280, 0.90, 505, 0.90, 3000, 280, 510, 'L', 'JET', 'SEED'),   -- Gulfstream G650
    ('LJ45', 4200, 240, 0.78, 430, 0.78, 3800, 240, 450, 'S', 'JET', 'SEED'),   -- Learjet 45
    ('LJ60', 3800, 250, 0.78, 430, 0.78, 3600, 250, 450, 'S', 'JET', 'SEED'),   -- Learjet 60
    ('FA50', 3500, 250, 0.75, 420, 0.75, 3200, 250, 430, 'S', 'JET', 'SEED'),   -- Falcon 50
    ('F900', 3200, 260, 0.80, 460, 0.80, 3000, 260, 450, 'S', 'JET', 'SEED'),   -- Falcon 900
    ('FA7X', 3400, 270, 0.85, 475, 0.85, 3200, 270, 470, 'L', 'JET', 'SEED'),   -- Falcon 7X
    ('PC24', 3600, 230, 0.74, 380, 0.74, 3400, 230, 450, 'S', 'JET', 'SEED'),   -- Pilatus PC-24
    
    -- ========================================================================
    -- TURBOPROPS
    -- ========================================================================
    
    -- ATR Family
    ('AT43', 1300, 170, NULL, 265, NULL, 1500, 180, 230, 'S', 'TURBOPROP', 'SEED'),  -- ATR 42-300
    ('AT45', 1400, 170, NULL, 265, NULL, 1500, 180, 230, 'S', 'TURBOPROP', 'SEED'),  -- ATR 42-500
    ('AT72', 1200, 170, NULL, 276, NULL, 1400, 180, 250, 'S', 'TURBOPROP', 'SEED'),  -- ATR 72
    ('AT76', 1300, 170, NULL, 276, NULL, 1400, 180, 250, 'S', 'TURBOPROP', 'SEED'),  -- ATR 72-600
    
    -- De Havilland Canada
    ('DH8A', 1500, 170, NULL, 250, NULL, 1600, 180, 200, 'S', 'TURBOPROP', 'SEED'),  -- Dash 8-100
    ('DH8B', 1400, 170, NULL, 250, NULL, 1500, 180, 200, 'S', 'TURBOPROP', 'SEED'),  -- Dash 8-200
    ('DH8C', 1300, 180, NULL, 285, NULL, 1500, 190, 250, 'S', 'TURBOPROP', 'SEED'),  -- Dash 8-300
    ('DH8D', 1200, 180, NULL, 310, NULL, 1400, 190, 270, 'L', 'TURBOPROP', 'SEED'),  -- Dash 8-400
    
    -- Beechcraft King Air
    ('BE20', 1800, 160, NULL, 270, NULL, 2000, 170, 280, 'S', 'TURBOPROP', 'SEED'),  -- King Air 200
    ('BE9L', 1600, 150, NULL, 250, NULL, 1800, 160, 270, 'S', 'TURBOPROP', 'SEED'),  -- King Air 90
    ('B350', 1900, 170, NULL, 312, NULL, 2100, 180, 350, 'S', 'TURBOPROP', 'SEED'),  -- King Air 350
    
    -- Other Turboprops
    ('PC12', 1500, 150, NULL, 280, NULL, 1800, 160, 300, 'S', 'TURBOPROP', 'SEED'),  -- Pilatus PC-12
    ('TBM9', 1800, 160, NULL, 330, NULL, 2000, 170, 310, 'S', 'TURBOPROP', 'SEED'),  -- TBM 900/930
    ('TBM8', 1700, 160, NULL, 320, NULL, 1900, 170, 300, 'S', 'TURBOPROP', 'SEED'),  -- TBM 850
    ('C208', 1000, 120, NULL, 175, NULL, 1200, 130, 250, 'S', 'TURBOPROP', 'SEED'),  -- Cessna Caravan
    ('SW4', 1400, 160, NULL, 265, NULL, 1600, 170, 270, 'S', 'TURBOPROP', 'SEED'),   -- Swearingen Metroliner
    ('SF34', 1200, 170, NULL, 260, NULL, 1400, 180, 250, 'S', 'TURBOPROP', 'SEED'),  -- Saab 340
    
    -- ========================================================================
    -- PISTON AIRCRAFT
    -- ========================================================================
    
    -- Cessna Singles
    ('C172', 700, 85, NULL, 122, NULL, 800, 95, 80, 'S', 'PISTON', 'SEED'),    -- Cessna 172
    ('C182', 900, 95, NULL, 145, NULL, 1000, 105, 100, 'S', 'PISTON', 'SEED'),  -- Cessna 182
    ('C152', 650, 80, NULL, 107, NULL, 750, 90, 60, 'S', 'PISTON', 'SEED'),     -- Cessna 152
    ('C206', 900, 100, NULL, 150, NULL, 1000, 110, 100, 'S', 'PISTON', 'SEED'),  -- Cessna 206
    ('C210', 1000, 105, NULL, 175, NULL, 1100, 115, 120, 'S', 'PISTON', 'SEED'), -- Cessna 210
    
    -- Piper Singles
    ('PA28', 700, 85, NULL, 125, NULL, 800, 95, 80, 'S', 'PISTON', 'SEED'),     -- Piper Cherokee
    ('PA32', 900, 100, NULL, 150, NULL, 1000, 110, 100, 'S', 'PISTON', 'SEED'),  -- Piper Saratoga
    ('PA46', 1200, 130, NULL, 210, NULL, 1400, 140, 180, 'S', 'PISTON', 'SEED'), -- Piper Malibu
    ('P28A', 700, 85, NULL, 125, NULL, 800, 95, 80, 'S', 'PISTON', 'SEED'),      -- PA-28 variant
    
    -- Beechcraft
    ('BE36', 1100, 110, NULL, 180, NULL, 1200, 120, 140, 'S', 'PISTON', 'SEED'), -- Bonanza
    ('BE58', 1300, 120, NULL, 195, NULL, 1400, 130, 150, 'S', 'PISTON', 'SEED'), -- Baron
    
    -- Twins
    ('C310', 1300, 130, NULL, 195, NULL, 1500, 140, 150, 'S', 'PISTON', 'SEED'), -- Cessna 310
    ('C414', 1500, 140, NULL, 230, NULL, 1700, 150, 200, 'S', 'PISTON', 'SEED'), -- Cessna 414
    ('PA34', 1100, 115, NULL, 170, NULL, 1300, 125, 130, 'S', 'PISTON', 'SEED'), -- Piper Seneca
    
    -- ========================================================================
    -- MILITARY/SPECIAL (popular on VATSIM)
    -- ========================================================================
    
    ('F16', 20000, 350, 0.90, 500, 0.90, 15000, 350, 400, 'S', 'JET', 'SEED'),   -- F-16
    ('F18', 18000, 350, 0.90, 490, 0.90, 14000, 350, 400, 'S', 'JET', 'SEED'),   -- F/A-18
    ('F15', 22000, 360, 0.95, 520, 0.95, 16000, 360, 450, 'S', 'JET', 'SEED'),   -- F-15
    ('C130', 1800, 200, NULL, 290, 0.50, 2000, 220, 280, 'H', 'TURBOPROP', 'SEED'), -- C-130
    ('C17', 2800, 280, 0.77, 450, 0.77, 2600, 280, 350, 'H', 'JET', 'SEED'),     -- C-17
    ('KC10', 2000, 280, 0.82, 460, 0.82, 2200, 280, 380, 'H', 'JET', 'SEED'),    -- KC-10
    ('E3CF', 1800, 280, 0.76, 440, 0.76, 2000, 280, 350, 'H', 'JET', 'SEED'),    -- E-3 Sentry
    ('A10', 3000, 220, NULL, 340, NULL, 4000, 220, 200, 'S', 'JET', 'SEED')      -- A-10
    
) AS source (aircraft_icao, climb_rate_fpm, climb_speed_kias, climb_speed_mach, cruise_speed_ktas, cruise_mach, descent_rate_fpm, descent_speed_kias, optimal_fl, weight_class, engine_type, source)
ON target.aircraft_icao = source.aircraft_icao
WHEN MATCHED AND target.source = 'SEED' THEN
    UPDATE SET 
        climb_rate_fpm = source.climb_rate_fpm,
        climb_speed_kias = source.climb_speed_kias,
        climb_speed_mach = source.climb_speed_mach,
        cruise_speed_ktas = source.cruise_speed_ktas,
        cruise_mach = source.cruise_mach,
        descent_rate_fpm = source.descent_rate_fpm,
        descent_speed_kias = source.descent_speed_kias,
        optimal_fl = source.optimal_fl,
        weight_class = source.weight_class,
        engine_type = source.engine_type,
        created_utc = SYSUTCDATETIME()
WHEN NOT MATCHED THEN
    INSERT (aircraft_icao, climb_rate_fpm, climb_speed_kias, climb_speed_mach, cruise_speed_ktas, cruise_mach, descent_rate_fpm, descent_speed_kias, optimal_fl, weight_class, engine_type, source, created_utc)
    VALUES (source.aircraft_icao, source.climb_rate_fpm, source.climb_speed_kias, source.climb_speed_mach, source.cruise_speed_ktas, source.cruise_mach, source.descent_rate_fpm, source.descent_speed_kias, source.optimal_fl, source.weight_class, source.engine_type, source.source, SYSUTCDATETIME());

PRINT 'Specific aircraft profiles inserted/updated.';
GO

-- ============================================================================
-- STEP 4: Verification
-- ============================================================================

PRINT '';
PRINT '=== Verification ===';

SELECT 
    source,
    COUNT(*) AS profile_count,
    AVG(cruise_speed_ktas) AS avg_cruise_kts
FROM dbo.aircraft_performance_profiles
GROUP BY source
ORDER BY source;

PRINT '';
PRINT 'Top 20 profiles by cruise speed:';

SELECT TOP 20 
    aircraft_icao,
    cruise_speed_ktas,
    cruise_mach,
    weight_class,
    engine_type,
    source
FROM dbo.aircraft_performance_profiles
ORDER BY cruise_speed_ktas DESC;

PRINT '';
PRINT '=== Aircraft Performance Seed Complete ===';
PRINT 'Timestamp: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
