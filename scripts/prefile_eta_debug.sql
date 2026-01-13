-- Prefile ETA Debug: Why aren't prefiles getting ETAs?
-- This traces through the sp_CalculateETABatch logic step by step
-- Run this to identify exactly where prefiles are being filtered out

PRINT '=== PREFILE ETA DEBUG ===';
PRINT '';

-- Step 0: Basic counts
PRINT '--- Step 0: Basic Prefile Counts ---';
SELECT 'Total prefiles' AS metric, COUNT(*) AS cnt FROM dbo.adl_flight_core WHERE phase = 'prefile'
UNION ALL SELECT 'Active prefiles', COUNT(*) FROM dbo.adl_flight_core WHERE phase = 'prefile' AND is_active = 1
UNION ALL SELECT 'With flight_plan', COUNT(*) FROM dbo.adl_flight_core c JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid WHERE c.phase = 'prefile' AND c.is_active = 1
UNION ALL SELECT 'With gcd_nm', COUNT(*) FROM dbo.adl_flight_core c JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid WHERE c.phase = 'prefile' AND c.is_active = 1 AND fp.gcd_nm IS NOT NULL
UNION ALL SELECT 'With times row', COUNT(*) FROM dbo.adl_flight_core c JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid WHERE c.phase = 'prefile' AND c.is_active = 1
UNION ALL SELECT 'With position', COUNT(*) FROM dbo.adl_flight_core c JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid WHERE c.phase = 'prefile' AND c.is_active = 1;

-- Step 1: Simulate the EXACT query from sp_CalculateETABatch #eta_work
PRINT '';
PRINT '--- Step 1: Simulating #eta_work query (what batch actually selects) ---';
SELECT
    'In #eta_work' AS check_point,
    COUNT(*) AS total_flights,
    SUM(CASE WHEN c.phase = 'prefile' THEN 1 ELSE 0 END) AS prefiles_included
FROM dbo.adl_flight_core c
LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid
LEFT JOIN dbo.apts apt ON apt.ICAO_ID = fp.fp_dest_icao
LEFT JOIN dbo.adl_flight_tmi tmi ON tmi.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
WHERE c.is_active = 1
  AND (p.lat IS NOT NULL OR c.phase = 'prefile');

-- Step 2: Check the WHERE clause logic
PRINT '';
PRINT '--- Step 2: WHERE clause evaluation ---';
SELECT
    'WHERE passed' AS check_point,
    COUNT(*) AS prefiles
FROM dbo.adl_flight_core c
INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
WHERE c.is_active = 1
  AND (p.lat IS NOT NULL OR c.phase = 'prefile');  -- Same WHERE as batch

-- Step 3: Check what distance values prefiles have
PRINT '';
PRINT '--- Step 3: Prefile distance data ---';
SELECT TOP 10
    c.flight_uid,
    c.callsign,
    c.phase,
    fp.gcd_nm,
    fp.route_dist_nm,
    p.dist_to_dest_nm AS pos_dist,
    p.route_dist_to_dest_nm AS pos_route_dist,
    COALESCE(p.dist_to_dest_nm, p.route_dist_to_dest_nm, fp.gcd_nm) AS effective_dist,
    CASE
        WHEN p.route_dist_to_dest_nm IS NOT NULL THEN 'ROUTE'
        WHEN fp.route_dist_nm IS NOT NULL THEN 'ROUTE'
        WHEN fp.gcd_nm IS NOT NULL THEN 'GCD'
        ELSE 'NONE'
    END AS dist_source
FROM dbo.adl_flight_core c
INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
WHERE c.is_active = 1
  AND c.phase = 'prefile'
ORDER BY fp.gcd_nm DESC;

-- Step 4: Check adl_flight_times rows for prefiles
PRINT '';
PRINT '--- Step 4: adl_flight_times rows for prefiles ---';
SELECT
    CASE WHEN ft.flight_uid IS NOT NULL THEN 'Has times row' ELSE 'No times row' END AS status,
    COUNT(*) AS prefiles
FROM dbo.adl_flight_core c
LEFT JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
WHERE c.is_active = 1
  AND c.phase = 'prefile'
GROUP BY CASE WHEN ft.flight_uid IS NOT NULL THEN 'Has times row' ELSE 'No times row' END;

