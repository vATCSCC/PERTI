-- ============================================================================
-- fn_GetWindTierInterval.sql
-- Returns the calculation interval in seconds for a given wind tier
--
-- Wind calculation tiers mirror trajectory tiers but optimized for wind updates:
-- - Tier 0: Critical - approaching destination (30 seconds)
-- - Tier 1: High Priority - within 100nm or transitioning (60 seconds)
-- - Tier 2: Active Enroute - relevant flights in cruise (2 minutes)
-- - Tier 3: Stable Cruise - far from events (5 minutes)
-- - Tier 4: Low Priority - oceanic, long haul (10 minutes)
-- - Tier 7: Skip - irrelevant, arrived, prefile (no calculation)
--
-- Part of the Tiered Wind Calculation System
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID('dbo.fn_GetWindTierInterval', 'FN') IS NOT NULL
    DROP FUNCTION dbo.fn_GetWindTierInterval;
GO

CREATE FUNCTION dbo.fn_GetWindTierInterval(@tier TINYINT)
RETURNS INT
AS
BEGIN
    RETURN CASE @tier
        WHEN 0 THEN 30      -- 30 seconds - Critical (approaching destination)
        WHEN 1 THEN 60      -- 1 minute - High priority (within 100nm)
        WHEN 2 THEN 120     -- 2 minutes - Active enroute
        WHEN 3 THEN 300     -- 5 minutes - Stable cruise
        WHEN 4 THEN 600     -- 10 minutes - Low priority (oceanic)
        WHEN 7 THEN NULL    -- Skip (irrelevant flights)
        ELSE 120            -- Default 2 minutes
    END;
END;
GO

PRINT 'Created function dbo.fn_GetWindTierInterval';
GO
