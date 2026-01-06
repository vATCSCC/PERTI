-- ============================================================================
-- fn_GetTrajectoryTier.sql
-- Main trajectory tier calculation function
-- 
-- Returns tier 0-7 based on:
-- - Flight phase (critical phases = Tier 0)
-- - Approaching events (TOD/TOC, boundaries, weather = Tier 1)
-- - Geographic relevance (oceanic = Tier 2)
-- - Ground operations (Tier 3)
-- - Stable cruise (Tier 4-6)
-- - Irrelevant flights (Tier 7 = no logging)
-- 
-- Part of the ETA & Trajectory Calculation System
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID('dbo.fn_GetTrajectoryTier', 'FN') IS NOT NULL
    DROP FUNCTION dbo.fn_GetTrajectoryTier;
GO

CREATE FUNCTION dbo.fn_GetTrajectoryTier(
    @dept_icao          CHAR(4),
    @dest_icao          CHAR(4),
    @current_lat        DECIMAL(10,7),
    @current_lon        DECIMAL(11,7),
    @altitude_ft        INT,
    @groundspeed_kts    INT,
    @vertical_rate_fpm  INT,
    @dist_to_dest_nm    DECIMAL(10,2),
    @dist_from_origin_nm DECIMAL(10,2),
    @filed_altitude_ft  INT,
    @phase              NVARCHAR(16)
)
RETURNS TINYINT
AS
BEGIN
    DECLARE @tier TINYINT = 4;  -- Default tier
    DECLARE @is_relevant BIT;
    
    -- Null handling
    SET @altitude_ft = ISNULL(@altitude_ft, 0);
    SET @groundspeed_kts = ISNULL(@groundspeed_kts, 0);
    SET @vertical_rate_fpm = ISNULL(@vertical_rate_fpm, 0);
    SET @dist_to_dest_nm = ISNULL(@dist_to_dest_nm, 9999);
    SET @dist_from_origin_nm = ISNULL(@dist_from_origin_nm, 0);
    SET @filed_altitude_ft = ISNULL(@filed_altitude_ft, 35000);
    
    -- ========================================================================
    -- TIER 7 CHECK: Relevance
    -- ========================================================================
    
    SET @is_relevant = dbo.fn_IsFlightRelevant(@dept_icao, @dest_icao, @current_lat, @current_lon);
    
    IF @is_relevant = 0
        RETURN 7;
    
    -- ========================================================================
    -- TIER 0 MANDATORY: Critical Phases (15 seconds)
    -- ========================================================================
    
    -- Initial climb: <50nm from origin, climbing, <FL180
    IF @dist_from_origin_nm < 50 
       AND @vertical_rate_fpm > 300 
       AND @altitude_ft < 18000
        RETURN 0;
    
    -- Final approach: <15nm from destination, descending, <10,000ft
    IF @dist_to_dest_nm < 15 
       AND @vertical_rate_fpm < -300 
       AND @altitude_ft < 10000
        RETURN 0;
    
    -- Go-around detection: climbing rapidly near airport
    IF @dist_to_dest_nm < 5 
       AND @vertical_rate_fpm > 1000 
       AND @altitude_ft < 5000
        RETURN 0;
    
    -- Runway operations: high speed on ground near airport
    IF @groundspeed_kts > 40 
       AND @groundspeed_kts < 180 
       AND @altitude_ft < 500
       AND (@dist_from_origin_nm < 5 OR @dist_to_dest_nm < 5)
        RETURN 0;
    
    -- Very close to either airport
    IF @dist_from_origin_nm < 3 OR @dist_to_dest_nm < 3
        RETURN 0;
    
    -- ========================================================================
    -- TIER 1 PROMOTIONS: Approaching Events (30 seconds)
    -- ========================================================================
    
    -- Calculate TOD distance (3nm per 1000ft)
    DECLARE @tod_dist_nm DECIMAL(10,2) = (@filed_altitude_ft - 0) / 1000.0 * 3.0;
    DECLARE @time_to_tod_min DECIMAL(10,2) = NULL;
    
    IF @dist_to_dest_nm > @tod_dist_nm AND @groundspeed_kts > 0
        SET @time_to_tod_min = (@dist_to_dest_nm - @tod_dist_nm) / @groundspeed_kts * 60;
    
    -- Approaching TOD (within 5 minutes)
    IF @time_to_tod_min IS NOT NULL AND @time_to_tod_min <= 5 AND @time_to_tod_min > 0
        RETURN 1;
    
    -- Approaching destination (< 100nm)
    IF @dist_to_dest_nm < 100
        RETURN 1;
    
    -- Speed anomaly in cruise: significant speed change indication
    -- (Would need historical data to detect, using proxy)
    IF @altitude_ft > 25000 AND ABS(@vertical_rate_fpm) > 500
        RETURN 1;
    
    -- Climbing phase (not initial)
    IF @vertical_rate_fpm > 300 AND @altitude_ft >= 18000
        RETURN 1;
    
    -- Descending phase (not final)
    IF @vertical_rate_fpm < -300 AND @altitude_ft >= 10000 AND @dist_to_dest_nm >= 15
        RETURN 1;
    
    -- Phase indicates transition
    IF @phase IN ('departed', 'climbing', 'descending')
        RETURN 1;
    
    -- ========================================================================
    -- TIER 2: Oceanic / Transit Entry-Exit (1 minute)
    -- ========================================================================
    
    -- Check if in oceanic airspace (simplified)
    DECLARE @is_oceanic BIT = 0;
    
    -- North Atlantic
    IF @current_lat BETWEEN 35 AND 65 AND @current_lon BETWEEN -60 AND -10
        SET @is_oceanic = 1;
    -- North Pacific  
    IF @current_lat BETWEEN 20 AND 60 AND @current_lon BETWEEN -180 AND -140
        SET @is_oceanic = 1;
    -- Central Pacific (Hawaii)
    IF @current_lat BETWEEN 15 AND 35 AND @current_lon BETWEEN -180 AND -150
        SET @is_oceanic = 1;
    
    IF @is_oceanic = 1 AND @dist_to_dest_nm > 100 AND ABS(@vertical_rate_fpm) < 500
        SET @tier = 2;
    
    -- ========================================================================
    -- TIER 3: Ground Operations (2 minutes)
    -- ========================================================================
    
    -- Taxiing
    IF @groundspeed_kts BETWEEN 5 AND 35 AND @altitude_ft < 500
        RETURN 3;
    
    -- Parked but engine running (based on phase)
    IF @phase = 'taxiing'
        RETURN 3;
    
    -- ========================================================================
    -- TIER 4: Stable Cruise (5 minutes)
    -- ========================================================================
    
    -- Level flight, cruising
    IF ABS(@vertical_rate_fpm) < 200 
       AND @altitude_ft > 20000 
       AND @dist_to_dest_nm > 200 
       AND @dist_from_origin_nm > 100
    BEGIN
        -- Blocking check: don't demote if approaching TOD within 10 min
        IF @time_to_tod_min IS NOT NULL AND @time_to_tod_min <= 10
            RETURN 1;  -- Stay at Tier 1
        
        SET @tier = 4;
    END
    
    -- Pre-departure / parked
    IF @phase IN ('prefile', 'unknown') AND @groundspeed_kts < 5
        SET @tier = 4;
    
    -- ========================================================================
    -- TIER 5: Extended Oceanic / Sim Pause (10 minutes)
    -- ========================================================================
    
    -- Extended stable oceanic cruise
    IF @is_oceanic = 1 
       AND ABS(@vertical_rate_fpm) < 100 
       AND @altitude_ft > 30000
       AND @dist_to_dest_nm > 500
    BEGIN
        -- Blocking check: don't demote if approaching TOD within 15 min
        IF @time_to_tod_min IS NOT NULL AND @time_to_tod_min <= 15
            RETURN 1;
            
        SET @tier = 5;
    END
    
    -- Sim pause (stationary in air)
    IF @groundspeed_kts < 50 AND @altitude_ft > 10000
        SET @tier = 5;
    
    -- ========================================================================
    -- TIER 6: Ultra-Long Oceanic (30 minutes)
    -- ========================================================================
    
    -- Ultra-long oceanic (very far from land)
    IF @is_oceanic = 1 
       AND ABS(@vertical_rate_fpm) < 50 
       AND @altitude_ft > 35000
       AND @dist_to_dest_nm > 1000
       AND @dist_from_origin_nm > 1000
    BEGIN
        -- Blocking check: don't demote if approaching TOD within 30 min
        IF @time_to_tod_min IS NOT NULL AND @time_to_tod_min <= 30
            RETURN 1;
            
        SET @tier = 6;
    END
    
    RETURN @tier;
END
GO

PRINT 'Created function dbo.fn_GetTrajectoryTier';
GO


-- ============================================================================
-- fn_GetTierIntervalSeconds - Tier to interval lookup
-- ============================================================================

IF OBJECT_ID('dbo.fn_GetTierIntervalSeconds', 'FN') IS NOT NULL
    DROP FUNCTION dbo.fn_GetTierIntervalSeconds;
GO

CREATE FUNCTION dbo.fn_GetTierIntervalSeconds(@tier TINYINT)
RETURNS INT
AS
BEGIN
    RETURN CASE @tier
        WHEN 0 THEN 15      -- 15 seconds
        WHEN 1 THEN 30      -- 30 seconds
        WHEN 2 THEN 60      -- 1 minute
        WHEN 3 THEN 120     -- 2 minutes
        WHEN 4 THEN 300     -- 5 minutes
        WHEN 5 THEN 600     -- 10 minutes
        WHEN 6 THEN 1800    -- 30 minutes
        WHEN 7 THEN 999999  -- No logging (effectively infinite)
        ELSE 300            -- Default 5 minutes
    END;
END
GO

PRINT 'Created function dbo.fn_GetTierIntervalSeconds';
GO
