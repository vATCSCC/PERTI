-- ============================================================================
-- ADL Changelog System - Migration 006: Utility Procedures
--
-- Creates utility stored procedures for querying and managing the changelog:
-- - sp_GetFlightHistory: Retrieve complete history for a flight
-- - sp_ArchiveChangelog: Archive and purge old changelog entries
-- - sp_GetChangelogStats: Get changelog statistics
-- - sp_GetRecentChanges: Get recent changes with filtering
--
-- Run Order: 6 of 6
-- Depends on: 001_changelog_schema_upgrade.sql
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Changelog Migration 006: Utility Procedures ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- Procedure: sp_GetFlightHistory
-- Retrieves complete changelog history for a flight (FSA-style)
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_GetFlightHistory
    @callsign NVARCHAR(16) = NULL,
    @flight_uid BIGINT = NULL,
    @hours_back INT = 24,
    @include_inserts BIT = 1
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @cutoff_utc DATETIME2(3) = DATEADD(HOUR, -@hours_back, SYSUTCDATETIME());

    SELECT
        cl.changelog_id,
        cl.flight_uid,
        cl.callsign,
        cl.change_utc,
        cl.change_type,
        ct.description AS change_type_desc,
        cl.source_table,
        cl.field_name,
        cl.old_value,
        cl.new_value,
        cl.change_reason,
        cl.batch_id,
        -- Computed fields
        CASE cl.change_type
            WHEN 'I' THEN 'New'
            WHEN 'U' THEN 'Update'
            WHEN 'S' THEN 'Status'
            WHEN 'D' THEN 'Deleted'
        END AS change_label,
        DATEDIFF(SECOND, LAG(cl.change_utc) OVER (PARTITION BY cl.flight_uid ORDER BY cl.change_utc), cl.change_utc) AS seconds_since_last
    FROM dbo.adl_flight_changelog cl
    LEFT JOIN dbo.adl_changelog_change_types ct ON ct.change_type = cl.change_type
    WHERE (
            (@flight_uid IS NOT NULL AND cl.flight_uid = @flight_uid)
            OR (@callsign IS NOT NULL AND cl.callsign = @callsign)
          )
      AND cl.change_utc >= @cutoff_utc
      AND (@include_inserts = 1 OR cl.change_type <> 'I')
    ORDER BY cl.change_utc ASC;
END;
GO

PRINT 'Created procedure dbo.sp_GetFlightHistory';
GO

-- ============================================================================
-- Procedure: sp_ArchiveChangelog
-- Archives and purges old changelog entries in batches
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_ArchiveChangelog
    @days_to_keep INT = 90,
    @batch_size INT = 10000,
    @max_batches INT = 100,  -- Safety limit
    @dry_run BIT = 0
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @cutoff_date DATETIME2(3) = DATEADD(DAY, -@days_to_keep, SYSUTCDATETIME());
    DECLARE @total_deleted INT = 0;
    DECLARE @batch_count INT = 0;
    DECLARE @rows_in_batch INT = 1;
    DECLARE @start_time DATETIME2(3) = SYSUTCDATETIME();

    -- Count records to be deleted
    DECLARE @total_eligible INT;
    SELECT @total_eligible = COUNT(*)
    FROM dbo.adl_flight_changelog
    WHERE change_utc < @cutoff_date;

    PRINT 'Changelog Archive Process';
    PRINT '========================';
    PRINT 'Cutoff date: ' + CONVERT(VARCHAR, @cutoff_date, 120);
    PRINT 'Records eligible for deletion: ' + CAST(@total_eligible AS VARCHAR);
    PRINT 'Dry run: ' + CASE WHEN @dry_run = 1 THEN 'YES' ELSE 'NO' END;
    PRINT '';

    IF @dry_run = 1
    BEGIN
        PRINT 'DRY RUN - No records will be deleted';
        SELECT @total_eligible AS records_to_delete,
               @cutoff_date AS cutoff_date,
               @days_to_keep AS days_to_keep;
        RETURN;
    END

    -- Delete in batches to avoid log bloat and blocking
    WHILE @rows_in_batch > 0 AND @batch_count < @max_batches
    BEGIN
        DELETE TOP (@batch_size) FROM dbo.adl_flight_changelog
        WHERE change_utc < @cutoff_date;

        SET @rows_in_batch = @@ROWCOUNT;
        SET @total_deleted = @total_deleted + @rows_in_batch;
        SET @batch_count = @batch_count + 1;

        IF @rows_in_batch > 0
        BEGIN
            PRINT 'Batch ' + CAST(@batch_count AS VARCHAR) + ': Deleted ' + CAST(@rows_in_batch AS VARCHAR) + ' rows (Total: ' + CAST(@total_deleted AS VARCHAR) + ')';
        END

        -- Brief pause to let other transactions through
        IF @rows_in_batch >= @batch_size
        BEGIN
            WAITFOR DELAY '00:00:01';
        END
    END

    -- Also clean up orphaned batch records
    DELETE FROM dbo.adl_changelog_batch
    WHERE batch_start_utc < @cutoff_date;

    DECLARE @elapsed_seconds INT = DATEDIFF(SECOND, @start_time, SYSUTCDATETIME());

    PRINT '';
    PRINT 'Archive Complete';
    PRINT '================';
    PRINT 'Total rows deleted: ' + CAST(@total_deleted AS VARCHAR);
    PRINT 'Batches processed: ' + CAST(@batch_count AS VARCHAR);
    PRINT 'Elapsed time: ' + CAST(@elapsed_seconds AS VARCHAR) + ' seconds';

    SELECT @total_deleted AS rows_archived,
           @batch_count AS batches_processed,
           @elapsed_seconds AS elapsed_seconds,
           @cutoff_date AS cutoff_date;
