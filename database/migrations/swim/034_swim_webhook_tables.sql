-- Migration 034: Webhook tables for SimTraffic-VATSWIM bidirectional event bridge
-- Database: SWIM_API
-- Date: 2026-03-30

-- ============================================================================
-- Table: swim_webhook_subscriptions
-- Stores webhook endpoint registrations (inbound + outbound)
-- ============================================================================
IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'swim_webhook_subscriptions')
BEGIN
    CREATE TABLE dbo.swim_webhook_subscriptions (
        id                   INT IDENTITY(1,1) PRIMARY KEY,
        source_id            VARCHAR(32)   NOT NULL,
        direction            VARCHAR(8)    NOT NULL,
        callback_url         VARCHAR(512)  NOT NULL,
        shared_secret        VARCHAR(128)  NOT NULL,
        event_types          VARCHAR(MAX)  NULL,
        is_active            BIT           NOT NULL DEFAULT 1,
        created_utc          DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME(),
        updated_utc          DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME(),
        last_success_utc     DATETIME2     NULL,
        last_failure_utc     DATETIME2     NULL,
        consecutive_failures INT           NOT NULL DEFAULT 0
    );

    CREATE INDEX IX_webhook_subs_source
        ON dbo.swim_webhook_subscriptions (source_id, direction, is_active);
END;

-- ============================================================================
-- Table: swim_webhook_events
-- Event queue (outbound) + dedup log (inbound), 30-day retention
-- ============================================================================
IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'swim_webhook_events')
BEGIN
    CREATE TABLE dbo.swim_webhook_events (
        event_id         VARCHAR(64)   NOT NULL PRIMARY KEY,
        event_type       VARCHAR(64)   NOT NULL,
        direction        VARCHAR(8)    NOT NULL,
        source_id        VARCHAR(32)   NOT NULL,
        source_channel   VARCHAR(8)    NOT NULL DEFAULT 'rest',
        payload          NVARCHAR(MAX) NULL,
        status           VARCHAR(16)   NOT NULL DEFAULT 'pending',
        attempts         INT           NOT NULL DEFAULT 0,
        next_retry_utc   DATETIME2     NULL,
        created_utc      DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME(),
        delivered_utc    DATETIME2     NULL,
        flight_uid       BIGINT        NULL,
        callsign         VARCHAR(16)   NULL
    );

    -- Outbound delivery queue (split into two — SQL Server filtered indexes cannot use OR/IN)
    CREATE INDEX IX_webhook_events_pending
        ON dbo.swim_webhook_events (next_retry_utc)
        INCLUDE (event_id, event_type, source_id, payload, attempts)
        WHERE status = 'pending';

    CREATE INDEX IX_webhook_events_sent
        ON dbo.swim_webhook_events (next_retry_utc)
        INCLUDE (event_id, event_type, source_id, payload, attempts)
        WHERE status = 'sent';

    -- Purge by age
    CREATE INDEX IX_webhook_events_created
        ON dbo.swim_webhook_events (created_utc);

    -- Inbound dedup lookup
    CREATE INDEX IX_webhook_events_dedup
        ON dbo.swim_webhook_events (event_id, created_utc)
        WHERE direction = 'inbound';
END;

-- ============================================================================
-- Seed: SimTraffic webhook subscriptions
-- shared_secret values are placeholders — replace before production use
-- ============================================================================
IF NOT EXISTS (SELECT 1 FROM dbo.swim_webhook_subscriptions WHERE source_id = 'simtraffic')
BEGIN
    -- Inbound: SimTraffic pushes lifecycle events to us
    INSERT INTO dbo.swim_webhook_subscriptions
        (source_id, direction, callback_url, shared_secret, event_types)
    VALUES
        ('simtraffic', 'inbound', '/api/swim/v1/webhooks/simtraffic', 'REPLACE_WITH_SHARED_SECRET_INBOUND', '*');

    -- Outbound: We push TMI events to SimTraffic
    INSERT INTO dbo.swim_webhook_subscriptions
        (source_id, direction, callback_url, shared_secret, event_types)
    VALUES
        ('simtraffic', 'outbound', 'https://hooks.simtraffic.net/vatswim', 'REPLACE_WITH_SHARED_SECRET_OUTBOUND', '*');
END;
