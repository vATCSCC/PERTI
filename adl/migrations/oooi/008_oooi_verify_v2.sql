-- ============================================================================
-- OOOI Zone Detection V3 - Verification Queries
-- Run these after deploying 043_oooi_batch_v3.sql
-- ============================================================================

-- ============================================================================
-- 1. OOOI Summary Stats (Compare before/after V3)
-- ============================================================================
SELECT
    COUNT(*) AS total_active_flights,
    SUM(CASE WHEN ft.out_utc IS NOT NULL THEN 1 ELSE 0 END) AS with_out,
    SUM(CASE WHEN ft.off_utc IS NOT NULL THEN 1 ELSE 0 END) AS with_off,
    SUM(CASE WHEN ft.on_utc IS NOT NULL THEN 1 ELSE 0 END) AS with_on,
    SUM(CASE WHEN ft.in_utc IS NOT NULL THEN 1 ELSE 0 END) AS with_in,
    SUM(CASE WHEN ft.out_utc IS NOT NULL AND ft.off_utc IS NOT NULL 
             AND ft.on_utc IS NOT NULL AND ft.in_utc IS NOT NULL THEN 1 ELSE 0 END) AS complete_oooi,
    -- Percentages
    CAST(100.0 * SUM(CASE WHEN ft.out_utc IS NOT NULL THEN 1 ELSE 0 END) / COUNT(*) AS DECIMAL(5,1)) AS pct_out,
    CAST(100.0 * SUM(CASE WHEN ft.in_utc IS NOT NULL THEN 1 ELSE 0 END) / NULLIF(SUM(CASE WHEN ft.on_utc IS NOT NULL THEN 1 ELSE 0 END), 0) AS DECIMAL(5,1)) AS pct_in_of_landed
FROM dbo.adl_flight_core c
LEFT JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
WHERE c.is_active = 1;

-- ============================================================================
-- 2. Flights that SHOULD have IN but don't (debugging)
-- These should be 0 after V3 catchup runs
-- ============================================================================
SELECT 
    c.callsign,
    c.phase,
    c.current_zone,
    c.current_zone_airport,
    fp.fp_dest_icao,
    CASE WHEN c.current_zone_airport = fp.fp_dest_icao THEN 'YES' ELSE 'NO' END AS at_destination,
    ft.on_utc,
    ft.in_utc,
    p.groundspeed_kts
FROM dbo.adl_flight_core c
INNER JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
WHERE c.is_active = 1
  AND ft.on_utc IS NOT NULL           -- Has landed
  AND ft.in_utc IS NULL               -- But no IN time
  AND c.current_zone IN ('PARKING', 'GATE')  -- At gate/parking
ORDER BY ft.on_utc DESC;

-- ============================================================================
-- 3. Recent V3 Zone Events (should show BATCH_V3)
-- ============================================================================
SELECT TOP 30
    e.event_utc,
    c.callsign,
    e.event_type,
    e.airport_icao,
    e.from_zone,
    e.to_zone,
    e.groundspeed_kts,
    e.altitude_ft,
    e.detection_method
FROM dbo.adl_zone_events e
INNER JOIN dbo.adl_flight_core c ON c.flight_uid = e.flight_uid
WHERE e.detection_method = 'BATCH_V3'
ORDER BY e.event_utc DESC;

-- ============================================================================
-- 4. OOOI Phase Distribution
-- ============================================================================
SELECT 
    CASE 
        WHEN ft.off_utc IS NULL THEN 'PRE_DEPARTURE'
        WHEN ft.on_utc IS NULL AND ISNULL(p.pct_complete, 0) > 70 THEN 'ARRIVING'
        WHEN ft.on_utc IS NULL THEN 'ENROUTE'
        WHEN ft.in_utc IS NULL THEN 'POST_LANDING'
        ELSE 'COMPLETE'
    END AS oooi_phase,
    COUNT(*) AS flight_count,
    SUM(CASE WHEN c.current_zone IN ('PARKING', 'GATE') THEN 1 ELSE 0 END) AS at_gate
FROM dbo.adl_flight_core c
LEFT JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
WHERE c.is_active = 1
GROUP BY 
    CASE 
        WHEN ft.off_utc IS NULL THEN 'PRE_DEPARTURE'
        WHEN ft.on_utc IS NULL AND ISNULL(p.pct_complete, 0) > 70 THEN 'ARRIVING'
        WHEN ft.on_utc IS NULL THEN 'ENROUTE'
        WHEN ft.in_utc IS NULL THEN 'POST_LANDING'
        ELSE 'COMPLETE'
    END
