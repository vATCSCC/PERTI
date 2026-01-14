-- =====================================================
-- Normalize Remaining Runway IDs
-- Migration: 093b
-- Description: Handles European-style patterns and
--              other edge cases not covered by 093
-- =====================================================

SET NOCOUNT ON;

PRINT '=== Migration 093b: Normalize Remaining Runway IDs ===';
PRINT '';

-- =====================================================
-- Step 1: Remove FCA entries (Flow Control Areas)
-- =====================================================
PRINT '=== Step 1: Remove FCA entries ===';

-- Show what will be deleted
SELECT 'FCA entries to remove:' AS info;
SELECT c.airport_icao, c.config_name, r.runway_id
FROM dbo.airport_config c
JOIN dbo.airport_config_runway r ON c.config_id = r.config_id
WHERE c.airport_icao LIKE '[_]%';

-- Delete from config_modifier first
DELETE cm
FROM dbo.config_modifier cm
JOIN dbo.airport_config c ON cm.config_id = c.config_id
WHERE c.airport_icao LIKE '[_]%';

PRINT 'Deleted FCA config_modifier entries: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- Delete runway entries
DELETE r
FROM dbo.airport_config_runway r
JOIN dbo.airport_config c ON r.config_id = c.config_id
WHERE c.airport_icao LIKE '[_]%';

PRINT 'Deleted FCA runway entries: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- Delete rate entries
DELETE rt
FROM dbo.airport_config_rate rt
JOIN dbo.airport_config c ON rt.config_id = c.config_id
WHERE c.airport_icao LIKE '[_]%';

PRINT 'Deleted FCA rate entries: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- Delete config entries
DELETE FROM dbo.airport_config WHERE airport_icao LIKE '[_]%';
PRINT 'Deleted FCA config entries: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- =====================================================
-- Step 2: Extract European-style traffic bias modifiers
-- =====================================================
PRINT '';
PRINT '=== Step 2: Extract European-style traffic bias modifiers ===';

-- ARR_ONLY (from XX_ARR, XX_ARRONLY)
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    CASE
        WHEN r.runway_id LIKE '%[_]ARRONLY' THEN LEFT(r.runway_id, CHARINDEX('_ARRONLY', r.runway_id) - 1)
        WHEN r.runway_id LIKE '%[_]ARR' THEN LEFT(r.runway_id, LEN(r.runway_id) - 4)
        ELSE r.runway_id
    END,
    'ARR_ONLY',
    r.runway_id,
    NULL
FROM dbo.airport_config_runway r
WHERE (r.runway_id LIKE '%[_]ARR' OR r.runway_id LIKE '%[_]ARRONLY')
  AND r.runway_id NOT LIKE '%ARRHEAVY%'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'ARR_ONLY'
        AND cm.runway_id = CASE
            WHEN r.runway_id LIKE '%[_]ARRONLY' THEN LEFT(r.runway_id, CHARINDEX('_ARRONLY', r.runway_id) - 1)
            WHEN r.runway_id LIKE '%[_]ARR' THEN LEFT(r.runway_id, LEN(r.runway_id) - 4)
            ELSE r.runway_id
        END
  );

PRINT 'Extracted ARR_ONLY from European patterns: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- DEP_ONLY (from XX_DEP, XX_DEPONLY)
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    CASE
        WHEN r.runway_id LIKE '%[_]DEPONLY' THEN LEFT(r.runway_id, CHARINDEX('_DEPONLY', r.runway_id) - 1)
        WHEN r.runway_id LIKE '%[_]DEP' THEN LEFT(r.runway_id, LEN(r.runway_id) - 4)
        ELSE r.runway_id
    END,
    'DEP_ONLY',
    r.runway_id,
    NULL
FROM dbo.airport_config_runway r
WHERE (r.runway_id LIKE '%[_]DEP' OR r.runway_id LIKE '%[_]DEPONLY')
  AND r.runway_id NOT LIKE '%DEPHEAVY%'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'DEP_ONLY'
        AND cm.runway_id = CASE
            WHEN r.runway_id LIKE '%[_]DEPONLY' THEN LEFT(r.runway_id, CHARINDEX('_DEPONLY', r.runway_id) - 1)
            WHEN r.runway_id LIKE '%[_]DEP' THEN LEFT(r.runway_id, LEN(r.runway_id) - 4)
            ELSE r.runway_id
        END
  );

PRINT 'Extracted DEP_ONLY from European patterns: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- ARR_HEAVY
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    LEFT(r.runway_id, CHARINDEX('_ARRHEAVY', r.runway_id) - 1),
    'ARR_HEAVY',
    r.runway_id,
    NULL
