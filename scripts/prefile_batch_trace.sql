-- Prefile Batch Trace: Debug why batch doesn't update prefiles
-- This simulates the exact batch query and checks each step

PRINT '=== PREFILE BATCH TRACE ===';
PRINT '';

DECLARE @now DATETIME2(0) = SYSUTCDATETIME();

-- Step 1: Build work table (same as batch)
PRINT '--- Step 1: Building work table (#eta_work simulation) ---';

DROP TABLE IF EXISTS #eta_work_debug;

SELECT
    c.flight_uid,
    c.phase,
    COALESCE(p.altitude_ft, fp.fp_altitude_ft) AS altitude_ft,
    p.groundspeed_kts,
    COALESCE(p.dist_to_dest_nm, p.route_dist_to_dest_nm, fp.gcd_nm) AS dist_to_dest_nm,
    p.dist_flown_nm,
    COALESCE(fp.final_alt_ft, fp.fp_altitude_ft, 35000) AS filed_alt,
    fp.initial_alt_ft,
    fp.final_alt_ft,
    fp.stepclimb_count,
    fp.is_simbrief,
    fp.fp_dest_icao,
    fp.fp_dept_icao,
    fp.route_dist_nm,
    fp.gcd_nm,
    COALESCE(fp.route_dist_nm, fp.gcd_nm) AS total_route_dist,
    CASE
        WHEN p.route_dist_to_dest_nm IS NOT NULL THEN 'ROUTE'
        WHEN fp.route_dist_nm IS NOT NULL THEN 'ROUTE'
        WHEN fp.gcd_nm IS NOT NULL THEN 'GCD'
        ELSE 'NONE'
    END AS dist_source,
    ac.aircraft_icao,
    ISNULL(ac.weight_class, 'L') AS weight_class,
    ac.engine_type,
    ISNULL(CAST(apt.ELEV AS INT), 0) AS dest_elev,
    tmi.edct_utc,
    tmi.cta_utc,
    ft.on_utc,
    ft.in_utc,
    ft.ata_runway_utc,
    ft.eta_utc AS current_eta,
    ft.flight_uid AS times_flight_uid  -- Track if times row exists
INTO #eta_work_debug
FROM dbo.adl_flight_core c
LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid
LEFT JOIN dbo.apts apt ON apt.ICAO_ID = fp.fp_dest_icao
LEFT JOIN dbo.adl_flight_tmi tmi ON tmi.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
WHERE c.is_active = 1
  AND (p.lat IS NOT NULL OR c.phase = 'prefile');

PRINT 'Work table rows: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- Check prefiles in work table
SELECT
    'Prefiles in work table' AS check_point,
    COUNT(*) AS total,
    SUM(CASE WHEN times_flight_uid IS NOT NULL THEN 1 ELSE 0 END) AS with_times_row,
    SUM(CASE WHEN dist_source = 'GCD' THEN 1 ELSE 0 END) AS dist_source_gcd,
    SUM(CASE WHEN gcd_nm IS NOT NULL THEN 1 ELSE 0 END) AS with_gcd_nm,
    SUM(CASE WHEN total_route_dist IS NOT NULL THEN 1 ELSE 0 END) AS with_total_dist
FROM #eta_work_debug
WHERE phase = 'prefile';

-- Step 2: Sample prefiles from work table
PRINT '';
PRINT '--- Step 2: Sample prefiles from work table ---';
SELECT TOP 5
    flight_uid,
    phase,
    dist_to_dest_nm,
    total_route_dist,
    dist_source,
    gcd_nm,
    filed_alt,
    dest_elev,
    times_flight_uid,
    current_eta
FROM #eta_work_debug
WHERE phase = 'prefile'
ORDER BY gcd_nm DESC;

