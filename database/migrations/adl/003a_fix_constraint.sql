-- ============================================================================
-- Migration: Fix CK_perti_events_type constraint
-- Database: VATSIM_ADL
-- Created: 2026-02-02
--
-- Problem: Migration 003 failed because existing rows have 'OTHER' event_type
-- which is not in the new constraint. This script fixes that.
-- ============================================================================

USE VATSIM_ADL;
GO

PRINT 'Fixing event_type constraint...';
PRINT '';

-- Step 1: Check current state
PRINT 'Current event_type distribution:';
SELECT event_type, COUNT(*) AS cnt
FROM dbo.perti_events
GROUP BY event_type
ORDER BY cnt DESC;

-- Step 2: Update any 'OTHER' values to 'UNKN' before adding constraint
PRINT '';
PRINT 'Updating OTHER to UNKN...';
UPDATE dbo.perti_events
SET event_type = 'UNKN'
WHERE event_type = 'OTHER';
PRINT '  Rows updated: ' + CAST(@@ROWCOUNT AS VARCHAR(10));

-- Step 3: Drop old constraint if exists
IF EXISTS (SELECT * FROM sys.check_constraints WHERE name = 'CK_perti_events_type')
BEGIN
    ALTER TABLE dbo.perti_events DROP CONSTRAINT CK_perti_events_type;
    PRINT '  Dropped old constraint';
END

-- Step 4: Add new constraint
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
PRINT '  Added new constraint with all event types';

-- Step 5: Verify
PRINT '';
PRINT 'Final event_type distribution:';
SELECT event_type, COUNT(*) AS cnt
FROM dbo.perti_events
GROUP BY event_type
ORDER BY cnt DESC;

PRINT '';
PRINT 'Constraint fix complete!';
GO
