-- ============================================================================
-- 013: Snapshot Gap Backfill Stored Procedure
--
-- Creates sp_BackfillPhaseSnapshotGap which reconstructs missing phase
-- snapshots from OOOI timestamps. Called by the ADL daemon when it detects
-- gaps > 10 minutes in the flight_phase_snapshot table and has sufficient
-- deferred processing budget.
--
-- Fills at 5-minute resolution (matching the daemon's snapshot interval).
-- Uses chunked processing (@max_rows) to limit SQL impact per call.
-- ============================================================================

SET NOCOUNT ON;

-- Drop and recreate the procedure
IF OBJECT_ID('dbo.sp_BackfillPhaseSnapshotGap', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_BackfillPhaseSnapshotGap;
GO

CREATE PROCEDURE dbo.sp_BackfillPhaseSnapshotGap
    @gap_start  DATETIME2(0),
    @gap_end    DATETIME2(0),
    @max_rows   INT = 10,
    @filled     INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    SET @filled = 0;

    -- Snap gap_start to next 5-minute boundary
    DECLARE @t DATETIME2(0) = DATEADD(MINUTE,
        CEILING(DATEDIFF(MINUTE, '2000-01-01', @gap_start) / 5.0) * 5,
        '2000-01-01');

    -- Don't backfill beyond current time or requested end
    DECLARE @effective_end DATETIME2(0) = CASE
        WHEN @gap_end < SYSUTCDATETIME() THEN @gap_end
        ELSE SYSUTCDATETIME()
    END;

    WHILE @t < @effective_end AND @filled < @max_rows
    BEGIN
        -- Skip if snapshot already exists for this time
        IF NOT EXISTS (
            SELECT 1 FROM dbo.flight_phase_snapshot WITH (NOLOCK)
            WHERE snapshot_utc = @t
        )
        BEGIN
            -- Reconstruct phase counts from OOOI timestamps
            -- Logic matches 012b_backfill_phase_snapshots.sql
            INSERT INTO dbo.flight_phase_snapshot (
                snapshot_utc, prefile_cnt, taxiing_cnt, departed_cnt,
                enroute_cnt, descending_cnt, arrived_cnt, unknown_cnt,
                total_active
            )
            SELECT
                @t AS snapshot_utc,

                -- Prefile: connected but no OUT/OFF yet
                COUNT(CASE
                    WHEN c.first_seen_utc <= @t
                         AND c.last_seen_utc >= @t
                         AND (t.out_utc IS NULL OR t.out_utc > @t)
                         AND (t.off_utc IS NULL OR t.off_utc > @t)
                    THEN 1
                END),

                -- Taxiing: OUT reached but not yet airborne
                COUNT(CASE
                    WHEN c.first_seen_utc <= @t
                         AND c.last_seen_utc >= @t
                         AND t.out_utc IS NOT NULL AND t.out_utc <= @t
                         AND (t.off_utc IS NULL OR t.off_utc > @t)
                    THEN 1
                END),

                -- Departed: airborne, first 15 minutes
                COUNT(CASE
                    WHEN c.first_seen_utc <= @t
                         AND c.last_seen_utc >= @t
                         AND t.off_utc IS NOT NULL AND t.off_utc <= @t
                         AND DATEDIFF(MINUTE, t.off_utc, @t) <= 15
                         AND (t.on_utc IS NULL OR t.on_utc > @t)
                    THEN 1
                END),

                -- Enroute: >15 min after OFF, >20 min before ON
                COUNT(CASE
                    WHEN c.first_seen_utc <= @t
                         AND c.last_seen_utc >= @t
                         AND t.off_utc IS NOT NULL AND t.off_utc <= @t
                         AND DATEDIFF(MINUTE, t.off_utc, @t) > 15
                         AND (t.on_utc IS NULL OR DATEDIFF(MINUTE, @t, t.on_utc) > 20)
                    THEN 1
                END),

                -- Descending: within 20 minutes of ON
                COUNT(CASE
                    WHEN c.first_seen_utc <= @t
                         AND c.last_seen_utc >= @t
                         AND t.off_utc IS NOT NULL AND t.off_utc <= @t
                         AND t.on_utc IS NOT NULL AND t.on_utc > @t
                         AND DATEDIFF(MINUTE, @t, t.on_utc) BETWEEN 0 AND 20
                    THEN 1
                END),

                -- Arrived: ON time has passed
                COUNT(CASE
                    WHEN c.first_seen_utc <= @t
                         AND c.last_seen_utc >= @t
                         AND t.on_utc IS NOT NULL AND t.on_utc <= @t
                    THEN 1
                END),

                -- Unknown: active but no OOOI times record
                COUNT(CASE
                    WHEN c.first_seen_utc <= @t
                         AND c.last_seen_utc >= @t
                         AND t.flight_uid IS NULL
                    THEN 1
                END),

                -- Total active
                COUNT(CASE
                    WHEN c.first_seen_utc <= @t
                         AND c.last_seen_utc >= @t
                    THEN 1
                END)

            FROM dbo.adl_flight_core c WITH (NOLOCK)
            LEFT JOIN dbo.adl_flight_times t WITH (NOLOCK) ON c.flight_uid = t.flight_uid
            WHERE c.first_seen_utc <= @t
              AND c.last_seen_utc >= DATEADD(MINUTE, -5, @t);

            SET @filled = @filled + 1;
        END

        -- Advance by 5 minutes
        SET @t = DATEADD(MINUTE, 5, @t);
    END
END
GO

PRINT 'Created sp_BackfillPhaseSnapshotGap';
PRINT '  Parameters: @gap_start, @gap_end, @max_rows (default 10), @filled OUTPUT';
PRINT '  Fills at 5-minute resolution using OOOI-based phase reconstruction';
GO
