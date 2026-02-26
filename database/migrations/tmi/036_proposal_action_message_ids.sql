-- Migration 036: Add discord_action_message_ids to tmi_proposals
-- Stores JSON array of ALL action message IDs when a proposal's
-- facility reactions are split across multiple Discord messages
-- (Discord enforces max 20 unique reactions per message).
--
-- The existing discord_message_id column keeps the FIRST action
-- message ID for backwards compatibility.

IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_proposals')
      AND name = 'discord_action_message_ids'
)
BEGIN
    ALTER TABLE dbo.tmi_proposals
    ADD discord_action_message_ids NVARCHAR(MAX) NULL;
END
GO
