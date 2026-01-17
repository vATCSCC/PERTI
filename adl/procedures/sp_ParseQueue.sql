-- ============================================================================
-- ADL Parse Queue Management Procedures
-- 
-- Handles queuing and processing of routes for GIS parsing
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

-- ============================================================================
-- sp_QueueRouteParsing - Queue routes for parsing based on tier
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.sp_QueueRouteParsing') AND type = 'P')
BEGIN
    DROP PROCEDURE dbo.sp_QueueRouteParsing;
END
GO

CREATE PROCEDURE dbo.sp_QueueRouteParsing
    @process_tier_0 BIT = 1  -- Whether to immediately process Tier 0
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @now DATETIME2(3) = SYSUTCDATETIME();
    DECLARE @tier0_count INT = 0;
    
    -- ═══════════════════════════════════════════════════════════════════════
    -- 1. Identify flights needing (re)parsing
    -- ═══════════════════════════════════════════════════════════════════════
    
    ;WITH flights_to_parse AS (
        SELECT 
            c.flight_uid,
            fp.fp_dept_icao,
            fp.fp_dest_icao,
            p.lat,
            p.lon,
            fp.fp_route,
            fp.fp_remarks,
            HASHBYTES('SHA2_256', ISNULL(fp.fp_route, '') + '|' + ISNULL(fp.fp_remarks, '')) AS new_hash
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
        WHERE c.is_active = 1
          AND fp.fp_route IS NOT NULL
          AND LEN(fp.fp_route) > 0
          -- Only if route has changed or never parsed
          AND (
              fp.fp_hash IS NULL 
              OR fp.fp_hash != HASHBYTES('SHA2_256', ISNULL(fp.fp_route, '') + '|' + ISNULL(fp.fp_remarks, ''))
              OR fp.parse_status IS NULL
          )
          -- Not already in queue
          AND NOT EXISTS (
              SELECT 1 FROM dbo.adl_parse_queue q 
              WHERE q.flight_uid = c.flight_uid 
                AND q.status = 'PENDING'
          )
    )
    INSERT INTO dbo.adl_parse_queue (
        flight_uid, 
        parse_tier, 
        queued_utc, 
        next_eligible_utc,
        route_hash
    )
    SELECT 
        flight_uid,
        dbo.fn_GetParseTier(fp_dept_icao, fp_dest_icao, lat, lon) AS parse_tier,
        @now,
        CASE dbo.fn_GetParseTier(fp_dept_icao, fp_dest_icao, lat, lon)
            WHEN 0 THEN @now                              -- Immediate
            WHEN 1 THEN DATEADD(SECOND, 30, @now)         -- 30 seconds
            WHEN 2 THEN DATEADD(SECOND, 60, @now)         -- 1 minute
            WHEN 3 THEN DATEADD(SECOND, 120, @now)        -- 2 minutes
            WHEN 4 THEN DATEADD(SECOND, 300, @now)        -- 5 minutes
            ELSE DATEADD(SECOND, 300, @now)
        END,
        new_hash
    FROM flights_to_parse;
    
    -- ═══════════════════════════════════════════════════════════════════════
    -- 2. Update parse_tier in flight_plan table
    -- ═══════════════════════════════════════════════════════════════════════
    
    UPDATE fp
    SET parse_tier = q.parse_tier
    FROM dbo.adl_flight_plan fp
    JOIN dbo.adl_parse_queue q ON q.flight_uid = fp.flight_uid
    WHERE q.status = 'PENDING'
      AND q.queued_utc = @now;
    
    -- ═══════════════════════════════════════════════════════════════════════
    -- 3. Process Tier 0 immediately if requested
    -- ═══════════════════════════════════════════════════════════════════════
    
    IF @process_tier_0 = 1
    BEGIN
        EXEC dbo.sp_ProcessParseQueue @tier = 0, @batch_size = 100;
        
        SELECT @tier0_count = COUNT(*) 
        FROM dbo.adl_parse_queue 
        WHERE parse_tier = 0 
          AND status = 'COMPLETE'
          AND completed_utc >= @now;
    END
    
    -- Return stats
    SELECT 
        (SELECT COUNT(*) FROM dbo.adl_parse_queue WHERE queued_utc = @now) AS flights_queued,
        @tier0_count AS tier0_processed,
        (SELECT COUNT(*) FROM dbo.adl_parse_queue WHERE status = 'PENDING') AS total_pending;
END
GO

PRINT 'Created procedure dbo.sp_QueueRouteParsing';
GO

