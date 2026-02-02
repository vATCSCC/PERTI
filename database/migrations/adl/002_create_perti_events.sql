-- ============================================================================
-- Migration: Create perti_events table (unified event scheduling)
-- Database: VATSIM_ADL
-- Created: 2026-02-02
-- Replaces: division_events (data will be migrated)
--
-- Purpose:
--   Central event table for VATSIM division events that triggers enhanced
--   position logging for TMI compliance analysis. Supports multi-divisional
--   events (CTP, cross-border events, etc.)
--
-- Event Types:
--   FNO      - Friday Night Ops (VATUSA)
--   SAT      - Saturday events (VATUSA)
--   SUN      - Sunday Ops (VATUSA)
--   MWK      - Mid-Week Ops (VATUSA, Mon-Thu)
--   OMN      - Open Mic Night (VATUSA)
--   CTP      - Cross The Pond
--   CTL      - Cross The Land
--   WF       - WorldFlight
--   24HRSOV  - 24 Hour Sovereignty
--   LIVE     - Live Event
--   REALOPS  - Real Ops
--   TRAIN    - Training Event
--   REG      - Regional Event
--   SPEC     - Special Event
--   UNKN     - Unknown/Unclassified (default)
--
-- Run with admin credentials via Azure Portal Query Editor or SSMS.
-- ============================================================================

USE VATSIM_ADL;
GO

-- ============================================================================
-- 1. CREATE PERTI_EVENTS TABLE
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'perti_events')
BEGIN
    CREATE TABLE dbo.perti_events (
        -- Primary Key
        event_id            INT IDENTITY(1,1) PRIMARY KEY,

        -- Event Identification
        event_name          NVARCHAR(512) NOT NULL,
        event_type          VARCHAR(16) NOT NULL,

        -- Timing (core)
        start_utc           DATETIME2(0) NOT NULL,
        end_utc             DATETIME2(0) NOT NULL,

        -- Computed logging window (+/- 1 hour buffer)
        logging_start_utc   AS DATEADD(HOUR, -1, start_utc) PERSISTED,
        logging_end_utc     AS DATEADD(HOUR, 1, end_utc) PERSISTED,

        -- Scope (supports multi-divisional)
        divisions           NVARCHAR(256) NULL,         -- Comma-separated: 'VATUSA,VATCAN' or JSON array
        featured_airports   NVARCHAR(MAX) NULL,         -- JSON array: ["KJFK","KLAX","KATL"]

        -- External Source Tracking
        source              VARCHAR(16) NOT NULL,       -- VATUSA, VATCAN, VATSIM, MANUAL
        external_id         NVARCHAR(64) NULL,          -- Original ID from source API
        external_url        NVARCHAR(512) NULL,         -- Link to event page
        banner_url          NVARCHAR(512) NULL,         -- Event banner image

        -- Link to Post-Event Statistics
        stats_event_idx     VARCHAR(64) NULL,           -- FK to vatusa_event.event_idx (post-event)

        -- Logging Control
        logging_enabled     BIT NOT NULL DEFAULT 1,
        positions_logged    INT NOT NULL DEFAULT 0,     -- Counter for analysis

        -- Status
        status              VARCHAR(16) NOT NULL DEFAULT 'SCHEDULED',
                            -- SCHEDULED, ACTIVE, COMPLETED, CANCELLED

        -- Description
        description         NVARCHAR(MAX) NULL,

        -- Sync & Audit
        synced_utc          DATETIME2(0) NULL,
        created_utc         DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        updated_utc         DATETIME2(0) NULL,

        -- Constraints
        CONSTRAINT CK_perti_events_type CHECK (
            event_type IN ('FNO','SAT','SUN','MWK','OMN','CTP','CTL','WF',
                          '24HRSOV','LIVE','REALOPS','TRAIN','REG','SPEC','UNKN')
        ),
        CONSTRAINT CK_perti_events_status CHECK (
            status IN ('SCHEDULED','ACTIVE','COMPLETED','CANCELLED')
        ),
        CONSTRAINT CK_perti_events_source CHECK (
            source IN ('VATUSA','VATCAN','VATSIM','VATEUR','VATPAC','MANUAL')
        ),

        -- Unique constraint for external source records
        CONSTRAINT UQ_perti_events_source_external UNIQUE (source, external_id)
    );

    PRINT 'Created perti_events table';
END
ELSE
BEGIN
    PRINT 'Table perti_events already exists - skipping creation';
END
GO

-- ============================================================================
-- 2. CREATE INDEXES
-- ============================================================================

