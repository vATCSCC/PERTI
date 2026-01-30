-- ============================================================================
-- VATSIM_STATS Database Schema
-- Complete schema for VATSIM network statistics, pattern detection, and analytics
--
-- Created: 2026-01-30
-- Target: Azure SQL Free Tier (Serverless GP_S_Gen5_1)
-- ============================================================================

-- ============================================================================
-- SECTION 1: CONFIGURATION & METADATA TABLES
-- ============================================================================

-- Retention tier configuration
CREATE TABLE stats_retention_config (
    tier_id             TINYINT PRIMARY KEY,
    tier_name           VARCHAR(20) NOT NULL,
    description         NVARCHAR(200),
    retention_days      INT NULL,              -- NULL = forever
    compression_type    VARCHAR(10) DEFAULT 'NONE',  -- NONE, ROW, PAGE
    archive_to_blob     BIT DEFAULT 0,
    blob_container      VARCHAR(100) NULL,
    is_active           BIT DEFAULT 1,
    created_utc         DATETIME2 DEFAULT GETUTCDATE()
);

INSERT INTO stats_retention_config (tier_id, tier_name, description, retention_days, compression_type, archive_to_blob) VALUES
(0, 'HOT',    'Real-time data, in-memory optimized', 2, 'NONE', 0),
(1, 'WARM',   'Recent operational data', 90, 'NONE', 0),
(2, 'COOL',   'Historical analysis data', 730, 'PAGE', 0),
(3, 'COLD',   'Archive data in blob storage', NULL, 'PAGE', 1);

-- Job configuration
CREATE TABLE stats_job_config (
    job_id              INT IDENTITY PRIMARY KEY,
    job_name            VARCHAR(50) NOT NULL UNIQUE,
    procedure_name      VARCHAR(100) NOT NULL,
    job_category        VARCHAR(20) NOT NULL,  -- REALTIME, HOURLY, DAILY, WEEKLY, MONTHLY
    schedule_cron       VARCHAR(50),           -- Cron expression
    schedule_utc_hour   TINYINT NULL,
    schedule_utc_minute TINYINT NULL,
    schedule_dow        TINYINT NULL,          -- 1=Sunday, 7=Saturday
    schedule_dom        TINYINT NULL,          -- Day of month (1-31)
    priority            TINYINT DEFAULT 50,    -- 1=highest, 100=lowest
    timeout_seconds     INT DEFAULT 300,
    is_enabled          BIT DEFAULT 1,
    last_run_utc        DATETIME2 NULL,
    last_run_status     VARCHAR(20) NULL,
    last_run_duration_ms INT NULL,
    description         NVARCHAR(500),
    created_utc         DATETIME2 DEFAULT GETUTCDATE()
);

-- Job run history
CREATE TABLE stats_job_run_log (
    run_id              BIGINT IDENTITY PRIMARY KEY,
    job_id              INT NOT NULL,
    job_name            VARCHAR(50) NOT NULL,
    started_utc         DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
    completed_utc       DATETIME2 NULL,
    status              VARCHAR(20) NOT NULL DEFAULT 'RUNNING',  -- RUNNING, SUCCESS, FAILED, TIMEOUT
    records_processed   INT NULL,
    records_inserted    INT NULL,
    records_updated     INT NULL,
    records_deleted     INT NULL,
    execution_ms        INT NULL,
    error_message       NVARCHAR(MAX) NULL,

    INDEX ix_job_run_time (job_id, started_utc DESC)
);

-- ============================================================================
-- SECTION 2: DIMENSION TABLES
-- ============================================================================

-- Time dimension (pre-populated for 10 years)
CREATE TABLE dim_time (
    time_id             INT PRIMARY KEY,       -- YYYYMMDDHHMM format
    full_timestamp      DATETIME2 NOT NULL,

    -- Minute granularity
    minute_of_hour      TINYINT NOT NULL,      -- 0-59
    minute_bin_15       TINYINT NOT NULL,      -- 0, 15, 30, 45
    minute_bin_30       TINYINT NOT NULL,      -- 0, 30

    -- Hour granularity
    hour_of_day         TINYINT NOT NULL,      -- 0-23

    -- Time-of-day bins (UTC-based for VATSIM global ops)
    time_of_day         VARCHAR(10) NOT NULL,  -- night, morning, afternoon, evening
    time_of_day_code    TINYINT NOT NULL,      -- 0=night, 1=morning, 2=afternoon, 3=evening

    -- Day granularity
    day_of_week         TINYINT NOT NULL,      -- 1=Sunday, 7=Saturday
    day_of_week_name    VARCHAR(3) NOT NULL,   -- Sun, Mon, Tue, etc.
    day_of_month        TINYINT NOT NULL,      -- 1-31
    day_of_year         SMALLINT NOT NULL,     -- 1-366
    is_weekend          BIT NOT NULL,

    -- Week granularity
    week_of_year        TINYINT NOT NULL,      -- 1-53
    iso_week            TINYINT NOT NULL,      -- ISO 8601 week

    -- Month granularity
    month_num           TINYINT NOT NULL,      -- 1-12
    month_name          VARCHAR(3) NOT NULL,   -- Jan, Feb, etc.
    month_name_full     VARCHAR(10) NOT NULL,

    -- Quarter granularity
    quarter_num         TINYINT NOT NULL,      -- 1-4
    quarter_name        VARCHAR(2) NOT NULL,   -- Q1, Q2, Q3, Q4

    -- Season (meteorological, Northern Hemisphere focus for VATSIM US)
    season              VARCHAR(6) NOT NULL,   -- winter, spring, summer, fall
    season_code         VARCHAR(3) NOT NULL,   -- DJF, MAM, JJA, SON

    -- Year
    year_num            SMALLINT NOT NULL,

    -- Computed flags for common queries
    is_peak_hour        BIT NOT NULL,          -- 08-14 UTC
    is_quiet_hour       BIT NOT NULL,          -- 00-06 UTC

    INDEX ix_time_timestamp (full_timestamp),
    INDEX ix_time_date (year_num, month_num, day_of_month),
    INDEX ix_time_dow_hour (day_of_week, hour_of_day),
    INDEX ix_time_season (season_code, year_num)
);

-- Populate dim_time for 2020-2035 (5-minute intervals)
-- This generates ~1.5M rows, about 150MB
-- Run as a separate script or procedure

-- Traffic baselines (for real-time tagging)
CREATE TABLE traffic_baselines (
    baseline_id         INT IDENTITY PRIMARY KEY,
    baseline_type       VARCHAR(30) NOT NULL,  -- hourly_dow, hourly_season, daily_month, etc.
    group_key           VARCHAR(30) NOT NULL,  -- "Mon_14", "summer_Fri", etc.

    -- Baseline statistics
    sample_count        INT NOT NULL,
    avg_value           DECIMAL(10,2) NOT NULL,
    std_dev             DECIMAL(10,2),
    min_value           INT,
    max_value           INT,
    p10                 INT,
    p25                 INT,
    p50                 INT,                   -- median
    p75                 INT,
    p90                 INT,
    p95                 INT,
    p99                 INT,

    -- Metadata
    computed_from_date  DATE,
    computed_to_date    DATE,
    updated_utc         DATETIME2 DEFAULT GETUTCDATE(),

    CONSTRAINT uq_baseline UNIQUE (baseline_type, group_key),
    INDEX ix_baseline_lookup (baseline_type, group_key)
);

