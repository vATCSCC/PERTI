-- ============================================================================
-- Migration: Create event_position_log table (TMI compliance position tracking)
-- Database: VATSIM_ADL
-- Created: 2026-02-02
--
-- Purpose:
--   Captures controller position snapshots during event logging windows.
--   Used for TMI compliance analysis to correlate ATC staffing with traffic
--   management decisions and outcomes.
--
-- Data Sources:
--   - VATSIM datafeed controllers array (cid, callsign, frequency, facility, etc.)
--   - Captured by vatsim_adl_daemon.php during active event logging windows
--
-- Related Tables:
--   - perti_events: Event that triggered the logging
--   - adl_flight_core: Flights active during the logging period
--
-- Run with admin credentials via Azure Portal Query Editor or SSMS.
-- ============================================================================

USE VATSIM_ADL;
GO

-- ============================================================================
-- 1. CREATE EVENT_POSITION_LOG TABLE
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'event_position_log')
BEGIN
    CREATE TABLE dbo.event_position_log (
        -- Primary Key
        log_id              BIGINT IDENTITY(1,1) PRIMARY KEY,

        -- Event Association
        event_id            INT NOT NULL,                   -- FK to perti_events

        -- Controller Identity
        cid                 INT NOT NULL,                   -- VATSIM CID
        callsign            VARCHAR(16) NOT NULL,           -- e.g., BOS_GND, ZBW_CTR

        -- Position Details
        facility_type       VARCHAR(8) NULL,                -- DEL, GND, TWR, APP, DEP, CTR, FSS
        facility_id         VARCHAR(16) NULL,               -- Parsed facility (BOS, ZBW, etc.)
        frequency           DECIMAL(7,3) NULL,              -- e.g., 121.900
        visual_range        INT NULL,                       -- Visibility range in nm

        -- Rating & Time
        rating              TINYINT NULL,                   -- 1=OBS, 2=S1, 3=S2, etc.
        logon_utc           DATETIME2(0) NULL,              -- When controller logged on
        atis_code           CHAR(1) NULL,                   -- Current ATIS letter if available

        -- Geolocation (for mapping staffing coverage)
        lat                 DECIMAL(10,6) NULL,
        lon                 DECIMAL(11,6) NULL,

        -- Snapshot Timing
        captured_utc        DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

        -- Text info from datafeed
        text_atis           NVARCHAR(MAX) NULL,             -- Controller remarks/ATIS text

        -- Constraints
        CONSTRAINT FK_event_position_log_event FOREIGN KEY (event_id)
            REFERENCES dbo.perti_events(event_id) ON DELETE CASCADE,

        -- Index hint for common query pattern
        INDEX IX_event_position_log_event_time (event_id, captured_utc)
    );

    PRINT 'Created event_position_log table';
END
ELSE
BEGIN
    PRINT 'Table event_position_log already exists - skipping creation';
END
GO

-- ============================================================================
-- 2. CREATE INDEXES
-- ============================================================================

-- Query by event and controller
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_event_position_log_cid')
BEGIN
    CREATE NONCLUSTERED INDEX IX_event_position_log_cid
        ON dbo.event_position_log (cid, event_id, captured_utc DESC);
    PRINT 'Created IX_event_position_log_cid index';
END
GO

-- Query by callsign pattern (for facility analysis)
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_event_position_log_callsign')
BEGIN
    CREATE NONCLUSTERED INDEX IX_event_position_log_callsign
        ON dbo.event_position_log (callsign)
        INCLUDE (event_id, facility_type, captured_utc);
    PRINT 'Created IX_event_position_log_callsign index';
END
GO

-- Time-based queries (retention cleanup)
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_event_position_log_captured')
BEGIN
    CREATE NONCLUSTERED INDEX IX_event_position_log_captured
        ON dbo.event_position_log (captured_utc);
    PRINT 'Created IX_event_position_log_captured index';
END
GO

-- Facility type grouping
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_event_position_log_facility')
BEGIN
    CREATE NONCLUSTERED INDEX IX_event_position_log_facility
        ON dbo.event_position_log (facility_type, facility_id, event_id);
    PRINT 'Created IX_event_position_log_facility index';
END
GO

-- ============================================================================
-- 3. CREATE HELPER FUNCTION: Parse facility type from callsign
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.fn_ParseFacilityType') AND type = 'FN')
    DROP FUNCTION dbo.fn_ParseFacilityType;
GO

