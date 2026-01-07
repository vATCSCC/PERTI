-- ============================================================================
-- Migration 086: Parse Queue Cleanup Procedure
--
-- Adds cleanup functionality for orphaned parse queue entries.
-- The queue accumulates entries for flights that become inactive before
-- their routes are parsed, leading to permanent backlog.
--
-- Cleanup criteria:
-- 1. Remove PENDING entries for inactive flights (is_active = 0)
-- 2. Remove PENDING entries older than 2 hours (stale)
-- 3. Remove FAILED entries older than 4 hours
-- 4. Remove PROCESSING entries older than 30 minutes (stuck)
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

-- ============================================================================
-- sp_CleanupParseQueue - Remove orphaned/stale queue entries
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.sp_CleanupParseQueue') AND type = 'P')
BEGIN
    DROP PROCEDURE dbo.sp_CleanupParseQueue;
END
GO

CREATE PROCEDURE dbo.sp_CleanupParseQueue
    @dry_run BIT = 0  -- Set to 1 to see what would be deleted without deleting
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @now DATETIME2(3) = SYSUTCDATETIME();
    DECLARE @deleted_inactive INT = 0;
    DECLARE @deleted_stale INT = 0;
    DECLARE @deleted_failed INT = 0;
    DECLARE @deleted_stuck INT = 0;
    DECLARE @total_deleted INT = 0;

    -- =========================================================================
    -- 1. Remove PENDING entries for inactive flights
    -- =========================================================================

    IF @dry_run = 1
    BEGIN
        SELECT @deleted_inactive = COUNT(*)
        FROM dbo.adl_parse_queue q
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = q.flight_uid
        WHERE q.status = 'PENDING'
          AND (c.flight_uid IS NULL OR c.is_active = 0);
    END
    ELSE
    BEGIN
        DELETE q
        FROM dbo.adl_parse_queue q
        LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = q.flight_uid
        WHERE q.status = 'PENDING'
          AND (c.flight_uid IS NULL OR c.is_active = 0);

        SET @deleted_inactive = @@ROWCOUNT;
    END

    -- =========================================================================
    -- 2. Remove PENDING entries older than 2 hours (stale)
    -- =========================================================================

    IF @dry_run = 1
    BEGIN
        SELECT @deleted_stale = COUNT(*)
        FROM dbo.adl_parse_queue
        WHERE status = 'PENDING'
          AND queued_utc < DATEADD(HOUR, -2, @now);
    END
    ELSE
    BEGIN
        DELETE FROM dbo.adl_parse_queue
        WHERE status = 'PENDING'
          AND queued_utc < DATEADD(HOUR, -2, @now);

        SET @deleted_stale = @@ROWCOUNT;
    END

    -- =========================================================================
    -- 3. Remove FAILED entries older than 4 hours
    -- =========================================================================

    IF @dry_run = 1
    BEGIN
        SELECT @deleted_failed = COUNT(*)
        FROM dbo.adl_parse_queue
        WHERE status = 'FAILED'
          AND queued_utc < DATEADD(HOUR, -4, @now);
    END
    ELSE
    BEGIN
        DELETE FROM dbo.adl_parse_queue
        WHERE status = 'FAILED'
          AND queued_utc < DATEADD(HOUR, -4, @now);

        SET @deleted_failed = @@ROWCOUNT;
    END

    -- =========================================================================
    -- 4. Remove PROCESSING entries older than 30 minutes (stuck)
    -- =========================================================================

    IF @dry_run = 1
    BEGIN
        SELECT @deleted_stuck = COUNT(*)
        FROM dbo.adl_parse_queue
        WHERE status = 'PROCESSING'
          AND started_utc < DATEADD(MINUTE, -30, @now);
    END
    ELSE
    BEGIN
        DELETE FROM dbo.adl_parse_queue
        WHERE status = 'PROCESSING'
          AND started_utc < DATEADD(MINUTE, -30, @now);

        SET @deleted_stuck = @@ROWCOUNT;
    END

    SET @total_deleted = @deleted_inactive + @deleted_stale + @deleted_failed + @deleted_stuck;

    -- Return cleanup stats
    SELECT
        @deleted_inactive AS deleted_inactive_flights,
        @deleted_stale AS deleted_stale_pending,
        @deleted_failed AS deleted_old_failed,
        @deleted_stuck AS deleted_stuck_processing,
        @total_deleted AS total_cleaned,
        @dry_run AS was_dry_run,
        (SELECT COUNT(*) FROM dbo.adl_parse_queue WHERE status = 'PENDING') AS remaining_pending;
END
GO

PRINT 'Created procedure dbo.sp_CleanupParseQueue';
GO

-- ============================================================================
-- Update sp_ProcessParseQueue to include cleanup
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.sp_ProcessParseQueue') AND type = 'P')
BEGIN
    DROP PROCEDURE dbo.sp_ProcessParseQueue;
END
GO

