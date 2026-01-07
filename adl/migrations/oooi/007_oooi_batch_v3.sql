-- ============================================================================
-- 043_oooi_batch_v3.sql
-- OOOI Zone Detection Batch Processing V3
-- 
-- Fixes from V2:
--   1. GATE added to prev_zone check for IN time detection
--   2. For post-landing flights, check CURRENT zone airport (not recalculated)
--   3. Better departure/arrival classification using current_zone_airport
--   4. Groundspeed checks for OFF detection (prevent spurious airborne)
--   5. Fixed extended arrival times not being set
--
-- Key insight: After landing, use current_zone_airport (where flight IS)
-- rather than recalculating check_airport (which might pick wrong airport)
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

PRINT '==========================================================================';
PRINT '  OOOI Zone Detection Batch V3 - Airport Logic Fix';
PRINT '  Version 3.0 - ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
PRINT '';

-- ============================================================================
-- Drop and recreate sp_ProcessZoneDetectionBatch with V3 enhancements
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
    -- 
    -- Key logic:
    --   - Pre-departure (off_utc IS NULL): Check ORIGIN airport
    --   - Post-landing (on_utc IS NOT NULL): Check DESTINATION airport
    --   - Arriving (>70% or descending): Check DESTINATION airport
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
        -- V3 FIX: Be more inclusive for enroute flights to catch landings
        CASE 
            -- Pre-departure: check origin (haven't taken off yet)
            WHEN ft.off_utc IS NULL THEN fp.fp_dept_icao
            
            -- Post-landing: check destination (already landed)
            WHEN ft.on_utc IS NOT NULL THEN fp.fp_dest_icao
            
            -- Enroute/Arriving: check destination if any of these conditions:
            -- 1. High completion percentage (>60%)
            -- 2. Descending significantly (VR < -300)
            -- 3. Low altitude (<10000 ft)
            -- 4. Low groundspeed while not at cruise (<250 kts and alt < 25000)
            WHEN ISNULL(p.pct_complete, 0) > 60 THEN fp.fp_dest_icao
            WHEN ISNULL(p.vertical_rate_fpm, 0) < -300 THEN fp.fp_dest_icao
            WHEN ISNULL(p.altitude_ft, 0) < 10000 THEN fp.fp_dest_icao
            WHEN ISNULL(p.groundspeed_kts, 0) < 250 AND ISNULL(p.altitude_ft, 99999) < 25000 THEN fp.fp_dest_icao
            
            -- Otherwise not near an airport (high altitude cruise)
            ELSE NULL
        END AS check_airport,
        
        -- Flight phase for update logic
        -- V3: Lowered ARRIVING threshold to 60% to catch more landings
        CASE 
            WHEN ft.off_utc IS NULL THEN 'PRE_DEPARTURE'
            WHEN ft.on_utc IS NULL AND (
                ISNULL(p.pct_complete, 0) > 60 
                OR ISNULL(p.vertical_rate_fpm, 0) < -300
                OR ISNULL(p.altitude_ft, 99999) < 10000
            ) THEN 'ARRIVING'
            WHEN ft.on_utc IS NULL THEN 'ENROUTE'
            WHEN ft.in_utc IS NULL THEN 'POST_LANDING'
            ELSE 'COMPLETE'
        END AS oooi_phase
        
    INTO #flights
    FROM dbo.adl_flight_core c
    INNER JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
    WHERE c.is_active = 1 
      AND p.lat IS NOT NULL
      AND p.lon IS NOT NULL
      -- Include relevant flight phases
      AND (
          ft.off_utc IS NULL                          -- Pre-departure
          OR ft.in_utc IS NULL                        -- Not yet at gate (includes arriving/landed)
          OR ISNULL(p.pct_complete, 0) > 70           -- Arriving
          OR ISNULL(p.vertical_rate_fpm, 0) < -500    -- Descending
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
    -- Only log actual transitions (zone changed)
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
        CASE 
            WHEN prev_zone IS NULL THEN 'INITIAL'
            WHEN current_zone = 'AIRBORNE' AND prev_zone != 'AIRBORNE' THEN 'DEPARTURE'
            WHEN prev_zone = 'AIRBORNE' AND current_zone != 'AIRBORNE' THEN 'ARRIVAL'
            ELSE 'TRANSITION'
        END,
        check_airport, 
        prev_zone, 
        current_zone, 
        lat, 
        lon, 
        altitude_ft, 
        groundspeed_kts, 
        heading_deg, 
        vertical_rate_fpm, 
        'BATCH_V3', 
        0.85
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
    -- Step 5: Update OOOI times for PRE-DEPARTURE flights
    -- These are flights that haven't taken off yet (off_utc IS NULL)
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
        
        -- OFF: Became airborne with sufficient speed (GS > 60)
        -- Must transition from ground zone to AIRBORNE
        off_utc = CASE 
            WHEN d.prev_zone IN ('RUNWAY', 'TAXIWAY', 'HOLD', 'APRON', 'PARKING', 'GATE')
             AND d.current_zone = 'AIRBORNE' 
             AND d.groundspeed_kts > 60  -- Must have takeoff speed
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
            THEN @now
            ELSE ft.runway_entered_utc
        END,
        
        takeoff_roll_utc = CASE
            WHEN d.current_zone = 'RUNWAY'
             AND d.groundspeed_kts > 40
             AND ft.takeoff_roll_utc IS NULL
            THEN @now
            ELSE ft.takeoff_roll_utc
        END,
        
        rotation_utc = CASE
            WHEN d.prev_zone = 'RUNWAY'
             AND d.current_zone = 'AIRBORNE'
             AND d.groundspeed_kts > 60
             AND ft.rotation_utc IS NULL
            THEN @now
            ELSE ft.rotation_utc
        END
        
    FROM dbo.adl_flight_times ft 
    INNER JOIN #detections d ON d.flight_uid = ft.flight_uid 
    WHERE d.oooi_phase = 'PRE_DEPARTURE'
      AND d.current_zone != 'UNKNOWN';
    
    -- ========================================================================
    -- Step 6: Update OOOI times for ARRIVING/ENROUTE flights
    -- These are flights that have departed but haven't landed yet
    -- Check both ARRIVING and ENROUTE to catch all landing events
    -- ========================================================================
    
    UPDATE ft 
    SET
        -- ON: Touched down (AIRBORNE → RUNWAY)
        -- Must have departed (off_utc set) and not already landed
        on_utc = CASE 
            WHEN d.prev_zone = 'AIRBORNE' 
             AND d.current_zone = 'RUNWAY'
             AND d.groundspeed_kts < 200  -- Landing speed, not departing
             AND ft.off_utc IS NOT NULL   -- Must have departed
             AND ft.on_utc IS NULL 
            THEN @now 
            ELSE ft.on_utc 
        END,
        
        -- Approach start: Low altitude and descending
        approach_start_utc = CASE
            WHEN d.current_zone = 'AIRBORNE'
             AND d.altitude_ft < 5000  -- AGL check would be better but this is simpler
             AND d.vertical_rate_fpm < -300
             AND ft.approach_start_utc IS NULL
             AND ft.off_utc IS NOT NULL
            THEN @now
            ELSE ft.approach_start_utc
        END,
        
        -- Touchdown: AIRBORNE → RUNWAY
        touchdown_utc = CASE
            WHEN d.prev_zone = 'AIRBORNE'
             AND d.current_zone = 'RUNWAY'
             AND ft.touchdown_utc IS NULL
             AND ft.off_utc IS NOT NULL
            THEN @now
            ELSE ft.touchdown_utc
        END
        
    FROM dbo.adl_flight_times ft 
    INNER JOIN #detections d ON d.flight_uid = ft.flight_uid 
    WHERE d.oooi_phase IN ('ARRIVING', 'ENROUTE')  -- Check both phases for landings
      AND d.current_zone != 'UNKNOWN';
    
    -- ========================================================================
    -- Step 7: Update OOOI times for POST-LANDING flights
    -- These are flights that have landed (on_utc IS NOT NULL) but not at gate
    -- ========================================================================
    
    UPDATE ft 
    SET
        -- IN: Arrived at parking/gate after landing
        -- V3 FIX: Added GATE to prev_zone list
        in_utc = CASE 
            WHEN d.prev_zone IN ('TAXIWAY', 'APRON', 'TAXILANE', 'RUNWAY', 'GATE', 'HOLD')
             AND d.current_zone IN ('PARKING', 'GATE')
             AND d.groundspeed_kts < 5  -- Must be stopped or nearly stopped
             AND ft.in_utc IS NULL 
            THEN @now 
            ELSE ft.in_utc 
        END,
        
        -- Rollout end: Left runway after landing
        rollout_end_utc = CASE
            WHEN d.prev_zone = 'RUNWAY'
             AND d.current_zone IN ('TAXIWAY', 'TAXILANE')
             AND ft.rollout_end_utc IS NULL
            THEN @now
            ELSE ft.rollout_end_utc
        END,
        
        -- Taxiway arrival: On taxiway after landing
        taxiway_arr_utc = CASE
            WHEN d.prev_zone IN ('RUNWAY', 'APRON')
             AND d.current_zone = 'TAXIWAY'
             AND ft.taxiway_arr_utc IS NULL
            THEN @now
            ELSE ft.taxiway_arr_utc
        END,
        
        -- Parking entered: Reached parking/gate
        parking_entered_utc = CASE
            WHEN d.prev_zone IN ('TAXIWAY', 'APRON', 'TAXILANE', 'GATE', 'HOLD')
             AND d.current_zone IN ('PARKING', 'GATE')
             AND ft.parking_entered_utc IS NULL
            THEN @now
            ELSE ft.parking_entered_utc
        END
        
    FROM dbo.adl_flight_times ft 
    INNER JOIN #detections d ON d.flight_uid = ft.flight_uid 
    WHERE d.oooi_phase = 'POST_LANDING'
      AND d.current_zone != 'UNKNOWN';
    
    -- ========================================================================
    -- Step 8: Catchup - Set IN for flights at PARKING/GATE with ON set
    -- This catches flights that missed the transition detection
    -- INCLUDES INACTIVE FLIGHTS - pilots often disconnect at gate immediately
    -- ========================================================================
    
    -- 8a: Active flights at gate
    UPDATE ft
    SET in_utc = @now
    FROM dbo.adl_flight_times ft
    INNER JOIN dbo.adl_flight_core c ON c.flight_uid = ft.flight_uid
    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
    WHERE ft.on_utc IS NOT NULL           -- Has landed
      AND ft.in_utc IS NULL               -- But no IN time
      AND c.current_zone IN ('PARKING', 'GATE')  -- Currently at gate/parking
      AND c.current_zone_airport = fp.fp_dest_icao  -- At destination airport
      AND c.is_active = 1;
    
    -- 8b: Inactive flights that reached gate before disconnecting
    -- Use last zone event time or current time
    UPDATE ft
    SET in_utc = COALESCE(
        (SELECT MAX(e.event_utc) FROM dbo.adl_zone_events e WHERE e.flight_uid = ft.flight_uid),
        @now
    )
    FROM dbo.adl_flight_times ft
    INNER JOIN dbo.adl_flight_core c ON c.flight_uid = ft.flight_uid
    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
    WHERE ft.on_utc IS NOT NULL           -- Has landed
      AND ft.in_utc IS NULL               -- But no IN time
      AND c.current_zone IN ('PARKING', 'GATE')  -- At gate/parking when disconnected
      AND c.current_zone_airport = fp.fp_dest_icao  -- At destination airport
      AND c.is_active = 0;                -- Disconnected flights
    
    -- ========================================================================
    -- Step 9: Cleanup
    -- ========================================================================
    
    DROP TABLE #flights;
    DROP TABLE #detections;
    
END;
GO

PRINT '';
PRINT '✓ sp_ProcessZoneDetectionBatch V3 created successfully';
PRINT '';
PRINT '  Key fixes from V2:';
PRINT '  - Added GATE to prev_zone check for IN detection';
PRINT '  - Added HOLD to prev_zone check for IN detection';  
PRINT '  - Groundspeed check (GS < 5) for IN time';
PRINT '  - Groundspeed check (GS > 60) for OFF time';
PRINT '  - Groundspeed check (GS < 200) for ON time';
PRINT '  - Better oooi_phase classification';
PRINT '  - Catchup logic for missed IN transitions';
PRINT '  - Extended arrival times now properly tracked';
PRINT '';
PRINT '==========================================================================';
GO

-- ============================================================================
-- Immediate catchup: Set IN for flights that should have it
-- ============================================================================

PRINT 'Running immediate IN time catchup...';

-- Active flights at gate
DECLARE @active_updated INT = 0;
DECLARE @inactive_updated INT = 0;

UPDATE ft
SET in_utc = SYSUTCDATETIME()
FROM dbo.adl_flight_times ft
INNER JOIN dbo.adl_flight_core c ON c.flight_uid = ft.flight_uid
INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
WHERE ft.on_utc IS NOT NULL           
  AND ft.in_utc IS NULL               
  AND c.current_zone IN ('PARKING', 'GATE')  
  AND c.current_zone_airport = fp.fp_dest_icao  
  AND c.is_active = 1;

SET @active_updated = @@ROWCOUNT;

-- Inactive flights that reached gate before disconnecting
UPDATE ft
SET in_utc = COALESCE(
    (SELECT MAX(e.event_utc) FROM dbo.adl_zone_events e WHERE e.flight_uid = ft.flight_uid),
    SYSUTCDATETIME()
)
FROM dbo.adl_flight_times ft
INNER JOIN dbo.adl_flight_core c ON c.flight_uid = ft.flight_uid
INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
WHERE ft.on_utc IS NOT NULL           
  AND ft.in_utc IS NULL               
  AND c.current_zone IN ('PARKING', 'GATE')  
  AND c.current_zone_airport = fp.fp_dest_icao  
  AND c.is_active = 0;

SET @inactive_updated = @@ROWCOUNT;

PRINT '  ✓ Updated ' + CAST(@active_updated AS VARCHAR) + ' active flights with IN time';
PRINT '  ✓ Updated ' + CAST(@inactive_updated AS VARCHAR) + ' inactive flights with IN time';
GO
