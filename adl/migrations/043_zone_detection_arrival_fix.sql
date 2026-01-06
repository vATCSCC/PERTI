-- ============================================================================
-- PATCH: sp_ProcessZoneDetectionBatch V2 - Arrival Detection Fix
-- 
-- Fixes:
-- 1. Arrival OOOI times not being set for flights already airborne
-- 2. Lowered arrival threshold from 80% to 50% complete
-- 3. on_utc now triggers on AIRBORNE → RUNWAY or AIRBORNE → TAXIWAY
-- 4. in_utc requires on_utc IS NOT NULL (must have landed first)
--
-- Date: 2026-01-06
-- ============================================================================

ALTER PROCEDURE dbo.sp_ProcessZoneDetectionBatch 
    @transitions_detected INT = NULL OUTPUT 
AS 
BEGIN 
    SET NOCOUNT ON; 
    DECLARE @now DATETIME2(0) = SYSUTCDATETIME(); 
    SET @transitions_detected = 0; 
     
    -- ========================================================================
    -- Step 1: Identify flights near airports that need zone detection
    -- ========================================================================
    SELECT 
        c.flight_uid, 
        p.lat, 
        p.lon, 
        p.altitude_ft, 
        p.groundspeed_kts, 
        p.heading_deg, 
        p.vertical_rate_fpm, 
        c.current_zone AS prev_zone, 
        c.current_zone_airport AS prev_airport, 
        -- Determine which airport to check based on flight phase
        CASE 
            WHEN ft.off_utc IS NULL AND ISNULL(p.pct_complete, 0) < 50 THEN fp.fp_dept_icao  -- Departing
            WHEN ISNULL(p.pct_complete, 0) >= 50 THEN fp.fp_dest_icao  -- Arriving
            ELSE fp.fp_dept_icao 
        END AS check_airport, 
        -- Determine if flight is in arrival phase (V2 fix: use pct_complete)
        CASE 
            WHEN ISNULL(p.pct_complete, 0) >= 50 THEN 1  -- Treat as "in arrival phase"
            WHEN ft.off_utc IS NOT NULL THEN 1 
            ELSE 0 
        END AS is_arriving
    INTO #flights 
    FROM dbo.adl_flight_core c 
    JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid 
    JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid 
    LEFT JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid 
    WHERE c.is_active = 1 
      AND p.lat IS NOT NULL 
      AND (ft.off_utc IS NULL OR ISNULL(p.pct_complete, 0) >= 50); 
    
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
    -- Step 3: Log zone transitions
    -- ========================================================================
    INSERT INTO dbo.adl_zone_events (
        flight_uid, event_utc, event_type, airport_icao, 
        from_zone, to_zone, lat, lon, altitude_ft, 
        groundspeed_kts, heading_deg, vertical_rate_fpm, 
        detection_method, confidence
    ) 
    SELECT 
        flight_uid, @now, 'TRANSITION', check_airport, 
        prev_zone, current_zone, lat, lon, altitude_ft, 
        groundspeed_kts, heading_deg, vertical_rate_fpm, 
        'BATCH', 0.75 
    FROM #detections 
    WHERE current_zone != ISNULL(prev_zone, ''); 
    
    SET @transitions_detected = @@ROWCOUNT; 
    
    -- ========================================================================
    -- Step 4: Update current zone on flight core
    -- ========================================================================
    UPDATE c 
    SET c.current_zone = d.current_zone, 
        c.current_zone_airport = d.check_airport, 
        c.last_zone_check_utc = @now 
    FROM dbo.adl_flight_core c 
    JOIN #detections d ON d.flight_uid = c.flight_uid; 
    
    -- ========================================================================
    -- Step 5: Update OOOI times for DEPARTURES (is_arriving = 0)
    -- ========================================================================
    UPDATE ft SET 
        -- OUT: Left parking/gate
        out_utc = CASE 
            WHEN d.prev_zone IN ('PARKING','GATE') 
             AND d.current_zone NOT IN ('PARKING','GATE') 
             AND ft.out_utc IS NULL 
            THEN @now 
            ELSE ft.out_utc 
        END, 
        -- OFF: Wheels up (runway to airborne)
        off_utc = CASE 
            WHEN d.prev_zone = 'RUNWAY' 
             AND d.current_zone = 'AIRBORNE' 
             AND ft.off_utc IS NULL 
            THEN @now 
            ELSE ft.off_utc 
        END 
    FROM dbo.adl_flight_times ft 
    JOIN #detections d ON d.flight_uid = ft.flight_uid 
    WHERE d.is_arriving = 0 
      AND d.current_zone != ISNULL(d.prev_zone, ''); 
    
    -- ========================================================================
    -- Step 6: Update OOOI times for ARRIVALS (is_arriving = 1)
    -- ========================================================================
    UPDATE ft SET 
        -- ON: Touchdown (airborne to runway/taxiway - sometimes runway zone missed)
        on_utc = CASE 
            WHEN d.prev_zone = 'AIRBORNE' 
             AND d.current_zone IN ('RUNWAY','TAXIWAY') 
             AND ft.on_utc IS NULL 
            THEN @now 
            ELSE ft.on_utc 
        END, 
        -- IN: At gate (must have landed first)
        in_utc = CASE 
            WHEN d.prev_zone IN ('TAXIWAY','APRON','GATE') 
             AND d.current_zone = 'PARKING' 
             AND ft.on_utc IS NOT NULL  -- Must have ON time first
             AND ft.in_utc IS NULL 
            THEN @now 
            ELSE ft.in_utc 
        END 
    FROM dbo.adl_flight_times ft 
    JOIN #detections d ON d.flight_uid = ft.flight_uid 
    WHERE d.is_arriving = 1 
      AND d.current_zone != ISNULL(d.prev_zone, ''); 
    
    -- ========================================================================
    -- Cleanup
    -- ========================================================================
    DROP TABLE #flights; 
    DROP TABLE #detections; 
END
GO

PRINT '============================================================================';
PRINT 'sp_ProcessZoneDetectionBatch V2 - Arrival Detection Fix';
PRINT '============================================================================';
PRINT '';
PRINT 'Changes:';
PRINT '  - is_arriving now uses pct_complete >= 50% (was only off_utc check)';
PRINT '  - on_utc triggers on AIRBORNE -> RUNWAY or TAXIWAY';
PRINT '  - in_utc requires on_utc IS NOT NULL';
PRINT '  - Arrival threshold lowered from 80% to 50%';
PRINT '============================================================================';
GO
