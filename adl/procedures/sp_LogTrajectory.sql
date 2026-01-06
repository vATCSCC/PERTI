-- ============================================================================
-- sp_LogTrajectory.sql
-- Evaluates trajectory tier and logs position if interval criteria met
-- 
-- Part of the ETA & Trajectory Calculation System
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID('dbo.sp_LogTrajectory', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_LogTrajectory;
GO

CREATE PROCEDURE dbo.sp_LogTrajectory
    @flight_uid     BIGINT,
    @force_log      BIT = 0,
    @tier_out       TINYINT = NULL OUTPUT,
    @logged         BIT = 0 OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @tier TINYINT;
    DECLARE @tier_reason NVARCHAR(32);
    DECLARE @should_log BIT = 0;
    DECLARE @last_logged_utc DATETIME2(0);
    DECLARE @last_tier TINYINT;
    DECLARE @interval_seconds INT;
    
    -- Position data
    DECLARE @lat DECIMAL(10,7);
    DECLARE @lon DECIMAL(11,7);
    DECLARE @altitude INT;
    DECLARE @groundspeed INT;
    DECLARE @heading SMALLINT;
    DECLARE @vrate INT;
    
    -- Flight data
    DECLARE @phase NVARCHAR(16);
    DECLARE @dept_icao CHAR(4);
    DECLARE @dest_icao CHAR(4);
    DECLARE @dist_to_dest DECIMAL(10,2);
    DECLARE @dist_flown DECIMAL(10,2);
    DECLARE @filed_alt INT;
    DECLARE @is_relevant BIT;
    
    -- ========================================================================
    -- Step 1: Gather current flight data
    -- ========================================================================
    
    SELECT 
        @lat = p.lat,
        @lon = p.lon,
        @altitude = p.altitude_ft,
        @groundspeed = p.groundspeed_kts,
        @heading = p.heading_deg,
        @vrate = ISNULL(p.vertical_rate_fpm, 0),
        @phase = c.phase,
        @dept_icao = fp.fp_dept_icao,
        @dest_icao = fp.fp_dest_icao,
        @dist_to_dest = p.dist_to_dest_nm,
        @dist_flown = p.dist_flown_nm,
        @filed_alt = fp.fp_altitude_ft,
        @last_tier = c.last_trajectory_tier,
        @last_logged_utc = c.last_trajectory_utc,
        @is_relevant = c.is_relevant
    FROM dbo.adl_flight_core c
    LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
    WHERE c.flight_uid = @flight_uid
      AND c.is_active = 1;
    
    -- No active flight found
    IF @lat IS NULL
    BEGIN
        SET @tier_out = 7;
        SET @logged = 0;
        RETURN;
    END
    
    -- ========================================================================
    -- Step 2: Check relevance (Tier 7 = no logging)
    -- ========================================================================
    
    -- If we haven't checked relevance yet, do it now
    IF @is_relevant IS NULL
    BEGIN
        SET @is_relevant = dbo.fn_IsFlightRelevant(@dept_icao, @dest_icao, @lat, @lon);
        
        -- Update the cached relevance flag
        UPDATE dbo.adl_flight_core
        SET is_relevant = @is_relevant
        WHERE flight_uid = @flight_uid;
    END
    
    -- Not relevant = Tier 7 = no logging
    IF @is_relevant = 0
    BEGIN
        SET @tier_out = 7;
        SET @logged = 0;
        RETURN;
    END
    
    -- ========================================================================
    -- Step 3: Calculate tier using the function
    -- ========================================================================
    
    SET @tier = dbo.fn_GetTrajectoryTier(
        @dept_icao, @dest_icao, @lat, @lon, @altitude, @groundspeed,
        @vrate, @dist_to_dest, @dist_flown, @filed_alt, @phase
    );
    
    SET @tier_out = @tier;
    
    -- Tier 7 shouldn't happen here since we checked relevance, but just in case
    IF @tier = 7
    BEGIN
        SET @logged = 0;
        RETURN;
    END
    
    -- ========================================================================
    -- Step 4: Determine tier reason for logging
    -- ========================================================================
    
    SET @tier_reason = CASE @tier
        WHEN 0 THEN 
            CASE 
                WHEN @dist_flown < 50 AND @vrate > 300 AND @altitude < 18000 THEN 'INITIAL_CLIMB'
                WHEN @dist_to_dest < 15 AND @vrate < -300 AND @altitude < 10000 THEN 'FINAL_APPROACH'
                WHEN @groundspeed > 40 AND @altitude < 500 THEN 'RUNWAY_OPS'
                ELSE 'CRITICAL_PHASE'
            END
        WHEN 1 THEN 
            CASE
                WHEN @dist_to_dest < 100 THEN 'APPROACHING_DEST'
                ELSE 'BOUNDARY_TMI'
            END
        WHEN 2 THEN 'OCEANIC_TRANSIT'
        WHEN 3 THEN 'GROUND_OPS'
        WHEN 4 THEN 'STABLE_CRUISE'
        WHEN 5 THEN 'EXTENDED_OCEANIC'
        WHEN 6 THEN 'ULTRA_LONG'
        ELSE 'UNKNOWN'
    END;
    
    -- ========================================================================
    -- Step 5: Check if we should log based on tier interval
    -- ========================================================================
    
    SET @interval_seconds = dbo.fn_GetTierIntervalSeconds(@tier);
    
    IF @force_log = 1
        SET @should_log = 1;
    ELSE IF @last_logged_utc IS NULL
        SET @should_log = 1;
    ELSE IF DATEDIFF(SECOND, @last_logged_utc, @now) >= @interval_seconds
        SET @should_log = 1;
    -- Also log on tier change (promotion to more critical tier)
    ELSE IF @last_tier IS NOT NULL AND @tier < @last_tier
        SET @should_log = 1;
    
    -- ========================================================================
    -- Step 6: Log trajectory point if criteria met
    -- ========================================================================
    
    IF @should_log = 1
    BEGIN
        -- Insert trajectory point
        INSERT INTO dbo.adl_flight_trajectory (
            flight_uid,
            recorded_utc,
            lat,
            lon,
            altitude_ft,
            groundspeed_kts,
            heading_deg,
            vertical_rate_fpm,
            tier,
            tier_reason,
            flight_phase,
            dist_to_dest_nm,
            dist_from_origin_nm,
            source
        )
        VALUES (
            @flight_uid,
            @now,
            @lat,
            @lon,
            @altitude,
            @groundspeed,
            @heading,
            @vrate,
            @tier,
            @tier_reason,
            @phase,
            @dist_to_dest,
            @dist_flown,
            'vatsim'
        );
        
        -- Update core table with last trajectory info
        UPDATE dbo.adl_flight_core
        SET last_trajectory_tier = @tier,
            last_trajectory_utc = @now
        WHERE flight_uid = @flight_uid;
        
        SET @logged = 1;
    END
    ELSE
    BEGIN
        SET @logged = 0;
    END
    
END
GO

PRINT 'Created stored procedure dbo.sp_LogTrajectory';
GO
