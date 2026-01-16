-- ============================================================================
-- MIGRATE: Legacy Trajectory Data - PARALLEL VERSION
--
-- Run this script in 4 separate SSMS query windows simultaneously.
-- Change @partition to 0, 1, 2, or 3 in each window before running.
--
-- This divides the remaining work by flight_uid % 4, so each window
-- handles 25% of the flights with no overlap.
-- ============================================================================

SET NOCOUNT ON;

-- *** CHANGE THIS VALUE: 0, 1, 2, or 3 for each window ***
DECLARE @partition INT = 0;

DECLARE @batch_size INT = 1000;
DECLARE @offset INT = 0;
DECLARE @total_inserted BIGINT = 0;
DECLARE @rows_inserted INT = 1;

PRINT '=== Trajectory Migration - Partition ' + CAST(@partition AS VARCHAR) + ' of 4 ==='
PRINT 'Started: ' + CONVERT(VARCHAR, GETUTCDATE(), 120)
PRINT ''

-- Get only flights for this partition that haven't been migrated yet
IF OBJECT_ID('tempdb..#my_flights') IS NOT NULL DROP TABLE #my_flights;

SELECT fm.flight_uid, fm.flight_key, ROW_NUMBER() OVER (ORDER BY fm.flight_uid) as rn
INTO #my_flights
FROM dbo.adl_flight_core fm
WHERE fm.last_source = 'legacy'
  AND fm.flight_uid % 4 = @partition
  AND NOT EXISTS (
      SELECT 1 FROM dbo.adl_flight_trajectory t
      WHERE t.flight_uid = fm.flight_uid AND t.source = 'legacy'
  );

CREATE INDEX IX_rn ON #my_flights(rn);

DECLARE @todo INT = (SELECT COUNT(*) FROM #my_flights);
PRINT 'Partition ' + CAST(@partition AS VARCHAR) + ': ' + FORMAT(@todo, 'N0') + ' flights remaining to process';
PRINT ''

IF @todo = 0
BEGIN
    PRINT 'No flights to process for this partition!'
    RETURN;
END

WHILE @rows_inserted > 0
BEGIN
    ;WITH FlightBatch AS (
        SELECT flight_uid, flight_key
        FROM #my_flights
        WHERE rn > @offset AND rn <= @offset + @batch_size
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
            CASE
                WHEN h.groundspeed > 40 AND h.altitude < 500 THEN 0
                WHEN h.phase IN ('departed', 'climbing', 'descending') THEN 1
                WHEN h.groundspeed BETWEEN 5 AND 35 AND h.altitude < 500 THEN 3
                WHEN h.altitude > 20000 THEN 4
                ELSE 2
            END AS calc_tier,
            ROW_NUMBER() OVER (PARTITION BY fb.flight_uid ORDER BY h.snapshot_utc) AS pos_seq
        FROM dbo.adl_flights_history h
        INNER JOIN FlightBatch fb ON fb.flight_key = h.flight_key
        WHERE h.lat IS NOT NULL AND h.lon IS NOT NULL
    )
    INSERT INTO dbo.adl_flight_trajectory (
        flight_uid, recorded_utc, lat, lon, altitude_ft, groundspeed_kts,
        vertical_rate_fpm, heading_deg, track_deg, source, tier, tier_reason, flight_phase
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
    WHERE (calc_tier <= 2)
       OR (calc_tier = 3 AND pos_seq % 2 = 0)
       OR (calc_tier = 4 AND pos_seq % 5 = 0);

    SET @rows_inserted = @@ROWCOUNT;
    SET @total_inserted = @total_inserted + @rows_inserted;
    SET @offset = @offset + @batch_size;

    -- Progress update every 5000 flights
    IF @offset % 5000 = 0
        PRINT 'P' + CAST(@partition AS VARCHAR) + ' Progress: ' +
              FORMAT(@offset, 'N0') + '/' + FORMAT(@todo, 'N0') + ' flights (' +
              CAST(CAST(@offset * 100.0 / @todo AS INT) AS VARCHAR) + '%), ' +
              FORMAT(@total_inserted, 'N0') + ' points';

    -- Check if we've processed all flights
    IF @offset >= @todo
        SET @rows_inserted = 0;
END

PRINT ''
PRINT '=== Partition ' + CAST(@partition AS VARCHAR) + ' Complete ==='
PRINT 'Total trajectory points inserted: ' + FORMAT(@total_inserted, 'N0')
PRINT 'Completed: ' + CONVERT(VARCHAR, GETUTCDATE(), 120)

DROP TABLE #my_flights;
GO
