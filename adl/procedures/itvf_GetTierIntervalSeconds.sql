-- ============================================================================
-- itvf_GetTierIntervalSeconds.sql
-- INLINE TABLE-VALUED FUNCTION (Performance Optimized)
--
-- Replaces scalar fn_GetTierIntervalSeconds for better parallelism.
--
-- Returns the logging interval in seconds for a given trajectory tier.
--
-- Usage:
--   CROSS APPLY dbo.itvf_GetTierIntervalSeconds(tier) i
--   WHERE DATEDIFF(SECOND, last_log, @now) >= i.interval_seconds
--
-- Part of the ETA & Trajectory Calculation System
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID('dbo.itvf_GetTierIntervalSeconds', 'IF') IS NOT NULL
    DROP FUNCTION dbo.itvf_GetTierIntervalSeconds;
GO

CREATE FUNCTION dbo.itvf_GetTierIntervalSeconds(@tier TINYINT)
RETURNS TABLE
AS
RETURN (
    SELECT CASE @tier
        WHEN 0 THEN 15      -- 15 seconds - Critical phases
        WHEN 1 THEN 30      -- 30 seconds - Approaching events
        WHEN 2 THEN 60      -- 1 minute   - US/CA oceanic approaching
        WHEN 3 THEN 120     -- 2 minutes  - Ground ops
        WHEN 4 THEN 300     -- 5 minutes  - Stable cruise
        WHEN 5 THEN 600     -- 10 minutes - Extended oceanic
        WHEN 6 THEN 1800    -- 30 minutes - Ultra-long oceanic
        WHEN 7 THEN 999999  -- No logging (effectively infinite)
        ELSE 300            -- Default 5 minutes
    END AS interval_seconds
);
GO

PRINT 'Created inline table-valued function dbo.itvf_GetTierIntervalSeconds';
GO
