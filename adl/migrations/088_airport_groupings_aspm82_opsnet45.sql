-- =====================================================
-- Airport Groupings: ASPM82 + OPSNET45
-- Migration: 088_airport_groupings_aspm82_opsnet45.sql
-- Databases: VATSIM_ADL (Azure SQL), VATSIM_GIS (PostgreSQL)
-- Purpose: Update FAA airport tier groupings to current standards
-- =====================================================
--
-- Changes:
--   1. Rename ASPM77 → ASPM82 in ref_major_airports
--   2. Remove 3 deprecated airports (ALB, CHS, RIC)
--   3. Add 14 new ASPM82 airports
--   4. Add opsnet45 column to apts table
--   5. Set airport tier flags
--
-- Hierarchy: Core30 → OEP35 → OPSNET45 → ASPM82
--
-- =====================================================

SET NOCOUNT ON;
GO

PRINT 'Starting Airport Groupings migration...';
GO

-- =====================================================
-- 1. Update ref_major_airports
-- =====================================================

PRINT '1. Updating ref_major_airports region names...';

UPDATE dbo.ref_major_airports
SET region = 'ASPM82'
WHERE region = 'ASPM77';

PRINT '   - Renamed ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows from ASPM77 to ASPM82';

DELETE FROM dbo.ref_major_airports
WHERE airport_icao IN ('KALB', 'KCHS', 'KRIC')
  AND region = 'ASPM82';

PRINT '   - Removed ' + CAST(@@ROWCOUNT AS VARCHAR) + ' deprecated airports';

INSERT INTO dbo.ref_major_airports (airport_icao, region, tier, description) VALUES
('KAPA', 'ASPM82', 0, 'Denver Centennial'),
('KASE', 'ASPM82', 0, 'Aspen'),
('KBJC', 'ASPM82', 0, 'Denver Rocky Mountain'),
('KBOI', 'ASPM82', 0, 'Boise'),
('KDAY', 'ASPM82', 0, 'Dayton'),
('KGYY', 'ASPM82', 0, 'Gary Chicago'),
('KHPN', 'ASPM82', 0, 'Westchester County'),
('KISP', 'ASPM82', 0, 'Long Island Mac Arthur'),
('KMHT', 'ASPM82', 0, 'Manchester'),
('KOXR', 'ASPM82', 0, 'Oxnard'),
('KPSP', 'ASPM82', 0, 'Palm Springs'),
('KRFD', 'ASPM82', 0, 'Greater Rockford'),
('KSWF', 'ASPM82', 0, 'Stewart'),
('KVNY', 'ASPM82', 0, 'Van Nuys');

PRINT '   - Added ' + CAST(@@ROWCOUNT AS VARCHAR) + ' new ASPM82 airports';

DECLARE @aspm82_count INT;
SELECT @aspm82_count = COUNT(*) FROM dbo.ref_major_airports WHERE region = 'ASPM82';
PRINT '   - Final ASPM82 airport count: ' + CAST(@aspm82_count AS VARCHAR) + ' (expected: 82)';

IF @aspm82_count != 82
BEGIN
    RAISERROR('ERROR: ASPM82 count is %d, expected 82', 16, 1, @aspm82_count);
END;

GO

-- =====================================================
-- 2. Update apts table columns
-- =====================================================

PRINT '2. Updating apts table columns...';

IF EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.apts') AND name = 'aspm77')
BEGIN
    EXEC sp_rename 'dbo.apts.aspm77', 'aspm82', 'COLUMN';
    PRINT '   - Renamed apts.aspm77 -> apts.aspm82';
END
ELSE
BEGIN
    PRINT '   - Column apts.aspm77 does not exist, skipping rename';
END;

IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.apts') AND name = 'opsnet45')
BEGIN
    ALTER TABLE dbo.apts ADD opsnet45 BIT NULL;
    PRINT '   - Added apts.opsnet45 column';
END
ELSE
BEGIN
    PRINT '   - Column apts.opsnet45 already exists';
END;

GO

-- =====================================================
-- 3. Set airport tier flags
-- =====================================================

PRINT '3. Setting airport tier flags...';

-- Clear existing aspm82 flags to reset
UPDATE dbo.apts SET aspm82 = 0 WHERE aspm82 IS NOT NULL;

-- Set ASPM82 flags (82 airports)
UPDATE dbo.apts
SET aspm82 = 1
WHERE ICAO_ID IN (
    'KABQ', 'PANC', 'KAPA', 'KASE', 'KATL', 'KAUS', 'KBDL', 'KBHM', 'KBJC', 'KBNA',
    'KBOI', 'KBOS', 'KBUF', 'KBUR', 'KBWI', 'KCLE', 'KCLT', 'KCMH', 'KCVG', 'KDAL',
    'KDAY', 'KDCA', 'KDEN', 'KDFW', 'KDTW', 'KEWR', 'KFLL', 'KGYY', 'PHNL', 'KHOU',
    'KHPN', 'KIAD', 'KIAH', 'KIND', 'KISP', 'KJAX', 'KJFK', 'KLAS', 'KLAX', 'KLGA',
    'KLGB', 'KMCI', 'KMCO', 'KMDW', 'KMEM', 'KMHT', 'KMIA', 'KMKE', 'KMSP', 'KMSY',
    'KOAK', 'PHOG', 'KOMA', 'KONT', 'KORD', 'KOXR', 'KPBI', 'KPDX', 'KPHL', 'KPHX',
    'KPIT', 'KPSP', 'KPVD', 'KRDU', 'KRFD', 'KRSW', 'KSAN', 'KSAT', 'KSDF', 'KSEA',
    'KSFO', 'KSJC', 'TJSJ', 'KSLC', 'KSMF', 'KSNA', 'KSTL', 'KSWF', 'KTEB', 'KTPA',
    'KTUS', 'KVNY'
);

