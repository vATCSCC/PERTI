-- ============================================================
-- Discord Integration Database Schema
-- Migration: 020_discord_integration.sql
-- Database: VATSIM_ADL (Azure SQL)
-- ============================================================

-- ------------------------------------------------------------
-- Table: discord_channels
-- Stores configured Discord channels for PERTI integration
-- ------------------------------------------------------------
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'discord_channels')
BEGIN
    CREATE TABLE dbo.discord_channels (
        id                  INT IDENTITY(1,1) PRIMARY KEY,
        channel_id          NVARCHAR(64) NOT NULL UNIQUE,
        channel_name        NVARCHAR(128) NOT NULL,
        channel_type        NVARCHAR(32) NOT NULL,           -- TEXT, ANNOUNCEMENT, VOICE, CATEGORY
        guild_id            NVARCHAR(64) NOT NULL,
        purpose             NVARCHAR(64) NOT NULL,           -- TMI, ADVISORIES, OPERATIONS, ALERTS, GENERAL
        is_announcement     BIT NOT NULL DEFAULT 0,          -- True if announcement channel
        is_active           BIT NOT NULL DEFAULT 1,
        last_sync_utc       DATETIME2 NULL,
        created_at          DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
        updated_at          DATETIME2 NULL
    );

    CREATE INDEX IX_discord_channels_purpose ON dbo.discord_channels (purpose);
    CREATE INDEX IX_discord_channels_guild ON dbo.discord_channels (guild_id);

    PRINT 'Created table: dbo.discord_channels';
END
GO

-- ------------------------------------------------------------
-- Table: discord_messages
-- Stores Discord messages for tracking and history
-- ------------------------------------------------------------
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'discord_messages')
BEGIN
    CREATE TABLE dbo.discord_messages (
        id                  INT IDENTITY(1,1) PRIMARY KEY,
        message_id          NVARCHAR(64) NOT NULL UNIQUE,
        channel_id          NVARCHAR(64) NOT NULL,
        guild_id            NVARCHAR(64) NULL,
        author_id           NVARCHAR(64) NOT NULL,
        author_username     NVARCHAR(128) NULL,
        author_bot          BIT NOT NULL DEFAULT 0,

        -- Content
        content             NVARCHAR(MAX) NOT NULL,
        content_parsed      NVARCHAR(MAX) NULL,              -- Parsed/structured version

        -- Embeds (JSON array)
        embeds_json         NVARCHAR(MAX) NULL,

        -- Message metadata
        message_type        INT NULL DEFAULT 0,              -- Discord message type (0 = DEFAULT)
        reference_message_id NVARCHAR(64) NULL,              -- Reply/thread parent

        -- Timestamps
        discord_timestamp   DATETIME2 NOT NULL,              -- Discord's timestamp
        edited_timestamp    DATETIME2 NULL,
        received_at         DATETIME2 NOT NULL DEFAULT GETUTCDATE(),

        -- Processing status
        parsed_type         NVARCHAR(32) NULL,               -- TMI, ADVISORY, GENERAL, UNKNOWN
        parsed_data         NVARCHAR(MAX) NULL,              -- JSON parsed data
        is_processed        BIT NOT NULL DEFAULT 0,
        processed_at        DATETIME2 NULL,

        -- Soft delete
        is_deleted          BIT NOT NULL DEFAULT 0,
        deleted_at          DATETIME2 NULL
    );

    CREATE INDEX IX_discord_messages_channel ON dbo.discord_messages (channel_id, discord_timestamp DESC);
    CREATE INDEX IX_discord_messages_author ON dbo.discord_messages (author_id);
    CREATE INDEX IX_discord_messages_type ON dbo.discord_messages (parsed_type);
    CREATE INDEX IX_discord_messages_processed ON dbo.discord_messages (is_processed);

    PRINT 'Created table: dbo.discord_messages';
END
GO