-- Step 5: Check if prefiles have aircraft data (for performance lookup)
PRINT '';
PRINT '--- Step 5: Prefile aircraft data ---';
SELECT
    CASE
        WHEN ac.aircraft_icao IS NOT NULL THEN 'Has aircraft'
        ELSE 'No aircraft'
    END AS status,
    COUNT(*) AS prefiles
FROM dbo.adl_flight_core c
INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid
WHERE c.is_active = 1
  AND c.phase = 'prefile'
GROUP BY CASE
    WHEN ac.aircraft_icao IS NOT NULL THEN 'Has aircraft'
    ELSE 'No aircraft'
END;

-- Step 6: Simulate the ETA calculation for prefiles
PRINT '';
PRINT '--- Step 6: Simulated ETA calculation for sample prefile ---';
SELECT TOP 3
    c.flight_uid,
    c.callsign,
    c.phase,
    COALESCE(p.altitude_ft, fp.fp_altitude_ft) AS altitude_ft,
    COALESCE(fp.final_alt_ft, fp.fp_altitude_ft, 35000) AS filed_alt,
    COALESCE(p.dist_to_dest_nm, p.route_dist_to_dest_nm, fp.gcd_nm) AS dist_to_dest_nm,
    COALESCE(fp.route_dist_nm, fp.gcd_nm) AS total_route_dist,
    450 AS cruise_speed,  -- Default
    -- Calculate what time_to_dest_min SHOULD be (from ELSE branch)
    15 + ISNULL(
        COALESCE(p.dist_to_dest_nm, p.route_dist_to_dest_nm, fp.gcd_nm),
        COALESCE(fp.route_dist_nm, fp.gcd_nm)
    ) / 450.0 * 60 * 1.15 AS calc_time_to_dest_min,
    -- What ETA should be
    DATEADD(MINUTE,
        CAST(15 + ISNULL(
            COALESCE(p.dist_to_dest_nm, p.route_dist_to_dest_nm, fp.gcd_nm),
            COALESCE(fp.route_dist_nm, fp.gcd_nm)
        ) / 450.0 * 60 * 1.15 AS INT),
        SYSUTCDATETIME()
    ) AS expected_eta,
    ft.eta_utc AS current_eta,
    ft.eta_method AS current_method
FROM dbo.adl_flight_core c
INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
WHERE c.is_active = 1
  AND c.phase = 'prefile'
  AND fp.gcd_nm IS NOT NULL
ORDER BY fp.gcd_nm DESC;

-- Step 7: Check if batch procedure exists and is latest version
PRINT '';
PRINT '--- Step 7: Batch Procedure Check ---';
SELECT
    OBJECT_NAME(object_id) AS proc_name,
    create_date,
    modify_date
FROM sys.procedures
WHERE name = 'sp_CalculateETABatch';

-- Step 8: ACTUALLY RUN the batch and check results
PRINT '';
PRINT '--- Step 8: Running batch with debug ---';
PRINT 'Check counts before:';

SELECT 'Before batch - prefiles with eta_method' AS check_point,
       COUNT(*) AS cnt
FROM dbo.adl_flight_core c
JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
WHERE c.phase = 'prefile' AND c.is_active = 1 AND ft.eta_method IS NOT NULL;

PRINT 'Running sp_CalculateETABatch @debug = 1...';
DECLARE @eta_count INT;
EXEC dbo.sp_CalculateETABatch @eta_count = @eta_count OUTPUT, @debug = 1;
PRINT 'Batch completed. Rows processed: ' + CAST(@eta_count AS VARCHAR);

PRINT '';
PRINT 'Check counts after:';
SELECT 'After batch - prefiles with eta_method' AS check_point,
       COUNT(*) AS cnt
FROM dbo.adl_flight_core c
JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
WHERE c.phase = 'prefile' AND c.is_active = 1 AND ft.eta_method IS NOT NULL;

-- Step 9: Show sample prefiles after batch
PRINT '';
PRINT '--- Step 9: Sample prefiles after batch ---';
SELECT TOP 5
    c.flight_uid,
    c.callsign,
    c.phase,
    fp.gcd_nm,
    ft.eta_utc,
    ft.eta_method,
    ft.eta_dist_source,
    ft.eta_confidence
FROM dbo.adl_flight_core c
JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
WHERE c.phase = 'prefile' AND c.is_active = 1 AND fp.gcd_nm IS NOT NULL
ORDER BY fp.gcd_nm DESC;

PRINT '';
PRINT '=== END DEBUG ===';
