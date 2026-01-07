-- =====================================================
-- VATUSA Event Statistics Tables
-- Migration: 074_vatusa_event_stats.sql
-- Database: VATSIM_ADL (Azure SQL)
-- Purpose: Store VATUSA event statistics from Statsim.net
--          and historical Excel data imports
-- =====================================================

SET NOCOUNT ON;
GO

-- =====================================================
-- 1. MAIN EVENT TABLE
-- One row per event (from Event List)
-- =====================================================

-- Drop existing tables if re-running (will lose data - comment out if updating)
IF EXISTS (SELECT * FROM sys.tables WHERE name = 'vatusa_event_hourly')
    DROP TABLE dbo.vatusa_event_hourly;
IF EXISTS (SELECT * FROM sys.tables WHERE name = 'vatusa_event_airport')
    DROP TABLE dbo.vatusa_event_airport;
IF EXISTS (SELECT * FROM sys.tables WHERE name = 'vatusa_event')
    DROP TABLE dbo.vatusa_event;
GO

CREATE TABLE dbo.vatusa_event (
    id                  BIGINT IDENTITY(1,1) PRIMARY KEY,
    event_idx           VARCHAR(64) NOT NULL,           -- Composite key: 202003062359T202003070400/FNO/FNO1
    event_name          NVARCHAR(256) NULL,
    event_type          VARCHAR(16) NULL,               -- FNO, SAT, MWK, etc.
    event_code          VARCHAR(16) NULL,               -- FNO1, FOR1, TRA2, etc.

    -- Timing (nullable for incomplete data)
    start_utc           DATETIME2 NULL,
    end_utc             DATETIME2 NULL,
    duration_hours      AS DATEDIFF(MINUTE, start_utc, end_utc) / 60.0 PERSISTED,
    day_of_week         VARCHAR(3) NULL,                -- Mon, Tue, Wed, etc.

    -- Aggregate stats (computed from hourly data)
    total_arrivals      INT NULL,
    total_departures    INT NULL,
    total_operations    INT NULL,
    airport_count       INT NULL,                       -- Number of airports involved

    -- Real-world comparison
    rw_total_arrivals   INT NULL,
    rw_total_departures INT NULL,
    rw_total_operations INT NULL,
    pct_of_rw_total     DECIMAL(6,2) NULL,

    -- Categorization for analysis
    season              VARCHAR(16) NULL,               -- Winter, Spring, Summer, Fall
    month_num           TINYINT NULL,
    year_num            SMALLINT NULL,

    -- TMR Review data
    tmr_link            NVARCHAR(512) NULL,
    timelapse_link      NVARCHAR(512) NULL,
    simaware_link       NVARCHAR(512) NULL,
    perti_plan_link     NVARCHAR(512) NULL,
    staffing_score      DECIMAL(3,1) NULL,
    tactical_score      DECIMAL(3,1) NULL,
    overall_score       DECIMAL(3,1) NULL,

    source              VARCHAR(16) DEFAULT 'EXCEL',    -- EXCEL, STATSIM, VATUSA_API
    created_utc         DATETIME2 DEFAULT GETUTCDATE(),
    updated_utc         DATETIME2 NULL,

    CONSTRAINT UQ_vatusa_event_idx UNIQUE (event_idx),
    INDEX IX_vatusa_event_date (start_utc),
    INDEX IX_vatusa_event_type (event_type),
    INDEX IX_vatusa_event_year_month (year_num, month_num)
);
GO

PRINT 'Created vatusa_event table';
GO

-- =====================================================
-- 2. EVENT HOURLY DATA TABLE
-- One row per airport per hour per event
-- =====================================================

