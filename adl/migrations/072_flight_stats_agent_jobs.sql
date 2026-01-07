-- =====================================================
-- Flight Statistics Scheduled Jobs
-- Migration: 072_flight_stats_agent_jobs.sql
-- Database: VATSIM_ADL (Azure SQL)
-- Purpose: Define scheduled job configurations for stats aggregation
-- =====================================================

-- NOTE: Azure SQL Database does not support SQL Server Agent directly.
-- This script documents the job configurations and provides alternatives:
--
-- Option 1: Azure Elastic Jobs (Azure SQL managed service)
-- Option 2: Azure Functions with Timer Triggers
-- Option 3: Call procedures from the PHP daemon (vatsim_adl_daemon.php)
-- Option 4: On-premises SQL Server Agent (if using hybrid deployment)
--
-- The procedures are designed to be idempotent and can be called safely
-- multiple times or from any scheduling mechanism.

SET NOCOUNT ON;
GO

-- =====================================================
-- 1. JOB CONFIGURATION TABLE
-- Stores job schedules for reference/monitoring
-- =====================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.flight_stats_job_config') AND type = 'U')
CREATE TABLE dbo.flight_stats_job_config (
    job_name            VARCHAR(64) PRIMARY KEY,
    procedure_name      VARCHAR(128) NOT NULL,
    schedule_type       VARCHAR(32) NOT NULL,   -- HOURLY, DAILY, MONTHLY
    schedule_cron       VARCHAR(32) NULL,       -- Cron expression for reference
    schedule_utc_hour   TINYINT NULL,           -- For daily jobs: hour to run (0-23)
    schedule_utc_minute TINYINT NULL,           -- Minute to run (0-59)
    schedule_day        TINYINT NULL,           -- For monthly: day of month (1-31)
    is_enabled          BIT NOT NULL DEFAULT 1,
    last_run_utc        DATETIME2 NULL,
    last_run_status     VARCHAR(16) NULL,
    description         NVARCHAR(256),
    created_utc         DATETIME2 DEFAULT GETUTCDATE()
);
GO

-- Seed job configurations
IF NOT EXISTS (SELECT 1 FROM dbo.flight_stats_job_config WHERE job_name = 'FlightStats_Hourly')
BEGIN
    INSERT INTO dbo.flight_stats_job_config
    (job_name, procedure_name, schedule_type, schedule_cron, schedule_utc_minute, description)
    VALUES
    ('FlightStats_Hourly', 'sp_GenerateFlightStats_Hourly', 'HOURLY', '5 * * * *', 5,
     'Runs every hour at :05 to aggregate hourly flight statistics'),

    ('FlightStats_Daily', 'sp_GenerateFlightStats_Daily', 'DAILY', '15 0 * * *', 15,
     'Runs daily at 00:15 UTC to aggregate previous day statistics'),

    ('FlightStats_Monthly', 'sp_RollupFlightStats_Monthly', 'MONTHLY', '30 1 1 * *', 30,
     'Runs on 1st of month at 01:30 UTC to roll up monthly statistics'),

    ('FlightStats_Cleanup', 'sp_CleanupFlightStats', 'DAILY', '45 3 * * *', 45,
     'Runs daily at 03:45 UTC to apply retention policy');
END
GO

-- =====================================================
-- 2. WRAPPER PROCEDURE FOR SCHEDULED EXECUTION
-- Single entry point that checks job config and executes
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_ExecuteFlightStatsJob')
    DROP PROCEDURE dbo.sp_ExecuteFlightStatsJob;
GO

CREATE PROCEDURE dbo.sp_ExecuteFlightStatsJob
    @job_name VARCHAR(64)
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @proc_name VARCHAR(128);
    DECLARE @is_enabled BIT;

    SELECT @proc_name = procedure_name, @is_enabled = is_enabled
    FROM dbo.flight_stats_job_config
    WHERE job_name = @job_name;

    IF @proc_name IS NULL
    BEGIN
        RAISERROR('Job not found: %s', 16, 1, @job_name);
        RETURN;
    END

    IF @is_enabled = 0
    BEGIN
        PRINT 'Job is disabled: ' + @job_name;
        RETURN;
    END

    -- Execute the procedure
    BEGIN TRY
        EXEC @proc_name;

        UPDATE dbo.flight_stats_job_config
        SET last_run_utc = GETUTCDATE(),
            last_run_status = 'SUCCESS'
        WHERE job_name = @job_name;
    END TRY
    BEGIN CATCH
        UPDATE dbo.flight_stats_job_config
        SET last_run_utc = GETUTCDATE(),
            last_run_status = 'FAILED'
        WHERE job_name = @job_name;

        THROW;
    END CATCH
END;
GO

-- =====================================================
-- 3. CHECK IF JOB SHOULD RUN
-- Helper for daemon-based scheduling
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_ShouldRunFlightStatsJob')
    DROP PROCEDURE dbo.sp_ShouldRunFlightStatsJob;
GO

