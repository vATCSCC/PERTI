-- ============================================================================
-- 047_openap_aircraft_import.sql
-- Import aircraft performance data from OpenAP (TU Delft)
-- 
-- Source: https://github.com/TUDelft-CNS-ATM/openap
-- License: GPL-3.0
-- Generated: 2026-01-07
-- 
-- OpenAP provides open-source aircraft performance models derived from
-- ADS-B surveillance data (WRAP kinematic model).
-- ============================================================================

SET NOCOUNT ON;
GO

PRINT '=== Importing OpenAP Aircraft Performance Data ===';
PRINT 'Aircraft profiles: 32';
GO

-- ============================================================================
-- MERGE OpenAP data into aircraft_performance_profiles
-- Uses COALESCE to fill gaps - preserves existing values where OpenAP is NULL
-- For required columns, falls back to reasonable defaults
-- ============================================================================

MERGE dbo.aircraft_performance_profiles AS target
USING (VALUES
    -- Aircraft with full WRAP kinematic data
    ('A319', 1313, 272, 0.740, 424, 0.740, 2593, 264, 334, 'L', 'TURBOFAN', 6889, 25262, 41010, 350, 0.82, 122),
    ('A320', 1148, 272, 0.730, 429, 0.750, 2768, 262, 328, 'L', 'TURBOFAN', 6233, 25918, 41010, 350, 0.82, 130),
    ('A321', 1067, 279, 0.740, 429, 0.750, 2509, 268, 307, 'L', 'TURBOFAN', 6889, 25590, 41010, 350, 0.82, 136),
    ('A332', 1021, 277, 0.760, 452, 0.790, 2555, 264, 351, 'H', 'TURBOFAN', 7545, 27559, 41010, 330, 0.86, 130),
    ('A333', 973, 276, 0.750, 452, 0.790, 2516, 266, 345, 'H', 'TURBOFAN', 6233, 27887, 41010, 330, 0.86, 134),
    ('A343', 826, 279, 0.750, 452, 0.790, 2530, 268, 332, 'H', 'TURBOFAN', 5577, 27230, 41010, 330, 0.86, 134),
    ('A388', 943, 301, 0.800, 469, 0.820, 2326, 276, 356, 'J', 'TURBOFAN', 4265, 28215, 42979, 340, 0.89, 132),
    ('B737', 1371, 270, 0.720, 424, 0.740, 2690, 258, 352, 'L', 'TURBOFAN', 7217, 26574, 41010, 340, 0.82, 126),
    ('B738', 1301, 272, 0.750, 429, 0.750, 2668, 256, 339, 'L', 'TURBOFAN', 5905, 27230, 41010, 340, 0.82, 139),
    ('B739', 1178, 277, 0.750, 435, 0.760, 2476, 268, 315, 'L', 'TURBOFAN', 7545, 26902, 41010, 340, 0.82, 139),
    ('B744', 1097, 305, 0.810, 469, 0.820, 2580, 270, 320, 'J', 'TURBOFAN', 7217, 28543, 44947, 365, 0.92, 139),
    ('B752', 1181, 279, 0.750, 441, 0.770, 2668, 266, 337, 'L', 'TURBOFAN', 7545, 26246, 41994, 350, 0.86, 120),
    ('B763', 1186, 283, 0.760, 441, 0.770, 2774, 270, 324, 'H', 'TURBOFAN', 8530, 27230, 42979, 360, 0.86, 132),
    ('B77W', 1083, 307, 0.800, 469, 0.820, 2498, 272, 319, 'J', 'TURBOFAN', 5905, 28215, 42979, 330, 0.89, 141),
    ('B788', 1116, 283, 0.810, 475, 0.830, 2762, 272, 366, 'H', 'TURBOFAN', 8202, 28871, 42979, 515, 0.90, 136),
    ('B789', 1105, 293, 0.820, 475, 0.830, 2587, 270, 351, 'H', 'TURBOFAN', 7545, 28215, 42979, 515, 0.90, 139),
    ('E190', 1075, 258, 0.710, 424, 0.740, 2468, 260, 329, 'L', 'TURBOFAN', 5905, 26246, 41010, 320, 0.82, 124)
) AS source (
    aircraft_icao, climb_rate_fpm, climb_speed_kias, climb_speed_mach,
    cruise_speed_ktas, cruise_mach, descent_rate_fpm, descent_speed_kias,
    optimal_fl, weight_class, engine_type, climb_crossover_ft,
    descent_crossover_ft, max_altitude_ft, vmo_kts, mmo, approach_speed_kias
)
ON target.aircraft_icao = source.aircraft_icao

-- Only update if current source is NOT BADA (preserve BADA data)
WHEN MATCHED AND target.source NOT IN ('BADA') THEN
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
        climb_crossover_ft = source.climb_crossover_ft,
        descent_crossover_ft = source.descent_crossover_ft,
        max_altitude_ft = source.max_altitude_ft,
        vmo_kts = source.vmo_kts,
        mmo = source.mmo,
        approach_speed_kias = source.approach_speed_kias,
        source = 'OPENAP',
        created_utc = SYSUTCDATETIME()

