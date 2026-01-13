-- ============================================================================
-- ETA Accuracy Report
-- Shows prediction accuracy statistics for arrived flights
-- Run against PERTI database to see current accuracy metrics
-- ============================================================================

PRINT '============================================================';
PRINT '          ETA ACCURACY REPORT - ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '============================================================';
PRINT '';

-- ============================================================================
-- 1. DATASET OVERVIEW
-- ============================================================================
PRINT '--- 1. DATASET OVERVIEW ---';

SELECT
    'Active Flights' AS metric,
    COUNT(*) AS value
FROM dbo.adl_flight_core WHERE is_active = 1
UNION ALL
SELECT
    'With ETA',
    COUNT(*)
FROM dbo.adl_flight_core c
JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
WHERE c.is_active = 1 AND t.eta_utc IS NOT NULL
UNION ALL
SELECT
    'Arrived (24h)',
    COUNT(*)
FROM dbo.adl_flight_core c
JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
WHERE c.phase = 'arrived'
  AND t.ata_utc >= DATEADD(HOUR, -24, SYSUTCDATETIME())
UNION ALL
SELECT
    'With ETA+ATA (analyzable)',
    COUNT(*)
FROM dbo.adl_flight_core c
JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
WHERE c.phase = 'arrived'
  AND t.ata_utc >= DATEADD(HOUR, -24, SYSUTCDATETIME())
  AND t.eta_utc IS NOT NULL;

-- ============================================================================
-- 2. OVERALL ETA ACCURACY (24-hour window)
-- ============================================================================
PRINT '';
PRINT '--- 2. OVERALL ETA ACCURACY (Last 24 Hours) ---';

WITH ArrivedFlights AS (
    SELECT
        DATEDIFF(SECOND, t.eta_utc, t.ata_utc) / 60.0 AS error_minutes,
        t.eta_dist_source,
        t.eta_confidence,
        t.eta_method
    FROM dbo.adl_flight_core c
    JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
    WHERE c.phase = 'arrived'
      AND t.ata_utc IS NOT NULL
      AND t.eta_utc IS NOT NULL
      AND t.ata_utc >= DATEADD(HOUR, -24, SYSUTCDATETIME())
)
SELECT
    COUNT(*) AS sample_size,
    CAST(AVG(error_minutes) AS DECIMAL(6,2)) AS mean_error_min,
    CAST(STDEV(error_minutes) AS DECIMAL(6,2)) AS stddev_min,
    CAST(MIN(error_minutes) AS DECIMAL(6,2)) AS min_error_min,
    CAST(MAX(error_minutes) AS DECIMAL(6,2)) AS max_error_min,
    CAST(AVG(CASE WHEN ABS(error_minutes) <= 5 THEN 100.0 ELSE 0.0 END) AS DECIMAL(5,1)) AS pct_within_5min,
    CAST(AVG(CASE WHEN ABS(error_minutes) <= 10 THEN 100.0 ELSE 0.0 END) AS DECIMAL(5,1)) AS pct_within_10min,
    CAST(AVG(CASE WHEN ABS(error_minutes) <= 15 THEN 100.0 ELSE 0.0 END) AS DECIMAL(5,1)) AS pct_within_15min
FROM ArrivedFlights;

-- ============================================================================
-- 3. ACCURACY BY DISTANCE SOURCE (ROUTE vs GCD)
-- ============================================================================
PRINT '';
PRINT '--- 3. ACCURACY BY DISTANCE SOURCE ---';

