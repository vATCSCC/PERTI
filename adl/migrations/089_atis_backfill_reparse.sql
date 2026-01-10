-- ============================================================================
-- Migration 089: ATIS Runway Backfill Re-parse
-- Date: 2026-01-10
-- Description: Reset all existing ATIS records to PENDING status so they can
--              be re-parsed with the improved parser patterns that now handle:
--              - LAX-style "IN PROG" format
--              - "RWY XX AND RWY YY" lists (RWY prefix on each runway)
--              - Slash-separated runways without spaces (12L/12R)
--              - Single-digit runway lists (4L 4R)
--              - Australian bracket format [RWY] XX ARR/DEP
--              - Various international ATIS formats
-- ============================================================================

SET NOCOUNT ON;
GO

PRINT '=== ATIS Runway Backfill Re-parse ===';
PRINT '';

-- =====================================================
-- 1. GET CURRENT STATS
-- =====================================================

DECLARE @total_atis INT;
DECLARE @pending INT;
DECLARE @parsed INT;
DECLARE @skipped INT;
DECLARE @failed INT;
DECLARE @runway_records INT;

SELECT @total_atis = COUNT(*) FROM dbo.vatsim_atis;
SELECT @pending = COUNT(*) FROM dbo.vatsim_atis WHERE parse_status = 'PENDING';
SELECT @parsed = COUNT(*) FROM dbo.vatsim_atis WHERE parse_status = 'PARSED';
SELECT @skipped = COUNT(*) FROM dbo.vatsim_atis WHERE parse_status = 'SKIPPED';
SELECT @failed = COUNT(*) FROM dbo.vatsim_atis WHERE parse_status = 'FAILED';
SELECT @runway_records = COUNT(*) FROM dbo.runway_in_use;

PRINT 'Before backfill:';
PRINT '  Total ATIS records: ' + CAST(@total_atis AS VARCHAR);
PRINT '  PENDING: ' + CAST(@pending AS VARCHAR);
PRINT '  PARSED:  ' + CAST(@parsed AS VARCHAR);
PRINT '  SKIPPED: ' + CAST(@skipped AS VARCHAR);
PRINT '  FAILED:  ' + CAST(@failed AS VARCHAR);
PRINT '  Runway-in-use records: ' + CAST(@runway_records AS VARCHAR);
PRINT '';

-- =====================================================
-- 2. CLEAR EXISTING RUNWAY DATA
-- We need to clear this since the parser will re-create
-- all runway assignments from scratch
-- =====================================================

PRINT 'Clearing runway_in_use table...';

DELETE FROM dbo.runway_in_use;
PRINT '  Deleted ' + CAST(@@ROWCOUNT AS VARCHAR) + ' runway records';

-- =====================================================
-- 3. CLEAR CONFIG HISTORY (Optional - keeps history clean)
-- Only clear recent entries that will be regenerated
-- =====================================================

PRINT 'Clearing recent atis_config_history entries...';

-- Keep history older than 24 hours, clear recent ones that will be regenerated
DELETE FROM dbo.atis_config_history
WHERE effective_utc > DATEADD(HOUR, -24, GETUTCDATE());

PRINT '  Deleted ' + CAST(@@ROWCOUNT AS VARCHAR) + ' recent config history records';

-- =====================================================
-- 4. RESET ALL ATIS TO PENDING
-- This allows the daemon to re-parse everything
-- =====================================================

PRINT 'Resetting ATIS parse_status to PENDING...';

UPDATE dbo.vatsim_atis
SET parse_status = 'PENDING',
    parse_error = NULL
WHERE parse_status IN ('PARSED', 'SKIPPED', 'FAILED');

DECLARE @reset_count INT = @@ROWCOUNT;
PRINT '  Reset ' + CAST(@reset_count AS VARCHAR) + ' ATIS records to PENDING';

-- =====================================================
-- 5. VERIFY
-- =====================================================

SELECT @pending = COUNT(*) FROM dbo.vatsim_atis WHERE parse_status = 'PENDING';
SELECT @runway_records = COUNT(*) FROM dbo.runway_in_use;

PRINT '';
PRINT 'After backfill reset:';
PRINT '  PENDING ATIS records: ' + CAST(@pending AS VARCHAR);
PRINT '  Runway-in-use records: ' + CAST(@runway_records AS VARCHAR);
PRINT '';
PRINT 'The daemon will now re-parse all ATIS records on next cycle.';
PRINT 'Expected improvements with new parser:';
PRINT '  - LAX "INST APCHS IN PROG RWY 24R AND RWY 25L" now parsed correctly';
PRINT '  - "SIMUL INSTR DEPARTURES IN PROG RWYS 24 AND 25" now parsed correctly';
PRINT '  - Slash-separated runways like "12L/12R" now fully captured';
PRINT '  - Single-digit runway lists like "4L 4R" now parsed';
PRINT '';

GO

PRINT '089_atis_backfill_reparse.sql completed';
GO
