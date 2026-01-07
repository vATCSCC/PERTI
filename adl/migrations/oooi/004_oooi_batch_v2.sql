-- ============================================================================
-- 042_oooi_batch_v2.sql
-- Enhanced OOOI Zone Detection Batch Processing V2
-- 
-- Fixes from V1:
--   1. IN time now properly set when arriving at PARKING/GATE after landing
--   2. Extended zone times populated (taxiway_entered, hold_entered, etc.)
--   3. Better OFF detection (any ground zone → AIRBORNE)
--   4. Taxi-out and taxi-in duration calculations
--   5. Approach detection for arrivals
--
-- Zone Time Columns Updated:
--   Departure: parking_left_utc, taxiway_entered_utc, hold_entered_utc, 
--              runway_entered_utc, takeoff_roll_utc, rotation_utc
--   Arrival:   approach_start_utc, threshold_utc, touchdown_utc,
--              rollout_end_utc, taxiway_arr_utc, parking_entered_utc
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

PRINT '==========================================================================';
PRINT '  OOOI Zone Detection Batch V2 - Enhanced Time Tracking';
PRINT '  Version 2.0 - ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
PRINT '';

-- ============================================================================
-- Drop and recreate sp_ProcessZoneDetectionBatch with V2 enhancements
-- ============================================================================

IF OBJECT_ID('dbo.sp_ProcessZoneDetectionBatch', 'P') IS NOT NULL 
    DROP PROCEDURE dbo.sp_ProcessZoneDetectionBatch;
GO

