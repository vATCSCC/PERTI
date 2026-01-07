-- ============================================================================
-- ADL Archive System - Migration 001: Archive Tables and Configuration
-- 
-- Creates the archive infrastructure tables
-- Run Order: 1 of 3
-- Target Database: VATSIM_ADL
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Archive Migration 001: Tables and Config ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. adl_archive_config - Runtime Configuration
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_archive_config') AND type = 'U')
BEGIN
    CREATE TABLE dbo.adl_archive_config (
        config_key              NVARCHAR(64) NOT NULL,
        config_value            NVARCHAR(256) NOT NULL,
        description             NVARCHAR(512) NULL,
        updated_utc             DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        
        CONSTRAINT PK_adl_archive_config PRIMARY KEY (config_key)
    );
    
    PRINT 'Created table dbo.adl_archive_config';
    
    -- Insert default configuration
    INSERT INTO dbo.adl_archive_config (config_key, config_value, description) VALUES
    ('TRAJECTORY_LOG_INTERVAL_SEC', '60', 'Seconds between trajectory position logs (default: 60)'),
    ('TRAJECTORY_HOT_HOURS', '24', 'Hours to keep full-resolution trajectory in live table'),
    ('TRAJECTORY_WARM_DAYS', '7', 'Days to keep 60-sec trajectory in warm archive tier'),
    ('TRAJECTORY_COLD_DAYS', '90', 'Days to keep 5-min trajectory in cold archive tier before purge'),
    ('CHANGELOG_RETENTION_DAYS', '90', 'Days to retain changelog entries before purge'),
    ('FLIGHT_ARCHIVE_RETENTION_DAYS', '365', 'Days to retain archived flight records'),
    ('RUN_LOG_RETENTION_DAYS', '30', 'Days to retain run log entries'),
    ('ARCHIVE_BATCH_SIZE', '1000', 'Rows to process per batch in archive jobs'),
    ('DOWNSAMPLE_COLD_INTERVAL_SEC', '300', 'Target interval (seconds) for cold-tier downsampling'),
    ('COMPLETED_FLIGHT_DELAY_HOURS', '2', 'Hours after last_seen before archiving a completed flight');
    
    PRINT 'Inserted default configuration values';
END
ELSE
BEGIN
    PRINT 'Table dbo.adl_archive_config already exists - skipping';
END
GO

-- ============================================================================
-- 2. adl_archive_log - Job Execution History
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_archive_log') AND type = 'U')
BEGIN
    CREATE TABLE dbo.adl_archive_log (
        log_id                  INT IDENTITY(1,1) NOT NULL,
        job_name                NVARCHAR(64) NOT NULL,
        started_utc             DATETIME2(3) NOT NULL,
        ended_utc               DATETIME2(3) NULL,
        duration_ms             INT NULL,
        
        -- Metrics
        rows_processed          INT NULL,
        rows_archived           INT NULL,
        rows_deleted            INT NULL,
        bytes_freed             BIGINT NULL,
        
        -- Status
        status                  NVARCHAR(16) NOT NULL DEFAULT 'RUNNING',
        error_message           NVARCHAR(MAX) NULL,
        
        CONSTRAINT PK_adl_archive_log PRIMARY KEY CLUSTERED (log_id)
    );
    
    CREATE NONCLUSTERED INDEX IX_archive_log_job ON dbo.adl_archive_log (job_name, started_utc DESC);
    CREATE NONCLUSTERED INDEX IX_archive_log_status ON dbo.adl_archive_log (status) WHERE status <> 'SUCCESS';
    
    PRINT 'Created table dbo.adl_archive_log';
END
ELSE
BEGIN
    PRINT 'Table dbo.adl_archive_log already exists - skipping';
END
GO

-- ============================================================================
-- 3. adl_flight_archive - Denormalized Completed Flights
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flight_archive') AND type = 'U')
BEGIN
    CREATE TABLE dbo.adl_flight_archive (
        -- Archive metadata
        archive_id              BIGINT IDENTITY(1,1) NOT NULL,
        archived_utc            DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        
        -- From adl_flight_core
        flight_uid              BIGINT NOT NULL,
        flight_key              NVARCHAR(64) NOT NULL,
        cid                     INT NOT NULL,
        callsign                NVARCHAR(16) NOT NULL,
        phase                   NVARCHAR(16) NULL,
        flight_status           NVARCHAR(32) NULL,
        first_seen_utc          DATETIME2(0) NULL,
        last_seen_utc           DATETIME2(0) NULL,
        logon_time_utc          DATETIME2(0) NULL,
        
        -- From adl_flight_plan
        fp_dept_icao            CHAR(4) NULL,
        fp_dest_icao            CHAR(4) NULL,
        fp_alt_icao             CHAR(4) NULL,
        fp_route                NVARCHAR(MAX) NULL,
        fp_altitude_ft          INT NULL,
        dp_name                 NVARCHAR(16) NULL,
        star_name               NVARCHAR(16) NULL,
        dfix                    NVARCHAR(8) NULL,
        afix                    NVARCHAR(8) NULL,
        fp_dept_artcc           NVARCHAR(4) NULL,
        fp_dest_artcc           NVARCHAR(4) NULL,
        fp_dept_tracon          NVARCHAR(4) NULL,
        fp_dest_tracon          NVARCHAR(4) NULL,
        gcd_nm                  DECIMAL(8,2) NULL,
        aircraft_type           NVARCHAR(8) NULL,
        
        -- From adl_flight_aircraft
        aircraft_icao           NVARCHAR(8) NULL,
        weight_class            NCHAR(1) NULL,
        engine_type             NVARCHAR(8) NULL,
        wake_category           NVARCHAR(8) NULL,
        airline_icao            NVARCHAR(4) NULL,
        airline_name            NVARCHAR(64) NULL,
        
        -- From adl_flight_times (key times only)
        etd_utc                 DATETIME2(0) NULL,
        eta_utc                 DATETIME2(0) NULL,
        atd_utc                 DATETIME2(0) NULL,
        ata_utc                 DATETIME2(0) NULL,
        
        -- From adl_flight_tmi
        ctl_type                NVARCHAR(8) NULL,
        delay_minutes           INT NULL,
        edct_utc                DATETIME2(0) NULL,
        
        -- Computed at archive time
        flight_duration_min     INT NULL,
        total_distance_nm       DECIMAL(8,2) NULL,
        avg_groundspeed_kts     INT NULL,
        max_altitude_ft         INT NULL,
        trajectory_points       INT NULL,
        changelog_entries       INT NULL,
        
        CONSTRAINT PK_adl_flight_archive PRIMARY KEY CLUSTERED (archive_id)
    );
    
    CREATE NONCLUSTERED INDEX IX_archive_flight_uid ON dbo.adl_flight_archive (flight_uid);
    CREATE NONCLUSTERED INDEX IX_archive_callsign ON dbo.adl_flight_archive (callsign, archived_utc DESC);
    CREATE NONCLUSTERED INDEX IX_archive_date ON dbo.adl_flight_archive (archived_utc DESC);
    CREATE NONCLUSTERED INDEX IX_archive_dept_dest ON dbo.adl_flight_archive (fp_dept_icao, fp_dest_icao);
    CREATE NONCLUSTERED INDEX IX_archive_cid ON dbo.adl_flight_archive (cid, archived_utc DESC);
    CREATE NONCLUSTERED INDEX IX_archive_first_seen ON dbo.adl_flight_archive (first_seen_utc DESC);
    
    PRINT 'Created table dbo.adl_flight_archive';
