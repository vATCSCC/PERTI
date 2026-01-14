-- =====================================================
-- Fix Edge Case Runway IDs
-- Migration: 093c
-- Description: Manual fixes for specific edge cases
-- =====================================================

SET NOCOUNT ON;

PRINT '=== Migration 093c: Fix Edge Case Runway IDs ===';
PRINT '';

-- =====================================================
-- CYYZ: Generic flow configs - normalize to descriptive
-- placeholders and store original in notes
-- =====================================================
PRINT '=== CYYZ: Normalize generic flow config entries ===';

-- LAND_1_DEPART_OTHER_EAST -> 1A1D_E (1 Arrival, 1 Departure, East flow)
UPDATE dbo.airport_config_runway
SET notes = runway_id,
    runway_id = '1A1D_E'
WHERE runway_id = 'LAND_1_DEPART_OTHER_EAST';

PRINT 'Updated LAND_1_DEPART_OTHER_EAST: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- WEST_(W -> W_FLOW (West flow, truncated original)
UPDATE dbo.airport_config_runway
SET notes = runway_id,
    runway_id = 'W_FLOW'
WHERE runway_id = 'WEST_(W';

PRINT 'Updated WEST_(W: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- =====================================================
-- KBOS: 15V04L -> 04L with VISUAL modifier (from 15)
-- =====================================================
PRINT '';
PRINT '=== KBOS: Fix 15V04L -> 04L + VISUAL modifier ===';

-- Add VISUAL modifier
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    '04L',
    'VISUAL',
    r.runway_id,
    '15'  -- Approach from 15 heading
FROM dbo.airport_config_runway r
WHERE r.runway_id = '15V04L'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.runway_id = '04L' AND cm.modifier_code = 'VISUAL'
  );

PRINT 'Added VISUAL modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- Update runway_id
UPDATE dbo.airport_config_runway
SET runway_id = '04L'
WHERE runway_id = '15V04L';

PRINT 'Updated 15V04L to 04L: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- =====================================================
-- KDAL: SINGLE_RUNWAY -> SRO config-level modifier
-- =====================================================
PRINT '';
PRINT '=== KDAL: Fix SINGLE_RUNWAY -> SRO modifier ===';

-- Add config-level SRO modifier
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    NULL,  -- Config-level
    'SINGLE_RWY',
    'SINGLE_RUNWAY',
    NULL
FROM dbo.airport_config_runway r
WHERE r.runway_id = 'SINGLE_RUNWAY'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'SINGLE_RWY' AND cm.runway_id IS NULL
  );

PRINT 'Added SINGLE_RWY modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- Delete the SINGLE_RUNWAY runway entries (they're not real runways)
DELETE FROM dbo.airport_config_runway
WHERE runway_id = 'SINGLE_RUNWAY';

PRINT 'Deleted SINGLE_RUNWAY entries: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- =====================================================
-- KEWR: 04R_OVHD_29 -> 04R, add 29 as separate runway
-- (Overhead pattern from 04R approach to land 29)
-- =====================================================
PRINT '';
PRINT '=== KEWR: Fix 04R_OVHD_29 -> 04R + 29 ===';

-- Add circling/overhead modifier
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    '04R',
    'CIRCLING',
    r.runway_id,
    '29'  -- Circle to land 29
FROM dbo.airport_config_runway r
WHERE r.runway_id = '04R_OVHD_29'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.modifier_code = 'CIRCLING'
  );

PRINT 'Added CIRCLING modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- Add 29 as additional arrival runway
INSERT INTO dbo.airport_config_runway (config_id, runway_id, runway_use, priority)
SELECT DISTINCT
    r.config_id,
    '29',
    'ARR',
    r.priority + 1
FROM dbo.airport_config_runway r
WHERE r.runway_id = '04R_OVHD_29'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.airport_config_runway r2
      WHERE r2.config_id = r.config_id AND r2.runway_id = '29'
  );

