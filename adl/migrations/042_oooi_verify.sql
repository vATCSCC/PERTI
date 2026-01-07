-- ============================================================================
-- OOOI Zone Detection V2 - Verification Queries
-- Run these after deploying 042_oooi_batch_v2.sql
-- ============================================================================

-- ============================================================================
-- 1. OOOI Summary Stats
-- ============================================================================
SELECT
    COUNT(*) AS total_active_flights,
    SUM(CASE WHEN ft.out_utc IS NOT NULL THEN 1 ELSE 0 END) AS with_out,
    SUM(CASE WHEN ft.off_utc IS NOT NULL THEN 1 ELSE 0 END) AS with_off,
    SUM(CASE WHEN ft.on_utc IS NOT NULL THEN 1 ELSE 0 END) AS with_on,
    SUM(CASE WHEN ft.in_utc IS NOT NULL THEN 1 ELSE 0 END) AS with_in,
    -- Complete OOOI cycle
    SUM(CASE WHEN ft.out_utc IS NOT NULL AND ft.off_utc IS NOT NULL 
             AND ft.on_utc IS NOT NULL AND ft.in_utc IS NOT NULL THEN 1 ELSE 0 END) AS complete_oooi
FROM dbo.adl_flight_core c
LEFT JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
WHERE c.is_active = 1;

-- ============================================================================
-- 2. Extended Zone Times Coverage
-- ============================================================================
SELECT
    'Departure Times' AS category,
    SUM(CASE WHEN ft.parking_left_utc IS NOT NULL THEN 1 ELSE 0 END) AS parking_left,
    SUM(CASE WHEN ft.taxiway_entered_utc IS NOT NULL THEN 1 ELSE 0 END) AS taxiway_entered,
    SUM(CASE WHEN ft.hold_entered_utc IS NOT NULL THEN 1 ELSE 0 END) AS hold_entered,
    SUM(CASE WHEN ft.runway_entered_utc IS NOT NULL THEN 1 ELSE 0 END) AS runway_entered,
    SUM(CASE WHEN ft.takeoff_roll_utc IS NOT NULL THEN 1 ELSE 0 END) AS takeoff_roll,
    SUM(CASE WHEN ft.rotation_utc IS NOT NULL THEN 1 ELSE 0 END) AS rotation
FROM dbo.adl_flight_core c
LEFT JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
WHERE c.is_active = 1

UNION ALL

SELECT
    'Arrival Times' AS category,
    SUM(CASE WHEN ft.approach_start_utc IS NOT NULL THEN 1 ELSE 0 END) AS approach_start,
    SUM(CASE WHEN ft.touchdown_utc IS NOT NULL THEN 1 ELSE 0 END) AS touchdown,
    SUM(CASE WHEN ft.rollout_end_utc IS NOT NULL THEN 1 ELSE 0 END) AS rollout_end,
    SUM(CASE WHEN ft.taxiway_arr_utc IS NOT NULL THEN 1 ELSE 0 END) AS taxiway_arr,
    SUM(CASE WHEN ft.parking_entered_utc IS NOT NULL THEN 1 ELSE 0 END) AS parking_entered,
    NULL AS unused
FROM dbo.adl_flight_core c
LEFT JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
WHERE c.is_active = 1;

-- ============================================================================
-- 3. Flights with Complete OOOI (OUT→OFF→ON→IN)
-- ============================================================================
SELECT TOP 20
    c.callsign,
    fp.fp_dept_icao AS origin,
    fp.fp_dest_icao AS destination,
    ft.out_utc,
    ft.off_utc,
    ft.on_utc,
    ft.in_utc,
    -- Calculate durations
    DATEDIFF(MINUTE, ft.out_utc, ft.off_utc) AS taxi_out_min,
    DATEDIFF(MINUTE, ft.off_utc, ft.on_utc) AS flight_time_min,
    DATEDIFF(MINUTE, ft.on_utc, ft.in_utc) AS taxi_in_min,
    DATEDIFF(MINUTE, ft.out_utc, ft.in_utc) AS block_time_min
FROM dbo.adl_flight_core c
INNER JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
WHERE ft.out_utc IS NOT NULL
  AND ft.off_utc IS NOT NULL
  AND ft.on_utc IS NOT NULL
  AND ft.in_utc IS NOT NULL
ORDER BY ft.in_utc DESC;

-- ============================================================================
-- 4. Flights Missing IN Time (Landed but not at gate)
-- Should have ON but not IN - potential candidates for next IN
-- ============================================================================
SELECT TOP 20
    c.callsign,
    c.phase,
    c.current_zone,
    c.current_zone_airport,
    fp.fp_dest_icao,
    ft.on_utc,
    ft.in_utc,
    p.groundspeed_kts
FROM dbo.adl_flight_core c
INNER JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
WHERE c.is_active = 1
  AND ft.on_utc IS NOT NULL
  AND ft.in_utc IS NULL
