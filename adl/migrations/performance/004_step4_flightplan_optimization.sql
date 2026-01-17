-- ============================================================================
-- ADL Performance Migration 004: Step 4 Flight Plan Optimization
--
-- Problem: Step 4 (4_flightplan) takes 1.4-2.8 seconds because the MERGE
--          processes ALL 2400+ pilots even when only ~50-100 have route changes.
--          Each row evaluates 20+ COALESCE expressions unnecessarily.
--
-- Solution:
--   1. Add index on fp_hash for fast change detection
--   2. Split MERGE into INSERT (new) + UPDATE (changed only)
--   3. Filter UPDATE to only rows where hash actually changed
--
-- Expected improvement: 1.5-2.5s -> 200-400ms (5-10x faster)
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Performance Migration 004: Step 4 Optimization ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- Step 1: Add index on fp_hash for fast change detection
-- ============================================================================

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.adl_flight_plan')
    AND name = 'IX_fp_hash'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_fp_hash
    ON dbo.adl_flight_plan (fp_hash)
    INCLUDE (flight_uid);

    PRINT 'Created index IX_fp_hash on adl_flight_plan';
END
ELSE
BEGIN
    PRINT 'Index IX_fp_hash already exists - skipping';
END
GO

-- ============================================================================
-- Step 2: Add covering index for the flight plan lookup pattern
-- This supports the JOIN between #pilots and adl_flight_plan efficiently
-- ============================================================================

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.adl_flight_plan')
    AND name = 'IX_fp_uid_hash'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_fp_uid_hash
    ON dbo.adl_flight_plan (flight_uid)
    INCLUDE (fp_hash, fp_route, fp_remarks);

    PRINT 'Created covering index IX_fp_uid_hash on adl_flight_plan';
END
ELSE
BEGIN
    PRINT 'Index IX_fp_uid_hash already exists - skipping';
END
GO

PRINT '';
PRINT '=== Migration 004 Complete ===';
PRINT 'Next step: Deploy updated sp_Adl_RefreshFromVatsim_Normalized';
PRINT '';
PRINT 'The SP update will change Step 4 from:';
PRINT '  - MERGE all 2400 pilots (evaluates 20+ COALESCE per row)';
PRINT 'To:';
PRINT '  - INSERT only new flights (~50-100 per cycle)';
PRINT '  - UPDATE only changed flights (hash mismatch, ~50-100 per cycle)';
PRINT '';
GO
