-- ============================================================================
-- sp_ProcessZoneDetectionBatch_Tiered.sql V1.0
-- Tiered zone detection - only processes flights matching the specified tier
--
-- Tier System (15s VATSIM minimum):
--   Tier 0: Active runway (GS > 30, on RWY) - Every 15s (every cycle)
--   Tier 1: Taxiing (GS 5-30 kts) - Every 15s (every cycle)
--   Tier 2: Parked (GS < 5 kts) - Every 60s (4 cycles)
--   Tier 3: Arriving (>80% complete) - Every 15s (every cycle)
--   Tier 4: Prefile/no position - Every 120s (8 cycles)
--
-- Called by zone_daemon.php with @tier_mask parameter
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID('dbo.sp_ProcessZoneDetectionBatch_Tiered', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_ProcessZoneDetectionBatch_Tiered;
GO

CREATE PROCEDURE dbo.sp_ProcessZoneDetectionBatch_Tiered
    @tier_mask INT = 31,  -- Bitmask: 1=T0, 2=T1, 4=T2, 8=T3, 16=T4 (31=all)
    @transitions_detected INT = NULL OUTPUT,
    @flights_checked INT = NULL OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @now DATETIME2(0) = SYSUTCDATETIME();
    SET @transitions_detected = 0;
    SET @flights_checked = 0;

    -- ========================================================================
    -- Step 1: Identify flights by tier and filter by mask
    -- ========================================================================

    DROP TABLE IF EXISTS #flights_to_check;

    SELECT
        c.flight_uid,
        p.lat,
        p.lon,
        p.altitude_ft,
        p.groundspeed_kts,
        p.heading_deg,
        p.vertical_rate_fpm,
        p.pct_complete,
        c.current_zone AS prev_zone,
        c.current_zone_airport AS prev_zone_airport,
        -- Determine which airport to check
        CASE
            WHEN ft.off_utc IS NULL THEN fp.fp_dept_icao  -- Pre-departure
            WHEN ISNULL(p.pct_complete, 0) > 80 THEN fp.fp_dest_icao  -- Arriving
            ELSE NULL  -- En route (skip)
        END AS check_airport,
        CASE WHEN ft.off_utc IS NOT NULL THEN 1 ELSE 0 END AS has_departed,
        -- Assign tier
        CASE
            -- Tier 0: Active runway (high speed on ground)
            WHEN p.groundspeed_kts > 30 AND c.current_zone = 'RUNWAY' THEN 0
            -- Tier 1: Taxiing
            WHEN p.groundspeed_kts BETWEEN 5 AND 30 THEN 1
            -- Tier 2: Parked/stationary
            WHEN p.groundspeed_kts < 5 AND p.lat IS NOT NULL THEN 2
            -- Tier 3: Arriving (descending or in approach zone)
            WHEN ISNULL(p.pct_complete, 0) > 80 THEN 3
            -- Tier 4: Prefile or no position
            WHEN p.lat IS NULL THEN 4
            ELSE 2  -- Default to parked tier
        END AS zone_tier,
        -- Get airport reference coordinates for bounding box filter
        a.LAT_DECIMAL AS apt_lat,
        a.LONG_DECIMAL AS apt_lon
    INTO #flights_to_check
    FROM dbo.adl_flight_core c
    LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
    LEFT JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
    LEFT JOIN dbo.apts a ON a.ICAO_ID = CASE
        WHEN ft.off_utc IS NULL THEN fp.fp_dept_icao
        WHEN ISNULL(p.pct_complete, 0) > 80 THEN fp.fp_dest_icao
        ELSE NULL
    END
    WHERE c.is_active = 1
      AND (
          ft.off_utc IS NULL  -- Pre-departure
          OR ISNULL(p.pct_complete, 0) > 80  -- Arriving
      );

    -- Remove flights without valid airport to check
    DELETE FROM #flights_to_check WHERE check_airport IS NULL;

    -- Filter by tier mask (bitmask check)
    DELETE FROM #flights_to_check
    WHERE (@tier_mask & POWER(2, zone_tier)) = 0;

    -- Bounding box pre-filter: remove flights > ~12nm from airport
    DELETE FROM #flights_to_check
    WHERE apt_lat IS NOT NULL
      AND (
          ABS(lat - apt_lat) > 0.2
          OR ABS(lon - apt_lon) > 0.25
      );

    -- Skip flights that haven't moved since last check (for parked tier)
    DELETE f
    FROM #flights_to_check f
    JOIN dbo.adl_flight_core c ON c.flight_uid = f.flight_uid
    WHERE f.zone_tier = 2  -- Only apply to parked tier
      AND c.last_zone_check_utc IS NOT NULL
      AND DATEDIFF(SECOND, c.last_zone_check_utc, @now) < 55  -- Checked within 55 seconds
      AND f.groundspeed_kts < 3;  -- And barely moving

    SET @flights_checked = (SELECT COUNT(*) FROM #flights_to_check);

    -- If no flights to check, exit early
    IF @flights_checked = 0
    BEGIN
        RETURN;
    END

    -- Create index for efficient airport lookup
    CREATE INDEX IX_flights_airport ON #flights_to_check (check_airport);

    -- ========================================================================
    -- Step 2: Detect zones using direct STDistance (no STBuffer!)
    -- ========================================================================

    DROP TABLE IF EXISTS #flight_points;

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
        f.zone_tier,
        geography::Point(f.lat, f.lon, 4326) AS flight_point
    INTO #flight_points
    FROM #flights_to_check f
    WHERE f.lat IS NOT NULL;

    -- Find matching zones
    ;WITH zone_distances AS (
        SELECT
            fp.flight_uid,
            fp.lat,
            fp.lon,
            fp.altitude_ft,
            fp.groundspeed_kts,
            fp.heading_deg,
            fp.vertical_rate_fpm,
            fp.prev_zone,
            fp.check_airport,
            fp.has_departed,
            fp.zone_tier,
            ag.zone_type,
            ag.zone_name,
            fp.flight_point.STDistance(ag.geometry) AS distance_m,
            CASE ag.zone_type
                WHEN 'PARKING' THEN 1
                WHEN 'GATE' THEN 2
                WHEN 'HOLD' THEN 3
                WHEN 'RUNWAY' THEN 4
                WHEN 'TAXILANE' THEN 5
                WHEN 'TAXIWAY' THEN 6
                WHEN 'APRON' THEN 7
                ELSE 99
            END AS zone_priority
        FROM #flight_points fp
        JOIN dbo.airport_geometry ag
            ON ag.airport_icao = fp.check_airport
            AND ag.is_active = 1
            AND fp.flight_point.STDistance(ag.geometry) <= 100
    ),
    zone_ranked AS (
        SELECT *,
            ROW_NUMBER() OVER (
                PARTITION BY flight_uid
                ORDER BY zone_priority, distance_m
            ) AS zone_rank
        FROM zone_distances
    ),
    best_zones AS (
        SELECT * FROM zone_ranked WHERE zone_rank = 1
    )
    SELECT
        fp.flight_uid,
        fp.lat,
        fp.lon,
        fp.altitude_ft,
        fp.groundspeed_kts,
        fp.heading_deg,
        fp.vertical_rate_fpm,
        fp.prev_zone,
        fp.check_airport,
        fp.has_departed,
        fp.zone_tier,
        COALESCE(
            bz.zone_type,
            CASE
                WHEN fp.altitude_ft - ISNULL((SELECT CAST(ELEV AS INT) FROM dbo.apts WHERE ICAO_ID = fp.check_airport), 0) > 500 THEN 'AIRBORNE'
                WHEN fp.groundspeed_kts < 5 THEN 'PARKING'
                WHEN fp.groundspeed_kts BETWEEN 5 AND 35 THEN 'TAXIWAY'
                WHEN fp.groundspeed_kts > 35 THEN 'RUNWAY'
                ELSE 'AIRBORNE'
            END
        ) AS current_zone,
        bz.zone_name,
        bz.distance_m
    INTO #zone_detections
    FROM #flight_points fp
    LEFT JOIN best_zones bz ON bz.flight_uid = fp.flight_uid;

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

    -- DEPARTURE transitions
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

    -- ARRIVAL transitions
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
    DROP TABLE IF EXISTS #flight_points;
    DROP TABLE IF EXISTS #zone_detections;

END
GO

PRINT 'Created sp_ProcessZoneDetectionBatch_Tiered V1.0';
PRINT 'Tier system:';
PRINT '  Tier 0: Active runway (GS > 30) - mask bit 1';
PRINT '  Tier 1: Taxiing (GS 5-30) - mask bit 2';
PRINT '  Tier 2: Parked (GS < 5) - mask bit 4';
PRINT '  Tier 3: Arriving (>80%) - mask bit 8';
PRINT '  Tier 4: Prefile - mask bit 16';
PRINT 'Default @tier_mask = 31 (all tiers)';
GO
