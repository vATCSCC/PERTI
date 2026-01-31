-- ============================================================================
-- Backfill Script: Re-parse flights with sp_ParseRoute v4.3
--
-- Purpose: Queue existing flights for re-parsing to apply:
--   - Enhanced SID/STAR pattern matching (ABTAN2W, DUMEP1T, etc.)
--   - Runway extraction from route tokens (/07C, /02L, /RW26L)
--   - Fallback dfix/afix extraction for direct routes
--
-- Run this AFTER deploying sp_ParseRoute v4.3 to VATSIM_ADL
-- ============================================================================

SET NOCOUNT ON;
PRINT '=== Backfill: Re-parse flights with sp_ParseRoute v4.3 ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '';

-- ============================================================================
-- Phase 1: Count candidates for re-parsing
-- ============================================================================

DECLARE @total_with_route INT;
DECLARE @missing_dfix INT;
DECLARE @missing_afix INT;
DECLARE @missing_both INT;
DECLARE @has_runway_suffix INT;
DECLARE @has_potential_star INT;

-- Total flights with a route string
SELECT @total_with_route = COUNT(*)
FROM dbo.adl_flight_plan
WHERE fp_route IS NOT NULL AND LEN(fp_route) > 5;

-- Flights missing dfix
SELECT @missing_dfix = COUNT(*)
FROM dbo.adl_flight_plan
WHERE fp_route IS NOT NULL AND LEN(fp_route) > 5
  AND dfix IS NULL;

-- Flights missing afix
SELECT @missing_afix = COUNT(*)
FROM dbo.adl_flight_plan
WHERE fp_route IS NOT NULL AND LEN(fp_route) > 5
  AND afix IS NULL;

-- Flights missing both
SELECT @missing_both = COUNT(*)
FROM dbo.adl_flight_plan
WHERE fp_route IS NOT NULL AND LEN(fp_route) > 5
  AND dfix IS NULL AND afix IS NULL;

-- Flights with runway suffixes that weren't extracted
SELECT @has_runway_suffix = COUNT(*)
FROM dbo.adl_flight_plan
WHERE fp_route IS NOT NULL
  AND (fp_route LIKE '%/[0-9][0-9]%' OR fp_route LIKE '%/RW[0-9]%')
  AND dep_runway IS NULL AND arr_runway IS NULL;

-- Flights with potential STAR patterns (digit+letter ending) that weren't detected
SELECT @has_potential_star = COUNT(*)
FROM dbo.adl_flight_plan
WHERE fp_route IS NOT NULL
  AND fp_route LIKE '%[A-Z][0-9][A-Z] %'  -- Pattern like ABTAN2W
  AND star_name IS NULL;

PRINT 'Analysis of current data:';
PRINT '  Total flights with routes: ' + CAST(@total_with_route AS VARCHAR);
PRINT '  Missing dfix:              ' + CAST(@missing_dfix AS VARCHAR);
PRINT '  Missing afix:              ' + CAST(@missing_afix AS VARCHAR);
PRINT '  Missing both:              ' + CAST(@missing_both AS VARCHAR);
PRINT '  Have runway suffix, not extracted: ' + CAST(@has_runway_suffix AS VARCHAR);
PRINT '  Have potential STAR pattern:       ' + CAST(@has_potential_star AS VARCHAR);
PRINT '';

-- ============================================================================
-- Phase 2: Queue flights for re-parsing
-- Uses parse_tier to prioritize:
--   Tier 1: Flights with runway suffixes (quick win)
--   Tier 1: Flights with potential STAR patterns (likely to gain star_name)
--   Tier 2: Flights missing both dfix and afix
-- ============================================================================

DECLARE @queued INT = 0;
DECLARE @batch_size INT = 5000;  -- Process in batches to avoid timeout

PRINT 'Queueing flights for re-parse...';
PRINT '';

-- Clear any stale queue entries first
DELETE FROM dbo.adl_parse_queue
WHERE status IN ('PENDING', 'ERROR', 'FAILED')
  AND queued_utc < DATEADD(HOUR, -24, SYSUTCDATETIME());