CREATE TABLE dbo.vatusa_event_hourly (
    id                  BIGINT IDENTITY(1,1) PRIMARY KEY,
    event_idx           VARCHAR(64) NOT NULL,           -- FK to vatusa_event
    airport_icao        VARCHAR(4) NOT NULL,
    hour_utc            VARCHAR(5) NOT NULL,            -- 2300Z, 0000Z, etc.
    hour_offset         TINYINT NULL,                   -- 0, 1, 2... hours from event start

    -- VATSIM traffic
    arrivals            INT NULL,
    departures          INT NULL,
    throughput          INT NULL,                       -- arrivals + departures
    vatsim_aar          INT NULL,                       -- Airport Arrival Rate
    vatsim_adr          INT NULL,                       -- Airport Departure Rate
    vatsim_total        INT NULL,

    -- Real-world comparison
    rw_aar              INT NULL,
    rw_adr              INT NULL,
    rw_total            INT NULL,

    -- Percentages
    pct_vatsim_aar      DECIMAL(6,2) NULL,
    pct_vatsim_adr      DECIMAL(6,2) NULL,
    pct_vatsim_total    DECIMAL(6,2) NULL,
    pct_rw_aar          DECIMAL(6,2) NULL,
    pct_rw_adr          DECIMAL(6,2) NULL,
    pct_rw_total        DECIMAL(6,2) NULL,

    -- Rolling averages
    rolling_arr         INT NULL,
    rolling_dep         INT NULL,
    rolling_throughput  INT NULL,

    -- Event totals (for this airport up to this hour)
    event_airport_arr   INT NULL,
    event_airport_dep   INT NULL,
    event_airport_total INT NULL,

    -- Hourly averages for comparison
    hourly_avg          DECIMAL(8,2) NULL,
    hourly_avg_airport  DECIMAL(8,2) NULL,
    hourly_avg_event_type DECIMAL(8,2) NULL,

    created_utc         DATETIME2 DEFAULT GETUTCDATE(),

    CONSTRAINT FK_hourly_event FOREIGN KEY (event_idx)
        REFERENCES dbo.vatusa_event(event_idx),
    CONSTRAINT UQ_event_hourly UNIQUE (event_idx, airport_icao, hour_utc),
    INDEX IX_hourly_airport (airport_icao),
    INDEX IX_hourly_event (event_idx)
);
GO

PRINT 'Created vatusa_event_hourly table';
GO

-- =====================================================
-- 3. EVENT AIRPORT SUMMARY TABLE
-- Aggregated stats per airport per event
-- =====================================================

CREATE TABLE dbo.vatusa_event_airport (
    id                  BIGINT IDENTITY(1,1) PRIMARY KEY,
    event_idx           VARCHAR(64) NOT NULL,
    airport_icao        VARCHAR(4) NOT NULL,
    is_featured         BIT DEFAULT 1,                  -- Featured vs secondary airport

    -- Totals for this airport during event
    total_arrivals      INT NULL,
    total_departures    INT NULL,
    total_operations    INT NULL,

    -- VATSIM rates
    avg_vatsim_aar      DECIMAL(6,2) NULL,
    avg_vatsim_adr      DECIMAL(6,2) NULL,
    peak_vatsim_aar     INT NULL,
    peak_hour_utc       VARCHAR(5) NULL,

    -- Real-world comparison
    rw_total_arrivals   INT NULL,
    rw_total_departures INT NULL,
    pct_of_rw           DECIMAL(6,2) NULL,

    -- Performance metrics
    hours_above_50pct   INT NULL,                       -- Hours >= 50% of RW rate
    hours_above_75pct   INT NULL,
    hours_above_90pct   INT NULL,

    created_utc         DATETIME2 DEFAULT GETUTCDATE(),

    CONSTRAINT FK_airport_event FOREIGN KEY (event_idx)
        REFERENCES dbo.vatusa_event(event_idx),
    CONSTRAINT UQ_event_airport UNIQUE (event_idx, airport_icao),
    INDEX IX_airport_stats (airport_icao)
);
GO

PRINT 'Created vatusa_event_airport table';
GO

-- =====================================================
-- 4. VIEWS FOR ANALYSIS
-- =====================================================

IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_vatusa_event_summary')
    DROP VIEW dbo.vw_vatusa_event_summary;
GO

CREATE VIEW dbo.vw_vatusa_event_summary AS
SELECT
    e.event_idx,
    e.event_name,
    e.event_type,
    e.start_utc,
    e.end_utc,
    e.duration_hours,
    e.day_of_week,
    e.year_num,
    e.month_num,
    e.total_operations,
    e.rw_total_operations,
    e.pct_of_rw_total,
    e.airport_count,
    e.overall_score,
    STRING_AGG(a.airport_icao, ', ') WITHIN GROUP (ORDER BY a.total_operations DESC) AS airports
FROM dbo.vatusa_event e
LEFT JOIN dbo.vatusa_event_airport a ON e.event_idx = a.event_idx
GROUP BY
    e.event_idx, e.event_name, e.event_type, e.start_utc, e.end_utc,
    e.duration_hours, e.day_of_week, e.year_num, e.month_num,
    e.total_operations, e.rw_total_operations, e.pct_of_rw_total,
    e.airport_count, e.overall_score;
GO

PRINT 'Created vw_vatusa_event_summary view';
GO

-- =====================================================
-- 5. JOB CONFIGURATION
-- =====================================================

