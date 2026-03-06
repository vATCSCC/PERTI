-- ============================================================================
-- SWIM_API Migration 024: Controller Data Tables
-- Adds persistent controller tracking with vNAS enrichment
-- ============================================================================
-- Run on: SWIM_API (Azure SQL)
-- Date: 2026-03-06
-- Run as: jpeterson (DDL admin)
--
-- Creates:
--   - swim_controllers: Active controller state (CID = PK)
--   - swim_controller_log: Session history (connect/disconnect/position change)
--   - fn_ParseFacilityType: Ported from VATSIM_ADL
--   - fn_ParseFacilityId: Ported from VATSIM_ADL
--   - vw_swim_active_controllers: Active-only view
--   - vw_swim_facility_staffing: Facility summary view
--   - sp_Swim_UpsertControllers: MERGE + connect/disconnect detection
--   - sp_Swim_EnrichControllersVnas: vNAS enrichment (UPDATE-only)
-- ============================================================================

USE SWIM_API;
GO

PRINT '==========================================================================';
PRINT '  Migration 024: Controller Data Tables';
PRINT '  ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
GO

-- ============================================================================
-- 1. TABLE: swim_controllers
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'swim_controllers')
BEGIN
    CREATE TABLE dbo.swim_controllers (
        -- Identity (VATSIM CID as PK)
        cid                     INT NOT NULL PRIMARY KEY,
        callsign                VARCHAR(16) NOT NULL,
        frequency               DECIMAL(7,3) NULL,
        visual_range            INT NULL,
        rating                  TINYINT NULL,
        logon_utc               DATETIME2(0) NULL,
        lat                     DECIMAL(10,6) NULL,
        lon                     DECIMAL(11,6) NULL,
        text_atis               NVARCHAR(MAX) NULL,

        -- Parsed from callsign
        facility_type           VARCHAR(8) NULL,
        facility_id             VARCHAR(16) NULL,

        -- vNAS enrichment (NULL for non-vNAS controllers)
        vnas_artcc_id           VARCHAR(8) NULL,
        vnas_facility_id        VARCHAR(64) NULL,
        vnas_position_id        VARCHAR(64) NULL,
        vnas_position_name      VARCHAR(64) NULL,
        vnas_position_type      VARCHAR(16) NULL,
        vnas_radio_name         VARCHAR(32) NULL,
        vnas_role               VARCHAR(16) NULL,
        vnas_eram_sector_id     VARCHAR(16) NULL,
        vnas_stars_sector_id    VARCHAR(16) NULL,
        vnas_stars_area_id      VARCHAR(16) NULL,
        vnas_secondary_json     NVARCHAR(MAX) NULL,
        is_observer             BIT NULL DEFAULT 0,

        -- Tracking
        is_active               BIT NOT NULL DEFAULT 1,
        last_source             VARCHAR(16) NOT NULL DEFAULT 'vatsim',
        first_seen_utc          DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        last_seen_utc           DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        vnas_updated_utc        DATETIME2(0) NULL
    );

    PRINT 'Created table: swim_controllers';
END
ELSE
    PRINT 'Table swim_controllers already exists - skipping';
GO

-- ============================================================================
-- 2. INDEXES on swim_controllers (5 DTU-conscious)
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_controllers_active_facility')
    CREATE NONCLUSTERED INDEX IX_controllers_active_facility
        ON dbo.swim_controllers (is_active, facility_type, facility_id)
        INCLUDE (callsign, frequency, rating);
GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_controllers_artcc')
    CREATE NONCLUSTERED INDEX IX_controllers_artcc
        ON dbo.swim_controllers (vnas_artcc_id)
        WHERE vnas_artcc_id IS NOT NULL AND is_active = 1;
GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_controllers_last_seen')
    CREATE NONCLUSTERED INDEX IX_controllers_last_seen
        ON dbo.swim_controllers (last_seen_utc)
        WHERE is_active = 1;
GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_controllers_callsign')
    CREATE NONCLUSTERED INDEX IX_controllers_callsign
        ON dbo.swim_controllers (callsign)
        WHERE is_active = 1;
GO

PRINT 'Created indexes on swim_controllers';
GO

-- ============================================================================
-- 3. TABLE: swim_controller_log (session history)
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'swim_controller_log')
BEGIN
    CREATE TABLE dbo.swim_controller_log (
        log_id                  BIGINT IDENTITY(1,1) PRIMARY KEY,
        cid                     INT NOT NULL,
        callsign                VARCHAR(16) NOT NULL,
        facility_type           VARCHAR(8) NULL,
        facility_id             VARCHAR(16) NULL,
        event_type              VARCHAR(16) NOT NULL,
        event_utc               DATETIME2(0) NOT NULL,
        logon_utc               DATETIME2(0) NULL,
        session_minutes         INT NULL
    );

    PRINT 'Created table: swim_controller_log';
