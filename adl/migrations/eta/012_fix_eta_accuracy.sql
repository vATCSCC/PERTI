-- ============================================================================
-- Migration 012: Fix ETA Accuracy Issues
--
-- This migration fixes three critical issues identified in ETA accuracy analysis:
--
-- 1. WIND CALCULATION NOT RUNNING
--    - Daemon was calling V1 procedure which wasn't executing
--    - Fix: Daemon updated to call sp_UpdateFlightWindAdjustments_V2
--    - File changed: scripts/vatsim_adl_daemon.php (line 1355)
--
-- 2. INCORRECT ARRIVAL DETECTION (THIS SCRIPT)
--    - Flights were marked "arrived" when pilot disconnected, regardless of location
--    - This caused flights 5000+ nm from destination to have "ata_utc" set
--    - Corrupted ETA accuracy metrics (showed -262 min average error for far flights)
--    - Fix: Only mark "arrived" if dist_to_dest < 50nm, else mark "disconnected"
--
-- 3. STALE DISTANCE DATA
--    - ETA was calculated with old distance values
--    - Root cause: Same as #2 - ATA being set for non-arrived flights
--
-- Run this script with admin credentials (db_owner or similar)
-- Date: 2026-01-27
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

PRINT '============================================================================';
PRINT 'Migration 012: Fix ETA Accuracy - Arrival Detection';
PRINT '============================================================================';
PRINT '';

-- ============================================================================
-- Update sp_Adl_RefreshFromVatsim_Staged - Step 7 Fix
-- ============================================================================

PRINT 'Updating sp_Adl_RefreshFromVatsim_Staged...';

-- We need to recreate the entire procedure to update Step 7
-- First, check if the procedure has our fix
IF OBJECT_DEFINITION(OBJECT_ID('dbo.sp_Adl_RefreshFromVatsim_Staged')) NOT LIKE '%disconnected%'
BEGIN
    PRINT '  Applying arrival detection fix...';

    -- The fix changes Step 7 from:
    --   UPDATE ... SET phase = 'arrived' WHERE last_seen < 5 min ago
    -- To:
    --   UPDATE ... SET phase = 'arrived' WHERE last_seen < 5 min ago AND dist_to_dest < 50nm
    --   UPDATE ... SET phase = 'disconnected' WHERE last_seen < 5 min ago AND dist_to_dest >= 50nm

    -- Since we can't easily modify a procedure in-place, we'll use a workaround
    -- by creating a wrapper or updating via full script deployment

    PRINT '  NOTE: Full procedure deployment required.';
    PRINT '  Run: sqlcmd -i adl/procedures/sp_Adl_RefreshFromVatsim_Staged.sql';
END
ELSE
BEGIN
    PRINT '  Fix already applied.';
END

PRINT '';

-- ============================================================================
-- Update sp_Adl_RefreshFromVatsim_Normalized - Step 7 Fix
-- ============================================================================

PRINT 'Updating sp_Adl_RefreshFromVatsim_Normalized...';

IF OBJECT_DEFINITION(OBJECT_ID('dbo.sp_Adl_RefreshFromVatsim_Normalized')) NOT LIKE '%disconnected%'
BEGIN
    PRINT '  Applying arrival detection fix...';
    PRINT '  NOTE: Full procedure deployment required.';
    PRINT '  Run: sqlcmd -i adl/procedures/sp_Adl_RefreshFromVatsim_Normalized.sql';
END
ELSE
BEGIN
    PRINT '  Fix already applied.';
END

PRINT '';

-- ============================================================================
-- Clean up incorrectly marked "arrived" flights
-- ============================================================================

PRINT 'Cleaning up incorrectly marked arrived flights...';

-- Count affected flights
DECLARE @affected_count INT;
SELECT @affected_count = COUNT(*)
FROM dbo.adl_flight_core c
JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
WHERE c.phase = 'arrived'
  AND c.is_active = 0
  AND p.dist_to_dest_nm >= 50;

PRINT '  Flights incorrectly marked as arrived: ' + CAST(@affected_count AS VARCHAR);

-- Update flights that were marked arrived but were far from destination
-- Change phase to 'disconnected' and clear invalid ATA
UPDATE c
SET c.phase = 'disconnected'
FROM dbo.adl_flight_core c
JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
WHERE c.phase = 'arrived'
  AND c.is_active = 0
  AND p.dist_to_dest_nm >= 50;

PRINT '  Updated phase to disconnected: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- Clear invalid ATA times for disconnected flights
UPDATE t
SET t.ata_utc = NULL,
    t.ata_runway_utc = NULL,
    t.eta_prefix = CASE WHEN t.eta_prefix = 'A' THEN 'E' ELSE t.eta_prefix END
FROM dbo.adl_flight_times t
JOIN dbo.adl_flight_core c ON c.flight_uid = t.flight_uid
JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
WHERE c.phase = 'disconnected'
  AND t.ata_utc IS NOT NULL
  AND p.dist_to_dest_nm >= 50;

PRINT '  Cleared invalid ATA times: ' + CAST(@@ROWCOUNT AS VARCHAR);

PRINT '';
PRINT '============================================================================';
PRINT 'Migration 012 Complete';
PRINT '';
PRINT 'IMPORTANT: You must also deploy the updated stored procedures:';
PRINT '';
PRINT '  sqlcmd -S vatsim.database.windows.net -d VATSIM_ADL \';
PRINT '    -i adl/procedures/sp_Adl_RefreshFromVatsim_Staged.sql';
PRINT '';
PRINT '  sqlcmd -S vatsim.database.windows.net -d VATSIM_ADL \';
PRINT '    -i adl/procedures/sp_Adl_RefreshFromVatsim_Normalized.sql';
PRINT '';
PRINT 'And restart the daemon to pick up the V2 wind procedure change.';
PRINT '============================================================================';
GO