WITH ArrivedFlights AS (
    SELECT
        COALESCE(t.eta_dist_source, 'NOT_SET') AS dist_source,
        DATEDIFF(SECOND, t.eta_utc, t.ata_utc) / 60.0 AS error_minutes
    FROM dbo.adl_flight_core c
    JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
    WHERE c.phase = 'arrived'
      AND t.ata_utc IS NOT NULL
      AND t.eta_utc IS NOT NULL
      AND t.ata_utc >= DATEADD(HOUR, -24, SYSUTCDATETIME())
)
SELECT
    dist_source,
    COUNT(*) AS sample_size,
    CAST(AVG(error_minutes) AS DECIMAL(6,2)) AS mean_error_min,
    CAST(STDEV(error_minutes) AS DECIMAL(6,2)) AS stddev_min,
    CAST(AVG(CASE WHEN ABS(error_minutes) <= 5 THEN 100.0 ELSE 0.0 END) AS DECIMAL(5,1)) AS pct_within_5min,
    CAST(AVG(CASE WHEN ABS(error_minutes) <= 10 THEN 100.0 ELSE 0.0 END) AS DECIMAL(5,1)) AS pct_within_10min
FROM ArrivedFlights
GROUP BY dist_source
ORDER BY sample_size DESC;

-- ============================================================================
-- 4. ACCURACY BY ETA METHOD
-- ============================================================================
PRINT '';
PRINT '--- 4. ACCURACY BY ETA METHOD ---';

WITH ArrivedFlights AS (
    SELECT
        COALESCE(t.eta_method, 'UNKNOWN') AS eta_method,
        DATEDIFF(SECOND, t.eta_utc, t.ata_utc) / 60.0 AS error_minutes
    FROM dbo.adl_flight_core c
    JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
    WHERE c.phase = 'arrived'
      AND t.ata_utc IS NOT NULL
      AND t.eta_utc IS NOT NULL
      AND t.ata_utc >= DATEADD(HOUR, -24, SYSUTCDATETIME())
)
SELECT
    eta_method,
    COUNT(*) AS sample_size,
    CAST(AVG(error_minutes) AS DECIMAL(6,2)) AS mean_error_min,
    CAST(STDEV(error_minutes) AS DECIMAL(6,2)) AS stddev_min,
    CAST(AVG(CASE WHEN ABS(error_minutes) <= 5 THEN 100.0 ELSE 0.0 END) AS DECIMAL(5,1)) AS pct_within_5min
FROM ArrivedFlights
GROUP BY eta_method
ORDER BY sample_size DESC;

-- ============================================================================
-- 5. ACCURACY BY CONFIDENCE BAND
-- ============================================================================
PRINT '';
PRINT '--- 5. ACCURACY BY CONFIDENCE BAND ---';

WITH ArrivedFlights AS (
    SELECT
        t.eta_confidence,
        ABS(DATEDIFF(SECOND, t.eta_utc, t.ata_utc) / 60.0) AS abs_error_minutes
    FROM dbo.adl_flight_core c
    JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
    WHERE c.phase = 'arrived'
      AND t.ata_utc IS NOT NULL
      AND t.eta_utc IS NOT NULL
      AND t.eta_confidence IS NOT NULL
      AND t.ata_utc >= DATEADD(HOUR, -24, SYSUTCDATETIME())
)
SELECT
    CASE
        WHEN eta_confidence >= 0.95 THEN '95-100%'
        WHEN eta_confidence >= 0.90 THEN '90-95%'
        WHEN eta_confidence >= 0.85 THEN '85-90%'
        WHEN eta_confidence >= 0.75 THEN '75-85%'
        ELSE '<75%'
    END AS confidence_band,
    COUNT(*) AS sample_size,
    CAST(AVG(eta_confidence) * 100 AS DECIMAL(5,1)) AS avg_confidence_pct,
    CAST(AVG(abs_error_minutes) AS DECIMAL(6,2)) AS mean_abs_error_min,
    CAST(AVG(CASE WHEN abs_error_minutes <= 5 THEN 100.0 ELSE 0.0 END) AS DECIMAL(5,1)) AS pct_within_5min
FROM ArrivedFlights
GROUP BY CASE
    WHEN eta_confidence >= 0.95 THEN '95-100%'
    WHEN eta_confidence >= 0.90 THEN '90-95%'
    WHEN eta_confidence >= 0.85 THEN '85-90%'
    WHEN eta_confidence >= 0.75 THEN '75-85%'
    ELSE '<75%'
END
ORDER BY MIN(eta_confidence) DESC;

