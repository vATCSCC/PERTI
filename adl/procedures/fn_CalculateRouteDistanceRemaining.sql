-- ============================================================================
-- fn_CalculateRouteDistanceRemaining.sql
-- 
-- Calculates remaining distance to destination along the parsed route,
-- rather than great circle distance. Uses a combination of:
--   - Route geometry projection (Option B)
--   - Pre-calculated waypoint cumulative distances (Option C)
--
-- Part of ETA Enhancement Project - Item #1 (Route Distance)
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

-- ============================================================================
-- fn_CalculateRouteDistanceRemaining
-- 
-- Returns remaining distance (nm) along the route from current position
-- to destination. Returns NULL if route not parsed or position unknown.
--
-- Algorithm:
--   1. Find the route segment closest to aircraft's current position
--   2. Project position onto that segment
--   3. Calculate: route_total - (cum_dist_to_segment + dist_within_segment)
--
-- Also returns next waypoint information via output table.
-- ============================================================================

IF OBJECT_ID('dbo.fn_CalculateRouteDistanceRemaining', 'TF') IS NOT NULL
    DROP FUNCTION dbo.fn_CalculateRouteDistanceRemaining;
GO

CREATE FUNCTION dbo.fn_CalculateRouteDistanceRemaining (
    @flight_uid BIGINT,
    @current_lat DECIMAL(10,7),
    @current_lon DECIMAL(11,7)
)
RETURNS @result TABLE (
    route_dist_remaining_nm DECIMAL(10,2),
    route_total_nm DECIMAL(10,2),
    route_dist_flown_nm DECIMAL(10,2),
    route_pct_complete DECIMAL(5,2),
    next_waypoint_seq INT,
    next_waypoint_name NVARCHAR(64),
    dist_to_next_waypoint_nm DECIMAL(10,2),
    closest_segment_seq INT,
    projection_method NVARCHAR(16)  -- SEGMENT, GEOMETRY, FALLBACK
)
AS
BEGIN
    -- Validate inputs
    IF @flight_uid IS NULL OR @current_lat IS NULL OR @current_lon IS NULL
    BEGIN
        INSERT INTO @result VALUES (NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'INVALID');
        RETURN;
    END
    
    DECLARE @route_total DECIMAL(10,2);
    DECLARE @waypoint_count INT;
    DECLARE @route_geometry GEOGRAPHY;
    
    -- Get route metadata
    SELECT 
        @route_total = route_total_nm,
        @waypoint_count = waypoint_count,
        @route_geometry = route_geometry
    FROM dbo.adl_flight_plan
    WHERE flight_uid = @flight_uid;
    
    -- No route parsed
    IF @waypoint_count IS NULL OR @waypoint_count < 2
    BEGIN
        INSERT INTO @result VALUES (NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'NO_ROUTE');
        RETURN;
    END
    
    -- Create current position as geography point
    DECLARE @current_pos GEOGRAPHY = geography::Point(@current_lat, @current_lon, 4326);
    
    -- ========================================================================
    -- Method 1: Segment-based projection (preferred - uses pre-calculated distances)
    -- ========================================================================
    
    DECLARE @segments TABLE (
        seg_start_seq INT,
        seg_end_seq INT,
        seg_start_name NVARCHAR(64),
        seg_end_name NVARCHAR(64),
        seg_start_lat DECIMAL(10,7),
        seg_start_lon DECIMAL(11,7),
        seg_end_lat DECIMAL(10,7),
        seg_end_lon DECIMAL(11,7),
        seg_start_cum_dist DECIMAL(10,2),
        seg_end_cum_dist DECIMAL(10,2),
        segment_length_nm DECIMAL(10,2)
    );
    
    -- Build segment list with cumulative distances
    INSERT INTO @segments
    SELECT 
        w1.sequence_num AS seg_start_seq,
        w2.sequence_num AS seg_end_seq,
        w1.fix_name AS seg_start_name,
        w2.fix_name AS seg_end_name,
        w1.lat AS seg_start_lat,
        w1.lon AS seg_start_lon,
        w2.lat AS seg_end_lat,
        w2.lon AS seg_end_lon,
        ISNULL(w1.cum_dist_nm, 0) AS seg_start_cum_dist,
        ISNULL(w2.cum_dist_nm, 0) AS seg_end_cum_dist,
        ISNULL(w2.segment_dist_nm, 0) AS segment_length_nm
    FROM dbo.adl_flight_waypoints w1
    INNER JOIN dbo.adl_flight_waypoints w2 
        ON w2.flight_uid = w1.flight_uid 
        AND w2.sequence_num = w1.sequence_num + 1
    WHERE w1.flight_uid = @flight_uid
      AND w1.lat IS NOT NULL 
      AND w2.lat IS NOT NULL;
    
    -- If no segments with cumulative distances, fall back to geometry method
    IF NOT EXISTS (SELECT 1 FROM @segments WHERE seg_end_cum_dist > 0)
    BEGIN
        -- Try geometry-based calculation
        IF @route_geometry IS NOT NULL AND @route_total IS NOT NULL
        BEGIN
            -- Use STDistance to find closest point on route
            -- Then calculate remaining based on proportion
            DECLARE @dist_to_route FLOAT = @current_pos.STDistance(@route_geometry) / 1852.0;
            
            -- If aircraft is very far from route (>50nm), use great circle fallback
            IF @dist_to_route > 50
            BEGIN
                INSERT INTO @result VALUES (NULL, @route_total, NULL, NULL, NULL, NULL, NULL, NULL, 'OFF_ROUTE');
                RETURN;
            END
            
            -- Simple geometry method: use route_geometry.STLength() and proportion
            -- This is less accurate but works when cum_dist not populated
            DECLARE @route_length_m FLOAT = @route_geometry.STLength();
            
            -- Find approximate position along route by checking waypoints
            DECLARE @best_seq INT = NULL;
            DECLARE @best_dist FLOAT = 999999;
            DECLARE @best_cum_dist DECIMAL(10,2) = 0;
            
            SELECT TOP 1 
                @best_seq = sequence_num,
                @best_dist = position_geo.STDistance(@current_pos),
                @best_cum_dist = ISNULL(cum_dist_nm, 
                    -- Estimate cum_dist if not populated
                    (SELECT ISNULL(SUM(segment_dist_nm), 0) 
                     FROM dbo.adl_flight_waypoints 
                     WHERE flight_uid = @flight_uid AND sequence_num <= w.sequence_num)
                )
            FROM dbo.adl_flight_waypoints w
            WHERE flight_uid = @flight_uid
              AND position_geo IS NOT NULL
            ORDER BY position_geo.STDistance(@current_pos);
            
            IF @best_seq IS NOT NULL
            BEGIN
                DECLARE @remaining DECIMAL(10,2) = @route_total - @best_cum_dist;
                IF @remaining < 0 SET @remaining = 0;
                
                DECLARE @flown DECIMAL(10,2) = @best_cum_dist;
                DECLARE @pct DECIMAL(5,2) = CASE 
                    WHEN @route_total > 0 THEN (@flown / @route_total) * 100.0 
                    ELSE 0 
                END;
                IF @pct > 100 SET @pct = 100;
                
                -- Get next waypoint
                DECLARE @next_seq INT;
                DECLARE @next_name NVARCHAR(64);
                DECLARE @next_lat DECIMAL(10,7);
                DECLARE @next_lon DECIMAL(11,7);
                
                SELECT TOP 1 
                    @next_seq = sequence_num,
                    @next_name = fix_name,
                    @next_lat = lat,
                    @next_lon = lon
                FROM dbo.adl_flight_waypoints
                WHERE flight_uid = @flight_uid
                  AND sequence_num > @best_seq
                  AND lat IS NOT NULL
                ORDER BY sequence_num;
                
                DECLARE @dist_to_next DECIMAL(10,2) = NULL;
                IF @next_lat IS NOT NULL
                BEGIN
                    SET @dist_to_next = @current_pos.STDistance(
                        geography::Point(@next_lat, @next_lon, 4326)
                    ) / 1852.0;
                END
                
                INSERT INTO @result VALUES (
                    @remaining, @route_total, @flown, @pct,
                    @next_seq, @next_name, @dist_to_next,
                    @best_seq, 'GEOMETRY'
                );
                RETURN;
            END
        END
        
        -- Ultimate fallback - no usable data
        INSERT INTO @result VALUES (NULL, @route_total, NULL, NULL, NULL, NULL, NULL, NULL, 'NO_DATA');
        RETURN;
    END
    
    -- ========================================================================
    -- Segment projection: Find closest segment and project position onto it
    -- ========================================================================
    
    DECLARE @closest_seg_start INT = NULL;
    DECLARE @closest_seg_end INT = NULL;
    DECLARE @min_dist_to_segment FLOAT = 999999999;
    DECLARE @projection_dist_along DECIMAL(10,2) = 0;
    
    -- For each segment, calculate distance from current position
    -- Use the segment midpoint + endpoints to find closest
    DECLARE @seg_start INT, @seg_end INT;
    DECLARE @seg_start_lat DECIMAL(10,7), @seg_start_lon DECIMAL(11,7);
    DECLARE @seg_end_lat DECIMAL(10,7), @seg_end_lon DECIMAL(11,7);
    DECLARE @seg_start_cum DECIMAL(10,2), @seg_end_cum DECIMAL(10,2);
    DECLARE @seg_length DECIMAL(10,2);
    
    DECLARE seg_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT seg_start_seq, seg_end_seq, 
               seg_start_lat, seg_start_lon, seg_end_lat, seg_end_lon,
               seg_start_cum_dist, seg_end_cum_dist, segment_length_nm
        FROM @segments
        ORDER BY seg_start_seq;
    
    OPEN seg_cursor;
    FETCH NEXT FROM seg_cursor INTO @seg_start, @seg_end, 
        @seg_start_lat, @seg_start_lon, @seg_end_lat, @seg_end_lon,
        @seg_start_cum, @seg_end_cum, @seg_length;
    
    WHILE @@FETCH_STATUS = 0
    BEGIN
        -- Create segment line
        DECLARE @seg_line GEOGRAPHY = geography::STGeomFromText(
            'LINESTRING(' + 
            CAST(@seg_start_lon AS VARCHAR(20)) + ' ' + CAST(@seg_start_lat AS VARCHAR(20)) + ', ' +
            CAST(@seg_end_lon AS VARCHAR(20)) + ' ' + CAST(@seg_end_lat AS VARCHAR(20)) + ')',
            4326
        );
        
        -- Distance from current position to this segment
        DECLARE @dist_to_seg FLOAT = @current_pos.STDistance(@seg_line);
        
        IF @dist_to_seg < @min_dist_to_segment
        BEGIN
            SET @min_dist_to_segment = @dist_to_seg;
            SET @closest_seg_start = @seg_start;
            SET @closest_seg_end = @seg_end;
            
            -- Calculate projection distance along this segment
            -- Using dot product approximation
            DECLARE @seg_start_pos GEOGRAPHY = geography::Point(@seg_start_lat, @seg_start_lon, 4326);
            DECLARE @seg_end_pos GEOGRAPHY = geography::Point(@seg_end_lat, @seg_end_lon, 4326);
            
            DECLARE @dist_to_start FLOAT = @current_pos.STDistance(@seg_start_pos) / 1852.0;
            DECLARE @dist_to_end FLOAT = @current_pos.STDistance(@seg_end_pos) / 1852.0;
            DECLARE @seg_len_m FLOAT = @seg_line.STLength();
            DECLARE @seg_len_nm FLOAT = @seg_len_m / 1852.0;
            
            -- Use law of cosines to find projection point
            -- a = dist_to_start, b = seg_len, c = dist_to_end
            -- projection_along = (a² + b² - c²) / (2b)
            IF @seg_len_nm > 0.1  -- Avoid division issues
            BEGIN
                DECLARE @proj_along FLOAT = (
                    POWER(@dist_to_start, 2) + POWER(@seg_len_nm, 2) - POWER(@dist_to_end, 2)
                ) / (2 * @seg_len_nm);
                
                -- Clamp to segment bounds
                IF @proj_along < 0 SET @proj_along = 0;
                IF @proj_along > @seg_len_nm SET @proj_along = @seg_len_nm;
                
                SET @projection_dist_along = @seg_start_cum + @proj_along;
            END
            ELSE
            BEGIN
                -- Very short segment, use start point
                SET @projection_dist_along = @seg_start_cum;
            END
        END
        
        FETCH NEXT FROM seg_cursor INTO @seg_start, @seg_end, 
            @seg_start_lat, @seg_start_lon, @seg_end_lat, @seg_end_lon,
            @seg_start_cum, @seg_end_cum, @seg_length;
    END
    
    CLOSE seg_cursor;
    DEALLOCATE seg_cursor;
    
    -- Calculate final values
    DECLARE @final_remaining DECIMAL(10,2) = ISNULL(@route_total, 0) - @projection_dist_along;
    IF @final_remaining < 0 SET @final_remaining = 0;
    
    DECLARE @final_flown DECIMAL(10,2) = @projection_dist_along;
    DECLARE @final_pct DECIMAL(5,2) = CASE 
        WHEN ISNULL(@route_total, 0) > 0 THEN (@final_flown / @route_total) * 100.0 
        ELSE 0 
    END;
    IF @final_pct > 100 SET @final_pct = 100;
    
    -- Get next waypoint (first waypoint after closest segment end)
    DECLARE @final_next_seq INT;
    DECLARE @final_next_name NVARCHAR(64);
    DECLARE @final_dist_to_next DECIMAL(10,2);
    
    SELECT TOP 1 
        @final_next_seq = sequence_num,
        @final_next_name = fix_name,
        @final_dist_to_next = @current_pos.STDistance(position_geo) / 1852.0
    FROM dbo.adl_flight_waypoints
    WHERE flight_uid = @flight_uid
      AND sequence_num >= @closest_seg_end
      AND position_geo IS NOT NULL
    ORDER BY sequence_num;
    
    INSERT INTO @result VALUES (
        @final_remaining,
        @route_total,
        @final_flown,
        @final_pct,
        @final_next_seq,
        @final_next_name,
        @final_dist_to_next,
        @closest_seg_start,
        'SEGMENT'
    );
    
    RETURN;
END;
GO

PRINT 'Created function dbo.fn_CalculateRouteDistanceRemaining';
GO

-- ============================================================================
-- fn_CalculateRouteDistanceRemainingScalar
-- 
-- Scalar wrapper for use in UPDATE statements
-- Returns just the remaining distance
-- ============================================================================

IF OBJECT_ID('dbo.fn_GetRouteDistanceRemaining', 'FN') IS NOT NULL
    DROP FUNCTION dbo.fn_GetRouteDistanceRemaining;
GO

CREATE FUNCTION dbo.fn_GetRouteDistanceRemaining (
    @flight_uid BIGINT,
    @current_lat DECIMAL(10,7),
    @current_lon DECIMAL(11,7)
)
RETURNS DECIMAL(10,2)
AS
BEGIN
    DECLARE @result DECIMAL(10,2);
    
    SELECT @result = route_dist_remaining_nm
    FROM dbo.fn_CalculateRouteDistanceRemaining(@flight_uid, @current_lat, @current_lon);
    
    RETURN @result;
END;
GO

PRINT 'Created function dbo.fn_GetRouteDistanceRemaining';
GO