-- ============================================================================
-- SECTION 3: FACT TABLES (Core Snapshots)
-- ============================================================================

-- Network-level 5-minute snapshots
CREATE TABLE fact_network_5min (
    snapshot_id         BIGINT IDENTITY PRIMARY KEY,
    snapshot_time       DATETIME2 NOT NULL,
    time_id             INT NOT NULL,          -- FK to dim_time

    -- Raw metrics
    total_pilots        INT NOT NULL,
    total_controllers   INT NOT NULL,
    total_observers     INT DEFAULT 0,
    total_atis          INT DEFAULT 0,

    -- Derived metrics
    pilot_controller_ratio DECIMAL(5,2),

    -- Pre-computed time tags (denormalized for query speed)
    hour_of_day         TINYINT NOT NULL,
    minute_bin_15       TINYINT NOT NULL,
    minute_bin_30       TINYINT NOT NULL,
    time_of_day         VARCHAR(10) NOT NULL,
    day_of_week         TINYINT NOT NULL,
    day_of_week_name    VARCHAR(3) NOT NULL,
    is_weekend          BIT NOT NULL,
    week_of_year        TINYINT NOT NULL,
    month_num           TINYINT NOT NULL,
    season_code         VARCHAR(3) NOT NULL,
    year_num            SMALLINT NOT NULL,

    -- Traffic level tags (computed at INSERT using baselines)
    traffic_level       VARCHAR(10),           -- quiet, low, normal, busy, peak
    percentile_rank     TINYINT,               -- 1-100
    vs_hour_avg         VARCHAR(10),           -- below, normal, above
    vs_dow_avg          VARCHAR(10),           -- vs same day-of-week average
    vs_season_avg       VARCHAR(10),           -- vs seasonal average
    is_above_p75        BIT DEFAULT 0,
    is_above_p90        BIT DEFAULT 0,
    is_above_p95        BIT DEFAULT 0,
    is_peak_of_day      BIT DEFAULT 0,

    -- Tiering
    retention_tier      TINYINT DEFAULT 0,

    -- Indexes
    INDEX ix_network_time (snapshot_time DESC),
    INDEX ix_network_hot (snapshot_time DESC) WHERE retention_tier = 0,
    INDEX ix_network_dow_hour (day_of_week, hour_of_day, snapshot_time DESC),
    INDEX ix_network_traffic (traffic_level, snapshot_time DESC),
    INDEX ix_network_season (season_code, year_num, snapshot_time DESC)
);

-- Facility-level 5-minute snapshots
CREATE TABLE fact_facility_5min (
    snapshot_id         BIGINT IDENTITY PRIMARY KEY,
    snapshot_time       DATETIME2 NOT NULL,
    time_id             INT NOT NULL,

    -- Facility reference (links to VATSIM_ADL.artcc_facilities or similar)
    facility_code       VARCHAR(10) NOT NULL,  -- ZNY, ZDC, EGLL_APP, etc.
    facility_type       VARCHAR(20) NOT NULL,  -- ARTCC, TRACON, TOWER, FSS
    parent_artcc        VARCHAR(4) NULL,       -- Parent ARTCC for TRACONs

    -- Traffic metrics
    pilots_in_airspace  INT DEFAULT 0,
    controllers_online  INT DEFAULT 0,
    departures_active   INT DEFAULT 0,
    arrivals_active     INT DEFAULT 0,
    overflights         INT DEFAULT 0,

    -- Time tags (denormalized)
    hour_of_day         TINYINT NOT NULL,
    time_of_day         VARCHAR(10) NOT NULL,
    day_of_week         TINYINT NOT NULL,
    is_weekend          BIT NOT NULL,
    season_code         VARCHAR(3) NOT NULL,
    year_num            SMALLINT NOT NULL,

    -- Traffic tags
    traffic_level       VARCHAR(10),
    vs_facility_avg     VARCHAR(10),           -- vs this facility's average

    -- Tiering
    retention_tier      TINYINT DEFAULT 0,

    INDEX ix_facility_time (facility_code, snapshot_time DESC),
    INDEX ix_facility_hot (facility_code, snapshot_time DESC) WHERE retention_tier = 0,
    INDEX ix_facility_type (facility_type, snapshot_time DESC)
);

-- ============================================================================
-- SECTION 4: AGGREGATED STATS TABLES
-- ============================================================================

-- Hourly network rollup
CREATE TABLE stats_network_hourly (
    stat_id             BIGINT IDENTITY PRIMARY KEY,
    bucket_hour         DATETIME2 NOT NULL,    -- Truncated to hour

    -- Snapshot aggregates
    snapshot_count      INT NOT NULL,          -- Should be ~12 per hour

    -- Pilot metrics
    pilots_min          INT,
    pilots_max          INT,
    pilots_avg          DECIMAL(8,2),
    pilots_p50          INT,
    pilots_std_dev      DECIMAL(8,2),

    -- Controller metrics
    controllers_min     INT,
    controllers_max     INT,
    controllers_avg     DECIMAL(8,2),

    -- Time tags
    hour_of_day         TINYINT NOT NULL,
    time_of_day         VARCHAR(10) NOT NULL,
    day_of_week         TINYINT NOT NULL,
    is_weekend          BIT NOT NULL,

    -- Tiering
    retention_tier      TINYINT DEFAULT 0,
    created_utc         DATETIME2 DEFAULT GETUTCDATE(),

    CONSTRAINT uq_hourly_bucket UNIQUE (bucket_hour),
    INDEX ix_hourly_time (bucket_hour DESC),
    INDEX ix_hourly_dow (day_of_week, hour_of_day)
);

-- Daily network summary
CREATE TABLE stats_network_daily (
    stat_date           DATE PRIMARY KEY,

    -- Volume metrics
    total_snapshots     INT,

    -- Pilot aggregates
    pilots_min          INT,
    pilots_max          INT,
    pilots_avg          DECIMAL(8,2),
    pilots_p50          INT,
    pilots_p90          INT,
    pilots_p95          INT,
    pilots_std_dev      DECIMAL(8,2),
    pilots_total_minutes INT,                  -- Sum of all 5-min counts (activity measure)

    -- Controller aggregates
    controllers_min     INT,
    controllers_max     INT,
    controllers_avg     DECIMAL(8,2),
    controllers_total_minutes INT,

    -- Peak analysis
    peak_hour_utc       TINYINT,
    peak_hour_pilots    INT,
    trough_hour_utc     TINYINT,
    trough_hour_pilots  INT,

    -- Distribution by time-of-day (% of daily total)
    pct_night           DECIMAL(5,2),          -- 00-06 UTC
    pct_morning         DECIMAL(5,2),          -- 06-12 UTC
    pct_afternoon       DECIMAL(5,2),          -- 12-18 UTC
    pct_evening         DECIMAL(5,2),          -- 18-00 UTC

    -- Time tags
    day_of_week         TINYINT NOT NULL,
    day_of_week_name    VARCHAR(3) NOT NULL,
    is_weekend          BIT NOT NULL,
    week_of_year        TINYINT NOT NULL,
    month_num           TINYINT NOT NULL,
    season_code         VARCHAR(3) NOT NULL,
    year_num            SMALLINT NOT NULL,

    -- Pattern tags (assigned by daily batch)
    traffic_level       VARCHAR(10),           -- Based on daily total
    is_event_day        BIT DEFAULT 0,         -- Flagged if significantly above normal
    event_name          NVARCHAR(100) NULL,    -- If known event

    -- Tiering
    retention_tier      TINYINT DEFAULT 1,
    created_utc         DATETIME2 DEFAULT GETUTCDATE(),
    updated_utc         DATETIME2 NULL,

    INDEX ix_daily_dow (day_of_week, stat_date DESC),
    INDEX ix_daily_month (year_num, month_num),
    INDEX ix_daily_season (season_code, year_num)
);

