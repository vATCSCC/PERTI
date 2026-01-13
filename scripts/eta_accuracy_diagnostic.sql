-- ============================================================================
-- ETA Accuracy Diagnostic
-- Analyze why ETAs have a -60 minute bias (flights arriving early)
-- ============================================================================

PRINT '=== ETA ACCURACY DIAGNOSTIC ===';
PRINT 'Investigating systematic late prediction bias';
PRINT '';

-- ============================================================================
-- 1. ACCURACY BY RECENCY (are recent calculations better?)
-- ============================================================================
PRINT '--- 1. Accuracy by Data Recency ---';
PRINT 'Comparing old vs recent arrivals to see if new ETA code is better';
PRINT '';

WITH ArrivedFlights AS (
    SELECT
        DATEDIFF(SECOND, t.eta_utc, t.ata_utc) / 60.0 AS error_minutes,
        t.eta_method,
        t.eta_dist_source,
        t.ata_utc,
        DATEDIFF(DAY, t.ata_utc, SYSUTCDATETIME()) AS days_ago
    FROM dbo.adl_flight_core c
    JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
    WHERE c.phase = 'arrived'
      AND t.ata_utc IS NOT NULL
      AND t.eta_utc IS NOT NULL
)
SELECT
    CASE
        WHEN days_ago <= 1 THEN 'Last 24 hours'
        WHEN days_ago <= 7 THEN 'Last week'
        WHEN days_ago <= 30 THEN 'Last month'
        ELSE 'Older than 30 days'
    END AS period,
    COUNT(*) AS sample_size,
    CAST(AVG(error_minutes) AS DECIMAL(8,2)) AS mean_error_min,
    CAST(STDEV(error_minutes) AS DECIMAL(8,2)) AS stddev_min,
    CAST(AVG(CASE WHEN ABS(error_minutes) <= 5 THEN 100.0 ELSE 0.0 END) AS DECIMAL(5,1)) AS pct_within_5min,
    CAST(AVG(CASE WHEN ABS(error_minutes) <= 10 THEN 100.0 ELSE 0.0 END) AS DECIMAL(5,1)) AS pct_within_10min
FROM ArrivedFlights
GROUP BY CASE
    WHEN days_ago <= 1 THEN 'Last 24 hours'
    WHEN days_ago <= 7 THEN 'Last week'
    WHEN days_ago <= 30 THEN 'Last month'
    ELSE 'Older than 30 days'
END
ORDER BY MIN(days_ago);

-- ============================================================================
-- 2. ACCURACY BY ETA METHOD
-- ============================================================================
PRINT '';
PRINT '--- 2. Accuracy by ETA Method ---';
PRINT 'V3_ROUTE should be better than V3 (GCD-based)';
PRINT '';

WITH ArrivedFlights AS (
    SELECT
        DATEDIFF(SECOND, t.eta_utc, t.ata_utc) / 60.0 AS error_minutes,
        COALESCE(t.eta_method, 'NO_METHOD') AS eta_method
    FROM dbo.adl_flight_core c
    JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
    WHERE c.phase = 'arrived'
      AND t.ata_utc IS NOT NULL
      AND t.eta_utc IS NOT NULL
)
SELECT
    eta_method,
    COUNT(*) AS sample_size,
    CAST(AVG(error_minutes) AS DECIMAL(8,2)) AS mean_error_min,
    CAST(STDEV(error_minutes) AS DECIMAL(8,2)) AS stddev_min,
    CAST(AVG(CASE WHEN ABS(error_minutes) <= 5 THEN 100.0 ELSE 0.0 END) AS DECIMAL(5,1)) AS pct_within_5min,
    CAST(AVG(CASE WHEN ABS(error_minutes) <= 10 THEN 100.0 ELSE 0.0 END) AS DECIMAL(5,1)) AS pct_within_10min
FROM ArrivedFlights
GROUP BY eta_method
ORDER BY sample_size DESC;

-- ============================================================================
-- 3. ERROR DISTRIBUTION
-- ============================================================================
PRINT '';
PRINT '--- 3. Error Distribution ---';
PRINT 'Understanding the spread of prediction errors';
PRINT '';

WITH ArrivedFlights AS (
    SELECT
        DATEDIFF(SECOND, t.eta_utc, t.ata_utc) / 60.0 AS error_minutes
    FROM dbo.adl_flight_core c
    JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
    WHERE c.phase = 'arrived'
      AND t.ata_utc IS NOT NULL
      AND t.eta_utc IS NOT NULL
)
SELECT
    CASE
        WHEN error_minutes < -120 THEN 'Very early (>2h before ETA)'
        WHEN error_minutes < -60 THEN 'Early (1-2h before ETA)'
        WHEN error_minutes < -30 THEN 'Slightly early (30-60min)'
        WHEN error_minutes < -10 THEN 'Within 10-30 min early'
        WHEN error_minutes <= 10 THEN 'Within 10 min (good)'
        WHEN error_minutes <= 30 THEN 'Within 10-30 min late'
        WHEN error_minutes <= 60 THEN 'Slightly late (30-60min)'
        WHEN error_minutes <= 120 THEN 'Late (1-2h after ETA)'
        ELSE 'Very late (>2h after ETA)'
    END AS error_bucket,
    COUNT(*) AS flight_count,
    CAST(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER() AS DECIMAL(5,1)) AS pct_of_total
