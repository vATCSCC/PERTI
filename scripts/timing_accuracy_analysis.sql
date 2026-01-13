-- ============================================================================
-- PERTI Timing Accuracy & Consistency Analysis
--
-- Run this script against the ADL database to analyze:
--   1. ETA Consistency - How stable are ETAs between updates?
--   2. ETA Accuracy - How close are predictions to actual arrivals?
--   3. Update Frequency - How often are times being calculated?
--   4. Coverage - What percentage of flights have calculated times?
--   5. OOOI Detection - How well are gate/runway times captured?
-- ============================================================================

SET NOCOUNT ON;
PRINT '=============================================================================';
PRINT 'PERTI TIMING ACCURACY & CONSISTENCY ANALYSIS';
PRINT 'Generated: ' + CONVERT(VARCHAR, GETUTCDATE(), 120) + ' UTC';
PRINT '=============================================================================';
PRINT '';

-- ============================================================================
-- 1. DATASET OVERVIEW
-- ============================================================================
PRINT '--- 1. DATASET OVERVIEW ---';

SELECT
    'Total Active Flights' AS metric,
    COUNT(*) AS value
FROM dbo.adl_flight_core WHERE is_active = 1
UNION ALL
SELECT
    'Flights with ETA calculated',
    COUNT(*)
FROM dbo.adl_flight_times WHERE eta_utc IS NOT NULL
UNION ALL
SELECT
    'Flights with ETD calculated',
    COUNT(*)
FROM dbo.adl_flight_times WHERE etd_utc IS NOT NULL
UNION ALL
SELECT
    'Arrived flights (last 24h)',
    COUNT(*)
FROM dbo.adl_flight_core c
JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
WHERE c.phase = 'arrived' AND t.ata_runway_utc >= DATEADD(HOUR, -24, SYSUTCDATETIME());

PRINT '';

-- ============================================================================
-- 2. ETA COVERAGE BY PHASE
-- ============================================================================
PRINT '--- 2. ETA COVERAGE BY FLIGHT PHASE ---';

SELECT
    c.phase,
    COUNT(*) AS total_flights,
    SUM(CASE WHEN t.eta_utc IS NOT NULL THEN 1 ELSE 0 END) AS has_eta,
    SUM(CASE WHEN t.etd_utc IS NOT NULL THEN 1 ELSE 0 END) AS has_etd,
    CAST(100.0 * SUM(CASE WHEN t.eta_utc IS NOT NULL THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0) AS DECIMAL(5,1)) AS eta_pct,
    AVG(t.eta_confidence) AS avg_confidence
FROM dbo.adl_flight_core c
LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
WHERE c.is_active = 1
GROUP BY c.phase
ORDER BY total_flights DESC;

PRINT '';

-- ============================================================================
-- 3. ETA PREFIX DISTRIBUTION (Time Type)
-- ============================================================================
PRINT '--- 3. ETA PREFIX DISTRIBUTION ---';
PRINT '  A=Actual, E=Estimated, C=Controlled, P=Proposed';

SELECT
    COALESCE(t.eta_prefix, 'NULL') AS eta_prefix,
    CASE t.eta_prefix
        WHEN 'A' THEN 'Actual (arrived)'
        WHEN 'E' THEN 'Estimated (calculated)'
        WHEN 'C' THEN 'Controlled (TMI applied)'
        WHEN 'P' THEN 'Proposed (prefile)'
        ELSE 'Not set'
    END AS description,
    COUNT(*) AS flight_count,
    AVG(t.eta_confidence) AS avg_confidence
FROM dbo.adl_flight_times t
JOIN dbo.adl_flight_core c ON c.flight_uid = t.flight_uid
WHERE c.is_active = 1
GROUP BY t.eta_prefix
ORDER BY flight_count DESC;

PRINT '';

-- ============================================================================
-- 4. ETA DISTANCE SOURCE DISTRIBUTION
-- ============================================================================
PRINT '--- 4. ETA DISTANCE SOURCE (Route vs GCD) ---';

SELECT
    COALESCE(t.eta_dist_source, 'NOT_SET') AS distance_source,
    COUNT(*) AS flight_count,
    AVG(t.eta_confidence) AS avg_confidence,
    AVG(t.eta_route_dist_nm) AS avg_distance_nm
FROM dbo.adl_flight_times t
JOIN dbo.adl_flight_core c ON c.flight_uid = t.flight_uid
WHERE c.is_active = 1 AND t.eta_utc IS NOT NULL
GROUP BY t.eta_dist_source
ORDER BY flight_count DESC;

