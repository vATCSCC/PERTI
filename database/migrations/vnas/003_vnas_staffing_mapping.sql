-- ============================================================================
-- Migration 003: Staffing columns on adl_boundary + position/TCP sector maps
-- Enables 2B: enhanced controller feed parsing
-- Design: docs/plans/2026-04-07-vnas-reference-sync-design.md
-- ============================================================================

-- 1. Staffing columns on adl_boundary
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_boundary') AND name = 'is_staffed')
BEGIN
    ALTER TABLE dbo.adl_boundary ADD is_staffed BIT NOT NULL DEFAULT 0;
    ALTER TABLE dbo.adl_boundary ADD staffed_by_cid INT NULL;
    ALTER TABLE dbo.adl_boundary ADD staffed_updated_utc DATETIME2 NULL;
    PRINT 'Added staffing columns to dbo.adl_boundary';
END
GO

-- 2. vnas_position_sector_map (maps position ULIDs to adl_boundary rows)
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'vnas_position_sector_map')
BEGIN
    CREATE TABLE dbo.vnas_position_sector_map (
        position_ulid             NVARCHAR(32)  NOT NULL,
        boundary_id               INT           NOT NULL,
        boundary_code             NVARCHAR(20)  NOT NULL,
        parent_artcc              NVARCHAR(4)   NOT NULL,
        sector_type               NVARCHAR(16)  NOT NULL,
        mapped_utc                DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_vnas_pos_sector PRIMARY KEY (position_ulid, boundary_id)
    );
    CREATE INDEX IX_vnas_pos_sector_boundary ON dbo.vnas_position_sector_map (boundary_id);
    PRINT 'Created table: dbo.vnas_position_sector_map';
END
GO

-- 3. vnas_tcp_sector_map (maps STARS TCP sectorIds to adl_boundary rows)
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'vnas_tcp_sector_map')
BEGIN
    CREATE TABLE dbo.vnas_tcp_sector_map (
        tcp_id                    NVARCHAR(32)  NOT NULL PRIMARY KEY,
        facility_id               NVARCHAR(8)   NOT NULL,
        sector_id                 NVARCHAR(4)   NOT NULL,
        boundary_id               INT           NULL,
        boundary_code             NVARCHAR(20)  NULL,
        parent_artcc              NVARCHAR(4)   NOT NULL,
        mapped_utc                DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME()
    );
    CREATE INDEX IX_vnas_tcp_sector_facility ON dbo.vnas_tcp_sector_map (facility_id, sector_id);
    PRINT 'Created table: dbo.vnas_tcp_sector_map';
END
GO
