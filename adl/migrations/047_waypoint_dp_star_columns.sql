-- ============================================================================
-- ADL Migration 047: Add DP/STAR columns to waypoints
--
-- Adds columns to track which specific departure procedure (DP/SID) or
-- standard terminal arrival route (STAR) a waypoint belongs to.
--
-- Run Order: 47
-- Depends on: 003_adl_waypoints_stepclimbs.sql
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Migration 047: Waypoint DP/STAR Columns ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. Add on_dp column - stores the departure procedure name (e.g., SKORR5)
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_waypoints') AND type = 'U')
BEGIN
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_waypoints') AND name = 'on_dp')
    BEGIN
        ALTER TABLE dbo.adl_flight_waypoints ADD on_dp NVARCHAR(20) NULL;
        PRINT 'Added on_dp column to adl_flight_waypoints';
    END
    ELSE
    BEGIN
        PRINT 'Column on_dp already exists - skipping';
    END
END
GO

-- ============================================================================
-- 2. Add on_star column - stores the STAR name (e.g., ANJLL4)
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_waypoints') AND type = 'U')
BEGIN
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo.adl_flight_waypoints') AND name = 'on_star')
    BEGIN
        ALTER TABLE dbo.adl_flight_waypoints ADD on_star NVARCHAR(20) NULL;
        PRINT 'Added on_star column to adl_flight_waypoints';
    END
    ELSE
    BEGIN
        PRINT 'Column on_star already exists - skipping';
    END
END
GO

-- ============================================================================
-- 3. Create indexes for the new columns
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_waypoints') AND type = 'U')
BEGIN
    IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.adl_flight_waypoints') AND name = 'IX_waypoint_dp')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_waypoint_dp ON dbo.adl_flight_waypoints (on_dp) WHERE on_dp IS NOT NULL;
        PRINT 'Created index IX_waypoint_dp';
    END

    IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.adl_flight_waypoints') AND name = 'IX_waypoint_star')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_waypoint_star ON dbo.adl_flight_waypoints (on_star) WHERE on_star IS NOT NULL;
        PRINT 'Created index IX_waypoint_star';
    END
END
GO

PRINT '';
PRINT '=== ADL Migration 047 Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
