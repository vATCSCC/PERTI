-- ============================================================================
-- 020_swim_acars_messages.sql
-- SWIM_API Database: ACARS Message Logging and Processing Schema
--
-- Purpose: Unified ACARS message handling from multiple sources (Hoppie, VAs,
--          simulator plugins) with priority-based OOOI time extraction and
--          bi-directional PDC delivery.
--
-- Reference: ACARS_VATSWIM_Integration.md
-- ============================================================================

USE SWIM_API;
GO

PRINT '==========================================================================';
PRINT '  SWIM Migration 020: ACARS Message Logging Schema';
PRINT '  ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
GO

-- ============================================================================
-- Table 1: swim_acars_messages - Core message logging
-- ============================================================================

PRINT '';
PRINT '-- Table 1: swim_acars_messages --';
GO

IF NOT EXISTS (SELECT 1 FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.swim_acars_messages') AND type = 'U')
BEGIN
    CREATE TABLE dbo.swim_acars_messages (
        message_id          BIGINT IDENTITY(1,1) PRIMARY KEY,

        -- Message Identity
        message_guid        UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),
        flight_uid          BIGINT NULL,                    -- FK to swim_flights
        gufi                NVARCHAR(64) NULL,              -- GUFI if matched
        callsign            NVARCHAR(16) NOT NULL,

        -- Message Type & Source
        message_type        NVARCHAR(16) NOT NULL,          -- OOOI/POSITION/PROGRESS/PDC/WEATHER/TELEX
        source              NVARCHAR(32) NOT NULL,          -- hoppie/smartcars/phpvms/vam/fs2crew/etc
        direction           NVARCHAR(8) NULL,               -- UPLINK/DOWNLINK

        -- Message Content
        raw_payload         NVARCHAR(MAX) NULL,             -- Original message
        parsed_payload      NVARCHAR(MAX) NULL,             -- JSON parsed data

        -- Timing
        message_utc         DATETIME2(0) NOT NULL,          -- When event occurred
        received_utc        DATETIME2(0) NOT NULL DEFAULT GETUTCDATE(),
        processed_utc       DATETIME2(0) NULL,

        -- Processing Status
        status              NVARCHAR(16) NOT NULL DEFAULT 'PENDING',  -- PENDING/PROCESSED/REJECTED/ERROR
        error_message       NVARCHAR(MAX) NULL,
        flight_matched      BIT NOT NULL DEFAULT 0,
        swim_updated        BIT NOT NULL DEFAULT 0,

        -- OOOI-specific fields (for quick queries)
        oooi_event          NVARCHAR(8) NULL,               -- OUT/OFF/ON/IN
        airport_icao        NVARCHAR(4) NULL,
        gate_stand          NVARCHAR(16) NULL,
        runway              NVARCHAR(4) NULL,

        -- Indexes
        INDEX IX_acars_msg_flight (flight_uid, received_utc),
        INDEX IX_acars_msg_callsign (callsign, received_utc DESC),
        INDEX IX_acars_msg_type (message_type, received_utc DESC),
        INDEX IX_acars_msg_source (source, received_utc DESC),
        INDEX IX_acars_msg_oooi (oooi_event, airport_icao) WHERE oooi_event IS NOT NULL,
        INDEX IX_acars_msg_pending (status) WHERE status = 'PENDING'
    );
    PRINT '+ Created table swim_acars_messages';
END
ELSE PRINT '= Table swim_acars_messages already exists';
GO

-- ============================================================================
-- Table 2: swim_acars_pdc_queue - PDC Delivery Queue (Bi-directional)
-- ============================================================================

PRINT '';
PRINT '-- Table 2: swim_acars_pdc_queue --';
GO

