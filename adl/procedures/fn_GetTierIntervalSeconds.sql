-- ============================================================================
-- fn_GetTierIntervalSeconds.sql
-- 
-- Returns the logging interval in seconds for a given trajectory tier
-- ============================================================================

IF OBJECT_ID('dbo.fn_GetTierIntervalSeconds', 'FN') IS NOT NULL
    DROP FUNCTION dbo.fn_GetTierIntervalSeconds;
GO

CREATE FUNCTION dbo.fn_GetTierIntervalSeconds(@tier TINYINT)
RETURNS INT
AS
BEGIN
    RETURN CASE @tier
        WHEN 0 THEN 15      -- 15 seconds - Critical phases
        WHEN 1 THEN 30      -- 30 seconds - Approaching events
        WHEN 2 THEN 60      -- 1 minute   - US/CA oceanic approaching
        WHEN 3 THEN 120     -- 2 minutes  - Ground ops
        WHEN 4 THEN 300     -- 5 minutes  - Stable cruise
        WHEN 5 THEN 600     -- 10 minutes - Extended oceanic
        WHEN 6 THEN 1800    -- 30 minutes - Ultra-long oceanic
        WHEN 7 THEN NULL    -- No logging
        ELSE 300            -- Default 5 minutes
    END;
END;
GO

PRINT 'Created function dbo.fn_GetTierIntervalSeconds';
GO
