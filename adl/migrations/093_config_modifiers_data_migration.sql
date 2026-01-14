-- =====================================================
-- Config Modifiers Data Migration
-- Migration: 093
-- Description: Extracts modifiers from existing runway_id,
--              notes, and config_mode columns into the
--              new structured modifier system
-- =====================================================

SET NOCOUNT ON;
BEGIN TRANSACTION;

PRINT '=== Migration 093: Config Modifiers Data Migration ===';
PRINT '';

-- =====================================================
-- Step 1: Extract intersection markers (@XX)
-- =====================================================
PRINT '=== Step 1: Extract intersection markers ===';

UPDATE dbo.airport_config_runway
SET
    intersection = SUBSTRING(runway_id, CHARINDEX('@', runway_id) + 1, LEN(runway_id)),
    runway_id = LEFT(runway_id, CHARINDEX('@', runway_id) - 1)
WHERE runway_id LIKE '%@%'
  AND intersection IS NULL;

PRINT 'Extracted intersection markers: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- =====================================================
-- Step 2: Extract FMS Visual (.308) approach modifiers
-- =====================================================
PRINT '';
PRINT '=== Step 2: Extract FMS Visual (.XXX) modifiers ===';

-- Insert FMS_VISUAL modifiers for (.308) style entries
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    LEFT(r.runway_id, CHARINDEX('(', r.runway_id) - 1) AS runway_id,
    'FMS_VISUAL',
    SUBSTRING(r.runway_id, CHARINDEX('(', r.runway_id), LEN(r.runway_id)) AS original_value,
    REPLACE(REPLACE(SUBSTRING(r.runway_id, CHARINDEX('(', r.runway_id), LEN(r.runway_id)), '(', ''), ')', '') AS variant_value
FROM dbo.airport_config_runway r
WHERE r.runway_id LIKE '%[(].%[)]'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id
        AND cm.runway_id = LEFT(r.runway_id, CHARINDEX('(', r.runway_id) - 1)
        AND cm.modifier_code = 'FMS_VISUAL'
  );

PRINT 'Extracted FMS_VISUAL modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- Clean up runway_id
UPDATE dbo.airport_config_runway
SET runway_id = RTRIM(LEFT(runway_id, CHARINDEX('(', runway_id) - 1))
WHERE runway_id LIKE '%[(].%[)]';

PRINT 'Cleaned FMS_VISUAL from runway_id: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- =====================================================
-- Step 3: Extract approach types (ILS_, VOR_, RNAV_, etc.)
-- =====================================================
PRINT '';
PRINT '=== Step 3: Extract approach type modifiers ===';

-- ILS approaches
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    -- Extract runway from end: ILS_04R -> 04R, ILS_31R_VAP_31L -> needs special handling
    CASE
        WHEN r.runway_id LIKE 'ILS[_]%[_]VAP[_]%' THEN
            SUBSTRING(r.runway_id, 5, CHARINDEX('_VAP_', r.runway_id) - 5)
        WHEN r.runway_id LIKE 'ILS[_]%[_]CIR[_]%' THEN
            SUBSTRING(r.runway_id, 5, CHARINDEX('_CIR_', r.runway_id) - 5)
        ELSE
            SUBSTRING(r.runway_id, 5, LEN(r.runway_id))
    END AS runway_id,
    'ILS',
    r.runway_id,
    NULL
FROM dbo.airport_config_runway r
WHERE r.runway_id LIKE 'ILS[_]%'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'ILS'
        AND cm.runway_id = CASE
            WHEN r.runway_id LIKE 'ILS[_]%[_]VAP[_]%' THEN SUBSTRING(r.runway_id, 5, CHARINDEX('_VAP_', r.runway_id) - 5)
            WHEN r.runway_id LIKE 'ILS[_]%[_]CIR[_]%' THEN SUBSTRING(r.runway_id, 5, CHARINDEX('_CIR_', r.runway_id) - 5)
            ELSE SUBSTRING(r.runway_id, 5, LEN(r.runway_id))
        END
  );

PRINT 'Extracted ILS modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- VOR approaches
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    SUBSTRING(r.runway_id, 5, LEN(r.runway_id)) AS runway_id,
    'VOR',
    r.runway_id,
    NULL
FROM dbo.airport_config_runway r
WHERE r.runway_id LIKE 'VOR[_]%'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'VOR'
        AND cm.runway_id = SUBSTRING(r.runway_id, 5, LEN(r.runway_id))
  );

