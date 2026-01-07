-- ============================================================================
-- Backfill: 24 hours of 1-minute phase snapshots
-- Uses OOOI timestamps to reconstruct historical phases
-- Run this once to populate historical data
-- ============================================================================

SET NOCOUNT ON;

PRINT '============================================================================';
PRINT 'Backfilling 24 hours of 1-minute phase snapshots...';
PRINT 'Using OOOI timestamps to reconstruct historical flight phases';
PRINT '============================================================================';

-- Clear existing data to start fresh
TRUNCATE TABLE dbo.flight_phase_snapshot;
PRINT 'Cleared existing snapshot data';

DECLARE @start_time DATETIME2(0) = DATEADD(HOUR, -24, SYSUTCDATETIME());
DECLARE @end_time DATETIME2(0) = SYSUTCDATETIME();
DECLARE @current_time DATETIME2(0) = @start_time;
DECLARE @backfill_count INT = 0;

PRINT CONCAT('Start: ', @start_time);
PRINT CONCAT('End: ', @end_time);
PRINT '';

-- Loop through each minute for the last 24 hours
WHILE @current_time <= @end_time
BEGIN
    -- Insert snapshot with OOOI-based phase reconstruction
    INSERT INTO dbo.flight_phase_snapshot (
        snapshot_utc,
        prefile_cnt,
        taxiing_cnt,
        departed_cnt,
        enroute_cnt,
        descending_cnt,
        arrived_cnt,
        unknown_cnt,
        total_active
    )
    SELECT
        @current_time AS snapshot_utc,

        -- Prefile: flight exists but no OUT time yet, or OUT time is in the future
        COUNT(CASE
            WHEN c.first_seen_utc <= @current_time
                 AND c.last_seen_utc >= @current_time
                 AND (t.out_utc IS NULL OR t.out_utc > @current_time)
                 AND (t.off_utc IS NULL OR t.off_utc > @current_time)
            THEN 1
        END) AS prefile_cnt,

        -- Taxiing: OUT time reached but not yet airborne (OFF not reached)
        COUNT(CASE
            WHEN c.first_seen_utc <= @current_time
                 AND c.last_seen_utc >= @current_time
                 AND t.out_utc IS NOT NULL AND t.out_utc <= @current_time
                 AND (t.off_utc IS NULL OR t.off_utc > @current_time)
            THEN 1
        END) AS taxiing_cnt,

        -- Departed: OFF time reached, in first 15 minutes of flight
        COUNT(CASE
            WHEN c.first_seen_utc <= @current_time
                 AND c.last_seen_utc >= @current_time
                 AND t.off_utc IS NOT NULL AND t.off_utc <= @current_time
                 AND DATEDIFF(MINUTE, t.off_utc, @current_time) <= 15
                 AND (t.on_utc IS NULL OR t.on_utc > @current_time)
            THEN 1
        END) AS departed_cnt,

        -- Enroute: more than 15 min after OFF, not yet in descent (>20 min from ON)
        COUNT(CASE
            WHEN c.first_seen_utc <= @current_time
                 AND c.last_seen_utc >= @current_time
                 AND t.off_utc IS NOT NULL AND t.off_utc <= @current_time
                 AND DATEDIFF(MINUTE, t.off_utc, @current_time) > 15
                 AND (t.on_utc IS NULL OR DATEDIFF(MINUTE, @current_time, t.on_utc) > 20)
            THEN 1
        END) AS enroute_cnt,

        -- Descending: within 20 minutes of ON time
        COUNT(CASE
            WHEN c.first_seen_utc <= @current_time
                 AND c.last_seen_utc >= @current_time
                 AND t.off_utc IS NOT NULL AND t.off_utc <= @current_time
                 AND t.on_utc IS NOT NULL AND t.on_utc > @current_time
                 AND DATEDIFF(MINUTE, @current_time, t.on_utc) BETWEEN 0 AND 20
            THEN 1
        END) AS descending_cnt,

        -- Arrived: ON time has passed
        COUNT(CASE
            WHEN c.first_seen_utc <= @current_time
                 AND c.last_seen_utc >= @current_time
                 AND t.on_utc IS NOT NULL AND t.on_utc <= @current_time
            THEN 1
        END) AS arrived_cnt,

        -- Unknown: active but no OOOI data to classify
        COUNT(CASE
            WHEN c.first_seen_utc <= @current_time
                 AND c.last_seen_utc >= @current_time
                 AND t.flight_uid IS NULL  -- No times record at all
            THEN 1
        END) AS unknown_cnt,

        -- Total active at this time point
        COUNT(CASE
            WHEN c.first_seen_utc <= @current_time
                 AND c.last_seen_utc >= @current_time
            THEN 1
        END) AS total_active

    FROM dbo.adl_flight_core c
    LEFT JOIN dbo.adl_flight_times t ON c.flight_uid = t.flight_uid
    WHERE c.first_seen_utc <= @current_time
      AND c.last_seen_utc >= DATEADD(MINUTE, -5, @current_time);

    SET @backfill_count = @backfill_count + 1;

    -- Progress indicator every 60 minutes
    IF @backfill_count % 60 = 0
    BEGIN
        PRINT CONCAT('  Processed ', @backfill_count, ' minutes (',
            CAST(@backfill_count / 60 AS VARCHAR), ' hours)...');
    END

    SET @current_time = DATEADD(MINUTE, 1, @current_time);
END

PRINT '';
PRINT CONCAT('Backfill complete: ', @backfill_count, ' snapshots created (1-min intervals)');

-- Show sample of data
PRINT '';
PRINT 'Sample of recent data:';
SELECT TOP 10
    snapshot_utc,
    prefile_cnt,
    taxiing_cnt,
    departed_cnt,
    enroute_cnt,
    descending_cnt,
    arrived_cnt,
    unknown_cnt,
    total_active
FROM dbo.flight_phase_snapshot
ORDER BY snapshot_utc DESC;

-- Show sample from middle of period
PRINT '';
PRINT 'Sample from 12 hours ago:';
SELECT TOP 5
    snapshot_utc,
    prefile_cnt,
    taxiing_cnt,
    departed_cnt,
    enroute_cnt,
    descending_cnt,
    arrived_cnt,
    unknown_cnt,
    total_active
FROM dbo.flight_phase_snapshot
WHERE snapshot_utc BETWEEN DATEADD(HOUR, -13, SYSUTCDATETIME()) AND DATEADD(HOUR, -11, SYSUTCDATETIME())
ORDER BY snapshot_utc DESC;

PRINT '';
PRINT '============================================================================';
PRINT 'Backfill Complete - Chart should now show 24 hours of OOOI-based phases';
PRINT '============================================================================';
GO