FROM dbo.airport_config_runway r
WHERE r.runway_id LIKE '%[_]ARRHEAVY'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'ARR_HEAVY'
        AND cm.runway_id = LEFT(r.runway_id, CHARINDEX('_ARRHEAVY', r.runway_id) - 1)
  );

PRINT 'Extracted ARR_HEAVY from European patterns: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- DEP_HEAVY
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    LEFT(r.runway_id, CHARINDEX('_DEPHEAVY', r.runway_id) - 1),
    'DEP_HEAVY',
    r.runway_id,
    NULL
FROM dbo.airport_config_runway r
WHERE r.runway_id LIKE '%[_]DEPHEAVY'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'DEP_HEAVY'
        AND cm.runway_id = LEFT(r.runway_id, CHARINDEX('_DEPHEAVY', r.runway_id) - 1)
  );

PRINT 'Extracted DEP_HEAVY from European patterns: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- BALANCED
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    LEFT(r.runway_id, CHARINDEX('_BALANCED', r.runway_id) - 1),
    'BALANCED',
    r.runway_id,
    NULL
FROM dbo.airport_config_runway r
WHERE r.runway_id LIKE '%[_]BALANCED'
  AND r.runway_id NOT LIKE '%CAT%'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'BALANCED'
        AND cm.runway_id = LEFT(r.runway_id, CHARINDEX('_BALANCED', r.runway_id) - 1)
  );

PRINT 'Extracted BALANCED from European patterns: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- MIXED
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    LEFT(r.runway_id, CHARINDEX('_MIXED', r.runway_id) - 1),
    'MIXED',
    r.runway_id,
    NULL
FROM dbo.airport_config_runway r
WHERE r.runway_id LIKE '%[_]MIXED'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'MIXED'
        AND cm.runway_id = LEFT(r.runway_id, CHARINDEX('_MIXED', r.runway_id) - 1)
  );

PRINT 'Extracted MIXED from European patterns: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- =====================================================
-- Step 3: Extract time restrictions (DAY/NIGHT)
-- =====================================================
PRINT '';
PRINT '=== Step 3: Extract time restriction modifiers ===';

-- DAY
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    LEFT(r.runway_id, CHARINDEX('_DAY', r.runway_id) - 1),
    'DAY',
    r.runway_id,
    NULL
FROM dbo.airport_config_runway r
WHERE r.runway_id LIKE '%[_]DAY'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'DAY'
        AND cm.runway_id = LEFT(r.runway_id, CHARINDEX('_DAY', r.runway_id) - 1)
  );

PRINT 'Extracted DAY modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- NIGHT
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    LEFT(r.runway_id, CHARINDEX('_NIGHT', r.runway_id) - 1),
    'NIGHT',
    r.runway_id,
    NULL
FROM dbo.airport_config_runway r
WHERE r.runway_id LIKE '%[_]NIGHT'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'NIGHT'
        AND cm.runway_id = LEFT(r.runway_id, CHARINDEX('_NIGHT', r.runway_id) - 1)
  );

PRINT 'Extracted NIGHT modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- =====================================================
-- Step 4: Extract visibility CAT from European patterns
-- =====================================================
PRINT '';
PRINT '=== Step 4: Extract CAT modifiers from European patterns ===';

-- CAT_I (CATARR, CATDEP, CATBALANCED -> CAT I approach)
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    CASE
        WHEN r.runway_id LIKE '%[_]CATARR' THEN LEFT(r.runway_id, CHARINDEX('_CATARR', r.runway_id) - 1)
        WHEN r.runway_id LIKE '%[_]CATDEP' THEN LEFT(r.runway_id, CHARINDEX('_CATDEP', r.runway_id) - 1)
        WHEN r.runway_id LIKE '%[_]CATBALANCED' THEN LEFT(r.runway_id, CHARINDEX('_CATBALANCED', r.runway_id) - 1)
    END,
    'CAT_I',
    r.runway_id,
    CASE
        WHEN r.runway_id LIKE '%CATARR' THEN 'ARR'
        WHEN r.runway_id LIKE '%CATDEP' THEN 'DEP'
        WHEN r.runway_id LIKE '%CATBALANCED' THEN 'BALANCED'
    END
FROM dbo.airport_config_runway r
WHERE (r.runway_id LIKE '%[_]CATARR' OR r.runway_id LIKE '%[_]CATDEP' OR r.runway_id LIKE '%[_]CATBALANCED')
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'CAT_I'
  );

PRINT 'Extracted CAT_I modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- =====================================================
-- Step 5: Extract CONFIG# patterns (NAMED variants)
-- =====================================================
PRINT '';
PRINT '=== Step 5: Extract CONFIG# patterns as NAMED variants ===';

INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    LEFT(r.runway_id, CHARINDEX('_CONFIG', r.runway_id) - 1),
    'NAMED',
    r.runway_id,
    SUBSTRING(r.runway_id, CHARINDEX('CONFIG', r.runway_id) + 6, LEN(r.runway_id))
FROM dbo.airport_config_runway r
WHERE r.runway_id LIKE '%[_]CONFIG[0-9]%'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'NAMED'
        AND cm.runway_id = LEFT(r.runway_id, CHARINDEX('_CONFIG', r.runway_id) - 1)
  );

PRINT 'Extracted NAMED (CONFIG#) modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- =====================================================
-- Step 6: Extract circling patterns (XX_CIR_YY)
-- =====================================================
PRINT '';
PRINT '=== Step 6: Extract additional circling patterns ===';

INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    CASE
        WHEN r.runway_id LIKE '%[_]CIR[_]%' THEN LEFT(r.runway_id, CHARINDEX('_CIR_', r.runway_id) - 1)
        WHEN r.runway_id LIKE '%[_]CIRC[_]%' THEN LEFT(r.runway_id, CHARINDEX('_CIRC_', r.runway_id) - 1)
        ELSE LEFT(r.runway_id, LEN(r.runway_id) - 4)
    END,
    'CIRCLING',
    r.runway_id,
    CASE
        WHEN r.runway_id LIKE '%[_]CIR[_]%' THEN SUBSTRING(r.runway_id, CHARINDEX('_CIR_', r.runway_id) + 5, LEN(r.runway_id))
        WHEN r.runway_id LIKE '%[_]CIRC[_]%' THEN SUBSTRING(r.runway_id, CHARINDEX('_CIRC_', r.runway_id) + 6, LEN(r.runway_id))
        ELSE NULL
    END
FROM dbo.airport_config_runway r
WHERE (r.runway_id LIKE '%[_]CIR[_]%' OR r.runway_id LIKE '%[_]CIRC[_]%')
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'CIRCLING'
  );

PRINT 'Extracted additional CIRCLING modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- =====================================================
-- Step 7: Extract STAGGER patterns from runway_id
-- =====================================================
PRINT '';
PRINT '=== Step 7: Extract STAGGER patterns ===';

INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    LEFT(r.runway_id, CHARINDEX('_STAGGER', r.runway_id) - 1),
    'STAGGERED',
    r.runway_id,
    NULL
FROM dbo.airport_config_runway r
WHERE r.runway_id LIKE '%[_]STAGGER'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'STAGGERED'
        AND cm.runway_id = LEFT(r.runway_id, CHARINDEX('_STAGGER', r.runway_id) - 1)
  );

PRINT 'Extracted STAGGERED modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- =====================================================
-- Step 8: Extract SRO patterns (SRO_ARR, SRO_DEP, etc.)
-- =====================================================
PRINT '';
PRINT '=== Step 8: Handle SRO patterns ===';

-- Mark SRO as config-level SINGLE_RWY modifier
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    NULL,  -- Config-level
    'SINGLE_RWY',
    r.runway_id,
    CASE
        WHEN r.runway_id LIKE 'SRO[_]ARR' THEN 'ARR'
        WHEN r.runway_id LIKE 'SRO[_]DEP' THEN 'DEP'
        WHEN r.runway_id LIKE 'SRO[_]BALANCED' THEN 'BALANCED'
        ELSE NULL
    END
FROM dbo.airport_config_runway r
WHERE r.runway_id LIKE 'SRO[_]%'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'SINGLE_RWY' AND cm.runway_id IS NULL
  );

PRINT 'Extracted SRO config-level modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- =====================================================
-- Step 9: Normalize runway_id values
-- =====================================================
PRINT '';
PRINT '=== Step 9: Normalize runway_id values ===';

-- European patterns: XX_ARR, XX_ARRONLY, XX_DEP, XX_DEPONLY
UPDATE dbo.airport_config_runway
SET runway_id = LEFT(runway_id, CHARINDEX('_ARRONLY', runway_id) - 1)
WHERE runway_id LIKE '%[_]ARRONLY';

UPDATE dbo.airport_config_runway
SET runway_id = LEFT(runway_id, CHARINDEX('_DEPONLY', runway_id) - 1)
WHERE runway_id LIKE '%[_]DEPONLY';

UPDATE dbo.airport_config_runway
SET runway_id = LEFT(runway_id, LEN(runway_id) - 4)
WHERE runway_id LIKE '%[_]ARR'
  AND runway_id NOT LIKE '%ARRHEAVY%';

