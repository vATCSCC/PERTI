-- ============================================================================
-- 008_demand_monitors_table.sql
-- Global/shared demand monitors table
--
-- Purpose:
--   Store demand monitoring elements that are visible to all users.
--   Monitors can be created by any user and are shared globally.
--
-- Date: 2026-01-15
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

-- Create the demand monitors table
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'demand_monitors' AND schema_id = SCHEMA_ID('dbo'))
BEGIN
    CREATE TABLE dbo.demand_monitors (
        monitor_id      INT IDENTITY(1,1) NOT NULL,
        monitor_key     NVARCHAR(128) NOT NULL,     -- Unique identifier (e.g., 'fix_MERIT', 'airway_segment_Q90_JASSE_DNERO')
        monitor_type    NVARCHAR(32) NOT NULL,      -- fix, segment, airway, airway_segment, via_fix
        definition      NVARCHAR(MAX) NOT NULL,     -- JSON definition of the monitor
        display_label   NVARCHAR(128) NULL,         -- Human-readable label
        created_by      NVARCHAR(64) NULL,          -- User who created (optional)
        created_utc     DATETIME2(0) NOT NULL DEFAULT GETUTCDATE(),
        is_active       BIT NOT NULL DEFAULT 1,
        CONSTRAINT PK_demand_monitors PRIMARY KEY CLUSTERED (monitor_id),
        CONSTRAINT UQ_demand_monitors_key UNIQUE (monitor_key)
    );

    PRINT 'Created table dbo.demand_monitors';
END
ELSE
BEGIN
    PRINT 'Table dbo.demand_monitors already exists';
END
GO

-- Create index on monitor_type for filtering
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_demand_monitors_type' AND object_id = OBJECT_ID('dbo.demand_monitors'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_demand_monitors_type
    ON dbo.demand_monitors (monitor_type, is_active);

    PRINT 'Created index IX_demand_monitors_type';
END
GO

-- Create index on created_utc for ordering
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_demand_monitors_created' AND object_id = OBJECT_ID('dbo.demand_monitors'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_demand_monitors_created
    ON dbo.demand_monitors (created_utc DESC);

    PRINT 'Created index IX_demand_monitors_created';
END
GO
