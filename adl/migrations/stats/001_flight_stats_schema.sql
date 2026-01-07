-- =====================================================
-- Flight Statistics Schema
-- Migration: 070_flight_stats_schema.sql
-- Database: VATSIM_ADL (Azure SQL)
-- Purpose: Create flight statistics tables with tiered retention
-- =====================================================

SET NOCOUNT ON;
GO

-- =====================================================
-- 1. RETENTION TIER CONFIGURATION
-- =====================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.flight_stats_retention_tiers') AND type = 'U')
CREATE TABLE dbo.flight_stats_retention_tiers (
    tier_id             TINYINT PRIMARY KEY,
    tier_name           VARCHAR(32) NOT NULL,
    description         VARCHAR(128),
    retention_days      INT NOT NULL,           -- 0 = indefinite
    applies_to          VARCHAR(128) NOT NULL,  -- Table name pattern (comma-separated)
    is_active           BIT NOT NULL DEFAULT 1,
    created_utc         DATETIME2 DEFAULT GETUTCDATE()
);
GO

-- Seed retention tiers
IF NOT EXISTS (SELECT 1 FROM dbo.flight_stats_retention_tiers WHERE tier_id = 0)
BEGIN
    INSERT INTO dbo.flight_stats_retention_tiers (tier_id, tier_name, description, retention_days, applies_to) VALUES
    (0, 'HOURLY',   'Hourly statistics - recent trend analysis',      30,  'flight_stats_hourly'),
    (1, 'DAILY',    'Daily statistics - operational analysis',        180, 'flight_stats_daily,flight_stats_airport,flight_stats_citypair,flight_stats_artcc,flight_stats_tmi,flight_stats_aircraft'),
    (2, 'MONTHLY',  'Monthly rollups - seasonal patterns',            730, 'flight_stats_monthly_summary,flight_stats_monthly_airport'),
    (3, 'YEARLY',   'Yearly summaries - long-term trends',            0,   'flight_stats_yearly_summary');
END
GO

-- =====================================================
-- 2. DAILY NETWORK SUMMARY
-- =====================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.flight_stats_daily') AND type = 'U')
CREATE TABLE dbo.flight_stats_daily (
    stats_date          DATE NOT NULL PRIMARY KEY,

    -- Flight Counts
    total_flights       INT,
    completed_flights   INT,                    -- Had all 4 OOOI times
    domestic_flights    INT,                    -- US domestic (K-to-K)
    international_flights INT,                  -- At least one non-K airport

    -- OOOI Completion Rates (percentage)
    pct_out_captured    DECIMAL(5,2),
    pct_off_captured    DECIMAL(5,2),
    pct_on_captured     DECIMAL(5,2),
    pct_in_captured     DECIMAL(5,2),
    pct_complete_oooi   DECIMAL(5,2),           -- All 4 times captured

    -- Time Metrics (minutes)
    avg_block_time_min  DECIMAL(8,2),           -- out_utc to in_utc
    avg_flight_time_min DECIMAL(8,2),           -- off_utc to on_utc
    avg_taxi_out_min    DECIMAL(6,2),           -- out_utc to off_utc
    avg_taxi_in_min     DECIMAL(6,2),           -- on_utc to in_utc

    -- Peak Hour Analysis
    peak_hour_utc       TINYINT,                -- Hour with most flights (0-23)
    peak_hour_flights   INT,                    -- Count during peak hour

    -- TMI Impact
    flights_with_tmi    INT,                    -- Flights with any TMI control
    total_tmi_delay_min INT,                    -- Sum of all TMI delays
    gs_affected_flights INT,                    -- Ground Stop affected
    gdp_affected_flights INT,                   -- GDP affected

    -- DCC Region Breakdown
    arr_ne              INT,                    -- Northeast arrivals
    arr_se              INT,                    -- Southeast arrivals
    arr_mw              INT,                    -- Midwest arrivals
    arr_sc              INT,                    -- South Central arrivals
    arr_w               INT,                    -- West arrivals

    -- Metadata
    created_utc         DATETIME2 DEFAULT GETUTCDATE(),
    updated_utc         DATETIME2,
    retention_tier      TINYINT DEFAULT 1
);
GO