ORDER BY ft.on_utc DESC;

-- ============================================================================
-- 5. Recent Zone Transitions (should show BATCH_V2)
-- ============================================================================
SELECT TOP 30
    e.event_utc,
    c.callsign,
    e.airport_icao,
    e.from_zone,
    e.to_zone,
    e.groundspeed_kts,
    e.altitude_ft,
    e.detection_method
FROM dbo.adl_zone_events e
INNER JOIN dbo.adl_flight_core c ON c.flight_uid = e.flight_uid
ORDER BY e.event_utc DESC;

-- ============================================================================
-- 6. Zone Distribution by Phase
-- ============================================================================
SELECT 
    c.phase,
    c.current_zone,
    COUNT(*) AS flight_count
FROM dbo.adl_flight_core c
WHERE c.is_active = 1
  AND c.current_zone IS NOT NULL
GROUP BY c.phase, c.current_zone
ORDER BY c.phase, c.current_zone;

-- ============================================================================
-- 7. Extended Departure Times Example
-- ============================================================================
SELECT TOP 10
    c.callsign,
    fp.fp_dept_icao,
    ft.parking_left_utc,
    ft.taxiway_entered_utc,
    ft.hold_entered_utc,
    ft.runway_entered_utc,
    ft.takeoff_roll_utc,
    ft.rotation_utc,
    ft.off_utc,
    -- Durations
    DATEDIFF(SECOND, ft.parking_left_utc, ft.taxiway_entered_utc) AS pushback_sec,
    DATEDIFF(SECOND, ft.taxiway_entered_utc, ft.hold_entered_utc) AS taxi_to_hold_sec,
    DATEDIFF(SECOND, ft.hold_entered_utc, ft.runway_entered_utc) AS hold_time_sec,
    DATEDIFF(SECOND, ft.runway_entered_utc, ft.off_utc) AS runway_time_sec
FROM dbo.adl_flight_core c
INNER JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
WHERE ft.off_utc IS NOT NULL
  AND ft.parking_left_utc IS NOT NULL
ORDER BY ft.off_utc DESC;

-- ============================================================================
-- 8. Extended Arrival Times Example
-- ============================================================================
SELECT TOP 10
    c.callsign,
    fp.fp_dest_icao,
    ft.approach_start_utc,
    ft.touchdown_utc,
    ft.rollout_end_utc,
    ft.taxiway_arr_utc,
    ft.parking_entered_utc,
    ft.in_utc,
    -- Durations
    DATEDIFF(SECOND, ft.approach_start_utc, ft.touchdown_utc) AS approach_sec,
    DATEDIFF(SECOND, ft.touchdown_utc, ft.rollout_end_utc) AS rollout_sec,
    DATEDIFF(SECOND, ft.rollout_end_utc, ft.parking_entered_utc) AS taxi_in_sec
FROM dbo.adl_flight_core c
INNER JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
WHERE ft.on_utc IS NOT NULL
  AND ft.touchdown_utc IS NOT NULL
ORDER BY ft.on_utc DESC;

-- ============================================================================
-- 9. Average Taxi Times by Airport (requires data accumulation)
-- ============================================================================
SELECT 
    fp.fp_dept_icao AS airport,
    COUNT(*) AS departures,
    AVG(DATEDIFF(MINUTE, ft.out_utc, ft.off_utc)) AS avg_taxi_out_min,
    MIN(DATEDIFF(MINUTE, ft.out_utc, ft.off_utc)) AS min_taxi_out_min,
    MAX(DATEDIFF(MINUTE, ft.out_utc, ft.off_utc)) AS max_taxi_out_min
FROM dbo.adl_flight_times ft
INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = ft.flight_uid
WHERE ft.out_utc IS NOT NULL 
  AND ft.off_utc IS NOT NULL
  AND DATEDIFF(MINUTE, ft.out_utc, ft.off_utc) BETWEEN 1 AND 60  -- Filter outliers
GROUP BY fp.fp_dept_icao
HAVING COUNT(*) >= 3
ORDER BY departures DESC;

-- ============================================================================
-- 10. Test Zone Detection Function
-- ============================================================================
SELECT 
    'KJFK Gate' AS test_case,
    dbo.fn_DetectCurrentZone('KJFK', 40.6413, -73.7781, 13, 0) AS detected_zone
UNION ALL
SELECT 'KLAX Parking', dbo.fn_DetectCurrentZone('KLAX', 33.9425, -118.4081, 128, 0)
UNION ALL
SELECT 'KORD Taxiway', dbo.fn_DetectCurrentZone('KORD', 41.9787, -87.9161, 668, 20)
UNION ALL
SELECT 'KATL Runway', dbo.fn_DetectCurrentZone('KATL', 33.6407, -84.4277, 1026, 150)
UNION ALL
SELECT 'Any Airborne', dbo.fn_DetectCurrentZone('KJFK', 40.7000, -73.9000, 5000, 250);
