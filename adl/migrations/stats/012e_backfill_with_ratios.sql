-- ============================================================================
-- Backfill: 24 hours of phase snapshots using current phase ratios
--
-- Since we can't know historical phase values, we estimate by:
-- 1. Getting current phase distribution percentages
-- 2. Counting flights active at each historical time point
-- 3. Applying current ratios to historical totals
--
-- This gives a reasonable approximation for the chart until real data builds up
-- ============================================================================

SET NOCOUNT ON;

PRINT '============================================================================';
PRINT 'Backfilling 24 hours of phase snapshots with ratio-based estimates';
PRINT '============================================================================';

-- First, ensure stale flights are cleaned up
UPDATE dbo.adl_flight_core
SET is_active = 0, phase = 'arrived', flight_status = 'COMPLETED'
WHERE is_active = 1
  AND last_seen_utc < DATEADD(MINUTE, -5, SYSUTCDATETIME());

PRINT CONCAT('Cleaned up ', @@ROWCOUNT, ' stale flights');

-- Clear existing snapshots
TRUNCATE TABLE dbo.flight_phase_snapshot;
PRINT 'Cleared existing snapshot data';

-- Get current phase distribution ratios
DECLARE @total_now INT;
DECLARE @pct_taxiing DECIMAL(5,4);
DECLARE @pct_departed DECIMAL(5,4);
DECLARE @pct_enroute DECIMAL(5,4);
DECLARE @pct_descending DECIMAL(5,4);
DECLARE @pct_arrived DECIMAL(5,4);
DECLARE @pct_unknown DECIMAL(5,4);

SELECT
    @total_now = COUNT(*),
    @pct_taxiing = CAST(COUNT(CASE WHEN phase = 'taxiing' THEN 1 END) AS DECIMAL(10,4)) / NULLIF(COUNT(*), 0),
    @pct_departed = CAST(COUNT(CASE WHEN phase = 'departed' THEN 1 END) AS DECIMAL(10,4)) / NULLIF(COUNT(*), 0),
    @pct_enroute = CAST(COUNT(CASE WHEN phase = 'enroute' THEN 1 END) AS DECIMAL(10,4)) / NULLIF(COUNT(*), 0),
    @pct_descending = CAST(COUNT(CASE WHEN phase = 'descending' THEN 1 END) AS DECIMAL(10,4)) / NULLIF(COUNT(*), 0),
    @pct_arrived = CAST(COUNT(CASE WHEN phase = 'arrived' THEN 1 END) AS DECIMAL(10,4)) / NULLIF(COUNT(*), 0),
    @pct_unknown = CAST(COUNT(CASE WHEN phase IS NULL OR phase NOT IN ('taxiing','departed','enroute','descending','arrived') THEN 1 END) AS DECIMAL(10,4)) / NULLIF(COUNT(*), 0)
FROM dbo.adl_flight_core
WHERE is_active = 1;

PRINT '';
PRINT 'Current phase distribution:';
PRINT CONCAT('  Total active: ', @total_now);
PRINT CONCAT('  Taxiing: ', CAST(@pct_taxiing * 100 AS DECIMAL(5,2)), '%');
PRINT CONCAT('  Departed: ', CAST(@pct_departed * 100 AS DECIMAL(5,2)), '%');
PRINT CONCAT('  Enroute: ', CAST(@pct_enroute * 100 AS DECIMAL(5,2)), '%');
PRINT CONCAT('  Descending: ', CAST(@pct_descending * 100 AS DECIMAL(5,2)), '%');
PRINT CONCAT('  Arrived: ', CAST(@pct_arrived * 100 AS DECIMAL(5,2)), '%');
PRINT '';

-- Backfill 24 hours at 1-minute intervals
DECLARE @start_time DATETIME2(0) = DATEADD(HOUR, -24, SYSUTCDATETIME());
DECLARE @end_time DATETIME2(0) = SYSUTCDATETIME();
DECLARE @current_time DATETIME2(0) = @start_time;
DECLARE @backfill_count INT = 0;

PRINT 'Backfilling snapshots...';

WHILE @current_time <= @end_time
BEGIN
    -- Count flights that were active at this time point
    DECLARE @active_at_time INT;

    SELECT @active_at_time = COUNT(*)
    FROM dbo.adl_flight_core
    WHERE first_seen_utc <= @current_time
      AND last_seen_utc >= @current_time;

    -- Insert snapshot with estimated phase breakdown
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
    VALUES (
        @current_time,
        0,  -- prefile not tracked historically
        ROUND(@active_at_time * @pct_taxiing, 0),
        ROUND(@active_at_time * @pct_departed, 0),
        ROUND(@active_at_time * @pct_enroute, 0),
        ROUND(@active_at_time * @pct_descending, 0),
        ROUND(@active_at_time * @pct_arrived, 0),
        ROUND(@active_at_time * @pct_unknown, 0),
        @active_at_time
    );

    SET @backfill_count = @backfill_count + 1;

    -- Progress every 60 minutes
    IF @backfill_count % 60 = 0
    BEGIN
        PRINT CONCAT('  Processed ', @backfill_count, ' minutes (', @backfill_count / 60, ' hours)...');
    END

    SET @current_time = DATEADD(MINUTE, 1, @current_time);
END

PRINT '';
PRINT CONCAT('Backfilled ', @backfill_count, ' snapshots');

-- Capture one real snapshot at the end
EXEC dbo.sp_CapturePhaseSnapshot;
PRINT 'Captured current real snapshot';

-- Show sample
PRINT '';
PRINT 'Sample of backfilled data:';
SELECT TOP 5
    snapshot_utc,
    taxiing_cnt,
    departed_cnt,
    enroute_cnt,
    descending_cnt,
    arrived_cnt,
    total_active
FROM dbo.flight_phase_snapshot
ORDER BY snapshot_utc DESC;

PRINT '';
PRINT '============================================================================';
PRINT 'Backfill Complete';
PRINT '';
PRINT 'Note: Historical data uses current phase ratios as estimates.';
PRINT 'Real data will replace estimates as new snapshots are captured.';
PRINT '============================================================================';
GO