-- =====================================================
-- 3. HOURLY SYSTEM PATTERNS
-- =====================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.flight_stats_hourly') AND type = 'U')
CREATE TABLE dbo.flight_stats_hourly (
    id                  BIGINT IDENTITY(1,1) PRIMARY KEY,
    bucket_utc          DATETIME2 NOT NULL,     -- Hour start (truncated to hour)

    -- Counts by Operation
    departures          INT,
    arrivals            INT,
    enroute             INT,                    -- Active flights mid-flight

    -- Regional Breakdown
    domestic_dep        INT,
    domestic_arr        INT,
    intl_dep            INT,
    intl_arr            INT,

    -- DCC Region Arrivals
    arr_ne              INT,
    arr_se              INT,
    arr_mw              INT,
    arr_sc              INT,
    arr_w               INT,

    -- Average Times (minutes, for flights completing this hour)
    avg_taxi_out_min    DECIMAL(6,2),
    avg_taxi_in_min     DECIMAL(6,2),

    -- TMI Metrics
    tmi_affected        INT,
    avg_tmi_delay_min   DECIMAL(6,2),

    -- Metadata
    created_utc         DATETIME2 DEFAULT GETUTCDATE(),
    retention_tier      TINYINT DEFAULT 0,

    INDEX IX_stats_hourly_bucket (bucket_utc),
    CONSTRAINT UQ_stats_hourly_bucket UNIQUE (bucket_utc)
);
GO

-- =====================================================
-- 4. AIRPORT PERFORMANCE (TAXI TIMES)
-- =====================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.flight_stats_airport') AND type = 'U')
CREATE TABLE dbo.flight_stats_airport (
    id                  BIGINT IDENTITY(1,1) PRIMARY KEY,
    stats_date          DATE NOT NULL,
    icao                VARCHAR(4) NOT NULL,

    -- Operation Counts
    departures          INT,
    arrivals            INT,

    -- Taxi Out Statistics (minutes)
    taxi_out_count      INT,                    -- Sample size
    taxi_out_avg        DECIMAL(6,2),
    taxi_out_min        DECIMAL(6,2),
    taxi_out_max        DECIMAL(6,2),
    taxi_out_p50        DECIMAL(6,2),           -- Median
    taxi_out_p75        DECIMAL(6,2),           -- 75th percentile
    taxi_out_p90        DECIMAL(6,2),           -- 90th percentile
    taxi_out_p95        DECIMAL(6,2),           -- 95th percentile

    -- Taxi In Statistics (minutes)
    taxi_in_count       INT,
    taxi_in_avg         DECIMAL(6,2),
    taxi_in_min         DECIMAL(6,2),
    taxi_in_max         DECIMAL(6,2),
    taxi_in_p50         DECIMAL(6,2),
    taxi_in_p75         DECIMAL(6,2),
    taxi_in_p90         DECIMAL(6,2),
    taxi_in_p95         DECIMAL(6,2),

    -- Extended Segment Times (seconds, averages)
    avg_pushback_sec    INT,                    -- parking_left to taxiway_entered
    avg_taxi_to_hold_sec INT,                   -- taxiway_entered to hold_entered
    avg_hold_time_sec   INT,                    -- hold_entered to runway_entered
    avg_runway_time_sec INT,                    -- runway_entered to off_utc
    avg_rollout_sec     INT,                    -- touchdown to rollout_end
    avg_arrival_taxi_sec INT,                   -- rollout_end to parking_entered

    -- Peak Hours
    peak_dep_hour       TINYINT,                -- Hour with most departures (0-23)
    peak_arr_hour       TINYINT,                -- Hour with most arrivals (0-23)

    -- Metadata
    created_utc         DATETIME2 DEFAULT GETUTCDATE(),
    retention_tier      TINYINT DEFAULT 1,

    CONSTRAINT UQ_stats_airport UNIQUE (stats_date, icao),
    INDEX IX_stats_airport_date (stats_date),
    INDEX IX_stats_airport_icao (icao)
);
GO

