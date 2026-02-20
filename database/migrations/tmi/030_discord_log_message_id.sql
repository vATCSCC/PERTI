-- ============================================================================
-- Migration 030: Add Discord message tracking columns to tmi_proposals
-- Purpose: Store the auto-updating coordination log message ID and the
--          starter (channel) message ID for status updates on approval.
-- Date: 2026-02-20
-- ============================================================================

USE VATSIM_TMI;
GO

PRINT '=== Migration 030: Discord Message Tracking Columns ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- 1. discord_log_message_id: the auto-updating log message in the thread
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_proposals')
    AND name = 'discord_log_message_id'
)
BEGIN
    ALTER TABLE dbo.tmi_proposals ADD discord_log_message_id NVARCHAR(64) NULL;
    PRINT 'Added column: tmi_proposals.discord_log_message_id';
END
ELSE
BEGIN
    PRINT 'Column tmi_proposals.discord_log_message_id already exists - skipping';
END
GO

-- 2. discord_starter_message_id: the starter message in the main channel (for status edits)
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_proposals')
    AND name = 'discord_starter_message_id'
)
BEGIN
    ALTER TABLE dbo.tmi_proposals ADD discord_starter_message_id NVARCHAR(64) NULL;
    PRINT 'Added column: tmi_proposals.discord_starter_message_id';
END
ELSE
BEGIN
    PRINT 'Column tmi_proposals.discord_starter_message_id already exists - skipping';
END
GO

PRINT '';
PRINT '=== Migration 030 completed successfully ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
