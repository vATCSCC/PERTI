-- ============================================================================
-- 009_arrival_detection_backfill.sql
-- Backfill Arrival Detection Using touchdown_utc and Reduced Distance Threshold
--
-- This migration corrects historical flights that were incorrectly marked as:
-- - 'disconnected' when they actually arrived (had touchdown_utc or were <10nm)
--
-- Priority: touchdown_utc (zone detection) > 10nm distance fallback
-- Target Database: VATSIM_ADL
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '==========================================================================';
PRINT '  Arrival Detection Backfill Migration';
PRINT '  Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
PRINT '';
GO

-- ============================================================================
-- Pre-Migration Diagnostics
-- ============================================================================

PRINT '--- Pre-Migration Statistics ---';
PRINT '';

-- Current phase distribution
SELECT
    phase,
    COUNT(*) AS flight_count
FROM dbo.adl_flight_core
WHERE is_active = 0
GROUP BY phase
ORDER BY flight_count DESC;

DECLARE @disconnected_with_touchdown INT;
DECLARE @disconnected_under_10nm INT;
DECLARE @arrived_10_to_50nm INT;

-- Flights with touchdown_utc that are marked disconnected
SELECT @disconnected_with_touchdown = COUNT(*)
FROM dbo.adl_flight_core c
INNER JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
WHERE c.is_active = 0
  AND c.phase = 'disconnected'
  AND t.touchdown_utc IS NOT NULL;

PRINT 'Disconnected with touchdown_utc (will fix): ' + CAST(@disconnected_with_touchdown AS VARCHAR);

-- Flights <10nm marked disconnected
SELECT @disconnected_under_10nm = COUNT(*)
FROM dbo.adl_flight_core c
INNER JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
WHERE c.is_active = 0
  AND c.phase = 'disconnected'
  AND (t.touchdown_utc IS NULL OR t.flight_uid IS NULL)
  AND p.dist_to_dest_nm < 10;

PRINT 'Disconnected but <10nm (will fix): ' + CAST(@disconnected_under_10nm AS VARCHAR);

-- Flights marked arrived at 10-50nm without touchdown (for review)
SELECT @arrived_10_to_50nm = COUNT(*)
FROM dbo.adl_flight_core c
INNER JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
WHERE c.is_active = 0
  AND c.phase = 'arrived'
  AND (t.touchdown_utc IS NULL OR t.flight_uid IS NULL)
  AND p.dist_to_dest_nm >= 10 AND p.dist_to_dest_nm < 50;

PRINT 'Arrived at 10-50nm without touchdown (info only): ' + CAST(@arrived_10_to_50nm AS VARCHAR);
PRINT '';
GO

-- ============================================================================
-- Step 1: Fix flights with touchdown_utc that are marked disconnected
-- These are DEFINITIVE arrivals - zone detection confirmed landing
-- ============================================================================

PRINT '--- Step 1: Fix flights with touchdown_utc marked as disconnected ---';

DECLARE @step1_core INT = 0;
DECLARE @step1_times INT = 0;

-- Update phase to arrived
UPDATE c
SET c.phase = 'arrived'
FROM dbo.adl_flight_core c
INNER JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
WHERE c.is_active = 0
  AND c.phase = 'disconnected'
  AND t.touchdown_utc IS NOT NULL;

SET @step1_core = @@ROWCOUNT;

-- Update ata_utc from touchdown_utc if not already set
UPDATE t
SET t.ata_utc = COALESCE(t.ata_utc, t.touchdown_utc, t.on_utc),
    t.ata_runway_utc = COALESCE(t.ata_runway_utc, t.touchdown_utc),
    t.eta_prefix = 'A',
    t.times_updated_utc = SYSUTCDATETIME()
FROM dbo.adl_flight_times t
INNER JOIN dbo.adl_flight_core c ON c.flight_uid = t.flight_uid
WHERE c.is_active = 0
  AND c.phase = 'arrived'
  AND t.touchdown_utc IS NOT NULL
  AND t.ata_utc IS NULL;

SET @step1_times = @@ROWCOUNT;

PRINT 'Step 1: Updated ' + CAST(@step1_core AS VARCHAR) + ' flights from disconnected to arrived (had touchdown_utc)';
PRINT 'Step 1: Set ata_utc for ' + CAST(@step1_times AS VARCHAR) + ' flights from touchdown_utc';
GO

-- ============================================================================
-- Step 2: Fix flights <10nm from destination that are marked disconnected
-- These are PROBABLE arrivals (fallback when zone detection missed landing)
-- ============================================================================

PRINT '';
PRINT '--- Step 2: Fix flights <10nm marked as disconnected ---';

DECLARE @step2_core INT = 0;
DECLARE @step2_times INT = 0;

-- Update phase to arrived
UPDATE c
SET c.phase = 'arrived'
FROM dbo.adl_flight_core c
INNER JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
WHERE c.is_active = 0
  AND c.phase = 'disconnected'
  AND (t.touchdown_utc IS NULL OR t.flight_uid IS NULL)
  AND p.dist_to_dest_nm < 10;

SET @step2_core = @@ROWCOUNT;

-- Update ata_utc if not set
UPDATE t
SET t.ata_utc = COALESCE(t.ata_utc, c.last_seen_utc),
    t.eta_prefix = 'A',
    t.times_updated_utc = SYSUTCDATETIME()
