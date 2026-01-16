-- ============================================================================
-- Diagnostic query to debug demand monitoring waypoint issues
-- Run this to check why flights aren't appearing in demand monitors
-- ============================================================================

-- Check waypoints for specific flights (SWA1991, FDX2071, SWA4329)
SELECT
    c.callsign,
    c.flight_uid,
    c.phase,
    c.is_active,
    w.sequence_num,
    w.fix_name,
    w.on_airway,
    w.eta_utc,
    w.source
FROM dbo.adl_flight_core c
INNER JOIN dbo.adl_flight_waypoints w ON w.flight_uid = c.flight_uid
WHERE c.callsign IN ('SWA1991', 'FDX2071', 'SWA4329')
ORDER BY c.callsign, w.sequence_num;

-- Check if JASSE, DNERO, PLNDL, YORRK exist in any active flight's waypoints
SELECT
    w.fix_name,
    COUNT(DISTINCT w.flight_uid) AS flight_count,
    COUNT(*) AS waypoint_count
FROM dbo.adl_flight_waypoints w
INNER JOIN dbo.adl_flight_core c ON c.flight_uid = w.flight_uid
WHERE w.fix_name IN ('JASSE', 'DNERO', 'PLNDL', 'YORRK')
  AND c.is_active = 1
  AND c.phase NOT IN ('arrived', 'disconnected')
GROUP BY w.fix_name;

-- Check what airway values exist for Q90 and Q86
SELECT
    w.on_airway,
    COUNT(DISTINCT w.flight_uid) AS flight_count,
    COUNT(*) AS waypoint_count
FROM dbo.adl_flight_waypoints w
INNER JOIN dbo.adl_flight_core c ON c.flight_uid = w.flight_uid
WHERE w.on_airway IN ('Q90', 'Q86')
  AND c.is_active = 1
  AND c.phase NOT IN ('arrived', 'disconnected')
GROUP BY w.on_airway;

-- Check flights that have BOTH JASSE and DNERO
SELECT
    c.callsign,
    c.flight_uid,
    c.phase,
    fp.fp_dept_icao,
    fp.fp_dest_icao
FROM dbo.adl_flight_core c
INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
WHERE c.is_active = 1
  AND c.phase NOT IN ('arrived', 'disconnected')
  AND EXISTS (
      SELECT 1 FROM dbo.adl_flight_waypoints w1
      WHERE w1.flight_uid = c.flight_uid AND w1.fix_name = 'JASSE'
  )
  AND EXISTS (
      SELECT 1 FROM dbo.adl_flight_waypoints w2
      WHERE w2.flight_uid = c.flight_uid AND w2.fix_name = 'DNERO'
  );

-- Check flights that have BOTH PLNDL and YORRK
SELECT
    c.callsign,
    c.flight_uid,
    c.phase,
    fp.fp_dept_icao,
    fp.fp_dest_icao
FROM dbo.adl_flight_core c
INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
WHERE c.is_active = 1
  AND c.phase NOT IN ('arrived', 'disconnected')
  AND EXISTS (
      SELECT 1 FROM dbo.adl_flight_waypoints w1
      WHERE w1.flight_uid = c.flight_uid AND w1.fix_name = 'PLNDL'
  )
  AND EXISTS (
      SELECT 1 FROM dbo.adl_flight_waypoints w2
      WHERE w2.flight_uid = c.flight_uid AND w2.fix_name = 'YORRK'
  );

-- Test the fn_AirwaySegmentDemandBucketed function directly
SELECT * FROM dbo.fn_AirwaySegmentDemandBucketed('Q90', 'JASSE', 'DNERO', 15, 12, NULL);
SELECT * FROM dbo.fn_AirwaySegmentDemandBucketed('Q86', 'PLNDL', 'YORRK', 15, 12, NULL);
