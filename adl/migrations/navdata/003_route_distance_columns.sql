-- ============================================================================
-- Migration 049: Route Distance Columns
-- 
-- Adds cumulative distance tracking to support accurate route-based
-- distance remaining calculations (vs great circle).
--
-- Part of ETA Enhancement Project - Item #1 (Route Distance)
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== Migration 049: Route Distance Columns ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. Add cum_dist_nm to adl_flight_waypoints
-- ============================================================================

IF NOT EXISTS (
    SELECT 1 FROM sys.columns 
    WHERE object_id = OBJECT_ID(N'dbo.adl_flight_waypoints') 
    AND name = 'cum_dist_nm'
)
BEGIN
    ALTER TABLE dbo.adl_flight_waypoints
    ADD cum_dist_nm DECIMAL(10,2) NULL;
    
    PRINT 'Added cum_dist_nm column to adl_flight_waypoints';
END
ELSE
BEGIN
    PRINT 'Column cum_dist_nm already exists on adl_flight_waypoints - skipping';
END
GO

-- ============================================================================
-- 2. Add segment_dist_nm to adl_flight_waypoints (distance from previous waypoint)
-- ============================================================================

IF NOT EXISTS (
    SELECT 1 FROM sys.columns 
    WHERE object_id = OBJECT_ID(N'dbo.adl_flight_waypoints') 
    AND name = 'segment_dist_nm'
)
BEGIN
    ALTER TABLE dbo.adl_flight_waypoints
    ADD segment_dist_nm DECIMAL(10,2) NULL;
    
    PRINT 'Added segment_dist_nm column to adl_flight_waypoints';
END
ELSE
BEGIN
    PRINT 'Column segment_dist_nm already exists on adl_flight_waypoints - skipping';
END
GO

-- ============================================================================
-- 3. Add route_total_nm to adl_flight_plan
-- ============================================================================

IF NOT EXISTS (
    SELECT 1 FROM sys.columns 
    WHERE object_id = OBJECT_ID(N'dbo.adl_flight_plan') 
    AND name = 'route_total_nm'
)
BEGIN
    ALTER TABLE dbo.adl_flight_plan
    ADD route_total_nm DECIMAL(10,2) NULL;
    
    PRINT 'Added route_total_nm column to adl_flight_plan';
END
ELSE
BEGIN
    PRINT 'Column route_total_nm already exists on adl_flight_plan - skipping';
END
GO

-- ============================================================================
-- 4. Add route_dist_to_dest_nm to adl_flight_position
--    (calculated remaining distance along route vs GCD)
-- ============================================================================

IF NOT EXISTS (
    SELECT 1 FROM sys.columns 
    WHERE object_id = OBJECT_ID(N'dbo.adl_flight_position') 
    AND name = 'route_dist_to_dest_nm'
)
BEGIN
    ALTER TABLE dbo.adl_flight_position
    ADD route_dist_to_dest_nm DECIMAL(10,2) NULL;
    
    PRINT 'Added route_dist_to_dest_nm column to adl_flight_position';
END
ELSE
BEGIN
    PRINT 'Column route_dist_to_dest_nm already exists on adl_flight_position - skipping';
END
GO

-- ============================================================================
-- 5. Add route_pct_complete to adl_flight_position
--    (calculated from route distance vs route total)
-- ============================================================================

IF NOT EXISTS (
    SELECT 1 FROM sys.columns 
    WHERE object_id = OBJECT_ID(N'dbo.adl_flight_position') 
    AND name = 'route_pct_complete'
)
BEGIN
    ALTER TABLE dbo.adl_flight_position
    ADD route_pct_complete DECIMAL(5,2) NULL;
    
    PRINT 'Added route_pct_complete column to adl_flight_position';
END
ELSE
BEGIN
    PRINT 'Column route_pct_complete already exists on adl_flight_position - skipping';
END
GO

-- ============================================================================
-- 6. Add next_waypoint columns to adl_flight_position
-- ============================================================================

IF NOT EXISTS (
    SELECT 1 FROM sys.columns 
    WHERE object_id = OBJECT_ID(N'dbo.adl_flight_position') 
    AND name = 'next_waypoint_seq'
)
BEGIN
    ALTER TABLE dbo.adl_flight_position
    ADD next_waypoint_seq INT NULL;
    
    PRINT 'Added next_waypoint_seq column to adl_flight_position';
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.columns 
    WHERE object_id = OBJECT_ID(N'dbo.adl_flight_position') 
    AND name = 'next_waypoint_name'
)
BEGIN
    ALTER TABLE dbo.adl_flight_position
    ADD next_waypoint_name NVARCHAR(64) NULL;
    
    PRINT 'Added next_waypoint_name column to adl_flight_position';
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.columns 
    WHERE object_id = OBJECT_ID(N'dbo.adl_flight_position') 
    AND name = 'dist_to_next_waypoint_nm'
)
BEGIN
    ALTER TABLE dbo.adl_flight_position
    ADD dist_to_next_waypoint_nm DECIMAL(10,2) NULL;
    
    PRINT 'Added dist_to_next_waypoint_nm column to adl_flight_position';
END
GO

-- ============================================================================
-- Summary
-- ============================================================================

PRINT '';
PRINT '=== Migration 049 Complete ===';
PRINT '';
PRINT 'Added columns:';
PRINT '  adl_flight_waypoints.cum_dist_nm      - Cumulative distance from origin';
PRINT '  adl_flight_waypoints.segment_dist_nm  - Distance from previous waypoint';
PRINT '  adl_flight_plan.route_total_nm        - Total route distance';
PRINT '  adl_flight_position.route_dist_to_dest_nm   - Remaining distance along route';
PRINT '  adl_flight_position.route_pct_complete      - Percent complete along route';
PRINT '  adl_flight_position.next_waypoint_seq       - Next waypoint sequence number';
PRINT '  adl_flight_position.next_waypoint_name      - Next waypoint fix name';
PRINT '  adl_flight_position.dist_to_next_waypoint_nm - Distance to next waypoint';
PRINT '';
PRINT 'Next: Update sp_ParseRoute to calculate cumulative distances';
GO