END
ELSE
BEGIN
    PRINT 'Table dbo.adl_flight_archive already exists - skipping';
END
GO

-- ============================================================================
-- 4. adl_trajectory_archive - Downsampled Position History
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_trajectory_archive') AND type = 'U')
BEGIN
    CREATE TABLE dbo.adl_trajectory_archive (
        trajectory_archive_id   BIGINT IDENTITY(1,1) NOT NULL,
        
        -- Flight reference
        flight_uid              BIGINT NOT NULL,
        callsign                NVARCHAR(16) NULL,
        
        -- Position data
        timestamp_utc           DATETIME2(0) NOT NULL,
        lat                     DECIMAL(10,7) NOT NULL,
        lon                     DECIMAL(11,7) NOT NULL,
        altitude_ft             INT NULL,
        groundspeed_kts         INT NULL,
        heading_deg             SMALLINT NULL,
        vertical_rate_fpm       INT NULL,
        
        -- Archive metadata
        sample_interval_sec     INT NOT NULL DEFAULT 60,
        archived_utc            DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        source_tier             NVARCHAR(8) NOT NULL DEFAULT 'WARM',
        
        CONSTRAINT PK_adl_trajectory_archive PRIMARY KEY CLUSTERED (trajectory_archive_id)
    );
    
    CREATE NONCLUSTERED INDEX IX_traj_archive_flight ON dbo.adl_trajectory_archive (flight_uid, timestamp_utc DESC);
    CREATE NONCLUSTERED INDEX IX_traj_archive_time ON dbo.adl_trajectory_archive (timestamp_utc DESC);
    CREATE NONCLUSTERED INDEX IX_traj_archive_callsign ON dbo.adl_trajectory_archive (callsign, timestamp_utc DESC) WHERE callsign IS NOT NULL;
    CREATE NONCLUSTERED INDEX IX_traj_archive_tier ON dbo.adl_trajectory_archive (source_tier, archived_utc);
    
    PRINT 'Created table dbo.adl_trajectory_archive';
END
ELSE
BEGIN
    PRINT 'Table dbo.adl_trajectory_archive already exists - skipping';
END
GO

-- ============================================================================
-- 5. Helper View: Archive Statistics
-- ============================================================================

IF EXISTS (SELECT * FROM sys.views WHERE name = 'v_archive_stats')
    DROP VIEW dbo.v_archive_stats;
GO

CREATE VIEW dbo.v_archive_stats
AS
SELECT 
    'adl_flight_trajectory' AS table_name,
    COUNT(*) AS row_count,
    MIN(timestamp_utc) AS oldest_record,
    MAX(timestamp_utc) AS newest_record,
    'HOT' AS tier
FROM dbo.adl_flight_trajectory

UNION ALL

SELECT 
    'adl_trajectory_archive',
    COUNT(*),
    MIN(timestamp_utc),
    MAX(timestamp_utc),
    source_tier
FROM dbo.adl_trajectory_archive
GROUP BY source_tier

UNION ALL

SELECT 
    'adl_flight_archive',
    COUNT(*),
    MIN(archived_utc),
    MAX(archived_utc),
    'ARCHIVE'
FROM dbo.adl_flight_archive

UNION ALL

SELECT 
    'adl_flight_changelog',
    COUNT(*),
    MIN(changed_utc),
    MAX(changed_utc),
    'CHANGELOG'
FROM dbo.adl_flight_changelog;
GO

PRINT 'Created view dbo.v_archive_stats';
GO

-- ============================================================================
-- 6. Helper Function: Get Config Value
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.fn_GetArchiveConfig') AND type = 'FN')
    DROP FUNCTION dbo.fn_GetArchiveConfig;
GO

CREATE FUNCTION dbo.fn_GetArchiveConfig(@key NVARCHAR(64))
RETURNS NVARCHAR(256)
AS
BEGIN
    DECLARE @value NVARCHAR(256);
    SELECT @value = config_value FROM dbo.adl_archive_config WHERE config_key = @key;
    RETURN @value;
END;
GO

PRINT 'Created function dbo.fn_GetArchiveConfig';
GO

PRINT '';
PRINT '=== ADL Archive Migration 001 Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- END OF MIGRATION 001, BEGINNING MIGRATION 002
-- ============================================================================