-- ============================================================================
-- 6. ETA COVERAGE BY PHASE
-- ============================================================================
PRINT '';
PRINT '--- 6. ETA COVERAGE BY PHASE ---';

SELECT
    c.phase,
    COUNT(*) AS total_flights,
    SUM(CASE WHEN t.eta_utc IS NOT NULL THEN 1 ELSE 0 END) AS with_eta,
    CAST(SUM(CASE WHEN t.eta_utc IS NOT NULL THEN 100.0 ELSE 0.0 END) / NULLIF(COUNT(*), 0) AS DECIMAL(5,1)) AS eta_coverage_pct,
    SUM(CASE WHEN t.eta_method IS NOT NULL THEN 1 ELSE 0 END) AS with_method
FROM dbo.adl_flight_core c
LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
WHERE c.is_active = 1
GROUP BY c.phase
ORDER BY
    CASE c.phase
        WHEN 'prefile' THEN 1
        WHEN 'filed' THEN 2
        WHEN 'departing' THEN 3
        WHEN 'enroute' THEN 4
        WHEN 'descending' THEN 5
        WHEN 'arrived' THEN 6
        ELSE 7
    END;

-- ============================================================================
-- 7. ETA METHOD DISTRIBUTION (Active Flights)
-- ============================================================================
PRINT '';
PRINT '--- 7. ETA METHOD DISTRIBUTION (Active Flights) ---';

SELECT
    COALESCE(t.eta_method, 'NO_ETA') AS eta_method,
    COUNT(*) AS flight_count,
    CAST(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER() AS DECIMAL(5,1)) AS pct_of_total
FROM dbo.adl_flight_core c
LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
WHERE c.is_active = 1
GROUP BY t.eta_method
ORDER BY flight_count DESC;

-- ============================================================================
-- 8. SAMPLE RECENT ARRIVALS WITH ACCURACY
-- ============================================================================
PRINT '';
PRINT '--- 8. SAMPLE RECENT ARRIVALS (Last 10) ---';

SELECT TOP 10
    c.callsign,
    fp.fp_dest_icao AS dest,
    t.eta_utc,
    t.ata_utc,
    DATEDIFF(MINUTE, t.eta_utc, t.ata_utc) AS error_min,
    CAST(t.eta_confidence * 100 AS INT) AS conf_pct,
    t.eta_method,
    t.eta_dist_source AS dist_src
FROM dbo.adl_flight_core c
JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
WHERE c.phase = 'arrived'
  AND t.ata_utc IS NOT NULL
  AND t.eta_utc IS NOT NULL
ORDER BY t.ata_utc DESC;

-- ============================================================================
-- 9. ETA PREDICTION BIAS (Early vs Late)
-- ============================================================================
PRINT '';
PRINT '--- 9. ETA PREDICTION BIAS ---';

WITH ArrivedFlights AS (
    SELECT
        DATEDIFF(SECOND, t.eta_utc, t.ata_utc) / 60.0 AS error_minutes
    FROM dbo.adl_flight_core c
    JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
    WHERE c.phase = 'arrived'
      AND t.ata_utc IS NOT NULL
      AND t.eta_utc IS NOT NULL
      AND t.ata_utc >= DATEADD(HOUR, -24, SYSUTCDATETIME())
)
SELECT
    'Predictions Early (arrived after ETA)' AS bias_type,
    COUNT(*) AS count,
    CAST(AVG(error_minutes) AS DECIMAL(6,2)) AS avg_minutes
FROM ArrivedFlights WHERE error_minutes > 0
UNION ALL
SELECT
    'Predictions Late (arrived before ETA)',
    COUNT(*),
    CAST(AVG(error_minutes) AS DECIMAL(6,2))
FROM ArrivedFlights WHERE error_minutes < 0
UNION ALL
SELECT
    'Exact (within 1 min)',
    COUNT(*),
    0
FROM ArrivedFlights WHERE ABS(error_minutes) <= 1;

PRINT '';
PRINT '============================================================';
PRINT '                    END OF REPORT';
PRINT '============================================================';
