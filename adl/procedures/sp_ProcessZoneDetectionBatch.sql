-- ============================================================================
-- sp_ProcessZoneDetectionBatch.sql
-- Batch processes zone detection for all active flights near airports
-- 
-- Efficiently checks zone transitions for multiple flights
-- Called from the main refresh procedure
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

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
    -- Step 1: Identify flights that need zone checking
    -- ========================================================================
    -- Only check flights that are:
    -- 1. Near their departure airport (before takeoff)
    -- 2. Near their destination airport (arriving)
    -- 3. Have OSM geometry available for those airports
    
    SELECT 
        c.flight_uid,
        p.lat,
        p.lon,
        p.altitude_ft,
        p.groundspeed_kts,
        p.heading_deg,
        p.vertical_rate_fpm,
        c.current_zone AS prev_zone,
        c.current_zone_airport AS prev_zone_airport,
        -- Determine which airport to check
        CASE 
            WHEN ft.off_utc IS NULL THEN fp.fp_dept_icao  -- Pre-departure
            WHEN ISNULL(p.pct_complete, 0) > 80 THEN fp.fp_dest_icao  -- Arriving
            ELSE NULL  -- En route
        END AS check_airport,
        CASE WHEN ft.off_utc IS NOT NULL THEN 1 ELSE 0 END AS has_departed
    INTO #flights_to_check
    FROM dbo.adl_flight_core c
    JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
    WHERE c.is_active = 1
      AND p.lat IS NOT NULL
      -- Only check if near airport (not en route)
      AND (
          ft.off_utc IS NULL  -- Pre-departure
          OR ISNULL(p.pct_complete, 0) > 80  -- Arriving
      );
    
    -- ========================================================================
    -- Step 2: Detect zones using OSM geometry (batch)
    -- ========================================================================
    
    SELECT 
        f.flight_uid,
        f.lat,
        f.lon,
        f.altitude_ft,
        f.groundspeed_kts,
        f.heading_deg,
        f.vertical_rate_fpm,
        f.prev_zone,
        f.check_airport,
        f.has_departed,
        -- Zone detection result
        COALESCE(
            (SELECT TOP 1 ag.zone_type
             FROM dbo.airport_geometry ag
             WHERE ag.airport_icao = f.check_airport
               AND ag.is_active = 1
               AND ag.geometry.STDistance(geography::Point(f.lat, f.lon, 4326)) < 100
             ORDER BY 
                 CASE ag.zone_type
                     WHEN 'PARKING' THEN 1
                     WHEN 'GATE' THEN 2
                     WHEN 'HOLD' THEN 3
                     WHEN 'RUNWAY' THEN 4
                     WHEN 'TAXILANE' THEN 5
                     WHEN 'TAXIWAY' THEN 6
                     WHEN 'APRON' THEN 7
                     ELSE 99
                 END,
                 ag.geometry.STDistance(geography::Point(f.lat, f.lon, 4326))),
            -- Fallback: speed-based
            CASE 
                WHEN f.altitude_ft - ISNULL((SELECT CAST(ELEV AS INT) FROM dbo.apts WHERE ICAO_ID = f.check_airport), 0) > 500 THEN 'AIRBORNE'
                WHEN f.groundspeed_kts < 5 THEN 'PARKING'
                WHEN f.groundspeed_kts BETWEEN 5 AND 35 THEN 'TAXIWAY'
                WHEN f.groundspeed_kts > 35 THEN 'RUNWAY'
                ELSE 'AIRBORNE'
            END
        ) AS current_zone,
        -- Zone name (if available)
        (SELECT TOP 1 ag.zone_name
         FROM dbo.airport_geometry ag
         WHERE ag.airport_icao = f.check_airport
           AND ag.is_active = 1
           AND ag.geometry.STDistance(geography::Point(f.lat, f.lon, 4326)) < 100
         ORDER BY 
             CASE ag.zone_type
                 WHEN 'PARKING' THEN 1
                 WHEN 'GATE' THEN 2
                 WHEN 'HOLD' THEN 3
                 WHEN 'RUNWAY' THEN 4
                 WHEN 'TAXILANE' THEN 5
                 WHEN 'TAXIWAY' THEN 6
                 WHEN 'APRON' THEN 7
                 ELSE 99
             END,
             ag.geometry.STDistance(geography::Point(f.lat, f.lon, 4326))) AS zone_name,
        -- Distance to zone
        (SELECT TOP 1 ag.geometry.STDistance(geography::Point(f.lat, f.lon, 4326))
         FROM dbo.airport_geometry ag
         WHERE ag.airport_icao = f.check_airport
           AND ag.is_active = 1
           AND ag.geometry.STDistance(geography::Point(f.lat, f.lon, 4326)) < 100
         ORDER BY ag.geometry.STDistance(geography::Point(f.lat, f.lon, 4326))) AS distance_m
    INTO #zone_detections
    FROM #flights_to_check f
    WHERE f.check_airport IS NOT NULL;
    
    -- ========================================================================
    -- Step 3: Log zone transitions
    -- ========================================================================
    
    INSERT INTO dbo.adl_zone_events (
        flight_uid, event_utc, event_type,
        airport_icao, from_zone, to_zone, zone_name,
        lat, lon, altitude_ft, groundspeed_kts, heading_deg, vertical_rate_fpm,
        detection_method, distance_to_zone_m, confidence
    )
    SELECT 
        z.flight_uid,
        @now,
        'TRANSITION',
        z.check_airport,
        z.prev_zone,
        z.current_zone,
        z.zone_name,
        z.lat,
        z.lon,
        z.altitude_ft,
        z.groundspeed_kts,
        z.heading_deg,
        z.vertical_rate_fpm,
        CASE WHEN z.distance_m IS NOT NULL THEN 'OSM_GEOMETRY' ELSE 'SPEED_FALLBACK' END,
        z.distance_m,
        CASE 
            WHEN z.distance_m < 10 THEN 0.99
            WHEN z.distance_m < 30 THEN 0.90
            WHEN z.distance_m < 60 THEN 0.75
            WHEN z.distance_m IS NOT NULL THEN 0.50
            ELSE 0.60
        END
    FROM #zone_detections z
    WHERE z.current_zone != ISNULL(z.prev_zone, '');
    
    SET @transitions_detected = @@ROWCOUNT;
    
    -- ========================================================================
    -- Step 4: Update flight core with current zones
    -- ========================================================================
    
    UPDATE c
    SET c.current_zone = z.current_zone,
        c.current_zone_airport = z.check_airport,
        c.last_zone_check_utc = @now
    FROM dbo.adl_flight_core c
    JOIN #zone_detections z ON z.flight_uid = c.flight_uid;
    
    -- ========================================================================
    -- Step 5: Update OOOI times for transitions
    -- ========================================================================
    
    -- DEPARTURE transitions (not yet departed)
    UPDATE ft
    SET
        out_utc = CASE 
            WHEN z.prev_zone = 'PARKING' AND z.current_zone NOT IN ('PARKING', 'GATE') AND ft.out_utc IS NULL
            THEN @now ELSE ft.out_utc END,
        
        parking_left_utc = CASE 
            WHEN z.prev_zone = 'PARKING' AND z.current_zone != 'PARKING' AND ft.parking_left_utc IS NULL
            THEN @now ELSE ft.parking_left_utc END,
        
        taxiway_entered_utc = CASE
            WHEN z.prev_zone IN ('PARKING', 'APRON', 'TAXILANE') AND z.current_zone = 'TAXIWAY' AND ft.taxiway_entered_utc IS NULL
            THEN @now ELSE ft.taxiway_entered_utc END,
        
        hold_entered_utc = CASE
            WHEN z.prev_zone = 'TAXIWAY' AND z.current_zone = 'HOLD' AND ft.hold_entered_utc IS NULL
            THEN @now ELSE ft.hold_entered_utc END,
        
        runway_entered_utc = CASE
            WHEN z.prev_zone IN ('TAXIWAY', 'HOLD') AND z.current_zone = 'RUNWAY' AND ft.runway_entered_utc IS NULL
            THEN @now ELSE ft.runway_entered_utc END,
        
        off_utc = CASE
            WHEN z.prev_zone = 'RUNWAY' AND z.current_zone = 'AIRBORNE' AND ft.off_utc IS NULL
            THEN @now ELSE ft.off_utc END,
        
        takeoff_roll_utc = CASE
            WHEN z.current_zone = 'RUNWAY' AND z.groundspeed_kts > 40 AND ft.takeoff_roll_utc IS NULL
            THEN @now ELSE ft.takeoff_roll_utc END,
        
        rotation_utc = CASE
            WHEN z.prev_zone = 'RUNWAY' AND z.current_zone = 'AIRBORNE' AND ft.rotation_utc IS NULL
            THEN @now ELSE ft.rotation_utc END,
        
        times_updated_utc = @now
    FROM dbo.adl_flight_times ft
    JOIN #zone_detections z ON z.flight_uid = ft.flight_uid
    WHERE z.has_departed = 0
      AND z.current_zone != ISNULL(z.prev_zone, '');
    
    -- ARRIVAL transitions (already departed)
    UPDATE ft
    SET
        on_utc = CASE
            WHEN z.prev_zone = 'AIRBORNE' AND z.current_zone = 'RUNWAY' AND ft.on_utc IS NULL
            THEN @now ELSE ft.on_utc END,
        
        touchdown_utc = CASE
            WHEN z.prev_zone = 'AIRBORNE' AND z.current_zone = 'RUNWAY' AND ft.touchdown_utc IS NULL
            THEN @now ELSE ft.touchdown_utc END,
        
        rollout_end_utc = CASE
            WHEN z.prev_zone = 'RUNWAY' AND z.current_zone IN ('TAXIWAY', 'TAXILANE') AND ft.rollout_end_utc IS NULL
            THEN @now ELSE ft.rollout_end_utc END,
        
        taxiway_arr_utc = CASE
            WHEN z.prev_zone = 'RUNWAY' AND z.current_zone IN ('TAXIWAY', 'TAXILANE') AND ft.taxiway_arr_utc IS NULL
            THEN @now ELSE ft.taxiway_arr_utc END,
        
        parking_entered_utc = CASE
            WHEN z.prev_zone IN ('APRON', 'TAXILANE', 'TAXIWAY') AND z.current_zone = 'PARKING' AND ft.parking_entered_utc IS NULL
            THEN @now ELSE ft.parking_entered_utc END,
        
        in_utc = CASE
            WHEN z.prev_zone IN ('APRON', 'TAXILANE', 'TAXIWAY') AND z.current_zone = 'PARKING' AND ft.in_utc IS NULL
            THEN @now ELSE ft.in_utc END,
        
        times_updated_utc = @now
    FROM dbo.adl_flight_times ft
    JOIN #zone_detections z ON z.flight_uid = ft.flight_uid
    WHERE z.has_departed = 1
      AND z.current_zone != ISNULL(z.prev_zone, '');
    
    -- Cleanup
    DROP TABLE IF EXISTS #flights_to_check;
    DROP TABLE IF EXISTS #zone_detections;
    
END
GO

PRINT 'Created stored procedure dbo.sp_ProcessZoneDetectionBatch';
GO