PRINT '';

-- ============================================================================
-- 5. ETA ACCURACY ANALYSIS (Predicted vs Actual)
-- ============================================================================
PRINT '--- 5. ETA ACCURACY: PREDICTED vs ACTUAL (Last 24 Hours) ---';
PRINT '  Analyzing arrived flights where we have both ETA and ATA';

-- For arrived flights, compare the final ETA prediction to actual arrival
WITH ArrivedFlights AS (
    SELECT
        c.flight_uid,
        c.callsign,
        t.eta_utc AS final_eta,
        t.ata_runway_utc AS actual_arrival,
        t.eta_confidence,
        t.eta_dist_source,
        DATEDIFF(SECOND, t.eta_utc, t.ata_runway_utc) / 60.0 AS error_minutes
    FROM dbo.adl_flight_core c
    JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
    WHERE c.phase = 'arrived'
      AND t.ata_runway_utc IS NOT NULL
      AND t.eta_utc IS NOT NULL
      AND t.ata_runway_utc >= DATEADD(HOUR, -24, SYSUTCDATETIME())
)
SELECT
    COUNT(*) AS sample_size,
    AVG(error_minutes) AS mean_error_min,
    STDEV(error_minutes) AS stddev_min,
    MIN(error_minutes) AS min_error_min,
    MAX(error_minutes) AS max_error_min,
    -- Percentiles approximation
    AVG(CASE WHEN error_minutes BETWEEN -5 AND 5 THEN 1.0 ELSE 0.0 END) * 100 AS pct_within_5min,
    AVG(CASE WHEN error_minutes BETWEEN -10 AND 10 THEN 1.0 ELSE 0.0 END) * 100 AS pct_within_10min,
    AVG(CASE WHEN error_minutes BETWEEN -15 AND 15 THEN 1.0 ELSE 0.0 END) * 100 AS pct_within_15min
FROM ArrivedFlights;

PRINT '';

-- Accuracy by distance source
PRINT '--- 5b. ETA ACCURACY BY DISTANCE SOURCE ---';

WITH ArrivedFlights AS (
    SELECT
        t.eta_dist_source,
        DATEDIFF(SECOND, t.eta_utc, t.ata_runway_utc) / 60.0 AS error_minutes
    FROM dbo.adl_flight_core c
    JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
    WHERE c.phase = 'arrived'
      AND t.ata_runway_utc IS NOT NULL
      AND t.eta_utc IS NOT NULL
      AND t.ata_runway_utc >= DATEADD(HOUR, -24, SYSUTCDATETIME())
)
SELECT
    COALESCE(eta_dist_source, 'NOT_SET') AS distance_source,
    COUNT(*) AS sample_size,
    AVG(error_minutes) AS mean_error_min,
    STDEV(error_minutes) AS stddev_min,
    AVG(CASE WHEN error_minutes BETWEEN -5 AND 5 THEN 1.0 ELSE 0.0 END) * 100 AS pct_within_5min
FROM ArrivedFlights
GROUP BY eta_dist_source
ORDER BY sample_size DESC;

PRINT '';

-- ============================================================================
-- 6. ETA UPDATE FREQUENCY ANALYSIS
-- ============================================================================
PRINT '--- 6. ETA UPDATE FREQUENCY (Active Flights) ---';

;WITH UpdateFreq AS (
    SELECT
        CASE
            WHEN DATEDIFF(SECOND, t.eta_last_calc_utc, SYSUTCDATETIME()) < 30 THEN 1
            WHEN DATEDIFF(SECOND, t.eta_last_calc_utc, SYSUTCDATETIME()) < 60 THEN 2
            WHEN DATEDIFF(SECOND, t.eta_last_calc_utc, SYSUTCDATETIME()) < 120 THEN 3
            WHEN DATEDIFF(SECOND, t.eta_last_calc_utc, SYSUTCDATETIME()) < 300 THEN 4
            WHEN DATEDIFF(SECOND, t.eta_last_calc_utc, SYSUTCDATETIME()) < 600 THEN 5
            ELSE 6
        END AS sort_order,
        CASE
            WHEN DATEDIFF(SECOND, t.eta_last_calc_utc, SYSUTCDATETIME()) < 30 THEN '< 30 sec'
            WHEN DATEDIFF(SECOND, t.eta_last_calc_utc, SYSUTCDATETIME()) < 60 THEN '30-60 sec'
            WHEN DATEDIFF(SECOND, t.eta_last_calc_utc, SYSUTCDATETIME()) < 120 THEN '1-2 min'
            WHEN DATEDIFF(SECOND, t.eta_last_calc_utc, SYSUTCDATETIME()) < 300 THEN '2-5 min'
            WHEN DATEDIFF(SECOND, t.eta_last_calc_utc, SYSUTCDATETIME()) < 600 THEN '5-10 min'
            ELSE '> 10 min'
        END AS time_since_eta_calc
    FROM dbo.adl_flight_times t
    JOIN dbo.adl_flight_core c ON c.flight_uid = t.flight_uid
    WHERE c.is_active = 1 AND t.eta_last_calc_utc IS NOT NULL
)
SELECT time_since_eta_calc, COUNT(*) AS flight_count
FROM UpdateFreq
GROUP BY sort_order, time_since_eta_calc
ORDER BY sort_order;

