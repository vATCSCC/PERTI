-- ============================================================================
-- Migration 023: Preferred Routes Reference Table
--
-- Purpose:
--   Create dbo.preferred_routes for imported preferred-route reference data.
--
-- Notes:
--   - Intended to run on BOTH VATSIM_REF (authoritative) and VATSIM_ADL (cache).
--   - Import pipeline normalizes origin/destination to ICAO when airport-mappable,
--     strips airport endpoint tokens from route_string, and enriches with parent
--     TRACON/Center fields.
-- ============================================================================

SET NOCOUNT ON;
GO

PRINT '=== Migration 023: Preferred Routes Table ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.tables
    WHERE object_id = OBJECT_ID(N'dbo.preferred_routes')
)
BEGIN
    CREATE TABLE dbo.preferred_routes (
        preferred_route_id  INT IDENTITY(1,1) NOT NULL,

        -- Endpoint codes (normalized)
        origin_code         NVARCHAR(8) NOT NULL,    -- ICAO if airport, else original code
        dest_code           NVARCHAR(8) NOT NULL,    -- ICAO if airport, else original code

        -- Raw endpoint values from source CSV
        origin_raw          NVARCHAR(8) NOT NULL,
        dest_raw            NVARCHAR(8) NOT NULL,

        -- Route body (cleaned: airport endpoints stripped when applicable)
        route_string        NVARCHAR(MAX) NOT NULL,

        -- Source metadata columns from prefroutes_db.csv
        hours1              NVARCHAR(32) NULL,
        hours2              NVARCHAR(32) NULL,
        hours3              NVARCHAR(32) NULL,
        route_type          NVARCHAR(16) NOT NULL,
        area                NVARCHAR(256) NULL,
        altitude            NVARCHAR(128) NULL,
        aircraft            NVARCHAR(256) NULL,
        direction           NVARCHAR(64) NULL,
        seq                 INT NOT NULL,

        -- Facility metadata
        dep_artcc           NVARCHAR(8) NULL,
        arr_artcc           NVARCHAR(8) NULL,
        origin_tracon       NVARCHAR(8) NULL,
        origin_center       NVARCHAR(8) NULL,
        dest_tracon         NVARCHAR(8) NULL,
        dest_center         NVARCHAR(8) NULL,
        traversed_centers   NVARCHAR(MAX) NULL,
        origin_is_airport   BIT NOT NULL DEFAULT 0,
        dest_is_airport     BIT NOT NULL DEFAULT 0,

        -- Standard reference-data metadata
        is_active           BIT NOT NULL DEFAULT 1,
        source              NVARCHAR(64) NULL,
        effective_date      DATE NULL,
        last_updated_utc    DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

        CONSTRAINT PK_preferred_routes PRIMARY KEY CLUSTERED (preferred_route_id)
    );

    PRINT 'Created table dbo.preferred_routes';
END
ELSE
BEGIN
    PRINT 'Table dbo.preferred_routes already exists - skipping';
END
GO

IF COL_LENGTH('dbo.preferred_routes', 'traversed_centers') IS NULL
BEGIN
    ALTER TABLE dbo.preferred_routes
        ADD traversed_centers NVARCHAR(MAX) NULL;
    PRINT 'Added missing column dbo.preferred_routes.traversed_centers';
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.preferred_routes')
      AND name = 'IX_preferred_routes_origin_dest'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_preferred_routes_origin_dest
        ON dbo.preferred_routes (origin_code, dest_code)
        INCLUDE (route_type, seq, route_string);
    PRINT 'Created index IX_preferred_routes_origin_dest';
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.preferred_routes')
      AND name = 'IX_preferred_routes_od_type_seq'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_preferred_routes_od_type_seq
        ON dbo.preferred_routes (origin_code, dest_code, route_type, seq);
    PRINT 'Created index IX_preferred_routes_od_type_seq';
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.preferred_routes')
      AND name = 'IX_preferred_routes_dep_artcc'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_preferred_routes_dep_artcc
        ON dbo.preferred_routes (dep_artcc)
        WHERE dep_artcc IS NOT NULL;
    PRINT 'Created index IX_preferred_routes_dep_artcc';
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.preferred_routes')
      AND name = 'IX_preferred_routes_arr_artcc'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_preferred_routes_arr_artcc
        ON dbo.preferred_routes (arr_artcc)
        WHERE arr_artcc IS NOT NULL;
    PRINT 'Created index IX_preferred_routes_arr_artcc';
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.preferred_routes')
      AND name = 'IX_preferred_routes_origin_tracon'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_preferred_routes_origin_tracon
        ON dbo.preferred_routes (origin_tracon)
        WHERE origin_tracon IS NOT NULL;
    PRINT 'Created index IX_preferred_routes_origin_tracon';
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.preferred_routes')
      AND name = 'IX_preferred_routes_dest_tracon'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_preferred_routes_dest_tracon
        ON dbo.preferred_routes (dest_tracon)
        WHERE dest_tracon IS NOT NULL;
    PRINT 'Created index IX_preferred_routes_dest_tracon';
END
GO

PRINT 'Migration 023 complete.';
GO
