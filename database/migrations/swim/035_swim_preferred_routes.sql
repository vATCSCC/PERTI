-- ============================================================================
-- Migration 035: SWIM Preferred Routes Mirror Table
--
-- Mirrors VATSIM_REF.dbo.preferred_routes into SWIM_API for public/partner
-- route-query consumption and isolated API workload.
-- ============================================================================

USE SWIM_API;
GO

PRINT '==========================================================================';
PRINT '  Migration 035: SWIM Preferred Routes Mirror';
PRINT '  ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
GO

IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'swim_preferred_routes')
BEGIN
    CREATE TABLE dbo.swim_preferred_routes (
        preferred_route_id  INT NOT NULL PRIMARY KEY,
        origin_code         NVARCHAR(8) NOT NULL,
        dest_code           NVARCHAR(8) NOT NULL,
        origin_raw          NVARCHAR(8) NOT NULL,
        dest_raw            NVARCHAR(8) NOT NULL,
        route_string        NVARCHAR(MAX) NOT NULL,
        hours1              NVARCHAR(32) NULL,
        hours2              NVARCHAR(32) NULL,
        hours3              NVARCHAR(32) NULL,
        route_type          NVARCHAR(16) NOT NULL,
        area                NVARCHAR(256) NULL,
        altitude            NVARCHAR(128) NULL,
        aircraft            NVARCHAR(256) NULL,
        direction           NVARCHAR(64) NULL,
        seq                 INT NOT NULL,
        dep_artcc           NVARCHAR(8) NULL,
        arr_artcc           NVARCHAR(8) NULL,
        origin_tracon       NVARCHAR(8) NULL,
        origin_center       NVARCHAR(8) NULL,
        dest_tracon         NVARCHAR(8) NULL,
        dest_center         NVARCHAR(8) NULL,
        traversed_centers   NVARCHAR(MAX) NULL,
        origin_is_airport   BIT NOT NULL DEFAULT 0,
        dest_is_airport     BIT NOT NULL DEFAULT 0,
        is_active           BIT NOT NULL DEFAULT 1,
        source              NVARCHAR(64) NULL,
        effective_date      DATE NULL,
        last_sync_utc       DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME()
    );
    PRINT 'Created table: swim_preferred_routes';
END
GO

IF COL_LENGTH('dbo.swim_preferred_routes', 'traversed_centers') IS NULL
BEGIN
    ALTER TABLE dbo.swim_preferred_routes
        ADD traversed_centers NVARCHAR(MAX) NULL;
    PRINT 'Added column: swim_preferred_routes.traversed_centers';
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_swim_pref_origin_dest')
    CREATE NONCLUSTERED INDEX IX_swim_pref_origin_dest
        ON dbo.swim_preferred_routes (origin_code, dest_code)
        INCLUDE (route_type, seq, route_string);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_swim_pref_od_type_seq')
    CREATE NONCLUSTERED INDEX IX_swim_pref_od_type_seq
        ON dbo.swim_preferred_routes (origin_code, dest_code, route_type, seq);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_swim_pref_dep_artcc')
    CREATE NONCLUSTERED INDEX IX_swim_pref_dep_artcc
        ON dbo.swim_preferred_routes (dep_artcc)
        WHERE dep_artcc IS NOT NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_swim_pref_arr_artcc')
    CREATE NONCLUSTERED INDEX IX_swim_pref_arr_artcc
        ON dbo.swim_preferred_routes (arr_artcc)
        WHERE arr_artcc IS NOT NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_swim_pref_origin_tracon')
    CREATE NONCLUSTERED INDEX IX_swim_pref_origin_tracon
        ON dbo.swim_preferred_routes (origin_tracon)
        WHERE origin_tracon IS NOT NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_swim_pref_dest_tracon')
    CREATE NONCLUSTERED INDEX IX_swim_pref_dest_tracon
        ON dbo.swim_preferred_routes (dest_tracon)
        WHERE dest_tracon IS NOT NULL;
GO

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
FROM dbo.swim_playbook_routes
UNION ALL
SELECT
    'preferred_routes',
    COUNT(*),
    MAX(last_sync_utc),
    DATEDIFF(MINUTE, MAX(last_sync_utc), SYSUTCDATETIME())
FROM dbo.swim_preferred_routes;
GO

PRINT 'Created/updated view: vw_swim_refdata_sync_status';
PRINT '==========================================================================';
PRINT '  Migration 035 complete.';
PRINT '==========================================================================';
GO