-- ------------------------------------------------------------
-- Table: discord_reactions
-- Tracks reactions on messages
-- ------------------------------------------------------------
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'discord_reactions')
BEGIN
    CREATE TABLE dbo.discord_reactions (
        id                  INT IDENTITY(1,1) PRIMARY KEY,
        message_id          NVARCHAR(64) NOT NULL,
        channel_id          NVARCHAR(64) NOT NULL,
        user_id             NVARCHAR(64) NOT NULL,
        emoji               NVARCHAR(128) NOT NULL,          -- Unicode or name:id
        emoji_id            NVARCHAR(64) NULL,               -- For custom emojis
        added_at            DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
        removed_at          DATETIME2 NULL,
        is_active           BIT NOT NULL DEFAULT 1
    );

    CREATE INDEX IX_discord_reactions_message ON dbo.discord_reactions (message_id);
    CREATE INDEX IX_discord_reactions_user ON dbo.discord_reactions (user_id);
    CREATE UNIQUE INDEX UQ_discord_reactions ON dbo.discord_reactions (message_id, user_id, emoji);

    PRINT 'Created table: dbo.discord_reactions';
END
GO

-- ------------------------------------------------------------
-- Table: discord_sent_messages
-- Tracks messages sent BY PERTI to Discord
-- ------------------------------------------------------------
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'discord_sent_messages')
BEGIN
    CREATE TABLE dbo.discord_sent_messages (
        id                  INT IDENTITY(1,1) PRIMARY KEY,
        message_id          NVARCHAR(64) NULL,               -- Discord's message ID (after send)
        channel_id          NVARCHAR(64) NOT NULL,

        -- Content sent
        content             NVARCHAR(MAX) NULL,
        embeds_json         NVARCHAR(MAX) NULL,

        -- Source reference
        source_type         NVARCHAR(32) NOT NULL,           -- ADVISORY, TMI, ALERT, MANUAL
        source_id           INT NULL,                        -- FK to source table

        -- Status
        status              NVARCHAR(16) NOT NULL DEFAULT 'PENDING', -- PENDING, SENT, FAILED, DELETED
        error_message       NVARCHAR(MAX) NULL,

        -- Crossposting (for announcement channels)
        is_crossposted      BIT NOT NULL DEFAULT 0,
        crossposted_at      DATETIME2 NULL,

        -- Timestamps
        created_at          DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
        sent_at             DATETIME2 NULL,
        edited_at           DATETIME2 NULL,
        deleted_at          DATETIME2 NULL,

        -- Audit
        created_by          NVARCHAR(64) NOT NULL
    );

    CREATE INDEX IX_discord_sent_channel ON dbo.discord_sent_messages (channel_id, created_at DESC);
    CREATE INDEX IX_discord_sent_status ON dbo.discord_sent_messages (status);
    CREATE INDEX IX_discord_sent_source ON dbo.discord_sent_messages (source_type, source_id);

    PRINT 'Created table: dbo.discord_sent_messages';
END
GO

-- ------------------------------------------------------------
-- Table: discord_webhook_log
-- Logs incoming webhook events for debugging/audit
-- ------------------------------------------------------------
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'discord_webhook_log')
BEGIN
    CREATE TABLE dbo.discord_webhook_log (
        id                  INT IDENTITY(1,1) PRIMARY KEY,
        event_type          NVARCHAR(64) NOT NULL,           -- MESSAGE_CREATE, etc.
        event_id            NVARCHAR(64) NULL,
        payload_json        NVARCHAR(MAX) NOT NULL,
        signature_valid     BIT NOT NULL DEFAULT 1,
        processed           BIT NOT NULL DEFAULT 0,
        processing_result   NVARCHAR(MAX) NULL,
        received_at         DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
        processed_at        DATETIME2 NULL
    );

    CREATE INDEX IX_discord_webhook_type ON dbo.discord_webhook_log (event_type, received_at DESC);

    PRINT 'Created table: dbo.discord_webhook_log';
END
GO

