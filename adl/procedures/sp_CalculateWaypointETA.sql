-- ============================================================================
-- sp_CalculateWaypointETA.sql (V1)
-- Calculate ETA at each waypoint along the route
--
-- Purpose:
--   - Projects flight position forward to calculate ETA at each waypoint
--   - Enables sector/boundary entry time prediction
--   - Supports demand forecasting at fixes
--
-- Algorithm:
--   1. Find current position along route (dist_flown_nm vs cum_dist_nm)
--   2. For waypoints ahead of current position:
--      - Calculate remaining distance to waypoint
--      - Use groundspeed (or cruise speed) to estimate time
--      - Add to current time for waypoint ETA
--
-- Date: 2026-01-07
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID('dbo.sp_CalculateWaypointETABatch', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_CalculateWaypointETABatch;
GO

CREATE PROCEDURE dbo.sp_CalculateWaypointETABatch
    @waypoint_count INT = NULL OUTPUT,
    @debug BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @start_time DATETIME2(3) = SYSDATETIME();
    
    SET @waypoint_count = 0;
    
    -- ========================================================================
    -- Step 1: Build flight context with position and speed data
    -- ========================================================================
    IF @debug = 1
        PRINT 'Step 1: Building flight context...';
    
    DROP TABLE IF EXISTS #flight_context;
    
    SELECT 
        c.flight_uid,
        c.phase,
        p.dist_flown_nm,
        p.dist_to_dest_nm,
        p.groundspeed_kts,
        -- Use groundspeed if available and reasonable, else estimate from aircraft
        CASE 
            WHEN p.groundspeed_kts > 50 AND p.groundspeed_kts < 700 THEN p.groundspeed_kts
            WHEN c.phase IN ('enroute', 'cruise', 'descending') THEN 450
            WHEN c.phase IN ('climbing', 'departed') THEN 380
            ELSE 350
        END AS effective_speed_kts,
        -- Get max waypoint distance for route length validation
        (SELECT MAX(w.cum_dist_nm) FROM dbo.adl_flight_waypoints w WHERE w.flight_uid = c.flight_uid) AS max_waypoint_dist
    INTO #flight_context
    FROM dbo.adl_flight_core c
    INNER JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
    WHERE c.is_active = 1
      AND c.phase NOT IN ('arrived', 'in')  -- Skip arrived flights
      AND EXISTS (SELECT 1 FROM dbo.adl_flight_waypoints w WHERE w.flight_uid = c.flight_uid);
    
    IF @debug = 1
    BEGIN
        DECLARE @fc_count INT;
        SELECT @fc_count = COUNT(*) FROM #flight_context;
        PRINT '  Flights with waypoints: ' + CAST(@fc_count AS VARCHAR);
    END
    
    -- ========================================================================
    -- Step 2: Calculate waypoint ETAs
    -- For each waypoint ahead of current position, calculate ETA
    -- ========================================================================
    IF @debug = 1
        PRINT 'Step 2: Calculating waypoint ETAs...';
    
    DROP TABLE IF EXISTS #waypoint_etas;
    
    ;WITH WaypointCalc AS (
        SELECT 
            w.waypoint_id,
            w.flight_uid,
            w.sequence_num,
            w.fix_name,
            w.cum_dist_nm,
            fc.dist_flown_nm,
            fc.effective_speed_kts,
            fc.phase,
            fc.max_waypoint_dist,
            
            -- Distance remaining to this waypoint from current position
            CASE 
                WHEN w.cum_dist_nm > ISNULL(fc.dist_flown_nm, 0) 
                THEN w.cum_dist_nm - ISNULL(fc.dist_flown_nm, 0)
                ELSE 0  -- Already passed this waypoint
            END AS dist_remaining_nm,
            
            -- Flag if waypoint is ahead or behind
            CASE 
                WHEN w.cum_dist_nm > ISNULL(fc.dist_flown_nm, 0) THEN 1
                ELSE 0
            END AS is_ahead
            
        FROM dbo.adl_flight_waypoints w
        INNER JOIN #flight_context fc ON fc.flight_uid = w.flight_uid
    )
    SELECT 
        wc.waypoint_id,
        wc.flight_uid,
        wc.sequence_num,
        wc.fix_name,
        wc.cum_dist_nm,
        wc.dist_remaining_nm,
        wc.effective_speed_kts,
        wc.is_ahead,
        
        -- Calculate ETA
        CASE 
            WHEN wc.is_ahead = 0 THEN NULL  -- Already passed
            WHEN wc.effective_speed_kts < 50 THEN NULL  -- Invalid speed
            ELSE DATEADD(
                SECOND, 
                CAST(wc.dist_remaining_nm / wc.effective_speed_kts * 3600 AS INT),
                @now
            )
        END AS calc_eta_utc,
        
        -- Minutes to waypoint
        CASE 
            WHEN wc.is_ahead = 0 THEN NULL
            WHEN wc.effective_speed_kts < 50 THEN NULL
            ELSE wc.dist_remaining_nm / wc.effective_speed_kts * 60
        END AS minutes_to_waypoint
        
    INTO #waypoint_etas
    FROM WaypointCalc wc;
    
    IF @debug = 1
    BEGIN
        SELECT 
            SUM(CASE WHEN is_ahead = 1 THEN 1 ELSE 0 END) AS waypoints_ahead,
            SUM(CASE WHEN is_ahead = 0 THEN 1 ELSE 0 END) AS waypoints_passed,
            SUM(CASE WHEN calc_eta_utc IS NOT NULL THEN 1 ELSE 0 END) AS with_eta
        FROM #waypoint_etas;
    END
    
    -- ========================================================================
    -- Step 3: Update waypoint table with calculated ETAs
    -- ========================================================================
    IF @debug = 1
        PRINT 'Step 3: Updating waypoint ETAs...';
    
    UPDATE w
    SET w.eta_utc = we.calc_eta_utc
    FROM dbo.adl_flight_waypoints w
    INNER JOIN #waypoint_etas we ON we.waypoint_id = w.waypoint_id
    WHERE we.calc_eta_utc IS NOT NULL;
    
    SET @waypoint_count = @@ROWCOUNT;
    
    -- Also clear ETAs for passed waypoints (set to NULL or could set ATA)
    UPDATE w
    SET w.eta_utc = NULL
    FROM dbo.adl_flight_waypoints w
    INNER JOIN #waypoint_etas we ON we.waypoint_id = w.waypoint_id
    WHERE we.is_ahead = 0 AND w.eta_utc IS NOT NULL;
    
    IF @debug = 1
    BEGIN
        PRINT '  Waypoint ETAs updated: ' + CAST(@waypoint_count AS VARCHAR);
        PRINT '  Duration: ' + CAST(DATEDIFF(MILLISECOND, @start_time, SYSDATETIME()) AS VARCHAR) + 'ms';
    END
    
    -- Cleanup
    DROP TABLE IF EXISTS #flight_context;
    DROP TABLE IF EXISTS #waypoint_etas;
    
END
GO

PRINT 'Created sp_CalculateWaypointETABatch';
GO

-- ============================================================================
-- Helper: Get sector entry times for a flight
-- Uses waypoint ETAs + boundary intersection
-- ============================================================================
IF OBJECT_ID('dbo.fn_GetFlightSectorEntries', 'TF') IS NOT NULL
    DROP FUNCTION dbo.fn_GetFlightSectorEntries;
GO

CREATE FUNCTION dbo.fn_GetFlightSectorEntries (
    @flight_uid BIGINT
)
RETURNS @result TABLE (
    waypoint_seq INT,
    fix_name NVARCHAR(64),
    eta_utc DATETIME2(0),
    boundary_code VARCHAR(16),
    boundary_type VARCHAR(16),
    boundary_name NVARCHAR(128)
)
AS
BEGIN
    -- Find which boundary each waypoint is in
    -- Return distinct boundary entries (first waypoint in each boundary)
    
    ;WITH WaypointBoundaries AS (
        SELECT 
            w.sequence_num,
            w.fix_name,
            w.eta_utc,
            w.lat,
            w.lon,
            b.boundary_code,
            b.boundary_type,
            b.boundary_name,
            ROW_NUMBER() OVER (
                PARTITION BY b.boundary_code 
                ORDER BY w.sequence_num
            ) AS rn
        FROM dbo.adl_flight_waypoints w
        CROSS APPLY (
            SELECT TOP 1 
                boundary_code, 
                boundary_type, 
                boundary_name
            FROM dbo.adl_boundary b
            WHERE b.is_active = 1
              AND b.boundary_type = 'ARTCC'
              AND b.boundary_geography IS NOT NULL
              AND b.boundary_geography.STIsValid() = 1
              AND w.lat BETWEEN -90 AND 90 AND w.lon BETWEEN -180 AND 180
              AND b.boundary_geography.STContains(
                  geography::Point(w.lat, w.lon, 4326)
              ) = 1
            ORDER BY b.boundary_id
        ) b
        WHERE w.flight_uid = @flight_uid
          AND w.eta_utc IS NOT NULL
    )
    INSERT INTO @result
    SELECT 
        sequence_num,
        fix_name,
        eta_utc,
        boundary_code,
        boundary_type,
        boundary_name
    FROM WaypointBoundaries
    WHERE rn = 1  -- First waypoint in each boundary = entry point
    ORDER BY sequence_num;
    
    RETURN;
END
GO

PRINT 'Created fn_GetFlightSectorEntries';
GO
