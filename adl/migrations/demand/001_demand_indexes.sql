-- ============================================================================
-- Airspace Element Demand Indexes
-- Optimizes fix and airway segment demand queries
--
-- Creates filtered indexes for efficient time-range queries on waypoint ETAs.
-- Filtered indexes (WHERE eta_utc IS NOT NULL) minimize write overhead since
-- INSERTs from route parsing have NULL eta_utc initially.
--
-- Date: 2026-01-15
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== Airspace Element Demand Indexes ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. IX_waypoint_fix_eta - Fix demand queries
-- Optimizes: "How many flights over MERIT in the next 45 minutes?"
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_waypoint_fix_eta'
               AND object_id = OBJECT_ID('dbo.adl_flight_waypoints'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_waypoint_fix_eta
    ON dbo.adl_flight_waypoints (fix_name, eta_utc)
    INCLUDE (flight_uid, on_airway, sequence_num)
    WHERE eta_utc IS NOT NULL;

    PRINT 'Created index IX_waypoint_fix_eta';
END
ELSE
BEGIN
    PRINT 'Index IX_waypoint_fix_eta already exists - skipping';
END
GO

-- ============================================================================
-- 2. IX_waypoint_airway_eta - Airway segment demand queries
-- Optimizes: "How many flights on J48 between LANNA and MOL?"
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_waypoint_airway_eta'
               AND object_id = OBJECT_ID('dbo.adl_flight_waypoints'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_waypoint_airway_eta
    ON dbo.adl_flight_waypoints (on_airway, eta_utc)
    INCLUDE (flight_uid, fix_name, sequence_num)
    WHERE on_airway IS NOT NULL AND eta_utc IS NOT NULL;

    PRINT 'Created index IX_waypoint_airway_eta';
END
ELSE
BEGIN
    PRINT 'Index IX_waypoint_airway_eta already exists - skipping';
END
GO

PRINT '';
PRINT '=== Airspace Element Demand Indexes Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