-- ============================================================================
-- ADL Archive System - Migration 002: Archive Procedures
-- 
-- Creates all archive maintenance stored procedures
-- Run Order: 2 of 3
-- Depends on: 001_archive_tables.sql
-- Target Database: VATSIM_ADL
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Archive Migration 002: Procedures ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. sp_Archive_CompletedFlights
--    Archives flights that have completed (not seen for N hours)
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_Archive_CompletedFlights
    @hours_since_last_seen INT = NULL,
    @batch_size INT = NULL,
    @debug BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @start_utc DATETIME2(3) = SYSUTCDATETIME();
    DECLARE @log_id INT;
    DECLARE @rows_archived INT = 0;
    DECLARE @rows_deleted INT = 0;
    DECLARE @cutoff_utc DATETIME2(0);
    
    -- Get config values
    IF @hours_since_last_seen IS NULL
        SELECT @hours_since_last_seen = CAST(config_value AS INT)
        FROM dbo.adl_archive_config WHERE config_key = 'COMPLETED_FLIGHT_DELAY_HOURS';
    SET @hours_since_last_seen = ISNULL(@hours_since_last_seen, 2);
    
    IF @batch_size IS NULL
        SELECT @batch_size = CAST(config_value AS INT)
        FROM dbo.adl_archive_config WHERE config_key = 'ARCHIVE_BATCH_SIZE';
    SET @batch_size = ISNULL(@batch_size, 1000);
    
    SET @cutoff_utc = DATEADD(HOUR, -@hours_since_last_seen, SYSUTCDATETIME());
    
    IF @debug = 1
        PRINT 'Archiving flights not seen since: ' + CONVERT(VARCHAR, @cutoff_utc, 120);
    
    -- Log start
    INSERT INTO dbo.adl_archive_log (job_name, started_utc, status)
    VALUES ('sp_Archive_CompletedFlights', @start_utc, 'RUNNING');
    SET @log_id = SCOPE_IDENTITY();
    
    BEGIN TRY
        BEGIN TRANSACTION;
        
        -- Collect flight_uids to archive
        CREATE TABLE #flights_to_archive (flight_uid BIGINT PRIMARY KEY);
        
        INSERT INTO #flights_to_archive (flight_uid)
        SELECT TOP (@batch_size) c.flight_uid
        FROM dbo.adl_flight_core c
        WHERE c.is_active = 0
          AND c.last_seen_utc < @cutoff_utc
          AND NOT EXISTS (
              SELECT 1 FROM dbo.adl_flight_archive arc 
              WHERE arc.flight_uid = c.flight_uid
          )
        ORDER BY c.last_seen_utc ASC;
        
        IF @debug = 1
            SELECT COUNT(*) AS flights_to_archive FROM #flights_to_archive;
        
        -- Archive flights with all related data denormalized
        INSERT INTO dbo.adl_flight_archive (
            flight_uid, flight_key, cid, callsign, phase, flight_status,
            first_seen_utc, last_seen_utc, logon_time_utc,
            fp_dept_icao, fp_dest_icao, fp_alt_icao, fp_route, fp_altitude_ft,
            dp_name, star_name, dfix, afix, fp_dept_artcc, fp_dest_artcc,
            fp_dept_tracon, fp_dest_tracon, gcd_nm, aircraft_type,
            aircraft_icao, weight_class, engine_type, wake_category, airline_icao, airline_name,
            etd_utc, eta_utc, atd_utc, ata_utc,
            ctl_type, delay_minutes, edct_utc,
            flight_duration_min, total_distance_nm, max_altitude_ft,
            trajectory_points, changelog_entries
        )
        SELECT 
            c.flight_uid, c.flight_key, c.cid, c.callsign, c.phase, c.flight_status,
            c.first_seen_utc, c.last_seen_utc, c.logon_time_utc,
            fp.fp_dept_icao, fp.fp_dest_icao, fp.fp_alt_icao, fp.fp_route, fp.fp_altitude_ft,
            fp.dp_name, fp.star_name, fp.dfix, fp.afix, fp.fp_dept_artcc, fp.fp_dest_artcc,
            fp.fp_dept_tracon, fp.fp_dest_tracon, fp.gcd_nm, fp.aircraft_type,
            a.aircraft_icao, a.weight_class, a.engine_type, a.wake_category, a.airline_icao, a.airline_name,
            t.etd_utc, t.eta_utc, t.atd_utc, t.ata_utc,
            tmi.ctl_type, tmi.delay_minutes, tmi.edct_utc,
            DATEDIFF(MINUTE, c.first_seen_utc, c.last_seen_utc) AS flight_duration_min,
            fp.gcd_nm AS total_distance_nm,
            (SELECT MAX(altitude_ft) FROM dbo.adl_flight_trajectory tr WHERE tr.flight_uid = c.flight_uid) AS max_altitude_ft,
            (SELECT COUNT(*) FROM dbo.adl_flight_trajectory tr WHERE tr.flight_uid = c.flight_uid) AS trajectory_points,
            (SELECT COUNT(*) FROM dbo.adl_flight_changelog cl WHERE cl.flight_uid = c.flight_uid) AS changelog_entries
        FROM #flights_to_archive fta
        JOIN dbo.adl_flight_core c ON c.flight_uid = fta.flight_uid
        LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_aircraft a ON a.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_tmi tmi ON tmi.flight_uid = c.flight_uid;
        
        SET @rows_archived = @@ROWCOUNT;
        
        -- Delete archived flights from live tables (CASCADE handles related tables)
        DELETE c
        FROM dbo.adl_flight_core c
        INNER JOIN #flights_to_archive fta ON fta.flight_uid = c.flight_uid;
        
        SET @rows_deleted = @@ROWCOUNT;
        
        DROP TABLE #flights_to_archive;
        
        COMMIT TRANSACTION;
        
        -- Log success
        UPDATE dbo.adl_archive_log
        SET ended_utc = SYSUTCDATETIME(),
            duration_ms = DATEDIFF(MILLISECOND, @start_utc, SYSUTCDATETIME()),
            rows_processed = @rows_archived,
            rows_archived = @rows_archived,
            rows_deleted = @rows_deleted,
            status = 'SUCCESS'
        WHERE log_id = @log_id;
        
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        
        UPDATE dbo.adl_archive_log
        SET ended_utc = SYSUTCDATETIME(),
            duration_ms = DATEDIFF(MILLISECOND, @start_utc, SYSUTCDATETIME()),
            status = 'FAILED',
            error_message = ERROR_MESSAGE()
        WHERE log_id = @log_id;
        
        IF @debug = 1
            THROW;
    END CATCH
    
    SELECT @rows_archived AS flights_archived, @rows_deleted AS rows_deleted_from_live;
END;
GO

PRINT 'Created procedure dbo.sp_Archive_CompletedFlights';
GO

