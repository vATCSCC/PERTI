-- Migration 056: Bridge 1 delivery configuration columns
-- Adds controller-configurable delivery mode for reroutes and GS release follow-on

-- Reroute delivery mode: VOICE (standby for voice clearance) or DELIVERY (contact delivery freq)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.tmi_reroutes') AND name = 'delivery_mode')
BEGIN
    ALTER TABLE dbo.tmi_reroutes ADD delivery_mode VARCHAR(10) NOT NULL DEFAULT 'VOICE';
END
GO

-- Delivery frequency (only used when delivery_mode = 'DELIVERY')
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.tmi_reroutes') AND name = 'delivery_freq')
BEGIN
    ALTER TABLE dbo.tmi_reroutes ADD delivery_freq VARCHAR(10) NULL;
END
GO

-- GS release follow-on: GDP_ACTIVE (flights may get new EDCTs) or RELEASED (depart when ready)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.tmi_programs') AND name = 'gs_release_followon')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD gs_release_followon VARCHAR(12) NOT NULL DEFAULT 'RELEASED';
END
GO

-- Message delivery tracking: which flights have been notified of which TMI changes
IF NOT EXISTS (SELECT 1 FROM sys.objects WHERE name = 'tmi_delivery_log' AND type = 'U')
BEGIN
    CREATE TABLE dbo.tmi_delivery_log (
        log_id          BIGINT IDENTITY(1,1) PRIMARY KEY,
        flight_uid      BIGINT NOT NULL,
        callsign        VARCHAR(10) NOT NULL,
        message_type    VARCHAR(20) NOT NULL,
        message_hash    VARCHAR(64) NOT NULL,   -- SHA256 of message body (dedup)
        edct_utc        DATETIME2 NULL,         -- EDCT value at time of delivery
        program_id      INT NULL,
        delivered_utc   DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
        channels_sent   VARCHAR(50) NULL,       -- e.g. 'cpdlc,web,discord'
        ack_type        VARCHAR(10) NULL,       -- WILCO, UNABLE, STANDBY, NULL
        ack_utc         DATETIME2 NULL,

        INDEX IX_delivery_log_flight (flight_uid, delivered_utc DESC),
        INDEX IX_delivery_log_hash (flight_uid, message_hash)
    );
END
GO
