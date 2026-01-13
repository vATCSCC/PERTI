-- ETA Diagnostic: Why is eta_dist_source NULL and prefiles not getting ETAs?
-- Run this to identify root causes

PRINT '=== ETA DIAGNOSTIC ===';
PRINT '';

-- 1. Check if eta_dist_source column exists
PRINT '--- 1. eta_dist_source Column Check ---';
SELECT
    'eta_dist_source column' AS check_item,
    CASE WHEN EXISTS (
        SELECT 1 FROM sys.columns
        WHERE object_id = OBJECT_ID('dbo.adl_flight_times')
        AND name = 'eta_dist_source'
    ) THEN 'EXISTS' ELSE 'MISSING' END AS status;

-- 2. Check eta_dist_source values
PRINT '';
PRINT '--- 2. eta_dist_source Distribution ---';
SELECT
    ISNULL(eta_dist_source, 'NULL') AS dist_source,
    COUNT(*) AS flights
FROM dbo.adl_flight_times
GROUP BY eta_dist_source
ORDER BY COUNT(*) DESC;

-- 3. Check eta_method values (what procedure is setting ETAs?)
PRINT '';
PRINT '--- 3. eta_method Distribution (What''s calculating ETAs?) ---';
SELECT
    ISNULL(eta_method, 'NULL') AS eta_method,
    COUNT(*) AS flights
FROM dbo.adl_flight_times
GROUP BY eta_method
ORDER BY COUNT(*) DESC;

-- 4. Check prefile status
PRINT '';
PRINT '--- 4. Prefile Analysis ---';
SELECT
    'Prefiles total' AS metric,
    COUNT(*) AS cnt
FROM dbo.adl_flight_core
WHERE phase = 'prefile'
UNION ALL
SELECT
    'Prefiles is_active=1',
    COUNT(*)
FROM dbo.adl_flight_core
WHERE phase = 'prefile' AND is_active = 1
UNION ALL
SELECT
    'Prefiles with flight_plan',
    COUNT(*)
FROM dbo.adl_flight_core c
JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
WHERE c.phase = 'prefile'
UNION ALL
SELECT
    'Prefiles with gcd_nm',
    COUNT(*)
FROM dbo.adl_flight_core c
JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
WHERE c.phase = 'prefile' AND fp.gcd_nm IS NOT NULL
UNION ALL
SELECT
    'Prefiles with position',
    COUNT(*)
FROM dbo.adl_flight_core c
JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
WHERE c.phase = 'prefile' AND p.lat IS NOT NULL
UNION ALL
SELECT
    'Prefiles with times row',
    COUNT(*)
FROM dbo.adl_flight_core c
JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
WHERE c.phase = 'prefile';

-- 5. Sample prefile data
PRINT '';
PRINT '--- 5. Sample Prefiles (Top 5) ---';
SELECT TOP 5
    c.flight_uid,
    c.callsign,
    c.phase,
    c.is_active,
    fp.gcd_nm,
    fp.route_total_nm,
    fp.fp_dest_icao,
    ft.eta_utc,
    ft.eta_dist_source,
    ft.eta_method
FROM dbo.adl_flight_core c
LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
WHERE c.phase = 'prefile';

-- 6. Check what dist_source SHOULD be for flights
PRINT '';
PRINT '--- 6. What dist_source SHOULD be (based on available data) ---';
SELECT
    CASE
        WHEN p.route_dist_to_dest_nm IS NOT NULL THEN 'ROUTE (position)'
        WHEN fp.route_dist_nm IS NOT NULL THEN 'ROUTE (plan)'
        WHEN fp.gcd_nm IS NOT NULL THEN 'GCD'
        ELSE 'NONE'
    END AS expected_source,
    COUNT(*) AS flights
FROM dbo.adl_flight_core c
LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
WHERE c.is_active = 1
GROUP BY
    CASE
        WHEN p.route_dist_to_dest_nm IS NOT NULL THEN 'ROUTE (position)'
        WHEN fp.route_dist_nm IS NOT NULL THEN 'ROUTE (plan)'
        WHEN fp.gcd_nm IS NOT NULL THEN 'GCD'
        ELSE 'NONE'
    END
ORDER BY COUNT(*) DESC;

-- 7. Check if batch procedure exists
PRINT '';
PRINT '--- 7. Procedure Version Check ---';
SELECT
    'sp_CalculateETABatch' AS proc_name,
    CASE WHEN OBJECT_ID('dbo.sp_CalculateETABatch', 'P') IS NOT NULL
         THEN 'EXISTS' ELSE 'MISSING' END AS status
UNION ALL
SELECT
    'sp_UpdateRouteDistancesBatch',
    CASE WHEN OBJECT_ID('dbo.sp_UpdateRouteDistancesBatch', 'P') IS NOT NULL
         THEN 'EXISTS' ELSE 'MISSING' END;

PRINT '';
PRINT '=== END DIAGNOSTIC ===';
