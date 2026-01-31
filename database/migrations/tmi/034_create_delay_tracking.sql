-- =============================================================================
-- TMI Delay Tracking Tables
-- Database: VATSIM_TMI
-- Version: 1.0
-- Date: 2026-01-31
-- =============================================================================
--
-- TABLES:
--   1. tmi_delay_entries   - D/D, E/D, A/D delay tracking entries
--   2. tmi_airport_configs - Airport configuration updates (VMC/IMC, runways, rates)
--
-- =============================================================================

-- =============================================================================
-- TABLE 1: tmi_delay_entries
-- =============================================================================
-- Tracks D/D (Departure Delays), E/D (En Route Delays), A/D (Arrival Delays)
--
-- Delay tracking methodology:
--   - Tracked in 15-minute increments
--   - Logging starts at 15+ min delays (or +Holding for E/D/A/D)
--   - Logging ends when delays drop below 15 min (or -Holding)
--   - Trend can be: increasing, decreasing, or steady
-- =============================================================================

IF OBJECT_ID('dbo.tmi_delay_entries', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.tmi_delay_entries (
        delay_id                INT IDENTITY(1,1) PRIMARY KEY,
        delay_guid              UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),

        -- Classification
        delay_type              NVARCHAR(4) NOT NULL,      -- 'D/D', 'E/D', 'A/D'
        airport                 NVARCHAR(4) NOT NULL,      -- Affected airport (BOS, LGA, DCA)
        facility                NVARCHAR(8) NULL,          -- Reporting facility (ZBW, N90)

        -- Timing
        timestamp_utc           DATETIME2(0) NOT NULL,     -- When this update was posted
        delay_start_utc         DATETIME2(0) NULL,         -- When delays started

        -- Delay Amount (15-min increments)
        delay_minutes           SMALLINT NOT NULL DEFAULT 0,
        delay_trend             NVARCHAR(16) NULL,         -- 'increasing', 'decreasing', 'steady'

        -- Holding Info (E/D and A/D primarily)
        holding_status          NVARCHAR(16) NULL,         -- '+Holding', '-Holding', or NULL
        holding_fix             NVARCHAR(8) NULL,          -- Fix for holding pattern (AJJAY)
        aircraft_holding        SMALLINT NULL DEFAULT 0,   -- Number currently holding

        -- Context
        reason                  NVARCHAR(64) NULL,         -- VOLUME, WEATHER, etc.
        raw_line                NVARCHAR(500) NULL,        -- Original NTML line

        -- Linkage
        event_id                INT NULL,                  -- FK to division event if applicable
        program_id              INT NULL,                  -- FK to tmi_programs if related

        -- Source Tracking
        source_type             NVARCHAR(16) NULL,         -- 'DISCORD', 'MANUAL', 'IMPORT'
        source_id               NVARCHAR(100) NULL,        -- Discord message ID, etc.
        discord_channel_id      NVARCHAR(64) NULL,

        -- Audit
        created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        created_by              NVARCHAR(64) NULL,

        CONSTRAINT UQ_delay_entries_guid UNIQUE (delay_guid),
        CONSTRAINT CK_delay_type CHECK (delay_type IN ('D/D', 'E/D', 'A/D')),
        CONSTRAINT CK_delay_trend CHECK (delay_trend IS NULL OR delay_trend IN ('increasing', 'decreasing', 'steady', 'unknown')),
        CONSTRAINT CK_holding_status CHECK (holding_status IS NULL OR holding_status IN ('+Holding', '-Holding'))
    );

    -- Indexes
    CREATE NONCLUSTERED INDEX IX_delay_entries_type_airport ON dbo.tmi_delay_entries (delay_type, airport, timestamp_utc DESC);
    CREATE NONCLUSTERED INDEX IX_delay_entries_timestamp ON dbo.tmi_delay_entries (timestamp_utc DESC);
    CREATE NONCLUSTERED INDEX IX_delay_entries_airport ON dbo.tmi_delay_entries (airport, timestamp_utc DESC);
    CREATE NONCLUSTERED INDEX IX_delay_entries_event ON dbo.tmi_delay_entries (event_id) WHERE event_id IS NOT NULL;
    CREATE NONCLUSTERED INDEX IX_delay_entries_holding ON dbo.tmi_delay_entries (holding_status) WHERE holding_status IS NOT NULL;

    PRINT 'Created table: tmi_delay_entries';
END
ELSE
BEGIN
    PRINT 'Table tmi_delay_entries already exists';
END
GO

-- =============================================================================
-- TABLE 2: tmi_airport_configs
-- =============================================================================
-- Tracks airport configuration updates (runways, conditions, rates)
-- Example: "30/2328    BOS    VMC    ARR:27/32 DEP:33L    AAR:40 ADR:40"
-- =============================================================================