PRINT '   - Set aspm82 flag for ' + CAST(@@ROWCOUNT AS VARCHAR) + ' airports (expected: 82)';

-- Set OPSNET45 flags (45 airports)
UPDATE dbo.apts
SET opsnet45 = 1
WHERE ICAO_ID IN (
    'KABQ', 'KATL', 'KBNA', 'KBOS', 'KBWI', 'KCLE', 'KCLT', 'KCVG', 'KDCA', 'KDEN',
    'KDFW', 'KDTW', 'KEWR', 'KFLL', 'KHOU', 'KIAD', 'KIAH', 'KIND', 'KJFK', 'KLAS',
    'KLAX', 'KLGA', 'KMCI', 'KMCO', 'KMDW', 'KMEM', 'KMIA', 'KMSP', 'KMSY', 'KOAK',
    'KORD', 'KPBI', 'KPDX', 'KPHL', 'KPHX', 'KPIT', 'KRDU', 'KSAN', 'KSEA', 'KSFO',
    'KSJC', 'KSLC', 'KSTL', 'KTEB', 'KTPA'
);

PRINT '   - Set opsnet45 flag for ' + CAST(@@ROWCOUNT AS VARCHAR) + ' airports (expected: 45)';

GO

-- =====================================================
-- 4. PostgreSQL GIS Updates (run separately via psql)
-- =====================================================

-- NOTE: The following SQL is for VATSIM_GIS (PostgreSQL/PostGIS).
-- Extract and run via psql, NOT via sqlcmd.

/*
-- PostgreSQL: Rename column
ALTER TABLE airports RENAME COLUMN aspm77 TO aspm82;

-- PostgreSQL: Add new column
ALTER TABLE airports ADD COLUMN opsnet45 BOOLEAN;

-- PostgreSQL: Update ASPM82 flags
UPDATE airports
SET aspm82 = true
WHERE icao_id IN (
    'KABQ', 'PANC', 'KAPA', 'KASE', 'KATL', 'KAUS', 'KBDL', 'KBHM', 'KBJC', 'KBNA',
    'KBOI', 'KBOS', 'KBUF', 'KBUR', 'KBWI', 'KCLE', 'KCLT', 'KCMH', 'KCVG', 'KDAL',
    'KDAY', 'KDCA', 'KDEN', 'KDFW', 'KDTW', 'KEWR', 'KFLL', 'KGYY', 'PHNL', 'KHOU',
    'KHPN', 'KIAD', 'KIAH', 'KIND', 'KISP', 'KJAX', 'KJFK', 'KLAS', 'KLAX', 'KLGA',
    'KLGB', 'KMCI', 'KMCO', 'KMDW', 'KMEM', 'KMHT', 'KMIA', 'KMKE', 'KMSP', 'KMSY',
    'KOAK', 'PHOG', 'KOMA', 'KONT', 'KORD', 'KOXR', 'KPBI', 'KPDX', 'KPHL', 'KPHX',
    'KPIT', 'KPSP', 'KPVD', 'KRDU', 'KRFD', 'KRSW', 'KSAN', 'KSAT', 'KSDF', 'KSEA',
    'KSFO', 'KSJC', 'TJSJ', 'KSLC', 'KSMF', 'KSNA', 'KSTL', 'KSWF', 'KTEB', 'KTPA',
    'KTUS', 'KVNY'
);

-- PostgreSQL: Update OPSNET45 flags
UPDATE airports
SET opsnet45 = true
WHERE icao_id IN (
    'KABQ', 'KATL', 'KBNA', 'KBOS', 'KBWI', 'KCLE', 'KCLT', 'KCVG', 'KDCA', 'KDEN',
    'KDFW', 'KDTW', 'KEWR', 'KFLL', 'KHOU', 'KIAD', 'KIAH', 'KIND', 'KJFK', 'KLAS',
    'KLAX', 'KLGA', 'KMCI', 'KMCO', 'KMDW', 'KMEM', 'KMIA', 'KMSP', 'KMSY', 'KOAK',
    'KORD', 'KPBI', 'KPDX', 'KPHL', 'KPHX', 'KPIT', 'KRDU', 'KSAN', 'KSEA', 'KSFO',
    'KSJC', 'KSLC', 'KSTL', 'KTEB', 'KTPA'
);
*/

PRINT 'Migration complete.';
PRINT 'NOTE: PostgreSQL GIS updates commented out - run separately via psql.';
GO
