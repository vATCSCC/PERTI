-- ============================================================================
-- Migration: 012_flight_phase_snapshot.sql
-- Purpose: Create table to store 1-minute resolution flight phase snapshots
-- for 24-hour historical chart display
-- ============================================================================

SET NOCOUNT ON;

PRINT '============================================================================';
PRINT 'Migration 012: Flight Phase Snapshot Table';
PRINT '============================================================================';
PRINT '';

-- ============================================================================
-- 1. Create flight_phase_snapshot table
-- ============================================================================

IF OBJECT_ID('dbo.flight_phase_snapshot', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.flight_phase_snapshot (
        snapshot_id     BIGINT IDENTITY(1,1) PRIMARY KEY,
        snapshot_utc    DATETIME2(0) NOT NULL,

        -- Phase counts (active flights only)
        prefile_cnt     INT NOT NULL DEFAULT 0,
        taxiing_cnt     INT NOT NULL DEFAULT 0,
        departed_cnt    INT NOT NULL DEFAULT 0,
        enroute_cnt     INT NOT NULL DEFAULT 0,
        descending_cnt  INT NOT NULL DEFAULT 0,
        arrived_cnt     INT NOT NULL DEFAULT 0,
        unknown_cnt     INT NOT NULL DEFAULT 0,

        -- Total active flights
        total_active    INT NOT NULL DEFAULT 0,

        -- Index for time-based queries
        INDEX IX_phase_snapshot_time (snapshot_utc DESC)
    );

    PRINT 'Created table: flight_phase_snapshot';
END
ELSE
BEGIN
    PRINT 'Table flight_phase_snapshot already exists';
END
GO

-- ============================================================================
-- 2. Create stored procedure to capture phase snapshot
-- ============================================================================

IF OBJECT_ID('dbo.sp_CapturePhaseSnapshot', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_CapturePhaseSnapshot;
GO

CREATE PROCEDURE dbo.sp_CapturePhaseSnapshot
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @now DATETIME2(0) = SYSUTCDATETIME();

    -- Insert snapshot of current phase counts
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
        @now,
        COUNT(CASE WHEN phase = 'prefile' THEN 1 END),
        COUNT(CASE WHEN phase = 'taxiing' THEN 1 END),
        COUNT(CASE WHEN phase = 'departed' THEN 1 END),
        COUNT(CASE WHEN phase = 'enroute' THEN 1 END),
        COUNT(CASE WHEN phase = 'descending' THEN 1 END),
        COUNT(CASE WHEN phase = 'arrived' THEN 1 END),
        COUNT(CASE WHEN phase = 'unknown' OR phase IS NULL THEN 1 END),
        COUNT(*)
    FROM dbo.adl_flight_core
    WHERE is_active = 1;

    -- Cleanup: Delete snapshots older than 48 hours
    DELETE FROM dbo.flight_phase_snapshot
    WHERE snapshot_utc < DATEADD(HOUR, -48, @now);
END
GO

PRINT 'Created procedure: sp_CapturePhaseSnapshot';
GO

-- ============================================================================
-- 3. Backfill historical data from actual flight records
-- ============================================================================

-- Only backfill if table is empty (first run)
IF NOT EXISTS (SELECT 1 FROM dbo.flight_phase_snapshot)
BEGIN
    PRINT 'Backfilling historical data from flight records...';

    -- Generate time buckets for last 48 hours (1 minute resolution)
    DECLARE @start_time DATETIME2(0) = DATEADD(HOUR, -48, SYSUTCDATETIME());
    DECLARE @end_time DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @current_time DATETIME2(0) = @start_time;
    DECLARE @backfill_count INT = 0;

    -- Process in 15-minute chunks for performance
    WHILE @current_time < @end_time
    BEGIN
        -- For each time point, count flights by phase based on timestamps
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
            -- Prefile: first_seen but no position data, or explicitly prefile phase
            COUNT(CASE
                WHEN c.first_seen_utc <= @current_time
                     AND c.last_seen_utc >= @current_time
                     AND (c.phase = 'prefile' OR (t.out_utc IS NULL AND t.off_utc IS NULL))
                THEN 1
            END) AS prefile_cnt,
            -- Taxiing: has OUT time but no OFF time yet
            COUNT(CASE
                WHEN c.first_seen_utc <= @current_time
                     AND c.last_seen_utc >= @current_time
                     AND t.out_utc IS NOT NULL AND t.out_utc <= @current_time
                     AND (t.off_utc IS NULL OR t.off_utc > @current_time)
                THEN 1
            END) AS taxiing_cnt,
            -- Departed (climbing): has OFF time, in first 15 min of flight
            COUNT(CASE
                WHEN c.first_seen_utc <= @current_time
                     AND c.last_seen_utc >= @current_time
                     AND t.off_utc IS NOT NULL AND t.off_utc <= @current_time
                     AND DATEDIFF(MINUTE, t.off_utc, @current_time) <= 15
                     AND (t.on_utc IS NULL OR t.on_utc > @current_time)
                THEN 1
            END) AS departed_cnt,
            -- Enroute: has OFF time, more than 15 min in, not yet descending
            COUNT(CASE
                WHEN c.first_seen_utc <= @current_time
                     AND c.last_seen_utc >= @current_time
                     AND t.off_utc IS NOT NULL AND t.off_utc <= @current_time
                     AND DATEDIFF(MINUTE, t.off_utc, @current_time) > 15
                     AND (t.on_utc IS NULL OR DATEDIFF(MINUTE, @current_time, t.on_utc) > 20)
                THEN 1
            END) AS enroute_cnt,
            -- Descending: within 20 min of ON time
            COUNT(CASE
                WHEN c.first_seen_utc <= @current_time
                     AND c.last_seen_utc >= @current_time
                     AND t.off_utc IS NOT NULL AND t.off_utc <= @current_time
                     AND t.on_utc IS NOT NULL
                     AND DATEDIFF(MINUTE, @current_time, t.on_utc) BETWEEN 0 AND 20
                THEN 1
            END) AS descending_cnt,
            -- Arrived: has ON time and IN time or recently landed
            COUNT(CASE
                WHEN c.first_seen_utc <= @current_time
                     AND c.last_seen_utc >= @current_time
                     AND t.on_utc IS NOT NULL AND t.on_utc <= @current_time
                THEN 1
            END) AS arrived_cnt,
            -- Unknown: active but doesn't fit other categories
            COUNT(CASE
                WHEN c.first_seen_utc <= @current_time
                     AND c.last_seen_utc >= @current_time
                     AND c.phase = 'unknown'
                THEN 1
            END) AS unknown_cnt,
            -- Total active at this time
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
        SET @current_time = DATEADD(MINUTE, 15, @current_time);
    END

    PRINT CONCAT('  Backfilled ', @backfill_count, ' snapshots (15-min intervals)');
END
ELSE
BEGIN
    PRINT 'Table already has data, skipping backfill';
END
GO

-- Capture current snapshot
EXEC dbo.sp_CapturePhaseSnapshot;
PRINT 'Captured initial phase snapshot';
GO

PRINT '';
PRINT '============================================================================';
PRINT 'Migration 012 Complete';
PRINT '  - Table: flight_phase_snapshot (1-min resolution, 48hr retention)';
PRINT '  - Procedure: sp_CapturePhaseSnapshot';
PRINT '  - Backfill: Historical data from flight_stats_hourly';
PRINT '============================================================================';
GO