-- ------------------------------------------------------------
-- Table: discord_rate_limits
-- Tracks rate limit state for API calls
-- ------------------------------------------------------------
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'discord_rate_limits')
BEGIN
    CREATE TABLE dbo.discord_rate_limits (
        id                  INT IDENTITY(1,1) PRIMARY KEY,
        endpoint_bucket     NVARCHAR(128) NOT NULL UNIQUE,
        remaining           INT NOT NULL DEFAULT 50,
        limit_total         INT NOT NULL DEFAULT 50,
        reset_at            DATETIME2 NOT NULL,
        updated_at          DATETIME2 NOT NULL DEFAULT GETUTCDATE()
    );

    CREATE INDEX IX_discord_rate_reset ON dbo.discord_rate_limits (reset_at);

    PRINT 'Created table: dbo.discord_rate_limits';
END
GO

-- ------------------------------------------------------------
-- Update existing dcc_discord_tmi table if it exists
-- Add index for faster lookups
-- ------------------------------------------------------------
IF EXISTS (SELECT * FROM sys.tables WHERE name = 'dcc_discord_tmi')
BEGIN
    IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_discord_tmi_message' AND object_id = OBJECT_ID('dbo.dcc_discord_tmi'))
    BEGIN
        CREATE INDEX IX_discord_tmi_message ON dbo.dcc_discord_tmi (discord_message_id);
        PRINT 'Added index IX_discord_tmi_message to dbo.dcc_discord_tmi';
    END

    -- Add updated_at column if it doesn't exist
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.dcc_discord_tmi') AND name = 'updated_at')
    BEGIN
        ALTER TABLE dbo.dcc_discord_tmi ADD updated_at DATETIME2 NULL;
        PRINT 'Added column updated_at to dbo.dcc_discord_tmi';
    END
END
GO

-- ------------------------------------------------------------
-- Create dcc_discord_tmi table if it doesn't exist
-- (for storing parsed TMI data from Discord messages)
-- ------------------------------------------------------------
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'dcc_discord_tmi')
BEGIN
    CREATE TABLE dbo.dcc_discord_tmi (
        id                  INT IDENTITY(1,1) PRIMARY KEY,
        discord_message_id  NVARCHAR(64) NULL,
        tmi_type            NVARCHAR(16) NOT NULL,           -- GS, GDP, AFP, REROUTE, MIT
        airport             NVARCHAR(8) NULL,                -- ICAO code
        facility            NVARCHAR(8) NULL,                -- ARTCC code
        reason              NVARCHAR(256) NULL,
        details             NVARCHAR(MAX) NULL,
        raw_message         NVARCHAR(MAX) NULL,
        start_time_utc      DATETIME2 NULL,
        end_time_utc        DATETIME2 NULL,
        max_delay_minutes   INT NULL,
        adr                 INT NULL,                        -- Airport Departure Rate
        aar                 INT NULL,                        -- Airport Arrival Rate
        mit                 INT NULL,                        -- Miles-In-Trail
        status              NVARCHAR(16) NOT NULL DEFAULT 'ACTIVE', -- ACTIVE, ENDED, CANCELLED
        received_at         DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
        ended_at            DATETIME2 NULL,
        updated_at          DATETIME2 NULL
    );

    CREATE INDEX IX_dcc_discord_tmi_type ON dbo.dcc_discord_tmi (tmi_type, status);
    CREATE INDEX IX_dcc_discord_tmi_airport ON dbo.dcc_discord_tmi (airport);
    CREATE INDEX IX_dcc_discord_tmi_status ON dbo.dcc_discord_tmi (status, received_at DESC);
    CREATE INDEX IX_dcc_discord_tmi_message ON dbo.dcc_discord_tmi (discord_message_id);

    PRINT 'Created table: dbo.dcc_discord_tmi';
END
GO

PRINT '============================================================';
PRINT 'Discord Integration migration complete';
PRINT '============================================================';
GO