CREATE PROCEDURE dbo.sp_ShouldRunFlightStatsJob
    @job_name VARCHAR(64),
    @should_run BIT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @schedule_type VARCHAR(32);
    DECLARE @schedule_utc_hour TINYINT;
    DECLARE @schedule_utc_minute TINYINT;
    DECLARE @schedule_day TINYINT;
    DECLARE @last_run_utc DATETIME2;
    DECLARE @is_enabled BIT;

    SELECT
        @schedule_type = schedule_type,
        @schedule_utc_hour = schedule_utc_hour,
        @schedule_utc_minute = schedule_utc_minute,
        @schedule_day = schedule_day,
        @last_run_utc = last_run_utc,
        @is_enabled = is_enabled
    FROM dbo.flight_stats_job_config
    WHERE job_name = @job_name;

    SET @should_run = 0;

    IF @is_enabled = 0
        RETURN;

    DECLARE @now DATETIME2 = GETUTCDATE();
    DECLARE @current_hour TINYINT = DATEPART(HOUR, @now);
    DECLARE @current_minute TINYINT = DATEPART(MINUTE, @now);
    DECLARE @current_day TINYINT = DATEPART(DAY, @now);

    -- Check based on schedule type
    IF @schedule_type = 'HOURLY'
    BEGIN
        -- Run if it's past the scheduled minute and we haven't run this hour
        IF @current_minute >= ISNULL(@schedule_utc_minute, 5)
           AND (@last_run_utc IS NULL OR DATEDIFF(HOUR, @last_run_utc, @now) >= 1)
            SET @should_run = 1;
    END
    ELSE IF @schedule_type = 'DAILY'
    BEGIN
        -- Run if it's past the scheduled hour:minute and we haven't run today
        IF (@current_hour > ISNULL(@schedule_utc_hour, 0)
            OR (@current_hour = ISNULL(@schedule_utc_hour, 0) AND @current_minute >= ISNULL(@schedule_utc_minute, 15)))
           AND (@last_run_utc IS NULL OR CAST(@last_run_utc AS DATE) < CAST(@now AS DATE))
            SET @should_run = 1;
    END
    ELSE IF @schedule_type = 'MONTHLY'
    BEGIN
        -- Run if it's the scheduled day and we haven't run this month
        IF @current_day = ISNULL(@schedule_day, 1)
           AND (@current_hour > ISNULL(@schedule_utc_hour, 1)
                OR (@current_hour = ISNULL(@schedule_utc_hour, 1) AND @current_minute >= ISNULL(@schedule_utc_minute, 30)))
           AND (@last_run_utc IS NULL OR DATEDIFF(MONTH, @last_run_utc, @now) >= 1)
            SET @should_run = 1;
    END
END;
GO

-- =====================================================
-- 4. DAEMON INTEGRATION PROCEDURE
-- Call this from PHP daemon to check and run all due jobs
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_ProcessFlightStatsJobs')
    DROP PROCEDURE dbo.sp_ProcessFlightStatsJobs;
GO

CREATE PROCEDURE dbo.sp_ProcessFlightStatsJobs
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @job_name VARCHAR(64);
    DECLARE @should_run BIT;

    DECLARE job_cursor CURSOR FOR
        SELECT job_name FROM dbo.flight_stats_job_config WHERE is_enabled = 1;

    OPEN job_cursor;
    FETCH NEXT FROM job_cursor INTO @job_name;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        EXEC dbo.sp_ShouldRunFlightStatsJob @job_name, @should_run OUTPUT;

        IF @should_run = 1
        BEGIN
            PRINT 'Running job: ' + @job_name;
            BEGIN TRY
                EXEC dbo.sp_ExecuteFlightStatsJob @job_name;
            END TRY
            BEGIN CATCH
                PRINT 'Job failed: ' + @job_name + ' - ' + ERROR_MESSAGE();
            END CATCH
        END

        FETCH NEXT FROM job_cursor INTO @job_name;
    END

    CLOSE job_cursor;
    DEALLOCATE job_cursor;
END;
GO

-- =====================================================
-- 5. SQL SERVER AGENT JOB DEFINITIONS (FOR ON-PREM)
-- These T-SQL statements create Agent jobs if running
-- on SQL Server (not Azure SQL Database)
-- =====================================================