PRINT 'Extracted VOR modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- RNAV approaches (all variants)
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    -- Extract runway number from end
    REVERSE(LEFT(REVERSE(r.runway_id), CHARINDEX('_', REVERSE(r.runway_id)) - 1)) AS runway_id,
    'RNAV',
    r.runway_id,
    -- Extract variant (GPS, X, Y, Z, GPS_Y, etc.)
    CASE
        WHEN r.runway_id LIKE 'RNAV[_]GPS[_]Y[_]%' THEN 'GPS_Y'
        WHEN r.runway_id LIKE 'RNAV[_]GPS[_]Z[_]%' THEN 'GPS_Z'
        WHEN r.runway_id LIKE 'RNAV[_]GPS[_]X[_]%' THEN 'GPS_X'
        WHEN r.runway_id LIKE 'RNAV[_]GPS[_]%' THEN 'GPS'
        WHEN r.runway_id LIKE 'RNAV[_]X[_]%' THEN 'X'
        WHEN r.runway_id LIKE 'RNAV[_]Y[_]%' THEN 'Y'
        WHEN r.runway_id LIKE 'RNAV[_]Z[_]%' THEN 'Z'
        ELSE NULL
    END AS variant_value
FROM dbo.airport_config_runway r
WHERE r.runway_id LIKE 'RNAV[_]%'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'RNAV'
        AND cm.runway_id = REVERSE(LEFT(REVERSE(r.runway_id), CHARINDEX('_', REVERSE(r.runway_id)) - 1))
  );

PRINT 'Extracted RNAV modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- LDA approaches
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    SUBSTRING(r.runway_id, 5, LEN(r.runway_id)) AS runway_id,
    'LDA',
    r.runway_id,
    NULL
FROM dbo.airport_config_runway r
WHERE r.runway_id LIKE 'LDA[_]%'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'LDA'
        AND cm.runway_id = SUBSTRING(r.runway_id, 5, LEN(r.runway_id))
  );

PRINT 'Extracted LDA modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- LOC approaches
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    SUBSTRING(r.runway_id, 5, LEN(r.runway_id)) AS runway_id,
    'LOC',
    r.runway_id,
    NULL
FROM dbo.airport_config_runway r
WHERE r.runway_id LIKE 'LOC[_]%'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'LOC'
        AND cm.runway_id = SUBSTRING(r.runway_id, 5, LEN(r.runway_id))
  );

PRINT 'Extracted LOC modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- =====================================================
-- Step 4: Extract VAP (Visual Approach) modifiers
-- =====================================================
PRINT '';
PRINT '=== Step 4: Extract VAP (Visual Approach) modifiers ===';

INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    -- Runway is the part before _VAP_
    CASE
        WHEN r.runway_id LIKE 'ILS[_]%[_]VAP[_]%' THEN SUBSTRING(r.runway_id, 5, CHARINDEX('_VAP_', r.runway_id) - 5)
        ELSE LEFT(r.runway_id, CHARINDEX('_VAP_', r.runway_id) - 1)
    END AS runway_id,
    'VAP',
    r.runway_id,
    -- Target runway is after _VAP_
    SUBSTRING(r.runway_id, CHARINDEX('_VAP_', r.runway_id) + 5, LEN(r.runway_id)) AS variant_value
FROM dbo.airport_config_runway r
WHERE r.runway_id LIKE '%[_]VAP[_]%'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'VAP'
  );

PRINT 'Extracted VAP modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- =====================================================
-- Step 5: Extract CIRCLING modifiers
-- =====================================================
PRINT '';
PRINT '=== Step 5: Extract CIRCLING modifiers ===';

INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    CASE
        WHEN r.runway_id LIKE 'ILS[_]%[_]CIR[_]%' THEN SUBSTRING(r.runway_id, 5, CHARINDEX('_CIR_', r.runway_id) - 5)
        WHEN r.runway_id LIKE '%[_]CIRC[_]%' THEN LEFT(r.runway_id, CHARINDEX('_CIRC_', r.runway_id) - 1)
        ELSE LEFT(r.runway_id, CHARINDEX('_CIR_', r.runway_id) - 1)
    END AS runway_id,
    'CIRCLING',
    r.runway_id,
    -- Target runway
    CASE
        WHEN r.runway_id LIKE '%[_]CIRC[_]%' THEN SUBSTRING(r.runway_id, CHARINDEX('_CIRC_', r.runway_id) + 6, LEN(r.runway_id))
        ELSE SUBSTRING(r.runway_id, CHARINDEX('_CIR_', r.runway_id) + 5, LEN(r.runway_id))
    END AS variant_value