-- ============================================================================
-- 2. sp_Archive_Trajectory_ToWarm
--    Moves trajectory data older than HOT threshold to WARM archive
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_Archive_Trajectory_ToWarm
    @batch_size INT = NULL,
    @debug BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @start_utc DATETIME2(3) = SYSUTCDATETIME();
    DECLARE @log_id INT;
    DECLARE @rows_archived INT = 0;
    DECLARE @rows_deleted INT = 0;
    DECLARE @hot_hours INT;
    DECLARE @cutoff_utc DATETIME2(0);
    
    -- Get config
    SELECT @hot_hours = CAST(config_value AS INT)
    FROM dbo.adl_archive_config WHERE config_key = 'TRAJECTORY_HOT_HOURS';
    SET @hot_hours = ISNULL(@hot_hours, 24);
    
    IF @batch_size IS NULL
        SELECT @batch_size = CAST(config_value AS INT)
        FROM dbo.adl_archive_config WHERE config_key = 'ARCHIVE_BATCH_SIZE';
    SET @batch_size = ISNULL(@batch_size, 10000);
    
    SET @cutoff_utc = DATEADD(HOUR, -@hot_hours, SYSUTCDATETIME());
    
    IF @debug = 1
        PRINT 'Moving trajectory older than: ' + CONVERT(VARCHAR, @cutoff_utc, 120);
    
    -- Log start
    INSERT INTO dbo.adl_archive_log (job_name, started_utc, status)
    VALUES ('sp_Archive_Trajectory_ToWarm', @start_utc, 'RUNNING');
    SET @log_id = SCOPE_IDENTITY();
    
    BEGIN TRY
        -- Process in batches
        WHILE 1 = 1
        BEGIN
            -- Move to warm archive
            INSERT INTO dbo.adl_trajectory_archive (
                flight_uid, callsign, timestamp_utc, lat, lon,
                altitude_ft, groundspeed_kts, heading_deg, vertical_rate_fpm,
                sample_interval_sec, source_tier
            )
            SELECT TOP (@batch_size)
                t.flight_uid,
                c.callsign,
                t.timestamp_utc,
                t.lat,
                t.lon,
                t.altitude_ft,
                t.groundspeed_kts,
                t.heading_deg,
                t.vertical_rate_fpm,
                60,
                'WARM'
            FROM dbo.adl_flight_trajectory t
            LEFT JOIN dbo.adl_flight_core c ON c.flight_uid = t.flight_uid
            WHERE t.timestamp_utc < @cutoff_utc
            ORDER BY t.timestamp_utc ASC;
            
            IF @@ROWCOUNT = 0 BREAK;
            SET @rows_archived = @rows_archived + @@ROWCOUNT;
            
            -- Delete moved records
            DELETE TOP (@batch_size) FROM dbo.adl_flight_trajectory
            WHERE timestamp_utc < @cutoff_utc;
            
            SET @rows_deleted = @rows_deleted + @@ROWCOUNT;
            
            -- Brief pause for large jobs
            IF @rows_archived > @batch_size * 5
                WAITFOR DELAY '00:00:01';
        END
        
        -- Log success
        UPDATE dbo.adl_archive_log
        SET ended_utc = SYSUTCDATETIME(),
            duration_ms = DATEDIFF(MILLISECOND, @start_utc, SYSUTCDATETIME()),
            rows_processed = @rows_archived,
            rows_archived = @rows_archived,
            rows_deleted = @rows_deleted,
            status = 'SUCCESS'
        WHERE log_id = @log_id;
        
    END TRY
    BEGIN CATCH
        UPDATE dbo.adl_archive_log
        SET ended_utc = SYSUTCDATETIME(),
            status = 'FAILED',
            error_message = ERROR_MESSAGE()
        WHERE log_id = @log_id;
        
        IF @debug = 1
            THROW;
    END CATCH
    
    SELECT @rows_archived AS rows_archived, @rows_deleted AS rows_deleted;
END;
GO

PRINT 'Created procedure dbo.sp_Archive_Trajectory_ToWarm';
GO