-- Weekly summary
CREATE TABLE stats_network_weekly (
    year_week           VARCHAR(8) PRIMARY KEY, -- '2026-W05'
    week_start_date     DATE NOT NULL,
    week_end_date       DATE NOT NULL,

    -- Volume metrics
    total_days          INT,                    -- Should be 7
    pilots_weekly_avg   DECIMAL(8,2),
    pilots_weekly_max   INT,
    controllers_weekly_avg DECIMAL(8,2),

    -- Daily breakdown
    daily_pilots_json   NVARCHAR(200),          -- [sun, mon, tue, wed, thu, fri, sat]
    peak_day            VARCHAR(3),
    peak_day_pilots     INT,

    -- Comparison
    vs_prev_week_pct    DECIMAL(5,2),
    vs_4week_avg_pct    DECIMAL(5,2),

    -- Tags
    week_of_year        TINYINT NOT NULL,
    year_num            SMALLINT NOT NULL,
    season_code         VARCHAR(3) NOT NULL,

    retention_tier      TINYINT DEFAULT 1,
    created_utc         DATETIME2 DEFAULT GETUTCDATE()
);

-- Monthly summary
CREATE TABLE stats_network_monthly (
    year_month          VARCHAR(7) PRIMARY KEY, -- '2026-01'
    month_start_date    DATE NOT NULL,

    -- Volume metrics
    total_days          INT,
    pilots_monthly_avg  DECIMAL(8,2),
    pilots_monthly_max  INT,
    controllers_monthly_avg DECIMAL(8,2),

    -- Weekly breakdown
    weekly_pilots_json  NVARCHAR(200),

    -- Day-of-week patterns
    dow_avg_json        NVARCHAR(200),          -- Avg by dow within month
    busiest_dow         VARCHAR(3),
    quietest_dow        VARCHAR(3),

    -- Comparison
    vs_prev_month_pct   DECIMAL(5,2),
    vs_prev_year_pct    DECIMAL(5,2),
    vs_3yr_avg_pct      DECIMAL(5,2),
    seasonal_rank       TINYINT,                -- 1=busiest month of year

    -- Tags
    month_num           TINYINT NOT NULL,
    year_num            SMALLINT NOT NULL,
    quarter_num         TINYINT NOT NULL,
    season_code         VARCHAR(3) NOT NULL,

    retention_tier      TINYINT DEFAULT 2,
    created_utc         DATETIME2 DEFAULT GETUTCDATE()
);

-- Yearly summary
CREATE TABLE stats_network_yearly (
    year_num            SMALLINT PRIMARY KEY,

    -- Volume metrics
    total_days          INT,
    pilots_yearly_avg   DECIMAL(8,2),
    pilots_yearly_max   INT,
    pilots_yearly_total BIGINT,                 -- Sum of daily totals

    -- Monthly breakdown
    monthly_avg_json    NVARCHAR(500),
    busiest_month       TINYINT,
    quietest_month      TINYINT,

    -- Seasonal patterns
    seasonal_avg_json   NVARCHAR(200),          -- {winter: X, spring: Y, ...}
    busiest_season      VARCHAR(6),

    -- Growth
    vs_prev_year_pct    DECIMAL(5,2),

    retention_tier      TINYINT DEFAULT 3,
    created_utc         DATETIME2 DEFAULT GETUTCDATE()
);

-- ============================================================================
-- SECTION 5: DIMENSIONAL STATS (Carrier, Aircraft, Facility, etc.)
-- ============================================================================

-- Daily carrier stats (links to VATSIM_ADL.airlines)
CREATE TABLE stats_carrier_daily (
    stat_id             BIGINT IDENTITY PRIMARY KEY,
    stat_date           DATE NOT NULL,
    carrier_icao        VARCHAR(4) NOT NULL,
    carrier_name        NVARCHAR(100),

    -- Flight metrics
    flight_count        INT,
    completed_flights   INT,

    -- Time tags
    day_of_week         TINYINT NOT NULL,
    season_code         VARCHAR(3) NOT NULL,
    year_num            SMALLINT NOT NULL,

    retention_tier      TINYINT DEFAULT 1,
    created_utc         DATETIME2 DEFAULT GETUTCDATE(),

    CONSTRAINT uq_carrier_daily UNIQUE (stat_date, carrier_icao),
    INDEX ix_carrier_date (carrier_icao, stat_date DESC)
);

-- Daily aircraft stats (links to VATSIM_ADL.aircraft_type_lookup)
CREATE TABLE stats_aircraft_daily (
    stat_id             BIGINT IDENTITY PRIMARY KEY,
    stat_date           DATE NOT NULL,
    aircraft_icao       VARCHAR(4) NOT NULL,
    aircraft_category   VARCHAR(20),           -- Jet, Prop, Heli, etc.
    weight_class        VARCHAR(10),           -- Light, Medium, Heavy, Super

    -- Flight metrics
    flight_count        INT,

    -- Time tags
    day_of_week         TINYINT NOT NULL,
    season_code         VARCHAR(3) NOT NULL,
    year_num            SMALLINT NOT NULL,

    retention_tier      TINYINT DEFAULT 1,
    created_utc         DATETIME2 DEFAULT GETUTCDATE(),

    CONSTRAINT uq_aircraft_daily UNIQUE (stat_date, aircraft_icao),
    INDEX ix_aircraft_date (aircraft_icao, stat_date DESC)
);

-- Daily facility stats
CREATE TABLE stats_facility_daily (
    stat_id             BIGINT IDENTITY PRIMARY KEY,
    stat_date           DATE NOT NULL,
    facility_code       VARCHAR(10) NOT NULL,
    facility_type       VARCHAR(20) NOT NULL,

    -- Traffic metrics
    total_operations    INT,                   -- Departures + arrivals + overflights
    departures          INT,
    arrivals            INT,
    overflights         INT,
    peak_concurrent     INT,

    -- Controller metrics
    controller_hours    DECIMAL(8,2),

    -- Time tags
    day_of_week         TINYINT NOT NULL,
    season_code         VARCHAR(3) NOT NULL,
    year_num            SMALLINT NOT NULL,

    retention_tier      TINYINT DEFAULT 1,
    created_utc         DATETIME2 DEFAULT GETUTCDATE(),

    CONSTRAINT uq_facility_daily UNIQUE (stat_date, facility_code),
    INDEX ix_facility_date (facility_code, stat_date DESC)
);