END;
GO

PRINT 'Created procedure dbo.sp_ArchiveChangelog';
GO

-- ============================================================================
-- Procedure: sp_GetChangelogStats
-- Returns changelog statistics for monitoring
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_GetChangelogStats
    @days_back INT = 7
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @cutoff_utc DATETIME2(3) = DATEADD(DAY, -@days_back, SYSUTCDATETIME());

    -- Daily volume by table
    SELECT
        CAST(change_utc AS DATE) AS change_date,
        source_table,
        change_type,
        COUNT(*) AS change_count,
        COUNT(DISTINCT flight_uid) AS flights_affected
    FROM dbo.adl_flight_changelog
    WHERE change_utc >= @cutoff_utc
    GROUP BY CAST(change_utc AS DATE), source_table, change_type
    ORDER BY change_date DESC, source_table, change_type;

    -- Field-level breakdown
    SELECT TOP 20
        source_table,
        field_name,
        COUNT(*) AS change_count,
        COUNT(DISTINCT flight_uid) AS flights_affected
    FROM dbo.adl_flight_changelog
    WHERE change_utc >= @cutoff_utc
    GROUP BY source_table, field_name
    ORDER BY change_count DESC;

    -- Hourly trend (last 24 hours)
    SELECT
        DATEADD(HOUR, DATEDIFF(HOUR, 0, change_utc), 0) AS hour_bucket,
        COUNT(*) AS changes,
        COUNT(DISTINCT flight_uid) AS flights
    FROM dbo.adl_flight_changelog
    WHERE change_utc >= DATEADD(HOUR, -24, SYSUTCDATETIME())
    GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, change_utc), 0)
    ORDER BY hour_bucket DESC;

    -- Storage estimate
    SELECT
        COUNT(*) AS total_rows,
        COUNT(*) / NULLIF(@days_back, 0) AS avg_daily_rows,
        (SELECT SUM(reserved_page_count) * 8 / 1024.0
         FROM sys.dm_db_partition_stats
         WHERE object_id = OBJECT_ID('dbo.adl_flight_changelog')) AS size_mb;
END;
GO

PRINT 'Created procedure dbo.sp_GetChangelogStats';
GO