CREATE FUNCTION dbo.fn_ParseFacilityType(@callsign VARCHAR(16))
RETURNS VARCHAR(8)
AS
BEGIN
    DECLARE @suffix VARCHAR(8);

    -- Extract suffix after last underscore
    SET @suffix = UPPER(REVERSE(LEFT(REVERSE(@callsign),
        CHARINDEX('_', REVERSE(@callsign)) - 1)));

    -- Map to facility type
    RETURN CASE
        WHEN @suffix IN ('DEL', 'CLNC') THEN 'DEL'
        WHEN @suffix = 'GND' THEN 'GND'
        WHEN @suffix = 'TWR' THEN 'TWR'
        WHEN @suffix IN ('APP', 'DEP') THEN 'APP'
        WHEN @suffix = 'CTR' THEN 'CTR'
        WHEN @suffix IN ('FSS', 'AFIS') THEN 'FSS'
        WHEN @suffix = 'TMU' THEN 'TMU'
        WHEN @suffix = 'ATIS' THEN 'ATIS'
        WHEN @suffix = 'OBS' THEN 'OBS'
        ELSE NULL
    END;
END;
GO

PRINT 'Created fn_ParseFacilityType function';
GO

-- ============================================================================
-- 4. CREATE HELPER FUNCTION: Parse facility ID from callsign
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.fn_ParseFacilityId') AND type = 'FN')
    DROP FUNCTION dbo.fn_ParseFacilityId;
GO

CREATE FUNCTION dbo.fn_ParseFacilityId(@callsign VARCHAR(16))
RETURNS VARCHAR(16)
AS
BEGIN
    -- Return everything before the first underscore
    IF CHARINDEX('_', @callsign) > 0
        RETURN UPPER(LEFT(@callsign, CHARINDEX('_', @callsign) - 1));
    RETURN @callsign;
END;
GO

PRINT 'Created fn_ParseFacilityId function';
GO

-- ============================================================================
-- 5. CREATE VIEW: Event staffing summary
-- ============================================================================

IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_event_staffing_summary')
    DROP VIEW dbo.vw_event_staffing_summary;
GO

CREATE VIEW dbo.vw_event_staffing_summary AS
SELECT
    e.event_id,
    e.event_name,
    e.event_type,
    e.start_utc,
    e.end_utc,
    COUNT(DISTINCT p.cid) AS unique_controllers,
    COUNT(DISTINCT p.callsign) AS unique_positions,
    COUNT(*) AS total_snapshots,
    MIN(p.captured_utc) AS first_snapshot,
    MAX(p.captured_utc) AS last_snapshot,
    -- Breakdown by facility type
    SUM(CASE WHEN p.facility_type = 'DEL' THEN 1 ELSE 0 END) AS del_snapshots,
    SUM(CASE WHEN p.facility_type = 'GND' THEN 1 ELSE 0 END) AS gnd_snapshots,
    SUM(CASE WHEN p.facility_type = 'TWR' THEN 1 ELSE 0 END) AS twr_snapshots,
    SUM(CASE WHEN p.facility_type = 'APP' THEN 1 ELSE 0 END) AS app_snapshots,
    SUM(CASE WHEN p.facility_type = 'CTR' THEN 1 ELSE 0 END) AS ctr_snapshots
FROM dbo.perti_events e
LEFT JOIN dbo.event_position_log p ON p.event_id = e.event_id
GROUP BY
    e.event_id, e.event_name, e.event_type, e.start_utc, e.end_utc;
GO

PRINT 'Created vw_event_staffing_summary view';
GO

-- ============================================================================
-- 6. CREATE VIEW: Position timeline for specific event
-- ============================================================================

IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_event_position_timeline')
    DROP VIEW dbo.vw_event_position_timeline;
GO

CREATE VIEW dbo.vw_event_position_timeline AS
SELECT
    p.log_id,
    p.event_id,
    e.event_name,
    p.cid,
    p.callsign,
    p.facility_type,
    p.facility_id,
    p.frequency,
    p.rating,
    p.logon_utc,
    p.captured_utc,
    -- Calculate how long controller was on at capture time
    DATEDIFF(MINUTE, p.logon_utc, p.captured_utc) AS minutes_online,
    p.lat,
    p.lon
FROM dbo.event_position_log p
JOIN dbo.perti_events e ON e.event_id = p.event_id;
GO

PRINT 'Created vw_event_position_timeline view';
GO

-- ============================================================================
-- 7. CREATE PROCEDURE: Log position snapshot
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.sp_LogEventPosition') AND type = 'P')
    DROP PROCEDURE dbo.sp_LogEventPosition;
GO