-- =====================================================
-- 5. CITY PAIR / ROUTE ANALYTICS
-- =====================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.flight_stats_citypair') AND type = 'U')
CREATE TABLE dbo.flight_stats_citypair (
    id                  BIGINT IDENTITY(1,1) PRIMARY KEY,
    stats_date          DATE NOT NULL,
    origin_icao         VARCHAR(4) NOT NULL,
    dest_icao           VARCHAR(4) NOT NULL,

    -- Counts
    flight_count        INT,
    completed_count     INT,                    -- All OOOI times captured

    -- Block Time (gate-to-gate, minutes)
    block_time_avg      DECIMAL(8,2),
    block_time_min      DECIMAL(8,2),
    block_time_max      DECIMAL(8,2),
    block_time_p50      DECIMAL(8,2),           -- Median

    -- Flight Time (wheels-up to wheels-down, minutes)
    flight_time_avg     DECIMAL(8,2),
    flight_time_min     DECIMAL(8,2),
    flight_time_max     DECIMAL(8,2),
    flight_time_p50     DECIMAL(8,2),

    -- Taxi Times (minutes)
    taxi_out_avg        DECIMAL(6,2),
    taxi_in_avg         DECIMAL(6,2),

    -- TMI Impact
    tmi_affected        INT,
    avg_tmi_delay_min   DECIMAL(6,2),

    -- Top Aircraft Types (JSON array: [{"type":"B738","count":25},...]
    top_aircraft_types  NVARCHAR(500),

    -- Metadata
    created_utc         DATETIME2 DEFAULT GETUTCDATE(),
    retention_tier      TINYINT DEFAULT 1,

    CONSTRAINT UQ_stats_citypair UNIQUE (stats_date, origin_icao, dest_icao),
    INDEX IX_stats_citypair_date (stats_date),
    INDEX IX_stats_citypair_origin (origin_icao),
    INDEX IX_stats_citypair_dest (dest_icao),
    INDEX IX_stats_citypair_route (origin_icao, dest_icao)
);
GO

-- =====================================================
-- 6. ARTCC TRAFFIC STATISTICS
-- =====================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.flight_stats_artcc') AND type = 'U')
CREATE TABLE dbo.flight_stats_artcc (
    id                  BIGINT IDENTITY(1,1) PRIMARY KEY,
    stats_date          DATE NOT NULL,
    artcc               VARCHAR(4) NOT NULL,    -- ZDC, ZNY, etc.

    -- Traffic Counts
    entries             INT,                    -- Flights entering this ARTCC
    exits               INT,                    -- Flights exiting this ARTCC
    transits            INT,                    -- Flights passing through (entered AND exited)
    peak_count          INT,                    -- Max simultaneous flights in airspace

    -- Time in Airspace (minutes)
    avg_time_in_artcc   DECIMAL(8,2),
    min_time_in_artcc   DECIMAL(8,2),
    max_time_in_artcc   DECIMAL(8,2),

    -- Hourly Distribution (JSON: [h0_count, h1_count, ..., h23_count])
    hourly_entries      NVARCHAR(500),

    -- Peak Hour
    peak_hour           TINYINT,                -- Hour with most entries (0-23)
    peak_hour_entries   INT,                    -- Count during peak hour

    -- Origin/Destination Breakdown (top 5 JSON arrays)
    top_origins         NVARCHAR(500),          -- [{"icao":"KJFK","count":100},...]
    top_destinations    NVARCHAR(500),          -- [{"icao":"KLAX","count":95},...]

    -- Metadata
    created_utc         DATETIME2 DEFAULT GETUTCDATE(),
    retention_tier      TINYINT DEFAULT 1,

    CONSTRAINT UQ_stats_artcc UNIQUE (stats_date, artcc),
    INDEX IX_stats_artcc_date (stats_date),
    INDEX IX_stats_artcc_artcc (artcc)
);
GO

-- =====================================================
-- 7. TMI IMPACT ANALYSIS
-- =====================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.flight_stats_tmi') AND type = 'U')
CREATE TABLE dbo.flight_stats_tmi (
    id                  BIGINT IDENTITY(1,1) PRIMARY KEY,
    stats_date          DATE NOT NULL,
    tmi_type            VARCHAR(16) NOT NULL,   -- GS, GDP, AFP, REROUTE, MIT, MINIT, etc.
    airport_icao        VARCHAR(4) NULL,        -- Target airport (NULL for system-wide)

    -- Affected Flights
    affected_flights    INT,
    exempt_flights      INT,

    -- Delay Metrics (minutes)
    total_delay_min     INT,
    avg_delay_min       DECIMAL(8,2),
    max_delay_min       INT,
    p50_delay_min       DECIMAL(8,2),
    p90_delay_min       DECIMAL(8,2),

    -- Duration
    program_duration_min INT,                   -- How long the TMI was active

    -- Hourly Breakdown (JSON: {"h10":5,"h11":12,...})
    hourly_affected     NVARCHAR(500),

    -- Metadata
    created_utc         DATETIME2 DEFAULT GETUTCDATE(),
    retention_tier      TINYINT DEFAULT 1,

    CONSTRAINT UQ_stats_tmi UNIQUE (stats_date, tmi_type, airport_icao),
    INDEX IX_stats_tmi_date (stats_date),
    INDEX IX_stats_tmi_type (tmi_type),
    INDEX IX_stats_tmi_airport (airport_icao)
);
GO

-- =====================================================
-- 8. AIRCRAFT TYPE ANALYTICS
-- =====================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.flight_stats_aircraft') AND type = 'U')
CREATE TABLE dbo.flight_stats_aircraft (
    id                  BIGINT IDENTITY(1,1) PRIMARY KEY,
    stats_date          DATE NOT NULL,
    aircraft_type       VARCHAR(10) NOT NULL,   -- ICAO type code (B738, A320, etc.)

    -- Counts
    flight_count        INT,
    completed_count     INT,                    -- All OOOI times captured

    -- Performance Averages
    avg_groundspeed_kts INT,
    avg_cruise_altitude INT,                    -- Feet
    avg_flight_time_min DECIMAL(8,2),
    avg_taxi_out_min    DECIMAL(6,2),
    avg_taxi_in_min     DECIMAL(6,2),

    -- Top Routes (JSON: [{"origin":"KJFK","dest":"KLAX","count":25},...]
    top_routes          NVARCHAR(1000),

    -- Top Airlines Using This Type (JSON: [{"airline":"AAL","count":50},...]
    top_airlines        NVARCHAR(500),

    -- Metadata
    created_utc         DATETIME2 DEFAULT GETUTCDATE(),
    retention_tier      TINYINT DEFAULT 1,

    CONSTRAINT UQ_stats_aircraft UNIQUE (stats_date, aircraft_type),
    INDEX IX_stats_aircraft_date (stats_date),
    INDEX IX_stats_aircraft_type (aircraft_type)
);
GO

-- =====================================================
-- 9. MONTHLY ROLLUP TABLES
-- =====================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.flight_stats_monthly_summary') AND type = 'U')
CREATE TABLE dbo.flight_stats_monthly_summary (
    id                  BIGINT IDENTITY(1,1) PRIMARY KEY,
    stats_month         DATE NOT NULL,          -- First day of month

    -- Aggregated Counts
    total_flights       INT,
    completed_flights   INT,
    avg_daily_flights   DECIMAL(10,2),

    -- OOOI Rates (average across days)
    avg_pct_complete_oooi DECIMAL(5,2),

    -- Time Metrics (averages)
    avg_block_time_min  DECIMAL(8,2),
    avg_flight_time_min DECIMAL(8,2),
    avg_taxi_out_min    DECIMAL(6,2),
    avg_taxi_in_min     DECIMAL(6,2),

    -- TMI Impact
    total_tmi_affected  INT,
    avg_daily_tmi_delay_min DECIMAL(8,2),

    -- Peak Analysis
    busiest_day         DATE,
    busiest_day_flights INT,

    -- Metadata
    days_with_data      INT,                    -- How many days contributed
    created_utc         DATETIME2 DEFAULT GETUTCDATE(),
    retention_tier      TINYINT DEFAULT 2,

    CONSTRAINT UQ_stats_monthly UNIQUE (stats_month)
);
GO

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.flight_stats_monthly_airport') AND type = 'U')
CREATE TABLE dbo.flight_stats_monthly_airport (
    id                  BIGINT IDENTITY(1,1) PRIMARY KEY,
    stats_month         DATE NOT NULL,          -- First day of month
    icao                VARCHAR(4) NOT NULL,

    -- Aggregated Counts
    total_departures    INT,
    total_arrivals      INT,
    avg_daily_departures DECIMAL(8,2),
    avg_daily_arrivals  DECIMAL(8,2),

    -- Taxi Time Averages (across month)
    avg_taxi_out_min    DECIMAL(6,2),
    avg_taxi_in_min     DECIMAL(6,2),
    p90_taxi_out_min    DECIMAL(6,2),
    p90_taxi_in_min     DECIMAL(6,2),

    -- Metadata
    days_with_data      INT,
    created_utc         DATETIME2 DEFAULT GETUTCDATE(),
    retention_tier      TINYINT DEFAULT 2,

    CONSTRAINT UQ_stats_monthly_airport UNIQUE (stats_month, icao),
    INDEX IX_stats_monthly_airport_month (stats_month),
    INDEX IX_stats_monthly_airport_icao (icao)
);
GO

-- =====================================================
-- 10. YEARLY SUMMARY TABLE
-- =====================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.flight_stats_yearly_summary') AND type = 'U')
CREATE TABLE dbo.flight_stats_yearly_summary (
    stats_year          INT NOT NULL PRIMARY KEY,

    -- Aggregated Counts
    total_flights       BIGINT,
    completed_flights   BIGINT,
    avg_daily_flights   DECIMAL(10,2),

    -- Time Metrics
    avg_block_time_min  DECIMAL(8,2),
    avg_flight_time_min DECIMAL(8,2),
    avg_taxi_out_min    DECIMAL(6,2),
    avg_taxi_in_min     DECIMAL(6,2),

    -- Peak Analysis
    busiest_month       DATE,
    busiest_month_flights INT,
    busiest_day         DATE,
    busiest_day_flights INT,

    -- Metadata
    months_with_data    INT,
    created_utc         DATETIME2 DEFAULT GETUTCDATE(),
    retention_tier      TINYINT DEFAULT 3
);
GO

-- =====================================================
-- 11. STATISTICS RUN LOG
-- =====================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.flight_stats_run_log') AND type = 'U')
CREATE TABLE dbo.flight_stats_run_log (
    id                  BIGINT IDENTITY(1,1) PRIMARY KEY,
    run_type            VARCHAR(32) NOT NULL,   -- HOURLY, DAILY, MONTHLY, YEARLY, CLEANUP
    started_utc         DATETIME2 NOT NULL,
    completed_utc       DATETIME2,
    status              VARCHAR(16) NOT NULL,   -- RUNNING, SUCCESS, FAILED
    records_processed   INT,
    records_inserted    INT,
    records_deleted     INT,                    -- For cleanup runs
    error_message       NVARCHAR(MAX),
    execution_ms        INT
);
GO

CREATE INDEX IX_stats_run_log_type ON dbo.flight_stats_run_log (run_type, started_utc DESC);
GO

-- =====================================================
-- 12. HELPER VIEWS
-- =====================================================

-- View: Recent hourly stats (last 7 days)
IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_flight_stats_hourly_recent')
    DROP VIEW dbo.vw_flight_stats_hourly_recent;
GO

CREATE VIEW dbo.vw_flight_stats_hourly_recent AS
SELECT *
FROM dbo.flight_stats_hourly
WHERE bucket_utc >= DATEADD(DAY, -7, GETUTCDATE());
GO

-- View: Today's airport stats
IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_flight_stats_airport_today')
    DROP VIEW dbo.vw_flight_stats_airport_today;
GO

CREATE VIEW dbo.vw_flight_stats_airport_today AS
SELECT *
FROM dbo.flight_stats_airport
WHERE stats_date = CAST(GETUTCDATE() AS DATE);
GO

-- View: Active retention tiers
IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_flight_stats_retention_active')
    DROP VIEW dbo.vw_flight_stats_retention_active;
GO

CREATE VIEW dbo.vw_flight_stats_retention_active AS
SELECT
    tier_id,
    tier_name,
    retention_days,
    applies_to,
    CASE WHEN retention_days = 0 THEN 'Indefinite'
         ELSE CAST(retention_days AS VARCHAR) + ' days'
    END AS retention_display
FROM dbo.flight_stats_retention_tiers
WHERE is_active = 1;
GO

PRINT '070_flight_stats_schema.sql completed successfully';
GO
