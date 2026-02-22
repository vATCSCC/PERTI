-- ============================================================================
-- Migration 008: Covering index for route distance calculation
--
-- Problem: sp_UpdateRouteDistancesBatch Step B self-joins adl_flight_waypoints
-- to build segments. The existing IX_waypoint_flight index on (flight_uid)
-- finds rows but requires 315K+ key lookups per cycle to fetch lat, lon,
-- cum_dist_nm, segment_dist_nm, fix_name from the clustered PK.
--
-- Solution: Covering index on (flight_uid, sequence_num) with INCLUDE columns
-- that the route distance SP needs. Eliminates all key lookups for this query.
--
-- Impact: Step B (INSERT INTO #rd_segments) should drop from ~1643ms to
-- ~600-800ms by avoiding 300K+ bookmark lookups per execution.
--
-- Risk: None. This is a new index — no existing queries or indexes are modified.
-- Index maintenance cost is low because waypoints only change when routes are
-- parsed (~50-100 flights per cycle, not every flight).
-- ============================================================================

-- Drop if exists (idempotent)
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_waypoints_route_calc' AND object_id = OBJECT_ID('dbo.adl_flight_waypoints'))
BEGIN
    DROP INDEX IX_waypoints_route_calc ON dbo.adl_flight_waypoints;
    PRINT 'Dropped existing IX_waypoints_route_calc';
END
GO

CREATE NONCLUSTERED INDEX IX_waypoints_route_calc
ON dbo.adl_flight_waypoints (flight_uid, sequence_num)
INCLUDE (lat, lon, cum_dist_nm, segment_dist_nm, fix_name)
WITH (ONLINE = ON, SORT_IN_TEMPDB = ON);
GO

PRINT 'Created IX_waypoints_route_calc covering index';
PRINT 'Columns: (flight_uid, sequence_num) INCLUDE (lat, lon, cum_dist_nm, segment_dist_nm, fix_name)';
PRINT 'Expected impact: Route distance Step B 1643ms -> ~600-800ms';
GO