CREATE PROCEDURE dbo.sp_LogEventPosition
    @event_id       INT,
    @cid            INT,
    @callsign       VARCHAR(16),
    @frequency      DECIMAL(7,3) = NULL,
    @visual_range   INT = NULL,
    @rating         TINYINT = NULL,
    @logon_utc      DATETIME2(0) = NULL,
    @atis_code      CHAR(1) = NULL,
    @lat            DECIMAL(10,6) = NULL,
    @lon            DECIMAL(11,6) = NULL,
    @text_atis      NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    -- Parse facility info from callsign
    DECLARE @facility_type VARCHAR(8) = dbo.fn_ParseFacilityType(@callsign);
    DECLARE @facility_id VARCHAR(16) = dbo.fn_ParseFacilityId(@callsign);

    INSERT INTO dbo.event_position_log (
        event_id, cid, callsign, facility_type, facility_id,
        frequency, visual_range, rating, logon_utc, atis_code,
        lat, lon, text_atis
    )
    VALUES (
        @event_id, @cid, @callsign, @facility_type, @facility_id,
        @frequency, @visual_range, @rating, @logon_utc, @atis_code,
        @lat, @lon, @text_atis
    );

    -- Update position count on the event
    UPDATE dbo.perti_events
    SET positions_logged = positions_logged + 1
    WHERE event_id = @event_id;
END;
GO

PRINT 'Created sp_LogEventPosition procedure';
GO

-- ============================================================================
-- 8. CREATE PROCEDURE: Bulk log position snapshots (for efficiency)
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.sp_LogEventPositionsBulk') AND type = 'P')
    DROP PROCEDURE dbo.sp_LogEventPositionsBulk;
GO

CREATE PROCEDURE dbo.sp_LogEventPositionsBulk
    @event_id   INT,
    @json       NVARCHAR(MAX)  -- JSON array of controller objects from VATSIM datafeed
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @inserted INT = 0;

    -- Parse JSON and insert
    INSERT INTO dbo.event_position_log (
        event_id, cid, callsign, facility_type, facility_id,
        frequency, visual_range, rating, logon_utc, lat, lon, text_atis
    )
    SELECT
        @event_id,
        CAST(JSON_VALUE(c.value, '$.cid') AS INT),
        JSON_VALUE(c.value, '$.callsign'),
        dbo.fn_ParseFacilityType(JSON_VALUE(c.value, '$.callsign')),
        dbo.fn_ParseFacilityId(JSON_VALUE(c.value, '$.callsign')),
        TRY_CAST(JSON_VALUE(c.value, '$.frequency') AS DECIMAL(7,3)),
        TRY_CAST(JSON_VALUE(c.value, '$.visual_range') AS INT),
        TRY_CAST(JSON_VALUE(c.value, '$.rating') AS TINYINT),
        TRY_CAST(JSON_VALUE(c.value, '$.logon_time') AS DATETIME2(0)),
        TRY_CAST(JSON_VALUE(c.value, '$.latitude') AS DECIMAL(10,6)),
        TRY_CAST(JSON_VALUE(c.value, '$.longitude') AS DECIMAL(11,6)),
        JSON_VALUE(c.value, '$.text_atis')
    FROM OPENJSON(@json) c
    WHERE JSON_VALUE(c.value, '$.callsign') IS NOT NULL
      AND JSON_VALUE(c.value, '$.cid') IS NOT NULL;

    SET @inserted = @@ROWCOUNT;

    -- Update position count on the event
    UPDATE dbo.perti_events
    SET positions_logged = positions_logged + @inserted
    WHERE event_id = @event_id;

    SELECT @inserted AS positions_logged;
END;
GO

PRINT 'Created sp_LogEventPositionsBulk procedure';
GO

-- ============================================================================
-- 9. GRANT PERMISSIONS
-- ============================================================================

IF EXISTS (SELECT * FROM sys.database_principals WHERE name = 'adl_api_user')
BEGIN
    GRANT SELECT, INSERT ON dbo.event_position_log TO adl_api_user;
    GRANT SELECT ON dbo.vw_event_staffing_summary TO adl_api_user;
    GRANT SELECT ON dbo.vw_event_position_timeline TO adl_api_user;
    GRANT EXECUTE ON dbo.sp_LogEventPosition TO adl_api_user;
    GRANT EXECUTE ON dbo.sp_LogEventPositionsBulk TO adl_api_user;
    PRINT 'Granted permissions to adl_api_user';
END
GO

-- ============================================================================
-- SUMMARY
-- ============================================================================

PRINT '';
PRINT '=== Migration 004_create_event_position_log.sql Complete ===';
PRINT '';
PRINT 'Created:';
PRINT '  - event_position_log table (controller staffing snapshots)';
PRINT '  - fn_ParseFacilityType() scalar function';
PRINT '  - fn_ParseFacilityId() scalar function';
PRINT '  - vw_event_staffing_summary view';
PRINT '  - vw_event_position_timeline view';
PRINT '  - sp_LogEventPosition procedure';
PRINT '  - sp_LogEventPositionsBulk procedure';
PRINT '';
PRINT 'Integration points:';
PRINT '  1. vatsim_adl_daemon.php: Check fn_IsEventLoggingActive()';
PRINT '  2. If active, call sp_LogEventPositionsBulk with controllers JSON';
PRINT '  3. Query vw_event_staffing_summary for event analysis';
PRINT '';
GO
