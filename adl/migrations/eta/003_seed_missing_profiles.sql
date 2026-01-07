-- Quick fix: Insert missing aircraft profiles (skip existing)
-- Run this to complete the seed data

-- First ensure engine_type is wide enough
ALTER TABLE dbo.aircraft_performance_profiles ALTER COLUMN engine_type NVARCHAR(16) NULL;
GO

-- Insert only if not exists
INSERT INTO dbo.aircraft_performance_profiles 
    (aircraft_icao, climb_rate_fpm, climb_speed_kias, cruise_speed_ktas, cruise_mach, descent_rate_fpm, weight_class, engine_type, source)
SELECT v.aircraft_icao, v.climb_rate_fpm, v.climb_speed_kias, v.cruise_speed_ktas, v.cruise_mach, v.descent_rate_fpm, v.weight_class, v.engine_type, v.source
FROM (VALUES
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
    ('AT76', 1200, 180, 280, NULL, 1200, 'L', 'TURBOPROP', 'ESTIMATED')
) AS v(aircraft_icao, climb_rate_fpm, climb_speed_kias, cruise_speed_ktas, cruise_mach, descent_rate_fpm, weight_class, engine_type, source)
WHERE NOT EXISTS (SELECT 1 FROM dbo.aircraft_performance_profiles WHERE aircraft_icao = v.aircraft_icao);

PRINT 'Inserted ' + CAST(@@ROWCOUNT AS VARCHAR) + ' missing aircraft profiles';

-- Show current count
SELECT COUNT(*) AS total_profiles FROM dbo.aircraft_performance_profiles;
GO
