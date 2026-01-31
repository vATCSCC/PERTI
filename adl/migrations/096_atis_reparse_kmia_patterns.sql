-- ============================================================================
-- Migration 096: Re-parse ATIS for New Patterns
-- Date: 2026-02-01
-- Description: Reset recent ATIS records to PENDING so they can be re-parsed
--              with new patterns that handle:
--              - Pattern 20: Comma-separated runway lists
--                "ARRIVALS EXPECT ILS RWY 26L, RWY 27, RWY 30"
--              - Pattern 21: OR-joined approach types
--                "RNAV OR GPS APCH RWY 26R"
-- ============================================================================

SET NOCOUNT ON;
GO

PRINT '=== ATIS Re-parse for New Patterns (096) ===';
PRINT '';

-- =====================================================
-- 1. GET CURRENT STATS
-- =====================================================

DECLARE @total_atis INT;
DECLARE @pending INT;
DECLARE @parsed INT;
DECLARE @runway_records INT;

SELECT @total_atis = COUNT(*) FROM dbo.vatsim_atis WHERE fetched_utc > DATEADD(HOUR, -24, GETUTCDATE());
SELECT @pending = COUNT(*) FROM dbo.vatsim_atis WHERE parse_status = 'PENDING' AND fetched_utc > DATEADD(HOUR, -24, GETUTCDATE());
SELECT @parsed = COUNT(*) FROM dbo.vatsim_atis WHERE parse_status = 'PARSED' AND fetched_utc > DATEADD(HOUR, -24, GETUTCDATE());
SELECT @runway_records = COUNT(*) FROM dbo.runway_in_use WHERE effective_utc > DATEADD(HOUR, -24, GETUTCDATE());

PRINT 'Before re-parse (last 24 hours):';
PRINT '  Total ATIS records: ' + CAST(@total_atis AS VARCHAR);
PRINT '  PENDING: ' + CAST(@pending AS VARCHAR);
PRINT '  PARSED:  ' + CAST(@parsed AS VARCHAR);
PRINT '  Runway-in-use records: ' + CAST(@runway_records AS VARCHAR);
PRINT '';

-- =====================================================
-- 2. CLEAR RECENT RUNWAY DATA
-- Only clear last 24 hours to minimize impact
-- =====================================================

PRINT 'Clearing recent runway_in_use records...';

DELETE FROM dbo.runway_in_use
WHERE effective_utc > DATEADD(HOUR, -24, GETUTCDATE());

PRINT '  Deleted ' + CAST(@@ROWCOUNT AS VARCHAR) + ' runway records';

-- =====================================================
-- 3. CLEAR RECENT CONFIG HISTORY
-- =====================================================

PRINT 'Clearing recent atis_config_history entries...';

DELETE FROM dbo.atis_config_history
WHERE effective_utc > DATEADD(HOUR, -24, GETUTCDATE());

PRINT '  Deleted ' + CAST(@@ROWCOUNT AS VARCHAR) + ' config history records';

-- =====================================================
-- 4. RESET RECENT ATIS TO PENDING
-- =====================================================

PRINT 'Resetting recent ATIS parse_status to PENDING...';

UPDATE dbo.vatsim_atis
SET parse_status = 'PENDING',
    parse_error = NULL
WHERE parse_status IN ('PARSED', 'SKIPPED', 'FAILED')
  AND fetched_utc > DATEADD(HOUR, -24, GETUTCDATE());

DECLARE @reset_count INT = @@ROWCOUNT;
PRINT '  Reset ' + CAST(@reset_count AS VARCHAR) + ' ATIS records to PENDING';

-- =====================================================
-- 5. VERIFY
-- =====================================================

SELECT @pending = COUNT(*) FROM dbo.vatsim_atis WHERE parse_status = 'PENDING';
SELECT @runway_records = COUNT(*) FROM dbo.runway_in_use;

PRINT '';
PRINT 'After re-parse reset:';
PRINT '  PENDING ATIS records: ' + CAST(@pending AS VARCHAR);
PRINT '  Runway-in-use records: ' + CAST(@runway_records AS VARCHAR);
PRINT '';
PRINT 'The daemon will now re-parse all PENDING records on next cycle.';
PRINT 'New patterns added:';
PRINT '  - Pattern 20: "ARRIVALS EXPECT ILS RWY 26L, RWY 27, RWY 30"';
PRINT '  - Pattern 21: "RNAV OR GPS APCH RWY 26R"';
PRINT '';

GO

PRINT '096_atis_reparse_kmia_patterns.sql completed';
GO
