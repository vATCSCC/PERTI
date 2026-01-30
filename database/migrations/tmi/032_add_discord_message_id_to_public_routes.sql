-- ============================================================================
-- Migration: Add discord_message_id to tmi_public_routes
-- Purpose: Fix missing column causing API failure
-- Date: 2026-01-31
-- ============================================================================

USE VATSIM_TMI;
GO

-- Add discord_message_id column to tmi_public_routes if missing
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_public_routes')
    AND name = 'discord_message_id'
)
BEGIN
    ALTER TABLE dbo.tmi_public_routes
    ADD discord_message_id NVARCHAR(64) NULL;

    PRINT 'Added discord_message_id column to tmi_public_routes';
END
ELSE
BEGIN
    PRINT 'discord_message_id column already exists in tmi_public_routes';
END
GO

PRINT 'Migration 032_add_discord_message_id_to_public_routes completed successfully';