-- Uncomment and run on SQL Server with Agent:
/*
USE msdb;
GO

-- Hourly job
EXEC sp_add_job
    @job_name = N'FlightStats_Hourly',
    @enabled = 1,
    @description = N'Aggregates hourly flight statistics';

EXEC sp_add_jobstep
    @job_name = N'FlightStats_Hourly',
    @step_name = N'Execute Hourly Stats',
    @subsystem = N'TSQL',
    @command = N'EXEC VATSIM_ADL.dbo.sp_GenerateFlightStats_Hourly',
    @database_name = N'VATSIM_ADL';

EXEC sp_add_schedule
    @schedule_name = N'Hourly at :05',
    @freq_type = 4,        -- Daily
    @freq_interval = 1,
    @freq_subday_type = 8, -- Hours
    @freq_subday_interval = 1,
    @active_start_time = 000500;  -- 00:05:00

EXEC sp_attach_schedule
    @job_name = N'FlightStats_Hourly',
    @schedule_name = N'Hourly at :05';

EXEC sp_add_jobserver
    @job_name = N'FlightStats_Hourly';

-- Daily job
EXEC sp_add_job
    @job_name = N'FlightStats_Daily',
    @enabled = 1,
    @description = N'Aggregates daily flight statistics';

EXEC sp_add_jobstep
    @job_name = N'FlightStats_Daily',
    @step_name = N'Execute Daily Stats',
    @subsystem = N'TSQL',
    @command = N'EXEC VATSIM_ADL.dbo.sp_GenerateFlightStats_Daily',
    @database_name = N'VATSIM_ADL';

EXEC sp_add_schedule
    @schedule_name = N'Daily at 00:15 UTC',
    @freq_type = 4,
    @freq_interval = 1,
    @active_start_time = 001500;

EXEC sp_attach_schedule
    @job_name = N'FlightStats_Daily',
    @schedule_name = N'Daily at 00:15 UTC';

EXEC sp_add_jobserver
    @job_name = N'FlightStats_Daily';

-- Monthly job
EXEC sp_add_job
    @job_name = N'FlightStats_Monthly',
    @enabled = 1,
    @description = N'Rolls up monthly flight statistics';

EXEC sp_add_jobstep
    @job_name = N'FlightStats_Monthly',
    @step_name = N'Execute Monthly Rollup',
    @subsystem = N'TSQL',
    @command = N'EXEC VATSIM_ADL.dbo.sp_RollupFlightStats_Monthly',
    @database_name = N'VATSIM_ADL';

EXEC sp_add_schedule
    @schedule_name = N'Monthly on 1st at 01:30 UTC',
    @freq_type = 16,       -- Monthly
    @freq_interval = 1,    -- Day 1
    @active_start_time = 013000;

EXEC sp_attach_schedule
    @job_name = N'FlightStats_Monthly',
    @schedule_name = N'Monthly on 1st at 01:30 UTC';

EXEC sp_add_jobserver
    @job_name = N'FlightStats_Monthly';
*/

-- =====================================================
-- 6. VIEW FOR JOB STATUS MONITORING
-- =====================================================

IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_flight_stats_job_status')
    DROP VIEW dbo.vw_flight_stats_job_status;
GO

CREATE VIEW dbo.vw_flight_stats_job_status AS
SELECT
    jc.job_name,
    jc.procedure_name,
    jc.schedule_type,
    jc.schedule_cron,
    jc.is_enabled,
    jc.last_run_utc,
    jc.last_run_status,
    DATEDIFF(MINUTE, jc.last_run_utc, GETUTCDATE()) AS minutes_since_last_run,
    CASE
        WHEN jc.schedule_type = 'HOURLY' AND DATEDIFF(MINUTE, jc.last_run_utc, GETUTCDATE()) > 90 THEN 'OVERDUE'
        WHEN jc.schedule_type = 'DAILY' AND DATEDIFF(HOUR, jc.last_run_utc, GETUTCDATE()) > 36 THEN 'OVERDUE'
        WHEN jc.schedule_type = 'MONTHLY' AND DATEDIFF(DAY, jc.last_run_utc, GETUTCDATE()) > 35 THEN 'OVERDUE'
        ELSE 'OK'
    END AS schedule_status,
    jc.description
FROM dbo.flight_stats_job_config jc;
GO

-- =====================================================
-- 7. RECENT RUN LOG VIEW
-- =====================================================

IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_flight_stats_recent_runs')
    DROP VIEW dbo.vw_flight_stats_recent_runs;
GO

CREATE VIEW dbo.vw_flight_stats_recent_runs AS
SELECT TOP 100
    rl.id,
    rl.run_type,
    rl.started_utc,
    rl.completed_utc,
    rl.status,
    rl.records_processed,
    rl.records_inserted,
    rl.records_deleted,
    rl.execution_ms,
    CASE WHEN rl.execution_ms > 60000 THEN 'SLOW'
         WHEN rl.execution_ms > 30000 THEN 'MODERATE'
         ELSE 'FAST'
    END AS performance_indicator,
    LEFT(rl.error_message, 200) AS error_preview
FROM dbo.flight_stats_run_log rl
ORDER BY rl.started_utc DESC;
GO

PRINT '072_flight_stats_agent_jobs.sql completed successfully';
PRINT '';
PRINT 'IMPORTANT: For Azure SQL Database, schedule jobs using one of:';
PRINT '  1. Azure Elastic Jobs';
PRINT '  2. Azure Functions with Timer Trigger';
PRINT '  3. PHP daemon integration (call sp_ProcessFlightStatsJobs)';
PRINT '';
PRINT 'Job configurations stored in: dbo.flight_stats_job_config';
PRINT 'Monitor status with: SELECT * FROM vw_flight_stats_job_status';
GO