UPDATE dbo.airport_config_runway
SET runway_id = LEFT(runway_id, LEN(runway_id) - 4)
WHERE runway_id LIKE '%[_]DEP'
  AND runway_id NOT LIKE '%DEPHEAVY%';

UPDATE dbo.airport_config_runway
SET runway_id = LEFT(runway_id, CHARINDEX('_ARRHEAVY', runway_id) - 1)
WHERE runway_id LIKE '%[_]ARRHEAVY';

UPDATE dbo.airport_config_runway
SET runway_id = LEFT(runway_id, CHARINDEX('_DEPHEAVY', runway_id) - 1)
WHERE runway_id LIKE '%[_]DEPHEAVY';

UPDATE dbo.airport_config_runway
SET runway_id = LEFT(runway_id, CHARINDEX('_BALANCED', runway_id) - 1)
WHERE runway_id LIKE '%[_]BALANCED';

UPDATE dbo.airport_config_runway
SET runway_id = LEFT(runway_id, CHARINDEX('_MIXED', runway_id) - 1)
WHERE runway_id LIKE '%[_]MIXED';

PRINT 'Normalized European traffic bias patterns';

-- Time patterns
UPDATE dbo.airport_config_runway
SET runway_id = LEFT(runway_id, CHARINDEX('_DAY', runway_id) - 1)
WHERE runway_id LIKE '%[_]DAY';

UPDATE dbo.airport_config_runway
SET runway_id = LEFT(runway_id, CHARINDEX('_NIGHT', runway_id) - 1)
WHERE runway_id LIKE '%[_]NIGHT';

PRINT 'Normalized time restriction patterns';

-- CAT patterns
UPDATE dbo.airport_config_runway
SET runway_id = LEFT(runway_id, CHARINDEX('_CATARR', runway_id) - 1)
WHERE runway_id LIKE '%[_]CATARR';

UPDATE dbo.airport_config_runway
SET runway_id = LEFT(runway_id, CHARINDEX('_CATDEP', runway_id) - 1)
WHERE runway_id LIKE '%[_]CATDEP';

UPDATE dbo.airport_config_runway
SET runway_id = LEFT(runway_id, CHARINDEX('_CATBALANCED', runway_id) - 1)
WHERE runway_id LIKE '%[_]CATBALANCED';

PRINT 'Normalized CAT patterns';

-- CONFIG patterns
UPDATE dbo.airport_config_runway
SET runway_id = LEFT(runway_id, CHARINDEX('_CONFIG', runway_id) - 1)
WHERE runway_id LIKE '%[_]CONFIG[0-9]%';

PRINT 'Normalized CONFIG# patterns';

-- Circling patterns
UPDATE dbo.airport_config_runway
SET runway_id = LEFT(runway_id, CHARINDEX('_CIR_', runway_id) - 1)
WHERE runway_id LIKE '%[_]CIR[_]%';

UPDATE dbo.airport_config_runway
SET runway_id = LEFT(runway_id, CHARINDEX('_CIRC_', runway_id) - 1)
WHERE runway_id LIKE '%[_]CIRC[_]%';

PRINT 'Normalized circling patterns';

-- STAGGER patterns
UPDATE dbo.airport_config_runway
SET runway_id = LEFT(runway_id, CHARINDEX('_STAGGER', runway_id) - 1)
WHERE runway_id LIKE '%[_]STAGGER';

PRINT 'Normalized STAGGER patterns';

-- SRO patterns - these become the actual runway from the config
-- For now, just mark them as 'SRO' since we don't know which runway
UPDATE dbo.airport_config_runway
SET runway_id = 'SRO'
WHERE runway_id LIKE 'SRO[_]%';

PRINT 'Normalized SRO patterns';

-- =====================================================
-- Summary
-- =====================================================
PRINT '';
PRINT '=== Migration 093b Summary ===';

SELECT 'Total config_modifier records' AS metric, COUNT(*) AS value FROM dbo.config_modifier;

SELECT
    mc.category_name AS category,
    COUNT(cm.id) AS modifier_count
FROM dbo.modifier_category mc
LEFT JOIN dbo.modifier_type mt ON mc.category_code = mt.category_code
LEFT JOIN dbo.config_modifier cm ON mt.modifier_code = cm.modifier_code
GROUP BY mc.category_name, mc.display_order
ORDER BY mc.display_order;

PRINT '';
PRINT '=== Remaining runway IDs that may need manual review ===';

SELECT DISTINCT
    c.airport_icao,
    r.runway_id,
    LEN(r.runway_id) AS len
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

PRINT '';
PRINT 'Migration 093b completed.';
GO
