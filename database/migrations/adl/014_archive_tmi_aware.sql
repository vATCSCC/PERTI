-- ============================================================================
-- ADL Migration 014: TMI-Aware Archive Procedure
--
-- Purpose: Extract TMI-relevant trajectory data BEFORE archive downsampling
-- Atomic: TMI extraction + archive in single transaction
--
-- Target Database: VATSIM_ADL
-- Depends on: adl_tmi_trajectory, fn_ComputeTmiTierBatch
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Migration 014: TMI-Aware Archive Procedure ===';
GO

CREATE OR ALTER PROCEDURE dbo.sp_ArchiveTrajectory_TmiAware
    @archive_threshold_hours INT = 1,  -- Archive positions older than this
    @batch_size INT = 10000            -- Process in batches
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @log_id INT;
    DECLARE @start_time DATETIME2(3) = SYSUTCDATETIME();
    DECLARE @cutoff_utc DATETIME2(0) = DATEADD(HOUR, -@archive_threshold_hours, SYSUTCDATETIME());
    DECLARE @rows_tmi INT = 0, @rows_archived INT = 0, @rows_deleted INT = 0;
    DECLARE @batch_count INT = 0;
    DECLARE @error_message NVARCHAR(MAX);

    -- Log job start
    INSERT INTO dbo.adl_archive_log (job_name, started_utc, status)
    VALUES ('TMI_TRAJECTORY_ARCHIVE', @start_time, 'RUNNING');
    SET @log_id = SCOPE_IDENTITY();

    BEGIN TRY
        -- Process in batches to avoid lock escalation
        WHILE 1 = 1
        BEGIN
            BEGIN TRANSACTION;

            -- 1. Identify batch of rows to process
            -- Note: adl_flight_trajectory uses 'recorded_utc', target tables use 'timestamp_utc'
            SELECT TOP (@batch_size)
                t.trajectory_id,
                t.flight_uid,
                t.recorded_utc AS timestamp_utc,  -- Rename for consistency with target tables
                t.lat,
                t.lon,
                t.altitude_ft,
                t.groundspeed_kts,
                t.track_deg,
                t.vertical_rate_fpm,
                tier.tmi_tier,
                tier.perti_event_id
            INTO #pending_batch
            FROM dbo.adl_flight_trajectory t
            CROSS APPLY dbo.fn_ComputeTmiTier(t.flight_uid, t.recorded_utc) tier
            WHERE t.recorded_utc < @cutoff_utc
            ORDER BY t.trajectory_id;

            IF @@ROWCOUNT = 0
            BEGIN
                DROP TABLE IF EXISTS #pending_batch;
                COMMIT TRANSACTION;
                BREAK;  -- No more rows to process
            END

            SET @batch_count = @batch_count + 1;

            -- 2. Extract TMI-relevant rows (T-0, T-1, T-2) to TMI table
            INSERT INTO dbo.adl_tmi_trajectory (
                flight_uid, timestamp_utc, lat, lon, altitude_ft,
                groundspeed_kts, track_deg, vertical_rate_fpm,
                tmi_tier, perti_event_id
            )
            SELECT
                flight_uid, timestamp_utc, lat, lon, altitude_ft,
                groundspeed_kts, track_deg, vertical_rate_fpm,
                tmi_tier, perti_event_id
            FROM #pending_batch
            WHERE tmi_tier IS NOT NULL;  -- In coverage area

            SET @rows_tmi = @rows_tmi + @@ROWCOUNT;

            -- 3. Move to archive (existing archive logic would go here)
            -- For now, we insert to archive with downsampling
            INSERT INTO dbo.adl_trajectory_archive (
                flight_uid, callsign, timestamp_utc, lat, lon,
                altitude_ft, groundspeed_kts, heading_deg, vertical_rate_fpm,
                sample_interval_sec, source_tier
            )
            SELECT
                p.flight_uid,
                c.callsign,
                p.timestamp_utc,
                p.lat,
                p.lon,
                p.altitude_ft,
                p.groundspeed_kts,
                p.track_deg,  -- Using track_deg as heading
                p.vertical_rate_fpm,
                60,  -- WARM tier = 60 sec
                'WARM'
            FROM #pending_batch p
            JOIN dbo.adl_flight_core c ON p.flight_uid = c.flight_uid
            WHERE p.trajectory_id % 4 = 0;  -- Downsample to ~60 sec (every 4th 15-sec point)

            SET @rows_archived = @rows_archived + @@ROWCOUNT;

            -- 4. Delete from hot table
            DELETE t
            FROM dbo.adl_flight_trajectory t
            WHERE EXISTS (
                SELECT 1 FROM #pending_batch p
                WHERE p.trajectory_id = t.trajectory_id
            );

            SET @rows_deleted = @rows_deleted + @@ROWCOUNT;

            DROP TABLE #pending_batch;

            COMMIT TRANSACTION;

            -- Brief pause between batches to reduce lock contention
            WAITFOR DELAY '00:00:00.100';  -- 100ms
        END

        -- Log success
        UPDATE dbo.adl_archive_log
        SET ended_utc = SYSUTCDATETIME(),
            duration_ms = DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME()),
            rows_processed = @rows_deleted,
            rows_archived = @rows_archived,
            status = 'SUCCESS'
        WHERE log_id = @log_id;

        -- Return summary
        SELECT
            @batch_count AS batches_processed,
            @rows_tmi AS rows_to_tmi_table,
            @rows_archived AS rows_to_archive,
            @rows_deleted AS rows_deleted_from_hot,
            DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME()) AS duration_ms;

    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;

        SET @error_message = ERROR_MESSAGE();

        UPDATE dbo.adl_archive_log
        SET ended_utc = SYSUTCDATETIME(),
            duration_ms = DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME()),
            status = 'FAILED',
            error_message = @error_message
        WHERE log_id = @log_id;

        THROW;
    END CATCH
END
GO

PRINT 'Created procedure dbo.sp_ArchiveTrajectory_TmiAware';
GO

PRINT '=== ADL Migration 014 Complete ===';
GO