PRINT 'Added 29 arrival runway: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- Update 04R_OVHD_29 to 04R
UPDATE dbo.airport_config_runway
SET runway_id = '04R'
WHERE runway_id = '04R_OVHD_29';

PRINT 'Updated 04R_OVHD_29 to 04R: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- =====================================================
-- KHPN: SOUND_VIS_34 -> 34 with VISUAL modifier
-- =====================================================
PRINT '';
PRINT '=== KHPN: Fix SOUND_VIS_34 -> 34 + VISUAL modifier ===';

INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    '34',
    'VISUAL',
    r.runway_id,
    'SOUND'  -- Long Island Sound Visual
FROM dbo.airport_config_runway r
WHERE r.runway_id = 'SOUND_VIS_34'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.runway_id = '34' AND cm.modifier_code = 'VISUAL'
  );

PRINT 'Added VISUAL modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR);

UPDATE dbo.airport_config_runway
SET runway_id = '34'
WHERE runway_id = 'SOUND_VIS_34';

PRINT 'Updated SOUND_VIS_34 to 34: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- =====================================================
-- KLGA: EXPY_31 -> 31 with VISUAL modifier
-- =====================================================
PRINT '';
PRINT '=== KLGA: Fix EXPY_31 -> 31 + VISUAL modifier ===';

INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    '31',
    'VISUAL',
    r.runway_id,
    'EXPY'  -- Expressway Visual
FROM dbo.airport_config_runway r
WHERE r.runway_id = 'EXPY_31'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.runway_id = '31' AND cm.modifier_code = 'VISUAL'
  );

PRINT 'Added VISUAL modifiers: ' + CAST(@@ROWCOUNT AS VARCHAR);

UPDATE dbo.airport_config_runway
SET runway_id = '31'
WHERE runway_id = 'EXPY_31';

PRINT 'Updated EXPY_31 to 31: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- =====================================================
-- KLGA: ILS orphan -> delete (22 is already there)
-- =====================================================
PRINT '';
PRINT '=== KLGA: Delete orphan ILS entries ===';

-- Add ILS modifier to runway 22 in those configs
INSERT INTO dbo.config_modifier (config_id, runway_id, modifier_code, original_value, variant_value)
SELECT DISTINCT
    r.config_id,
    '22',
    'ILS',
    'ILS',
    NULL
FROM dbo.airport_config_runway r
WHERE r.runway_id = 'ILS'
  AND EXISTS (
      SELECT 1 FROM dbo.airport_config_runway r2
      WHERE r2.config_id = r.config_id AND r2.runway_id = '22'
  )
  AND NOT EXISTS (
      SELECT 1 FROM dbo.config_modifier cm
      WHERE cm.config_id = r.config_id AND cm.runway_id = '22' AND cm.modifier_code = 'ILS'
  );

PRINT 'Added ILS modifiers to 22: ' + CAST(@@ROWCOUNT AS VARCHAR);

DELETE FROM dbo.airport_config_runway
WHERE runway_id = 'ILS';

PRINT 'Deleted orphan ILS entries: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- =====================================================
-- KMCO: Shared runway configs - store original in notes
-- =====================================================
PRINT '';
PRINT '=== KMCO: Fix shared runway configs ===';

-- Update notes to preserve the shared runway info
UPDATE dbo.airport_config_runway
SET notes = runway_id,
    runway_id = CASE
        WHEN runway_id LIKE '(N)%' THEN 'NORTH'
        WHEN runway_id LIKE '(S)%' THEN 'SOUTH'
    END
WHERE runway_id IN ('(N)_1_Shared_RWY', '(N)_2_Shared_RWYs', '(S)_1_Shared_RWY', '(S)_2_Shared_RWYs');

PRINT 'Updated KMCO shared runway entries: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- =====================================================
-- KMSP: Fix badly parsed entries
-- =====================================================
PRINT '';
PRINT '=== KMSP: Fix badly parsed entries ===';

