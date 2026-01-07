-- =====================================================
-- Parse and Enrich Runway Data
-- Extracts embedded metadata from runway fields
-- =====================================================

SET NOCOUNT ON;

-- First, expand the runway_id column
PRINT '=== Step 1: Expand runway_id column ===';
ALTER TABLE dbo.airport_config_runway
ALTER COLUMN runway_id VARCHAR(32) NOT NULL;
PRINT 'Done';
GO

-- Add metadata columns if they don't exist
PRINT '';
PRINT '=== Step 2: Add metadata columns ===';

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.airport_config_runway') AND name = 'approach_type')
    ALTER TABLE dbo.airport_config_runway ADD approach_type VARCHAR(16) NULL;

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.airport_config_runway') AND name = 'config_mode')
    ALTER TABLE dbo.airport_config_runway ADD config_mode VARCHAR(16) NULL;

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.airport_config_runway') AND name = 'notes')
    ALTER TABLE dbo.airport_config_runway ADD notes VARCHAR(64) NULL;

PRINT 'Added columns: approach_type, config_mode, notes';
GO

-- Fix Canadian ICAOs
PRINT '';
PRINT '=== Step 3: Fix Canadian Airport ICAOs ===';

UPDATE dbo.airport_config
SET airport_icao = 'C' + airport_faa
WHERE airport_faa IN ('YEG', 'YUL', 'YVR', 'YYC', 'YYZ', 'YOW', 'YWG', 'YHZ', 'YQB', 'YXE', 'YQR', 'YXU', 'YQT', 'YFC', 'YQM', 'YYJ', 'YLW', 'YXX', 'YKF')
  AND airport_icao LIKE 'K%';

PRINT 'Fixed ' + CAST(@@ROWCOUNT AS VARCHAR) + ' Canadian ICAOs';

PRINT '';
PRINT '=== Step 3b: Remove FCA entries (Flow Control Areas - not airport configs) ===';

-- Show FCA entries before deleting
SELECT 'FCA entries to remove:' AS info;
SELECT c.airport_faa, c.airport_icao, r.runway_id, r.runway_use
FROM dbo.airport_config c
JOIN dbo.airport_config_runway r ON c.config_id = r.config_id
WHERE c.airport_faa LIKE 'FCA%' OR c.airport_icao LIKE 'FCA%';

-- Delete FCA runway entries first (foreign key)
DELETE r
FROM dbo.airport_config_runway r
JOIN dbo.airport_config c ON r.config_id = c.config_id
WHERE c.airport_faa LIKE 'FCA%' OR c.airport_icao LIKE 'FCA%';

PRINT 'Deleted FCA runway entries: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- Delete FCA rate entries
DELETE rt
FROM dbo.airport_config_rate rt
JOIN dbo.airport_config c ON rt.config_id = c.config_id
WHERE c.airport_faa LIKE 'FCA%' OR c.airport_icao LIKE 'FCA%';

PRINT 'Deleted FCA rate entries: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- Delete FCA config entries
DELETE FROM dbo.airport_config
WHERE airport_faa LIKE 'FCA%' OR airport_icao LIKE 'FCA%';

PRINT 'Deleted FCA config entries: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';
PRINT '(FCAs will be added to a separate lookup table later)';

PRINT '';
PRINT '=== Step 4: Parse European config modes (XX_ARR/DEP/BALANCED/MIXED) ===';

-- Pattern: 07R_ARR, 07R_DEP, 07R_BALANCED, 07R_MIXED, 07R_ARRONLY, etc.
UPDATE dbo.airport_config_runway
SET
    config_mode = CASE
        WHEN runway_id LIKE '%[_]ARRONLY' THEN 'ARR_ONLY'
        WHEN runway_id LIKE '%[_]DEPONLY' THEN 'DEP_ONLY'
        WHEN runway_id LIKE '%[_]ARRHEAVY' THEN 'ARR_HEAVY'
        WHEN runway_id LIKE '%[_]DEPHEAVY' THEN 'DEP_HEAVY'
        WHEN runway_id LIKE '%[_]BALANCED' THEN 'BALANCED'
        WHEN runway_id LIKE '%[_]MIXED' THEN 'MIXED'
        WHEN runway_id LIKE '%[_]ARR' THEN 'ARR'
        WHEN runway_id LIKE '%[_]DEP' THEN 'DEP'
        WHEN runway_id LIKE '%[_]DAY' THEN 'DAY'
        WHEN runway_id LIKE '%[_]NIGHT' THEN 'NIGHT'
        WHEN runway_id LIKE '%[_]CATARR' THEN 'CAT_ARR'
        WHEN runway_id LIKE '%[_]CATDEP' THEN 'CAT_DEP'
        WHEN runway_id LIKE '%[_]CATBALANCED' THEN 'CAT_BALANCED'
    END,
    runway_id = LEFT(runway_id, CHARINDEX('_', runway_id) - 1)
