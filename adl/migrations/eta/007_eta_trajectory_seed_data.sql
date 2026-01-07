-- ============================================================================
-- ADL Migration 031: ETA & Trajectory Seed Data
-- 
-- Seeds:
-- 1. FIR boundaries (covered regions)
-- 2. Aircraft performance profiles (defaults + common types)
-- 
-- Run Order: After 030_eta_trajectory_schema.sql
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Migration 031: ETA & Trajectory Seed Data ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. SEED fir_boundaries - Covered Region FIRs
-- ============================================================================

PRINT 'Seeding fir_boundaries...';

-- Clear existing seed data
DELETE FROM dbo.fir_boundaries WHERE source = 'SEED';

-- US Domestic ARTCCs
INSERT INTO dbo.fir_boundaries (fir_icao, fir_name, fir_type, is_covered_region, is_us_ca_oceanic, source) VALUES
    ('KZAB', 'Albuquerque Center', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZAU', 'Chicago Center', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZBW', 'Boston Center', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZDC', 'Washington Center', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZDV', 'Denver Center', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZFW', 'Fort Worth Center', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZHU', 'Houston Center', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZID', 'Indianapolis Center', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZJX', 'Jacksonville Center', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZKC', 'Kansas City Center', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZLA', 'Los Angeles Center', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZLC', 'Salt Lake City Center', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZMA', 'Miami Center', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZME', 'Memphis Center', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZMP', 'Minneapolis Center', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZNY', 'New York Center', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZOA', 'Oakland Center', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZOB', 'Cleveland Center', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZSE', 'Seattle Center', 'DOMESTIC', 1, 0, 'SEED'),
    ('KZTL', 'Atlanta Center', 'DOMESTIC', 1, 0, 'SEED'),
    ('PZAN', 'Anchorage Center', 'DOMESTIC', 1, 0, 'SEED'),
    ('PHZH', 'Honolulu Control Facility', 'DOMESTIC', 1, 0, 'SEED');

-- US Oceanic
INSERT INTO dbo.fir_boundaries (fir_icao, fir_name, fir_type, is_covered_region, is_us_ca_oceanic, source) VALUES
    ('KZAK', 'Oakland Oceanic', 'OCEANIC', 1, 1, 'SEED'),
    ('KZWY', 'New York Oceanic East', 'OCEANIC', 1, 1, 'SEED'),
    ('KZMA', 'Miami Oceanic', 'OCEANIC', 1, 1, 'SEED'),
    ('KZHU', 'Houston Oceanic', 'OCEANIC', 1, 1, 'SEED'),
    ('PAZA', 'Anchorage Oceanic', 'OCEANIC', 1, 1, 'SEED');