-- Config 8162: 12R_(17_N and A) -> 12R with note "17 N/A"
UPDATE dbo.airport_config_runway
SET runway_id = '12R',
    notes = '17 N/A'
WHERE runway_id = '12R_(17_N';

UPDATE dbo.airport_config_runway
SET runway_id = '12L'  -- Assume this was meant to be another runway
WHERE runway_id = 'A)' AND config_id = 8162;

-- Config 8165: 30R_(35_N and A) and R -> 30R and 30L with note "35 N/A"
UPDATE dbo.airport_config_runway
SET runway_id = '30R',
    notes = '35 N/A'
WHERE runway_id = '30R_(35_N';

UPDATE dbo.airport_config_runway
SET runway_id = '30L'
WHERE runway_id = 'A)' AND config_id = 8165;

UPDATE dbo.airport_config_runway
SET runway_id = '30R'
WHERE runway_id = 'R' AND config_id = 8165;

-- Config 8170: 35_(Parallels_N and A) -> 35 with note "Parallels N/A"
UPDATE dbo.airport_config_runway
SET runway_id = '35',
    notes = 'Parallels N/A'
WHERE runway_id = '35_(Parallels_N';

-- Delete orphan A) from config 8170
DELETE FROM dbo.airport_config_runway
WHERE runway_id = 'A)' AND config_id = 8170;

PRINT 'Fixed KMSP entries';

-- =====================================================
-- KTEB: CIR orphan -> delete or fix
-- (RNAV 19 circle was already extracted, CIR is leftover)
-- =====================================================
PRINT '';
PRINT '=== KTEB: Fix CIR orphan ===';

-- Update CIR to show actual runway (likely circling to land a different runway)
-- Based on config "RNAV_19_CIR / 24", the circle is probably to land 19 or 6
UPDATE dbo.airport_config_runway
SET runway_id = '19',
    notes = 'RNAV circle'
WHERE runway_id = 'CIR';

PRINT 'Updated CIR entries: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- =====================================================
-- LFBO: NO_TWY notes -> extract to notes field
-- =====================================================
PRINT '';
PRINT '=== LFBO: Fix taxiway restriction entries ===';

UPDATE dbo.airport_config_runway
SET notes = 'TWY M4 N/A',
    runway_id = '14R'
WHERE runway_id = '14R_NO_TWY_M4';

UPDATE dbo.airport_config_runway
SET notes = 'TWY M8 N/A',
    runway_id = '32R'
WHERE runway_id = '32R_NO_TWY_M8';

PRINT 'Fixed LFBO taxiway restriction entries';

-- =====================================================
-- Summary
-- =====================================================
PRINT '';
PRINT '=== Migration 093c Summary ===';

PRINT 'Checking for remaining unusual runway IDs...';

SELECT DISTINCT
    c.airport_icao,
    r.runway_id,
    r.notes,
    LEN(r.runway_id) AS len
FROM dbo.airport_config_runway r
JOIN dbo.airport_config c ON r.config_id = c.config_id
WHERE r.runway_id NOT LIKE '[0-9]'
  AND r.runway_id NOT LIKE '[0-9][0-9]'
  AND r.runway_id NOT LIKE '[0-9][LRC]'
  AND r.runway_id NOT LIKE '[0-9][0-9][LRC]'
  AND r.runway_id NOT LIKE 'SRO'
  AND r.runway_id NOT IN ('NORTH', 'SOUTH')  -- KMCO flow designators
  AND r.runway_id NOT IN ('1A1D_E', 'W_FLOW')  -- CYYZ generic flow configs
  AND r.runway_id IS NOT NULL
  AND LEN(r.runway_id) > 0
ORDER BY c.airport_icao, r.runway_id;

PRINT '';
PRINT 'Migration 093c completed.';
GO