-- Daily airport stats
CREATE TABLE stats_airport_daily (
    stat_id             BIGINT IDENTITY PRIMARY KEY,
    stat_date           DATE NOT NULL,
    airport_icao        VARCHAR(4) NOT NULL,

    -- Traffic metrics
    departures          INT,
    arrivals            INT,

    -- Time tags
    day_of_week         TINYINT NOT NULL,
    season_code         VARCHAR(3) NOT NULL,
    year_num            SMALLINT NOT NULL,

    retention_tier      TINYINT DEFAULT 1,
    created_utc         DATETIME2 DEFAULT GETUTCDATE(),

    CONSTRAINT uq_airport_daily UNIQUE (stat_date, airport_icao),
    INDEX ix_airport_date (airport_icao, stat_date DESC)
);

-- ============================================================================
-- SECTION 6: PATTERN DETECTION & ANALOG MATCHING
-- ============================================================================

-- Pattern archetypes (canonical pattern templates)
CREATE TABLE pattern_archetypes (
    archetype_id        INT IDENTITY PRIMARY KEY,
    archetype_name      VARCHAR(50) NOT NULL UNIQUE,
    archetype_category  VARCHAR(30) NOT NULL,  -- TYPICAL, EVENT, HOLIDAY, ANOMALY
    description         NVARCHAR(500),

    -- Canonical hourly shape (24 values, normalized 0-100)
    canonical_pattern   VARCHAR(200),          -- "5,8,12,25,45,65,80,85,82,75,..."

    -- Defining characteristics
    typical_peak_hour   TINYINT,
    typical_peak_range  VARCHAR(10),           -- "18-22"
    typical_trough_hour TINYINT,
    expected_dow        VARCHAR(30),           -- "Fri,Sat" or "Mon-Thu" or "any"
    expected_season     VARCHAR(30),           -- "any" or "summer" etc.
    expected_volume     VARCHAR(20),           -- "normal", "high", "very_high"
    volatility          VARCHAR(10),           -- "low", "medium", "high"

    -- Matching thresholds
    min_correlation     DECIMAL(4,3) DEFAULT 0.80,

    -- Metadata
    is_auto_generated   BIT DEFAULT 0,         -- ML-generated vs manually defined
    is_active           BIT DEFAULT 1,
    sample_count        INT DEFAULT 0,         -- How many days match this archetype
    created_utc         DATETIME2 DEFAULT GETUTCDATE(),
    updated_utc         DATETIME2 NULL
);

-- Seed common archetypes
INSERT INTO pattern_archetypes (archetype_name, archetype_category, description, expected_dow, expected_volume) VALUES
('typical_weekday',      'TYPICAL', 'Standard weekday pattern with morning ramp, afternoon peak', 'Mon-Fri', 'normal'),
('typical_weekend',      'TYPICAL', 'Relaxed weekend pattern, later peak, lower volume', 'Sat,Sun', 'low'),
('friday_night_ops',     'EVENT',   'FNO event pattern - extended evening peak', 'Fri', 'high'),
('cross_the_pond',       'EVENT',   'CTP event - massive volume, extended duration', 'Sat', 'very_high'),
('worldflight',          'EVENT',   'WorldFlight event pattern', 'any', 'very_high'),
('holiday_lull',         'HOLIDAY', 'Major holiday - reduced activity', 'any', 'very_low'),
('holiday_travel',       'HOLIDAY', 'Holiday travel surge (Thanksgiving, etc.)', 'any', 'high'),
('summer_peak',          'TYPICAL', 'Summer season elevated baseline', 'any', 'high'),
('winter_lull',          'TYPICAL', 'Winter season reduced activity', 'any', 'low'),
('anomaly_high',         'ANOMALY', 'Unexplained high traffic', 'any', 'very_high'),
('anomaly_low',          'ANOMALY', 'Unexplained low traffic', 'any', 'very_low');

-- Daily feature vectors (computed daily for each day)
CREATE TABLE daily_feature_vectors (
    feature_date        DATE PRIMARY KEY,

    -- Shape features (hourly pattern characteristics)
    peak_hour           TINYINT,
    peak_value          INT,
    trough_hour         TINYINT,
    trough_value        INT,
    daily_range         INT,                   -- peak - trough
    morning_slope       DECIMAL(6,3),          -- Rate of increase 06-12Z
    afternoon_slope     DECIMAL(6,3),          -- Rate of change 12-18Z
    evening_slope       DECIMAL(6,3),          -- Rate of decrease 18-00Z
    night_slope         DECIMAL(6,3),          -- Rate of change 00-06Z
    midday_plateau_len  TINYINT,               -- Hours within 10% of peak

    -- Volume features
    total_volume        INT,                   -- Sum of all 5-min snapshots
    mean_volume         DECIMAL(10,2),
    std_dev             DECIMAL(10,2),
    coefficient_of_var  DECIMAL(6,4),          -- std/mean (normalized volatility)

    -- Distribution features
    skewness            DECIMAL(6,3),          -- Left/right lean
    kurtosis            DECIMAL(6,3),          -- Peakedness
    p10                 INT,
    p25                 INT,
    p50                 INT,
    p75                 INT,
    p90                 INT,
    p95                 INT,
    interquartile_range INT,                   -- p75-p25

    -- Time-of-day breakdown (% of daily total)
    pct_night           DECIMAL(5,2),          -- 00-06Z
    pct_morning         DECIMAL(5,2),          -- 06-12Z
    pct_afternoon       DECIMAL(5,2),          -- 12-18Z
    pct_evening         DECIMAL(5,2),          -- 18-00Z

    -- Hourly pattern (24 values, space-separated)
    hourly_pattern      VARCHAR(200),          -- "45 42 38 35 33 32 48 72 95 98 100 97..."
    hourly_pattern_norm VARCHAR(200),          -- Normalized 0-100

    -- Autocorrelation (pattern persistence)
    lag1_autocorr       DECIMAL(5,3),          -- Correlation with 1-hour lag
    lag2_autocorr       DECIMAL(5,3),

    -- Compact feature vector for similarity calculations (JSON)
    feature_vector_json NVARCHAR(500),

    -- Context
    day_of_week         TINYINT NOT NULL,
    season_code         VARCHAR(3) NOT NULL,
    year_num            SMALLINT NOT NULL,

    created_utc         DATETIME2 DEFAULT GETUTCDATE(),

    INDEX ix_feature_volume (total_volume DESC),
    INDEX ix_feature_peak (peak_hour, peak_value DESC),
    INDEX ix_feature_dow (day_of_week, feature_date DESC)
);

