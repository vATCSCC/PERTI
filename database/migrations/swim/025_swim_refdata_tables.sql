-- ============================================================================
-- Migration 025: SWIM API Reference Data Tables (CDR + Playbook)
--
-- Mirrors CDR and playbook data from internal databases (VATSIM_REF, MySQL)
-- into SWIM_API so external SWIM endpoints serve from isolated data.
--
-- Target: SWIM_API (Azure SQL Basic)
-- Run as: jpeterson (DDL admin)
-- Date: 2026-03-14
-- ============================================================================

USE SWIM_API;
GO

PRINT '==========================================================================';
PRINT '  Migration 025: Reference Data Tables (CDR + Playbook)';
PRINT '  ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
GO

-- ============================================================================
-- 1. swim_coded_departure_routes
--    Mirrors VATSIM_REF.dbo.coded_departure_routes (~41K rows)
--    Synced daily by refdata_sync_daemon Phase 3
-- ============================================================================

IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'swim_coded_departure_routes')
BEGIN
    CREATE TABLE dbo.swim_coded_departure_routes (
        cdr_id          INT IDENTITY(1,1) PRIMARY KEY,
        cdr_code        NVARCHAR(50) NOT NULL,
        full_route      NVARCHAR(MAX) NOT NULL,
        origin_icao     NVARCHAR(4) NULL,
        dest_icao       NVARCHAR(4) NULL,
        dep_artcc       NVARCHAR(4) NULL,
        arr_artcc       NVARCHAR(4) NULL,
        direction       NVARCHAR(10) NULL,
        altitude_min_ft INT NULL,
        altitude_max_ft INT NULL,
        is_active       BIT NOT NULL DEFAULT 1,
        source          NVARCHAR(50) NULL,
        last_sync_utc   DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME()
    );
    PRINT 'Created table: swim_coded_departure_routes';
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_swim_cdr_code')
    CREATE NONCLUSTERED INDEX IX_swim_cdr_code
        ON dbo.swim_coded_departure_routes (cdr_code);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_swim_cdr_origin_dest')
    CREATE NONCLUSTERED INDEX IX_swim_cdr_origin_dest
        ON dbo.swim_coded_departure_routes (origin_icao, dest_icao);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_swim_cdr_dep_artcc')
    CREATE NONCLUSTERED INDEX IX_swim_cdr_dep_artcc
        ON dbo.swim_coded_departure_routes (dep_artcc)
        WHERE dep_artcc IS NOT NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_swim_cdr_arr_artcc')
    CREATE NONCLUSTERED INDEX IX_swim_cdr_arr_artcc
        ON dbo.swim_coded_departure_routes (arr_artcc)
        WHERE arr_artcc IS NOT NULL;
GO

-- ============================================================================
-- 2. swim_playbook_plays
--    Mirrors MySQL perti_site.playbook_plays (~1,800 rows)
--    play_id preserves MySQL auto-increment (consumers reference by ID)
-- ============================================================================

IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'swim_playbook_plays')
BEGIN
    CREATE TABLE dbo.swim_playbook_plays (
        play_id             INT NOT NULL PRIMARY KEY,
        play_name           NVARCHAR(100) NOT NULL,
        play_name_norm      NVARCHAR(100) NOT NULL,
        display_name        NVARCHAR(200) NULL,
        description         NVARCHAR(MAX) NULL,
        category            NVARCHAR(50) NULL,
        impacted_area       NVARCHAR(2000) NULL,
        facilities_involved NVARCHAR(2000) NULL,
        scenario_type       NVARCHAR(50) NULL,
        route_format        NVARCHAR(10) NOT NULL DEFAULT 'standard',
        source              NVARCHAR(20) NOT NULL DEFAULT 'DCC',
        status              NVARCHAR(10) NOT NULL DEFAULT 'active',
        visibility          NVARCHAR(20) NOT NULL DEFAULT 'public',
        airac_cycle         NVARCHAR(10) NULL,
        route_count         INT NOT NULL DEFAULT 0,
        org_code            NVARCHAR(20) NULL,
        ctp_scope           NVARCHAR(10) NULL,
        ctp_session_id      INT NULL,
        created_by          NVARCHAR(20) NULL,
        updated_by          NVARCHAR(20) NULL,
        created_at          DATETIME2(0) NULL,
        updated_at          DATETIME2(0) NULL,
        last_sync_utc       DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME()
    );
    PRINT 'Created table: swim_playbook_plays';
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_swim_pb_plays_category')
    CREATE NONCLUSTERED INDEX IX_swim_pb_plays_category
        ON dbo.swim_playbook_plays (category)
        WHERE category IS NOT NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_swim_pb_plays_source_name')
    CREATE NONCLUSTERED INDEX IX_swim_pb_plays_source_name
        ON dbo.swim_playbook_plays (source, play_name);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_swim_pb_plays_status')
    CREATE NONCLUSTERED INDEX IX_swim_pb_plays_status
        ON dbo.swim_playbook_plays (status)
        INCLUDE (visibility, source, play_name);
