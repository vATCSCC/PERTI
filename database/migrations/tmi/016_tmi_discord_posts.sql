-- =============================================================================
-- Migration 016: TMI Discord Posts Tracking Table
-- Database: VATSIM_TMI
-- Server: vatsim.database.windows.net
-- 
-- Purpose: Track Discord messages posted for TMI entries/advisories across
--          multiple Discord organizations (vATCSCC, VATCAN, ECFMP, etc.)
--
-- This table enables:
--   1. Multi-Discord posting (same TMI to multiple servers)
--   2. Bidirectional sync (track message IDs from both outbound and inbound)
--   3. Staging → Production promotion workflow
--   4. Message edit/delete synchronization
--   5. Approval workflow via Discord reactions
--
-- Date: 2026-01-27
-- Version: 1.0
-- =============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== Migration 016: TMI Discord Posts Tracking ===';
PRINT '';

-- =============================================================================
-- TABLE: tmi_discord_posts
-- =============================================================================

IF OBJECT_ID('dbo.tmi_discord_posts', 'U') IS NULL
BEGIN
    PRINT 'Creating table: tmi_discord_posts';
    
    CREATE TABLE dbo.tmi_discord_posts (
        -- Primary Key
        post_id                 INT IDENTITY(1,1) PRIMARY KEY,
        
        -- Entity Reference (what TMI this post is for)
        entity_type             NVARCHAR(16) NOT NULL,          -- 'ENTRY', 'ADVISORY', 'PROGRAM'
        entity_id               INT NOT NULL,                   -- FK to tmi_entries, tmi_advisories, or tmi_programs
        entity_guid             UNIQUEIDENTIFIER NULL,          -- For cross-reference
        
        -- Discord Organization Target
        org_code                NVARCHAR(16) NOT NULL,          -- 'vatcscc', 'vatcan', 'ecfmp', etc.
        org_name                NVARCHAR(64) NULL,              -- 'vATCSCC', 'VATCAN' (denormalized for display)
        
        -- Channel Information
        channel_purpose         NVARCHAR(32) NOT NULL,          -- 'ntml', 'advisories', 'ntml_staging', 'advzy_staging'
        channel_id              NVARCHAR(64) NOT NULL,          -- Discord channel ID
        guild_id                NVARCHAR(64) NULL,              -- Discord guild/server ID
        
        -- Message Information
        message_id              NVARCHAR(64) NULL,              -- Discord message ID (null if pending/failed)
        message_url             NVARCHAR(256) NULL,             -- Full Discord message URL
        message_content_hash    NVARCHAR(64) NULL,              -- Hash of message content for dedup
        
        -- Status Tracking
        status                  NVARCHAR(16) NOT NULL DEFAULT 'PENDING',
        -- PENDING   = Queued for posting
        -- POSTED    = Successfully posted to Discord
        -- FAILED    = Post failed (see error_message)
        -- DELETED   = Message deleted from Discord
        -- PROMOTED  = Staging message replaced by production post
        -- SYNCED    = Created from inbound Discord message (bidirectional)
        
        -- Error Handling
        error_message           NVARCHAR(512) NULL,             -- Error details if status=FAILED
        retry_count             TINYINT NOT NULL DEFAULT 0,     -- Number of retry attempts
        
        -- Timestamps
        requested_at            DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        posted_at               DATETIME2(0) NULL,              -- When successfully posted
        promoted_at             DATETIME2(0) NULL,              -- When promoted to production
        deleted_at              DATETIME2(0) NULL,              -- When deleted from Discord
        last_synced_at          DATETIME2(0) NULL,              -- Last bidirectional sync check
        
        -- Approval Workflow (for staging → production)
        approval_status         NVARCHAR(16) NULL,              -- NULL, 'PENDING', 'APPROVED', 'REJECTED'
        approved_by             NVARCHAR(64) NULL,              -- Discord user ID who approved
        approved_at             DATETIME2(0) NULL,
        approval_reaction_count INT NULL,                       -- Number of approval reactions (✅)
        
        -- Audit
        created_by              NVARCHAR(64) NULL,              -- VATSIM CID
        created_by_name         NVARCHAR(128) NULL,
        
        -- Direction tracking (for bidirectional sync)
        direction               NVARCHAR(8) NOT NULL DEFAULT 'OUTBOUND',
        -- OUTBOUND = PERTI → Discord
        -- INBOUND  = Discord → PERTI (message was created in Discord, synced to DB)
    );
    
    PRINT 'Table created: tmi_discord_posts';