ORDER BY 1;

-- ============================================================================
-- 5. Complete OOOI Cycles with Durations
-- ============================================================================
SELECT TOP 25
    c.callsign,
    fp.fp_dept_icao AS origin,
    fp.fp_dest_icao AS destination,
    ft.out_utc,
    ft.off_utc,
    ft.on_utc,
    ft.in_utc,
    -- Durations
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
-- 6. Extended Zone Times Coverage (V3)
-- ============================================================================
SELECT
    'Departure' AS phase,
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
    'Arrival' AS phase,
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
-- 7. Airport Mismatch Check (flights at wrong airport)
-- ============================================================================
SELECT 
    c.callsign,
    c.phase,
    c.current_zone,
    c.current_zone_airport AS zone_airport,
    fp.fp_dept_icao AS origin,
    fp.fp_dest_icao AS destination,
    CASE 
        WHEN c.current_zone_airport = fp.fp_dept_icao THEN 'AT_ORIGIN'
        WHEN c.current_zone_airport = fp.fp_dest_icao THEN 'AT_DESTINATION'
        ELSE 'MISMATCH'
    END AS airport_status,
    ft.off_utc,
    ft.on_utc
FROM dbo.adl_flight_core c
INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
WHERE c.is_active = 1
  AND c.current_zone IN ('PARKING', 'GATE', 'TAXIWAY', 'HOLD', 'RUNWAY')
  AND c.current_zone_airport IS NOT NULL
  AND c.current_zone_airport != fp.fp_dept_icao
  AND c.current_zone_airport != fp.fp_dest_icao
ORDER BY c.callsign;

-- ============================================================================
-- 8. Detection Method Distribution (shows V1/V2/V3 usage)
-- ============================================================================
SELECT 
    detection_method,
    COUNT(*) AS event_count,
    MIN(event_utc) AS first_event,
    MAX(event_utc) AS last_event
FROM dbo.adl_zone_events
GROUP BY detection_method
ORDER BY last_event DESC;

-- ============================================================================
-- 9. Zone Transition Patterns (what transitions are most common)
-- ============================================================================
SELECT TOP 20
    from_zone,
    to_zone,
    COUNT(*) AS transition_count
FROM dbo.adl_zone_events
WHERE detection_method = 'BATCH_V3'
  AND from_zone IS NOT NULL
GROUP BY from_zone, to_zone
ORDER BY transition_count DESC;

-- ============================================================================
-- 10. Average Taxi Times by Airport (needs data accumulation)
-- ============================================================================
SELECT 
    'Taxi Out' AS metric,
    fp.fp_dept_icao AS airport,
    COUNT(*) AS flights,
    AVG(DATEDIFF(MINUTE, ft.out_utc, ft.off_utc)) AS avg_minutes,
    MIN(DATEDIFF(MINUTE, ft.out_utc, ft.off_utc)) AS min_minutes,
    MAX(DATEDIFF(MINUTE, ft.out_utc, ft.off_utc)) AS max_minutes
FROM dbo.adl_flight_times ft
INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = ft.flight_uid
WHERE ft.out_utc IS NOT NULL 
  AND ft.off_utc IS NOT NULL
  AND DATEDIFF(MINUTE, ft.out_utc, ft.off_utc) BETWEEN 1 AND 60
GROUP BY fp.fp_dept_icao
HAVING COUNT(*) >= 5

UNION ALL

SELECT 
    'Taxi In' AS metric,
    fp.fp_dest_icao AS airport,
    COUNT(*) AS flights,
    AVG(DATEDIFF(MINUTE, ft.on_utc, ft.in_utc)) AS avg_minutes,
    MIN(DATEDIFF(MINUTE, ft.on_utc, ft.in_utc)) AS min_minutes,
    MAX(DATEDIFF(MINUTE, ft.on_utc, ft.in_utc)) AS max_minutes
FROM dbo.adl_flight_times ft
INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = ft.flight_uid
WHERE ft.on_utc IS NOT NULL 
  AND ft.in_utc IS NOT NULL
  AND DATEDIFF(MINUTE, ft.on_utc, ft.in_utc) BETWEEN 1 AND 30
GROUP BY fp.fp_dest_icao
HAVING COUNT(*) >= 5

ORDER BY metric, flights DESC;