END
ELSE
    PRINT 'Table swim_controller_log already exists - skipping';
GO

-- ============================================================================
-- 4. INDEXES on swim_controller_log
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_controller_log_cid_event')
    CREATE NONCLUSTERED INDEX IX_controller_log_cid_event
        ON dbo.swim_controller_log (cid, event_utc DESC);
GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_controller_log_event_utc')
    CREATE NONCLUSTERED INDEX IX_controller_log_event_utc
        ON dbo.swim_controller_log (event_utc DESC)
        INCLUDE (cid, callsign, event_type);
GO

PRINT 'Created indexes on swim_controller_log';
GO

-- ============================================================================
-- 5. HELPER FUNCTION: fn_ParseFacilityType (ported from VATSIM_ADL)
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.fn_ParseFacilityType') AND type = 'FN')
    DROP FUNCTION dbo.fn_ParseFacilityType;
GO

CREATE FUNCTION dbo.fn_ParseFacilityType(@callsign VARCHAR(16))
RETURNS VARCHAR(8)
AS
BEGIN
    DECLARE @suffix VARCHAR(8);

    IF CHARINDEX('_', @callsign) = 0
        RETURN NULL;

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

PRINT 'Created fn_ParseFacilityType (ported from VATSIM_ADL)';
GO

-- ============================================================================
-- 6. HELPER FUNCTION: fn_ParseFacilityId (ported from VATSIM_ADL)
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.fn_ParseFacilityId') AND type = 'FN')
    DROP FUNCTION dbo.fn_ParseFacilityId;
GO

CREATE FUNCTION dbo.fn_ParseFacilityId(@callsign VARCHAR(16))
RETURNS VARCHAR(16)
AS
BEGIN
    IF CHARINDEX('_', @callsign) > 0
        RETURN UPPER(LEFT(@callsign, CHARINDEX('_', @callsign) - 1));
    RETURN @callsign;
END;
GO

PRINT 'Created fn_ParseFacilityId (ported from VATSIM_ADL)';
GO

-- ============================================================================
-- 7. VIEW: vw_swim_active_controllers
-- ============================================================================

IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_swim_active_controllers')
    DROP VIEW dbo.vw_swim_active_controllers;
GO

CREATE VIEW dbo.vw_swim_active_controllers AS
SELECT
    cid, callsign, frequency, visual_range, rating,
    logon_utc, lat, lon, text_atis,
    facility_type, facility_id,
    vnas_artcc_id, vnas_facility_id, vnas_position_id,
    vnas_position_name, vnas_position_type, vnas_radio_name,
    vnas_role, vnas_eram_sector_id, vnas_stars_sector_id,
    vnas_stars_area_id, vnas_secondary_json, is_observer,
    last_source, first_seen_utc, last_seen_utc, vnas_updated_utc
FROM dbo.swim_controllers
WHERE is_active = 1;
GO

PRINT 'Created view: vw_swim_active_controllers';
GO

-- ============================================================================
-- 8. VIEW: vw_swim_facility_staffing
-- ============================================================================

IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_swim_facility_staffing')
    DROP VIEW dbo.vw_swim_facility_staffing;
GO

CREATE VIEW dbo.vw_swim_facility_staffing AS
SELECT
    COALESCE(facility_id, 'UNKNOWN') AS facility,
    COALESCE(facility_type, 'UNK') AS facility_type,
    COUNT(*) AS controller_count,
    SUM(CASE WHEN is_observer = 0 OR is_observer IS NULL THEN 1 ELSE 0 END) AS active_controllers,
    SUM(CASE WHEN is_observer = 1 THEN 1 ELSE 0 END) AS observers,
    SUM(CASE WHEN vnas_artcc_id IS NOT NULL THEN 1 ELSE 0 END) AS vnas_enriched
FROM dbo.swim_controllers
WHERE is_active = 1
GROUP BY facility_id, facility_type;
GO

PRINT 'Created view: vw_swim_facility_staffing';
GO

-- ============================================================================
-- 9. SP: sp_Swim_UpsertControllers
--    MERGE from JSON batch, detect connect/disconnect, staleness cleanup
-- ============================================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_Swim_UpsertControllers')
    DROP PROCEDURE dbo.sp_Swim_UpsertControllers;
GO

