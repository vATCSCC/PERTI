-- ============================================================================
-- ADL Migration 010: TMI High-Resolution Trajectory Table
--
-- Purpose: Store high-resolution trajectory data for TMI compliance analysis
-- Retention: 90 days at tier-specific resolution (15s/30s/60s)
--
-- Target Database: VATSIM_ADL
-- Depends on: adl_flight_core, perti_events
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Migration 010: TMI Trajectory Table ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. Create TMI Trajectory Table
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_tmi_trajectory') AND type = 'U')
BEGIN
    CREATE TABLE dbo.adl_tmi_trajectory (
        tmi_trajectory_id   BIGINT IDENTITY(1,1) NOT NULL,
        flight_uid          BIGINT NOT NULL,

        -- Position data (matches adl_flight_trajectory)
        timestamp_utc       DATETIME2(0) NOT NULL,
        lat                 DECIMAL(10,7) NOT NULL,
        lon                 DECIMAL(11,7) NOT NULL,
        altitude_ft         INT NULL,
        groundspeed_kts     INT NULL,
        track_deg           SMALLINT NULL,
        vertical_rate_fpm   INT NULL,

        -- TMI-specific metadata
        tmi_tier            TINYINT NOT NULL,          -- 0=15s, 1=30s, 2=1min
        perti_event_id      INT NULL,                  -- FK to perti_events (for T-0)

        CONSTRAINT PK_adl_tmi_trajectory PRIMARY KEY CLUSTERED (tmi_trajectory_id),
        CONSTRAINT FK_tmi_traj_core FOREIGN KEY (flight_uid)
            REFERENCES dbo.adl_flight_core(flight_uid) ON DELETE CASCADE,
        CONSTRAINT FK_tmi_traj_event FOREIGN KEY (perti_event_id)
            REFERENCES dbo.perti_events(event_id) ON DELETE SET NULL,
        CONSTRAINT CK_tmi_tier CHECK (tmi_tier IN (0, 1, 2))
    ) WITH (DATA_COMPRESSION = PAGE);

    PRINT 'Created table dbo.adl_tmi_trajectory with PAGE compression';
END
ELSE
BEGIN
    PRINT 'Table dbo.adl_tmi_trajectory already exists - skipping';
END
GO

-- ============================================================================
-- 2. Create Indexes
-- ============================================================================

-- Primary query pattern: flight-based trajectory retrieval
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.adl_tmi_trajectory') AND name = 'IX_tmi_traj_flight_time')
BEGIN
    CREATE NONCLUSTERED INDEX IX_tmi_traj_flight_time
        ON dbo.adl_tmi_trajectory (flight_uid, timestamp_utc DESC)
        INCLUDE (lat, lon, groundspeed_kts, altitude_ft);
    PRINT 'Created index IX_tmi_traj_flight_time';
END
GO

-- Event-based analysis
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.adl_tmi_trajectory') AND name = 'IX_tmi_traj_event_time')
BEGIN
    CREATE NONCLUSTERED INDEX IX_tmi_traj_event_time
        ON dbo.adl_tmi_trajectory (perti_event_id, timestamp_utc DESC)
        WHERE perti_event_id IS NOT NULL;
    PRINT 'Created filtered index IX_tmi_traj_event_time';
END
GO

-- Purge + time-range queries
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.adl_tmi_trajectory') AND name = 'IX_tmi_traj_timestamp')
BEGIN
    CREATE NONCLUSTERED INDEX IX_tmi_traj_timestamp
        ON dbo.adl_tmi_trajectory (timestamp_utc DESC);
    PRINT 'Created index IX_tmi_traj_timestamp';
END
GO

-- Duplicate prevention (idempotent inserts)
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.adl_tmi_trajectory') AND name = 'UX_tmi_traj_flight_time')
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_tmi_traj_flight_time
        ON dbo.adl_tmi_trajectory (flight_uid, timestamp_utc)
        WITH (IGNORE_DUP_KEY = ON);
    PRINT 'Created unique index UX_tmi_traj_flight_time with IGNORE_DUP_KEY';
END
GO

PRINT '';
PRINT '=== ADL Migration 010 Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