-- Pattern clusters (ML-driven groupings)
CREATE TABLE pattern_clusters (
    cluster_id          INT IDENTITY PRIMARY KEY,
    cluster_name        VARCHAR(50),           -- Auto-generated or human-labeled
    cluster_description NVARCHAR(500),

    -- Centroid (average feature vector of members)
    centroid_vector     NVARCHAR(500),

    -- Cluster characteristics
    member_count        INT DEFAULT 0,
    avg_volume          INT,
    avg_peak_hour       DECIMAL(4,2),
    typical_dow_json    NVARCHAR(100),         -- {"Fri": 45, "Sat": 30, ...} distribution
    typical_season_json NVARCHAR(100),

    -- Within-cluster variance
    intra_cluster_dist  DECIMAL(8,4),

    -- Linkage to archetypes
    closest_archetype_id INT NULL,
    archetype_similarity DECIMAL(5,3),

    is_active           BIT DEFAULT 1,
    created_utc         DATETIME2 DEFAULT GETUTCDATE(),
    updated_utc         DATETIME2 NULL,

    FOREIGN KEY (closest_archetype_id) REFERENCES pattern_archetypes(archetype_id)
);

-- Daily cluster and archetype assignments
CREATE TABLE daily_pattern_assignments (
    assignment_date     DATE PRIMARY KEY,

    -- Primary cluster assignment
    cluster_id          INT NOT NULL,
    cluster_distance    DECIMAL(8,4),          -- Distance to centroid
    cluster_confidence  DECIMAL(4,3),          -- 0-1, how well it fits

    -- Primary archetype match
    archetype_id        INT NOT NULL,
    archetype_score     DECIMAL(4,3),          -- Correlation to archetype pattern

    -- Secondary matches (for "similar to X but also like Y")
    secondary_cluster_id INT NULL,
    secondary_cluster_score DECIMAL(4,3) NULL,
    secondary_archetype_id INT NULL,
    secondary_archetype_score DECIMAL(4,3) NULL,

    -- Pattern classification
    pattern_type        VARCHAR(30),           -- typical, event, holiday, anomaly
    pattern_subtype     VARCHAR(50),           -- More specific classification

    -- Anomaly detection
    is_anomaly          BIT DEFAULT 0,
    anomaly_score       DECIMAL(5,3) NULL,     -- How anomalous (0=normal, 1=very unusual)
    anomaly_factors     NVARCHAR(200) NULL,    -- JSON explaining why

    created_utc         DATETIME2 DEFAULT GETUTCDATE(),

    FOREIGN KEY (cluster_id) REFERENCES pattern_clusters(cluster_id),
    FOREIGN KEY (archetype_id) REFERENCES pattern_archetypes(archetype_id),

    INDEX ix_pattern_cluster (cluster_id, assignment_date DESC),
    INDEX ix_pattern_archetype (archetype_id, assignment_date DESC),
    INDEX ix_pattern_type (pattern_type, assignment_date DESC)
);

-- Analog similarity matrix (pre-computed)
CREATE TABLE analog_similarity_matrix (
    source_date         DATE NOT NULL,
    rank                TINYINT NOT NULL,      -- 1-50 (top 50 similar days)
    similar_date        DATE NOT NULL,

    -- Overall similarity (weighted combination)
    overall_score       DECIMAL(5,3) NOT NULL, -- 0-1 (1 = identical)

    -- Component scores
    shape_similarity    DECIMAL(4,3),          -- Hourly pattern correlation
    volume_similarity   DECIMAL(4,3),          -- Total volume match
    timing_similarity   DECIMAL(4,3),          -- Peak/trough timing match
    distribution_sim    DECIMAL(4,3),          -- Time-of-day distribution match
    context_similarity  DECIMAL(4,3),          -- DOW, season, events match

    -- What makes them similar (for explainability)
    match_factors_json  NVARCHAR(500),

    -- Filtering helpers
    same_dow            BIT,
    same_season         BIT,
    same_year           BIT,
    year_diff           SMALLINT,              -- How many years apart

    PRIMARY KEY (source_date, rank),
    INDEX ix_analog_similar (similar_date, overall_score DESC),
    INDEX ix_analog_dow (source_date, same_dow, overall_score DESC)
);

-- Weekly pattern signatures
CREATE TABLE weekly_pattern_signatures (
    year_week           VARCHAR(8) PRIMARY KEY,
    week_start_date     DATE NOT NULL,

    -- Weekly shape (7 values for daily pattern)
    daily_pattern       VARCHAR(100),          -- "low,low,med,high,high,high,med"
    daily_pattern_norm  VARCHAR(100),          -- Normalized values

    -- Pattern characteristics
    peak_day            VARCHAR(3),
    peak_day_value      INT,
    trough_day          VARCHAR(3),
    weekly_range        INT,

    -- Feature vector
    feature_vector_json NVARCHAR(300),

    -- Cluster assignment
    cluster_id          INT NULL,
    archetype_id        INT NULL,
    pattern_type        VARCHAR(30),

    -- Top similar weeks
    similar_weeks_json  NVARCHAR(500),         -- Top 20 similar weeks

    created_utc         DATETIME2 DEFAULT GETUTCDATE()
);

-- Monthly pattern signatures
CREATE TABLE monthly_pattern_signatures (
    year_month          VARCHAR(7) PRIMARY KEY,

    -- Monthly shape
    weekly_pattern      VARCHAR(100),
    daily_dow_pattern   VARCHAR(100),          -- Avg by DOW

    -- Characteristics
    busiest_week        TINYINT,
    quietest_week       TINYINT,

    -- Seasonality
    seasonal_index      DECIMAL(5,3),          -- vs annual average (1.0 = average)

    -- Similar months
    similar_months_json NVARCHAR(500),

    created_utc         DATETIME2 DEFAULT GETUTCDATE()
);

-- ============================================================================
-- SECTION 7: CONTEXTUAL EVENTS
-- ============================================================================

-- Known events calendar
CREATE TABLE context_events (
    event_id            INT IDENTITY PRIMARY KEY,
    event_date          DATE NOT NULL,
    event_type          VARCHAR(30) NOT NULL,  -- vatsim_event, holiday, real_world, weather
    event_name          NVARCHAR(100) NOT NULL,
    event_category      VARCHAR(50),           -- FNO, CTP, WorldFlight, Thanksgiving, etc.
    event_region        VARCHAR(20),           -- US, EU, APAC, global, NULL
    event_url           NVARCHAR(500) NULL,

    -- Expected impact
    expected_impact     VARCHAR(10),           -- very_low, low, medium, high, very_high
    expected_hours      VARCHAR(50),           -- "18-24" or "all_day"
    expected_volume_pct DECIMAL(5,2),          -- Expected % change vs normal

    -- Actual impact (filled after the fact)
    actual_impact       VARCHAR(10) NULL,
    actual_volume_pct   DECIMAL(5,2) NULL,
    impact_notes        NVARCHAR(500) NULL,

    -- Metadata
    source              VARCHAR(50),           -- manual, vatsim_api, imported
    is_recurring        BIT DEFAULT 0,
    recurrence_rule     VARCHAR(100) NULL,     -- RRULE format for recurring events

    created_utc         DATETIME2 DEFAULT GETUTCDATE(),

    CONSTRAINT uq_event UNIQUE (event_date, event_type, event_name),
    INDEX ix_event_date (event_date),
    INDEX ix_event_type (event_type, event_date)
);

