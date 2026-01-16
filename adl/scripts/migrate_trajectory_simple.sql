-- ============================================================================
-- MIGRATE: Legacy Trajectory Data - Simple Approach
--
-- Samples legacy positions using ROW_NUMBER with tier-based modulo
-- Much simpler and more reliable than the complex date-bucket approach
--
-- Tier sampling rates (matching current system):
-- Tier 0: Every 15s = keep every row (legacy data ~1min intervals)
-- Tier 1: Every 30s = keep every row
-- Tier 2: Every 1min = keep every row
-- Tier 3: Every 2min = keep every 2nd row
-- Tier 4: Every 5min = keep every 5th row
-- ============================================================================

SET NOCOUNT ON;

PRINT '=== Trajectory Migration - Simple Sampled Approach ==='
PRINT 'Started: ' + CONVERT(VARCHAR, GETUTCDATE(), 120)
PRINT ''

-- Create the mapping table if not exists
IF OBJECT_ID('tempdb..#flight_map') IS NOT NULL DROP TABLE #flight_map;

SELECT flight_key, flight_uid
INTO #flight_map
FROM dbo.adl_flight_core
WHERE last_source = 'legacy';

CREATE UNIQUE INDEX IX_fm ON #flight_map(flight_key);

DECLARE @mapped INT;
SELECT @mapped = COUNT(*) FROM #flight_map;
PRINT 'Legacy flights to process: ' + CAST(@mapped AS VARCHAR);
PRINT ''

-- Process in chunks by flight to avoid memory issues
DECLARE @batch_size INT = 1000;  -- Flights per batch
DECLARE @offset INT = 0;
DECLARE @total_inserted BIGINT = 0;
DECLARE @batch_num INT = 0;
DECLARE @rows_inserted INT = 1;

WHILE @rows_inserted > 0
BEGIN
    SET @batch_num = @batch_num + 1;

    ;WITH FlightBatch AS (
        SELECT flight_uid, flight_key
        FROM #flight_map
        ORDER BY flight_uid
        OFFSET @offset ROWS FETCH NEXT @batch_size ROWS ONLY
    ),
    RankedPos AS (
        SELECT
            fb.flight_uid,
            h.snapshot_utc AS recorded_utc,
            h.lat,
            h.lon,
            h.altitude AS altitude_ft,
            h.groundspeed AS groundspeed_kts,
            NULL AS vertical_rate_fpm,
            h.heading_deg,
            h.phase AS flight_phase,
            -- Calculate tier based on position data
            CASE
                WHEN h.groundspeed > 40 AND h.altitude < 500 THEN 0
                WHEN h.phase IN ('departed', 'climbing', 'descending') THEN 1
                WHEN h.groundspeed BETWEEN 5 AND 35 AND h.altitude < 500 THEN 3
                WHEN h.altitude > 20000 THEN 4
                ELSE 2
            END AS calc_tier,
            ROW_NUMBER() OVER (
                PARTITION BY fb.flight_uid
                ORDER BY h.snapshot_utc
            ) AS pos_seq
        FROM dbo.adl_flights_history h
        INNER JOIN FlightBatch fb ON fb.flight_key = h.flight_key
        WHERE h.lat IS NOT NULL AND h.lon IS NOT NULL
    )
    INSERT INTO dbo.adl_flight_trajectory (
        flight_uid, recorded_utc, lat, lon, altitude_ft, groundspeed_kts,
        vertical_rate_fpm, heading_deg, track_deg, source,
        tier, tier_reason, flight_phase
    )
    SELECT
        flight_uid,
        recorded_utc,
        lat,
        lon,
        altitude_ft,
        groundspeed_kts,
        vertical_rate_fpm,
        heading_deg,
        NULL AS track_deg,
        'legacy' AS source,
        calc_tier AS tier,
        CASE calc_tier
            WHEN 0 THEN 'LEGACY_CRITICAL'
            WHEN 1 THEN 'LEGACY_TRANSITION'
            WHEN 2 THEN 'LEGACY_STANDARD'
            WHEN 3 THEN 'LEGACY_GROUND'
            WHEN 4 THEN 'LEGACY_CRUISE'
            ELSE 'LEGACY_UNKNOWN'
        END AS tier_reason,
        flight_phase
    FROM RankedPos
    WHERE
        -- Sample based on tier (legacy data is ~1 min intervals)
        (calc_tier <= 2)  -- Keep all for tiers 0-2
        OR (calc_tier = 3 AND pos_seq % 2 = 0)  -- Every 2nd for tier 3
        OR (calc_tier = 4 AND pos_seq % 5 = 0); -- Every 5th for tier 4

    SET @rows_inserted = @@ROWCOUNT;
    SET @total_inserted = @total_inserted + @rows_inserted;
    SET @offset = @offset + @batch_size;

    IF @batch_num % 50 = 0 OR @rows_inserted = 0
    BEGIN
        PRINT 'Batch ' + CAST(@batch_num AS VARCHAR) +
              ': +' + FORMAT(@rows_inserted, 'N0') +
              ' points (Total: ' + FORMAT(@total_inserted, 'N0') +
              ', Flights: ' + FORMAT(@offset, 'N0') + ')';
    END

    -- Stop if we've processed all flights
    IF NOT EXISTS (
        SELECT 1 FROM #flight_map
        ORDER BY flight_uid
        OFFSET @offset ROWS FETCH NEXT 1 ROWS ONLY
    )
        SET @rows_inserted = 0;
END

PRINT ''
PRINT '=== Migration Complete ==='
PRINT 'Total trajectory points inserted: ' + FORMAT(@total_inserted, 'N0')
PRINT 'Completed: ' + CONVERT(VARCHAR, GETUTCDATE(), 120)

-- Show tier distribution
PRINT ''
PRINT '--- Tier Distribution ---'
SELECT tier, tier_reason, COUNT(*) AS points
FROM dbo.adl_flight_trajectory
WHERE source = 'legacy'
GROUP BY tier, tier_reason
ORDER BY tier;

DROP TABLE #flight_map;
GO