END
ELSE
BEGIN
    PRINT 'Table already exists: tmi_discord_posts';
END
GO

-- =============================================================================
-- INDEXES
-- =============================================================================

-- Entity lookup (find all Discord posts for a TMI entry)
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_discord_posts_entity')
BEGIN
    CREATE NONCLUSTERED INDEX IX_discord_posts_entity 
        ON dbo.tmi_discord_posts (entity_type, entity_id)
        INCLUDE (org_code, channel_purpose, status, message_id);
    PRINT 'Created index: IX_discord_posts_entity';
END
GO

-- Organization/channel lookup (find posts by org)
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_discord_posts_org_channel')
BEGIN
    CREATE NONCLUSTERED INDEX IX_discord_posts_org_channel 
        ON dbo.tmi_discord_posts (org_code, channel_purpose, status)
        INCLUDE (entity_type, entity_id, message_id);
    PRINT 'Created index: IX_discord_posts_org_channel';
END
GO

-- Message ID lookup (for webhook handling - find entity by message)
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_discord_posts_message')
BEGIN
    CREATE NONCLUSTERED INDEX IX_discord_posts_message 
        ON dbo.tmi_discord_posts (message_id)
        WHERE message_id IS NOT NULL;
    PRINT 'Created index: IX_discord_posts_message';
END
GO

-- Channel ID lookup (for inbound webhook processing)
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_discord_posts_channel')
BEGIN
    CREATE NONCLUSTERED INDEX IX_discord_posts_channel 
        ON dbo.tmi_discord_posts (channel_id, status)
        INCLUDE (message_id, entity_type, entity_id);
    PRINT 'Created index: IX_discord_posts_channel';
END
GO

-- Content hash lookup (for deduplication)
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_discord_posts_hash')
BEGIN
    CREATE NONCLUSTERED INDEX IX_discord_posts_hash 
        ON dbo.tmi_discord_posts (message_content_hash)
        WHERE message_content_hash IS NOT NULL;
    PRINT 'Created index: IX_discord_posts_hash';
END
GO

-- Pending posts (for retry processing)
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_discord_posts_pending')
BEGIN
    CREATE NONCLUSTERED INDEX IX_discord_posts_pending 
        ON dbo.tmi_discord_posts (status, retry_count, requested_at)
        WHERE status IN ('PENDING', 'FAILED');
    PRINT 'Created index: IX_discord_posts_pending';
END
GO

-- Approval workflow (find pending approvals)
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_discord_posts_approval')
BEGIN
    CREATE NONCLUSTERED INDEX IX_discord_posts_approval 
        ON dbo.tmi_discord_posts (approval_status, channel_purpose)
        WHERE approval_status IS NOT NULL;
    PRINT 'Created index: IX_discord_posts_approval';
END
GO

-- =============================================================================
-- STORED PROCEDURES
-- =============================================================================

