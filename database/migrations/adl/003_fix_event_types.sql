-- ============================================================================
-- Migration: Fix event_type classification and deduplicate perti_events
-- Database: VATSIM_ADL
-- Created: 2026-02-02
-- Purpose:
--   1. Add OMN (Open Mic Night) and SAT event types
--   2. Remove duplicate events (prefer VATSIM over VATUSA/VATCAN)
--   3. Reclassify events using time-based logic (matches Excel formula)
-- ============================================================================

USE VATSIM_ADL;
GO

-- ============================================================================
-- STEP 1: UPDATE CONSTRAINT TO ADD OMN AND SAT
-- ============================================================================

PRINT 'Step 1: Updating event_type constraint to add OMN and SAT...';

-- Drop and recreate the constraint with new types
IF EXISTS (SELECT * FROM sys.check_constraints WHERE name = 'CK_perti_events_type')
BEGIN
    ALTER TABLE dbo.perti_events DROP CONSTRAINT CK_perti_events_type;
    PRINT '  Dropped old constraint';
END

ALTER TABLE dbo.perti_events ADD CONSTRAINT CK_perti_events_type CHECK (
    event_type IN (
        'FNO',      -- Friday Night Ops (includes late Fri / early Sat)
        'SAT',      -- Saturday events
        'SUN',      -- Sunday events
        'MWK',      -- Mid-Week (Mon-Thu, default for VATUSA)
        'OMN',      -- Open Mic Night
        'CTP',      -- Cross The Pond
        'CTL',      -- Cross The Land
        'WF',       -- WorldFlight
        '24HRSOV',  -- 24 Hour Sovereignty
        'LIVE',     -- Live Event
        'REALOPS',  -- Real Operations
        'TRAIN',    -- Training/Exam
        'REG',      -- Regional recurring
        'SPEC',     -- Special one-time
        'UNKN'      -- Unknown/Unclassified (default for non-VATUSA)
    )
);
PRINT '  Added new constraint with OMN and SAT';
GO

-- ============================================================================
-- STEP 2: DEDUPLICATE EVENTS
-- Priority: VATSIM > VATCAN > VATUSA
-- ============================================================================

PRINT '';
PRINT 'Step 2: Deduplicating events (preferring VATSIM data)...';

WITH DuplicateCandidates AS (
    SELECT
        p1.event_id AS keep_id,
        p1.source AS keep_source,
        p2.event_id AS remove_id,
        p2.source AS remove_source
    FROM dbo.perti_events p1
    JOIN dbo.perti_events p2 ON p1.event_id < p2.event_id
    WHERE
        -- Same day, overlapping times
        CAST(p1.start_utc AS DATE) = CAST(p2.start_utc AS DATE)
        AND (p1.start_utc <= p2.end_utc AND p1.end_utc >= p2.start_utc)
        AND (
            -- Similar names (fuzzy match)
            p1.event_name LIKE '%' + LEFT(p2.event_name, 15) + '%'
            OR p2.event_name LIKE '%' + LEFT(p1.event_name, 15) + '%'
            OR SOUNDEX(p1.event_name) = SOUNDEX(p2.event_name)
            -- Or same featured airports
            OR (p1.featured_airports IS NOT NULL
                AND p2.featured_airports IS NOT NULL
                AND p1.featured_airports = p2.featured_airports)
        )
        -- Prefer VATSIM over others
        AND (
            (p1.source = 'VATSIM' AND p2.source IN ('VATUSA', 'VATCAN'))
            OR (p1.source = 'VATCAN' AND p2.source = 'VATUSA')
        )
)
DELETE FROM dbo.perti_events
WHERE event_id IN (SELECT remove_id FROM DuplicateCandidates);

PRINT '  Duplicates removed: ' + CAST(@@ROWCOUNT AS VARCHAR(10));
GO

-- ============================================================================
-- STEP 3: RECLASSIFY EVENT TYPES
--
-- NOTE: FNO, SNO, OMN, and day-of-week types (SAT, SUN, MWK) are VATUSA
-- event conventions. Time-based classification only applies to VATUSA events.
-- Other divisions (VATSIM global, VATCAN, etc.) use name-based detection only.
-- ============================================================================

PRINT '';
PRINT 'Step 3: Reclassifying event types...';