FROM dbo.airport_config_runway r
WHERE (r.runway_id LIKE '%[_]CIR[_]%' OR r.runway_id LIKE '%[_]CIRC[_]%')
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'CIRCLING'
  );

PRINT 'Extracted CIRCLING modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- =====================================================
-- Step 6: Extract parallel ops modifiers from notes
-- =====================================================
PRINT '';
PRINT '=== Step 6: Extract parallel ops modifiers from notes/runway_id ===';

-- SIMOS
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    CASE
        WHEN r.runway_id LIKE '%[(]SIMOS[)]' THEN REPLACE(REPLACE(r.runway_id, '_(SIMOS)', ''), '(SIMOS)', '')
        ELSE r.runway_id
    END,
    'SIMOS',
    CASE WHEN r.runway_id LIKE '%SIMOS%' THEN r.runway_id ELSE r.notes END,
    NULL
FROM dbo.airport_config_runway r
WHERE (r.runway_id LIKE '%SIMOS%' OR r.notes LIKE '%SIMOS%')
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'SIMOS'
  );

PRINT 'Extracted SIMOS modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- STAGGERED (including STAGGERED_DUAL)
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    CASE
        WHEN r.runway_id LIKE '%[(]STAGGERED%[)]' THEN
            LEFT(r.runway_id, CHARINDEX('_(', r.runway_id) - 1)
        WHEN r.runway_id LIKE '%[_]STAGGERED%' THEN
            LEFT(r.runway_id, CHARINDEX('_STAGGERED', r.runway_id) - 1)
        ELSE r.runway_id
    END,
    'STAGGERED',
    CASE WHEN r.runway_id LIKE '%STAGGERED%' THEN r.runway_id ELSE r.notes END,
    CASE WHEN r.runway_id LIKE '%STAGGERED[_]DUAL%' OR r.notes LIKE '%STAGGERED[_]DUAL%' THEN 'DUAL' ELSE NULL END
FROM dbo.airport_config_runway r
WHERE (r.runway_id LIKE '%STAGGERED%' OR r.notes LIKE '%STAGGERED%')
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'STAGGERED'
  );

PRINT 'Extracted STAGGERED modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- SIDE_BY_SIDE (SIDEBY)
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    CASE
        WHEN r.runway_id LIKE '%[_]SIDEBY' THEN LEFT(r.runway_id, CHARINDEX('_SIDEBY', r.runway_id) - 1)
        ELSE r.runway_id
    END,
    'SIDE_BY_SIDE',
    COALESCE(NULLIF(r.notes, ''), r.runway_id),
    NULL
FROM dbo.airport_config_runway r
WHERE (r.runway_id LIKE '%SIDEBY%' OR r.notes LIKE '%SIDE%BY%SIDE%')
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'SIDE_BY_SIDE'
  );

PRINT 'Extracted SIDE_BY_SIDE modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- IN_TRAIL (INTRAIL)
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    CASE
        WHEN r.runway_id LIKE '%[_]INTRAIL' THEN LEFT(r.runway_id, CHARINDEX('_INTRAIL', r.runway_id) - 1)
        ELSE r.runway_id
    END,
    'IN_TRAIL',
    COALESCE(NULLIF(r.notes, ''), r.runway_id),
    NULL
FROM dbo.airport_config_runway r
WHERE (r.runway_id LIKE '%INTRAIL%' OR r.notes LIKE '%IN[_]TRAIL%')
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'IN_TRAIL'
  );

PRINT 'Extracted IN_TRAIL modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- =====================================================
-- Step 7: Extract special ops modifiers
-- =====================================================
PRINT '';
PRINT '=== Step 7: Extract special ops modifiers ===';

-- LAHSO
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    CASE
        WHEN r.runway_id LIKE '%[_]LAHSO' THEN LEFT(r.runway_id, CHARINDEX('_LAHSO', r.runway_id) - 1)
        ELSE r.runway_id
    END,
    'LAHSO',
    COALESCE(NULLIF(r.notes, ''), r.runway_id),
    NULL
FROM dbo.airport_config_runway r
WHERE (r.runway_id LIKE '%LAHSO%' OR r.notes LIKE '%LAHSO%')
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'LAHSO'
  );

