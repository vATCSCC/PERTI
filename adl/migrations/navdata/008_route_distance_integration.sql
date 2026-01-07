-- ============================================================================
-- 008_route_distance_integration.sql
-- Integrate parsed route distance into flight plan and ETA calculations
--
-- Changes:
--   1. Add route_dist_nm column to adl_flight_plan
--   2. Create trigger/procedure to update route_dist after parsing
--   3. Update ETA batch to prefer route distance over GCD
-- ============================================================================

PRINT 'Route Distance Integration - Migration 008';
PRINT '';

-- ============================================================================
-- Step 1: Add route_dist_nm column to adl_flight_plan
-- ============================================================================

IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_NAME = 'adl_flight_plan' AND COLUMN_NAME = 'route_dist_nm'
)
BEGIN
    ALTER TABLE dbo.adl_flight_plan ADD route_dist_nm DECIMAL(10,2) NULL;
    PRINT 'Added route_dist_nm column to adl_flight_plan';
END
ELSE
    PRINT 'route_dist_nm column already exists';
GO

-- ============================================================================
-- Step 2: Backfill route_dist_nm from existing waypoints
-- ============================================================================

PRINT 'Backfilling route_dist_nm from parsed waypoints...';

UPDATE fp
SET fp.route_dist_nm = w.max_dist
FROM dbo.adl_flight_plan fp
CROSS APPLY (
    SELECT MAX(cum_dist_nm) AS max_dist
    FROM dbo.adl_flight_waypoints w
    WHERE w.flight_uid = fp.flight_uid
) w
WHERE w.max_dist > 0
  AND (fp.route_dist_nm IS NULL OR fp.route_dist_nm != w.max_dist);

PRINT '  Updated ' + CAST(@@ROWCOUNT AS VARCHAR) + ' flight plans with route distance';
GO

-- ============================================================================
-- Step 3: Create procedure to sync route distances
-- Called after route parsing completes
-- ============================================================================

IF OBJECT_ID('dbo.sp_SyncRouteDistances', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_SyncRouteDistances;
GO

CREATE PROCEDURE dbo.sp_SyncRouteDistances
    @updated_count INT = NULL OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Update flight_plan with route distance from waypoints
    UPDATE fp
    SET fp.route_dist_nm = w.max_dist
    FROM dbo.adl_flight_plan fp
    INNER JOIN dbo.adl_flight_core c ON c.flight_uid = fp.flight_uid
    CROSS APPLY (
        SELECT MAX(cum_dist_nm) AS max_dist
        FROM dbo.adl_flight_waypoints w
        WHERE w.flight_uid = fp.flight_uid
    ) w
    WHERE c.is_active = 1
      AND w.max_dist > 0
      AND (fp.route_dist_nm IS NULL OR fp.route_dist_nm != w.max_dist);
    
    SET @updated_count = @@ROWCOUNT;
END
GO

PRINT 'Created sp_SyncRouteDistances';
GO

-- ============================================================================
-- Step 4: Create function to get best distance estimate
-- Prefers: route_dist > gcd_nm > position-based calculation
-- ============================================================================

IF OBJECT_ID('dbo.fn_GetRouteDistance', 'FN') IS NOT NULL
    DROP FUNCTION dbo.fn_GetRouteDistance;
GO

CREATE FUNCTION dbo.fn_GetRouteDistance (
    @flight_uid BIGINT
)
RETURNS DECIMAL(10,2)
AS
BEGIN
    DECLARE @dist DECIMAL(10,2);
    
    SELECT @dist = COALESCE(
        fp.route_dist_nm,  -- Parsed route distance (most accurate)
        fp.gcd_nm,         -- Great circle distance (fallback)
        -- Calculate from position if nothing else
        CASE 
            WHEN p.dist_to_dest_nm IS NOT NULL AND p.dist_flown_nm IS NOT NULL
            THEN p.dist_to_dest_nm + p.dist_flown_nm
            ELSE NULL
        END
    )
    FROM dbo.adl_flight_plan fp
    LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = fp.flight_uid
    WHERE fp.flight_uid = @flight_uid;
    
    RETURN @dist;
END
GO

PRINT 'Created fn_GetRouteDistance';
GO

-- ============================================================================
-- Step 5: Create view for route distance comparison
-- Useful for analyzing accuracy
-- ============================================================================

IF OBJECT_ID('dbo.vw_route_distance_analysis', 'V') IS NOT NULL
    DROP VIEW dbo.vw_route_distance_analysis;
GO

CREATE VIEW dbo.vw_route_distance_analysis AS
SELECT 
    c.flight_uid,
    c.callsign,
    c.phase,
    fp.fp_dept_icao,
    fp.fp_dest_icao,
    fp.gcd_nm,
    fp.route_dist_nm,
    -- Route efficiency (how much longer than GCD)
    CASE 
        WHEN fp.gcd_nm > 0 AND fp.route_dist_nm > 0
        THEN CAST((fp.route_dist_nm / fp.gcd_nm - 1) * 100 AS DECIMAL(5,2))
        ELSE NULL
    END AS route_inefficiency_pct,
    -- Distance source quality
    CASE 
        WHEN fp.route_dist_nm IS NOT NULL THEN 'PARSED'
        WHEN fp.gcd_nm IS NOT NULL THEN 'GCD'
        ELSE 'NONE'
    END AS distance_source,
    p.dist_flown_nm,
    p.dist_to_dest_nm,
    p.pct_complete
FROM dbo.adl_flight_core c
INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
WHERE c.is_active = 1;
GO

PRINT 'Created vw_route_distance_analysis';
GO

-- ============================================================================
-- Summary
-- ============================================================================

PRINT '';
PRINT '=== Route Distance Integration Complete ===';
PRINT '';
PRINT 'New objects:';
PRINT '  - adl_flight_plan.route_dist_nm column';
PRINT '  - sp_SyncRouteDistances: Syncs route distance from waypoints';
PRINT '  - fn_GetRouteDistance: Returns best distance estimate';
PRINT '  - vw_route_distance_analysis: Analysis view';
PRINT '';
PRINT 'Next: Update sp_CalculateETABatch to use route_dist_nm';
GO
