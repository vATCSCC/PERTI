-- ============================================================================
-- Migration: Add route_id to tmi_proposals
-- Purpose: Link route coordination proposals to tmi_public_routes
-- Date: 2026-01-29
-- ============================================================================

USE VATSIM_TMI;
GO

-- Add route_id column to tmi_proposals for ROUTE type proposals
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_proposals')
    AND name = 'route_id'
)
BEGIN
    ALTER TABLE dbo.tmi_proposals
    ADD route_id INT NULL;

    PRINT 'Added route_id column to tmi_proposals';
END
ELSE
BEGIN
    PRINT 'route_id column already exists in tmi_proposals';
END
GO

-- Add index for route lookups
IF NOT EXISTS (
    SELECT * FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tmi_proposals')
    AND name = 'IX_tmi_proposals_route_id'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_tmi_proposals_route_id
    ON dbo.tmi_proposals (route_id)
    WHERE route_id IS NOT NULL;

    PRINT 'Created index IX_tmi_proposals_route_id';
END
GO

-- Add discord_channel_id and discord_posted_at to tmi_public_routes if missing
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_public_routes')
    AND name = 'discord_channel_id'
)
BEGIN
    ALTER TABLE dbo.tmi_public_routes
    ADD discord_channel_id NVARCHAR(32) NULL;

    PRINT 'Added discord_channel_id column to tmi_public_routes';
END
GO

IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_public_routes')
    AND name = 'discord_posted_at'
)
BEGIN
    ALTER TABLE dbo.tmi_public_routes
    ADD discord_posted_at DATETIME2(0) NULL;

    PRINT 'Added discord_posted_at column to tmi_public_routes';
END
GO

PRINT 'Migration 021_add_route_id_to_proposals completed successfully';