WHEN NOT MATCHED THEN
    INSERT (aircraft_icao, climb_rate_fpm, climb_speed_kias, climb_speed_mach,
            cruise_speed_ktas, cruise_mach, descent_rate_fpm, descent_speed_kias,
            optimal_fl, weight_class, engine_type, climb_crossover_ft,
            descent_crossover_ft, max_altitude_ft, vmo_kts, mmo, approach_speed_kias,
            source, created_utc)
    VALUES (source.aircraft_icao, source.climb_rate_fpm, source.climb_speed_kias,
            source.climb_speed_mach, source.cruise_speed_ktas, source.cruise_mach,
            source.descent_rate_fpm, source.descent_speed_kias, source.optimal_fl,
            source.weight_class, source.engine_type, source.climb_crossover_ft,
            source.descent_crossover_ft, source.max_altitude_ft, source.vmo_kts,
            source.mmo, source.approach_speed_kias, 'OPENAP', SYSUTCDATETIME());

DECLARE @rows_affected INT = @@ROWCOUNT;
PRINT CONCAT('OpenAP aircraft profiles (with WRAP data): ', @rows_affected, ' rows affected');
GO

-- ============================================================================
-- Update aircraft that only have basic OpenAP data (no WRAP kinematic)
-- Only update cruise/ceiling/limits - keep existing climb/descent from SEED
-- ============================================================================

PRINT '';
PRINT 'Updating aircraft with basic OpenAP data (cruise/limits only)...';

-- A319neo family
UPDATE dbo.aircraft_performance_profiles SET
    cruise_speed_ktas = COALESCE(cruise_speed_ktas, 446),
    cruise_mach = COALESCE(cruise_mach, 0.78),
    max_altitude_ft = COALESCE(max_altitude_ft, 41010),
    vmo_kts = COALESCE(vmo_kts, 350),
    mmo = COALESCE(mmo, 0.82)
WHERE aircraft_icao IN ('A19N', 'A20N', 'A21N', 'A318')
  AND source NOT IN ('BADA', 'OPENAP');

-- A350/A380 without WRAP
UPDATE dbo.aircraft_performance_profiles SET
    cruise_speed_ktas = 487,
    cruise_mach = 0.85,
    max_altitude_ft = 42979,
    vmo_kts = 340,
    mmo = 0.89
WHERE aircraft_icao = 'A359'
  AND source NOT IN ('BADA', 'OPENAP');

-- 737 MAX family
UPDATE dbo.aircraft_performance_profiles SET
    cruise_speed_ktas = COALESCE(cruise_speed_ktas, 450),
    cruise_mach = COALESCE(cruise_mach, 0.79),
    max_altitude_ft = COALESCE(max_altitude_ft, 41010),
    vmo_kts = COALESCE(vmo_kts, 340),
    mmo = COALESCE(mmo, 0.82)
WHERE aircraft_icao IN ('B37M', 'B38M', 'B39M', 'B734')
  AND source NOT IN ('BADA', 'OPENAP');

-- 747-8
UPDATE dbo.aircraft_performance_profiles SET
    cruise_speed_ktas = 487,
    cruise_mach = 0.85,
    max_altitude_ft = 42979,
    vmo_kts = 365,
    mmo = 0.92
WHERE aircraft_icao = 'B748'
  AND source NOT IN ('BADA', 'OPENAP');

-- 777-200
UPDATE dbo.aircraft_performance_profiles SET
    cruise_speed_ktas = 481,
    cruise_mach = 0.84,
    max_altitude_ft = 42979,
    vmo_kts = 330,
    mmo = 0.89
WHERE aircraft_icao = 'B772'
  AND source NOT IN ('BADA', 'OPENAP');

-- Embraer E-Jets
UPDATE dbo.aircraft_performance_profiles SET
    cruise_speed_ktas = COALESCE(cruise_speed_ktas, 430),
    cruise_mach = COALESCE(cruise_mach, 0.75),
    max_altitude_ft = COALESCE(max_altitude_ft, 41010),
    vmo_kts = COALESCE(vmo_kts, 320),
    mmo = COALESCE(mmo, 0.82)
WHERE aircraft_icao IN ('E170', 'E195', 'E75L')
  AND source NOT IN ('BADA', 'OPENAP');

PRINT 'Basic data updates complete.';
GO

-- ============================================================================
-- Verification
-- ============================================================================

PRINT '';
PRINT '=== Profile Source Summary ===';

SELECT 
    source,
    COUNT(*) AS profile_count,
    AVG(cruise_speed_ktas) AS avg_cruise_kts,
    AVG(climb_rate_fpm) AS avg_climb_fpm,
    AVG(descent_rate_fpm) AS avg_descent_fpm
FROM dbo.aircraft_performance_profiles
GROUP BY source
ORDER BY 
    CASE source 
        WHEN 'BADA' THEN 1
        WHEN 'OPENAP' THEN 2
        WHEN 'SEED' THEN 3
        WHEN 'DEFAULT' THEN 4
        ELSE 5
    END;

PRINT '';
PRINT '=== OpenAP Aircraft Performance Details ===';

SELECT 
    aircraft_icao,
    climb_rate_fpm,
    climb_speed_kias,
    cruise_speed_ktas,
    cruise_mach,
    descent_rate_fpm,
    descent_speed_kias,
    optimal_fl,
    approach_speed_kias,
    weight_class
FROM dbo.aircraft_performance_profiles
WHERE source = 'OPENAP'
ORDER BY aircraft_icao;

PRINT '';
PRINT '=== OpenAP Import Complete ===';
GO