FROM ArrivedFlights
GROUP BY CASE
    WHEN error_minutes < -120 THEN 'Very early (>2h before ETA)'
    WHEN error_minutes < -60 THEN 'Early (1-2h before ETA)'
    WHEN error_minutes < -30 THEN 'Slightly early (30-60min)'
    WHEN error_minutes < -10 THEN 'Within 10-30 min early'
    WHEN error_minutes <= 10 THEN 'Within 10 min (good)'
    WHEN error_minutes <= 30 THEN 'Within 10-30 min late'
    WHEN error_minutes <= 60 THEN 'Slightly late (30-60min)'
    WHEN error_minutes <= 120 THEN 'Late (1-2h after ETA)'
    ELSE 'Very late (>2h after ETA)'
END
ORDER BY MIN(error_minutes);

-- ============================================================================
-- 4. RECENT FLIGHTS ONLY (Last 24 hours with V3 methods)
-- ============================================================================
PRINT '';
PRINT '--- 4. Recent V3 Method Accuracy (Last 24h) ---';
PRINT 'This is the most relevant for current system performance';
PRINT '';

WITH RecentFlights AS (
    SELECT
        DATEDIFF(SECOND, t.eta_utc, t.ata_utc) / 60.0 AS error_minutes,
        t.eta_method,
        t.eta_dist_source
    FROM dbo.adl_flight_core c
    JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
    WHERE c.phase = 'arrived'
      AND t.ata_utc IS NOT NULL
      AND t.eta_utc IS NOT NULL
      AND t.ata_utc >= DATEADD(HOUR, -24, SYSUTCDATETIME())
      AND t.eta_method LIKE 'V3%'
)
SELECT
    eta_method,
    eta_dist_source,
    COUNT(*) AS sample_size,
    CAST(AVG(error_minutes) AS DECIMAL(8,2)) AS mean_error_min,
    CAST(STDEV(error_minutes) AS DECIMAL(8,2)) AS stddev_min,
    CAST(AVG(CASE WHEN ABS(error_minutes) <= 5 THEN 100.0 ELSE 0.0 END) AS DECIMAL(5,1)) AS pct_within_5min
FROM RecentFlights
GROUP BY eta_method, eta_dist_source
ORDER BY sample_size DESC;

-- ============================================================================
-- 5. SAMPLE OUTLIERS
-- ============================================================================
PRINT '';
PRINT '--- 5. Sample Large Error Flights (for investigation) ---';
PRINT '';

SELECT TOP 20
    c.callsign,
    fp.fp_dept_icao AS orig,
    fp.fp_dest_icao AS dest,
    fp.gcd_nm,
    fp.route_total_nm,
    t.eta_utc,
    t.ata_utc,
    DATEDIFF(MINUTE, t.eta_utc, t.ata_utc) AS error_min,
    t.eta_method,
    t.eta_dist_source,
    t.eta_confidence
FROM dbo.adl_flight_core c
JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
WHERE c.phase = 'arrived'
  AND t.ata_utc IS NOT NULL
  AND t.eta_utc IS NOT NULL
  AND ABS(DATEDIFF(MINUTE, t.eta_utc, t.ata_utc)) > 60  -- More than 1 hour off
  AND t.ata_utc >= DATEADD(DAY, -7, SYSUTCDATETIME())  -- Recent
ORDER BY ABS(DATEDIFF(MINUTE, t.eta_utc, t.ata_utc)) DESC;

-- ============================================================================
-- 6. CHECK FOR STALE ETAs
-- ============================================================================
PRINT '';
PRINT '--- 6. ETA Staleness Check ---';
PRINT 'Were ETAs updated recently before arrival, or were they stale?';
PRINT '';

WITH ArrivedFlights AS (
    SELECT
        DATEDIFF(MINUTE, t.eta_last_calc_utc, t.ata_utc) AS eta_age_at_arrival_min,
        DATEDIFF(SECOND, t.eta_utc, t.ata_utc) / 60.0 AS error_minutes
    FROM dbo.adl_flight_core c
    JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
    WHERE c.phase = 'arrived'
      AND t.ata_utc IS NOT NULL
      AND t.eta_utc IS NOT NULL
      AND t.eta_last_calc_utc IS NOT NULL
      AND t.ata_utc >= DATEADD(DAY, -7, SYSUTCDATETIME())
)
SELECT
    CASE
        WHEN eta_age_at_arrival_min < 5 THEN 'Fresh (<5 min old)'
        WHEN eta_age_at_arrival_min < 15 THEN 'Recent (5-15 min)'
        WHEN eta_age_at_arrival_min < 60 THEN 'Aging (15-60 min)'
        ELSE 'Stale (>1 hour)'
    END AS eta_freshness,
    COUNT(*) AS sample_size,
    CAST(AVG(error_minutes) AS DECIMAL(8,2)) AS mean_error_min,
    CAST(AVG(CASE WHEN ABS(error_minutes) <= 10 THEN 100.0 ELSE 0.0 END) AS DECIMAL(5,1)) AS pct_within_10min
FROM ArrivedFlights
GROUP BY CASE
    WHEN eta_age_at_arrival_min < 5 THEN 'Fresh (<5 min old)'
    WHEN eta_age_at_arrival_min < 15 THEN 'Recent (5-15 min)'
    WHEN eta_age_at_arrival_min < 60 THEN 'Aging (15-60 min)'
    ELSE 'Stale (>1 hour)'
END
ORDER BY MIN(eta_age_at_arrival_min);

PRINT '';
PRINT '=== END DIAGNOSTIC ===';
