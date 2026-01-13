-- Direct simulation of batch UPDATE for prefiles
-- This will show if the problem is in the batch logic itself

PRINT '=== PREFILE UPDATE SIMULATION ===';
PRINT '';

DECLARE @now DATETIME2(0) = SYSUTCDATETIME();

-- Step 1: Create a simplified #eta_results just for prefiles
PRINT '--- Step 1: Creating prefile results table ---';

DROP TABLE IF EXISTS #prefile_results;

SELECT
    c.flight_uid,
    c.phase,
    fp.gcd_nm,
    -- Calculate ETA (simplified - same as ELSE branch in batch)
    DATEADD(MINUTE,
        CAST(15 + fp.gcd_nm / 450.0 * 60 * 1.15 AS INT),
        @now
    ) AS final_eta,
    'P' AS final_prefix,  -- Proposed for prefiles
    0.65 AS confidence,
    fp.gcd_nm AS dist_to_dest_nm,
    0 AS wind_component,
    0 AS tmi_delay,
    (35000 - ISNULL(CAST(apt.ELEV AS INT), 0)) / 1000.0 * 3.0 AS tod_dist,
    CAST(NULL AS DATETIME2(0)) AS tod_eta,  -- Must cast NULL to datetime2 to match column type
    'V3' AS eta_method,
    'GCD' AS dist_source
INTO #prefile_results
FROM dbo.adl_flight_core c
INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
LEFT JOIN dbo.apts apt ON apt.ICAO_ID = fp.fp_dest_icao
WHERE c.is_active = 1
  AND c.phase = 'prefile'
  AND fp.gcd_nm IS NOT NULL;

PRINT 'Prefile results created: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- Step 2: Show what we're about to update
PRINT '';
PRINT '--- Step 2: Sample prefile results ---';
SELECT TOP 5 flight_uid, phase, gcd_nm, final_eta, eta_method, dist_source
FROM #prefile_results
ORDER BY gcd_nm DESC;

-- Step 3: Check current state of times rows for these prefiles
PRINT '';
PRINT '--- Step 3: Current times row state (before UPDATE) ---';
SELECT
    'Before UPDATE' AS status,
    COUNT(*) AS total_prefiles,
    SUM(CASE WHEN ft.eta_method IS NOT NULL THEN 1 ELSE 0 END) AS with_eta_method,
    SUM(CASE WHEN ft.eta_utc IS NOT NULL THEN 1 ELSE 0 END) AS with_eta_utc
FROM #prefile_results r
INNER JOIN dbo.adl_flight_times ft ON ft.flight_uid = r.flight_uid;

-- Step 4: Perform the UPDATE (same as batch procedure)
PRINT '';
PRINT '--- Step 4: Performing UPDATE ---';

UPDATE ft
SET ft.eta_utc = r.final_eta,
    ft.eta_runway_utc = r.final_eta,
    ft.eta_epoch = CASE
        WHEN r.final_eta IS NOT NULL
        THEN DATEDIFF(SECOND, '1970-01-01', r.final_eta)
        ELSE NULL
    END,
    ft.eta_prefix = r.final_prefix,
    ft.eta_confidence = r.confidence,
    ft.eta_route_dist_nm = r.dist_to_dest_nm,
    ft.eta_wind_component_kts = r.wind_component,
    ft.eta_tmi_delay_min = r.tmi_delay,
    ft.eta_weather_delay_min = 0,
    ft.eta_last_calc_utc = @now,
    ft.tod_dist_nm = r.tod_dist,
    ft.tod_eta_utc = r.tod_eta,
    ft.eta_method = r.eta_method,
    ft.eta_dist_source = r.dist_source,
    ft.times_updated_utc = @now
FROM dbo.adl_flight_times ft
INNER JOIN #prefile_results r ON r.flight_uid = ft.flight_uid;

PRINT 'Rows updated: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- Step 5: Verify the UPDATE worked
PRINT '';
PRINT '--- Step 5: Current times row state (after UPDATE) ---';
SELECT
    'After UPDATE' AS status,
    COUNT(*) AS total_prefiles,
    SUM(CASE WHEN ft.eta_method IS NOT NULL THEN 1 ELSE 0 END) AS with_eta_method,
    SUM(CASE WHEN ft.eta_utc IS NOT NULL THEN 1 ELSE 0 END) AS with_eta_utc
FROM #prefile_results r
INNER JOIN dbo.adl_flight_times ft ON ft.flight_uid = r.flight_uid;

-- Step 6: Show sample updated prefiles
PRINT '';
PRINT '--- Step 6: Sample prefiles after UPDATE ---';
SELECT TOP 5
    c.flight_uid,
    c.callsign,
    c.phase,
    fp.gcd_nm,
    ft.eta_utc,
    ft.eta_method,
    ft.eta_dist_source,
    ft.eta_confidence,
    ft.times_updated_utc
FROM dbo.adl_flight_core c
JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
JOIN dbo.adl_flight_times ft ON ft.flight_uid = c.flight_uid
WHERE c.phase = 'prefile' AND c.is_active = 1 AND fp.gcd_nm IS NOT NULL
ORDER BY fp.gcd_nm DESC;

-- Cleanup
DROP TABLE IF EXISTS #prefile_results;

PRINT '';
PRINT '=== END SIMULATION ===';
