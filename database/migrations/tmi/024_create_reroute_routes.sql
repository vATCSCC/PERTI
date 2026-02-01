-- ============================================================================
-- PERTI Reroute Routes - TMI Database Migration
-- Version: 1.0
-- Date: February 2026
--
-- Creates:
--   - tmi_reroute_routes: Individual origin/destination route pairs
--
-- NOTE: This is a copy from adl/migrations/tmi/011_reroute_routes_table.sql
--       to ensure migration is in the canonical location.
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== Reroute Routes Migration (024) ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. tmi_reroute_routes - Individual origin/destination route pairs
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'tmi_reroute_routes')
BEGIN
    CREATE TABLE dbo.tmi_reroute_routes (
        -- Primary key
        route_id INT IDENTITY(1,1) PRIMARY KEY,

        -- Parent reroute reference
        reroute_id INT NOT NULL,

        -- Origin/Destination (can be single airport or space-delimited group)
        -- Examples: 'JFK', 'EWR LGA', 'PHL BWI DCA'
        origin NVARCHAR(64) NOT NULL,
        destination NVARCHAR(64) NOT NULL,

        -- The route string for this origin/dest pair
        -- Supports mandatory segment markers: >FIX and FIX<
        route_string NVARCHAR(MAX) NOT NULL,

        -- Display order in route table
        sort_order INT DEFAULT 0,

        -- Origin/destination filters (added in 026)
        -- Format: comma-separated airport codes with optional prefix (- for exclude, + for include)
        -- Examples: "-KJFK,-KPHL" excludes JFK and PHL
        origin_filter NVARCHAR(128) NULL,
        dest_filter NVARCHAR(128) NULL,

        -- Audit (consistent with other TMI tables: _at suffix)
        created_at DATETIME2(0) DEFAULT SYSUTCDATETIME(),
        updated_at DATETIME2(0) DEFAULT SYSUTCDATETIME(),

        -- Foreign key constraint
        CONSTRAINT FK_tmi_reroute_routes_reroute FOREIGN KEY (reroute_id)
            REFERENCES dbo.tmi_reroutes(reroute_id) ON DELETE CASCADE
    );

    -- Indexes
    CREATE INDEX IX_tmi_reroute_routes_reroute ON dbo.tmi_reroute_routes(reroute_id);
    CREATE INDEX IX_tmi_reroute_routes_sort ON dbo.tmi_reroute_routes(reroute_id, sort_order);

    PRINT 'Created table: dbo.tmi_reroute_routes';
END
ELSE
BEGIN
    PRINT 'Table dbo.tmi_reroute_routes already exists';
END
GO

PRINT '';
PRINT '=== Reroute Routes Migration Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
