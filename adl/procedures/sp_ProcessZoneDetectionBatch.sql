-- ============================================================================
-- sp_ProcessZoneDetectionBatch.sql
-- Batch processes zone detection for all active flights near airports
--
-- V2.1 - Added actual time fields:
--   - Now sets atd_utc when RUNWAY→AIRBORNE (departure)
--   - Now sets ata_runway_utc when AIRBORNE→RUNWAY (arrival)
--   - These fields are used for ETA accuracy analysis
--
-- V2.0 - Performance optimized:
--   - Single JOIN instead of 3 correlated subqueries
--   - STIntersects with buffer instead of multiple STDistance calls
--   - ROW_NUMBER ranking for best zone match
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
        CASE WHEN ft.off_utc IS NOT NULL THEN 1 ELSE 0 END AS has_departed,
        -- Pre-compute geography point ONCE per flight
        geography::Point(p.lat, p.lon, 4326) AS flight_point
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

    -- Filter to only flights with valid airports to check
    DELETE FROM #flights_to_check WHERE check_airport IS NULL;

    -- Add index for efficient airport lookup
    CREATE INDEX IX_flights_airport ON #flights_to_check (check_airport);

    -- ========================================================================
    -- Step 2: Detect zones using a single efficient JOIN
    -- ========================================================================
    -- Uses STIntersects with buffered point (100m) for spatial index usage
    -- Then ROW_NUMBER to pick best matching zone

    -- First calculate distances for all matching zones
    ;WITH zone_distances AS (
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
            ag.zone_type,
            ag.zone_name,
            -- Calculate distance ONCE for each matching zone
            -- Use MakeValid() to handle potentially invalid geometry from STDifference operations
            f.flight_point.STDistance(ag.geometry.MakeValid()) AS distance_m,
            -- Zone priority for ranking
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
        FROM #flights_to_check f
        JOIN dbo.airport_geometry ag
            ON ag.airport_icao = f.check_airport
            AND ag.is_active = 1
            -- Use STIntersects with buffer for spatial index efficiency
            -- Use MakeValid() to handle potentially invalid geometry from STDifference operations
            AND ag.geometry.MakeValid().STIntersects(f.flight_point.STBuffer(100)) = 1
    ),
    -- Then rank using pre-calculated values
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
        -- Use OSM zone if found, otherwise fallback to speed-based
        COALESCE(
            bz.zone_type,
            -- Fallback: speed-based
            CASE
                WHEN f.altitude_ft - ISNULL((SELECT CAST(ELEV AS INT) FROM dbo.apts WHERE ICAO_ID = f.check_airport), 0) > 500 THEN 'AIRBORNE'
                WHEN f.groundspeed_kts < 5 THEN 'PARKING'
                WHEN f.groundspeed_kts BETWEEN 5 AND 35 THEN 'TAXIWAY'
                WHEN f.groundspeed_kts > 35 THEN 'RUNWAY'
                ELSE 'AIRBORNE'
            END
        ) AS current_zone,
        bz.zone_name,
        bz.distance_m
    INTO #zone_detections
    FROM #flights_to_check f
    LEFT JOIN best_zones bz ON bz.flight_uid = f.flight_uid;

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

        -- Also set actual departure time for analytics/ETA accuracy
        atd_utc = CASE
            WHEN z.prev_zone = 'RUNWAY' AND z.current_zone = 'AIRBORNE' AND ft.atd_utc IS NULL
            THEN @now ELSE ft.atd_utc END,

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

        -- Also set actual runway arrival time for analytics/ETA accuracy
        ata_runway_utc = CASE
            WHEN z.prev_zone = 'AIRBORNE' AND z.current_zone = 'RUNWAY' AND ft.ata_runway_utc IS NULL
            THEN @now ELSE ft.ata_runway_utc END,

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

PRINT 'Created stored procedure dbo.sp_ProcessZoneDetectionBatch (V2.1 - Added atd_utc/ata_runway_utc)';
GO
