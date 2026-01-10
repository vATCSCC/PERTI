-- ============================================================================
-- ADL Topology Schema - Migration 001: ARTCC/FIR Tier Topology Tables
--
-- Creates normalized tables for ARTCC tier groups and facility configurations
-- Replaces the JSON-based artcc_tiers.json with database-backed topology
--
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Topology Migration 001: ARTCC Tier Schema ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. artcc_facilities - Master list of ARTCC/FIR facilities
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.artcc_facilities') AND type = 'U')
BEGIN
    CREATE TABLE dbo.artcc_facilities (
        facility_id         INT IDENTITY(1,1) NOT NULL,
        facility_code       NVARCHAR(8) NOT NULL,            -- ZAB, ZNY, CZQX, etc.
        facility_name       NVARCHAR(64) NULL,               -- Albuquerque ARTCC
        facility_type       NVARCHAR(16) NOT NULL DEFAULT 'ARTCC',  -- ARTCC, FIR, OCEANIC
        country_code        NVARCHAR(4) NULL,                -- US, CA, etc.

        -- Center position (approximate)
        center_lat          DECIMAL(10,7) NULL,
        center_lon          DECIMAL(11,7) NULL,

        -- Status
        is_active           BIT NOT NULL DEFAULT 1,

        -- Metadata
        created_at          DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
        updated_at          DATETIME2 NOT NULL DEFAULT GETUTCDATE(),

        CONSTRAINT PK_artcc_facilities PRIMARY KEY CLUSTERED (facility_id),
        CONSTRAINT UK_facility_code UNIQUE NONCLUSTERED (facility_code)
    );

    PRINT 'Created table dbo.artcc_facilities';
END
ELSE
BEGIN
    PRINT 'Table dbo.artcc_facilities already exists - skipping';
END
GO

-- ============================================================================
-- 2. artcc_tier_types - Enumeration of tier type categories
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.artcc_tier_types') AND type = 'U')
BEGIN
    CREATE TABLE dbo.artcc_tier_types (
        tier_type_id        INT IDENTITY(1,1) NOT NULL,
        tier_type_code      NVARCHAR(32) NOT NULL,           -- internal, 1stTier, 6West, etc.
        tier_type_label     NVARCHAR(64) NOT NULL,           -- Internal, 1st Tier, 6 West, etc.
        tier_type_category  NVARCHAR(32) NULL,               -- RADIAL, REGIONAL, COASTAL
        display_order       INT NOT NULL DEFAULT 0,

        -- Metadata
        created_at          DATETIME2 NOT NULL DEFAULT GETUTCDATE(),

        CONSTRAINT PK_artcc_tier_types PRIMARY KEY CLUSTERED (tier_type_id),
        CONSTRAINT UK_tier_type_code UNIQUE NONCLUSTERED (tier_type_code)
    );

    PRINT 'Created table dbo.artcc_tier_types';
END
ELSE
BEGIN
    PRINT 'Table dbo.artcc_tier_types already exists - skipping';
END
GO

-- ============================================================================
-- 3. artcc_tier_groups - Named tier group definitions
--    These are the canonical tier groups like "6West", "EastCoast", etc.
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.artcc_tier_groups') AND type = 'U')
BEGIN
    CREATE TABLE dbo.artcc_tier_groups (
        tier_group_id       INT IDENTITY(1,1) NOT NULL,
        tier_group_code     NVARCHAR(16) NOT NULL,           -- 6WEST, 10WEST, EASTCOAST, GULF, WESTCOAST
        tier_group_name     NVARCHAR(64) NOT NULL,           -- 6 West, 10 West, East Coast, etc.
        tier_type_id        INT NULL,                        -- Links to tier_types for categorization
        description         NVARCHAR(256) NULL,

        -- Visual/display
        display_color       NVARCHAR(16) NULL,               -- Hex color for UI
        display_order       INT NOT NULL DEFAULT 0,

        -- Status
        is_active           BIT NOT NULL DEFAULT 1,

        -- Metadata
        created_at          DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
        updated_at          DATETIME2 NOT NULL DEFAULT GETUTCDATE(),

        CONSTRAINT PK_artcc_tier_groups PRIMARY KEY CLUSTERED (tier_group_id),
        CONSTRAINT UK_tier_group_code UNIQUE NONCLUSTERED (tier_group_code),
        CONSTRAINT FK_tier_group_type FOREIGN KEY (tier_type_id)
            REFERENCES dbo.artcc_tier_types(tier_type_id)
    );

    CREATE NONCLUSTERED INDEX IX_tier_group_type ON dbo.artcc_tier_groups (tier_type_id);

    PRINT 'Created table dbo.artcc_tier_groups';