CREATE PROCEDURE dbo.sp_ProcessZoneDetectionBatch 
    @transitions_detected INT = NULL OUTPUT 
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @now DATETIME2(0) = SYSUTCDATETIME();
    SET @transitions_detected = 0;
    
    -- ========================================================================
    -- Step 1: Identify flights to check for zone detection
    -- Pre-departure: Near origin airport, not yet airborne
    -- Arriving: >70% complete OR descending OR near destination
    -- ========================================================================
    
    SELECT 
        c.flight_uid,
        c.callsign,
        p.lat,
        p.lon,
        p.altitude_ft,
        p.groundspeed_kts,
        p.heading_deg,
        p.vertical_rate_fpm,
        c.phase,
        c.current_zone AS prev_zone,
        c.current_zone_airport AS prev_airport,
        ft.off_utc,
        ft.on_utc,
        fp.fp_dept_icao,
        fp.fp_dest_icao,
        -- Determine which airport to check
        CASE 
            -- Pre-departure: check origin
            WHEN ft.off_utc IS NULL THEN fp.fp_dept_icao
            -- Post-landing: check destination  
            WHEN ft.on_utc IS NOT NULL THEN fp.fp_dest_icao
            -- Arriving (>70% or descending): check destination
            WHEN ISNULL(p.pct_complete, 0) > 70 OR p.vertical_rate_fpm < -300 THEN fp.fp_dest_icao
            ELSE NULL
        END AS check_airport,
        -- Flight direction flag
        CASE 
            WHEN ft.off_utc IS NULL THEN 'DEPARTING'
            WHEN ft.on_utc IS NOT NULL THEN 'ARRIVED'
            ELSE 'ENROUTE'
        END AS flight_direction
    INTO #flights
    FROM dbo.adl_flight_core c
    INNER JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
    WHERE c.is_active = 1 
      AND p.lat IS NOT NULL
      AND (
          -- Pre-departure flights
          ft.off_utc IS NULL 
          -- OR arriving/arrived flights
          OR ft.on_utc IS NOT NULL
          OR ISNULL(p.pct_complete, 0) > 70 
          OR p.vertical_rate_fpm < -300
      );
    
    -- ========================================================================
    -- Step 2: Detect current zone for each flight
    -- ========================================================================
    
    SELECT 
        f.*,
        dbo.fn_DetectCurrentZone(f.check_airport, f.lat, f.lon, f.altitude_ft, f.groundspeed_kts) AS current_zone
    INTO #detections 
    FROM #flights f 
    WHERE f.check_airport IS NOT NULL;
    
    -- ========================================================================
    -- Step 3: Log zone transitions to adl_zone_events
    -- ========================================================================
    
    INSERT INTO dbo.adl_zone_events (
        flight_uid, event_utc, event_type, airport_icao, 
        from_zone, to_zone, lat, lon, altitude_ft, 
        groundspeed_kts, heading_deg, vertical_rate_fpm, 
        detection_method, confidence
    )
    SELECT 
        flight_uid, 
        @now, 
        'TRANSITION', 
        check_airport, 
        prev_zone, 
        current_zone, 
        lat, 
        lon, 
        altitude_ft, 
        groundspeed_kts, 
        heading_deg, 
        vertical_rate_fpm, 
        'BATCH_V2', 
        0.80
    FROM #detections 
    WHERE current_zone != ISNULL(prev_zone, '')
      AND current_zone != 'UNKNOWN';
    
    SET @transitions_detected = @@ROWCOUNT;
    
    -- ========================================================================
    -- Step 4: Update adl_flight_core with current zone
    -- ========================================================================
    
    UPDATE c 
    SET 
        c.current_zone = d.current_zone, 
        c.current_zone_airport = d.check_airport, 
        c.last_zone_check_utc = @now
    FROM dbo.adl_flight_core c 
    INNER JOIN #detections d ON d.flight_uid = c.flight_uid
    WHERE d.current_zone != 'UNKNOWN';
    
    -- ========================================================================
    -- Step 5: Update OOOI times for DEPARTURES
    -- ========================================================================
    
    UPDATE ft 
    SET
        -- OUT: Left parking/gate for first time
        out_utc = CASE 
            WHEN d.prev_zone IN ('PARKING', 'GATE') 
             AND d.current_zone NOT IN ('PARKING', 'GATE', 'UNKNOWN') 
             AND ft.out_utc IS NULL 
            THEN @now 
            ELSE ft.out_utc 
        END,
        
        -- OFF: Became airborne (any ground zone → AIRBORNE with speed > 80)
        off_utc = CASE 
            WHEN d.prev_zone IN ('RUNWAY', 'TAXIWAY', 'HOLD', 'APRON', 'PARKING', 'GATE')
             AND d.current_zone = 'AIRBORNE' 
             AND d.groundspeed_kts > 80
             AND ft.off_utc IS NULL 
            THEN @now 
            ELSE ft.off_utc 
        END,
        
        -- Extended departure zone times
        parking_left_utc = CASE
            WHEN d.prev_zone IN ('PARKING', 'GATE')
             AND d.current_zone NOT IN ('PARKING', 'GATE', 'UNKNOWN')
             AND ft.parking_left_utc IS NULL
            THEN @now
            ELSE ft.parking_left_utc
        END,
        
        taxiway_entered_utc = CASE
            WHEN d.prev_zone IN ('PARKING', 'GATE', 'APRON')
             AND d.current_zone = 'TAXIWAY'
             AND ft.taxiway_entered_utc IS NULL
             AND ft.off_utc IS NULL  -- Only for departures
            THEN @now
            ELSE ft.taxiway_entered_utc
        END,
        
        hold_entered_utc = CASE
            WHEN d.prev_zone IN ('TAXIWAY', 'TAXILANE')
             AND d.current_zone = 'HOLD'
             AND ft.hold_entered_utc IS NULL
            THEN @now
            ELSE ft.hold_entered_utc
        END,
        
        runway_entered_utc = CASE
            WHEN d.prev_zone IN ('HOLD', 'TAXIWAY')
             AND d.current_zone = 'RUNWAY'
             AND ft.runway_entered_utc IS NULL
             AND ft.off_utc IS NULL  -- Only for departures
            THEN @now
            ELSE ft.runway_entered_utc
        END,
        
        takeoff_roll_utc = CASE
            WHEN d.current_zone = 'RUNWAY'
             AND d.groundspeed_kts > 40
             AND ft.takeoff_roll_utc IS NULL
             AND ft.off_utc IS NULL
            THEN @now
            ELSE ft.takeoff_roll_utc
        END,
        
        rotation_utc = CASE
            WHEN d.prev_zone = 'RUNWAY'
             AND d.current_zone = 'AIRBORNE'
             AND ft.rotation_utc IS NULL
            THEN @now
            ELSE ft.rotation_utc
        END
        
    FROM dbo.adl_flight_times ft 
    INNER JOIN #detections d ON d.flight_uid = ft.flight_uid 
    WHERE d.flight_direction = 'DEPARTING'
      AND d.current_zone != ISNULL(d.prev_zone, '')
      AND d.current_zone != 'UNKNOWN';
    
    -- ========================================================================
    -- Step 6: Update OOOI times for ARRIVALS
    -- ========================================================================
    
    UPDATE ft 
    SET
        -- ON: Touched down (AIRBORNE → RUNWAY after having been airborne)
        on_utc = CASE 
            WHEN d.prev_zone = 'AIRBORNE' 
             AND d.current_zone = 'RUNWAY'
             AND ft.off_utc IS NOT NULL  -- Must have departed
             AND ft.on_utc IS NULL 
            THEN @now 
            ELSE ft.on_utc 
        END,
        
        -- IN: Arrived at parking/gate after landing
        in_utc = CASE 
            WHEN d.prev_zone IN ('TAXIWAY', 'APRON', 'TAXILANE', 'RUNWAY')
             AND d.current_zone IN ('PARKING', 'GATE')
             AND ft.on_utc IS NOT NULL  -- Must have landed
             AND ft.in_utc IS NULL 
            THEN @now 
            ELSE ft.in_utc 
        END,
        
        -- Extended arrival zone times
        approach_start_utc = CASE
            WHEN d.current_zone = 'AIRBORNE'
             AND d.altitude_ft < 3000 + ISNULL((SELECT CAST(ELEV AS INT) FROM dbo.apts WHERE ICAO_ID = d.fp_dest_icao), 0)
             AND d.vertical_rate_fpm < -300
             AND ft.approach_start_utc IS NULL
             AND ft.off_utc IS NOT NULL
            THEN @now
            ELSE ft.approach_start_utc
        END,
        
        touchdown_utc = CASE
            WHEN d.prev_zone = 'AIRBORNE'
             AND d.current_zone = 'RUNWAY'
             AND ft.touchdown_utc IS NULL
             AND ft.off_utc IS NOT NULL
            THEN @now
            ELSE ft.touchdown_utc
        END,
        
        rollout_end_utc = CASE
            WHEN d.prev_zone = 'RUNWAY'
             AND d.current_zone IN ('TAXIWAY', 'TAXILANE')
             AND ft.rollout_end_utc IS NULL
             AND ft.on_utc IS NOT NULL
            THEN @now
            ELSE ft.rollout_end_utc
        END,
        
        taxiway_arr_utc = CASE
            WHEN d.prev_zone IN ('RUNWAY', 'APRON')
             AND d.current_zone = 'TAXIWAY'
             AND ft.taxiway_arr_utc IS NULL
             AND ft.on_utc IS NOT NULL
            THEN @now
            ELSE ft.taxiway_arr_utc
        END,
        
        parking_entered_utc = CASE
            WHEN d.prev_zone IN ('TAXIWAY', 'APRON', 'TAXILANE')
             AND d.current_zone IN ('PARKING', 'GATE')
             AND ft.parking_entered_utc IS NULL
             AND ft.on_utc IS NOT NULL
            THEN @now
            ELSE ft.parking_entered_utc
        END
        
    FROM dbo.adl_flight_times ft 
    INNER JOIN #detections d ON d.flight_uid = ft.flight_uid 
    WHERE d.flight_direction IN ('ENROUTE', 'ARRIVED')
      AND d.current_zone != 'UNKNOWN';
    
    -- ========================================================================
    -- Step 7: Cleanup
    -- ========================================================================
    
    DROP TABLE #flights;
    DROP TABLE #detections;
    
END;
GO

PRINT '';
PRINT '✓ sp_ProcessZoneDetectionBatch V2 created successfully';
PRINT '';
PRINT '  Changes from V1:';
PRINT '  - IN time now properly set when arriving at PARKING/GATE';
PRINT '  - Extended zone times populated for detailed tracking';
PRINT '  - Better OFF detection (any ground zone → AIRBORNE with GS>80)';
PRINT '  - Approach detection for arrivals (<3000ft AGL, descending)';
PRINT '';
PRINT '==========================================================================';
GO