IF OBJECT_ID('dbo.tmi_airport_configs', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.tmi_airport_configs (
        config_id               INT IDENTITY(1,1) PRIMARY KEY,
        config_guid             UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),

        -- Airport
        airport                 NVARCHAR(4) NOT NULL,      -- Airport code (BOS, LGA, DCA)
        timestamp_utc           DATETIME2(0) NOT NULL,     -- When config was posted

        -- Weather/Visibility
        conditions              NVARCHAR(4) NULL,          -- 'VMC' or 'IMC'

        -- Runway Configuration (stored as JSON arrays)
        arrival_runways         NVARCHAR(100) NULL,        -- JSON: ["27", "32"]
        departure_runways       NVARCHAR(100) NULL,        -- JSON: ["33L"]

        -- Rates
        aar                     SMALLINT NULL,             -- Arrival Acceptance Rate
        adr                     SMALLINT NULL,             -- Airport Departure Rate

        -- Raw data
        raw_line                NVARCHAR(500) NULL,

        -- Linkage
        event_id                INT NULL,                  -- FK to division event if applicable

        -- Source Tracking
        source_type             NVARCHAR(16) NULL,
        source_id               NVARCHAR(100) NULL,
        discord_channel_id      NVARCHAR(64) NULL,

        -- Audit
        created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        created_by              NVARCHAR(64) NULL,

        CONSTRAINT UQ_airport_configs_guid UNIQUE (config_guid),
        CONSTRAINT CK_conditions CHECK (conditions IS NULL OR conditions IN ('VMC', 'IMC'))
    );

    -- Indexes
    CREATE NONCLUSTERED INDEX IX_airport_configs_airport ON dbo.tmi_airport_configs (airport, timestamp_utc DESC);
    CREATE NONCLUSTERED INDEX IX_airport_configs_timestamp ON dbo.tmi_airport_configs (timestamp_utc DESC);
    CREATE NONCLUSTERED INDEX IX_airport_configs_event ON dbo.tmi_airport_configs (event_id) WHERE event_id IS NOT NULL;

    PRINT 'Created table: tmi_airport_configs';
END
ELSE
BEGIN
    PRINT 'Table tmi_airport_configs already exists';
END
GO

-- =============================================================================
-- VIEW: Active delays (most recent per airport/type)
-- =============================================================================

IF OBJECT_ID('dbo.vw_tmi_current_delays', 'V') IS NOT NULL DROP VIEW dbo.vw_tmi_current_delays;
GO

CREATE VIEW dbo.vw_tmi_current_delays AS
WITH RankedDelays AS (
    SELECT *,
        ROW_NUMBER() OVER (PARTITION BY airport, delay_type ORDER BY timestamp_utc DESC) AS rn
    FROM dbo.tmi_delay_entries
    WHERE timestamp_utc > DATEADD(HOUR, -6, SYSUTCDATETIME())  -- Only last 6 hours
)
SELECT delay_id, delay_guid, delay_type, airport, facility, timestamp_utc, delay_start_utc,
       delay_minutes, delay_trend, holding_status, holding_fix, aircraft_holding, reason
FROM RankedDelays
WHERE rn = 1
  AND (delay_minutes > 0 OR holding_status = '+Holding');  -- Only active delays
GO

PRINT 'Created view: vw_tmi_current_delays';
GO

-- =============================================================================
-- VIEW: Latest airport configs
-- =============================================================================

IF OBJECT_ID('dbo.vw_tmi_current_airport_configs', 'V') IS NOT NULL DROP VIEW dbo.vw_tmi_current_airport_configs;
GO

CREATE VIEW dbo.vw_tmi_current_airport_configs AS
WITH RankedConfigs AS (
    SELECT *,
        ROW_NUMBER() OVER (PARTITION BY airport ORDER BY timestamp_utc DESC) AS rn
    FROM dbo.tmi_airport_configs
    WHERE timestamp_utc > DATEADD(HOUR, -6, SYSUTCDATETIME())  -- Only last 6 hours
)
SELECT config_id, config_guid, airport, timestamp_utc, conditions,
       arrival_runways, departure_runways, aar, adr
FROM RankedConfigs
WHERE rn = 1;
GO

PRINT 'Created view: vw_tmi_current_airport_configs';
GO

-- =============================================================================
-- PROCEDURE: Get delay trend for an airport
-- =============================================================================

IF OBJECT_ID('dbo.sp_GetDelayTrend', 'P') IS NOT NULL DROP PROCEDURE dbo.sp_GetDelayTrend;
GO

CREATE PROCEDURE dbo.sp_GetDelayTrend
    @airport NVARCHAR(4),
    @delay_type NVARCHAR(4) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    -- Get recent delay entries for this airport (last 4 hours)
    SELECT
        delay_type,
        airport,
        timestamp_utc,
        delay_minutes,
        delay_trend,
        holding_status,
        aircraft_holding,
        LAG(delay_minutes) OVER (PARTITION BY delay_type ORDER BY timestamp_utc) AS prev_delay_minutes
    FROM dbo.tmi_delay_entries
    WHERE airport = @airport
      AND (@delay_type IS NULL OR delay_type = @delay_type)
      AND timestamp_utc > DATEADD(HOUR, -4, SYSUTCDATETIME())
    ORDER BY delay_type, timestamp_utc DESC;
END
GO

PRINT 'Created procedure: sp_GetDelayTrend';
GO

-- =============================================================================
-- VERIFICATION
-- =============================================================================

SELECT 'Delay Tracking Tables' AS migration, name
FROM sys.tables
WHERE name IN ('tmi_delay_entries', 'tmi_airport_configs')
ORDER BY name;

SELECT 'Delay Tracking Views' AS migration, name
FROM sys.views
WHERE name IN ('vw_tmi_current_delays', 'vw_tmi_current_airport_configs')
ORDER BY name;

PRINT '';
PRINT '=============================================================================';
PRINT 'TMI Delay Tracking migration complete!';
PRINT '=============================================================================';
GO