END
ELSE
BEGIN
    PRINT 'Table dbo.artcc_tier_groups already exists - skipping';
END
GO

-- ============================================================================
-- 4. artcc_tier_group_members - Maps tier groups to member ARTCCs
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.artcc_tier_group_members') AND type = 'U')
BEGIN
    CREATE TABLE dbo.artcc_tier_group_members (
        member_id           INT IDENTITY(1,1) NOT NULL,
        tier_group_id       INT NOT NULL,
        facility_id         INT NOT NULL,

        -- Membership order (for display purposes)
        display_order       INT NOT NULL DEFAULT 0,

        -- Metadata
        created_at          DATETIME2 NOT NULL DEFAULT GETUTCDATE(),

        CONSTRAINT PK_tier_group_members PRIMARY KEY CLUSTERED (member_id),
        CONSTRAINT UK_tier_group_member UNIQUE NONCLUSTERED (tier_group_id, facility_id),
        CONSTRAINT FK_member_tier_group FOREIGN KEY (tier_group_id)
            REFERENCES dbo.artcc_tier_groups(tier_group_id) ON DELETE CASCADE,
        CONSTRAINT FK_member_facility FOREIGN KEY (facility_id)
            REFERENCES dbo.artcc_facilities(facility_id) ON DELETE CASCADE
    );

    CREATE NONCLUSTERED INDEX IX_member_facility ON dbo.artcc_tier_group_members (facility_id);

    PRINT 'Created table dbo.artcc_tier_group_members';
END
ELSE
BEGIN
    PRINT 'Table dbo.artcc_tier_group_members already exists - skipping';
END
GO

-- ============================================================================
-- 5. facility_tier_configs - Facility-specific tier configurations
--    Each facility can have multiple tier configs (Internal, 1stTier, 2ndTier, etc.)
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.facility_tier_configs') AND type = 'U')
BEGIN
    CREATE TABLE dbo.facility_tier_configs (
        config_id           INT IDENTITY(1,1) NOT NULL,
        facility_id         INT NOT NULL,                    -- The facility this config belongs to
        config_code         NVARCHAR(16) NOT NULL,           -- ZAB1, ZAB2, ZAB6W, ZABI, etc.
        config_label        NVARCHAR(32) NOT NULL,           -- (1stTier), (6West), (Internal), etc.
        tier_type_id        INT NULL,                        -- Links to tier_types
        tier_group_id       INT NULL,                        -- If this uses a named tier group

        -- Display
        display_order       INT NOT NULL DEFAULT 0,

        -- Status
        is_active           BIT NOT NULL DEFAULT 1,
        is_default          BIT NOT NULL DEFAULT 0,          -- Default selection for this facility

        -- Metadata
        created_at          DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
        updated_at          DATETIME2 NOT NULL DEFAULT GETUTCDATE(),

        CONSTRAINT PK_facility_tier_configs PRIMARY KEY CLUSTERED (config_id),
        CONSTRAINT UK_facility_config_code UNIQUE NONCLUSTERED (config_code),
        CONSTRAINT FK_config_facility FOREIGN KEY (facility_id)
            REFERENCES dbo.artcc_facilities(facility_id) ON DELETE CASCADE,
        CONSTRAINT FK_config_tier_type FOREIGN KEY (tier_type_id)
            REFERENCES dbo.artcc_tier_types(tier_type_id),
        CONSTRAINT FK_config_tier_group FOREIGN KEY (tier_group_id)
            REFERENCES dbo.artcc_tier_groups(tier_group_id)
    );

    CREATE NONCLUSTERED INDEX IX_config_facility ON dbo.facility_tier_configs (facility_id);
    CREATE NONCLUSTERED INDEX IX_config_tier_group ON dbo.facility_tier_configs (tier_group_id)
        WHERE tier_group_id IS NOT NULL;

    PRINT 'Created table dbo.facility_tier_configs';
END
ELSE
BEGIN
    PRINT 'Table dbo.facility_tier_configs already exists - skipping';
END
GO