WHERE runway_id LIKE '[0-9][0-9][LRC]?[_]%'
   OR runway_id LIKE '[0-9][0-9][_]%'
   OR runway_id LIKE 'SRO[_]%';

PRINT 'Extracted config modes: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

PRINT '';
PRINT '=== Step 5: Parse approach types (ILS_XX, VOR_XX, RNAV_XX, LDA_XX) ===';

-- Pattern: ILS_04R, VOR_13L, RNAV_GPS_Y_16, etc.
UPDATE dbo.airport_config_runway
SET
    approach_type = CASE
        WHEN runway_id LIKE 'ILS[_]%' THEN 'ILS'
        WHEN runway_id LIKE 'VOR[_]%' THEN 'VOR'
        WHEN runway_id LIKE 'RNAV[_]GPS[_]%' THEN 'RNAV_GPS'
        WHEN runway_id LIKE 'RNAV[_]X[_]%' THEN 'RNAV_X'
        WHEN runway_id LIKE 'RNAV[_]Z[_]%' THEN 'RNAV_Z'
        WHEN runway_id LIKE 'RNAV[_]%' THEN 'RNAV'
        WHEN runway_id LIKE 'LDA[_]%' THEN 'LDA'
        WHEN runway_id LIKE 'LOC[_]%' THEN 'LOC'
    END,
    -- Extract the runway number from the end
    runway_id = REVERSE(LEFT(REVERSE(runway_id), CHARINDEX('_', REVERSE(runway_id)) - 1))
WHERE runway_id LIKE 'ILS[_]%'
   OR runway_id LIKE 'VOR[_]%'
   OR runway_id LIKE 'RNAV[_]%'
   OR runway_id LIKE 'LDA[_]%'
   OR runway_id LIKE 'LOC[_]%';

PRINT 'Extracted approach types: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

PRINT '';
PRINT '=== Step 6: Parse parenthetical notes ===';

-- Pattern: 06L_(WINTER), 17R_(DUAL), 01L_(CAT_II), etc.
UPDATE dbo.airport_config_runway
SET
    notes = SUBSTRING(runway_id, CHARINDEX('(', runway_id) + 1, CHARINDEX(')', runway_id) - CHARINDEX('(', runway_id) - 1),
    runway_id = LEFT(runway_id, CHARINDEX('(', runway_id) - 1)
WHERE runway_id LIKE '%[(]%[)]%'
  AND runway_id NOT LIKE '[(]%';  -- Don't touch entries that START with (

-- Clean up trailing underscores from the extraction
UPDATE dbo.airport_config_runway
SET runway_id = LEFT(runway_id, LEN(runway_id) - 1)
WHERE runway_id LIKE '%[_]';

PRINT 'Extracted parenthetical notes: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

PRINT '';
PRINT '=== Step 7: Parse config numbers ===';

-- Pattern: 26L_CONFIG3, 01R_CONFIG2, etc.
UPDATE dbo.airport_config_runway
SET
    config_mode = SUBSTRING(runway_id, CHARINDEX('CONFIG', runway_id), LEN(runway_id)),
    runway_id = LEFT(runway_id, CHARINDEX('_CONFIG', runway_id) - 1)
WHERE runway_id LIKE '%[_]CONFIG%';

PRINT 'Extracted config numbers: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

PRINT '';
PRINT '=== Step 8: Parse special conditions ===';

-- Pattern: XX_LAHSO, XX_SNOW, XX_CIRC_YY, etc.
UPDATE dbo.airport_config_runway
SET
    notes = CASE
        WHEN runway_id LIKE '%[_]LAHSO' THEN 'LAHSO'
        WHEN runway_id LIKE '%[_]SNOW' THEN 'SNOW'
        WHEN runway_id LIKE '%[_]NOISE' THEN 'NOISE'
        WHEN runway_id LIKE '%[_]SIDEBY' THEN 'SIDE_BY_SIDE'
        WHEN runway_id LIKE '%[_]STAGGER' THEN 'STAGGERED'
        WHEN runway_id LIKE '%[_]INTRAIL' THEN 'IN_TRAIL'
        WHEN runway_id LIKE '%[_]NO[_]TWY%' THEN SUBSTRING(runway_id, CHARINDEX('_NO_TWY', runway_id) + 1, LEN(runway_id))
    END,
    runway_id = LEFT(runway_id, CHARINDEX('_', runway_id + '_') - 1)