FROM dbo.adl_flight_times t
INNER JOIN dbo.adl_flight_core c ON c.flight_uid = t.flight_uid
INNER JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
WHERE c.is_active = 0
  AND c.phase = 'arrived'
  AND t.touchdown_utc IS NULL
  AND t.ata_utc IS NULL
  AND p.dist_to_dest_nm < 10;

SET @step2_times = @@ROWCOUNT;

PRINT 'Step 2: Updated ' + CAST(@step2_core AS VARCHAR) + ' flights from disconnected to arrived (<10nm fallback)';
PRINT 'Step 2: Set ata_utc for ' + CAST(@step2_times AS VARCHAR) + ' flights using last_seen_utc';
GO

-- ============================================================================
-- Step 3: Report flights at 10-50nm currently marked as arrived
-- These were marked under old 50nm threshold - report only, no auto-change
-- ============================================================================

PRINT '';
PRINT '--- Step 3: Report flights at 10-50nm marked as arrived ---';

DECLARE @step3_count INT = 0;

SELECT @step3_count = COUNT(*)
FROM dbo.adl_flight_core c
INNER JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
WHERE c.is_active = 0
  AND c.phase = 'arrived'
  AND (t.touchdown_utc IS NULL OR t.flight_uid IS NULL)
  AND p.dist_to_dest_nm >= 10 AND p.dist_to_dest_nm < 50;

PRINT 'Step 3: Found ' + CAST(@step3_count AS VARCHAR) + ' flights at 10-50nm marked arrived without touchdown_utc';
PRINT 'Step 3: These flights remain as arrived (conservative approach - no auto-reclassification)';
GO

-- ============================================================================
-- Step 4: Update adl_flight_archive table
-- Sync phase changes to already-archived flights
-- ============================================================================

PRINT '';
PRINT '--- Step 4: Update archived flights ---';

DECLARE @step4_touchdown INT = 0;

IF OBJECT_ID('dbo.adl_flight_archive', 'U') IS NOT NULL
BEGIN
    -- Update archived flights with touchdown_utc that are marked disconnected
    UPDATE arc
    SET arc.phase = 'arrived',
        arc.ata_utc = COALESCE(arc.ata_utc, t.touchdown_utc, t.on_utc)
    FROM dbo.adl_flight_archive arc
    INNER JOIN dbo.adl_flight_times t ON t.flight_uid = arc.flight_uid
    WHERE arc.phase = 'disconnected'
      AND t.touchdown_utc IS NOT NULL;

    SET @step4_touchdown = @@ROWCOUNT;

    PRINT 'Step 4: Updated ' + CAST(@step4_touchdown AS VARCHAR) + ' archived flights with touchdown_utc';
END
ELSE
BEGIN
    PRINT 'Step 4: adl_flight_archive table does not exist - skipping';
END
GO

-- ============================================================================
-- Step 5: Ensure ata_utc consistency
-- All flights marked 'arrived' should have ata_utc set
-- ============================================================================

PRINT '';
PRINT '--- Step 5: Ensure ata_utc consistency for arrived flights ---';

DECLARE @step5_fixed INT = 0;

UPDATE t
SET t.ata_utc = COALESCE(t.touchdown_utc, t.on_utc, c.last_seen_utc),
    t.eta_prefix = 'A',
    t.times_updated_utc = SYSUTCDATETIME()
FROM dbo.adl_flight_times t
INNER JOIN dbo.adl_flight_core c ON c.flight_uid = t.flight_uid
WHERE c.is_active = 0
  AND c.phase = 'arrived'
  AND t.ata_utc IS NULL;

SET @step5_fixed = @@ROWCOUNT;

PRINT 'Step 5: Set ata_utc for ' + CAST(@step5_fixed AS VARCHAR) + ' arrived flights that were missing it';
GO

-- ============================================================================
-- Post-Migration Diagnostics
-- ============================================================================

PRINT '';
PRINT '--- Post-Migration Statistics ---';
PRINT '';

-- Updated phase distribution
SELECT
    phase,
    COUNT(*) AS flight_count
FROM dbo.adl_flight_core
WHERE is_active = 0
GROUP BY phase
ORDER BY flight_count DESC;

DECLARE @check1 INT;
DECLARE @check2 INT;

-- Verify no arrived flights missing ata_utc
SELECT @check1 = COUNT(*)
FROM dbo.adl_flight_core c
INNER JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
WHERE c.is_active = 0
  AND c.phase = 'arrived'
  AND t.ata_utc IS NULL;

PRINT 'Verification - Arrived flights missing ata_utc: ' + CAST(@check1 AS VARCHAR);

-- Verify no disconnected flights with touchdown_utc
SELECT @check2 = COUNT(*)
FROM dbo.adl_flight_core c
INNER JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
WHERE c.is_active = 0
  AND c.phase = 'disconnected'
  AND t.touchdown_utc IS NOT NULL;

PRINT 'Verification - Disconnected flights with touchdown_utc: ' + CAST(@check2 AS VARCHAR);
GO

PRINT '';
PRINT '==========================================================================';
PRINT '  Arrival Detection Backfill Migration Complete';
PRINT '  Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '';
PRINT '  Changes applied:';
PRINT '  - Flights with touchdown_utc: now marked as arrived';
PRINT '  - Flights <10nm from destination: now marked as arrived';
PRINT '  - ata_utc populated from touchdown_utc when available';
PRINT '  - Flights 10-50nm: left unchanged (conservative)';
PRINT '==========================================================================';
GO