-- ============================================================================
-- 3. sp_Downsample_Trajectory_ToCold
--    Downsamples WARM tier data to 5-minute intervals (COLD tier)
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_Downsample_Trajectory_ToCold
    @debug BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @start_utc DATETIME2(3) = SYSUTCDATETIME();
    DECLARE @log_id INT;
    DECLARE @rows_kept INT = 0;
    DECLARE @rows_deleted INT = 0;
    DECLARE @warm_days INT;
    DECLARE @target_interval INT;
    DECLARE @cutoff_utc DATETIME2(0);
    
    -- Get config
    SELECT @warm_days = CAST(config_value AS INT)
    FROM dbo.adl_archive_config WHERE config_key = 'TRAJECTORY_WARM_DAYS';
    SET @warm_days = ISNULL(@warm_days, 7);
    
    SELECT @target_interval = CAST(config_value AS INT)
    FROM dbo.adl_archive_config WHERE config_key = 'DOWNSAMPLE_COLD_INTERVAL_SEC';
    SET @target_interval = ISNULL(@target_interval, 300);
    
    SET @cutoff_utc = DATEADD(DAY, -@warm_days, SYSUTCDATETIME());
    
    IF @debug = 1
    BEGIN
        PRINT 'Downsampling WARM data older than: ' + CONVERT(VARCHAR, @cutoff_utc, 120);
        PRINT 'Target interval: ' + CAST(@target_interval AS VARCHAR) + ' seconds';
    END
    
    -- Log start
    INSERT INTO dbo.adl_archive_log (job_name, started_utc, status)
    VALUES ('sp_Downsample_Trajectory_ToCold', @start_utc, 'RUNNING');
    SET @log_id = SCOPE_IDENTITY();
    
    BEGIN TRY
        -- Create temp table with records to keep (first record per 5-min bucket per flight)
        CREATE TABLE #keep_ids (trajectory_archive_id BIGINT PRIMARY KEY);
        
        ;WITH Bucketed AS (
            SELECT 
                trajectory_archive_id,
                flight_uid,
                timestamp_utc,
                ROW_NUMBER() OVER (
                    PARTITION BY 
                        flight_uid, 
                        -- Create 5-minute buckets
                        DATEADD(SECOND, 
                            (DATEDIFF(SECOND, '2020-01-01', timestamp_utc) / @target_interval) * @target_interval,
                            '2020-01-01')
                    ORDER BY timestamp_utc
                ) AS rn
            FROM dbo.adl_trajectory_archive
            WHERE source_tier = 'WARM'
              AND archived_utc < @cutoff_utc
        )
        INSERT INTO #keep_ids (trajectory_archive_id)
        SELECT trajectory_archive_id
        FROM Bucketed
        WHERE rn = 1;
        
        SET @rows_kept = @@ROWCOUNT;
        
        IF @debug = 1
            PRINT 'Rows to keep: ' + CAST(@rows_kept AS VARCHAR);
        
        -- Update kept records to COLD tier
        UPDATE t
        SET t.source_tier = 'COLD',
            t.sample_interval_sec = @target_interval
        FROM dbo.adl_trajectory_archive t
        INNER JOIN #keep_ids k ON k.trajectory_archive_id = t.trajectory_archive_id;
        
        -- Delete non-kept WARM records older than cutoff
        DELETE FROM dbo.adl_trajectory_archive
        WHERE source_tier = 'WARM'
          AND archived_utc < @cutoff_utc
          AND trajectory_archive_id NOT IN (SELECT trajectory_archive_id FROM #keep_ids);
        
        SET @rows_deleted = @@ROWCOUNT;
        
        DROP TABLE #keep_ids;
        
        -- Log success
        UPDATE dbo.adl_archive_log
        SET ended_utc = SYSUTCDATETIME(),
            duration_ms = DATEDIFF(MILLISECOND, @start_utc, SYSUTCDATETIME()),
            rows_processed = @rows_kept + @rows_deleted,
            rows_archived = @rows_kept,
            rows_deleted = @rows_deleted,
            status = 'SUCCESS'
        WHERE log_id = @log_id;
        
    END TRY
    BEGIN CATCH
        IF OBJECT_ID('tempdb..#keep_ids') IS NOT NULL
            DROP TABLE #keep_ids;
            
        UPDATE dbo.adl_archive_log
        SET ended_utc = SYSUTCDATETIME(),
            status = 'FAILED',
            error_message = ERROR_MESSAGE()
        WHERE log_id = @log_id;
        
        IF @debug = 1
            THROW;
    END CATCH
    
    SELECT @rows_kept AS rows_kept_as_cold, @rows_deleted AS rows_downsampled_away;
END;
GO

PRINT 'Created procedure dbo.sp_Downsample_Trajectory_ToCold';
GO

-- ============================================================================
-- 4. sp_Purge_OldData
--    Purges data older than retention thresholds
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_Purge_OldData
    @debug BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @start_utc DATETIME2(3) = SYSUTCDATETIME();
    DECLARE @log_id INT;
    DECLARE @batch_size INT;
    DECLARE @trajectory_days INT, @changelog_days INT, @flight_days INT, @runlog_days INT;
    DECLARE @traj_deleted INT = 0, @changelog_deleted INT = 0;
    DECLARE @flight_deleted INT = 0, @runlog_deleted INT = 0;
    DECLARE @total_deleted INT = 0;
    DECLARE @deleted_batch INT;
    
    -- Get config values
    SELECT @trajectory_days = CAST(config_value AS INT) FROM dbo.adl_archive_config WHERE config_key = 'TRAJECTORY_COLD_DAYS';
    SELECT @changelog_days = CAST(config_value AS INT) FROM dbo.adl_archive_config WHERE config_key = 'CHANGELOG_RETENTION_DAYS';
    SELECT @flight_days = CAST(config_value AS INT) FROM dbo.adl_archive_config WHERE config_key = 'FLIGHT_ARCHIVE_RETENTION_DAYS';
    SELECT @runlog_days = CAST(config_value AS INT) FROM dbo.adl_archive_config WHERE config_key = 'RUN_LOG_RETENTION_DAYS';
    SELECT @batch_size = CAST(config_value AS INT) FROM dbo.adl_archive_config WHERE config_key = 'ARCHIVE_BATCH_SIZE';
    
    SET @trajectory_days = ISNULL(@trajectory_days, 90);
    SET @changelog_days = ISNULL(@changelog_days, 90);
    SET @flight_days = ISNULL(@flight_days, 365);
    SET @runlog_days = ISNULL(@runlog_days, 30);
    SET @batch_size = ISNULL(@batch_size, 10000);
    
    IF @debug = 1
    BEGIN
        PRINT 'Purge thresholds:';
        PRINT '  Trajectory: ' + CAST(@trajectory_days AS VARCHAR) + ' days';
        PRINT '  Changelog: ' + CAST(@changelog_days AS VARCHAR) + ' days';
        PRINT '  Flight archive: ' + CAST(@flight_days AS VARCHAR) + ' days';
        PRINT '  Run log: ' + CAST(@runlog_days AS VARCHAR) + ' days';
    END
    
    -- Log start
    INSERT INTO dbo.adl_archive_log (job_name, started_utc, status)
    VALUES ('sp_Purge_OldData', @start_utc, 'RUNNING');
    SET @log_id = SCOPE_IDENTITY();
    
    BEGIN TRY
        -- 1. Purge old trajectory archive
        WHILE 1 = 1
        BEGIN
            DELETE TOP (@batch_size) FROM dbo.adl_trajectory_archive
            WHERE archived_utc < DATEADD(DAY, -@trajectory_days, SYSUTCDATETIME());
            
            SET @deleted_batch = @@ROWCOUNT;
            SET @traj_deleted = @traj_deleted + @deleted_batch;
            
            IF @deleted_batch = 0 BREAK;
            IF @deleted_batch < @batch_size BREAK;
            
            WAITFOR DELAY '00:00:00.500';
        END
        
        IF @debug = 1
            PRINT 'Trajectory deleted: ' + CAST(@traj_deleted AS VARCHAR);
        
        -- 2. Purge old changelog
        WHILE 1 = 1
        BEGIN
            DELETE TOP (@batch_size) FROM dbo.adl_flight_changelog
            WHERE changed_utc < DATEADD(DAY, -@changelog_days, SYSUTCDATETIME());
            
            SET @deleted_batch = @@ROWCOUNT;
            SET @changelog_deleted = @changelog_deleted + @deleted_batch;
            
            IF @deleted_batch = 0 BREAK;
            IF @deleted_batch < @batch_size BREAK;
            
            WAITFOR DELAY '00:00:00.500';
        END
        
        IF @debug = 1
            PRINT 'Changelog deleted: ' + CAST(@changelog_deleted AS VARCHAR);
        
        -- 3. Purge old flight archive
        WHILE 1 = 1
        BEGIN
            DELETE TOP (@batch_size) FROM dbo.adl_flight_archive
            WHERE archived_utc < DATEADD(DAY, -@flight_days, SYSUTCDATETIME());
            
            SET @deleted_batch = @@ROWCOUNT;
            SET @flight_deleted = @flight_deleted + @deleted_batch;
            
            IF @deleted_batch = 0 BREAK;
            IF @deleted_batch < @batch_size BREAK;
            
            WAITFOR DELAY '00:00:00.500';
        END
        
        IF @debug = 1
            PRINT 'Flight archive deleted: ' + CAST(@flight_deleted AS VARCHAR);
        
        -- 4. Purge old run log (if table exists)
        IF EXISTS (SELECT 1 FROM sys.objects WHERE name = 'adl_run_log' AND type = 'U')
        BEGIN
            WHILE 1 = 1
            BEGIN
                DELETE TOP (@batch_size) FROM dbo.adl_run_log
                WHERE started_utc < DATEADD(DAY, -@runlog_days, SYSUTCDATETIME());
                
                SET @deleted_batch = @@ROWCOUNT;
                SET @runlog_deleted = @runlog_deleted + @deleted_batch;
                
                IF @deleted_batch = 0 BREAK;
                IF @deleted_batch < @batch_size BREAK;
            END
            
            IF @debug = 1
                PRINT 'Run log deleted: ' + CAST(@runlog_deleted AS VARCHAR);
        END
        
        -- 5. Purge old archive log entries (keep 90 days)
        DELETE FROM dbo.adl_archive_log
        WHERE started_utc < DATEADD(DAY, -90, SYSUTCDATETIME())
          AND log_id <> @log_id;
        
        SET @total_deleted = @traj_deleted + @changelog_deleted + @flight_deleted + @runlog_deleted;
        
        -- Log success
        UPDATE dbo.adl_archive_log
        SET ended_utc = SYSUTCDATETIME(),
            duration_ms = DATEDIFF(MILLISECOND, @start_utc, SYSUTCDATETIME()),
            rows_deleted = @total_deleted,
            status = 'SUCCESS'
        WHERE log_id = @log_id;
        
    END TRY
    BEGIN CATCH
        UPDATE dbo.adl_archive_log
        SET ended_utc = SYSUTCDATETIME(),
            status = 'FAILED',
            error_message = ERROR_MESSAGE()
        WHERE log_id = @log_id;
        
        IF @debug = 1
            THROW;
    END CATCH
    
    SELECT 
        @traj_deleted AS trajectory_deleted,
        @changelog_deleted AS changelog_deleted,
        @flight_deleted AS flights_deleted,
        @runlog_deleted AS runlog_deleted,
        @total_deleted AS total_deleted;
END;
GO

PRINT 'Created procedure dbo.sp_Purge_OldData';
GO

-- ============================================================================
-- 5. sp_Get_Flight_History
--    Query procedure for retrieving archived flight data
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_Get_Flight_History
    @callsign NVARCHAR(16) = NULL,
    @flight_uid BIGINT = NULL,
    @cid INT = NULL,
    @dept_icao CHAR(4) = NULL,
    @dest_icao CHAR(4) = NULL,
    @from_date DATE = NULL,
    @to_date DATE = NULL,
    @include_trajectory BIT = 0,
    @include_changelog BIT = 0,
    @max_results INT = 100
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Default date range to last 30 days if no filters provided
    IF @callsign IS NULL AND @flight_uid IS NULL AND @cid IS NULL AND @from_date IS NULL
        SET @from_date = DATEADD(DAY, -30, CAST(SYSUTCDATETIME() AS DATE));
    
    SET @to_date = ISNULL(@to_date, CAST(SYSUTCDATETIME() AS DATE));
    
    -- Return flight archive records
    SELECT TOP (@max_results)
        archive_id,
        flight_uid,
        callsign,
        cid,
        fp_dept_icao AS origin,
        fp_dest_icao AS destination,
        first_seen_utc,
        last_seen_utc,
        flight_duration_min,
        aircraft_type,
        aircraft_icao,
        airline_icao,
        airline_name,
        phase AS final_phase,
        fp_route AS route,
        fp_altitude_ft AS altitude,
        gcd_nm AS distance_nm,
        max_altitude_ft,
        trajectory_points,
        changelog_entries,
        archived_utc
    FROM dbo.adl_flight_archive
    WHERE (@callsign IS NULL OR callsign = @callsign)
      AND (@flight_uid IS NULL OR flight_uid = @flight_uid)
      AND (@cid IS NULL OR cid = @cid)
      AND (@dept_icao IS NULL OR fp_dept_icao = @dept_icao)
      AND (@dest_icao IS NULL OR fp_dest_icao = @dest_icao)
      AND (@from_date IS NULL OR CAST(first_seen_utc AS DATE) >= @from_date)
      AND (CAST(first_seen_utc AS DATE) <= @to_date)
    ORDER BY first_seen_utc DESC;
    
    -- Optionally return trajectory (from both live and archive)
    IF @include_trajectory = 1 AND @flight_uid IS NOT NULL
    BEGIN
        SELECT 'HOT' AS tier, timestamp_utc, lat, lon, altitude_ft, groundspeed_kts, heading_deg
        FROM dbo.adl_flight_trajectory
        WHERE flight_uid = @flight_uid
        
        UNION ALL
        
        SELECT source_tier, timestamp_utc, lat, lon, altitude_ft, groundspeed_kts, heading_deg
        FROM dbo.adl_trajectory_archive
        WHERE flight_uid = @flight_uid
        
        ORDER BY timestamp_utc ASC;
    END
    
    -- Optionally return changelog
    IF @include_changelog = 1 AND @flight_uid IS NOT NULL
    BEGIN
        SELECT changed_utc, source_table, field_name, old_value, new_value
        FROM dbo.adl_flight_changelog
        WHERE flight_uid = @flight_uid
        ORDER BY changed_utc ASC;
    END
END;
GO

PRINT 'Created procedure dbo.sp_Get_Flight_History';
GO

-- ============================================================================
-- 6. sp_Archive_RunAll
--    Convenience procedure to run all archive jobs in sequence
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_Archive_RunAll
    @debug BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    
    PRINT '=== Starting Archive Run All ===';
    PRINT 'Time: ' + CONVERT(VARCHAR, SYSUTCDATETIME(), 120);
    
    -- 1. Archive completed flights
    PRINT '';
    PRINT '--- Step 1: Archive Completed Flights ---';
    EXEC dbo.sp_Archive_CompletedFlights @debug = @debug;
    
    -- 2. Move trajectory to warm
    PRINT '';
    PRINT '--- Step 2: Move Trajectory to Warm ---';
    EXEC dbo.sp_Archive_Trajectory_ToWarm @debug = @debug;
    
    -- 3. Downsample to cold
    PRINT '';
    PRINT '--- Step 3: Downsample Trajectory to Cold ---';
    EXEC dbo.sp_Downsample_Trajectory_ToCold @debug = @debug;
    
    -- 4. Purge old data
    PRINT '';
    PRINT '--- Step 4: Purge Old Data ---';
    EXEC dbo.sp_Purge_OldData @debug = @debug;
    
    PRINT '';
    PRINT '=== Archive Run All Complete ===';
    PRINT 'Time: ' + CONVERT(VARCHAR, SYSUTCDATETIME(), 120);
    
    -- Show archive stats
    SELECT * FROM dbo.v_archive_stats;
END;
GO

PRINT 'Created procedure dbo.sp_Archive_RunAll';
GO

PRINT '';
PRINT '=== ADL Archive Migration 002 Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- END OF MIGRATION 002, BEGINNING MIGRATION 003
-- ============================================================================

-- ============================================================================
-- ADL Archive System - Migration 003: Trajectory Logging
-- 
-- Creates the trajectory logging procedure and integration
-- Run Order: 3 of 3
-- Depends on: 001_archive_tables.sql, 002_archive_procedures.sql
-- Target Database: VATSIM_ADL
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Archive Migration 003: Trajectory Logging ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. sp_Log_Trajectory
--    Logs current position of all active flights to trajectory table
--    Called from refresh SP or separately on 60-second interval
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_Log_Trajectory
    @force_log BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @log_interval_sec INT;
    DECLARE @last_log_utc DATETIME2(0);
    DECLARE @now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @rows_logged INT = 0;
    DECLARE @seconds_since_last INT;
    
    -- Get configured interval
    SELECT @log_interval_sec = CAST(config_value AS INT)
    FROM dbo.adl_archive_config
    WHERE config_key = 'TRAJECTORY_LOG_INTERVAL_SEC';
    
    SET @log_interval_sec = ISNULL(@log_interval_sec, 60);
    
    -- Check if enough time has passed since last log
    SELECT @last_log_utc = MAX(timestamp_utc)
    FROM dbo.adl_flight_trajectory;
    
    IF @last_log_utc IS NOT NULL
        SET @seconds_since_last = DATEDIFF(SECOND, @last_log_utc, @now);
    ELSE
        SET @seconds_since_last = @log_interval_sec + 1;  -- Force first log
    
    -- Skip if not time yet (unless forced)
    IF @force_log = 0 AND @seconds_since_last < @log_interval_sec
    BEGIN
        -- Not time yet
        SELECT 0 AS positions_logged, NULL AS logged_at, 'SKIPPED' AS status,
               @seconds_since_last AS seconds_since_last, @log_interval_sec AS interval_sec;
        RETURN;
    END
    
    -- Log current positions for all active flights
    INSERT INTO dbo.adl_flight_trajectory (
        flight_uid,
        timestamp_utc,
        lat,
        lon,
        altitude_ft,
        groundspeed_kts,
        vertical_rate_fpm,
        heading_deg,
        track_deg,
        source
    )
    SELECT 
        c.flight_uid,
        @now,
        p.lat,
        p.lon,
        p.altitude_ft,
        p.groundspeed_kts,
        p.vertical_rate_fpm,
        p.heading_deg,
        p.track_deg,
        'vatsim'
    FROM dbo.adl_flight_core c
    JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
    WHERE c.is_active = 1
      AND p.lat IS NOT NULL
      AND p.lon IS NOT NULL
      AND p.lat BETWEEN -90 AND 90
      AND p.lon BETWEEN -180 AND 180;
    
    SET @rows_logged = @@ROWCOUNT;
    
    -- Update position_geo column in batch (for spatial queries)
    -- This is optional and can be commented out if not using spatial features
    UPDATE t
    SET position_geo = geography::Point(t.lat, t.lon, 4326)
    FROM dbo.adl_flight_trajectory t
    WHERE t.timestamp_utc = @now
      AND t.position_geo IS NULL
      AND t.lat BETWEEN -90 AND 90
      AND t.lon BETWEEN -180 AND 180;
    
    SELECT @rows_logged AS positions_logged, @now AS logged_at, 'SUCCESS' AS status,
           @seconds_since_last AS seconds_since_last, @log_interval_sec AS interval_sec;
END;
GO

PRINT 'Created procedure dbo.sp_Log_Trajectory';
GO

-- ============================================================================
-- 2. sp_Get_Flight_Track
--    Returns trajectory data for a flight (from all tiers)
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_Get_Flight_Track
    @flight_uid BIGINT = NULL,
    @callsign NVARCHAR(16) = NULL,
    @simplify BIT = 0,          -- If 1, return simplified track (fewer points)
    @max_points INT = 1000
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Resolve flight_uid from callsign if needed
    IF @flight_uid IS NULL AND @callsign IS NOT NULL
    BEGIN
        -- Try live first
        SELECT TOP 1 @flight_uid = flight_uid
        FROM dbo.adl_flight_core
        WHERE callsign = @callsign
        ORDER BY last_seen_utc DESC;
        
        -- Try archive if not in live
        IF @flight_uid IS NULL
        BEGIN
            SELECT TOP 1 @flight_uid = flight_uid
            FROM dbo.adl_flight_archive
            WHERE callsign = @callsign
            ORDER BY last_seen_utc DESC;
        END
    END
    
    IF @flight_uid IS NULL
    BEGIN
        SELECT 'ERROR' AS status, 'Flight not found' AS message;
        RETURN;
    END
    
    -- Combine trajectory from all sources
    ;WITH AllTrajectory AS (
        -- HOT tier (live)
        SELECT 
            'HOT' AS tier,
            timestamp_utc,
            lat,
            lon,
            altitude_ft,
            groundspeed_kts,
            heading_deg,
            vertical_rate_fpm,
            ROW_NUMBER() OVER (ORDER BY timestamp_utc) AS rn,
            COUNT(*) OVER () AS total_count
        FROM dbo.adl_flight_trajectory
        WHERE flight_uid = @flight_uid
        
        UNION ALL
        
        -- WARM/COLD tier (archive)
        SELECT 
            source_tier,
            timestamp_utc,
            lat,
            lon,
            altitude_ft,
            groundspeed_kts,
            heading_deg,
            vertical_rate_fpm,
            ROW_NUMBER() OVER (ORDER BY timestamp_utc) AS rn,
            COUNT(*) OVER () AS total_count
        FROM dbo.adl_trajectory_archive
        WHERE flight_uid = @flight_uid
    )
    SELECT 
        tier,
        timestamp_utc,
        lat,
        lon,
        altitude_ft,
        groundspeed_kts,
        heading_deg,
        vertical_rate_fpm
    FROM AllTrajectory
    WHERE @simplify = 0 
       OR rn % CEILING(CAST(total_count AS FLOAT) / @max_points) = 1
       OR rn = 1
       OR rn = total_count
    ORDER BY timestamp_utc ASC;
END;
GO

PRINT 'Created procedure dbo.sp_Get_Flight_Track';
GO

-- ============================================================================
-- 3. sp_Trajectory_Stats
--    Returns trajectory storage statistics
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_Trajectory_Stats
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        'HOT (Live)' AS tier,
        COUNT(*) AS position_count,
        COUNT(DISTINCT flight_uid) AS flight_count,
        MIN(timestamp_utc) AS oldest,
        MAX(timestamp_utc) AS newest,
        DATEDIFF(HOUR, MIN(timestamp_utc), MAX(timestamp_utc)) AS hours_span,
        CAST(COUNT(*) * 80 / 1024.0 / 1024.0 AS DECIMAL(10,2)) AS est_size_mb
    FROM dbo.adl_flight_trajectory
    
    UNION ALL
    
    SELECT 
        'WARM (Archive)',
        COUNT(*),
        COUNT(DISTINCT flight_uid),
        MIN(timestamp_utc),
        MAX(timestamp_utc),
        DATEDIFF(HOUR, MIN(timestamp_utc), MAX(timestamp_utc)),
        CAST(COUNT(*) * 80 / 1024.0 / 1024.0 AS DECIMAL(10,2))
    FROM dbo.adl_trajectory_archive
    WHERE source_tier = 'WARM'
    
    UNION ALL
    
    SELECT 
        'COLD (Archive)',
        COUNT(*),
        COUNT(DISTINCT flight_uid),
        MIN(timestamp_utc),
        MAX(timestamp_utc),
        DATEDIFF(HOUR, MIN(timestamp_utc), MAX(timestamp_utc)),
        CAST(COUNT(*) * 80 / 1024.0 / 1024.0 AS DECIMAL(10,2))
    FROM dbo.adl_trajectory_archive
    WHERE source_tier = 'COLD';
    
    -- Recent logging activity
    SELECT 
        CAST(timestamp_utc AS DATE) AS log_date,
        COUNT(*) AS positions_logged,
        COUNT(DISTINCT flight_uid) AS flights_tracked
    FROM dbo.adl_flight_trajectory
    GROUP BY CAST(timestamp_utc AS DATE)
    ORDER BY log_date DESC;
END;
GO

PRINT 'Created procedure dbo.sp_Trajectory_Stats';
GO

-- ============================================================================
-- 4. Trajectory Logging Trigger Approach (Alternative)
--    Creates a trigger to log on position updates - OPTIONAL
--    Commented out by default as SP approach is preferred
-- ============================================================================

/*
-- ALTERNATIVE: Trigger-based logging (more automatic but more overhead)
-- Uncomment if you prefer trigger-based approach

CREATE OR ALTER TRIGGER dbo.tr_adl_flight_position_LogTrajectory
ON dbo.adl_flight_position
AFTER UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @log_interval_sec INT = 60;
    
    -- Get interval from config
    SELECT @log_interval_sec = CAST(config_value AS INT)
    FROM dbo.adl_archive_config
    WHERE config_key = 'TRAJECTORY_LOG_INTERVAL_SEC';
    
    -- Only log if position significantly changed (lat/lon/alt)
    INSERT INTO dbo.adl_flight_trajectory (
        flight_uid, timestamp_utc, lat, lon, altitude_ft,
        groundspeed_kts, vertical_rate_fpm, heading_deg, track_deg, source
    )
    SELECT 
        i.flight_uid,
        @now,
        i.lat,
        i.lon,
        i.altitude_ft,
        i.groundspeed_kts,
        i.vertical_rate_fpm,
        i.heading_deg,
        i.track_deg,
        'vatsim'
    FROM inserted i
    JOIN deleted d ON d.flight_uid = i.flight_uid
    WHERE i.lat IS NOT NULL 
      AND i.lon IS NOT NULL
      -- Only log if no recent log for this flight
      AND NOT EXISTS (
          SELECT 1 FROM dbo.adl_flight_trajectory t
          WHERE t.flight_uid = i.flight_uid
            AND t.timestamp_utc > DATEADD(SECOND, -@log_interval_sec, @now)
      );
END;
GO
*/

-- ============================================================================
-- 5. Index optimization for trajectory queries
-- ============================================================================

-- Check and add covering index for common trajectory queries
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_traj_flight_time_cover' AND object_id = OBJECT_ID('dbo.adl_flight_trajectory'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_traj_flight_time_cover
    ON dbo.adl_flight_trajectory (flight_uid, timestamp_utc DESC)
    INCLUDE (lat, lon, altitude_ft, groundspeed_kts, heading_deg);
    
    PRINT 'Created covering index IX_traj_flight_time_cover';
END
GO

-- ============================================================================
-- 6. Integration helper: Check if trajectory logging is due
-- ============================================================================

CREATE OR ALTER FUNCTION dbo.fn_IsTrajectoryLoggingDue()
RETURNS BIT
AS
BEGIN
    DECLARE @log_interval_sec INT;
    DECLARE @last_log_utc DATETIME2(0);
    DECLARE @seconds_since_last INT;
    
    SELECT @log_interval_sec = CAST(config_value AS INT)
    FROM dbo.adl_archive_config
    WHERE config_key = 'TRAJECTORY_LOG_INTERVAL_SEC';
    
    SET @log_interval_sec = ISNULL(@log_interval_sec, 60);
    
    SELECT @last_log_utc = MAX(timestamp_utc)
    FROM dbo.adl_flight_trajectory;
    
    IF @last_log_utc IS NULL
        RETURN 1;
    
    SET @seconds_since_last = DATEDIFF(SECOND, @last_log_utc, SYSUTCDATETIME());
    
    IF @seconds_since_last >= @log_interval_sec
        RETURN 1;
    
    RETURN 0;
END;
GO

PRINT 'Created function dbo.fn_IsTrajectoryLoggingDue';
GO

-- ============================================================================
-- 7. Add call to sp_Log_Trajectory in refresh SP (manual step)
-- ============================================================================

PRINT '';
PRINT '=== MANUAL INTEGRATION STEP REQUIRED ===';
PRINT 'Add the following line to the END of sp_Adl_RefreshFromVatsim_Normalized:';
PRINT '';
PRINT '    -- Log trajectory positions (handles its own interval checking)';
PRINT '    EXEC dbo.sp_Log_Trajectory;';
PRINT '';
PRINT 'OR call sp_Log_Trajectory from the PHP daemon on a 60-second timer.';
PRINT '';
GO

-- ============================================================================
-- 8. Verify installation
-- ============================================================================

PRINT '=== Verification ===';

SELECT 'Archive Tables' AS category, name AS object_name, type_desc
FROM sys.objects 
WHERE name IN ('adl_archive_config', 'adl_archive_log', 'adl_flight_archive', 'adl_trajectory_archive')
  AND type = 'U'
ORDER BY name;

SELECT 'Archive Procedures' AS category, name AS object_name, type_desc
FROM sys.objects 
WHERE name IN ('sp_Log_Trajectory', 'sp_Archive_CompletedFlights', 'sp_Archive_Trajectory_ToWarm', 
               'sp_Downsample_Trajectory_ToCold', 'sp_Purge_OldData', 'sp_Get_Flight_History',
               'sp_Get_Flight_Track', 'sp_Trajectory_Stats', 'sp_Archive_RunAll')
  AND type = 'P'
ORDER BY name;

SELECT 'Configuration' AS category, config_key, config_value, description
FROM dbo.adl_archive_config
ORDER BY config_key;

GO

PRINT '';
PRINT '=== ADL Archive Migration 003 Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '';
PRINT 'Next steps:';
PRINT '1. Integrate sp_Log_Trajectory call into refresh SP or PHP daemon';
PRINT '2. Schedule archive jobs (hourly/daily/weekly)';
PRINT '3. Test with: EXEC sp_Log_Trajectory @force_log = 1';
PRINT '4. Monitor with: EXEC sp_Trajectory_Stats';
GO

-- ============================================================================
-- DEPLOYMENT COMPLETE - RUN VERIFICATION
-- ============================================================================

PRINT '';
PRINT '=== DEPLOYMENT VERIFICATION ===';
PRINT '';

-- Test trajectory logging
EXEC dbo.sp_Log_Trajectory @force_log = 1;

-- Show archive stats
SELECT * FROM dbo.v_archive_stats;

PRINT '';
PRINT '=== ALL MIGRATIONS COMPLETE ===';
GO