-- Create or update a Discord post record
IF OBJECT_ID('dbo.sp_UpsertDiscordPost', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_UpsertDiscordPost;
GO

CREATE PROCEDURE dbo.sp_UpsertDiscordPost
    @entity_type        NVARCHAR(16),
    @entity_id          INT,
    @org_code           NVARCHAR(16),
    @channel_purpose    NVARCHAR(32),
    @channel_id         NVARCHAR(64),
    @guild_id           NVARCHAR(64) = NULL,
    @message_id         NVARCHAR(64) = NULL,
    @message_url        NVARCHAR(256) = NULL,
    @status             NVARCHAR(16) = 'PENDING',
    @direction          NVARCHAR(8) = 'OUTBOUND',
    @created_by         NVARCHAR(64) = NULL,
    @created_by_name    NVARCHAR(128) = NULL,
    @content_hash       NVARCHAR(64) = NULL,
    @post_id            INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Check if record exists for this entity + org + channel_purpose
    SELECT @post_id = post_id 
    FROM dbo.tmi_discord_posts 
    WHERE entity_type = @entity_type 
      AND entity_id = @entity_id 
      AND org_code = @org_code 
      AND channel_purpose = @channel_purpose;
    
    IF @post_id IS NOT NULL
    BEGIN
        -- Update existing record
        UPDATE dbo.tmi_discord_posts SET
            message_id = COALESCE(@message_id, message_id),
            message_url = COALESCE(@message_url, message_url),
            message_content_hash = COALESCE(@content_hash, message_content_hash),
            status = @status,
            posted_at = CASE WHEN @status = 'POSTED' AND posted_at IS NULL THEN SYSUTCDATETIME() ELSE posted_at END,
            promoted_at = CASE WHEN @status = 'PROMOTED' THEN SYSUTCDATETIME() ELSE promoted_at END,
            deleted_at = CASE WHEN @status = 'DELETED' THEN SYSUTCDATETIME() ELSE deleted_at END
        WHERE post_id = @post_id;
    END
    ELSE
    BEGIN
        -- Insert new record
        INSERT INTO dbo.tmi_discord_posts (
            entity_type, entity_id, org_code, channel_purpose, channel_id, guild_id,
            message_id, message_url, message_content_hash, status, direction,
            created_by, created_by_name,
            posted_at
        ) VALUES (
            @entity_type, @entity_id, @org_code, @channel_purpose, @channel_id, @guild_id,
            @message_id, @message_url, @content_hash, @status, @direction,
            @created_by, @created_by_name,
            CASE WHEN @status = 'POSTED' THEN SYSUTCDATETIME() ELSE NULL END
        );
        
        SET @post_id = SCOPE_IDENTITY();
    END
END;
GO

PRINT 'Created procedure: sp_UpsertDiscordPost';
GO

-- Get Discord posts for an entity
IF OBJECT_ID('dbo.sp_GetDiscordPostsForEntity', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_GetDiscordPostsForEntity;
GO

CREATE PROCEDURE dbo.sp_GetDiscordPostsForEntity
    @entity_type    NVARCHAR(16),
    @entity_id      INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        post_id, org_code, org_name, channel_purpose, channel_id, guild_id,
        message_id, message_url, status, error_message,
        approval_status, approved_by, approved_at, approval_reaction_count,
        direction, posted_at, promoted_at, deleted_at,
        created_by, created_by_name, requested_at
    FROM dbo.tmi_discord_posts
    WHERE entity_type = @entity_type AND entity_id = @entity_id
    ORDER BY org_code, channel_purpose;
END;
GO

PRINT 'Created procedure: sp_GetDiscordPostsForEntity';
GO

-- Find entity by Discord message ID (for webhook handling)
IF OBJECT_ID('dbo.sp_FindEntityByDiscordMessage', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_FindEntityByDiscordMessage;
GO

CREATE PROCEDURE dbo.sp_FindEntityByDiscordMessage
    @message_id     NVARCHAR(64),
    @channel_id     NVARCHAR(64) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        post_id, entity_type, entity_id, entity_guid,
        org_code, channel_purpose, channel_id, guild_id,
        status, direction, approval_status
    FROM dbo.tmi_discord_posts
    WHERE message_id = @message_id
      AND (@channel_id IS NULL OR channel_id = @channel_id);
END;
GO

PRINT 'Created procedure: sp_FindEntityByDiscordMessage';
GO

-- Update approval status (for reaction handling)
IF OBJECT_ID('dbo.sp_UpdateDiscordPostApproval', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_UpdateDiscordPostApproval;
GO

CREATE PROCEDURE dbo.sp_UpdateDiscordPostApproval
    @message_id             NVARCHAR(64),
    @approval_status        NVARCHAR(16),
    @approved_by            NVARCHAR(64) = NULL,
    @approval_reaction_count INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    UPDATE dbo.tmi_discord_posts SET
        approval_status = @approval_status,
        approved_by = CASE WHEN @approval_status = 'APPROVED' THEN @approved_by ELSE approved_by END,
        approved_at = CASE WHEN @approval_status = 'APPROVED' THEN SYSUTCDATETIME() ELSE approved_at END,
        approval_reaction_count = COALESCE(@approval_reaction_count, approval_reaction_count)
    WHERE message_id = @message_id;
    
    SELECT @@ROWCOUNT AS rows_affected;
END;
GO

PRINT 'Created procedure: sp_UpdateDiscordPostApproval';
GO

-- Get pending posts for retry
IF OBJECT_ID('dbo.sp_GetPendingDiscordPosts', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_GetPendingDiscordPosts;
GO

CREATE PROCEDURE dbo.sp_GetPendingDiscordPosts
    @max_retry_count    TINYINT = 3,
    @min_age_minutes    INT = 1
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        p.post_id, p.entity_type, p.entity_id,
        p.org_code, p.channel_purpose, p.channel_id,
        p.status, p.retry_count, p.error_message,
        p.requested_at
    FROM dbo.tmi_discord_posts p
    WHERE p.status IN ('PENDING', 'FAILED')
      AND p.retry_count < @max_retry_count
      AND DATEDIFF(MINUTE, p.requested_at, SYSUTCDATETIME()) >= @min_age_minutes
    ORDER BY p.requested_at;
END;
GO

PRINT 'Created procedure: sp_GetPendingDiscordPosts';
GO

-- =============================================================================
-- VIEWS
-- =============================================================================

-- Active Discord posts view
IF OBJECT_ID('dbo.vw_tmi_discord_posts_active', 'V') IS NOT NULL
    DROP VIEW dbo.vw_tmi_discord_posts_active;
GO

CREATE VIEW dbo.vw_tmi_discord_posts_active AS
SELECT *
FROM dbo.tmi_discord_posts
WHERE status IN ('POSTED', 'SYNCED')
  AND message_id IS NOT NULL;
GO

PRINT 'Created view: vw_tmi_discord_posts_active';
GO

-- Staging posts pending approval
IF OBJECT_ID('dbo.vw_tmi_discord_posts_pending_approval', 'V') IS NOT NULL
    DROP VIEW dbo.vw_tmi_discord_posts_pending_approval;
GO

CREATE VIEW dbo.vw_tmi_discord_posts_pending_approval AS
SELECT *
FROM dbo.tmi_discord_posts
WHERE channel_purpose IN ('ntml_staging', 'advzy_staging')
  AND status = 'POSTED'
  AND approval_status IN ('PENDING', NULL);
GO

PRINT 'Created view: vw_tmi_discord_posts_pending_approval';
GO

-- =============================================================================
-- VERIFICATION
-- =============================================================================

PRINT '';
PRINT '=== Migration 016 Complete ===';
PRINT '';

SELECT 
    'tmi_discord_posts' AS table_name,
    COUNT(*) AS row_count
FROM dbo.tmi_discord_posts;

SELECT name AS index_name 
FROM sys.indexes 
WHERE object_id = OBJECT_ID('dbo.tmi_discord_posts') 
  AND name IS NOT NULL
ORDER BY name;

PRINT '';
GO
