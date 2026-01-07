-- =====================================================
-- Fix Config Migration Issues
-- 1. Fix Canadian airport ICAO codes (KYEG -> CYEG)
-- 2. Parse malformed runway IDs
-- =====================================================

SET NOCOUNT ON;

PRINT '=== Part 1: Fix Canadian Airport ICAO Codes ===';
PRINT '';

-- Canadian airports that were incorrectly prefixed with K instead of C
UPDATE dbo.airport_config
SET airport_icao = 'C' + airport_faa
WHERE airport_faa IN ('YEG', 'YUL', 'YVR', 'YYC', 'YYZ', 'YOW', 'YWG', 'YHZ', 'YQB', 'YXE', 'YQR', 'YXU', 'YQT', 'YFC', 'YQM', 'YYJ', 'YLW', 'YXX', 'YKF')
  AND airport_icao LIKE 'K%';

PRINT 'Fixed ' + CAST(@@ROWCOUNT AS VARCHAR) + ' Canadian airport ICAO codes';

-- Also fix any that might have been entered as 4-letter with wrong prefix
UPDATE dbo.airport_config
SET airport_icao = 'C' + SUBSTRING(airport_icao, 2, 3)
WHERE airport_icao LIKE 'KY__'
  AND SUBSTRING(airport_icao, 2, 3) IN ('YEG', 'YUL', 'YVR', 'YYC', 'YYZ', 'YOW', 'YWG', 'YHZ', 'YQB', 'YXE', 'YQR', 'YXU', 'YQT', 'YFC', 'YQM', 'YYJ', 'YLW', 'YXX', 'YKF');

PRINT '';
PRINT '=== Part 2: Expand runway_id Column ===';
PRINT '';

-- Expand runway_id to handle longer values (approach types, notes)
ALTER TABLE dbo.airport_config_runway
ALTER COLUMN runway_id VARCHAR(16) NOT NULL;

PRINT 'Expanded runway_id column to VARCHAR(16)';

PRINT '';
PRINT '=== Part 3: Add Approach Type Column ===';
PRINT '';

-- Add column for approach type if not exists
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.airport_config_runway') AND name = 'approach_type')
BEGIN
    ALTER TABLE dbo.airport_config_runway
    ADD approach_type VARCHAR(16) NULL;
    PRINT 'Added approach_type column';
END
ELSE
BEGIN
    PRINT 'approach_type column already exists';
END

PRINT '';
PRINT '=== Part 4: Parse Existing Malformed Runway Data ===';
PRINT '';

-- Parse runway IDs that have approach types embedded
-- Pattern: "07R_ILS" -> runway_id = "07R", approach_type = "ILS"

-- First, let's see what patterns exist
PRINT 'Analyzing runway patterns...';

SELECT
    runway_id,
    COUNT(*) as cnt,
    CASE
        WHEN runway_id LIKE '%[_]%' THEN 'Has underscore'
        WHEN runway_id LIKE '%ILS%' THEN 'Contains ILS'
        WHEN runway_id LIKE '%VOR%' THEN 'Contains VOR'
        WHEN runway_id LIKE '%LDA%' THEN 'Contains LDA'
        WHEN LEN(runway_id) > 4 THEN 'Too long'
        ELSE 'Normal'
    END as pattern_type
FROM dbo.airport_config_runway
WHERE LEN(runway_id) > 3 OR runway_id LIKE '%[_]%'
GROUP BY runway_id
ORDER BY pattern_type, runway_id;

PRINT '';
PRINT '=== Part 5: Fix Common Patterns ===';
PRINT '';

-- Fix patterns like "07R_" (trailing underscore)
UPDATE dbo.airport_config_runway
SET runway_id = REPLACE(runway_id, '_', '')
WHERE runway_id LIKE '%[_]' AND LEN(runway_id) <= 5;

PRINT 'Fixed trailing underscore patterns: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- Fix patterns with approach types (e.g., "ILS_26L" or "26L_ILS")
-- Extract approach type and clean runway ID

-- Pattern: Starts with approach type (ILS_, VOR_, LDA_, RNAV_)
UPDATE dbo.airport_config_runway
SET
    approach_type = LEFT(runway_id, CHARINDEX('_', runway_id) - 1),
    runway_id = SUBSTRING(runway_id, CHARINDEX('_', runway_id) + 1, LEN(runway_id))
WHERE runway_id LIKE 'ILS[_]%' OR runway_id LIKE 'VOR[_]%' OR runway_id LIKE 'LDA[_]%' OR runway_id LIKE 'RNAV[_]%';

PRINT 'Extracted leading approach types: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- Pattern: Ends with approach type (_ILS, _VOR, _LDA)
UPDATE dbo.airport_config_runway
SET
    approach_type = SUBSTRING(runway_id, CHARINDEX('_', runway_id) + 1, LEN(runway_id)),
    runway_id = LEFT(runway_id, CHARINDEX('_', runway_id) - 1)
WHERE runway_id LIKE '%[_]ILS' OR runway_id LIKE '%[_]VOR' OR runway_id LIKE '%[_]LDA' OR runway_id LIKE '%[_]RNAV';

PRINT 'Extracted trailing approach types: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- Fix runway IDs that are just numbers without L/R/C (pad with leading zero if needed)
UPDATE dbo.airport_config_runway
SET runway_id = '0' + runway_id
WHERE LEN(runway_id) = 1 AND runway_id LIKE '[0-9]';

PRINT 'Padded single-digit runways: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

PRINT '';
PRINT '=== Summary ===';
PRINT '';

-- Show remaining unusual entries
SELECT 'Remaining unusual runway IDs:' AS info;
SELECT DISTINCT runway_id, approach_type
FROM dbo.airport_config_runway
WHERE LEN(runway_id) > 4 OR runway_id LIKE '%[^0-9LRC]%'
ORDER BY runway_id;

-- Show config counts
SELECT
    'Total configs: ' + CAST(COUNT(*) AS VARCHAR) AS info
FROM dbo.airport_config;

SELECT
    'Total runways: ' + CAST(COUNT(*) AS VARCHAR) AS info
FROM dbo.airport_config_runway;

SELECT
    'Runways with approach types: ' + CAST(COUNT(*) AS VARCHAR) AS info
FROM dbo.airport_config_runway
WHERE approach_type IS NOT NULL;

PRINT '';
PRINT 'Migration fixes complete.';
GO
