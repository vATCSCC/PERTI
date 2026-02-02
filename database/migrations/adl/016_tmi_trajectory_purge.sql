-- ============================================================================
-- ADL Migration 016: TMI Trajectory Purge Procedure
--
-- Purpose: Purge TMI trajectory data older than retention period (90 days)
-- Schedule: Run daily during off-peak hours (0300-0600 UTC)
--
-- Target Database: VATSIM_ADL
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Migration 016: TMI Trajectory Purge ===';
GO

CREATE OR ALTER PROCEDURE dbo.sp_PurgeTmiTrajectory
    @retention_days INT = 90,
    @batch_size INT = 50000
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @log_id INT;
    DECLARE @start_time DATETIME2(3) = SYSUTCDATETIME();
    DECLARE @cutoff_utc DATETIME2(0) = DATEADD(DAY, -@retention_days, SYSUTCDATETIME());
    DECLARE @total_deleted INT = 0;
    DECLARE @batch_deleted INT = 1;

    -- Log job start
    INSERT INTO dbo.adl_archive_log (job_name, started_utc, status)
    VALUES ('TMI_TRAJECTORY_PURGE', @start_time, 'RUNNING');
    SET @log_id = SCOPE_IDENTITY();

    BEGIN TRY
        -- Delete in batches to minimize lock duration
        WHILE @batch_deleted > 0
        BEGIN
            DELETE TOP (@batch_size)
            FROM dbo.adl_tmi_trajectory
            WHERE timestamp_utc < @cutoff_utc;

            SET @batch_deleted = @@ROWCOUNT;
            SET @total_deleted = @total_deleted + @batch_deleted;

            -- Brief pause between batches
            IF @batch_deleted > 0
                WAITFOR DELAY '00:00:00.050';  -- 50ms
        END

        -- Log success
        UPDATE dbo.adl_archive_log
        SET ended_utc = SYSUTCDATETIME(),
            duration_ms = DATEDIFF(MILLISECOND, @start_time, SYSUTCDATETIME()),
            rows_deleted = @total_deleted,
            status = 'SUCCESS'
        WHERE log_id = @log_id;

        SELECT @total_deleted AS rows_purged, @retention_days AS retention_days;

    END TRY
    BEGIN CATCH
        UPDATE dbo.adl_archive_log
        SET ended_utc = SYSUTCDATETIME(),
            status = 'FAILED',
            error_message = ERROR_MESSAGE()
        WHERE log_id = @log_id;

        THROW;
    END CATCH
END
GO

PRINT 'Created procedure dbo.sp_PurgeTmiTrajectory';
GO

PRINT '=== ADL Migration 016 Complete ===';
GO