PRINT '';

-- ============================================================================
-- 7. ETA CONSISTENCY - CHANGELOG ANALYSIS
-- ============================================================================
PRINT '--- 7. ETA CONSISTENCY: VARIABILITY FROM CHANGELOG ---';
PRINT '  Analyzing ETA changes tracked in changelog (if available)';

-- Use changelog to track ETA changes instead of trajectory
IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_changelog') AND type = 'U')
   AND EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_changelog') AND name = 'change_utc')
BEGIN
    SELECT
        'eta_utc changes (6h)' AS metric,
        COUNT(*) AS change_count,
        COUNT(DISTINCT flight_uid) AS flights_affected
    FROM dbo.adl_flight_changelog
    WHERE change_utc >= DATEADD(HOUR, -6, SYSUTCDATETIME())
      AND field_name = 'eta_utc';
END
ELSE
BEGIN
    PRINT '  (Changelog not available or missing change_utc column - skipping)';

    -- Alternative: show when ETAs were last calculated
    SELECT
        'ETA calc timing stats' AS metric,
        MIN(DATEDIFF(SECOND, eta_last_calc_utc, SYSUTCDATETIME())) AS min_age_sec,
        AVG(DATEDIFF(SECOND, eta_last_calc_utc, SYSUTCDATETIME())) AS avg_age_sec,
        MAX(DATEDIFF(SECOND, eta_last_calc_utc, SYSUTCDATETIME())) AS max_age_sec
    FROM dbo.adl_flight_times t
    JOIN dbo.adl_flight_core c ON c.flight_uid = t.flight_uid
    WHERE c.is_active = 1 AND t.eta_last_calc_utc IS NOT NULL;
END

PRINT '';

-- ============================================================================
-- 8. OOOI TIME CAPTURE RATES
-- ============================================================================
PRINT '--- 8. OOOI TIME CAPTURE RATES (Last 24h Arrivals) ---';

SELECT
    COUNT(*) AS arrived_flights,
    SUM(CASE WHEN t.out_utc IS NOT NULL THEN 1 ELSE 0 END) AS has_out,
    SUM(CASE WHEN t.off_utc IS NOT NULL THEN 1 ELSE 0 END) AS has_off,
    SUM(CASE WHEN t.on_utc IS NOT NULL THEN 1 ELSE 0 END) AS has_on,
    SUM(CASE WHEN t.in_utc IS NOT NULL THEN 1 ELSE 0 END) AS has_in,
    -- Percentages
    CAST(100.0 * SUM(CASE WHEN t.out_utc IS NOT NULL THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0) AS DECIMAL(5,1)) AS out_pct,
    CAST(100.0 * SUM(CASE WHEN t.off_utc IS NOT NULL THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0) AS DECIMAL(5,1)) AS off_pct,
    CAST(100.0 * SUM(CASE WHEN t.on_utc IS NOT NULL THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0) AS DECIMAL(5,1)) AS on_pct,
    CAST(100.0 * SUM(CASE WHEN t.in_utc IS NOT NULL THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0) AS DECIMAL(5,1)) AS in_pct
FROM dbo.adl_flight_core c
JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
WHERE c.phase = 'arrived'
  AND t.ata_runway_utc >= DATEADD(HOUR, -24, SYSUTCDATETIME());

PRINT '';

-- ============================================================================
-- 9. ETD SOURCE DISTRIBUTION
-- ============================================================================
PRINT '--- 9. ETD SOURCE DISTRIBUTION ---';
PRINT '  D=DOF-based, P=Position-inferred, N=None';