WHERE runway_id LIKE '%[_]LAHSO'
   OR runway_id LIKE '%[_]SNOW'
   OR runway_id LIKE '%[_]NOISE'
   OR runway_id LIKE '%[_]SIDEBY'
   OR runway_id LIKE '%[_]STAGGER'
   OR runway_id LIKE '%[_]INTRAIL'
   OR runway_id LIKE '%[_]NO[_]TWY%';

PRINT 'Extracted special conditions: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

PRINT '';
PRINT '=== Step 9: Clean up circling approaches ===';

-- Pattern: XX_CIR_YY, XX_CIRC_YY
UPDATE dbo.airport_config_runway
SET
    notes = 'CIRC_' + REVERSE(LEFT(REVERSE(runway_id), CHARINDEX('_', REVERSE(runway_id)) - 1)),
    runway_id = LEFT(runway_id, CHARINDEX('_CIR', runway_id) - 1)
WHERE runway_id LIKE '%[_]CIR[_]%'
   OR runway_id LIKE '%[_]CIRC[_]%';

PRINT 'Extracted circling approaches: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

PRINT '';
PRINT '=== Step 10: Handle SRO (Single Runway Operations) ===';

-- SRO entries - mark as special
UPDATE dbo.airport_config_runway
SET notes = COALESCE(notes + ', ', '') + 'SRO'
WHERE runway_id = 'SRO';

PRINT 'Marked SRO entries: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

PRINT '';
PRINT '=== Step 11: Fix special characters in runway IDs ===';

-- Remove @ symbols (31L@KE, 28L@P)
UPDATE dbo.airport_config_runway
SET
    notes = COALESCE(notes + ', ', '') + 'EXIT_' + SUBSTRING(runway_id, CHARINDEX('@', runway_id) + 1, LEN(runway_id)),
    runway_id = LEFT(runway_id, CHARINDEX('@', runway_id) - 1)
WHERE runway_id LIKE '%@%';

PRINT 'Fixed @ characters: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- Fix (.308) style entries
UPDATE dbo.airport_config_runway
SET
    notes = COALESCE(notes + ', ', '') + SUBSTRING(runway_id, CHARINDEX('(', runway_id), LEN(runway_id)),
    runway_id = LEFT(runway_id, CHARINDEX('(', runway_id) - 1)
WHERE runway_id LIKE '%[(].%[)]';

PRINT 'Fixed decimal entries: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

PRINT '';
PRINT '=== Summary: Remaining unusual runway IDs ===';

SELECT DISTINCT
    c.airport_icao,
    r.runway_id,
    r.runway_use,
    r.config_mode,
    r.approach_type,
    r.notes,
    LEN(r.runway_id) as len
FROM dbo.airport_config_runway r
JOIN dbo.airport_config c ON r.config_id = c.config_id
WHERE r.runway_id NOT LIKE '[0-9]'
  AND r.runway_id NOT LIKE '[0-9][0-9]'
  AND r.runway_id NOT LIKE '[0-9][LRC]'
  AND r.runway_id NOT LIKE '[0-9][0-9][LRC]'
  AND r.runway_id NOT LIKE 'SRO'
ORDER BY c.airport_icao, r.runway_id;

PRINT '';
PRINT '=== Stats ===';

SELECT 'Total runway entries: ' + CAST(COUNT(*) AS VARCHAR) FROM dbo.airport_config_runway;
SELECT 'With config_mode: ' + CAST(COUNT(*) AS VARCHAR) FROM dbo.airport_config_runway WHERE config_mode IS NOT NULL;
SELECT 'With approach_type: ' + CAST(COUNT(*) AS VARCHAR) FROM dbo.airport_config_runway WHERE approach_type IS NOT NULL;
SELECT 'With notes: ' + CAST(COUNT(*) AS VARCHAR) FROM dbo.airport_config_runway WHERE notes IS NOT NULL;

PRINT '';
PRINT 'Parsing complete.';
GO