-- Seed known recurring events
INSERT INTO context_events (event_date, event_type, event_name, event_category, event_region, expected_impact, is_recurring) VALUES
('2026-01-01', 'holiday', 'New Year''s Day', 'Holiday', 'global', 'low', 1),
('2026-07-04', 'holiday', 'Independence Day (US)', 'Holiday', 'US', 'medium', 1),
('2026-11-26', 'holiday', 'Thanksgiving (US)', 'Holiday', 'US', 'high', 1),
('2026-12-25', 'holiday', 'Christmas', 'Holiday', 'global', 'very_low', 1);

-- ============================================================================
-- SECTION 8: HISTORICAL DATA MIGRATION TABLE
-- ============================================================================

-- For migrating Running_VATSIM_Data_2 from VATSIM_Data
CREATE TABLE historical_network_stats (
    record_id           BIGINT IDENTITY PRIMARY KEY,
    file_time           DATETIME2 NOT NULL,
    pilots              INT,
    controllers         INT,

    -- Time tags (will be populated during migration)
    hour_of_day         TINYINT,
    day_of_week         TINYINT,
    month_num           TINYINT,
    year_num            SMALLINT,
    season_code         VARCHAR(3),

    -- Source tracking
    source_table        VARCHAR(50) DEFAULT 'Running_VATSIM_Data_2',
    migrated_utc        DATETIME2 DEFAULT GETUTCDATE(),

    INDEX ix_hist_time (file_time DESC),
    INDEX ix_hist_date (year_num, month_num, file_time)
);

-- ============================================================================
-- SECTION 9: VIEWS
-- ============================================================================

-- Hot data view (last 48 hours, optimized queries)
GO
CREATE VIEW vw_network_hot AS
SELECT * FROM fact_network_5min
WHERE retention_tier = 0
  AND snapshot_time > DATEADD(HOUR, -48, GETUTCDATE());
GO

-- Recent data view (last 90 days)
CREATE VIEW vw_network_recent AS
SELECT * FROM fact_network_5min
WHERE retention_tier <= 1
  AND snapshot_time > DATEADD(DAY, -90, GETUTCDATE());
GO

-- Pattern summary view
CREATE VIEW vw_daily_patterns AS
SELECT
    d.stat_date,
    d.pilots_avg,
    d.pilots_max,
    d.peak_hour_utc,
    d.day_of_week_name,
    d.is_weekend,
    d.season_code,
    d.traffic_level,
    d.is_event_day,
    d.event_name,
    p.cluster_id,
    c.cluster_name,
    p.archetype_id,
    a.archetype_name,
    p.pattern_type,
    p.is_anomaly,
    f.total_volume,
    f.coefficient_of_var AS volatility
FROM stats_network_daily d
LEFT JOIN daily_pattern_assignments p ON d.stat_date = p.assignment_date
LEFT JOIN pattern_clusters c ON p.cluster_id = c.cluster_id
LEFT JOIN pattern_archetypes a ON p.archetype_id = a.archetype_id
LEFT JOIN daily_feature_vectors f ON d.stat_date = f.feature_date;
GO

-- Analog finder view
CREATE VIEW vw_find_analogs AS
SELECT
    s.source_date,
    s.rank,
    s.similar_date,
    s.overall_score,
    s.shape_similarity,
    s.volume_similarity,
    s.same_dow,
    s.same_season,
    s.year_diff,
    sd.pilots_avg AS similar_day_pilots,
    sd.traffic_level AS similar_day_level,
    sa.archetype_name AS similar_day_archetype
FROM analog_similarity_matrix s
JOIN stats_network_daily sd ON s.similar_date = sd.stat_date
LEFT JOIN daily_pattern_assignments sp ON s.similar_date = sp.assignment_date
LEFT JOIN pattern_archetypes sa ON sp.archetype_id = sa.archetype_id;
GO

-- ============================================================================
-- SECTION 10: STORED PROCEDURES
-- ============================================================================

-- Procedure to tag a 5-min snapshot at INSERT time
GO
CREATE PROCEDURE sp_TagNetworkSnapshot
    @snapshot_time DATETIME2,
    @total_pilots INT,
    @total_controllers INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @hour_of_day TINYINT = DATEPART(HOUR, @snapshot_time);
    DECLARE @minute TINYINT = DATEPART(MINUTE, @snapshot_time);
    DECLARE @dow TINYINT = DATEPART(WEEKDAY, @snapshot_time);
    DECLARE @month TINYINT = DATEPART(MONTH, @snapshot_time);
    DECLARE @year SMALLINT = DATEPART(YEAR, @snapshot_time);

    -- Calculate time bins
    DECLARE @minute_bin_15 TINYINT = (@minute / 15) * 15;
    DECLARE @minute_bin_30 TINYINT = (@minute / 30) * 30;

    -- Time of day
    DECLARE @time_of_day VARCHAR(10) = CASE
        WHEN @hour_of_day >= 0 AND @hour_of_day < 6 THEN 'night'
        WHEN @hour_of_day >= 6 AND @hour_of_day < 12 THEN 'morning'
        WHEN @hour_of_day >= 12 AND @hour_of_day < 18 THEN 'afternoon'
        ELSE 'evening'
    END;

    -- Day of week name
    DECLARE @dow_name VARCHAR(3) = CASE @dow
        WHEN 1 THEN 'Sun' WHEN 2 THEN 'Mon' WHEN 3 THEN 'Tue'
        WHEN 4 THEN 'Wed' WHEN 5 THEN 'Thu' WHEN 6 THEN 'Fri'
        WHEN 7 THEN 'Sat'
    END;

    -- Season
    DECLARE @season_code VARCHAR(3) = CASE
        WHEN @month IN (12, 1, 2) THEN 'DJF'
        WHEN @month IN (3, 4, 5) THEN 'MAM'
        WHEN @month IN (6, 7, 8) THEN 'JJA'
        ELSE 'SON'
    END;

    -- Is weekend
    DECLARE @is_weekend BIT = CASE WHEN @dow IN (1, 7) THEN 1 ELSE 0 END;

    -- Look up baseline for traffic level tagging
    DECLARE @baseline_key VARCHAR(30) = @dow_name + '_' + CAST(@hour_of_day AS VARCHAR);
    DECLARE @p50 INT, @p75 INT, @p90 INT, @p95 INT, @avg_val DECIMAL(10,2);

    SELECT @p50 = p50, @p75 = p75, @p90 = p90, @p95 = p95, @avg_val = avg_value
    FROM traffic_baselines
    WHERE baseline_type = 'hourly_dow' AND group_key = @baseline_key;

    -- Determine traffic level
    DECLARE @traffic_level VARCHAR(10) = CASE
        WHEN @p95 IS NULL THEN NULL  -- No baseline yet
        WHEN @total_pilots >= @p95 THEN 'peak'
        WHEN @total_pilots >= @p90 THEN 'busy'
        WHEN @total_pilots >= @p50 THEN 'normal'
        WHEN @total_pilots >= @p50 * 0.5 THEN 'low'
        ELSE 'quiet'
    END;

    -- Determine vs_hour_avg
    DECLARE @vs_hour_avg VARCHAR(10) = CASE
        WHEN @avg_val IS NULL THEN NULL
        WHEN @total_pilots > @avg_val * 1.15 THEN 'above'
        WHEN @total_pilots < @avg_val * 0.85 THEN 'below'
        ELSE 'normal'
    END;

    -- Calculate percentile rank (approximate)
    DECLARE @percentile TINYINT = CASE
        WHEN @p95 IS NULL THEN NULL
        WHEN @total_pilots >= @p95 THEN 95
        WHEN @total_pilots >= @p90 THEN 90
        WHEN @total_pilots >= @p75 THEN 75
        WHEN @total_pilots >= @p50 THEN 50
        ELSE 25
    END;

    -- Generate time_id (YYYYMMDDHHMM)
    DECLARE @time_id INT = @year * 100000000 + @month * 1000000 +
                           DAY(@snapshot_time) * 10000 + @hour_of_day * 100 + @minute;

    -- Insert with tags
    INSERT INTO fact_network_5min (
        snapshot_time, time_id,
        total_pilots, total_controllers,
        pilot_controller_ratio,
        hour_of_day, minute_bin_15, minute_bin_30,
        time_of_day, day_of_week, day_of_week_name,
        is_weekend, week_of_year, month_num, season_code, year_num,
        traffic_level, percentile_rank, vs_hour_avg,
        is_above_p75, is_above_p90, is_above_p95,
        retention_tier
    )
    VALUES (
        @snapshot_time, @time_id,
        @total_pilots, @total_controllers,
        CASE WHEN @total_controllers > 0 THEN CAST(@total_pilots AS DECIMAL) / @total_controllers ELSE NULL END,
        @hour_of_day, @minute_bin_15, @minute_bin_30,
        @time_of_day, @dow, @dow_name,
        @is_weekend, DATEPART(WEEK, @snapshot_time), @month, @season_code, @year,
        @traffic_level, @percentile, @vs_hour_avg,
        CASE WHEN @total_pilots >= ISNULL(@p75, 999999) THEN 1 ELSE 0 END,
        CASE WHEN @total_pilots >= ISNULL(@p90, 999999) THEN 1 ELSE 0 END,
        CASE WHEN @total_pilots >= ISNULL(@p95, 999999) THEN 1 ELSE 0 END,
        0  -- HOT tier
    );
