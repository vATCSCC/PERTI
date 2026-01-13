-- ============================================================================
-- Backfill ata_utc for existing arrived flights
--
-- Run ONCE after deploying V8.9.8 to populate historical arrival times
-- This enables immediate accuracy analysis of recent flights
-- ============================================================================

PRINT '=== Backfill ata_utc for Arrived Flights ===';
PRINT 'Started: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '';

-- Check current state
PRINT '--- Before Backfill ---';
SELECT
    'Arrived flights' AS metric,
    COUNT(*) AS value
FROM dbo.adl_flight_core WHERE phase = 'arrived'
UNION ALL
SELECT
    'With ata_utc',
    COUNT(*)
FROM dbo.adl_flight_core c
JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
WHERE c.phase = 'arrived' AND t.ata_utc IS NOT NULL
UNION ALL
SELECT
    'Without ata_utc',
    COUNT(*)
FROM dbo.adl_flight_core c
JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
WHERE c.phase = 'arrived' AND t.ata_utc IS NULL;

-- Perform backfill
PRINT '';
PRINT '--- Performing Backfill ---';

UPDATE t
SET t.ata_utc = c.last_seen_utc,
    t.ata_runway_utc = c.last_seen_utc,
    t.eta_prefix = 'A',
    t.times_updated_utc = SYSUTCDATETIME()
FROM dbo.adl_flight_times t
INNER JOIN dbo.adl_flight_core c ON c.flight_uid = t.flight_uid
WHERE c.phase = 'arrived'
  AND t.ata_utc IS NULL
  AND c.last_seen_utc IS NOT NULL;

PRINT 'Rows backfilled: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- Verify
PRINT '';
PRINT '--- After Backfill ---';
SELECT
    'Arrived flights' AS metric,
    COUNT(*) AS value
FROM dbo.adl_flight_core WHERE phase = 'arrived'
UNION ALL
SELECT
    'With ata_utc',
    COUNT(*)
FROM dbo.adl_flight_core c
JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
WHERE c.phase = 'arrived' AND t.ata_utc IS NOT NULL
UNION ALL
SELECT
    'Without ata_utc',
    COUNT(*)
FROM dbo.adl_flight_core c
JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
WHERE c.phase = 'arrived' AND t.ata_utc IS NULL;

-- Quick accuracy check
PRINT '';
PRINT '--- Immediate Accuracy Check ---';
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
    COUNT(*) AS sample_size,
    CAST(AVG(error_minutes) AS DECIMAL(6,2)) AS mean_error_min,
    CAST(STDEV(error_minutes) AS DECIMAL(6,2)) AS stddev_min,
    CAST(AVG(CASE WHEN ABS(error_minutes) <= 5 THEN 100.0 ELSE 0.0 END) AS DECIMAL(5,1)) AS pct_within_5min,
    CAST(AVG(CASE WHEN ABS(error_minutes) <= 10 THEN 100.0 ELSE 0.0 END) AS DECIMAL(5,1)) AS pct_within_10min
FROM ArrivedFlights;

PRINT '';
PRINT '=== Backfill Complete ===';