-- ============================================================================
-- 6. facility_tier_config_members - Custom ARTCC lists for facility-specific configs
--    Used when a config doesn't reference a named tier_group but has its own list
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.facility_tier_config_members') AND type = 'U')
BEGIN
    CREATE TABLE dbo.facility_tier_config_members (
        member_id           INT IDENTITY(1,1) NOT NULL,
        config_id           INT NOT NULL,
        facility_id         INT NOT NULL,                    -- Member ARTCC

        -- Membership order
        display_order       INT NOT NULL DEFAULT 0,

        -- Metadata
        created_at          DATETIME2 NOT NULL DEFAULT GETUTCDATE(),

        CONSTRAINT PK_facility_config_members PRIMARY KEY CLUSTERED (member_id),
        CONSTRAINT UK_config_member UNIQUE NONCLUSTERED (config_id, facility_id),
        CONSTRAINT FK_config_member_config FOREIGN KEY (config_id)
            REFERENCES dbo.facility_tier_configs(config_id) ON DELETE CASCADE,
        CONSTRAINT FK_config_member_facility FOREIGN KEY (facility_id)
            REFERENCES dbo.artcc_facilities(facility_id)
    );

    CREATE NONCLUSTERED INDEX IX_config_member_facility ON dbo.facility_tier_config_members (facility_id);

    PRINT 'Created table dbo.facility_tier_config_members';
END
ELSE
BEGIN
    PRINT 'Table dbo.facility_tier_config_members already exists - skipping';
END
GO

-- ============================================================================
-- 7. artcc_adjacencies - Border relationships between facilities
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.artcc_adjacencies') AND type = 'U')
BEGIN
    CREATE TABLE dbo.artcc_adjacencies (
        adjacency_id        INT IDENTITY(1,1) NOT NULL,
        facility_id         INT NOT NULL,                    -- The facility
        adjacent_facility_id INT NOT NULL,                   -- The bordering facility

        -- Border type
        border_type         NVARCHAR(16) NOT NULL DEFAULT 'LATERAL',  -- LATERAL, VERTICAL, OCEANIC

        -- Notes
        notes               NVARCHAR(256) NULL,              -- e.g., "when CZEG owns CZWG north high sector"

        -- Status
        is_active           BIT NOT NULL DEFAULT 1,

        -- Metadata
        created_at          DATETIME2 NOT NULL DEFAULT GETUTCDATE(),

        CONSTRAINT PK_artcc_adjacencies PRIMARY KEY CLUSTERED (adjacency_id),
        CONSTRAINT UK_adjacency_pair UNIQUE NONCLUSTERED (facility_id, adjacent_facility_id),
        CONSTRAINT FK_adjacency_facility FOREIGN KEY (facility_id)
            REFERENCES dbo.artcc_facilities(facility_id) ON DELETE CASCADE,
        CONSTRAINT FK_adjacency_adjacent FOREIGN KEY (adjacent_facility_id)
            REFERENCES dbo.artcc_facilities(facility_id)
    );

    CREATE NONCLUSTERED INDEX IX_adjacency_facility ON dbo.artcc_adjacencies (facility_id);
    CREATE NONCLUSTERED INDEX IX_adjacency_adjacent ON dbo.artcc_adjacencies (adjacent_facility_id);

    PRINT 'Created table dbo.artcc_adjacencies';
END
ELSE
BEGIN
    PRINT 'Table dbo.artcc_adjacencies already exists - skipping';
END
GO

-- ============================================================================
-- 8. View: vw_artcc_topology - Denormalized view for easy querying
-- ============================================================================

IF EXISTS (SELECT * FROM sys.views WHERE object_id = OBJECT_ID(N'dbo.vw_artcc_topology'))
BEGIN
    DROP VIEW dbo.vw_artcc_topology;
END
GO

CREATE VIEW dbo.vw_artcc_topology
AS
-- Named tier groups with their members
SELECT
    tg.tier_group_code,
    tg.tier_group_name,
    tt.tier_type_code,
    tt.tier_type_label,
    f.facility_code AS member_artcc,
    f.facility_name AS member_name,
    'TIER_GROUP' AS config_source,
    tgm.display_order
FROM dbo.artcc_tier_groups tg
INNER JOIN dbo.artcc_tier_group_members tgm ON tg.tier_group_id = tgm.tier_group_id
INNER JOIN dbo.artcc_facilities f ON tgm.facility_id = f.facility_id
LEFT JOIN dbo.artcc_tier_types tt ON tg.tier_type_id = tt.tier_type_id
WHERE tg.is_active = 1 AND f.is_active = 1;
GO

PRINT 'Created view dbo.vw_artcc_topology';
GO

-- ============================================================================
-- 8. View: vw_facility_tier_options - All tier options available for each facility
-- ============================================================================

