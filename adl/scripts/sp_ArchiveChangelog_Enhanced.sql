-- ============================================================================
-- sp_ArchiveChangelog_Enhanced
--
-- Enhanced archival procedure for adl_flight_changelog
-- Keeps operational data (7-30 days) and archives/deletes older records
--
-- The changelog is growing at ~7.7M records/day (40 GB in 9 days)
-- Without archival: ~840 GB/year
-- With this proc: ~4-8 GB maintained
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID('dbo.sp_ArchiveChangelog_Enhanced', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_ArchiveChangelog_Enhanced;
GO

CREATE PROCEDURE dbo.sp_ArchiveChangelog_Enhanced
    @retention_days INT = 7,              -- Days to keep in main table
    @batch_size INT = 100000,             -- Records per batch (prevents blocking)
    @max_batches INT = 100,               -- Max batches per run
    @dry_run BIT = 0,                     -- 1 = report only, no delete
    @rows_deleted INT = 0 OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @cutoff_utc DATETIME2(3) = DATEADD(DAY, -@retention_days, GETUTCDATE());
    DECLARE @total_deleted INT = 0;
    DECLARE @batch_deleted INT = 1;
    DECLARE @batch_count INT = 0;
    DECLARE @start_time DATETIME2 = GETUTCDATE();

    -- ========================================================================
    -- Step 1: Report current state
    -- ========================================================================

    PRINT '=== Changelog Archive Process ==='
    PRINT 'Retention: ' + CAST(@retention_days AS VARCHAR) + ' days'
    PRINT 'Cutoff: ' + CONVERT(VARCHAR, @cutoff_utc, 120)
    PRINT 'Batch size: ' + CAST(@batch_size AS VARCHAR)
    PRINT 'Dry run: ' + CASE @dry_run WHEN 1 THEN 'YES' ELSE 'NO' END
    PRINT ''

    -- Count records to archive
    DECLARE @to_archive BIGINT;
    SELECT @to_archive = COUNT(*)
    FROM dbo.adl_flight_changelog
    WHERE change_utc < @cutoff_utc;

    PRINT 'Records older than cutoff: ' + FORMAT(@to_archive, 'N0')

    IF @dry_run = 1
    BEGIN
        PRINT ''
        PRINT 'DRY RUN - No records deleted'
        SET @rows_deleted = 0;
        RETURN;
    END

    IF @to_archive = 0
    BEGIN
        PRINT 'No records to archive'
        SET @rows_deleted = 0;
        RETURN;
    END

    -- ========================================================================
    -- Step 2: Batch delete old records
    -- ========================================================================

    PRINT ''
    PRINT 'Starting batch deletion...'

    WHILE @batch_deleted > 0 AND @batch_count < @max_batches
    BEGIN
        DELETE TOP (@batch_size)
        FROM dbo.adl_flight_changelog
        WHERE change_utc < @cutoff_utc;

        SET @batch_deleted = @@ROWCOUNT;
        SET @total_deleted = @total_deleted + @batch_deleted;
        SET @batch_count = @batch_count + 1;

        IF @batch_deleted > 0
        BEGIN
            PRINT 'Batch ' + CAST(@batch_count AS VARCHAR) + ': Deleted '
                + FORMAT(@batch_deleted, 'N0') + ' records (Total: '
                + FORMAT(@total_deleted, 'N0') + ')';

            -- Brief pause to reduce lock contention
            WAITFOR DELAY '00:00:00.100';
        END
    END

    -- ========================================================================
    -- Step 3: Report results
    -- ========================================================================

    DECLARE @duration_sec INT = DATEDIFF(SECOND, @start_time, GETUTCDATE());

    PRINT ''
    PRINT '=== Archive Complete ==='
    PRINT 'Total deleted: ' + FORMAT(@total_deleted, 'N0')
    PRINT 'Batches: ' + CAST(@batch_count AS VARCHAR)
    PRINT 'Duration: ' + CAST(@duration_sec AS VARCHAR) + ' seconds'

    IF @batch_count >= @max_batches AND @batch_deleted > 0
    BEGIN
        PRINT ''
        PRINT 'WARNING: Max batches reached. More records may need archiving.'
        PRINT 'Run procedure again to continue.'
    END

    SET @rows_deleted = @total_deleted;

    -- Log the archival run
    IF OBJECT_ID('dbo.adl_archive_log', 'U') IS NOT NULL
    BEGIN
        INSERT INTO dbo.adl_archive_log (archive_type, records_archived, archive_utc, duration_seconds)
        VALUES ('changelog', @total_deleted, GETUTCDATE(), @duration_sec);
    END
END
GO

PRINT 'Created procedure sp_ArchiveChangelog_Enhanced'
GO

-- ============================================================================
-- Create scheduled job hint (run via SQL Agent or Azure Automation)
-- ============================================================================

/*
-- Recommended schedule: Daily at 3 AM UTC

-- Test with dry run first:
EXEC dbo.sp_ArchiveChangelog_Enhanced @retention_days = 7, @dry_run = 1;

-- Production run:
EXEC dbo.sp_ArchiveChangelog_Enhanced @retention_days = 7, @dry_run = 0;

-- Aggressive cleanup (for initial reduction):
EXEC dbo.sp_ArchiveChangelog_Enhanced
    @retention_days = 3,
    @batch_size = 500000,
    @max_batches = 500,
    @dry_run = 0;
*/

-- ============================================================================
-- Growth Analysis
-- ============================================================================

/*
Current Growth Rate:
- 66 million records in 9 days = ~7.3M records/day
- 40 GB in 9 days = ~4.4 GB/day

With 7-day retention:
- Maintained size: ~51M records = ~28 GB
- With PAGE compression: ~11 GB

Annual storage cost difference:
- Without archival: ~1.6 TB × $0.10 = $160/month
- With archival (compressed): ~11 GB × $0.10 = $1.10/month

SAVINGS: ~$159/month just from changelog management
*/
GO