PRINT 'Extracted LAHSO modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- SINGLE_RWY (SRO)
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    CASE
        WHEN r.runway_id LIKE '%[(]SRO[)]' THEN REPLACE(REPLACE(r.runway_id, '_(SRO)', ''), '(SRO)', '')
        WHEN r.runway_id = 'SRO' THEN NULL  -- Config-level
        ELSE r.runway_id
    END,
    'SINGLE_RWY',
    COALESCE(NULLIF(r.notes, ''), r.runway_id),
    NULL
FROM dbo.airport_config_runway r
WHERE (r.runway_id LIKE '%SRO%' OR r.notes LIKE '%SRO%')
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'SINGLE_RWY'
  );

PRINT 'Extracted SINGLE_RWY modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- =====================================================
-- Step 8: Extract visibility category modifiers
-- =====================================================
PRINT '';
PRINT '=== Step 8: Extract visibility category modifiers ===';

-- CAT_II
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    CASE
        WHEN r.runway_id LIKE '%[(]CAT[_]II[)]' THEN REPLACE(r.runway_id, '_(CAT_II)', '')
        ELSE r.runway_id
    END,
    'CAT_II',
    COALESCE(NULLIF(r.notes, ''), r.runway_id),
    NULL
FROM dbo.airport_config_runway r
WHERE (r.runway_id LIKE '%CAT[_]II%' OR r.notes LIKE '%CAT[_]II%')
  AND r.runway_id NOT LIKE '%CAT[_]III%'
  AND (r.notes IS NULL OR r.notes NOT LIKE '%CAT[_]III%')
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'CAT_II'
  );

PRINT 'Extracted CAT_II modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- CAT_III (including IIIA, IIIB, IIIC)
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    CASE
        WHEN r.runway_id LIKE '%[(]CAT[_]III%[)]' THEN LEFT(r.runway_id, CHARINDEX('_(CAT_III', r.runway_id) - 1)
        ELSE r.runway_id
    END,
    'CAT_III',
    COALESCE(NULLIF(r.notes, ''), r.runway_id),
    CASE
        WHEN r.runway_id LIKE '%IIIA%' OR r.notes LIKE '%IIIA%' THEN 'A'
        WHEN r.runway_id LIKE '%IIIB%' OR r.notes LIKE '%IIIB%' THEN 'B'
        WHEN r.runway_id LIKE '%IIIC%' OR r.notes LIKE '%IIIC%' THEN 'C'
        ELSE NULL
    END
FROM dbo.airport_config_runway r
WHERE (r.runway_id LIKE '%CAT[_]III%' OR r.notes LIKE '%CAT[_]III%')
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'CAT_III'
  );

PRINT 'Extracted CAT_III modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- =====================================================
-- Step 9: Extract weather/seasonal modifiers
-- =====================================================
PRINT '';
PRINT '=== Step 9: Extract weather/seasonal modifiers ===';

-- WINTER (including SNOW)
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    CASE
        WHEN r.runway_id LIKE '%[(]WINTER[)]' THEN REPLACE(r.runway_id, '_(WINTER)', '')
        WHEN r.runway_id LIKE '%[_]SNOW' THEN LEFT(r.runway_id, CHARINDEX('_SNOW', r.runway_id) - 1)
        ELSE r.runway_id
    END,
    'WINTER',
    COALESCE(NULLIF(r.notes, ''), r.runway_id),
    CASE WHEN r.runway_id LIKE '%SNOW%' OR r.notes LIKE '%SNOW%' THEN 'SNOW' ELSE NULL END
FROM dbo.airport_config_runway r
WHERE (r.runway_id LIKE '%WINTER%' OR r.runway_id LIKE '%SNOW%'
       OR r.notes LIKE '%WINTER%' OR r.notes LIKE '%SNOW%')
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'WINTER'
  );

PRINT 'Extracted WINTER modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- NOISE
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    CASE
        WHEN r.runway_id LIKE '%[_]NOISE' THEN LEFT(r.runway_id, CHARINDEX('_NOISE', r.runway_id) - 1)
        ELSE r.runway_id
    END,
    'NOISE',
    COALESCE(NULLIF(r.notes, ''), r.runway_id),
    NULL
FROM dbo.airport_config_runway r
WHERE (r.runway_id LIKE '%NOISE%' OR r.notes LIKE '%NOISE%')
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'NOISE'
  );

