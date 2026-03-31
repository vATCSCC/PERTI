-- ============================================================================
-- Migration: Create SWIM mirror tables for splits data
--
-- Creates 6 tables in SWIM_API for security-isolated split data publication:
--   1. splits_configs_swim      - Active/scheduled/draft/inactive configs
--   2. splits_positions_swim    - Positions within configs
--   3. splits_presets_swim      - Preset templates
--   4. splits_preset_positions_swim - Positions within presets
--   5. splits_areas_swim        - Predefined sector area groupings
--   6. splits_history_swim      - Append-only audit log of state transitions
--
-- Related: VATSWIM Splits Bridge (docs/superpowers/specs/2026-03-30-splits-to-swim-bridge-design.md)
-- ============================================================================

-- 1. splits_configs_swim
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'splits_configs_swim' AND schema_id = SCHEMA_ID('dbo'))
BEGIN
    CREATE TABLE dbo.splits_configs_swim (
        id              INT NOT NULL PRIMARY KEY,
        artcc           NVARCHAR(4) NOT NULL,
        config_name     NVARCHAR(100) NOT NULL,
        status          NVARCHAR(20) NOT NULL,
        start_time_utc  DATETIME2 NULL,
        end_time_utc    DATETIME2 NULL,
        sector_type     NVARCHAR(10) NULL,
        [source]        NVARCHAR(50) NOT NULL DEFAULT 'perti',
        source_id       NVARCHAR(100) NULL,
        created_by      NVARCHAR(50) NULL,
        activated_at    DATETIME2 NULL,
        created_at      DATETIME2 NULL,
        updated_at      DATETIME2 NULL,
        synced_utc      DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
    );

    CREATE NONCLUSTERED INDEX IX_splits_configs_swim_artcc_status
    ON dbo.splits_configs_swim (artcc, status);

    CREATE NONCLUSTERED INDEX IX_splits_configs_swim_status
    ON dbo.splits_configs_swim (status)
    INCLUDE (artcc, config_name, sector_type);

    PRINT 'Created table: dbo.splits_configs_swim';
END
ELSE
BEGIN
    PRINT 'Table dbo.splits_configs_swim already exists';
END
GO

-- 2. splits_positions_swim
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'splits_positions_swim' AND schema_id = SCHEMA_ID('dbo'))
BEGIN
    CREATE TABLE dbo.splits_positions_swim (
        id              INT NOT NULL PRIMARY KEY,
        config_id       INT NOT NULL,
        position_name   NVARCHAR(50) NOT NULL,
        sectors         NVARCHAR(MAX) NULL,
        color           NVARCHAR(20) NULL DEFAULT '#808080',
        sort_order      INT NULL DEFAULT 0,
        frequency       NVARCHAR(20) NULL,
        controller_oi   NVARCHAR(50) NULL,
        strata_filter   NVARCHAR(100) NULL,
        start_time_utc  DATETIME2 NULL,
        end_time_utc    DATETIME2 NULL,
        synced_utc      DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),

        CONSTRAINT FK_splits_positions_swim_config
            FOREIGN KEY (config_id) REFERENCES dbo.splits_configs_swim(id) ON DELETE CASCADE
    );

    CREATE NONCLUSTERED INDEX IX_splits_positions_swim_config
    ON dbo.splits_positions_swim (config_id);

    PRINT 'Created table: dbo.splits_positions_swim';
END
ELSE
BEGIN
    PRINT 'Table dbo.splits_positions_swim already exists';
END
GO

-- 3. splits_presets_swim
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'splits_presets_swim' AND schema_id = SCHEMA_ID('dbo'))
BEGIN
    CREATE TABLE dbo.splits_presets_swim (
        id              INT NOT NULL PRIMARY KEY,
        preset_name     NVARCHAR(100) NOT NULL,
        artcc           NVARCHAR(4) NOT NULL,
        description     NVARCHAR(500) NULL,
        created_at      DATETIME2 NULL,
        updated_at      DATETIME2 NULL,
        synced_utc      DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
    );

    CREATE NONCLUSTERED INDEX IX_splits_presets_swim_artcc
    ON dbo.splits_presets_swim (artcc);

    PRINT 'Created table: dbo.splits_presets_swim';
END
ELSE
BEGIN
    PRINT 'Table dbo.splits_presets_swim already exists';
END
GO

-- 4. splits_preset_positions_swim
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'splits_preset_positions_swim' AND schema_id = SCHEMA_ID('dbo'))
BEGIN
    CREATE TABLE dbo.splits_preset_positions_swim (
        id              INT NOT NULL PRIMARY KEY,
        preset_id       INT NOT NULL,
        position_name   NVARCHAR(50) NOT NULL,
        sectors         NVARCHAR(MAX) NULL,
        color           NVARCHAR(20) NULL DEFAULT '#808080',
        sort_order      INT NULL DEFAULT 0,
        frequency       NVARCHAR(20) NULL,
        strata_filter   NVARCHAR(100) NULL,
        synced_utc      DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),

        CONSTRAINT FK_splits_preset_positions_swim_preset
            FOREIGN KEY (preset_id) REFERENCES dbo.splits_presets_swim(id) ON DELETE CASCADE
    );

    CREATE NONCLUSTERED INDEX IX_splits_preset_positions_swim_preset
    ON dbo.splits_preset_positions_swim (preset_id);

    PRINT 'Created table: dbo.splits_preset_positions_swim';
END
ELSE
BEGIN
    PRINT 'Table dbo.splits_preset_positions_swim already exists';
END
GO

-- 5. splits_areas_swim
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'splits_areas_swim' AND schema_id = SCHEMA_ID('dbo'))
BEGIN
    CREATE TABLE dbo.splits_areas_swim (
        id              INT NOT NULL PRIMARY KEY,
        artcc           NVARCHAR(4) NOT NULL,
        area_name       NVARCHAR(100) NOT NULL,
        sectors         NVARCHAR(MAX) NULL,
        description     NVARCHAR(500) NULL,
        color           NVARCHAR(20) NULL,
        created_at      DATETIME2 NULL,
        updated_at      DATETIME2 NULL,
        synced_utc      DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
    );

    CREATE NONCLUSTERED INDEX IX_splits_areas_swim_artcc
    ON dbo.splits_areas_swim (artcc);

    PRINT 'Created table: dbo.splits_areas_swim';
END
ELSE
BEGIN
    PRINT 'Table dbo.splits_areas_swim already exists';
END
GO

-- 6. splits_history_swim (append-only audit log)
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'splits_history_swim' AND schema_id = SCHEMA_ID('dbo'))
BEGIN
    CREATE TABLE dbo.splits_history_swim (
        id              INT IDENTITY(1,1) PRIMARY KEY,
        config_id       INT NOT NULL,
        facility        NVARCHAR(4) NOT NULL,
        event_type      NVARCHAR(20) NOT NULL,
        config_snapshot NVARCHAR(MAX) NULL,
        [source]        NVARCHAR(50) NULL,
        event_at        DATETIME2 NOT NULL,
        synced_utc      DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
    );

    CREATE NONCLUSTERED INDEX IX_splits_history_swim_facility_event
    ON dbo.splits_history_swim (facility, event_at DESC);

    CREATE NONCLUSTERED INDEX IX_splits_history_swim_config
    ON dbo.splits_history_swim (config_id, event_at DESC);

    PRINT 'Created table: dbo.splits_history_swim';
END
ELSE
BEGIN
    PRINT 'Table dbo.splits_history_swim already exists';
END
GO