PRINT 'Cleared stale queue entries';

-- Tier 1: Flights with runway suffixes
INSERT INTO dbo.adl_parse_queue (flight_uid, parse_tier, status, queued_utc, next_eligible_utc)
SELECT TOP (@batch_size) fp.flight_uid, 1, 'PENDING', SYSUTCDATETIME(), SYSUTCDATETIME()
FROM dbo.adl_flight_plan fp
WHERE fp.fp_route IS NOT NULL
  AND (fp.fp_route LIKE '%/[0-9][0-9]%' OR fp.fp_route LIKE '%/RW[0-9]%')
  AND fp.dep_runway IS NULL AND fp.arr_runway IS NULL
  AND NOT EXISTS (SELECT 1 FROM dbo.adl_parse_queue q WHERE q.flight_uid = fp.flight_uid AND q.status = 'PENDING');

SET @queued = @queued + @@ROWCOUNT;
PRINT 'Queued ' + CAST(@@ROWCOUNT AS VARCHAR) + ' flights with runway suffixes (tier 1)';

-- Tier 1: Flights with potential STAR patterns (digit+letter ending)
INSERT INTO dbo.adl_parse_queue (flight_uid, parse_tier, status, queued_utc, next_eligible_utc)
SELECT TOP (@batch_size) fp.flight_uid, 1, 'PENDING', SYSUTCDATETIME(), SYSUTCDATETIME()
FROM dbo.adl_flight_plan fp
WHERE fp.fp_route IS NOT NULL
  AND fp.fp_route LIKE '%[A-Z][0-9][A-Z] %'
  AND fp.star_name IS NULL
  AND NOT EXISTS (SELECT 1 FROM dbo.adl_parse_queue q WHERE q.flight_uid = fp.flight_uid AND q.status = 'PENDING');

SET @queued = @queued + @@ROWCOUNT;
PRINT 'Queued ' + CAST(@@ROWCOUNT AS VARCHAR) + ' flights with potential STAR patterns (tier 1)';

-- Tier 2: Flights missing both dfix and afix
INSERT INTO dbo.adl_parse_queue (flight_uid, parse_tier, status, queued_utc, next_eligible_utc)
SELECT TOP (@batch_size) fp.flight_uid, 2, 'PENDING', SYSUTCDATETIME(), SYSUTCDATETIME()
FROM dbo.adl_flight_plan fp
WHERE fp.fp_route IS NOT NULL
  AND LEN(fp.fp_route) > 10  -- Meaningful route length
  AND fp.dfix IS NULL
  AND fp.afix IS NULL
  AND NOT EXISTS (SELECT 1 FROM dbo.adl_parse_queue q WHERE q.flight_uid = fp.flight_uid AND q.status = 'PENDING');

SET @queued = @queued + @@ROWCOUNT;
PRINT 'Queued ' + CAST(@@ROWCOUNT AS VARCHAR) + ' flights missing both dfix/afix (tier 2)';

PRINT '';
PRINT 'Total flights queued for re-parse: ' + CAST(@queued AS VARCHAR);

-- ============================================================================
-- Phase 3: Show queue status
-- ============================================================================

PRINT '';
PRINT 'Current parse queue status:';

SELECT
    status,
    parse_tier,
    COUNT(*) as count
FROM dbo.adl_parse_queue
GROUP BY status, parse_tier
ORDER BY status, parse_tier;

-- ============================================================================
-- Phase 4: Instructions
-- ============================================================================

PRINT '';
PRINT '=== Next Steps ===';
PRINT '1. Verify sp_ParseRoute v4.3 is deployed';
PRINT '2. Run batch processor: EXEC dbo.sp_ParseRouteBatch @batch_size = 100;';
PRINT '3. Monitor progress with: SELECT status, COUNT(*) FROM dbo.adl_parse_queue GROUP BY status;';
PRINT '';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
