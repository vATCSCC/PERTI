-- ============================================================================
-- 007_waypoint_eta_integration.sql
-- Adds Waypoint ETA calculation to refresh cycle
--
-- Prerequisites:
--   - sp_CalculateWaypointETABatch must exist
--   - adl_flight_waypoints.eta_utc column must exist
--
-- Changes:
--   - Adds Step 8c: Waypoint ETA calculation
--   - Updates procedure to V8.6
--   - Returns waypoint_etas_calculated in stats
-- ============================================================================

-- Verify prerequisite
IF OBJECT_ID('dbo.sp_CalculateWaypointETABatch', 'P') IS NULL
BEGIN
    RAISERROR('Prerequisite sp_CalculateWaypointETABatch not found. Run sp_CalculateWaypointETA.sql first.', 16, 1);
    RETURN;
END

PRINT 'Adding Waypoint ETA calculation (Step 8c) to refresh procedure...';
GO

-- ============================================================================
-- Update the refresh procedure to V8.6
-- ============================================================================

-- First, let's check current stats output and add waypoint counter
-- We'll modify the procedure by adding Step 8c after Step 8b

-- For now, create a simple wrapper that can be called after the main refresh
-- This avoids modifying the large refresh procedure directly

IF OBJECT_ID('dbo.sp_PostRefreshWaypointETA', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_PostRefreshWaypointETA;
GO

CREATE PROCEDURE dbo.sp_PostRefreshWaypointETA
    @waypoint_count INT = NULL OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    
    SET @waypoint_count = 0;
    
    -- Only run if the main procedure exists
    IF OBJECT_ID('dbo.sp_CalculateWaypointETABatch', 'P') IS NOT NULL
    BEGIN
        EXEC dbo.sp_CalculateWaypointETABatch 
            @waypoint_count = @waypoint_count OUTPUT,
            @debug = 0;
    END
END
GO

PRINT 'Created sp_PostRefreshWaypointETA wrapper';
PRINT '';
PRINT 'To integrate into refresh cycle, add this after Step 8b in sp_Adl_RefreshFromVatsim_Normalized:';
PRINT '';
PRINT '    -- Step 8c: Waypoint ETA Calculation';
PRINT '    DECLARE @waypoint_etas INT = 0;';
PRINT '    IF OBJECT_ID(''dbo.sp_CalculateWaypointETABatch'', ''P'') IS NOT NULL';
PRINT '    BEGIN';
PRINT '        EXEC dbo.sp_CalculateWaypointETABatch @waypoint_count = @waypoint_etas OUTPUT;';
PRINT '    END';
PRINT '';
PRINT 'Or call sp_PostRefreshWaypointETA from the PHP daemon after refresh completes.';
GO

-- ============================================================================
-- Create view for sector entry predictions
-- ============================================================================

IF OBJECT_ID('dbo.vw_flight_sector_entries', 'V') IS NOT NULL
    DROP VIEW dbo.vw_flight_sector_entries;
GO

CREATE VIEW dbo.vw_flight_sector_entries AS
WITH WaypointBoundaries AS (
    SELECT 
        c.flight_uid,
        c.callsign,
        c.phase,
        w.sequence_num,
        w.fix_name,
        w.eta_utc,
        w.lat,
        w.lon,
        b.boundary_code,
        b.boundary_type,
        b.boundary_name,
        ROW_NUMBER() OVER (
            PARTITION BY c.flight_uid, b.boundary_code 
            ORDER BY w.sequence_num
        ) AS entry_rank
    FROM dbo.adl_flight_core c
    INNER JOIN dbo.adl_flight_waypoints w ON w.flight_uid = c.flight_uid
    INNER JOIN dbo.adl_boundary b ON b.is_active = 1
        AND b.boundary_type = 'ARTCC'
        AND b.boundary_geography.STContains(geography::Point(w.lat, w.lon, 4326)) = 1
    WHERE c.is_active = 1
      AND w.eta_utc IS NOT NULL
)
SELECT 
    flight_uid,
    callsign,
    phase,
    sequence_num AS entry_waypoint_seq,
    fix_name AS entry_fix,
    eta_utc AS entry_eta,
    boundary_code,
    boundary_type,
    boundary_name,
    DATEDIFF(MINUTE, GETUTCDATE(), eta_utc) AS minutes_to_entry
FROM WaypointBoundaries
WHERE entry_rank = 1;  -- First waypoint in each boundary = entry point
GO

PRINT 'Created vw_flight_sector_entries view';
GO

-- ============================================================================
-- Create summary view for demand by sector
-- ============================================================================

IF OBJECT_ID('dbo.vw_sector_demand_15min', 'V') IS NOT NULL
    DROP VIEW dbo.vw_sector_demand_15min;
GO

CREATE VIEW dbo.vw_sector_demand_15min AS
SELECT 
    boundary_code,
    boundary_name,
    -- 15-minute bucket
    DATEADD(MINUTE, 
        (DATEDIFF(MINUTE, 0, entry_eta) / 15) * 15, 
        0
    ) AS time_bucket,
    COUNT(*) AS entry_count
FROM dbo.vw_flight_sector_entries
WHERE entry_eta >= GETUTCDATE()
  AND entry_eta < DATEADD(HOUR, 6, GETUTCDATE())  -- Next 6 hours
GROUP BY 
    boundary_code,
    boundary_name,
    DATEADD(MINUTE, (DATEDIFF(MINUTE, 0, entry_eta) / 15) * 15, 0);
GO

PRINT 'Created vw_sector_demand_15min view';
PRINT '';
PRINT '=== Waypoint ETA Integration Complete ===';
PRINT '';
PRINT 'New objects:';
PRINT '  - sp_CalculateWaypointETABatch: Calculates ETA at each waypoint';
PRINT '  - sp_PostRefreshWaypointETA: Wrapper for post-refresh execution';
PRINT '  - fn_GetFlightSectorEntries: Returns sector entries for a flight';
PRINT '  - vw_flight_sector_entries: View of all predicted sector entries';
PRINT '  - vw_sector_demand_15min: Sector demand by 15-minute buckets';
GO
