-- ============================================================================
-- sp_CalculateRouteDistanceBatch.sql
-- 
-- Batch procedures for route distance calculations:
--   1. sp_BackfillRouteDistances - Backfill distances for existing parsed routes
--   2. sp_UpdateRouteDistances - Update route_dist_to_dest for active flights
--
-- Part of ETA Enhancement Project - Item #1 (Route Distance)
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

-- ============================================================================
-- sp_BackfillRouteDistances
-- 
-- Backfills segment_dist_nm, cum_dist_nm, and route_total_nm for routes
-- that were parsed before the distance calculation was added.
-- ============================================================================

IF OBJECT_ID('dbo.sp_BackfillRouteDistances', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_BackfillRouteDistances;
GO

CREATE PROCEDURE dbo.sp_BackfillRouteDistances
    @batch_size INT = 100,
    @debug BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @start_time DATETIME2(3) = SYSUTCDATETIME();
    DECLARE @flights_processed INT = 0;
    DECLARE @waypoints_updated INT = 0;
    
    -- Find flights with parsed routes but no distance data
    DECLARE @flights TABLE (flight_uid BIGINT);
    
    INSERT INTO @flights (flight_uid)
    SELECT DISTINCT TOP (@batch_size) fp.flight_uid
    FROM dbo.adl_flight_plan fp
    WHERE fp.parse_status = 'COMPLETE'
      AND fp.waypoint_count >= 2
      AND fp.route_total_nm IS NULL
      AND EXISTS (
          SELECT 1 FROM dbo.adl_flight_waypoints w 
          WHERE w.flight_uid = fp.flight_uid
      );
    
    IF @debug = 1
    BEGIN
        DECLARE @found_count INT;
        SELECT @found_count = COUNT(*) FROM @flights;
        PRINT 'Found ' + CAST(@found_count AS VARCHAR) + ' flights to backfill';
    END
    
    -- Process each flight
    DECLARE @flight_uid BIGINT;
    
    DECLARE flight_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT flight_uid FROM @flights;
    
    OPEN flight_cursor;
    FETCH NEXT FROM flight_cursor INTO @flight_uid;
    
    WHILE @@FETCH_STATUS = 0
    BEGIN
        -- Calculate segment distances
        ;WITH WaypointPairs AS (
            SELECT 
                w.waypoint_id,
                w.sequence_num,
                w.lat,
                w.lon,
                LAG(w.lat) OVER (ORDER BY w.sequence_num) AS prev_lat,
                LAG(w.lon) OVER (ORDER BY w.sequence_num) AS prev_lon
            FROM dbo.adl_flight_waypoints w
            WHERE w.flight_uid = @flight_uid
        )
        UPDATE fw
        SET segment_dist_nm = CASE 
            WHEN wp.prev_lat IS NOT NULL AND wp.prev_lon IS NOT NULL 
                 AND wp.lat IS NOT NULL AND wp.lon IS NOT NULL
            THEN CAST(
                geography::Point(wp.prev_lat, wp.prev_lon, 4326).STDistance(
                    geography::Point(wp.lat, wp.lon, 4326)
                ) / 1852.0 AS DECIMAL(10,2)
            )
            ELSE 0
        END
        FROM dbo.adl_flight_waypoints fw
        INNER JOIN WaypointPairs wp ON wp.waypoint_id = fw.waypoint_id;
        
        SET @waypoints_updated = @waypoints_updated + @@ROWCOUNT;
        
        -- Calculate cumulative distances
        ;WITH CumulativeCalc AS (
            SELECT 
                waypoint_id,
                sequence_num,
                SUM(ISNULL(segment_dist_nm, 0)) OVER (ORDER BY sequence_num) AS running_total
            FROM dbo.adl_flight_waypoints
            WHERE flight_uid = @flight_uid
        )
        UPDATE fw
        SET cum_dist_nm = cc.running_total
        FROM dbo.adl_flight_waypoints fw
        INNER JOIN CumulativeCalc cc ON cc.waypoint_id = fw.waypoint_id;
        
        -- Update route_total_nm in flight plan
        UPDATE dbo.adl_flight_plan
        SET route_total_nm = (
            SELECT MAX(cum_dist_nm)
            FROM dbo.adl_flight_waypoints
            WHERE flight_uid = @flight_uid
        )
        WHERE flight_uid = @flight_uid;
        
        SET @flights_processed = @flights_processed + 1;
        
        FETCH NEXT FROM flight_cursor INTO @flight_uid;
    END
    
    CLOSE flight_cursor;
    DEALLOCATE flight_cursor;
    
    IF @debug = 1
    BEGIN
        DECLARE @elapsed_ms INT = DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME());
        PRINT 'Backfill complete:';
        PRINT '  Flights processed: ' + CAST(@flights_processed AS VARCHAR);
        PRINT '  Waypoints updated: ' + CAST(@waypoints_updated AS VARCHAR);
        PRINT '  Elapsed: ' + CAST(@elapsed_ms AS VARCHAR) + 'ms';
    END
    
    SELECT @flights_processed AS flights_processed, @waypoints_updated AS waypoints_updated;
END;
GO

PRINT 'Created procedure dbo.sp_BackfillRouteDistances';
GO

-- ============================================================================
-- sp_UpdateRouteDistancesBatch
-- 
-- Updates route_dist_to_dest_nm for active flights using the parsed route
-- and current position. Called during refresh cycle.
-- ============================================================================

IF OBJECT_ID('dbo.sp_UpdateRouteDistancesBatch', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_UpdateRouteDistancesBatch;
GO

CREATE PROCEDURE dbo.sp_UpdateRouteDistancesBatch
    @flights_updated INT = NULL OUTPUT,
    @debug BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @start_time DATETIME2(3) = SYSUTCDATETIME();
    SET @flights_updated = 0;
    
    -- Update route distances for active flights with parsed routes
    -- This uses the fn_CalculateRouteDistanceRemaining function
    
    -- First, build a temp table with results (more efficient than calling TVF per row)
    CREATE TABLE #route_distances (
        flight_uid BIGINT PRIMARY KEY,
        route_dist_remaining_nm DECIMAL(10,2),
        route_total_nm DECIMAL(10,2),
        route_dist_flown_nm DECIMAL(10,2),
        route_pct_complete DECIMAL(5,2),
        next_waypoint_seq INT,
        next_waypoint_name NVARCHAR(64),
        dist_to_next_waypoint_nm DECIMAL(10,2)
    );
    
    -- Get active flights with positions and parsed routes
    DECLARE @flight_uid BIGINT;
    DECLARE @lat DECIMAL(10,7);
    DECLARE @lon DECIMAL(11,7);
    
    DECLARE flight_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT c.flight_uid, p.lat, p.lon
        FROM dbo.adl_flight_core c
        INNER JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
        INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        WHERE c.is_active = 1
          AND p.lat IS NOT NULL
          AND fp.route_total_nm IS NOT NULL
          AND fp.route_total_nm > 0;
    
    OPEN flight_cursor;
    FETCH NEXT FROM flight_cursor INTO @flight_uid, @lat, @lon;
    
    WHILE @@FETCH_STATUS = 0
    BEGIN
        INSERT INTO #route_distances
        SELECT 
            @flight_uid,
            route_dist_remaining_nm,
            route_total_nm,
            route_dist_flown_nm,
            route_pct_complete,
            next_waypoint_seq,
            next_waypoint_name,
            dist_to_next_waypoint_nm
        FROM dbo.fn_CalculateRouteDistanceRemaining(@flight_uid, @lat, @lon);
        
        FETCH NEXT FROM flight_cursor INTO @flight_uid, @lat, @lon;
    END
    
    CLOSE flight_cursor;
    DEALLOCATE flight_cursor;
    
    -- Update positions with calculated route distances
    UPDATE p
    SET 
        p.route_dist_to_dest_nm = rd.route_dist_remaining_nm,
        p.route_pct_complete = rd.route_pct_complete,
        p.next_waypoint_seq = rd.next_waypoint_seq,
        p.next_waypoint_name = rd.next_waypoint_name,
        p.dist_to_next_waypoint_nm = rd.dist_to_next_waypoint_nm
    FROM dbo.adl_flight_position p
    INNER JOIN #route_distances rd ON rd.flight_uid = p.flight_uid
    WHERE rd.route_dist_remaining_nm IS NOT NULL;
    
    SET @flights_updated = @@ROWCOUNT;
    
    DROP TABLE #route_distances;
    
    IF @debug = 1
    BEGIN
        DECLARE @elapsed_ms INT = DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME());
        PRINT 'Route distance update complete:';
        PRINT '  Flights updated: ' + CAST(@flights_updated AS VARCHAR);
        PRINT '  Elapsed: ' + CAST(@elapsed_ms AS VARCHAR) + 'ms';
    END
END;
GO

PRINT 'Created procedure dbo.sp_UpdateRouteDistancesBatch';
GO

-- ============================================================================
-- sp_BackfillAllRouteDistances
-- 
-- Convenience wrapper to backfill ALL existing routes
-- ============================================================================

IF OBJECT_ID('dbo.sp_BackfillAllRouteDistances', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_BackfillAllRouteDistances;
GO

CREATE PROCEDURE dbo.sp_BackfillAllRouteDistances
    @batch_size INT = 100
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @total_processed INT = 0;
    DECLARE @batch_processed INT = 1;
    DECLARE @start_time DATETIME2(3) = SYSUTCDATETIME();
    
    PRINT 'Starting full backfill of route distances...';
    
    WHILE @batch_processed > 0
    BEGIN
        DECLARE @result TABLE (flights_processed INT, waypoints_updated INT);
        DELETE FROM @result;
        
        INSERT INTO @result
        EXEC dbo.sp_BackfillRouteDistances @batch_size = @batch_size, @debug = 0;
        
        SELECT @batch_processed = flights_processed FROM @result;
        SET @total_processed = @total_processed + @batch_processed;
        
        IF @batch_processed > 0
            PRINT '  Processed batch: ' + CAST(@batch_processed AS VARCHAR) + ' flights (total: ' + CAST(@total_processed AS VARCHAR) + ')';
    END
    
    DECLARE @elapsed_sec INT = DATEDIFF(SECOND, @start_time, SYSUTCDATETIME());
    PRINT '';
    PRINT 'Backfill complete:';
    PRINT '  Total flights: ' + CAST(@total_processed AS VARCHAR);
    PRINT '  Elapsed: ' + CAST(@elapsed_sec AS VARCHAR) + ' seconds';
END;
GO

PRINT 'Created procedure dbo.sp_BackfillAllRouteDistances';
GO

-- ============================================================================
-- Summary
-- ============================================================================

PRINT '';
PRINT '=== Route Distance Batch Procedures Created ===';
PRINT '';
PRINT 'Procedures:';
PRINT '  sp_BackfillRouteDistances     - Backfill batch of routes';
PRINT '  sp_BackfillAllRouteDistances  - Backfill ALL routes';
PRINT '  sp_UpdateRouteDistancesBatch  - Update active flight distances';
PRINT '';
PRINT 'Usage:';
PRINT '  -- Backfill existing routes:';
PRINT '  EXEC sp_BackfillAllRouteDistances;';
PRINT '';
PRINT '  -- Add to refresh cycle:';
PRINT '  EXEC sp_UpdateRouteDistancesBatch @flights_updated = @count OUTPUT;';
GO