CREATE PROCEDURE dbo.sp_Swim_UpsertControllers
    @Json       NVARCHAR(MAX),
    @Source     VARCHAR(16) = 'vatsim',
    @Inserted   INT OUTPUT,
    @Updated    INT OUTPUT,
    @Disconnected INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @now DATETIME2(0) = SYSUTCDATETIME();
    SET @Inserted = 0;
    SET @Updated = 0;
    SET @Disconnected = 0;

    -- Parse JSON into temp table
    SELECT
        CAST(j.cid AS INT) AS cid,
        CAST(j.callsign AS VARCHAR(16)) AS callsign,
        CAST(j.frequency AS DECIMAL(7,3)) AS frequency,
        CAST(j.visual_range AS INT) AS visual_range,
        CAST(j.rating AS TINYINT) AS rating,
        CAST(j.logon_utc AS DATETIME2(0)) AS logon_utc,
        CAST(j.lat AS DECIMAL(10,6)) AS lat,
        CAST(j.lon AS DECIMAL(11,6)) AS lon,
        CAST(j.text_atis AS NVARCHAR(MAX)) AS text_atis,
        CAST(j.is_observer AS BIT) AS is_observer
    INTO #batch
    FROM OPENJSON(@Json) WITH (
        cid         INT,
        callsign    VARCHAR(16),
        frequency   DECIMAL(7,3),
        visual_range INT,
        rating      TINYINT,
        logon_utc   DATETIME2(0),
        lat         DECIMAL(10,6),
        lon         DECIMAL(11,6),
        text_atis   NVARCHAR(MAX),
        is_observer BIT
    ) j
    WHERE j.cid IS NOT NULL AND j.callsign IS NOT NULL;

    -- MERGE: upsert controllers
    MERGE dbo.swim_controllers AS tgt
    USING #batch AS src ON tgt.cid = src.cid
    WHEN MATCHED THEN
        UPDATE SET
            tgt.callsign = src.callsign,
            tgt.frequency = src.frequency,
            tgt.visual_range = src.visual_range,
            tgt.rating = src.rating,
            tgt.logon_utc = src.logon_utc,
            tgt.lat = src.lat,
            tgt.lon = src.lon,
            tgt.text_atis = src.text_atis,
            tgt.is_observer = src.is_observer,
            tgt.facility_type = dbo.fn_ParseFacilityType(src.callsign),
            tgt.facility_id = dbo.fn_ParseFacilityId(src.callsign),
            tgt.is_active = 1,
            tgt.last_source = @Source,
            tgt.last_seen_utc = @now
    WHEN NOT MATCHED BY TARGET THEN
        INSERT (cid, callsign, frequency, visual_range, rating, logon_utc,
                lat, lon, text_atis, is_observer,
                facility_type, facility_id,
                is_active, last_source, first_seen_utc, last_seen_utc)
        VALUES (src.cid, src.callsign, src.frequency, src.visual_range,
                src.rating, src.logon_utc, src.lat, src.lon, src.text_atis,
                src.is_observer,
                dbo.fn_ParseFacilityType(src.callsign),
                dbo.fn_ParseFacilityId(src.callsign),
                1, @Source, @now, @now);

    -- Count inserted and updated from MERGE
    SET @Updated = @@ROWCOUNT;

    -- Count new inserts (controllers that didn't exist before)
    -- Use log table: new CIDs not yet logged as CONNECTED
    INSERT INTO dbo.swim_controller_log
        (cid, callsign, facility_type, facility_id, event_type, event_utc, logon_utc)
    SELECT
        b.cid, b.callsign,
        dbo.fn_ParseFacilityType(b.callsign),
        dbo.fn_ParseFacilityId(b.callsign),
        'CONNECTED', @now, b.logon_utc
    FROM #batch b
    WHERE NOT EXISTS (
        SELECT 1 FROM dbo.swim_controller_log l
        WHERE l.cid = b.cid
          AND l.event_type = 'CONNECTED'
          AND l.logon_utc = b.logon_utc
    )
    AND EXISTS (
        SELECT 1 FROM dbo.swim_controllers c
        WHERE c.cid = b.cid AND c.first_seen_utc = @now
    );

    SET @Inserted = @@ROWCOUNT;
    SET @Updated = @Updated - @Inserted;

    -- Staleness cleanup: mark controllers not seen for 90s as inactive
    -- Also log position changes (callsign changed = new position)
    INSERT INTO dbo.swim_controller_log
        (cid, callsign, facility_type, facility_id, event_type, event_utc,
         logon_utc, session_minutes)
    SELECT
        c.cid, c.callsign, c.facility_type, c.facility_id,
        'DISCONNECTED', @now, c.logon_utc,
        DATEDIFF(MINUTE, COALESCE(c.logon_utc, c.first_seen_utc), @now)
    FROM dbo.swim_controllers c
    WHERE c.is_active = 1
      AND c.last_seen_utc < DATEADD(SECOND, -90, @now)
      AND NOT EXISTS (SELECT 1 FROM #batch b WHERE b.cid = c.cid);

    SET @Disconnected = @@ROWCOUNT;

    UPDATE dbo.swim_controllers
    SET is_active = 0
    WHERE is_active = 1
      AND last_seen_utc < DATEADD(SECOND, -90, @now)
      AND cid NOT IN (SELECT cid FROM #batch);

    DROP TABLE #batch;
END;
GO

PRINT 'Created sp_Swim_UpsertControllers';
GO

-- ============================================================================
-- 10. SP: sp_Swim_EnrichControllersVnas
--     UPDATE-only — enriches existing rows matched by CID
-- ============================================================================

IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_Swim_EnrichControllersVnas')
    DROP PROCEDURE dbo.sp_Swim_EnrichControllersVnas;
GO

CREATE PROCEDURE dbo.sp_Swim_EnrichControllersVnas
    @Json       NVARCHAR(MAX),
    @Enriched   INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @now DATETIME2(0) = SYSUTCDATETIME();
    SET @Enriched = 0;

    -- Parse vNAS enrichment JSON
    SELECT
        CAST(j.cid AS INT) AS cid,
        CAST(j.artcc_id AS VARCHAR(8)) AS artcc_id,
        CAST(j.facility_id AS VARCHAR(64)) AS facility_id,
        CAST(j.position_id AS VARCHAR(64)) AS position_id,
        CAST(j.position_name AS VARCHAR(64)) AS position_name,
        CAST(j.position_type AS VARCHAR(16)) AS position_type,
        CAST(j.radio_name AS VARCHAR(32)) AS radio_name,
        CAST(j.role AS VARCHAR(16)) AS role,
        CAST(j.eram_sector_id AS VARCHAR(16)) AS eram_sector_id,
        CAST(j.stars_sector_id AS VARCHAR(16)) AS stars_sector_id,
        CAST(j.stars_area_id AS VARCHAR(16)) AS stars_area_id,
        CAST(j.secondary_json AS NVARCHAR(MAX)) AS secondary_json,
        CAST(j.is_observer AS BIT) AS is_observer
    INTO #vnas
    FROM OPENJSON(@Json) WITH (
        cid             INT,
        artcc_id        VARCHAR(8),
        facility_id     VARCHAR(64),
        position_id     VARCHAR(64),
        position_name   VARCHAR(64),
        position_type   VARCHAR(16),
        radio_name      VARCHAR(32),
        role            VARCHAR(16),
        eram_sector_id  VARCHAR(16),
        stars_sector_id VARCHAR(16),
        stars_area_id   VARCHAR(16),
        secondary_json  NVARCHAR(MAX),
        is_observer     BIT
    ) j
    WHERE j.cid IS NOT NULL;

    -- UPDATE-only: never INSERT, only enrich existing active rows
    UPDATE c SET
        c.vnas_artcc_id = v.artcc_id,
        c.vnas_facility_id = v.facility_id,
        c.vnas_position_id = v.position_id,
        c.vnas_position_name = v.position_name,
        c.vnas_position_type = v.position_type,
        c.vnas_radio_name = v.radio_name,
        c.vnas_role = v.role,
        c.vnas_eram_sector_id = v.eram_sector_id,
        c.vnas_stars_sector_id = v.stars_sector_id,
        c.vnas_stars_area_id = v.stars_area_id,
        c.vnas_secondary_json = v.secondary_json,
        c.is_observer = COALESCE(v.is_observer, c.is_observer),
        c.vnas_updated_utc = @now,
        c.last_source = 'vnas'
    FROM dbo.swim_controllers c
    INNER JOIN #vnas v ON c.cid = v.cid
    WHERE c.is_active = 1;

    SET @Enriched = @@ROWCOUNT;

    DROP TABLE #vnas;
END;
GO

PRINT 'Created sp_Swim_EnrichControllersVnas';
GO

-- ============================================================================
-- Done
-- ============================================================================

PRINT '==========================================================================';
PRINT '  Migration 024 complete.';
PRINT '  Tables: swim_controllers, swim_controller_log';
PRINT '  Views: vw_swim_active_controllers, vw_swim_facility_staffing';
PRINT '  Functions: fn_ParseFacilityType, fn_ParseFacilityId';
PRINT '  SPs: sp_Swim_UpsertControllers, sp_Swim_EnrichControllersVnas';
PRINT '  ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
GO
