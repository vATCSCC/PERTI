-- Migration: Create division_events table for VATUSA, VATCAN, and VATSIM events
-- Database: VATSIM_ADL
-- Created: 2026-01-31
--
-- Run this migration with admin credentials via Azure Portal Query Editor or SSMS.
-- The adl_api_user does not have CREATE TABLE permissions.

-- Table to store upcoming/scheduled events from division APIs
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'division_events')
BEGIN
    CREATE TABLE dbo.division_events (
        event_id            INT IDENTITY(1,1) PRIMARY KEY,

        -- Source identification
        source              NVARCHAR(16) NOT NULL,          -- 'VATUSA', 'VATCAN', 'VATSIM'
        external_id         NVARCHAR(64) NOT NULL,          -- Original ID from source API

        -- Event details
        event_name          NVARCHAR(256) NOT NULL,
        event_type          NVARCHAR(64) NULL,              -- 'Event', 'FNO', 'Controller Examination', etc.
        event_link          NVARCHAR(512) NULL,             -- URL to event page
        banner_url          NVARCHAR(512) NULL,             -- Event banner image

        -- Timing
        start_utc           DATETIME2 NOT NULL,
        end_utc             DATETIME2 NULL,

        -- Location/scope
        division            NVARCHAR(16) NULL,              -- 'USA', 'CAN', or VATSIM division code
        region              NVARCHAR(16) NULL,              -- VATSIM region (AMAS, EMEA, APAC)
        airports_json       NVARCHAR(MAX) NULL,             -- JSON array of airport ICAOs
        routes_json         NVARCHAR(MAX) NULL,             -- JSON array of routes (for VATSIM events)

        -- Description
        short_description   NVARCHAR(1024) NULL,
        description         NVARCHAR(MAX) NULL,

        -- Sync metadata
        synced_at           DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
        created_at          DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
        updated_at          DATETIME2 NULL,

        -- Unique constraint to prevent duplicates
        CONSTRAINT UQ_division_events_source_external UNIQUE (source, external_id)
    );

    -- Indexes for common queries
    CREATE INDEX IX_division_events_start ON dbo.division_events (start_utc);
    CREATE INDEX IX_division_events_source ON dbo.division_events (source);
    CREATE INDEX IX_division_events_division ON dbo.division_events (division);

    PRINT 'Created division_events table with indexes';
END
ELSE
BEGIN
    PRINT 'Table division_events already exists - skipping';
END
GO

-- View for active/upcoming events (run separately if needed)
IF NOT EXISTS (SELECT * FROM sys.views WHERE name = 'vw_division_events_upcoming')
BEGIN
    EXEC('
    CREATE VIEW dbo.vw_division_events_upcoming AS
    SELECT
        event_id,
        source,
        external_id,
        event_name,
        event_type,
        event_link,
        banner_url,
        start_utc,
        end_utc,
        division,
        region,
        airports_json,
        routes_json,
        short_description,
        synced_at
    FROM dbo.division_events
    WHERE end_utc IS NULL OR end_utc > SYSUTCDATETIME()
    ');
    PRINT 'Created vw_division_events_upcoming view';
END
GO

-- Grant permissions to API user
GRANT SELECT, INSERT, UPDATE, DELETE ON dbo.division_events TO adl_api_user;
GRANT SELECT ON dbo.vw_division_events_upcoming TO adl_api_user;
PRINT 'Granted permissions to adl_api_user';
GO