IF EXISTS (SELECT * FROM sys.views WHERE object_id = OBJECT_ID(N'dbo.vw_facility_tier_options'))
BEGIN
    DROP VIEW dbo.vw_facility_tier_options;
END
GO

CREATE VIEW dbo.vw_facility_tier_options
AS
SELECT
    fc.config_id,
    fc.config_code,
    fc.config_label,
    ff.facility_code AS owner_facility,
    ff.facility_name AS owner_facility_name,
    tt.tier_type_code,
    tt.tier_type_label,
    COALESCE(tg.tier_group_code, fc.config_code) AS effective_group_code,
    COALESCE(tg.tier_group_name, fc.config_label) AS effective_group_name,
    fc.is_default,
    fc.display_order
FROM dbo.facility_tier_configs fc
INNER JOIN dbo.artcc_facilities ff ON fc.facility_id = ff.facility_id
LEFT JOIN dbo.artcc_tier_types tt ON fc.tier_type_id = tt.tier_type_id
LEFT JOIN dbo.artcc_tier_groups tg ON fc.tier_group_id = tg.tier_group_id
WHERE fc.is_active = 1 AND ff.is_active = 1;
GO

PRINT 'Created view dbo.vw_facility_tier_options';
GO

-- ============================================================================
-- 9. Function: fn_GetTierGroupARTCCs - Returns comma-delimited list of ARTCCs
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.fn_GetTierGroupARTCCs') AND type = 'FN')
BEGIN
    DROP FUNCTION dbo.fn_GetTierGroupARTCCs;
END
GO

CREATE FUNCTION dbo.fn_GetTierGroupARTCCs
(
    @TierGroupCode NVARCHAR(16)
)
RETURNS NVARCHAR(MAX)
AS
BEGIN
    DECLARE @Result NVARCHAR(MAX);

    SELECT @Result = STRING_AGG(f.facility_code, ', ') WITHIN GROUP (ORDER BY tgm.display_order)
    FROM dbo.artcc_tier_groups tg
    INNER JOIN dbo.artcc_tier_group_members tgm ON tg.tier_group_id = tgm.tier_group_id
    INNER JOIN dbo.artcc_facilities f ON tgm.facility_id = f.facility_id
    WHERE tg.tier_group_code = @TierGroupCode
      AND tg.is_active = 1
      AND f.is_active = 1;

    RETURN @Result;
END
GO

PRINT 'Created function dbo.fn_GetTierGroupARTCCs';
GO

-- ============================================================================
-- 10. Function: fn_GetFacilityConfigARTCCs - Returns ARTCCs for a facility config
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.fn_GetFacilityConfigARTCCs') AND type = 'FN')
BEGIN
    DROP FUNCTION dbo.fn_GetFacilityConfigARTCCs;
END
GO

CREATE FUNCTION dbo.fn_GetFacilityConfigARTCCs
(
    @ConfigCode NVARCHAR(16)
)
RETURNS NVARCHAR(MAX)
AS
BEGIN
    DECLARE @Result NVARCHAR(MAX);
    DECLARE @TierGroupId INT;

    -- Check if config references a tier group
    SELECT @TierGroupId = tier_group_id
    FROM dbo.facility_tier_configs
    WHERE config_code = @ConfigCode AND is_active = 1;

    IF @TierGroupId IS NOT NULL
    BEGIN
        -- Use tier group members
        SELECT @Result = STRING_AGG(f.facility_code, ', ') WITHIN GROUP (ORDER BY tgm.display_order)
        FROM dbo.artcc_tier_group_members tgm
        INNER JOIN dbo.artcc_facilities f ON tgm.facility_id = f.facility_id
        WHERE tgm.tier_group_id = @TierGroupId
          AND f.is_active = 1;
    END
    ELSE
    BEGIN
        -- Use config-specific members
        SELECT @Result = STRING_AGG(f.facility_code, ', ') WITHIN GROUP (ORDER BY fcm.display_order)
        FROM dbo.facility_tier_configs fc
        INNER JOIN dbo.facility_tier_config_members fcm ON fc.config_id = fcm.config_id
        INNER JOIN dbo.artcc_facilities f ON fcm.facility_id = f.facility_id
        WHERE fc.config_code = @ConfigCode
          AND fc.is_active = 1
          AND f.is_active = 1;
    END

    RETURN @Result;
END
GO

PRINT 'Created function dbo.fn_GetFacilityConfigARTCCs';
GO

PRINT '';
PRINT '=== ADL Topology Migration 001 Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO