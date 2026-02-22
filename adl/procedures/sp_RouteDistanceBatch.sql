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
-- sp_UpdateRouteDistancesBatch  (V2.2 - Set-based rewrite)
--
-- Updates route_dist_to_dest_nm for active flights using the parsed route
-- and current position. Called during refresh cycle.
--
-- V2.0: Replaced cursor + msTVF with set-based temp tables + window functions.
-- V2.1: Switched to geodesic LINESTRING STDistance for closest-segment detection
--        (planar perpendicular distance diverged on long oceanic segments).
-- V2.2: Two-pass optimization - LINESTRING STDistance for closest segment only,
--        endpoint distances computed only for the winner (~900 vs ~19000 calls).
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

    -- ========================================================================
    -- Step A: Collect active flights with parsed routes and current positions
    -- ========================================================================
    CREATE TABLE #rd_flights (
        flight_uid BIGINT PRIMARY KEY,
        current_pos GEOGRAPHY,
        route_total_nm DECIMAL(10,2),
        waypoint_count INT,
        route_geometry GEOGRAPHY
    );

    INSERT INTO #rd_flights (flight_uid, current_pos, route_total_nm, waypoint_count, route_geometry)
    SELECT c.flight_uid, pos.position_geo, fp.route_total_nm, fp.waypoint_count,
           CASE WHEN fp.route_geometry IS NOT NULL THEN fp.route_geometry.MakeValid() ELSE NULL END
    FROM dbo.adl_flight_core c
    INNER JOIN dbo.adl_flight_position pos ON pos.flight_uid = c.flight_uid
    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
    WHERE c.is_active = 1
      AND pos.position_geo IS NOT NULL
      AND fp.route_total_nm IS NOT NULL
      AND fp.route_total_nm > 0;

    IF @debug = 1
    BEGIN
        DECLARE @flight_count INT;
        SELECT @flight_count = COUNT(*) FROM #rd_flights;
        PRINT 'Step A: ' + CAST(@flight_count AS VARCHAR) + ' flights to process';
    END

    -- ========================================================================
    -- Step B: Build all route segments for all flights at once
    -- Adjacent waypoint pairs with lat/lon, excluding zero-length (V1.1)
    -- ========================================================================
    CREATE TABLE #rd_segments (
        flight_uid BIGINT,
        seg_start_seq INT,
        seg_end_seq INT,
        seg_start_lat DECIMAL(10,7),
        seg_start_lon DECIMAL(11,7),
        seg_end_lat DECIMAL(10,7),
        seg_end_lon DECIMAL(11,7),
        seg_start_geo GEOGRAPHY,
        seg_end_geo GEOGRAPHY,
        seg_start_cum_dist DECIMAL(10,2),
        seg_end_cum_dist DECIMAL(10,2),
        segment_length_nm DECIMAL(10,2),
        INDEX IX_rd_seg_flight (flight_uid)
    );

    INSERT INTO #rd_segments
    SELECT w1.flight_uid, w1.sequence_num, w2.sequence_num,
           w1.lat, w1.lon, w2.lat, w2.lon,
           COALESCE(w1.position_geo, geography::Point(w1.lat, w1.lon, 4326)),
           COALESCE(w2.position_geo, geography::Point(w2.lat, w2.lon, 4326)),
           ISNULL(w1.cum_dist_nm, 0), ISNULL(w2.cum_dist_nm, 0),
           ISNULL(w2.segment_dist_nm, 0)
    FROM dbo.adl_flight_waypoints w1
    INNER JOIN dbo.adl_flight_waypoints w2
        ON w2.flight_uid = w1.flight_uid AND w2.sequence_num = w1.sequence_num + 1
    INNER JOIN #rd_flights f ON f.flight_uid = w1.flight_uid
    WHERE w1.lat IS NOT NULL AND w2.lat IS NOT NULL
      AND NOT (w1.lat = w2.lat AND w1.lon = w2.lon);

    IF @debug = 1
    BEGIN
        DECLARE @seg_count INT;
        SELECT @seg_count = COUNT(*) FROM #rd_segments;
        PRINT 'Step B: ' + CAST(@seg_count AS VARCHAR) + ' total segments';
    END

    -- ========================================================================
    -- Step C: Classify flights into SEGMENT vs GEOMETRY path
    -- SEGMENT = has cum_dist data;  GEOMETRY = all segments have cum_dist=0
    -- ========================================================================
    CREATE TABLE #rd_segment_flights (flight_uid BIGINT PRIMARY KEY);
    CREATE TABLE #rd_geometry_flights (flight_uid BIGINT PRIMARY KEY);

    INSERT INTO #rd_segment_flights (flight_uid)
    SELECT s.flight_uid
    FROM #rd_segments s
    GROUP BY s.flight_uid
    HAVING MAX(s.seg_end_cum_dist) > 0;

    INSERT INTO #rd_geometry_flights (flight_uid)
    SELECT s.flight_uid
    FROM #rd_segments s
    WHERE s.flight_uid NOT IN (SELECT flight_uid FROM #rd_segment_flights)
    GROUP BY s.flight_uid;

    IF @debug = 1
    BEGIN
        DECLARE @seg_flight_count INT, @geo_flight_count INT;
        SELECT @seg_flight_count = COUNT(*) FROM #rd_segment_flights;
        SELECT @geo_flight_count = COUNT(*) FROM #rd_geometry_flights;
        PRINT 'Step C: SEGMENT=' + CAST(@seg_flight_count AS VARCHAR) + ' GEOMETRY=' + CAST(@geo_flight_count AS VARCHAR);
    END

    -- ========================================================================
    -- Step D: SEGMENT path - find closest segment per flight (two-pass)
    -- Pass 1: Geodesic LINESTRING STDistance to find closest segment (exact V1 match)
    -- Pass 2: Law of cosines projection on closest segment only (~900 vs ~19000 calls)
    -- ========================================================================

    -- D1 (Pass 1): LINESTRING STDistance to find closest segment (geodesic, exact V1 match)
    -- Materialized into temp table to avoid double LINESTRING computation in CTE
    SELECT
        s.flight_uid,
        s.seg_start_seq,
        s.seg_end_seq,
        s.seg_start_cum_dist,
        s.segment_length_nm,
        f.current_pos.STDistance(
            geography::STGeomFromText(
                'LINESTRING(' +
                CAST(s.seg_start_lon AS VARCHAR(20)) + ' ' + CAST(s.seg_start_lat AS VARCHAR(20)) + ', ' +
                CAST(s.seg_end_lon AS VARCHAR(20)) + ' ' + CAST(s.seg_end_lat AS VARCHAR(20)) + ')',
                4326
            )
        ) AS dist_to_segment_m
    INTO #rd_seg_dists
    FROM #rd_segments s
    INNER JOIN #rd_flights f ON f.flight_uid = s.flight_uid
    WHERE s.flight_uid IN (SELECT flight_uid FROM #rd_segment_flights);

    -- Pick closest segment per flight
    ;WITH ranked AS (
        SELECT *, ROW_NUMBER() OVER (PARTITION BY flight_uid ORDER BY dist_to_segment_m, seg_start_seq) AS rn
        FROM #rd_seg_dists
    )
    SELECT flight_uid, seg_start_seq, seg_end_seq, seg_start_cum_dist, segment_length_nm
    INTO #rd_closest_seg
    FROM ranked WHERE rn = 1;

    DROP TABLE #rd_seg_dists;

    -- D2 (Pass 2): Compute projection on closest segment only
    -- endpoint distances computed here (not for all 19000 segments)
    ;WITH projected AS (
        SELECT
            c.flight_uid,
            c.seg_start_seq,
            c.seg_end_seq,
            c.seg_start_cum_dist,
            c.segment_length_nm,
            CASE WHEN c.segment_length_nm > 0.1
                THEN (POWER(f.current_pos.STDistance(s.seg_start_geo) / 1852.0, 2)
                    + POWER(c.segment_length_nm, 2)
                    - POWER(f.current_pos.STDistance(s.seg_end_geo) / 1852.0, 2))
                    / (2.0 * c.segment_length_nm)
                ELSE 0
            END AS raw_projection
        FROM #rd_closest_seg c
        INNER JOIN #rd_flights f ON f.flight_uid = c.flight_uid
        INNER JOIN #rd_segments s ON s.flight_uid = c.flight_uid AND s.seg_start_seq = c.seg_start_seq
    )
    SELECT flight_uid, seg_start_seq, seg_end_seq, seg_start_cum_dist,
           CASE WHEN raw_projection < 0 THEN 0
                WHEN raw_projection > segment_length_nm THEN segment_length_nm
                ELSE raw_projection END AS projection,
           seg_start_cum_dist + CASE WHEN raw_projection < 0 THEN 0
                WHEN raw_projection > segment_length_nm THEN segment_length_nm
                ELSE raw_projection END AS dist_flown_nm
    INTO #rd_closest
    FROM projected;

    DROP TABLE #rd_closest_seg;

    IF @debug = 1
    BEGIN
        DECLARE @closest_count INT;
        SELECT @closest_count = COUNT(*) FROM #rd_closest;
        PRINT 'Step D: ' + CAST(@closest_count AS VARCHAR) + ' flights matched via SEGMENT path';
    END

    -- ========================================================================
    -- Step E: GEOMETRY fallback - find closest waypoint for flights without cum_dist
    -- Same algorithm as msTVF GEOMETRY path: closest waypoint by position_geo,
    -- off-route check (>50nm from route_geometry), cum_dist estimation
    -- ========================================================================
    IF EXISTS (SELECT 1 FROM #rd_geometry_flights)
    BEGIN
        ;WITH closest_wp AS (
            SELECT
                f.flight_uid,
                w.sequence_num,
                ISNULL(w.cum_dist_nm,
                    (SELECT ISNULL(SUM(w2.segment_dist_nm), 0)
                     FROM dbo.adl_flight_waypoints w2
                     WHERE w2.flight_uid = f.flight_uid AND w2.sequence_num <= w.sequence_num)
                ) AS cum_dist,
                f.current_pos.STDistance(w.position_geo.MakeValid()) / 1852.0 AS dist_nm,
                ROW_NUMBER() OVER (
                    PARTITION BY f.flight_uid
                    ORDER BY f.current_pos.STDistance(w.position_geo.MakeValid())
                ) AS rn
            FROM #rd_flights f
            INNER JOIN dbo.adl_flight_waypoints w
                ON w.flight_uid = f.flight_uid AND w.position_geo IS NOT NULL
            WHERE f.flight_uid IN (SELECT flight_uid FROM #rd_geometry_flights)
              AND f.route_geometry IS NOT NULL
              AND f.current_pos.STDistance(f.route_geometry) / 1852.0 <= 50
        )
        INSERT INTO #rd_closest (flight_uid, seg_start_seq, seg_end_seq, seg_start_cum_dist, projection, dist_flown_nm)
        SELECT flight_uid, sequence_num, sequence_num, cum_dist, 0, cum_dist
        FROM closest_wp WHERE rn = 1;

        IF @debug = 1
        BEGIN
            DECLARE @geo_matched INT;
            SELECT @geo_matched = COUNT(*) FROM #rd_closest c
            INNER JOIN #rd_geometry_flights g ON g.flight_uid = c.flight_uid;
            PRINT 'Step E: ' + CAST(@geo_matched AS VARCHAR) + ' flights matched via GEOMETRY path';
        END
    END

    -- ========================================================================
    -- Step F: Compute final results with next waypoint
    -- Same output columns as fn_CalculateRouteDistanceRemaining
    -- ========================================================================
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

    INSERT INTO #route_distances
    SELECT
        r.flight_uid,
        -- route_dist_remaining_nm (clamped to >= 0)
        CASE WHEN f.route_total_nm - r.dist_flown_nm < 0 THEN 0
             ELSE CAST(f.route_total_nm - r.dist_flown_nm AS DECIMAL(10,2)) END,
        f.route_total_nm,
        CAST(r.dist_flown_nm AS DECIMAL(10,2)),
        -- route_pct_complete (capped at 100)
        CASE WHEN f.route_total_nm > 0
             THEN CASE WHEN (r.dist_flown_nm / f.route_total_nm) * 100.0 > 100 THEN CAST(100 AS DECIMAL(5,2))
                       ELSE CAST((r.dist_flown_nm / f.route_total_nm) * 100.0 AS DECIMAL(5,2)) END
             ELSE CAST(0 AS DECIMAL(5,2)) END,
        nw.sequence_num,
        nw.fix_name,
        CASE WHEN nw.wp_geo IS NOT NULL
             THEN CAST(f.current_pos.STDistance(nw.wp_geo) / 1852.0 AS DECIMAL(10,2))
             ELSE NULL END
    FROM #rd_closest r
    INNER JOIN #rd_flights f ON f.flight_uid = r.flight_uid
    OUTER APPLY (
        SELECT TOP 1 w.sequence_num, w.fix_name,
               w.position_geo.MakeValid() AS wp_geo
        FROM dbo.adl_flight_waypoints w
        WHERE w.flight_uid = r.flight_uid
          AND w.sequence_num >= r.seg_end_seq
          AND w.position_geo IS NOT NULL
        ORDER BY w.sequence_num
    ) nw;

    -- ========================================================================
    -- Step G: Final UPDATE to adl_flight_position (unchanged from V1)
    -- ========================================================================
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

    -- Cleanup
    DROP TABLE #route_distances;
    DROP TABLE #rd_closest;
    DROP TABLE #rd_segment_flights;
    DROP TABLE #rd_geometry_flights;
    DROP TABLE #rd_segments;
    DROP TABLE #rd_flights;

    IF @debug = 1
    BEGIN
        DECLARE @elapsed_ms INT = DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME());
        PRINT 'Route distance update complete (V2.2 two-pass geodesic):';
        PRINT '  Flights updated: ' + CAST(@flights_updated AS VARCHAR);
        PRINT '  Elapsed: ' + CAST(@elapsed_ms AS VARCHAR) + 'ms';
    END
END;
GO

PRINT 'Created procedure dbo.sp_UpdateRouteDistancesBatch V2.2 (two-pass geodesic)';
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
