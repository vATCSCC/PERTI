-- ============================================================================
-- sp_DetectZoneTransition.sql
-- Detects zone transitions and updates OOOI times
-- 
-- Called for each position update to detect when an aircraft changes zones
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID('dbo.sp_DetectZoneTransition', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_DetectZoneTransition;
GO

CREATE PROCEDURE dbo.sp_DetectZoneTransition
    @flight_uid         BIGINT,
    @lat                DECIMAL(10,7),
    @lon                DECIMAL(11,7),
    @altitude_ft        INT,
    @groundspeed_kts    INT,
    @heading_deg        SMALLINT = NULL,
    @vertical_rate_fpm  INT = NULL,
    @snapshot_utc       DATETIME2(0) = NULL,
    @zone_changed       BIT = 0 OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    
    IF @snapshot_utc IS NULL
        SET @snapshot_utc = SYSUTCDATETIME();
    
    SET @zone_changed = 0;
    
    DECLARE @current_zone NVARCHAR(16);
    DECLARE @current_zone_name NVARCHAR(32);
    DECLARE @prev_zone NVARCHAR(16);
    DECLARE @prev_zone_airport NVARCHAR(4);
    DECLARE @airport_icao NVARCHAR(4);
    DECLARE @detection_method NVARCHAR(32) = 'OSM_GEOMETRY';
    DECLARE @distance_m DECIMAL(10,2);
    DECLARE @confidence DECIMAL(3,2);
    DECLARE @phase NVARCHAR(16);
    DECLARE @dept_icao CHAR(4);
    DECLARE @dest_icao CHAR(4);
    DECLARE @pct_complete DECIMAL(5,2);
    DECLARE @has_departed BIT = 0;
    
    -- ========================================================================
    -- Step 1: Get flight context
    -- ========================================================================
    
    SELECT 
        @prev_zone = c.current_zone,
        @prev_zone_airport = c.current_zone_airport,
        @phase = c.phase,
        @dept_icao = fp.fp_dept_icao,
        @dest_icao = fp.fp_dest_icao,
        @pct_complete = p.pct_complete
    FROM dbo.adl_flight_core c
    LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    WHERE c.flight_uid = @flight_uid;
    
    -- Check if we've departed (off_utc set)
    IF EXISTS (SELECT 1 FROM dbo.adl_flight_times WHERE flight_uid = @flight_uid AND off_utc IS NOT NULL)
        SET @has_departed = 1;
    
    -- ========================================================================
    -- Step 2: Determine which airport to check
    -- ========================================================================
    
    -- Before departure: check departure airport
    -- After departure: check destination airport if close
    IF @has_departed = 0
        SET @airport_icao = @dept_icao;
    ELSE IF ISNULL(@pct_complete, 0) > 80
        SET @airport_icao = @dest_icao;
    ELSE
        SET @airport_icao = NULL;  -- En route, no zone checking needed
    
    -- Skip if no relevant airport
    IF @airport_icao IS NULL
    BEGIN
        -- Still update if we were in a zone before
        IF @prev_zone IS NOT NULL AND @prev_zone != 'AIRBORNE'
        BEGIN
            SET @current_zone = 'AIRBORNE';
            SET @zone_changed = 1;
        END
        ELSE
            RETURN;
    END
    
    -- ========================================================================
    -- Step 3: Detect current zone using OSM geometry
    -- ========================================================================
    
    SELECT TOP 1
        @current_zone = zone_type,
        @current_zone_name = zone_name,
        @distance_m = distance_m,
        @detection_method = detection_method,
        @confidence = confidence
    FROM dbo.fn_DetectCurrentZoneWithDetails(@airport_icao, @lat, @lon, @altitude_ft, @groundspeed_kts);
    
    -- ========================================================================
    -- Step 4: Fallback detection if no OSM match
    -- ========================================================================
    
    IF @current_zone IS NULL
    BEGIN
        SET @detection_method = 'SPEED_FALLBACK';
        SET @confidence = 0.60;
        
        -- Get airport elevation for AGL calculation
        DECLARE @airport_elev INT;
        SELECT @airport_elev = ISNULL(CAST(ELEV AS INT), 0) FROM dbo.apts WHERE ICAO_ID = @airport_icao;
        DECLARE @agl INT = @altitude_ft - ISNULL(@airport_elev, 0);
        
        -- Speed/altitude based fallback
        IF @agl > 500
            SET @current_zone = 'AIRBORNE';
        ELSE IF @groundspeed_kts < 5
            SET @current_zone = 'PARKING';
        ELSE IF @groundspeed_kts BETWEEN 5 AND 35
            SET @current_zone = 'TAXIWAY';
        ELSE IF @groundspeed_kts > 35 AND @agl < 100
            SET @current_zone = 'RUNWAY';
        ELSE
            SET @current_zone = 'AIRBORNE';
    END
    
    -- ========================================================================
    -- Step 5: Check for zone transition
    -- ========================================================================
    
    IF @current_zone != ISNULL(@prev_zone, '') OR @airport_icao != ISNULL(@prev_zone_airport, '')
    BEGIN
        SET @zone_changed = 1;
        
        -- Log zone event
        INSERT INTO dbo.adl_zone_events (
            flight_uid, event_utc, event_type,
            airport_icao, from_zone, to_zone, zone_name,
            lat, lon, altitude_ft, groundspeed_kts, heading_deg, vertical_rate_fpm,
            detection_method, distance_to_zone_m, confidence
        )
        VALUES (
            @flight_uid, @snapshot_utc, 'TRANSITION',
            @airport_icao, @prev_zone, @current_zone, @current_zone_name,
            @lat, @lon, @altitude_ft, @groundspeed_kts, @heading_deg, @vertical_rate_fpm,
            @detection_method, @distance_m, @confidence
        );
        
        -- Update flight core with current zone
        UPDATE dbo.adl_flight_core
        SET current_zone = @current_zone,
            current_zone_airport = @airport_icao,
            last_zone_check_utc = @snapshot_utc
        WHERE flight_uid = @flight_uid;
        
        -- ====================================================================
        -- Step 6: Update OOOI times based on zone transition
        -- ====================================================================
        
        -- DEPARTURE TRANSITIONS
        IF @has_departed = 0
        BEGIN
            UPDATE dbo.adl_flight_times
            SET
                -- OUT: Left parking
                out_utc = CASE 
                    WHEN @prev_zone = 'PARKING' AND @current_zone NOT IN ('PARKING', 'GATE') AND out_utc IS NULL
                    THEN @snapshot_utc ELSE out_utc END,
                
                parking_left_utc = CASE 
                    WHEN @prev_zone = 'PARKING' AND @current_zone != 'PARKING' AND parking_left_utc IS NULL
                    THEN @snapshot_utc ELSE parking_left_utc END,
                
                taxiway_entered_utc = CASE
                    WHEN @prev_zone IN ('PARKING', 'APRON', 'TAXILANE') AND @current_zone = 'TAXIWAY' AND taxiway_entered_utc IS NULL
                    THEN @snapshot_utc ELSE taxiway_entered_utc END,
                
                hold_entered_utc = CASE
                    WHEN @prev_zone = 'TAXIWAY' AND @current_zone = 'HOLD' AND hold_entered_utc IS NULL
                    THEN @snapshot_utc ELSE hold_entered_utc END,
                
                runway_entered_utc = CASE
                    WHEN @prev_zone IN ('TAXIWAY', 'HOLD') AND @current_zone = 'RUNWAY' AND runway_entered_utc IS NULL
                    THEN @snapshot_utc ELSE runway_entered_utc END,
                
                -- OFF: Became airborne
                off_utc = CASE
                    WHEN @prev_zone = 'RUNWAY' AND @current_zone = 'AIRBORNE' AND off_utc IS NULL
                    THEN @snapshot_utc ELSE off_utc END,
                
                takeoff_roll_utc = CASE
                    WHEN @current_zone = 'RUNWAY' AND @groundspeed_kts > 40 AND takeoff_roll_utc IS NULL
                    THEN @snapshot_utc ELSE takeoff_roll_utc END,
                
                rotation_utc = CASE
                    WHEN @prev_zone = 'RUNWAY' AND @current_zone = 'AIRBORNE' AND rotation_utc IS NULL
                    THEN @snapshot_utc ELSE rotation_utc END,
                
                times_updated_utc = @snapshot_utc
            WHERE flight_uid = @flight_uid;
        END
        
        -- ARRIVAL TRANSITIONS
        ELSE
        BEGIN
            UPDATE dbo.adl_flight_times
            SET
                -- ON: Touched down
                on_utc = CASE
                    WHEN @prev_zone = 'AIRBORNE' AND @current_zone = 'RUNWAY' AND on_utc IS NULL
                    THEN @snapshot_utc ELSE on_utc END,
                
                touchdown_utc = CASE
                    WHEN @prev_zone = 'AIRBORNE' AND @current_zone = 'RUNWAY' AND touchdown_utc IS NULL
                    THEN @snapshot_utc ELSE touchdown_utc END,
                
                rollout_end_utc = CASE
                    WHEN @prev_zone = 'RUNWAY' AND @current_zone IN ('TAXIWAY', 'TAXILANE') AND rollout_end_utc IS NULL
                    THEN @snapshot_utc ELSE rollout_end_utc END,
                
                taxiway_arr_utc = CASE
                    WHEN @prev_zone = 'RUNWAY' AND @current_zone IN ('TAXIWAY', 'TAXILANE') AND taxiway_arr_utc IS NULL
                    THEN @snapshot_utc ELSE taxiway_arr_utc END,
                
                parking_entered_utc = CASE
                    WHEN @prev_zone IN ('APRON', 'TAXILANE', 'TAXIWAY') AND @current_zone = 'PARKING' AND parking_entered_utc IS NULL
                    THEN @snapshot_utc ELSE parking_entered_utc END,
                
                -- IN: Arrived at parking
                in_utc = CASE
                    WHEN @prev_zone IN ('APRON', 'TAXILANE', 'TAXIWAY') AND @current_zone = 'PARKING' AND in_utc IS NULL
                    THEN @snapshot_utc ELSE in_utc END,
                
                times_updated_utc = @snapshot_utc
            WHERE flight_uid = @flight_uid;
        END
    END
    ELSE
    BEGIN
        -- No zone change, just update last check time
        UPDATE dbo.adl_flight_core
        SET last_zone_check_utc = @snapshot_utc
        WHERE flight_uid = @flight_uid;
    END
    
END
GO

PRINT 'Created stored procedure dbo.sp_DetectZoneTransition';
GO