CREATE PROCEDURE dbo.sp_ProcessParseQueue
    @tier           TINYINT = NULL,      -- NULL = all eligible tiers
    @batch_size     INT = 50
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @now DATETIME2(3) = SYSUTCDATETIME();
    DECLARE @processed INT = 0;
    DECLARE @failed INT = 0;

    -- =========================================================================
    -- 0. Run cleanup first (every execution)
    -- =========================================================================

    -- Delete orphaned entries (inactive flights)
    DELETE q
    FROM dbo.adl_parse_queue q
    LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = q.flight_uid
    WHERE q.status = 'PENDING'
      AND (c.flight_uid IS NULL OR c.is_active = 0);

    -- Delete stale entries (older than 2 hours)
    DELETE FROM dbo.adl_parse_queue
    WHERE status = 'PENDING'
      AND queued_utc < DATEADD(HOUR, -2, @now);

    -- Reset stuck processing entries
    UPDATE dbo.adl_parse_queue
    SET status = 'PENDING', started_utc = NULL
    WHERE status = 'PROCESSING'
      AND started_utc < DATEADD(MINUTE, -10, @now);

    -- =========================================================================
    -- 1. Claim a batch of work (only for ACTIVE flights)
    -- =========================================================================

    DECLARE @batch TABLE (
        queue_id BIGINT,
        flight_uid BIGINT
    );

    UPDATE TOP (@batch_size) q
    SET status = 'PROCESSING',
        started_utc = @now,
        attempts = attempts + 1
    OUTPUT inserted.queue_id, inserted.flight_uid INTO @batch
    FROM dbo.adl_parse_queue q
    INNER JOIN dbo.adl_flight_core c ON c.flight_uid = q.flight_uid AND c.is_active = 1
    WHERE q.status = 'PENDING'
      AND q.next_eligible_utc <= @now
      AND (@tier IS NULL OR q.parse_tier = @tier);

    IF @@ROWCOUNT = 0
    BEGIN
        SELECT 0 AS processed, 0 AS failed, 'No work available' AS message;
        RETURN;
    END

    -- =========================================================================
    -- 2. Process each flight
    -- =========================================================================

    DECLARE @flight_uid BIGINT, @queue_id BIGINT;
    DECLARE @route NVARCHAR(MAX), @remarks NVARCHAR(MAX);
    DECLARE @dept CHAR(4), @dest CHAR(4), @altitude INT;

    DECLARE flight_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT b.queue_id, b.flight_uid, fp.fp_route, fp.fp_remarks,
               fp.fp_dept_icao, fp.fp_dest_icao, fp.fp_altitude_ft
        FROM @batch b
        JOIN dbo.adl_flight_plan fp ON fp.flight_uid = b.flight_uid;

    OPEN flight_cursor;
    FETCH NEXT FROM flight_cursor INTO
        @queue_id, @flight_uid, @route, @remarks, @dept, @dest, @altitude;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        BEGIN TRY
            -- Parse runway specifications
            DECLARE @dep_runway NVARCHAR(4) = NULL;
            DECLARE @arr_runway NVARCHAR(4) = NULL;
            DECLARE @is_simbrief BIT = 0;
            DECLARE @simbrief_id NVARCHAR(32) = NULL;
            DECLARE @cost_index INT = NULL;

            -- Check for SimBrief markers
            IF @remarks LIKE '%SIMBRIEF%' OR @remarks LIKE '%OFP/%' OR
               @remarks LIKE '%/SB%' OR @remarks LIKE '%CI[0-9]%'
            BEGIN
                SET @is_simbrief = 1;

                IF @remarks LIKE '%OFP/%'
                BEGIN
                    SET @simbrief_id = SUBSTRING(@remarks,
                        CHARINDEX('OFP/', @remarks) + 4,
                        CHARINDEX(' ', @remarks + ' ', CHARINDEX('OFP/', @remarks) + 4) - CHARINDEX('OFP/', @remarks) - 4);
                END

                IF @remarks LIKE '%CI[0-9]%'
                BEGIN
                    DECLARE @ci_pos INT = PATINDEX('%CI[0-9]%', @remarks);
                    IF @ci_pos > 0
                    BEGIN
                        SET @cost_index = TRY_CAST(
                            SUBSTRING(@remarks, @ci_pos + 2,
                                PATINDEX('%[^0-9]%', SUBSTRING(@remarks, @ci_pos + 2, 10) + ' ') - 1
                            ) AS INT);
                    END
                END
            END

            -- Extract departure runway from route prefix
            IF @route LIKE '[A-Z][A-Z][A-Z][A-Z]/[0-9]%'
            BEGIN
                SET @dep_runway = SUBSTRING(@route, 6,
                    CHARINDEX(' ', @route + ' ', 6) - 6);
                IF LEN(@dep_runway) > 4 SET @dep_runway = LEFT(@dep_runway, 4);
            END

            IF @dep_runway IS NULL AND @remarks LIKE '%DEP/RWY[0-9]%'
            BEGIN
                DECLARE @dep_pos INT = CHARINDEX('DEP/RWY', @remarks);
                SET @dep_runway = SUBSTRING(@remarks, @dep_pos + 7, 4);
                SET @dep_runway = LEFT(@dep_runway, PATINDEX('%[^0-9A-Z]%', @dep_runway + ' ') - 1);
            END

            IF @remarks LIKE '%ARR/RWY[0-9]%'
            BEGIN
                DECLARE @arr_pos INT = CHARINDEX('ARR/RWY', @remarks);
                SET @arr_runway = SUBSTRING(@remarks, @arr_pos + 7, 4);
                SET @arr_runway = LEFT(@arr_runway, PATINDEX('%[^0-9A-Z]%', @arr_runway + ' ') - 1);
            END

            -- Step climb parsing
            DECLARE @stepclimb_count INT = 0;
            DECLARE @initial_alt INT = @altitude;
            DECLARE @final_alt INT = @altitude;

            DECLARE @search_pos INT = 1;
            WHILE CHARINDEX('/F', @route, @search_pos) > 0
            BEGIN
                SET @search_pos = CHARINDEX('/F', @route, @search_pos) + 2;
                IF SUBSTRING(@route, @search_pos, 1) LIKE '[0-9]'
                BEGIN
                    SET @stepclimb_count = @stepclimb_count + 1;

                    DECLARE @step_alt INT = TRY_CAST(
                        SUBSTRING(@route, @search_pos, 3) AS INT) * 100;
                    IF @step_alt IS NOT NULL
                    BEGIN
                        IF @final_alt IS NULL OR @step_alt > @final_alt
                            SET @final_alt = @step_alt;
                    END
                END
            END

            IF @remarks LIKE '%STP/%' OR @remarks LIKE '%STEPCLIMB%'
            BEGIN
                SET @search_pos = 1;
                WHILE CHARINDEX('FL', @remarks, @search_pos) > 0
                BEGIN
                    SET @search_pos = CHARINDEX('FL', @remarks, @search_pos) + 2;
                    IF SUBSTRING(@remarks, @search_pos, 1) LIKE '[0-9]'
                    BEGIN
                        SET @stepclimb_count = @stepclimb_count + 1;
                    END
                END
            END

            -- Update flight plan
            UPDATE dbo.adl_flight_plan
            SET
                dep_runway = @dep_runway,
                dep_runway_source = CASE WHEN @dep_runway IS NOT NULL
                    THEN CASE WHEN @is_simbrief = 1 THEN 'SIMBRIEF' ELSE 'ROUTE' END
                    ELSE NULL END,
                arr_runway = @arr_runway,
                arr_runway_source = CASE WHEN @arr_runway IS NOT NULL
                    THEN CASE WHEN @is_simbrief = 1 THEN 'SIMBRIEF' ELSE 'REMARKS' END
                    ELSE NULL END,
                is_simbrief = @is_simbrief,
                simbrief_id = @simbrief_id,
                cost_index = @cost_index,
                initial_alt_ft = @initial_alt,
                final_alt_ft = @final_alt,
                stepclimb_count = @stepclimb_count,
                parse_status = 'PARTIAL',
                parse_utc = SYSUTCDATETIME(),
                fp_hash = HASHBYTES('SHA2_256', ISNULL(@route, '') + '|' + ISNULL(@remarks, ''))
            WHERE flight_uid = @flight_uid;

            -- Mark queue entry complete
            UPDATE dbo.adl_parse_queue
            SET status = 'COMPLETE', completed_utc = SYSUTCDATETIME()
            WHERE queue_id = @queue_id;

            SET @processed = @processed + 1;

        END TRY
        BEGIN CATCH
            UPDATE dbo.adl_parse_queue
            SET status = 'FAILED',
                error_message = LEFT(ERROR_MESSAGE(), 512),
                next_eligible_utc = DATEADD(MINUTE, attempts * 5, SYSUTCDATETIME())
            WHERE queue_id = @queue_id;

            SET @failed = @failed + 1;
        END CATCH;

        FETCH NEXT FROM flight_cursor INTO
            @queue_id, @flight_uid, @route, @remarks, @dept, @dest, @altitude;
    END;

    CLOSE flight_cursor;
    DEALLOCATE flight_cursor;

    -- =========================================================================
    -- 3. Cleanup old completed entries (older than 1 hour)
    -- =========================================================================

    DELETE FROM dbo.adl_parse_queue
    WHERE status = 'COMPLETE'
      AND completed_utc < DATEADD(HOUR, -1, SYSUTCDATETIME());

    SELECT @processed AS processed, @failed AS failed,
           'Processed ' + CAST(@processed AS VARCHAR) + ' routes, ' +
           CAST(@failed AS VARCHAR) + ' failed' AS message;
END
GO

PRINT 'Updated procedure dbo.sp_ProcessParseQueue with cleanup logic';
GO

-- ============================================================================
-- Run initial cleanup to clear the backlog
-- ============================================================================

PRINT 'Running initial cleanup (dry run first)...';
EXEC dbo.sp_CleanupParseQueue @dry_run = 1;
GO

PRINT '';
PRINT 'To actually clean up the backlog, run:';
PRINT '  EXEC dbo.sp_CleanupParseQueue @dry_run = 0;';
PRINT '';
GO