IF NOT EXISTS (SELECT 1 FROM dbo.flight_stats_job_config WHERE job_name = 'VATUSAEventStats_Daily')
BEGIN
    INSERT INTO dbo.flight_stats_job_config
    (job_name, procedure_name, schedule_type, schedule_cron,
     schedule_utc_hour, schedule_utc_minute, description, is_enabled)
    VALUES
    ('VATUSAEventStats_Daily', 'sp_ImportVATUSAEventStats', 'DAILY',
     '30 2 * * *', 2, 30,
     'Daily import of VATUSA event statistics from Statsim.net', 1);
    PRINT 'Added VATUSAEventStats_Daily job configuration';
END
GO

-- =====================================================
-- 6. IMPORT STORED PROCEDURE (placeholder)
-- =====================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_ImportVATUSAEventStats')
    DROP PROCEDURE dbo.sp_ImportVATUSAEventStats;
GO

CREATE PROCEDURE dbo.sp_ImportVATUSAEventStats
AS
BEGIN
    SET NOCOUNT ON;
    -- This procedure will be called by the daily job
    -- Actual import logic is handled by Python script generating SQL
    -- This is a placeholder for daemon integration

    PRINT 'sp_ImportVATUSAEventStats: Import via Python script vatusa_event_stats.py';
    PRINT 'Run: python scripts/statsim/vatusa_event_stats.py -o import.sql';
    PRINT 'Then execute the generated SQL file';
END;
GO

PRINT 'Created sp_ImportVATUSAEventStats procedure';
GO

-- =====================================================
-- 7. UTILITY: PARSE EVENT INDEX
-- =====================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.fn_ParseEventIdx') AND type = 'TF')
    DROP FUNCTION dbo.fn_ParseEventIdx;
GO

CREATE FUNCTION dbo.fn_ParseEventIdx(@event_idx VARCHAR(64))
RETURNS @result TABLE (
    start_datetime DATETIME2,
    end_datetime DATETIME2,
    event_type VARCHAR(16),
    event_code VARCHAR(16)
)
AS
BEGIN
    -- Parse: 202003062359T202003070400/FNO/FNO1
    DECLARE @date_part VARCHAR(32), @type_part VARCHAR(16), @code_part VARCHAR(16);
    DECLARE @start_str VARCHAR(12), @end_str VARCHAR(12);

    SET @date_part = LEFT(@event_idx, CHARINDEX('/', @event_idx) - 1);
    SET @type_part = SUBSTRING(@event_idx,
        CHARINDEX('/', @event_idx) + 1,
        CHARINDEX('/', @event_idx, CHARINDEX('/', @event_idx) + 1) - CHARINDEX('/', @event_idx) - 1);
    SET @code_part = SUBSTRING(@event_idx,
        CHARINDEX('/', @event_idx, CHARINDEX('/', @event_idx) + 1) + 1,
        LEN(@event_idx));

    SET @start_str = LEFT(@date_part, 12);
    SET @end_str = RIGHT(@date_part, 12);

    INSERT INTO @result
    SELECT
        -- Parse 202003062359 -> 2020-03-06 23:59
        TRY_CONVERT(DATETIME2,
            LEFT(@start_str, 4) + '-' + SUBSTRING(@start_str, 5, 2) + '-' +
            SUBSTRING(@start_str, 7, 2) + ' ' + SUBSTRING(@start_str, 9, 2) + ':' +
            SUBSTRING(@start_str, 11, 2)),
        TRY_CONVERT(DATETIME2,
            LEFT(@end_str, 4) + '-' + SUBSTRING(@end_str, 5, 2) + '-' +
            SUBSTRING(@end_str, 7, 2) + ' ' + SUBSTRING(@end_str, 9, 2) + ':' +
            SUBSTRING(@end_str, 11, 2)),
        @type_part,
        @code_part;

    RETURN;
END;
GO

PRINT 'Created fn_ParseEventIdx function';
GO

-- =====================================================
-- SUMMARY
-- =====================================================

PRINT '';
PRINT '074_vatusa_event_stats.sql completed successfully';
PRINT '';
PRINT 'Tables created:';
PRINT '  - vatusa_event: Main event metadata';
PRINT '  - vatusa_event_hourly: Hourly traffic per airport';
PRINT '  - vatusa_event_airport: Aggregated airport stats';
PRINT '';
PRINT 'Views created:';
PRINT '  - vw_vatusa_event_summary: Event summary with airports';
PRINT '';
PRINT 'Next steps:';
PRINT '  1. Run import_historical_events.py to import Excel data';
PRINT '  2. Run vatusa_event_stats.py for new events from Statsim';
GO