-- Step 3: Calculate time_to_dest for prefiles
PRINT '';
PRINT '--- Step 3: ETA calculation for prefiles ---';
SELECT TOP 5
    flight_uid,
    phase,
    COALESCE(dist_to_dest_nm, total_route_dist) AS effective_dist,
    450 AS cruise_speed,
    -- ELSE branch calculation (for prefiles)
    15 + ISNULL(COALESCE(dist_to_dest_nm, total_route_dist), 0) / NULLIF(450, 0) * 60 * 1.15 AS time_to_dest_min,
    -- Final ETA
    DATEADD(MINUTE,
        CAST(15 + ISNULL(COALESCE(dist_to_dest_nm, total_route_dist), 0) / NULLIF(450, 0) * 60 * 1.15 AS INT),
        @now) AS calc_eta,
    times_flight_uid
FROM #eta_work_debug
WHERE phase = 'prefile'
ORDER BY gcd_nm DESC;

-- Step 4: Check the actual flight_times table for these prefiles
PRINT '';
PRINT '--- Step 4: Actual flight_times data for sample prefiles ---';
SELECT TOP 5
    w.flight_uid AS work_flight_uid,
    ft.flight_uid AS times_flight_uid,
    w.phase,
    ft.eta_utc,
    ft.eta_method,
    ft.eta_dist_source,
    ft.times_updated_utc
FROM #eta_work_debug w
LEFT JOIN dbo.adl_flight_times ft ON ft.flight_uid = w.flight_uid
WHERE w.phase = 'prefile'
ORDER BY w.gcd_nm DESC;

-- Step 5: Verify JOIN would match
PRINT '';
PRINT '--- Step 5: JOIN verification ---';
SELECT
    'Prefiles where times row exists' AS check_point,
    COUNT(*) AS cnt
FROM #eta_work_debug w
INNER JOIN dbo.adl_flight_times ft ON ft.flight_uid = w.flight_uid
WHERE w.phase = 'prefile';

-- Step 6: Check for any type mismatches
PRINT '';
PRINT '--- Step 6: Data type check ---';
SELECT
    'work_flight_uid type' AS item,
    SQL_VARIANT_PROPERTY(MIN(flight_uid), 'BaseType') AS base_type,
    SQL_VARIANT_PROPERTY(MIN(flight_uid), 'MaxLength') AS max_length
FROM #eta_work_debug
UNION ALL
SELECT
    'times_flight_uid type',
    SQL_VARIANT_PROPERTY(MIN(flight_uid), 'BaseType'),
    SQL_VARIANT_PROPERTY(MIN(flight_uid), 'MaxLength')
FROM dbo.adl_flight_times;

-- Step 7: Direct UPDATE test on one prefile
PRINT '';
PRINT '--- Step 7: Direct UPDATE test ---';

DECLARE @test_flight_uid BIGINT;
SELECT TOP 1 @test_flight_uid = w.flight_uid
FROM #eta_work_debug w
WHERE w.phase = 'prefile' AND w.gcd_nm IS NOT NULL;

PRINT 'Test flight_uid: ' + CAST(@test_flight_uid AS VARCHAR);

-- Show before
SELECT 'Before direct update' AS status, flight_uid, eta_utc, eta_method, eta_dist_source
FROM dbo.adl_flight_times WHERE flight_uid = @test_flight_uid;

-- Direct update
UPDATE dbo.adl_flight_times
SET eta_method = 'TEST_V3',
    eta_utc = DATEADD(HOUR, 5, @now),
    eta_dist_source = 'GCD'
WHERE flight_uid = @test_flight_uid;

PRINT 'Rows updated: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- Show after
SELECT 'After direct update' AS status, flight_uid, eta_utc, eta_method, eta_dist_source
FROM dbo.adl_flight_times WHERE flight_uid = @test_flight_uid;

-- Revert the test
UPDATE dbo.adl_flight_times
SET eta_method = NULL,
    eta_utc = NULL,
    eta_dist_source = NULL
WHERE flight_uid = @test_flight_uid;

-- Cleanup
DROP TABLE IF EXISTS #eta_work_debug;

PRINT '';
PRINT '=== END TRACE ===';