PRINT 'Extracted NOISE modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- =====================================================
-- Step 10: Extract traffic bias from config_mode
-- =====================================================
PRINT '';
PRINT '=== Step 10: Extract traffic bias modifiers from config_mode ===';

-- ARR_ONLY
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    r.runway_id,
    'ARR_ONLY',
    r.config_mode,
    NULL
FROM dbo.airport_config_runway r
WHERE r.config_mode IN ('ARR', 'ARR_ONLY')
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.runway_id = r.runway_id AND cm.modifier_code = 'ARR_ONLY'
  );

PRINT 'Extracted ARR_ONLY modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- DEP_ONLY
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    r.runway_id,
    'DEP_ONLY',
    r.config_mode,
    NULL
FROM dbo.airport_config_runway r
WHERE r.config_mode IN ('DEP', 'DEP_ONLY')
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.runway_id = r.runway_id AND cm.modifier_code = 'DEP_ONLY'
  );

PRINT 'Extracted DEP_ONLY modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- ARR_HEAVY
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    r.runway_id,
    'ARR_HEAVY',
    r.config_mode,
    NULL
FROM dbo.airport_config_runway r
WHERE r.config_mode = 'ARR_HEAVY'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.runway_id = r.runway_id AND cm.modifier_code = 'ARR_HEAVY'
  );

PRINT 'Extracted ARR_HEAVY modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- DEP_HEAVY
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    r.runway_id,
    'DEP_HEAVY',
    r.config_mode,
    NULL
FROM dbo.airport_config_runway r
WHERE r.config_mode = 'DEP_HEAVY'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.runway_id = r.runway_id AND cm.modifier_code = 'DEP_HEAVY'
  );

PRINT 'Extracted DEP_HEAVY modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- BALANCED
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    r.runway_id,
    'BALANCED',
    r.config_mode,
    NULL
FROM dbo.airport_config_runway r
WHERE r.config_mode = 'BALANCED'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.runway_id = r.runway_id AND cm.modifier_code = 'BALANCED'
  );

PRINT 'Extracted BALANCED modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- MIXED
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    r.runway_id,
    'MIXED',
    r.config_mode,
    NULL
FROM dbo.airport_config_runway r
WHERE r.config_mode = 'MIXED'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.runway_id = r.runway_id AND cm.modifier_code = 'MIXED'
  );

PRINT 'Extracted MIXED modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- =====================================================
-- Step 11: Extract time restriction modifiers
-- =====================================================
PRINT '';
PRINT '=== Step 11: Extract time restriction modifiers ===';

-- DAY
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    r.runway_id,
    'DAY',
    r.config_mode,
    NULL
FROM dbo.airport_config_runway r
WHERE r.config_mode = 'DAY'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.runway_id = r.runway_id AND cm.modifier_code = 'DAY'
  );

PRINT 'Extracted DAY modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- NIGHT
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    r.runway_id,
    'NIGHT',
    r.config_mode,
    NULL
FROM dbo.airport_config_runway r
WHERE r.config_mode = 'NIGHT'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.runway_id = r.runway_id AND cm.modifier_code = 'NIGHT'
  );

PRINT 'Extracted NIGHT modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- =====================================================
-- Step 12: Extract NAMED (CONFIG1, CONFIG2, etc.)
-- =====================================================
PRINT '';
PRINT '=== Step 12: Extract NAMED variant modifiers ===';

INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    r.runway_id,
    'NAMED',
    r.config_mode,
    -- Extract the number from CONFIG1, CONFIG2, etc.
    REPLACE(r.config_mode, 'CONFIG', '')
FROM dbo.airport_config_runway r
WHERE r.config_mode LIKE 'CONFIG%'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.runway_id = r.runway_id AND cm.modifier_code = 'NAMED'
  );

PRINT 'Extracted NAMED modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- =====================================================
-- Step 13: Normalize runway_id values
-- =====================================================
PRINT '';
PRINT '=== Step 13: Normalize runway_id values ===';

-- Remove approach prefixes (ILS_, VOR_, RNAV_, LDA_, LOC_)
UPDATE dbo.airport_config_runway
SET runway_id = REVERSE(LEFT(REVERSE(runway_id), CHARINDEX('_', REVERSE(runway_id)) - 1))
WHERE runway_id LIKE 'ILS[_]%'
   OR runway_id LIKE 'VOR[_]%'
   OR runway_id LIKE 'LDA[_]%'
   OR runway_id LIKE 'LOC[_]%';