-- Canadian FIRs
INSERT INTO dbo.fir_boundaries (fir_icao, fir_name, fir_type, is_covered_region, is_us_ca_oceanic, source) VALUES
    ('CZEG', 'Edmonton FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('CZUL', 'Montreal FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('CZWG', 'Winnipeg FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('CZVR', 'Vancouver FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('CZYZ', 'Toronto FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('CZQM', 'Moncton FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('CZQX', 'Gander Oceanic', 'OCEANIC', 1, 1, 'SEED');

-- Mexico
INSERT INTO dbo.fir_boundaries (fir_icao, fir_name, fir_type, is_covered_region, is_us_ca_oceanic, source) VALUES
    ('MMFR', 'Mexico FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('MMFO', 'Mazatlan Oceanic', 'OCEANIC', 1, 0, 'SEED');

-- Caribbean
INSERT INTO dbo.fir_boundaries (fir_icao, fir_name, fir_type, is_covered_region, is_us_ca_oceanic, source) VALUES
    ('TJZS', 'San Juan FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('MKJK', 'Kingston FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('MUFH', 'Havana FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('MDCS', 'Santo Domingo FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('TTZP', 'Piarco FIR', 'DOMESTIC', 1, 0, 'SEED');

-- Central America
INSERT INTO dbo.fir_boundaries (fir_icao, fir_name, fir_type, is_covered_region, is_us_ca_oceanic, source) VALUES
    ('MGGT', 'Guatemala FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('MHTG', 'Tegucigalpa FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('MNMG', 'Managua FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('MRPV', 'San Jose FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('MPZL', 'Panama FIR', 'DOMESTIC', 1, 0, 'SEED');

-- South America (major FIRs)
INSERT INTO dbo.fir_boundaries (fir_icao, fir_name, fir_type, is_covered_region, is_us_ca_oceanic, source) VALUES
    ('SKED', 'Bogota FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('SVZM', 'Maiquetia FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('SEFG', 'Guayaquil FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('SPIM', 'Lima FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('SLLF', 'La Paz FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('SGAS', 'Asuncion FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('SUEO', 'Montevideo FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('SAEF', 'Ezeiza FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('SACF', 'Cordoba FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('SAMF', 'Mendoza FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('SAVF', 'Comodoro Rivadavia FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('SCFZ', 'Santiago FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('SCTZ', 'Punta Arenas FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('SBAZ', 'Amazonica FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('SBBS', 'Brasilia FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('SBCW', 'Curitiba FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('SBRE', 'Recife FIR', 'DOMESTIC', 1, 0, 'SEED'),
    ('SBAO', 'Atlantico FIR', 'OCEANIC', 1, 0, 'SEED');

PRINT 'Seeded ' + CAST(@@ROWCOUNT AS VARCHAR) + ' FIR boundaries';
GO

-- ============================================================================
-- 2. SEED aircraft_performance_profiles - Default Profiles by Category
-- ============================================================================

PRINT 'Seeding aircraft_performance_profiles...';

-- Clear existing seed data
DELETE FROM dbo.aircraft_performance_profiles WHERE source = 'SEED';

-- Default profiles by category (used when specific aircraft not found)
INSERT INTO dbo.aircraft_performance_profiles 
    (aircraft_icao, climb_rate_fpm, climb_speed_kias, climb_speed_mach, cruise_speed_ktas, cruise_mach, descent_rate_fpm, descent_speed_kias, weight_class, engine_type, source) 
VALUES
    ('_DEF_JH', 2500, 290, 0.84, 490, 0.85, 2500, 290, 'H', 'JET', 'SEED'),      -- Heavy jet default
    ('_DEF_JL', 2200, 280, 0.78, 460, 0.80, 2200, 280, 'L', 'JET', 'SEED'),      -- Large jet default
    ('_DEF_JS', 2800, 250, 0.72, 420, 0.74, 2800, 260, 'S', 'JET', 'SEED'),      -- Small jet default
    ('_DEF_JJ', 1800, 300, 0.85, 500, 0.86, 1800, 300, 'J', 'JET', 'SEED'),      -- Super/Jumbo jet default
    ('_DEF_TP', 1500, 200, NULL, 300, NULL, 1500, 200, 'L', 'TURBOPROP', 'SEED'),-- Turboprop default
    ('_DEF_PS', 800, 120, NULL, 160, NULL, 800, 120, 'S', 'PISTON', 'SEED'),     -- Piston default
    ('_DEF_HE', 1200, 100, NULL, 140, NULL, 1200, 100, 'S', 'HELO', 'SEED');     -- Helicopter default

-- Common heavy jets
INSERT INTO dbo.aircraft_performance_profiles 
    (aircraft_icao, climb_rate_fpm, climb_speed_kias, climb_speed_mach, cruise_speed_ktas, cruise_mach, optimal_fl, descent_rate_fpm, descent_speed_kias, weight_class, engine_type, source) 
VALUES
    ('B744', 2000, 290, 0.84, 490, 0.855, 350, 2000, 290, 'H', 'JET', 'SEED'),
    ('B748', 2200, 290, 0.84, 495, 0.855, 370, 2200, 290, 'J', 'JET', 'SEED'),
    ('B772', 2200, 290, 0.84, 490, 0.840, 370, 2200, 290, 'H', 'JET', 'SEED'),
    ('B77L', 2200, 290, 0.84, 490, 0.840, 370, 2200, 290, 'H', 'JET', 'SEED'),
    ('B77W', 2400, 295, 0.85, 500, 0.850, 390, 2400, 290, 'H', 'JET', 'SEED'),
    ('B788', 2600, 290, 0.85, 490, 0.850, 410, 2600, 290, 'H', 'JET', 'SEED'),
    ('B789', 2500, 290, 0.85, 495, 0.850, 400, 2500, 290, 'H', 'JET', 'SEED'),
    ('B78X', 2500, 290, 0.85, 495, 0.850, 410, 2500, 290, 'H', 'JET', 'SEED'),
    ('A332', 2200, 290, 0.82, 480, 0.820, 370, 2200, 290, 'H', 'JET', 'SEED'),
    ('A333', 2200, 290, 0.82, 480, 0.820, 370, 2200, 290, 'H', 'JET', 'SEED'),
    ('A338', 2400, 290, 0.85, 490, 0.850, 390, 2400, 290, 'H', 'JET', 'SEED'),
    ('A339', 2400, 290, 0.85, 490, 0.850, 390, 2400, 290, 'H', 'JET', 'SEED'),
    ('A342', 2000, 290, 0.82, 480, 0.820, 350, 2000, 290, 'H', 'JET', 'SEED'),
    ('A343', 2000, 290, 0.82, 480, 0.820, 350, 2000, 290, 'H', 'JET', 'SEED'),
    ('A346', 2000, 290, 0.82, 485, 0.820, 350, 2000, 290, 'H', 'JET', 'SEED'),
    ('A359', 2500, 290, 0.85, 495, 0.850, 400, 2500, 290, 'H', 'JET', 'SEED'),
    ('A35K', 2500, 290, 0.85, 495, 0.850, 400, 2500, 290, 'H', 'JET', 'SEED'),
    ('A380', 1800, 290, 0.85, 495, 0.850, 350, 1800, 290, 'J', 'JET', 'SEED'),
    ('A388', 1800, 290, 0.85, 495, 0.850, 350, 1800, 290, 'J', 'JET', 'SEED');

-- Common large jets (narrowbody)
INSERT INTO dbo.aircraft_performance_profiles 
    (aircraft_icao, climb_rate_fpm, climb_speed_kias, climb_speed_mach, cruise_speed_ktas, cruise_mach, optimal_fl, descent_rate_fpm, descent_speed_kias, weight_class, engine_type, source) 
VALUES
    ('B737', 2500, 280, 0.78, 450, 0.785, 370, 2500, 280, 'L', 'JET', 'SEED'),
    ('B738', 2500, 280, 0.78, 453, 0.785, 370, 2500, 280, 'L', 'JET', 'SEED'),
    ('B739', 2400, 280, 0.78, 453, 0.785, 370, 2400, 280, 'L', 'JET', 'SEED'),
    ('B38M', 2600, 280, 0.79, 455, 0.790, 390, 2600, 280, 'L', 'JET', 'SEED'),
    ('B39M', 2500, 280, 0.79, 455, 0.790, 390, 2500, 280, 'L', 'JET', 'SEED'),
    ('B752', 2800, 285, 0.80, 465, 0.800, 380, 2800, 285, 'L', 'JET', 'SEED'),
    ('B753', 2600, 285, 0.80, 465, 0.800, 380, 2600, 285, 'L', 'JET', 'SEED'),
    ('B762', 2400, 285, 0.80, 470, 0.800, 370, 2400, 285, 'H', 'JET', 'SEED'),
    ('B763', 2400, 285, 0.80, 470, 0.800, 370, 2400, 285, 'H', 'JET', 'SEED'),
    ('B764', 2300, 285, 0.80, 470, 0.800, 370, 2300, 285, 'H', 'JET', 'SEED'),
    ('A318', 2800, 280, 0.78, 450, 0.780, 370, 2800, 280, 'L', 'JET', 'SEED'),
    ('A319', 2700, 280, 0.78, 450, 0.780, 370, 2700, 280, 'L', 'JET', 'SEED'),
    ('A320', 2600, 280, 0.78, 454, 0.780, 370, 2600, 280, 'L', 'JET', 'SEED'),
    ('A321', 2400, 280, 0.78, 454, 0.780, 370, 2400, 280, 'L', 'JET', 'SEED'),
    ('A19N', 2700, 280, 0.79, 455, 0.790, 390, 2700, 280, 'L', 'JET', 'SEED'),
    ('A20N', 2600, 280, 0.79, 458, 0.790, 390, 2600, 280, 'L', 'JET', 'SEED'),
    ('A21N', 2400, 280, 0.79, 458, 0.790, 390, 2400, 280, 'L', 'JET', 'SEED');

-- Regional jets
INSERT INTO dbo.aircraft_performance_profiles 
    (aircraft_icao, climb_rate_fpm, climb_speed_kias, climb_speed_mach, cruise_speed_ktas, cruise_mach, optimal_fl, descent_rate_fpm, descent_speed_kias, weight_class, engine_type, source) 
VALUES
    ('E170', 2800, 270, 0.75, 430, 0.750, 350, 2800, 270, 'L', 'JET', 'SEED'),
    ('E175', 2800, 270, 0.75, 430, 0.750, 350, 2800, 270, 'L', 'JET', 'SEED'),
    ('E190', 2700, 275, 0.78, 440, 0.780, 370, 2700, 275, 'L', 'JET', 'SEED'),
    ('E195', 2600, 275, 0.78, 440, 0.780, 370, 2600, 275, 'L', 'JET', 'SEED'),
    ('E290', 2800, 275, 0.80, 450, 0.800, 390, 2800, 275, 'L', 'JET', 'SEED'),
    ('E295', 2700, 275, 0.80, 450, 0.800, 390, 2700, 275, 'L', 'JET', 'SEED'),
    ('CRJ2', 3000, 260, 0.74, 420, 0.740, 370, 3000, 260, 'L', 'JET', 'SEED'),
    ('CRJ7', 2800, 265, 0.75, 430, 0.750, 370, 2800, 265, 'L', 'JET', 'SEED'),
    ('CRJ9', 2600, 270, 0.78, 440, 0.780, 370, 2600, 270, 'L', 'JET', 'SEED'),
    ('CRJX', 2500, 270, 0.78, 440, 0.780, 370, 2500, 270, 'L', 'JET', 'SEED');

-- Business jets
INSERT INTO dbo.aircraft_performance_profiles 
    (aircraft_icao, climb_rate_fpm, climb_speed_kias, climb_speed_mach, cruise_speed_ktas, cruise_mach, optimal_fl, descent_rate_fpm, descent_speed_kias, weight_class, engine_type, source) 
VALUES
    ('C56X', 3500, 250, 0.75, 430, 0.750, 450, 3500, 250, 'S', 'JET', 'SEED'),
    ('C560', 3200, 250, 0.72, 410, 0.720, 430, 3200, 250, 'S', 'JET', 'SEED'),
    ('C680', 3500, 250, 0.78, 450, 0.780, 450, 3500, 250, 'S', 'JET', 'SEED'),
    ('C68A', 3500, 250, 0.80, 460, 0.800, 450, 3500, 250, 'S', 'JET', 'SEED'),
    ('C700', 3500, 255, 0.85, 480, 0.850, 470, 3500, 255, 'S', 'JET', 'SEED'),
    ('C750', 3800, 260, 0.80, 460, 0.800, 450, 3800, 260, 'S', 'JET', 'SEED'),
    ('CL30', 3500, 260, 0.80, 455, 0.800, 450, 3500, 260, 'S', 'JET', 'SEED'),
    ('CL35', 3500, 260, 0.80, 460, 0.800, 450, 3500, 260, 'S', 'JET', 'SEED'),
    ('CL60', 3200, 260, 0.80, 460, 0.800, 450, 3200, 260, 'S', 'JET', 'SEED'),
    ('GL5T', 3000, 270, 0.85, 480, 0.850, 450, 3000, 270, 'L', 'JET', 'SEED'),
    ('GL7T', 3000, 275, 0.90, 505, 0.900, 450, 3000, 275, 'L', 'JET', 'SEED'),
    ('GLEX', 2800, 270, 0.85, 480, 0.850, 450, 2800, 270, 'L', 'JET', 'SEED'),
    ('GLF4', 3000, 270, 0.80, 460, 0.800, 450, 3000, 270, 'S', 'JET', 'SEED'),
    ('GLF5', 3000, 275, 0.85, 480, 0.850, 450, 3000, 275, 'L', 'JET', 'SEED'),
    ('GLF6', 3000, 280, 0.90, 500, 0.900, 450, 3000, 280, 'L', 'JET', 'SEED'),
    ('FA7X', 3200, 270, 0.80, 465, 0.800, 450, 3200, 270, 'L', 'JET', 'SEED'),
    ('FA8X', 3200, 275, 0.85, 485, 0.850, 450, 3200, 275, 'L', 'JET', 'SEED'),
    ('F900', 3000, 265, 0.78, 450, 0.780, 450, 3000, 265, 'S', 'JET', 'SEED'),
    ('E35L', 3500, 255, 0.78, 445, 0.780, 430, 3500, 255, 'S', 'JET', 'SEED'),
    ('E55P', 3800, 250, 0.75, 430, 0.750, 450, 3800, 250, 'S', 'JET', 'SEED'),
    ('LJ45', 4000, 250, 0.78, 445, 0.780, 430, 4000, 250, 'S', 'JET', 'SEED'),
    ('LJ60', 3800, 255, 0.78, 450, 0.780, 430, 3800, 255, 'S', 'JET', 'SEED'),
    ('LJ75', 3800, 260, 0.80, 465, 0.800, 450, 3800, 260, 'S', 'JET', 'SEED'),
    ('PC24', 3200, 240, 0.74, 410, 0.740, 450, 3200, 240, 'S', 'JET', 'SEED');

-- Turboprops
INSERT INTO dbo.aircraft_performance_profiles 
    (aircraft_icao, climb_rate_fpm, climb_speed_kias, cruise_speed_ktas, optimal_fl, descent_rate_fpm, descent_speed_kias, weight_class, engine_type, source) 
VALUES
    ('DH8A', 1500, 180, 270, 250, 1500, 180, 'L', 'TURBOPROP', 'SEED'),
    ('DH8B', 1500, 185, 280, 250, 1500, 185, 'L', 'TURBOPROP', 'SEED'),
    ('DH8C', 1600, 190, 300, 260, 1600, 190, 'L', 'TURBOPROP', 'SEED'),
    ('DH8D', 1400, 200, 350, 270, 1400, 200, 'L', 'TURBOPROP', 'SEED'),
    ('AT43', 1500, 185, 280, 250, 1500, 185, 'L', 'TURBOPROP', 'SEED'),
    ('AT45', 1500, 190, 295, 250, 1500, 190, 'L', 'TURBOPROP', 'SEED'),
    ('AT72', 1400, 195, 320, 260, 1400, 195, 'L', 'TURBOPROP', 'SEED'),
    ('AT76', 1400, 195, 325, 260, 1400, 195, 'L', 'TURBOPROP', 'SEED'),
    ('SF34', 1500, 180, 270, 250, 1500, 180, 'L', 'TURBOPROP', 'SEED'),
    ('SB20', 1600, 185, 310, 260, 1600, 185, 'L', 'TURBOPROP', 'SEED'),
    ('BE20', 1800, 175, 290, 270, 1800, 175, 'S', 'TURBOPROP', 'SEED'),
    ('BE9L', 1800, 165, 240, 250, 1800, 165, 'S', 'TURBOPROP', 'SEED'),
    ('B350', 2000, 190, 320, 350, 2000, 190, 'S', 'TURBOPROP', 'SEED'),
    ('PC12', 1500, 180, 285, 300, 1500, 180, 'S', 'TURBOPROP', 'SEED'),
    ('TBM7', 1800, 180, 300, 280, 1800, 180, 'S', 'TURBOPROP', 'SEED'),
    ('TBM8', 1900, 185, 320, 300, 1900, 185, 'S', 'TURBOPROP', 'SEED'),
    ('TBM9', 1900, 185, 330, 310, 1900, 185, 'S', 'TURBOPROP', 'SEED');

-- Pistons
INSERT INTO dbo.aircraft_performance_profiles 
    (aircraft_icao, climb_rate_fpm, climb_speed_kias, cruise_speed_ktas, optimal_fl, descent_rate_fpm, descent_speed_kias, weight_class, engine_type, source) 
VALUES
    ('C172', 700, 85, 125, 100, 700, 100, 'S', 'PISTON', 'SEED'),
    ('C182', 850, 95, 150, 120, 850, 110, 'S', 'PISTON', 'SEED'),
    ('C206', 800, 95, 155, 120, 800, 110, 'S', 'PISTON', 'SEED'),
    ('C210', 900, 100, 170, 140, 900, 120, 'S', 'PISTON', 'SEED'),
    ('C402', 1100, 120, 200, 180, 1100, 130, 'S', 'PISTON', 'SEED'),
    ('C414', 1200, 125, 220, 200, 1200, 135, 'S', 'PISTON', 'SEED'),
    ('PA28', 700, 80, 120, 100, 700, 95, 'S', 'PISTON', 'SEED'),
    ('PA32', 850, 90, 145, 120, 850, 105, 'S', 'PISTON', 'SEED'),
    ('PA34', 1100, 105, 175, 150, 1100, 120, 'S', 'PISTON', 'SEED'),
    ('PA46', 1000, 115, 210, 250, 1000, 125, 'S', 'PISTON', 'SEED'),
    ('BE33', 1000, 105, 180, 170, 1000, 120, 'S', 'PISTON', 'SEED'),
    ('BE35', 1000, 105, 185, 170, 1000, 120, 'S', 'PISTON', 'SEED'),
    ('BE36', 1050, 110, 190, 180, 1050, 125, 'S', 'PISTON', 'SEED'),
    ('BE55', 1200, 115, 195, 180, 1200, 130, 'S', 'PISTON', 'SEED'),
    ('BE58', 1250, 120, 210, 200, 1250, 135, 'S', 'PISTON', 'SEED'),
    ('P28A', 700, 80, 120, 100, 700, 95, 'S', 'PISTON', 'SEED'),
    ('SR20', 900, 100, 155, 120, 900, 115, 'S', 'PISTON', 'SEED'),
    ('SR22', 1100, 110, 185, 170, 1100, 125, 'S', 'PISTON', 'SEED'),
    ('DA40', 850, 90, 140, 120, 850, 105, 'S', 'PISTON', 'SEED'),
    ('DA42', 1100, 100, 175, 180, 1100, 115, 'S', 'PISTON', 'SEED'),
    ('DA62', 1200, 110, 195, 200, 1200, 120, 'S', 'PISTON', 'SEED');

PRINT 'Seeded ' + CAST((SELECT COUNT(*) FROM dbo.aircraft_performance_profiles WHERE source = 'SEED') AS VARCHAR) + ' aircraft performance profiles';
GO

PRINT '';
PRINT '=== ADL Migration 031 Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