-- ============================================================================
-- sp_ProcessParseQueue - Process queued routes by tier
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
    
    -- ═══════════════════════════════════════════════════════════════════════
    -- 1. Claim a batch of work
    -- ═══════════════════════════════════════════════════════════════════════
    
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
    WHERE q.status = 'PENDING'
      AND q.next_eligible_utc <= @now
      AND (@tier IS NULL OR q.parse_tier = @tier);
    
    IF @@ROWCOUNT = 0
    BEGIN
        SELECT 0 AS processed, 0 AS failed, 'No work available' AS message;
        RETURN;
    END
    
    -- ═══════════════════════════════════════════════════════════════════════
    -- 2. Process each flight
    -- ═══════════════════════════════════════════════════════════════════════
    
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
            -- ═══════════════════════════════════════════════════════════════
            -- Parse runway specifications
            -- ═══════════════════════════════════════════════════════════════
            
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
                
                -- Extract SimBrief OFP ID if present
                IF @remarks LIKE '%OFP/%'
                BEGIN
                    SET @simbrief_id = SUBSTRING(@remarks, 
                        CHARINDEX('OFP/', @remarks) + 4,
                        CHARINDEX(' ', @remarks + ' ', CHARINDEX('OFP/', @remarks) + 4) - CHARINDEX('OFP/', @remarks) - 4);
                END
                
                -- Extract cost index
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
            
            -- Extract departure runway from route prefix (KJFK/04L)
            IF @route LIKE '[A-Z][A-Z][A-Z][A-Z]/[0-9]%'
            BEGIN
                SET @dep_runway = SUBSTRING(@route, 6, 
                    CHARINDEX(' ', @route + ' ', 6) - 6);
                IF LEN(@dep_runway) > 4 SET @dep_runway = LEFT(@dep_runway, 4);
            END
            
            -- Extract from remarks (DEP/RWY04L or /DEP/KJFK04L)
            IF @dep_runway IS NULL AND @remarks LIKE '%DEP/RWY[0-9]%'
            BEGIN
                DECLARE @dep_pos INT = CHARINDEX('DEP/RWY', @remarks);
                SET @dep_runway = SUBSTRING(@remarks, @dep_pos + 7, 4);
                SET @dep_runway = LEFT(@dep_runway, PATINDEX('%[^0-9A-Z]%', @dep_runway + ' ') - 1);
            END
            
            -- Extract arrival runway
            IF @remarks LIKE '%ARR/RWY[0-9]%'
            BEGIN
                DECLARE @arr_pos INT = CHARINDEX('ARR/RWY', @remarks);
                SET @arr_runway = SUBSTRING(@remarks, @arr_pos + 7, 4);
                SET @arr_runway = LEFT(@arr_runway, PATINDEX('%[^0-9A-Z]%', @arr_runway + ' ') - 1);
            END
            
            -- ═══════════════════════════════════════════════════════════════
            -- Extract step climb count (simple version)
            -- Full step climb parsing will be in sp_ParseRoute
            -- ═══════════════════════════════════════════════════════════════
            
            DECLARE @stepclimb_count INT = 0;
            DECLARE @initial_alt INT = @altitude;
            DECLARE @final_alt INT = @altitude;
            
            -- Count /F### patterns in route
            DECLARE @search_pos INT = 1;
            WHILE CHARINDEX('/F', @route, @search_pos) > 0
            BEGIN
                SET @search_pos = CHARINDEX('/F', @route, @search_pos) + 2;
                IF SUBSTRING(@route, @search_pos, 1) LIKE '[0-9]'
                BEGIN
                    SET @stepclimb_count = @stepclimb_count + 1;
                    
                    -- Extract altitude
                    DECLARE @step_alt INT = TRY_CAST(
                        SUBSTRING(@route, @search_pos, 3) AS INT) * 100;
                    IF @step_alt IS NOT NULL
                    BEGIN
                        IF @final_alt IS NULL OR @step_alt > @final_alt
                            SET @final_alt = @step_alt;
                    END
                END
            END
            
            -- Also check remarks for STP/FL### patterns
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
            
            -- ═══════════════════════════════════════════════════════════════
            -- Update flight plan with parsed data
            -- (Full GIS geometry parsing will be added later)
            -- ═══════════════════════════════════════════════════════════════
            
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
                parse_status = 'PARTIAL',  -- Will be COMPLETE when full GIS parsing added
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
            -- Mark failed with retry
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
    
    -- ═══════════════════════════════════════════════════════════════════════
    -- 3. Cleanup old completed entries (older than 24 hours)
    -- Retain for full day to support daily tier breakdown stats on status page
    -- ═══════════════════════════════════════════════════════════════════════

    DELETE FROM dbo.adl_parse_queue
    WHERE status = 'COMPLETE'
      AND completed_utc < DATEADD(HOUR, -24, SYSUTCDATETIME());
    
    -- Return stats
    SELECT @processed AS processed, @failed AS failed,
           'Processed ' + CAST(@processed AS VARCHAR) + ' routes, ' + 
           CAST(@failed AS VARCHAR) + ' failed' AS message;
END
GO

PRINT 'Created procedure dbo.sp_ProcessParseQueue';
GO

-- ============================================================================
-- sp_GetParseQueueStatus - Get current queue status by tier
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.sp_GetParseQueueStatus') AND type = 'P')
BEGIN
    DROP PROCEDURE dbo.sp_GetParseQueueStatus;
END
GO

CREATE PROCEDURE dbo.sp_GetParseQueueStatus
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        parse_tier,
        COUNT(CASE WHEN status = 'PENDING' THEN 1 END) AS pending,
        COUNT(CASE WHEN status = 'PROCESSING' THEN 1 END) AS processing,
        COUNT(CASE WHEN status = 'COMPLETE' THEN 1 END) AS completed,
        COUNT(CASE WHEN status = 'FAILED' THEN 1 END) AS failed,
        MIN(CASE WHEN status = 'PENDING' THEN queued_utc END) AS oldest_pending,
        AVG(CASE WHEN status = 'COMPLETE' 
            THEN DATEDIFF(SECOND, queued_utc, completed_utc) END) AS avg_latency_sec
    FROM dbo.adl_parse_queue
    WHERE queued_utc > DATEADD(HOUR, -2, SYSUTCDATETIME())
    GROUP BY parse_tier
    ORDER BY parse_tier;
END
GO

PRINT 'Created procedure dbo.sp_GetParseQueueStatus';
GO