PRINT 'Normalized approach prefix runway_ids: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- Handle RNAV (may have multiple underscores)
UPDATE dbo.airport_config_runway
SET runway_id = REVERSE(LEFT(REVERSE(runway_id), CHARINDEX('_', REVERSE(runway_id)) - 1))
WHERE runway_id LIKE 'RNAV[_]%';

PRINT 'Normalized RNAV runway_ids: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- Remove parenthetical suffixes like (SIMOS), (STAGGERED), (WINTER), (CAT_II), (SRO)
UPDATE dbo.airport_config_runway
SET runway_id = RTRIM(LEFT(runway_id, CHARINDEX('(', runway_id) - 1))
WHERE runway_id LIKE '%[(]%[)]'
  AND runway_id NOT LIKE '[(]%';  -- Don't touch entries that start with (

-- Clean trailing underscores
UPDATE dbo.airport_config_runway
SET runway_id = LEFT(runway_id, LEN(runway_id) - 1)
WHERE runway_id LIKE '%[_]';

PRINT 'Cleaned parenthetical suffixes: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- Remove inline modifiers (_LAHSO, _SNOW, _NOISE, _SIDEBY, _INTRAIL)
UPDATE dbo.airport_config_runway
SET runway_id = LEFT(runway_id, CHARINDEX('_LAHSO', runway_id) - 1)
WHERE runway_id LIKE '%[_]LAHSO';

UPDATE dbo.airport_config_runway
SET runway_id = LEFT(runway_id, CHARINDEX('_SNOW', runway_id) - 1)
WHERE runway_id LIKE '%[_]SNOW';

UPDATE dbo.airport_config_runway
SET runway_id = LEFT(runway_id, CHARINDEX('_NOISE', runway_id) - 1)
WHERE runway_id LIKE '%[_]NOISE';

UPDATE dbo.airport_config_runway
SET runway_id = LEFT(runway_id, CHARINDEX('_SIDEBY', runway_id) - 1)
WHERE runway_id LIKE '%[_]SIDEBY';

UPDATE dbo.airport_config_runway
SET runway_id = LEFT(runway_id, CHARINDEX('_INTRAIL', runway_id) - 1)
WHERE runway_id LIKE '%[_]INTRAIL';

PRINT 'Removed inline modifiers from runway_ids';

-- Handle complex patterns like ILS_31R_VAP_31L -> 31R
UPDATE dbo.airport_config_runway
SET runway_id = SUBSTRING(runway_id, 5, CHARINDEX('_VAP_', runway_id) - 5)
WHERE runway_id LIKE 'ILS[_]%[_]VAP[_]%';

UPDATE dbo.airport_config_runway
SET runway_id = SUBSTRING(runway_id, 5, CHARINDEX('_CIR_', runway_id) - 5)
WHERE runway_id LIKE 'ILS[_]%[_]CIR[_]%';

PRINT 'Handled complex VAP/CIR patterns';

-- =====================================================
-- Summary
-- =====================================================
PRINT '';
PRINT '=== Migration 093 Summary ===';

-- Total count
SELECT 'Total config_modifier records' AS metric, COUNT(*) AS value FROM dbo.config_modifier;

-- Breakdown by category
SELECT
    mc.category_name AS category,
    COUNT(cm.id) AS modifier_count
FROM dbo.modifier_category mc
LEFT JOIN dbo.modifier_type mt ON mc.category_code = mt.category_code
LEFT JOIN dbo.config_modifier cm ON mt.modifier_code = cm.modifier_code
GROUP BY mc.category_name, mc.display_order
ORDER BY mc.display_order;

PRINT '';
PRINT '=== Runway IDs that may need manual review ===';

SELECT DISTINCT
    c.airport_icao,
    r.runway_id,
    r.notes,
    r.config_mode
FROM dbo.airport_config_runway r
JOIN dbo.airport_config c ON r.config_id = c.config_id
WHERE r.runway_id NOT LIKE '[0-9]'
  AND r.runway_id NOT LIKE '[0-9][0-9]'
  AND r.runway_id NOT LIKE '[0-9][LRC]'
  AND r.runway_id NOT LIKE '[0-9][0-9][LRC]'
  AND r.runway_id NOT LIKE 'SRO'
  AND r.runway_id IS NOT NULL
  AND LEN(r.runway_id) > 0
ORDER BY c.airport_icao, r.runway_id;

COMMIT TRANSACTION;
PRINT '';
PRINT 'Migration 093 completed successfully.';
GO