-- ============================================================================
-- Procedure: sp_GetRecentChanges
-- Returns recent changes with optional filtering
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_GetRecentChanges
    @minutes_back INT = 60,
    @source_table NVARCHAR(50) = NULL,
    @field_name NVARCHAR(50) = NULL,
    @change_type CHAR(1) = NULL,
    @change_reason NVARCHAR(50) = NULL,
    @limit INT = 1000
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @cutoff_utc DATETIME2(3) = DATEADD(MINUTE, -@minutes_back, SYSUTCDATETIME());

    SELECT TOP (@limit)
        cl.changelog_id,
        cl.flight_uid,
        cl.callsign,
        cl.change_utc,
        cl.change_type,
        cl.source_table,
        cl.field_name,
        cl.old_value,
        cl.new_value,
        cl.change_reason,
        -- Additional context
        fc.phase,
        fp.fp_dept_icao,
        fp.fp_dest_icao
    FROM dbo.adl_flight_changelog cl
    LEFT JOIN dbo.adl_flight_core fc ON fc.flight_uid = cl.flight_uid
    LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = cl.flight_uid
    WHERE cl.change_utc >= @cutoff_utc
      AND (@source_table IS NULL OR cl.source_table = @source_table)
      AND (@field_name IS NULL OR cl.field_name = @field_name)
      AND (@change_type IS NULL OR cl.change_type = @change_type)
      AND (@change_reason IS NULL OR cl.change_reason = @change_reason)
    ORDER BY cl.change_utc DESC;
END;
GO

PRINT 'Created procedure dbo.sp_GetRecentChanges';
GO

-- ============================================================================
-- Procedure: sp_GetRouteAmendments
-- Returns route amendment history for flights
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_GetRouteAmendments
    @dest_icao CHAR(4) = NULL,
    @dept_icao CHAR(4) = NULL,
    @hours_back INT = 24,
    @limit INT = 100
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @cutoff_utc DATETIME2(3) = DATEADD(HOUR, -@hours_back, SYSUTCDATETIME());

    SELECT TOP (@limit)
        cl.callsign,
        cl.change_utc,
        cl.old_value AS old_route,
        cl.new_value AS new_route,
        fp.fp_dept_icao,
        fp.fp_dest_icao,
        fp.dp_name,
        fp.star_name,
        fc.phase
    FROM dbo.adl_flight_changelog cl
    JOIN dbo.adl_flight_plan fp ON fp.flight_uid = cl.flight_uid
    JOIN dbo.adl_flight_core fc ON fc.flight_uid = cl.flight_uid
    WHERE cl.source_table = 'adl_flight_plan'
      AND cl.field_name = 'fp_route'
      AND cl.change_type = 'U'
      AND cl.change_utc >= @cutoff_utc
      AND (@dest_icao IS NULL OR fp.fp_dest_icao = @dest_icao)
      AND (@dept_icao IS NULL OR fp.fp_dept_icao = @dept_icao)
    ORDER BY cl.change_utc DESC;
END;
GO

PRINT 'Created procedure dbo.sp_GetRouteAmendments';
GO

-- ============================================================================
-- Procedure: sp_GetPhaseTransitions
-- Returns phase/status transition history
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_GetPhaseTransitions
    @callsign NVARCHAR(16) = NULL,
    @flight_uid BIGINT = NULL,
    @hours_back INT = 24
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @cutoff_utc DATETIME2(3) = DATEADD(HOUR, -@hours_back, SYSUTCDATETIME());

    SELECT
        cl.flight_uid,
        cl.callsign,
        cl.change_utc,
        cl.field_name,
        cl.old_value AS from_state,
        cl.new_value AS to_state,
        DATEDIFF(SECOND, LAG(cl.change_utc) OVER (PARTITION BY cl.flight_uid ORDER BY cl.change_utc), cl.change_utc) AS duration_seconds
    FROM dbo.adl_flight_changelog cl
    WHERE cl.change_type = 'S'
      AND cl.field_name IN ('phase', 'flight_status')
      AND cl.change_utc >= @cutoff_utc
      AND (
            (@flight_uid IS NOT NULL AND cl.flight_uid = @flight_uid)
            OR (@callsign IS NOT NULL AND cl.callsign = @callsign)
            OR (@flight_uid IS NULL AND @callsign IS NULL)
          )
    ORDER BY cl.flight_uid, cl.change_utc ASC;
END;
GO

PRINT 'Created procedure dbo.sp_GetPhaseTransitions';
GO

PRINT '';
PRINT '=== ADL Changelog Migration 006 Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