END;
GO

-- Procedure to generate hourly rollup
CREATE PROCEDURE sp_GenerateHourlyRollup
    @target_hour DATETIME2 = NULL
AS
BEGIN
    SET NOCOUNT ON;

    -- Default to previous hour
    IF @target_hour IS NULL
        SET @target_hour = DATEADD(HOUR, DATEDIFF(HOUR, 0, DATEADD(HOUR, -1, GETUTCDATE())), 0);

    DECLARE @hour_start DATETIME2 = DATEADD(HOUR, DATEDIFF(HOUR, 0, @target_hour), 0);
    DECLARE @hour_end DATETIME2 = DATEADD(HOUR, 1, @hour_start);

    INSERT INTO stats_network_hourly (
        bucket_hour, snapshot_count,
        pilots_min, pilots_max, pilots_avg, pilots_p50, pilots_std_dev,
        controllers_min, controllers_max, controllers_avg,
        hour_of_day, time_of_day, day_of_week, is_weekend
    )
    SELECT
        @hour_start,
        COUNT(*),
        MIN(total_pilots),
        MAX(total_pilots),
        AVG(CAST(total_pilots AS DECIMAL(10,2))),
        PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY total_pilots) OVER (),
        STDEV(total_pilots),
        MIN(total_controllers),
        MAX(total_controllers),
        AVG(CAST(total_controllers AS DECIMAL(10,2))),
        DATEPART(HOUR, @hour_start),
        CASE
            WHEN DATEPART(HOUR, @hour_start) < 6 THEN 'night'
            WHEN DATEPART(HOUR, @hour_start) < 12 THEN 'morning'
            WHEN DATEPART(HOUR, @hour_start) < 18 THEN 'afternoon'
            ELSE 'evening'
        END,
        DATEPART(WEEKDAY, @hour_start),
        CASE WHEN DATEPART(WEEKDAY, @hour_start) IN (1, 7) THEN 1 ELSE 0 END
    FROM fact_network_5min
    WHERE snapshot_time >= @hour_start AND snapshot_time < @hour_end
    GROUP BY DATEPART(HOUR, @hour_start);
END;
GO

-- Procedure to migrate tiers
CREATE PROCEDURE sp_MigrateDataTiers
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @rows_hot_to_warm INT = 0;
    DECLARE @rows_warm_to_cool INT = 0;

    -- HOT → WARM (after 2 days)
    UPDATE fact_network_5min
    SET retention_tier = 1
    WHERE retention_tier = 0
      AND snapshot_time < DATEADD(DAY, -2, GETUTCDATE());
    SET @rows_hot_to_warm = @@ROWCOUNT;

    UPDATE fact_facility_5min
    SET retention_tier = 1
    WHERE retention_tier = 0
      AND snapshot_time < DATEADD(DAY, -2, GETUTCDATE());

    -- WARM → COOL (after 90 days)
    UPDATE fact_network_5min
    SET retention_tier = 2
    WHERE retention_tier = 1
      AND snapshot_time < DATEADD(DAY, -90, GETUTCDATE());
    SET @rows_warm_to_cool = @@ROWCOUNT;

    UPDATE fact_facility_5min
    SET retention_tier = 2
    WHERE retention_tier = 1
      AND snapshot_time < DATEADD(DAY, -90, GETUTCDATE());

    -- Log the operation
    INSERT INTO stats_job_run_log (job_id, job_name, status, records_processed, execution_ms)
    VALUES (0, 'TierMigration', 'SUCCESS', @rows_hot_to_warm + @rows_warm_to_cool, 0);
END;
GO

