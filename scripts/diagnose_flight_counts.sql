-- ADL Flight Count Diagnostic Query
-- Run this to identify where flights are being lost

-- 1. Total active flights in core table (should match VATSIM pilot count)
SELECT 'adl_flight_core (active)' AS source, COUNT(*) AS flight_count
FROM dbo.adl_flight_core
WHERE is_active = 1;

-- 2. Active flights WITH position data (what the API currently returns)
SELECT 'adl_flight_core + position (INNER JOIN)' AS source, COUNT(*) AS flight_count
FROM dbo.adl_flight_core c
INNER JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
WHERE c.is_active = 1;

-- 3. Active flights from the view (includes NULL positions)
SELECT 'vw_adl_flights (active)' AS source, COUNT(*) AS flight_count
FROM dbo.vw_adl_flights
WHERE is_active = 1;

-- 4. Active flights MISSING position data (the gap)
SELECT 'Missing position data (the gap)' AS source, COUNT(*) AS flight_count
FROM dbo.adl_flight_core c
LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
WHERE c.is_active = 1
  AND p.flight_uid IS NULL;

-- 5. Last snapshot timestamp (data freshness check)
SELECT TOP 1
    'Last snapshot' AS source,
    snapshot_utc,
    DATEDIFF(SECOND, snapshot_utc, SYSUTCDATETIME()) AS seconds_ago
FROM dbo.adl_flight_core
WHERE snapshot_utc IS NOT NULL
ORDER BY snapshot_utc DESC;

-- 6. Sample of flights missing position data
SELECT TOP 10
    c.callsign,
    c.flight_key,
    c.first_seen_utc,
    c.last_seen_utc,
    c.phase,
    c.flight_status,
    fp.fp_dept_icao,
    fp.fp_dest_icao
FROM dbo.adl_flight_core c
LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
WHERE c.is_active = 1
  AND p.flight_uid IS NULL
ORDER BY c.last_seen_utc DESC;