GO

-- ============================================================================
-- 3. swim_playbook_routes
--    Mirrors MySQL perti_site.playbook_routes (~56K rows)
--    route_id preserves MySQL auto-increment
--    No FK to swim_playbook_plays (DELETE+INSERT sync would require FK toggling)
-- ============================================================================

IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'swim_playbook_routes')
BEGIN
    CREATE TABLE dbo.swim_playbook_routes (
        route_id                    INT NOT NULL PRIMARY KEY,
        play_id                     INT NOT NULL,
        route_string                NVARCHAR(MAX) NOT NULL,
        origin                      NVARCHAR(200) NULL,
        origin_filter               NVARCHAR(200) NULL,
        dest                        NVARCHAR(200) NULL,
        dest_filter                 NVARCHAR(200) NULL,
        origin_airports             NVARCHAR(500) NULL,
        origin_tracons              NVARCHAR(200) NULL,
        origin_artccs               NVARCHAR(200) NULL,
        dest_airports               NVARCHAR(500) NULL,
        dest_tracons                NVARCHAR(200) NULL,
        dest_artccs                 NVARCHAR(200) NULL,
        traversed_artccs            NVARCHAR(MAX) NULL,
        traversed_tracons           NVARCHAR(MAX) NULL,
        traversed_sectors_low       NVARCHAR(MAX) NULL,
        traversed_sectors_high      NVARCHAR(MAX) NULL,
        traversed_sectors_superhigh NVARCHAR(MAX) NULL,
        remarks                     NVARCHAR(MAX) NULL,
        sort_order                  INT NOT NULL DEFAULT 0,
        last_sync_utc               DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME()
    );
    PRINT 'Created table: swim_playbook_routes';
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_swim_pb_routes_play')
    CREATE NONCLUSTERED INDEX IX_swim_pb_routes_play
        ON dbo.swim_playbook_routes (play_id, sort_order)
        INCLUDE (route_id, route_string, origin, dest);
GO

-- ============================================================================
-- 4. Monitoring view
-- ============================================================================

IF EXISTS (SELECT 1 FROM sys.views WHERE name = 'vw_swim_refdata_sync_status')
    DROP VIEW dbo.vw_swim_refdata_sync_status;
GO

CREATE VIEW dbo.vw_swim_refdata_sync_status AS
SELECT
    'coded_departure_routes' AS table_name,
    COUNT(*) AS row_count,
    MAX(last_sync_utc) AS last_sync_utc,
    DATEDIFF(MINUTE, MAX(last_sync_utc), SYSUTCDATETIME()) AS minutes_since_sync
FROM dbo.swim_coded_departure_routes
UNION ALL
SELECT
    'playbook_plays',
    COUNT(*),
    MAX(last_sync_utc),
    DATEDIFF(MINUTE, MAX(last_sync_utc), SYSUTCDATETIME())
FROM dbo.swim_playbook_plays
UNION ALL
SELECT
    'playbook_routes',
    COUNT(*),
    MAX(last_sync_utc),
    DATEDIFF(MINUTE, MAX(last_sync_utc), SYSUTCDATETIME())
FROM dbo.swim_playbook_routes;
GO

PRINT 'Created view: vw_swim_refdata_sync_status';
GO

PRINT '==========================================================================';
PRINT '  Migration 025 complete.';
PRINT '  Tables: swim_coded_departure_routes, swim_playbook_plays, swim_playbook_routes';
PRINT '  View: vw_swim_refdata_sync_status';
PRINT '==========================================================================';
GO