SELECT
    COALESCE(t.etd_source, 'NULL') AS etd_source,
    CASE t.etd_source
        WHEN 'D' THEN 'DOF from flight plan (most accurate)'
        WHEN 'P' THEN 'Position-inferred (enroute estimate)'
        WHEN 'N' THEN 'No valid estimate'
        ELSE 'Not set'
    END AS description,
    COUNT(*) AS flight_count
FROM dbo.adl_flight_times t
JOIN dbo.adl_flight_core c ON c.flight_uid = t.flight_uid
WHERE c.is_active = 1
GROUP BY t.etd_source
ORDER BY flight_count DESC;

PRINT '';

-- ============================================================================
-- 10. CONFIDENCE SCORE DISTRIBUTION
-- ============================================================================
PRINT '--- 10. ETA CONFIDENCE SCORE DISTRIBUTION ---';

SELECT
    CASE
        WHEN t.eta_confidence >= 0.95 THEN '95-100% (Excellent)'
        WHEN t.eta_confidence >= 0.90 THEN '90-95% (Very Good)'
        WHEN t.eta_confidence >= 0.85 THEN '85-90% (Good)'
        WHEN t.eta_confidence >= 0.80 THEN '80-85% (Fair)'
        WHEN t.eta_confidence >= 0.70 THEN '70-80% (Moderate)'
        WHEN t.eta_confidence >= 0.65 THEN '65-70% (Low)'
        ELSE '< 65% (Very Low)'
    END AS confidence_band,
    COUNT(*) AS flight_count,
    AVG(t.eta_confidence) AS avg_confidence
FROM dbo.adl_flight_times t
JOIN dbo.adl_flight_core c ON c.flight_uid = t.flight_uid
WHERE c.is_active = 1 AND t.eta_confidence IS NOT NULL
GROUP BY
    CASE
        WHEN t.eta_confidence >= 0.95 THEN '95-100% (Excellent)'
        WHEN t.eta_confidence >= 0.90 THEN '90-95% (Very Good)'
        WHEN t.eta_confidence >= 0.85 THEN '85-90% (Good)'
        WHEN t.eta_confidence >= 0.80 THEN '80-85% (Fair)'
        WHEN t.eta_confidence >= 0.70 THEN '70-80% (Moderate)'
        WHEN t.eta_confidence >= 0.65 THEN '65-70% (Low)'
        ELSE '< 65% (Very Low)'
    END
ORDER BY avg_confidence DESC;

PRINT '';

-- ============================================================================
-- 11. CHANGELOG ANALYSIS - ETA CHANGES (if available)
-- ============================================================================
PRINT '--- 11. ETA CHANGE FREQUENCY FROM CHANGELOG (Last 6 Hours) ---';

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_changelog') AND type = 'U')
   AND EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_flight_changelog') AND name = 'change_utc')
BEGIN
    SELECT
        field_name,
        COUNT(*) AS change_count,
        COUNT(DISTINCT flight_uid) AS flights_affected
    FROM dbo.adl_flight_changelog
    WHERE change_utc >= DATEADD(HOUR, -6, SYSUTCDATETIME())
      AND field_name IN ('eta_utc', 'eta_runway_utc', 'etd_utc', 'phase', 'eta_prefix')
    GROUP BY field_name
    ORDER BY change_count DESC;
END
ELSE
BEGIN
    PRINT '  (Changelog table not found or schema mismatch - skipping)';
END

PRINT '';

-- ============================================================================
-- 12. SAMPLE FLIGHT TIMING DETAIL
-- ============================================================================
PRINT '--- 12. SAMPLE ARRIVED FLIGHT TIMING DETAIL (Last 10) ---';

SELECT TOP 10
    c.callsign,
    c.phase,
    fp.fp_dept_icao AS origin,
    fp.fp_dest_icao AS dest,
    t.etd_utc,
    t.out_utc,
    t.off_utc,
    t.eta_utc AS final_eta,
    t.on_utc,
    t.in_utc,
    t.ata_runway_utc AS actual_arrival,
    DATEDIFF(MINUTE, t.eta_utc, t.ata_runway_utc) AS eta_error_min,
    t.eta_confidence,
    t.eta_prefix,
    t.eta_dist_source
FROM dbo.adl_flight_core c
JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
WHERE c.phase = 'arrived'
  AND t.ata_runway_utc IS NOT NULL
  AND t.ata_runway_utc >= DATEADD(HOUR, -24, SYSUTCDATETIME())
ORDER BY t.ata_runway_utc DESC;

PRINT '';
PRINT '=============================================================================';
PRINT 'ANALYSIS COMPLETE';
PRINT '=============================================================================';
