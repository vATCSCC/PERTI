-- ============================================================================
-- Fix: Correct phase snapshot procedure and reset data
--
-- Issues fixed:
-- 1. sp_CapturePhaseSnapshot was using OOOI timestamps instead of phase column
-- 2. Backfill data had incorrect phase counts (departed/descending = 0)
-- 3. Stale flights weren't being cleaned up (is_active stuck at 1)
-- ============================================================================

SET NOCOUNT ON;

PRINT '============================================================================';
PRINT 'Fixing Phase Snapshot System';
PRINT '============================================================================';

-- ============================================================================
-- Step 1: Clean up stale "active" flights first
-- ============================================================================

PRINT 'Step 1: Cleaning up stale flights...';

DECLARE @cleaned INT;
UPDATE dbo.adl_flight_core
SET is_active = 0, phase = 'arrived'
WHERE is_active = 1
  AND last_seen_utc < DATEADD(MINUTE, -5, SYSUTCDATETIME());

SET @cleaned = @@ROWCOUNT;
PRINT CONCAT('  Cleaned up ', @cleaned, ' stale flights');

-- ============================================================================
-- Step 2: Recreate sp_CapturePhaseSnapshot with correct logic
-- ============================================================================

PRINT '';
PRINT 'Step 2: Recreating sp_CapturePhaseSnapshot...';

IF OBJECT_ID('dbo.sp_CapturePhaseSnapshot', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_CapturePhaseSnapshot;
GO

CREATE PROCEDURE dbo.sp_CapturePhaseSnapshot
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @now DATETIME2(0) = SYSUTCDATETIME();

    -- Insert snapshot using the actual phase column values
    INSERT INTO dbo.flight_phase_snapshot (
        snapshot_utc,
        prefile_cnt,
        taxiing_cnt,
        departed_cnt,
        enroute_cnt,
        descending_cnt,
        arrived_cnt,
        unknown_cnt,
        total_active
    )
    SELECT
        @now,
        COUNT(CASE WHEN phase = 'prefile' THEN 1 END),
        COUNT(CASE WHEN phase = 'taxiing' THEN 1 END),
        COUNT(CASE WHEN phase = 'departed' THEN 1 END),
        COUNT(CASE WHEN phase = 'enroute' THEN 1 END),
        COUNT(CASE WHEN phase = 'descending' THEN 1 END),
        COUNT(CASE WHEN phase = 'arrived' THEN 1 END),
        COUNT(CASE WHEN phase IS NULL OR phase NOT IN ('prefile','taxiing','departed','enroute','descending','arrived') THEN 1 END),
        COUNT(*)
    FROM dbo.adl_flight_core
    WHERE is_active = 1;

    -- Cleanup: Delete snapshots older than 48 hours
    DELETE FROM dbo.flight_phase_snapshot
    WHERE snapshot_utc < DATEADD(HOUR, -48, @now);
END
GO

PRINT '  Created sp_CapturePhaseSnapshot (uses phase column)';

-- ============================================================================
-- Step 3: Clear bad backfill data and capture fresh snapshot
-- ============================================================================

PRINT '';
PRINT 'Step 3: Clearing old snapshot data...';

TRUNCATE TABLE dbo.flight_phase_snapshot;
PRINT '  Cleared snapshot table';

-- Capture first correct snapshot
EXEC dbo.sp_CapturePhaseSnapshot;
PRINT '  Captured initial snapshot with correct phase data';

-- ============================================================================
-- Step 4: Verify the fix
-- ============================================================================

PRINT '';
PRINT 'Step 4: Verification...';

SELECT
    'Current active flights' as metric,
    COUNT(*) as value
FROM dbo.adl_flight_core
WHERE is_active = 1;

SELECT
    'Phase distribution' as info,
    phase,
    COUNT(*) as cnt
FROM dbo.adl_flight_core
WHERE is_active = 1
GROUP BY phase
ORDER BY cnt DESC;

SELECT
    'Latest snapshot' as info,
    snapshot_utc,
    taxiing_cnt,
    departed_cnt,
    enroute_cnt,
    descending_cnt,
    arrived_cnt,
    total_active
FROM dbo.flight_phase_snapshot
ORDER BY snapshot_utc DESC;

PRINT '';
PRINT '============================================================================';
PRINT 'Fix Complete';
PRINT '';
PRINT 'The chart will now show accurate data as new snapshots are captured.';
PRINT 'Historical data will build up over the next 24 hours.';
PRINT '';
PRINT 'IMPORTANT: Also run sp_Adl_RefreshFromVatsim_Normalized.sql to ensure';
PRINT 'the cleanup step runs on every refresh cycle.';
PRINT '============================================================================';
GO