IF NOT EXISTS (SELECT 1 FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.swim_acars_pdc_queue') AND type = 'U')
BEGIN
    CREATE TABLE dbo.swim_acars_pdc_queue (
        queue_id            BIGINT IDENTITY(1,1) PRIMARY KEY,

        -- Target
        callsign            NVARCHAR(16) NOT NULL,
        flight_uid          BIGINT NULL,

        -- PDC Content
        clearance_type      NVARCHAR(16) NOT NULL,          -- DCL/PDC/CPDLC
        destination         NVARCHAR(4) NOT NULL,
        route               NVARCHAR(MAX) NULL,
        cleared_altitude_fl INT NULL,
        initial_altitude_ft INT NULL,
        departure_runway    NVARCHAR(4) NULL,
        sid                 NVARCHAR(32) NULL,
        squawk              NVARCHAR(4) NULL,
        departure_frequency NVARCHAR(16) NULL,
        remarks             NVARCHAR(256) NULL,

        -- Raw clearance text
        clearance_text      NVARCHAR(MAX) NULL,

        -- Delivery
        delivery_channel    NVARCHAR(16) NOT NULL DEFAULT 'HOPPIE',  -- HOPPIE/CPDLC/TELEX
        delivery_status     NVARCHAR(16) NOT NULL DEFAULT 'PENDING', -- PENDING/SENT/DELIVERED/FAILED/EXPIRED
        delivery_attempts   INT NOT NULL DEFAULT 0,
        last_attempt_utc    DATETIME2(0) NULL,
        delivered_utc       DATETIME2(0) NULL,
        pilot_response      NVARCHAR(16) NULL,              -- WILCO/UNABLE/STANDBY

        -- Response tracking
        response_received_utc DATETIME2(0) NULL,
        response_text       NVARCHAR(256) NULL,

        -- Metadata
        created_by          NVARCHAR(64) NULL,              -- Who initiated (CID or system)
        facility_id         NVARCHAR(8) NULL,               -- Originating facility
        created_utc         DATETIME2(0) NOT NULL DEFAULT GETUTCDATE(),
        expires_utc         DATETIME2(0) NULL,

        INDEX IX_pdc_queue_callsign (callsign, delivery_status),
        INDEX IX_pdc_queue_pending (delivery_status, created_utc) WHERE delivery_status = 'PENDING',
        INDEX IX_pdc_queue_flight (flight_uid) WHERE flight_uid IS NOT NULL
    );
    PRINT '+ Created table swim_acars_pdc_queue';
END
ELSE PRINT '= Table swim_acars_pdc_queue already exists';
GO

-- ============================================================================
-- Table 3: swim_acars_sources - Registered ACARS Sources
-- ============================================================================

PRINT '';
PRINT '-- Table 3: swim_acars_sources --';
GO

IF NOT EXISTS (SELECT 1 FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.swim_acars_sources') AND type = 'U')
BEGIN
    CREATE TABLE dbo.swim_acars_sources (
        source_id           INT IDENTITY(1,1) PRIMARY KEY,
        source_code         NVARCHAR(32) NOT NULL UNIQUE,   -- hoppie, smartcars, etc
        source_name         NVARCHAR(128) NOT NULL,
        source_type         NVARCHAR(16) NOT NULL,          -- NETWORK/WEBHOOK/PLUGIN

        -- Capabilities
        supports_oooi       BIT NOT NULL DEFAULT 1,
        supports_position   BIT NOT NULL DEFAULT 0,
        supports_pdc        BIT NOT NULL DEFAULT 0,
        supports_telex      BIT NOT NULL DEFAULT 0,
        supports_weather    BIT NOT NULL DEFAULT 0,

        -- Priority (overrides swim_config defaults if set)
        oooi_priority       TINYINT NULL,                   -- NULL = use swim_config
        track_priority      TINYINT NULL,

        -- Configuration
        api_key_id          INT NULL,                       -- FK to swim_api_keys
        config_json         NVARCHAR(MAX) NULL,             -- Source-specific config

        -- Rate limiting
        rate_limit_per_min  INT NULL,                       -- NULL = use default

        -- Status
        is_active           BIT NOT NULL DEFAULT 1,
        last_message_utc    DATETIME2(0) NULL,
        message_count_24h   INT NOT NULL DEFAULT 0,

        created_utc         DATETIME2(0) NOT NULL DEFAULT GETUTCDATE(),
        updated_utc         DATETIME2(0) NOT NULL DEFAULT GETUTCDATE(),

        INDEX IX_acars_sources_active (is_active, source_code)
    );
    PRINT '+ Created table swim_acars_sources';

    -- Insert known sources
    INSERT INTO dbo.swim_acars_sources
        (source_code, source_name, source_type, supports_oooi, supports_position, supports_pdc, supports_telex, supports_weather, oooi_priority, track_priority)
    VALUES
        ('hoppie', 'Hoppie ACARS Network', 'NETWORK', 1, 1, 1, 1, 1, 1, 7),
        ('smartcars', 'smartCARS Webhooks', 'WEBHOOK', 1, 1, 0, 0, 0, 2, 8),
        ('phpvms', 'phpVMS 7 Module', 'WEBHOOK', 1, 1, 0, 0, 0, 2, 8),
        ('vam', 'VAM Integration', 'WEBHOOK', 1, 1, 0, 0, 0, 2, 8),
        ('simbrief', 'SimBrief ACARS', 'WEBHOOK', 0, 0, 1, 0, 0, NULL, NULL),
        ('fs2crew', 'FS2Crew ACARS', 'PLUGIN', 1, 0, 0, 1, 0, 3, NULL),
        ('pacx', 'PACX ACARS', 'PLUGIN', 1, 0, 0, 0, 0, 3, NULL),
        ('generic', 'Generic ACARS', 'WEBHOOK', 1, 1, 0, 1, 0, 5, 9);
    PRINT '+ Inserted default ACARS sources';
END
ELSE PRINT '= Table swim_acars_sources already exists';
GO

-- ============================================================================
-- Table 4: swim_acars_webhooks - Webhook Subscriptions for OOOI Events
-- ============================================================================

PRINT '';
PRINT '-- Table 4: swim_acars_webhooks --';
GO

IF NOT EXISTS (SELECT 1 FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.swim_acars_webhooks') AND type = 'U')
BEGIN
    CREATE TABLE dbo.swim_acars_webhooks (
        webhook_id          INT IDENTITY(1,1) PRIMARY KEY,
        api_key_id          INT NOT NULL,                   -- FK to swim_api_keys

        -- Endpoint
        endpoint_url        NVARCHAR(512) NOT NULL,
        secret_key          NVARCHAR(128) NULL,             -- For HMAC signature

        -- Event subscriptions (JSON array: ["oooi.out", "oooi.off", "oooi.on", "oooi.in"])
        subscribed_events   NVARCHAR(512) NOT NULL DEFAULT '["oooi.*"]',

        -- Filters
        filter_airports     NVARCHAR(256) NULL,             -- JSON array of ICAO codes
        filter_airlines     NVARCHAR(256) NULL,             -- JSON array of airline codes

        -- Status
        is_active           BIT NOT NULL DEFAULT 1,
        last_delivery_utc   DATETIME2(0) NULL,
        failure_count       INT NOT NULL DEFAULT 0,
        consecutive_failures INT NOT NULL DEFAULT 0,

        created_utc         DATETIME2(0) NOT NULL DEFAULT GETUTCDATE(),
        updated_utc         DATETIME2(0) NOT NULL DEFAULT GETUTCDATE(),

        INDEX IX_webhooks_active (is_active, api_key_id)
    );
    PRINT '+ Created table swim_acars_webhooks';
END
ELSE PRINT '= Table swim_acars_webhooks already exists';
GO

-- ============================================================================
-- Stored Procedure: sp_Swim_CleanupACARSMessages
-- ============================================================================

PRINT '';
PRINT '-- Stored Procedure: sp_Swim_CleanupACARSMessages --';
GO

CREATE OR ALTER PROCEDURE dbo.sp_Swim_CleanupACARSMessages
    @retention_days INT = 7
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @deleted_messages INT;
    DECLARE @deleted_pdc INT;

    -- Cleanup old messages
    DELETE FROM dbo.swim_acars_messages
    WHERE received_utc < DATEADD(DAY, -@retention_days, GETUTCDATE());
    SET @deleted_messages = @@ROWCOUNT;

    -- Cleanup expired/old PDC queue entries
    DELETE FROM dbo.swim_acars_pdc_queue
    WHERE created_utc < DATEADD(DAY, -@retention_days, GETUTCDATE())
      AND delivery_status IN ('DELIVERED', 'FAILED', 'EXPIRED');
    SET @deleted_pdc = @@ROWCOUNT;

    -- Update 24h message counts for sources
    UPDATE dbo.swim_acars_sources
    SET message_count_24h = ISNULL((
        SELECT COUNT(*)
        FROM dbo.swim_acars_messages m
        WHERE m.source = swim_acars_sources.source_code
          AND m.received_utc >= DATEADD(HOUR, -24, GETUTCDATE())
    ), 0),
    updated_utc = GETUTCDATE();

    PRINT 'ACARS Cleanup Complete:';
    PRINT '  Messages deleted: ' + CAST(@deleted_messages AS VARCHAR);
    PRINT '  PDC entries deleted: ' + CAST(@deleted_pdc AS VARCHAR);
END;
GO

PRINT '+ Created/updated procedure sp_Swim_CleanupACARSMessages';
GO

-- ============================================================================
-- Stored Procedure: sp_Swim_ProcessOOOIMessage
-- ============================================================================

PRINT '';
PRINT '-- Stored Procedure: sp_Swim_ProcessOOOIMessage --';
GO

CREATE OR ALTER PROCEDURE dbo.sp_Swim_ProcessOOOIMessage
    @message_id BIGINT,
    @flight_uid BIGINT OUTPUT,
    @updated BIT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @callsign NVARCHAR(16);
    DECLARE @oooi_event NVARCHAR(8);
    DECLARE @message_utc DATETIME2(0);
    DECLARE @airport_icao NVARCHAR(4);
    DECLARE @source NVARCHAR(32);
    DECLARE @gufi NVARCHAR(64);

    SET @updated = 0;
    SET @flight_uid = NULL;

    -- Get message details
    SELECT @callsign = callsign,
           @oooi_event = oooi_event,
           @message_utc = message_utc,
           @airport_icao = airport_icao,
           @source = source
    FROM dbo.swim_acars_messages
    WHERE message_id = @message_id;

    IF @callsign IS NULL
    BEGIN
        RAISERROR('Message not found', 16, 1);
        RETURN;
    END

    -- Find matching flight
    SELECT TOP 1 @flight_uid = flight_uid, @gufi = gufi
    FROM dbo.swim_flights
    WHERE callsign = @callsign AND is_active = 1
    ORDER BY last_seen_utc DESC;

    IF @flight_uid IS NULL
    BEGIN
        -- Update message as not matched
        UPDATE dbo.swim_acars_messages
        SET status = 'PROCESSED',
            flight_matched = 0,
            processed_utc = GETUTCDATE()
        WHERE message_id = @message_id;
        RETURN;
    END

    -- Update swim_flights based on OOOI event
    -- Dual-write to legacy and FIXM columns
    IF @oooi_event = 'OUT'
    BEGIN
        UPDATE dbo.swim_flights
        SET out_utc = @message_utc,
            actual_off_block_time = @message_utc,
            phase = CASE WHEN phase = 'preflight' THEN 'taxi_out' ELSE phase END,
            last_sync_utc = GETUTCDATE()
        WHERE flight_uid = @flight_uid;
        SET @updated = 1;
    END
    ELSE IF @oooi_event = 'OFF'
    BEGIN
        UPDATE dbo.swim_flights
        SET off_utc = @message_utc,
            actual_time_of_departure = @message_utc,
            phase = 'enroute',
            last_sync_utc = GETUTCDATE()
        WHERE flight_uid = @flight_uid;
        SET @updated = 1;
    END
    ELSE IF @oooi_event = 'ON'
    BEGIN
        UPDATE dbo.swim_flights
        SET on_utc = @message_utc,
            actual_landing_time = @message_utc,
            phase = 'taxi_in',
            last_sync_utc = GETUTCDATE()
        WHERE flight_uid = @flight_uid;
        SET @updated = 1;
    END
    ELSE IF @oooi_event = 'IN'
    BEGIN
        UPDATE dbo.swim_flights
        SET in_utc = @message_utc,
            actual_in_block_time = @message_utc,
            phase = 'arrived',
            is_active = 0,
            last_sync_utc = GETUTCDATE()
        WHERE flight_uid = @flight_uid;
        SET @updated = 1;
    END

    -- Update message status
    UPDATE dbo.swim_acars_messages
    SET status = 'PROCESSED',
        flight_uid = @flight_uid,
        gufi = @gufi,
        flight_matched = 1,
        swim_updated = @updated,
        processed_utc = GETUTCDATE()
    WHERE message_id = @message_id;
END;
GO

PRINT '+ Created/updated procedure sp_Swim_ProcessOOOIMessage';
GO

-- ============================================================================
-- Summary
-- ============================================================================

PRINT '';
PRINT '==========================================================================';
PRINT '  Migration 020 Complete: ACARS Message Logging Schema';
PRINT '';
PRINT '  Tables Created:';
PRINT '    - swim_acars_messages: Core message logging';
PRINT '    - swim_acars_pdc_queue: PDC delivery queue';
PRINT '    - swim_acars_sources: Registered ACARS sources';
PRINT '    - swim_acars_webhooks: Webhook subscriptions';
PRINT '';
PRINT '  Stored Procedures:';
PRINT '    - sp_Swim_CleanupACARSMessages: Retention cleanup';
PRINT '    - sp_Swim_ProcessOOOIMessage: OOOI processing';
PRINT '';
PRINT '  Default Sources Registered:';
PRINT '    - hoppie (OOOI priority 1)';
PRINT '    - smartcars, phpvms, vam (OOOI priority 2)';
PRINT '    - fs2crew, pacx (OOOI priority 3)';
PRINT '    - generic (OOOI priority 5)';
PRINT '==========================================================================';
GO