-- Procedure to refresh traffic baselines
CREATE PROCEDURE sp_RefreshTrafficBaselines
AS
BEGIN
    SET NOCOUNT ON;

    -- Clear existing hourly_dow baselines
    DELETE FROM traffic_baselines WHERE baseline_type = 'hourly_dow';

    -- Rebuild from last 90 days of data
    INSERT INTO traffic_baselines (
        baseline_type, group_key, sample_count,
        avg_value, std_dev, min_value, max_value,
        p10, p25, p50, p75, p90, p95, p99,
        computed_from_date, computed_to_date
    )
    SELECT
        'hourly_dow',
        day_of_week_name + '_' + CAST(hour_of_day AS VARCHAR),
        COUNT(*),
        AVG(CAST(total_pilots AS DECIMAL(10,2))),
        STDEV(total_pilots),
        MIN(total_pilots),
        MAX(total_pilots),
        PERCENTILE_CONT(0.10) WITHIN GROUP (ORDER BY total_pilots) OVER (PARTITION BY day_of_week_name, hour_of_day),
        PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY total_pilots) OVER (PARTITION BY day_of_week_name, hour_of_day),
        PERCENTILE_CONT(0.50) WITHIN GROUP (ORDER BY total_pilots) OVER (PARTITION BY day_of_week_name, hour_of_day),
        PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY total_pilots) OVER (PARTITION BY day_of_week_name, hour_of_day),
        PERCENTILE_CONT(0.90) WITHIN GROUP (ORDER BY total_pilots) OVER (PARTITION BY day_of_week_name, hour_of_day),
        PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY total_pilots) OVER (PARTITION BY day_of_week_name, hour_of_day),
        PERCENTILE_CONT(0.99) WITHIN GROUP (ORDER BY total_pilots) OVER (PARTITION BY day_of_week_name, hour_of_day),
        MIN(CAST(snapshot_time AS DATE)),
        MAX(CAST(snapshot_time AS DATE))
    FROM fact_network_5min
    WHERE snapshot_time > DATEADD(DAY, -90, GETUTCDATE())
    GROUP BY day_of_week_name, hour_of_day;
END;
GO

-- ============================================================================
-- SECTION 11: JOB CONFIGURATION
-- ============================================================================

INSERT INTO stats_job_config (job_name, procedure_name, job_category, schedule_cron, schedule_utc_hour, schedule_utc_minute, priority, description) VALUES
('Snapshot_5min',       'sp_TagNetworkSnapshot',       'REALTIME', '*/5 * * * *',  NULL, NULL, 10, 'Insert tagged 5-min network snapshot'),
('Rollup_Hourly',       'sp_GenerateHourlyRollup',     'HOURLY',   '5 * * * *',    NULL, 5,    20, 'Generate hourly statistics rollup'),
('Baseline_Refresh',    'sp_RefreshTrafficBaselines',  'DAILY',    '0 2 * * *',    2,    0,    30, 'Refresh traffic baselines from 90-day data'),
('Stats_Daily',         'sp_GenerateDailyStats',       'DAILY',    '30 2 * * *',   2,    30,   40, 'Generate daily statistics'),
('Features_Daily',      'sp_GenerateFeatureVectors',   'DAILY',    '45 2 * * *',   2,    45,   50, 'Calculate daily feature vectors'),
('Archetype_Match',     'sp_MatchArchetypes',          'DAILY',    '0 3 * * *',    3,    0,    60, 'Match days to pattern archetypes'),
('Cluster_Assign',      'sp_AssignClusters',           'DAILY',    '15 3 * * *',   3,    15,   70, 'Assign days to pattern clusters'),
('Similarity_Matrix',   'sp_UpdateSimilarityMatrix',   'DAILY',    '30 3 * * *',   3,    30,   80, 'Update analog similarity matrix'),
('Tier_Migration',      'sp_MigrateDataTiers',         'DAILY',    '45 3 * * *',   3,    45,   90, 'Migrate data between retention tiers'),
('Rollup_Weekly',       'sp_GenerateWeeklyStats',      'WEEKLY',   '0 4 * * 1',    4,    0,    50, 'Generate weekly statistics (Monday)'),
('Rollup_Monthly',      'sp_GenerateMonthlyStats',     'MONTHLY',  '30 4 1 * *',   4,    30,   50, 'Generate monthly statistics (1st)'),
('Cluster_Retrain',     'sp_RetrainClusters',          'MONTHLY',  '0 5 1 * *',    5,    0,    60, 'Retrain pattern clusters (1st Sunday)');

-- ============================================================================
-- SECTION 12: POPULATE DIM_TIME (Run separately - generates ~1.5M rows)
-- ============================================================================

-- This should be run as a separate batch after table creation
-- It populates dim_time for 2020-2035 at 5-minute intervals

/*
DECLARE @start_date DATETIME2 = '2020-01-01 00:00:00';
DECLARE @end_date DATETIME2 = '2036-01-01 00:00:00';
DECLARE @current DATETIME2 = @start_date;

WHILE @current < @end_date
BEGIN
    INSERT INTO dim_time (
        time_id, full_timestamp,
        minute_of_hour, minute_bin_15, minute_bin_30,
        hour_of_day, time_of_day, time_of_day_code,
        day_of_week, day_of_week_name, day_of_month, day_of_year, is_weekend,
        week_of_year, iso_week,
        month_num, month_name, month_name_full,
        quarter_num, quarter_name,
        season, season_code,
        year_num,
        is_peak_hour, is_quiet_hour
    )
    VALUES (
        YEAR(@current) * 100000000 + MONTH(@current) * 1000000 + DAY(@current) * 10000 + DATEPART(HOUR, @current) * 100 + DATEPART(MINUTE, @current),
        @current,
        DATEPART(MINUTE, @current),
        (DATEPART(MINUTE, @current) / 15) * 15,
        (DATEPART(MINUTE, @current) / 30) * 30,
        DATEPART(HOUR, @current),
        CASE
            WHEN DATEPART(HOUR, @current) < 6 THEN 'night'
            WHEN DATEPART(HOUR, @current) < 12 THEN 'morning'
            WHEN DATEPART(HOUR, @current) < 18 THEN 'afternoon'
            ELSE 'evening'
        END,
        CASE
            WHEN DATEPART(HOUR, @current) < 6 THEN 0
            WHEN DATEPART(HOUR, @current) < 12 THEN 1
            WHEN DATEPART(HOUR, @current) < 18 THEN 2
            ELSE 3
        END,
        DATEPART(WEEKDAY, @current),
        LEFT(DATENAME(WEEKDAY, @current), 3),
        DAY(@current),
        DATEPART(DAYOFYEAR, @current),
        CASE WHEN DATEPART(WEEKDAY, @current) IN (1, 7) THEN 1 ELSE 0 END,
        DATEPART(WEEK, @current),
        DATEPART(ISO_WEEK, @current),
        MONTH(@current),
        LEFT(DATENAME(MONTH, @current), 3),
        DATENAME(MONTH, @current),
        DATEPART(QUARTER, @current),
        'Q' + CAST(DATEPART(QUARTER, @current) AS VARCHAR),
        CASE
            WHEN MONTH(@current) IN (12, 1, 2) THEN 'winter'
            WHEN MONTH(@current) IN (3, 4, 5) THEN 'spring'
            WHEN MONTH(@current) IN (6, 7, 8) THEN 'summer'
            ELSE 'fall'
        END,
        CASE
            WHEN MONTH(@current) IN (12, 1, 2) THEN 'DJF'
            WHEN MONTH(@current) IN (3, 4, 5) THEN 'MAM'
            WHEN MONTH(@current) IN (6, 7, 8) THEN 'JJA'
            ELSE 'SON'
        END,
        YEAR(@current),
        CASE WHEN DATEPART(HOUR, @current) BETWEEN 8 AND 14 THEN 1 ELSE 0 END,
        CASE WHEN DATEPART(HOUR, @current) < 6 THEN 1 ELSE 0 END
    );

    SET @current = DATEADD(MINUTE, 5, @current);
END
*/

-- ============================================================================
-- END OF SCHEMA
-- ============================================================================