-- Upcoming events query (most common)
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_perti_events_upcoming')
BEGIN
    CREATE NONCLUSTERED INDEX IX_perti_events_upcoming
        ON dbo.perti_events (status, start_utc)
        INCLUDE (event_name, event_type, end_utc, logging_start_utc, logging_end_utc)
        WHERE status IN ('SCHEDULED', 'ACTIVE');
    PRINT 'Created IX_perti_events_upcoming index';
END
GO

-- Logging window query (for daemon to find active logging periods)
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_perti_events_logging_window')
BEGIN
    CREATE NONCLUSTERED INDEX IX_perti_events_logging_window
        ON dbo.perti_events (logging_start_utc, logging_end_utc)
        INCLUDE (event_id, logging_enabled)
        WHERE logging_enabled = 1;
    PRINT 'Created IX_perti_events_logging_window index';
END
GO

-- Event type analysis
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_perti_events_type')
BEGIN
    CREATE NONCLUSTERED INDEX IX_perti_events_type
        ON dbo.perti_events (event_type, start_utc DESC);
    PRINT 'Created IX_perti_events_type index';
END
GO

-- Source-based sync queries
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_perti_events_source')
BEGIN
    CREATE NONCLUSTERED INDEX IX_perti_events_source
        ON dbo.perti_events (source, synced_utc DESC);
    PRINT 'Created IX_perti_events_source index';
END
GO

-- Link to stats tables
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_perti_events_stats')
BEGIN
    CREATE NONCLUSTERED INDEX IX_perti_events_stats
        ON dbo.perti_events (stats_event_idx)
        WHERE stats_event_idx IS NOT NULL;
    PRINT 'Created IX_perti_events_stats index';
END
GO

-- ============================================================================
-- 3. CREATE VIEWS
-- ============================================================================

-- View: Upcoming/Active events
IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_perti_events_upcoming')
    DROP VIEW dbo.vw_perti_events_upcoming;
GO

CREATE VIEW dbo.vw_perti_events_upcoming AS
SELECT
    event_id,
    event_name,
    event_type,
    start_utc,
    end_utc,
    logging_start_utc,
    logging_end_utc,
    divisions,
    featured_airports,
    source,
    external_url,
    banner_url,
    status,
    logging_enabled
FROM dbo.perti_events
WHERE status IN ('SCHEDULED', 'ACTIVE')
  AND end_utc > SYSUTCDATETIME();
GO

PRINT 'Created vw_perti_events_upcoming view';
GO

-- View: Currently logging (for daemon)
IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_perti_events_logging')
    DROP VIEW dbo.vw_perti_events_logging;
GO

CREATE VIEW dbo.vw_perti_events_logging AS
SELECT
    event_id,
    event_name,
    event_type,
    start_utc,
    end_utc,
    logging_start_utc,
    logging_end_utc,
    featured_airports,
    positions_logged
FROM dbo.perti_events
WHERE logging_enabled = 1
  AND SYSUTCDATETIME() BETWEEN logging_start_utc AND logging_end_utc;
GO

PRINT 'Created vw_perti_events_logging view';
GO

-- ============================================================================
-- 4. MIGRATE DATA FROM DIVISION_EVENTS
-- ============================================================================