-- STEP 3a: Apply name-based classification to ALL events
UPDATE dbo.perti_events
SET event_type = CASE
    -- Explicit exclusions (VATUSA convention)
    WHEN event_name LIKE '%Not An FNO%' OR event_name LIKE '%Not a FNO%' THEN 'MWK'

    -- Cross-division special events (by name)
    WHEN LEFT(event_name, 14) = 'Cross the Pond' OR event_name LIKE '%CTP%' THEN 'CTP'
    WHEN event_name LIKE '%Cross The Land%' OR event_name LIKE '%CTL%' THEN 'CTL'
    WHEN event_name LIKE '%WorldFlight%' OR event_name LIKE '%World Flight%' THEN 'WF'
    WHEN event_name LIKE '%Sovereignty%' OR event_name LIKE '%24HR%' THEN '24HRSOV'

    -- VATUSA conventions (by name)
    WHEN event_name LIKE '%FNO%' THEN 'FNO'
    WHEN event_name LIKE '%SNO%' AND event_name NOT LIKE '%KSNO%' THEN 'SAT'
    -- OMN: Open Mic Night, but not KOMN (Ormond Beach airport)
    WHEN event_name LIKE '%Open Mic%' THEN 'OMN'
    WHEN event_name LIKE '% OMN%' AND event_name NOT LIKE '%KOMN%' AND event_name NOT LIKE '%Ormond%' THEN 'OMN'
    WHEN event_name LIKE '%OMN %' AND event_name NOT LIKE '%KOMN%' AND event_name NOT LIKE '%Ormond%' THEN 'OMN'

    -- Real Ops (any division)
    WHEN event_name LIKE '%Real Op%' OR event_name LIKE '%RealOps%' THEN 'REALOPS'

    -- Live events (any division)
    WHEN event_name LIKE '%Live%' AND event_name NOT LIKE '%Olive%' THEN 'LIVE'

    -- Training/Exam (any division)
    WHEN event_name LIKE '%Exam%' OR event_name LIKE '%First Wings%' THEN 'TRAIN'

    -- Special events
    WHEN event_name LIKE '%CROSS VATRUS%' OR event_name LIKE '%Overload%' THEN 'SPEC'

    ELSE event_type
END,
updated_utc = SYSUTCDATETIME()
WHERE event_type = 'OTHER' OR event_type IS NULL;

PRINT '  Name-based classification applied: ' + CAST(@@ROWCOUNT AS VARCHAR(10));

-- STEP 3b: Apply time-based classification to VATUSA events only
-- This matches the Excel formula logic for day-of-week detection
UPDATE dbo.perti_events
SET event_type = CASE
    -- FNO: Friday 21:00+ or Saturday before 06:00 UTC
    WHEN (DATENAME(WEEKDAY, start_utc) = 'Friday' AND CAST(start_utc AS TIME) >= '21:00:00')
         OR (DATENAME(WEEKDAY, start_utc) = 'Saturday' AND CAST(start_utc AS TIME) < '06:00:00') THEN 'FNO'

    -- SAT: Saturday events (not early morning FNO crossover)
    WHEN DATENAME(WEEKDAY, start_utc) = 'Saturday' THEN 'SAT'

    -- SUN: Sunday events
    WHEN DATENAME(WEEKDAY, start_utc) = 'Sunday' THEN 'SUN'

    -- MWK: Mon-Thu (default for VATUSA)
    ELSE 'MWK'
END,
updated_utc = SYSUTCDATETIME()
WHERE (event_type = 'OTHER' OR event_type IS NULL)
  AND (source = 'VATUSA' OR divisions LIKE '%VATUSA%' OR divisions LIKE '%USA%');

PRINT '  VATUSA time-based classification applied: ' + CAST(@@ROWCOUNT AS VARCHAR(10));

-- STEP 3c: Default remaining events to UNKN (unknown) for non-VATUSA
UPDATE dbo.perti_events
SET event_type = 'UNKN',
    updated_utc = SYSUTCDATETIME()
WHERE event_type = 'OTHER' OR event_type IS NULL;

PRINT '  Event types updated: ' + CAST(@@ROWCOUNT AS VARCHAR(10));
GO

-- ============================================================================
-- STEP 4: SUMMARY
-- ============================================================================

PRINT '';
PRINT '=== FINAL SUMMARY ===';
PRINT '';

-- By source
SELECT
    source,
    COUNT(*) as event_count
FROM dbo.perti_events
GROUP BY source
ORDER BY event_count DESC;

PRINT '';

-- By event type
SELECT
    event_type,
    COUNT(*) as event_count,
    MIN(event_name) as sample_event
FROM dbo.perti_events
GROUP BY event_type
ORDER BY event_count DESC;

PRINT '';
PRINT 'Migration 003_fix_event_types.sql complete.';
PRINT '';
PRINT 'VATUSA event types (day-of-week based):';
PRINT '  FNO   - Friday Night Ops (Fri 21:00+ / Sat <06:00 UTC)';
PRINT '  SAT   - Saturday events';
PRINT '  SUN   - Sunday events';
PRINT '  MWK   - Mid-Week (Mon-Thu, default)';
PRINT '  OMN   - Open Mic Night';
PRINT '';
PRINT 'Cross-division event types (name-based):';
PRINT '  CTP   - Cross The Pond';
PRINT '  CTL   - Cross The Land';
PRINT '  WF    - WorldFlight';
PRINT '  24HRSOV - 24 Hour Sovereignty';
PRINT '  LIVE  - Live events';
PRINT '  REALOPS - Real Operations';
PRINT '  TRAIN - Training/Exams';
PRINT '  SPEC  - Special events';
PRINT '  REG   - Regional recurring';
PRINT '  UNKN  - Unknown/Unclassified (non-VATUSA default)';
GO