IF EXISTS (SELECT * FROM sys.tables WHERE name = 'division_events')
BEGIN
    PRINT 'Migrating data from division_events to perti_events...';

    -- Insert existing division_events records
    INSERT INTO dbo.perti_events (
        event_name,
        event_type,
        start_utc,
        end_utc,
        divisions,
        featured_airports,
        source,
        external_id,
        external_url,
        banner_url,
        description,
        synced_utc,
        created_utc,
        status
    )
    SELECT
        event_name,
        -- Map existing event_type to new enum
        CASE
            WHEN event_type LIKE '%FNO%' THEN 'FNO'
            WHEN event_type LIKE '%Friday%' THEN 'FNO'
            WHEN event_type LIKE '%SNO%' THEN 'SAT'
            WHEN event_type LIKE '%Saturday%' THEN 'SAT'
            WHEN event_type LIKE '%Sunday%' THEN 'SUN'
            WHEN event_type LIKE '%Mid%Week%' THEN 'MWK'
            WHEN event_type LIKE '%OMN%' OR event_type LIKE '%Open Mic%' THEN 'OMN'
            WHEN event_type LIKE '%CTP%' OR event_type LIKE '%Cross%Pond%' THEN 'CTP'
            WHEN event_type LIKE '%CTL%' OR event_type LIKE '%Cross%Land%' THEN 'CTL'
            WHEN event_type LIKE '%WorldFlight%' OR event_type LIKE '%WF%' THEN 'WF'
            WHEN event_type LIKE '%24%' THEN '24HRSOV'
            WHEN event_type LIKE '%Live%' THEN 'LIVE'
            WHEN event_type LIKE '%Real%Ops%' THEN 'REALOPS'
            WHEN event_type LIKE '%Train%' THEN 'TRAIN'
            WHEN event_type LIKE '%Regional%' THEN 'REG'
            ELSE 'UNKN'
        END AS event_type,
        start_utc,
        ISNULL(end_utc, DATEADD(HOUR, 4, start_utc)),  -- Default 4-hour duration if null
        -- Map division field
        CASE source
            WHEN 'VATUSA' THEN 'VATUSA'
            WHEN 'VATCAN' THEN 'VATCAN'
            WHEN 'VATSIM' THEN ISNULL(division, region)
            ELSE division
        END AS divisions,
        airports_json,
        source,
        external_id,
        event_link,
        banner_url,
        ISNULL(description, short_description),
        synced_at,
        created_at,
        CASE
            WHEN end_utc IS NOT NULL AND end_utc < SYSUTCDATETIME() THEN 'COMPLETED'
            WHEN start_utc <= SYSUTCDATETIME() AND (end_utc IS NULL OR end_utc >= SYSUTCDATETIME()) THEN 'ACTIVE'
            ELSE 'SCHEDULED'
        END AS status
    FROM dbo.division_events
    WHERE NOT EXISTS (
        SELECT 1 FROM dbo.perti_events p
        WHERE p.source = division_events.source
          AND p.external_id = division_events.external_id
    );

    PRINT 'Migration complete. Records migrated: ' + CAST(@@ROWCOUNT AS VARCHAR(10));
END
ELSE
BEGIN
    PRINT 'division_events table not found - skipping migration';
END
GO

-- ============================================================================
-- 5. GRANT PERMISSIONS
-- ============================================================================

-- Grant to API user
IF EXISTS (SELECT * FROM sys.database_principals WHERE name = 'adl_api_user')
BEGIN
    GRANT SELECT, INSERT, UPDATE, DELETE ON dbo.perti_events TO adl_api_user;
    GRANT SELECT ON dbo.vw_perti_events_upcoming TO adl_api_user;
    GRANT SELECT ON dbo.vw_perti_events_logging TO adl_api_user;
    PRINT 'Granted permissions to adl_api_user';
END
GO

-- ============================================================================
-- 6. HELPER FUNCTION: Check if currently in logging window
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.fn_IsEventLoggingActive') AND type = 'FN')
    DROP FUNCTION dbo.fn_IsEventLoggingActive;
GO

CREATE FUNCTION dbo.fn_IsEventLoggingActive(@check_utc DATETIME2 = NULL)
RETURNS BIT
AS
BEGIN
    DECLARE @result BIT = 0;
    SET @check_utc = ISNULL(@check_utc, SYSUTCDATETIME());

    IF EXISTS (
        SELECT 1 FROM dbo.perti_events
        WHERE logging_enabled = 1
          AND @check_utc BETWEEN logging_start_utc AND logging_end_utc
    )
        SET @result = 1;

    RETURN @result;
END;
GO

PRINT 'Created fn_IsEventLoggingActive function';
GO

-- ============================================================================
-- 7. HELPER FUNCTION: Get active event IDs for a timestamp
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.fn_GetActiveEventIds') AND type = 'IF')
    DROP FUNCTION dbo.fn_GetActiveEventIds;
GO

CREATE FUNCTION dbo.fn_GetActiveEventIds(@check_utc DATETIME2 = NULL)
RETURNS TABLE
AS RETURN (
    SELECT event_id, event_name, event_type, featured_airports
    FROM dbo.perti_events
    WHERE logging_enabled = 1
      AND ISNULL(@check_utc, SYSUTCDATETIME()) BETWEEN logging_start_utc AND logging_end_utc
);
GO

PRINT 'Created fn_GetActiveEventIds function';
GO

-- ============================================================================
-- SUMMARY
-- ============================================================================

PRINT '';
PRINT '=== Migration 002_create_perti_events.sql Complete ===';
PRINT '';
PRINT 'Created:';
PRINT '  - perti_events table (unified event scheduling)';
PRINT '  - vw_perti_events_upcoming view';
PRINT '  - vw_perti_events_logging view';
PRINT '  - fn_IsEventLoggingActive() function';
PRINT '  - fn_GetActiveEventIds() inline TVF';
PRINT '';
PRINT 'Next steps:';
PRINT '  1. Verify migration data from division_events';
PRINT '  2. Update sync scripts to target perti_events';
PRINT '  3. After verification, drop division_events table';
PRINT '  4. Update API endpoints to use new table/views';
GO
